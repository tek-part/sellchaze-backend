<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StoreOrderStatusChange;
use App\Notifications\StoreOrderActivity;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 order lifecycle + numbering. Runs inside the CurrentStore tenant so
 * order-number uniqueness and lookups are per-store.
 */
class StoreOrderService
{
    /** Allowed status transitions (fail-closed: anything not listed is rejected). */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['waiting_for_pickup', 'shipped', 'cancelled'],
        'waiting_for_pickup' => ['shipped', 'cancelled'],
        // `refunded` from shipped/delivered/request_refund lets a recorded full refund close the order.
        'shipped' => ['delivered', 'returning_from_delivery', 'refunded'],
        'delivered' => ['request_refund', 'refunded'],
        'cancelled' => [],
        'returning_from_delivery' => ['refunded'],
        'request_refund' => ['refund_in_progress', 'refunded'],
        'refund_in_progress' => ['refunded'],
        'refunded' => [],
    ];

    /** A customer may cancel only while the order is still cancellable. */
    public const CUSTOMER_CANCELLABLE = ['pending', 'confirmed'];

    public function generateNumber(): string
    {
        do {
            $number = 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (StoreOrder::query()->where('order_number', $number)->exists());

        return $number;
    }

    public function canTransition(StoreOrder $order, string $to): bool
    {
        return in_array($to, $this->nextStatuses($order), true);
    }

    /** @return list<string> */
    public function nextStatuses(StoreOrder $order): array
    {
        // "Not confirmed until the deposit is paid": the order can only wait or be cancelled.
        if (OrderDeposits::blocksConfirmation($order)) {
            return array_values(array_diff(self::TRANSITIONS[$order->status] ?? [], ['confirmed', 'processing', 'waiting_for_pickup', 'shipped', 'delivered']));
        }
        if ($order->requiresShipping()) {
            return self::TRANSITIONS[$order->status] ?? [];
        }
        $next = match ($order->status) {
            'pending' => ['confirmed', 'cancelled'],
            'confirmed', 'processing' => ['cancelled'],
            'shipped' => [],
            default => [],
        };
        if ($order->payment_status === 'paid' && in_array($order->status, ['pending', 'confirmed', 'processing', 'shipped'], true)) {
            array_unshift($next, 'delivered');
        }

        return $next;
    }

    /**
     * Apply a status transition and record it in the order's timeline. The
     * single audit point for every status change — merchant-driven changes pass
     * the acting user and an optional internal note; customer self-service
     * cancels pass neither (actor stays null = customer-initiated). Recorded refunds pass
     * `$notifyCustomer = false` when no money changed hands (a failed cash-on-delivery return).
     *
     * Physical units ship through fulfillments. Marking a shipping order shipped (or delivered) here fulfils
     * whatever is still unshipped in one fulfillment; StoreFulfillments passes `$viaFulfillment` when it
     * drives the status itself, so only its own units move. Ending an order in a return/refund releases
     * units that never shipped.
     */
    public function transition(StoreOrder $order, string $to, ?int $actorId = null, ?string $note = null, ?string $source = null, bool $notifyCustomer = true, bool $viaFulfillment = false): StoreOrder
    {
        return DB::transaction(function () use ($order, $to, $actorId, $note, $source, $notifyCustomer, $viaFulfillment) {
            $current = StoreOrder::query()->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->setRawAttributes($current->getAttributes(), true);
            if ($source === 'customer' && ! in_array($order->status, self::CUSTOMER_CANCELLABLE, true)) {
                throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled.']);
            }
            if (! $this->canTransition($order, $to)) {
                if (OrderDeposits::blocksConfirmation($order) && in_array($to, self::TRANSITIONS[$order->status] ?? [], true)) {
                    throw ValidationException::withMessages(['status' => __('This order is confirmed once its deposit is paid.')]);
                }
                throw ValidationException::withMessages(['status' => "Cannot transition order from {$order->status} to {$to}."]);
            }
            // Paid digital items are released at payment: a shopper cannot cancel them back.
            $released = $to === 'cancelled' && $order->payment_status === 'paid' && DigitalProducts::hasDigitalItems($order);
            if ($source === 'customer' && $released) {
                throw ValidationException::withMessages(['status' => 'Paid digital orders cannot be cancelled. Contact the store for help.']);
            }

            $from = $order->status;
            $shipping = $order->requiresShipping();
            $digitalCompletion = $to === 'delivered' && ! $shipping;
            $fulfillments = app(StoreFulfillments::class);
            $leftover = null;
            if ($shipping && ! $viaFulfillment && in_array($to, ['shipped', 'delivered'], true)) {
                // A parcel fulfilled while marking the order delivered never sends a late shipping confirmation.
                $leftover = $fulfillments->fulfilRemaining($order, $actorId, $source, $notifyCustomer && $to === 'shipped');
            }
            if ($to === 'shipped' && $shipping) {
                // Physical lines shipped through fulfillments; digital lines of a mixed order are handed over now.
                app(StoreInventory::class)->settle($order, true, true);
            } elseif (in_array($to, ['cancelled', 'shipped'], true) || $digitalCompletion) {
                app(StoreInventory::class)->settle($order, $to === 'shipped' || $digitalCompletion);
            } elseif (in_array($to, ['returning_from_delivery', 'refunded'], true)) {
                app(StoreInventory::class)->settle($order, false);
            }
            $order->status = $to;
            if ($to === 'cancelled') {
                $order->cancelled_at = now();
                app(DigitalProducts::class)->cancel($order);
            }
            $order->save();
            // Cancellation puts what is left of the gift card payment back on the cards (once), unless
            // digital codes were already released: then the merchant decides through a recorded refund.
            // Refunds are owned by StoreOrderRefunds, which returns each refund's gift card share itself.
            if ($to === 'cancelled' && ! $released) {
                app(GiftCards::class)->restore($order, $actorId);
                // Paid entirely by gift card and fully restored: nothing is left paid.
                if ($order->payment_status === 'paid' && bccomp((string) $order->gift_card_total, '0', 2) > 0 && bccomp($order->amountDue(), '0', 2) === 0
                    && bccomp(app(GiftCards::class)->returnedTotal($order), (string) $order->gift_card_total, 2) >= 0) {
                    $order->forceFill(['payment_status' => 'refunded'])->save();
                }
            }
            $fulfillments->recompute($order);
            // Announced after the status change so webhook payloads carry the new order status.
            if ($leftover !== null) {
                $fulfillments->announce($order, $leftover, 'created');
            }
            if ($to === 'delivered' && $shipping && ! $viaFulfillment) {
                $fulfillments->markAllDelivered($order);
            }

            $change = $order->statusChanges()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'notes' => $note,
                'source' => $source,
            ]);

            DB::afterCommit(fn () => StoreAnalyticsService::forget($order->store_id));
            $order->store?->owner?->notify(new StoreOrderActivity($order, 'status_changed'));
            app(StoreOrderWebhooks::class)->statusChanged($order, $from, (int) $change->id);
            // Shipping confirmations go out per fulfillment, with that parcel's tracking.
            if ($notifyCustomer && ! ($to === 'shipped' && $shipping)) {
                app(StoreNotificationSettings::class)->statusChanged($order, $to);
            }

            return $order;
        });
    }

    /**
     * Record a standalone internal note on the order's timeline without a status
     * change. Reuses the StoreOrderStatusChange audit table (from == to == the
     * current status) so there is one notes/history system, not two.
     */
    public function addNote(StoreOrder $order, string $note, ?int $actorId = null): StoreOrderStatusChange
    {
        return $order->statusChanges()->create([
            'from_status' => $order->status,
            'to_status' => $order->status,
            'actor_id' => $actorId,
            'notes' => $note,
        ]);
    }

    public function cancelByCustomer(StoreOrder $order): StoreOrder
    {
        return $this->transition($order, 'cancelled', null, null, 'customer');
    }
}

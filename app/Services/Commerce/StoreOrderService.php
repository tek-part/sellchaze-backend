<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StoreOrderStatusChange;
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
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
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
     * cancels pass neither (actor stays null = customer-initiated).
     */
    public function transition(StoreOrder $order, string $to, ?int $actorId = null, ?string $note = null, ?string $source = null): StoreOrder
    {
        return DB::transaction(function () use ($order, $to, $actorId, $note, $source) {
            $current = StoreOrder::query()->where('store_id', $order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->setRawAttributes($current->getAttributes(), true);
            if ($source === 'customer' && ! in_array($order->status, self::CUSTOMER_CANCELLABLE, true)) {
                throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled.']);
            }
            if (! $this->canTransition($order, $to)) {
                throw ValidationException::withMessages(['status' => "Cannot transition order from {$order->status} to {$to}."]);
            }

            $from = $order->status;
            $digitalCompletion = $to === 'delivered' && ! $order->requiresShipping();
            if (in_array($to, ['cancelled', 'shipped'], true) || $digitalCompletion) {
                app(StoreInventory::class)->settle($order, $to === 'shipped' || $digitalCompletion);
            }
            $order->status = $to;
            if ($to === 'cancelled') {
                $order->cancelled_at = now();
                app(DigitalProducts::class)->cancel($order);
            }
            $order->save();

            $order->statusChanges()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'notes' => $note,
                'source' => $source,
            ]);

            DB::afterCommit(fn () => StoreAnalyticsService::forget($order->store_id));

            // One-way mirror onto the bridged B2B order (cancelled only). Never let it break the transition.
            try {
                app(StorefrontOrderBridge::class)->syncStatus($order);
            } catch (\Throwable $e) {
                report($e);
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

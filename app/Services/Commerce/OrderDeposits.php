<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deposits (عربون): part of the order is paid online with the store's payment methods, the rest is
 * collected on delivery. The deposit is a percentage of what the customer pays after gift cards, with
 * an optional fixed minimum, never more than that amount. It applies to every order with delivery, or
 * only to carts holding a product flagged `requires_deposit`. Off until the merchant enables it.
 *
 * The order keeps the frozen rule in `deposit_details` and the amount in `deposit_total`;
 * StoreOrder::amountDue() charges the deposit first, so gateways, webhooks and exports follow it.
 */
class OrderDeposits
{
    public const SCOPES = ['all', 'flagged'];

    /** Methods that cannot collect a deposit: the remainder is already cash on delivery. */
    public const UNPAYABLE_METHODS = ['cod', GiftCards::METHOD];

    /** @return array{enabled:bool,percentage:string,minimum:string,scope:string,confirm_after_payment:bool,version:int} */
    public function configured(?Store $store): array
    {
        $config = $store?->deposit_configuration ?? [];

        return ['enabled' => (bool) ($config['enabled'] ?? false),
            'percentage' => bcadd((string) ($config['percentage'] ?? '0'), '0', 2),
            'minimum' => bcadd((string) ($config['minimum'] ?? '0'), '0', 2),
            'scope' => in_array($config['scope'] ?? null, self::SCOPES, true) ? $config['scope'] : 'all',
            'confirm_after_payment' => (bool) ($config['confirm_after_payment'] ?? false),
            'version' => max(1, (int) ($config['version'] ?? 1))];
    }

    public static function rules(): array
    {
        return ['enabled' => ['required', 'boolean'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'minimum' => ['required', 'numeric', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY, 'decimal:0,2'],
            'scope' => ['required', 'in:'.implode(',', self::SCOPES)],
            'confirm_after_payment' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:1']];
    }

    /** Save a validated configuration; a stale version is a conflict. */
    public function save(Store $store, array $data): array
    {
        $percentage = bcadd((string) $data['percentage'], '0', 2);
        $minimum = bcadd((string) $data['minimum'], '0', 2);
        if ($data['enabled'] && bccomp($percentage, '0', 2) === 0 && bccomp($minimum, '0', 2) === 0) {
            throw ValidationException::withMessages(['percentage' => 'Enter a deposit percentage, a minimum amount, or both.']);
        }

        return DB::transaction(function () use ($store, $data, $percentage, $minimum) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->configured($locked)['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            $locked->update(['deposit_configuration' => ['enabled' => (bool) $data['enabled'], 'percentage' => $percentage, 'minimum' => $minimum,
                'scope' => $data['scope'], 'confirm_after_payment' => (bool) $data['confirm_after_payment'], 'version' => (int) $data['version'] + 1]]);

            return $this->configured($locked);
        });
    }

    public static function methodCanPay(string $method): bool
    {
        return ! in_array($method, self::UNPAYABLE_METHODS, true);
    }

    /**
     * Whether a cart needs a deposit: the setting is on, the order is delivered (the remainder is collected
     * on delivery) and either every order needs one or a product in the cart is flagged.
     *
     * @param  iterable<Product>  $products
     */
    public function required(Store $store, iterable $products, bool $requiresShipping): bool
    {
        $config = $this->configured($store);
        if (! $config['enabled'] || ! $requiresShipping) {
            return false;
        }
        if ($config['scope'] === 'all') {
            return true;
        }
        foreach ($products as $product) {
            if (ProductPreferences::flag($product, 'requires_deposit')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The deposit for what the customer pays after gift cards, or null when none applies.
     *
     * @param  iterable<Product>  $products
     * @return array{amount:string,remaining:string,details:array<string,mixed>}|null
     */
    public function quote(Store $store, iterable $products, bool $requiresShipping, string $due, string $currency): ?array
    {
        if (bccomp($due, '0', 2) <= 0 || ! $this->required($store, $products, $requiresShipping)) {
            return null;
        }
        $config = $this->configured($store);
        $rule = ['version' => 1, 'percentage' => $config['percentage'], 'minimum' => $config['minimum'], 'scope' => $config['scope'],
            'confirm_after_payment' => $config['confirm_after_payment'], 'currency' => strtoupper($currency)];

        return $this->forRule($rule, $due);
    }

    /**
     * Apply a frozen rule to an amount due (used again when a merchant changes the payment method).
     *
     * @return array{amount:string,remaining:string,details:array<string,mixed>}|null
     */
    public function forRule(array $rule, string $due): ?array
    {
        if (bccomp($due, '0', 2) <= 0) {
            return null;
        }
        $precision = min(2, PaymentAmounts::decimals((string) ($rule['currency'] ?? 'USD')));
        $share = bcdiv(bcmul($due, (string) $rule['percentage'], 6), '100', 6);
        $share = bcadd(bcadd($share, $precision === 0 ? '0.5' : '0.005', $precision), '0', 2);
        $minimum = bcadd((string) $rule['minimum'], '0', 2);
        $amount = bccomp($minimum, $share, 2) > 0 ? $minimum : $share;
        $amount = bccomp($amount, $due, 2) > 0 ? bcadd($due, '0', 2) : $amount;
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return ['amount' => $amount, 'remaining' => bcsub($due, $amount, 2), 'details' => $rule];
    }

    /** The public summary a quote and order resources carry. */
    public static function presentQuote(?array $deposit): ?array
    {
        return $deposit === null ? null : ['amount' => $deposit['amount'], 'remaining' => $deposit['remaining'],
            'percentage' => $deposit['details']['percentage'], 'minimum' => $deposit['details']['minimum'],
            'confirm_after_payment' => (bool) $deposit['details']['confirm_after_payment'], 'remainder_method' => 'cod'];
    }

    public static function present(StoreOrder $order): ?array
    {
        if ($order->deposit_total === null) {
            return null;
        }
        $details = $order->deposit_details ?? [];

        return ['amount' => $order->depositAmount(), 'remaining' => $order->remainderDue(),
            'percentage' => $details['percentage'] ?? null, 'minimum' => $details['minimum'] ?? null,
            'confirm_after_payment' => (bool) ($details['confirm_after_payment'] ?? false), 'remainder_method' => 'cod',
            'paid' => $order->deposit_paid_at !== null, 'paid_at' => $order->deposit_paid_at?->toIso8601String(),
            'remainder_collected' => $order->remainder_collected_at !== null, 'remainder_collected_at' => $order->remainder_collected_at?->toIso8601String()];
    }

    /** "Not confirmed until the deposit is paid": the order waits for the deposit before confirmation. */
    public static function blocksConfirmation(StoreOrder $order): bool
    {
        return $order->deposit_total !== null && $order->deposit_paid_at === null && (bool) ($order->deposit_details['confirm_after_payment'] ?? false);
    }

    /**
     * Record the deposit as paid on a locked order (gateway settlement or a confirmed transfer). Without a
     * remainder the order is fully paid. Returns false when the order has no unpaid deposit.
     */
    public function markPaid(StoreOrder $order, ?string $reference, ?int $actorId = null, ?string $source = 'deposit_payment'): bool
    {
        if ($order->deposit_total === null || $order->deposit_paid_at !== null) {
            return false;
        }
        $remaining = $order->remainderDue();
        $order->forceFill(['deposit_paid_at' => now(), 'payment_reference' => $reference,
            'payment_status' => bccomp($remaining, '0', 2) > 0 ? 'deposit_paid' : 'paid'])->save();
        $order->statusChanges()->create(['store_id' => $order->store_id, 'from_status' => $order->status, 'to_status' => $order->status, 'actor_id' => $actorId,
            'source' => $source, 'notes' => 'Deposit paid: '.$order->currency.' '.$order->depositAmount().($reference ? ' — '.$reference : '')
                .(bccomp($remaining, '0', 2) > 0 ? ' · '.$order->currency.' '.$remaining.' due on delivery' : '')]);
        DB::afterCommit(fn () => StoreAnalyticsService::forget($order->store_id));

        return true;
    }

    /** The merchant records the cash collected on delivery after a paid deposit. */
    public function collectRemainder(StoreOrder $order, string $reference, string $amount, string $currency, int $actorId, ?string $note = null): StoreOrder
    {
        $reference = trim($reference);

        return DB::transaction(function () use ($order, $reference, $amount, $currency, $actorId, $note) {
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $collection = $current->deposit_details['remainder'] ?? null;
            if ($current->remainder_collected_at !== null) {
                if (($collection['reference'] ?? null) !== $reference) {
                    throw ValidationException::withMessages(['reference' => 'The remaining amount was already recorded with another reference.']);
                }

                return $current;
            }
            $remaining = $current->remainderDue();
            if ($reference === '' || $current->deposit_total === null || $current->deposit_paid_at === null
                || in_array($current->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true)
                || ! in_array($current->payment_status, ['deposit_paid', 'partially_refunded'], true) || bccomp($remaining, '0', 2) <= 0
                || bccomp($amount, $remaining, 2) !== 0 || strtoupper($currency) !== strtoupper($current->currency)) {
                throw ValidationException::withMessages(['payment' => 'Confirm the full remaining amount and currency collected on delivery for this order.']);
            }
            $details = $current->deposit_details ?? [];
            $details['remainder'] = ['reference' => $reference, 'amount' => $remaining, 'currency' => strtoupper($current->currency),
                'actor_id' => $actorId, 'collected_at' => now()->toIso8601String(), 'note' => $note];
            $current->forceFill(['deposit_details' => $details, 'remainder_collected_at' => now(),
                'payment_status' => $current->payment_status === 'deposit_paid' ? 'paid' : $current->payment_status])->save();
            $current->statusChanges()->create(['store_id' => $current->store_id, 'from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId,
                'source' => 'deposit_remainder', 'notes' => 'Remaining amount collected on delivery: '.$reference.' — '.$current->currency.' '.$remaining.($note ? "\n".$note : '')]);
            if ($current->payment_status === 'paid') {
                app(DigitalDelivery::class)->record($current);
            }
            DB::afterCommit(fn () => StoreAnalyticsService::forget($current->store_id));

            return $current;
        });
    }
}

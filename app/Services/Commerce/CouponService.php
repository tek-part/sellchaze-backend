<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6D coupon engine. Every query runs inside the CurrentStore tenant
 * (Coupon uses BelongsToStore), so a code from another store simply does not
 * resolve — cross-store redemption is impossible. Validation is fail-closed and
 * is re-run at checkout time, not just at apply time.
 */
class CouponService
{
    /** Find an active coupon of the current store by code (case-insensitive). */
    public function resolveActive(string $code): ?Coupon
    {
        $normalized = Str::upper(trim($code));

        return Coupon::query()
            ->where('code', $normalized)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Assert the coupon may be redeemed for the given subtotal + customer.
     * Throws ValidationException (422) with a `coupon` key on any rule failure.
     */
    public function validate(Coupon $coupon, ?StoreCustomer $customer, string $subtotal): void
    {
        if (! $coupon->is_active) {
            $this->reject('This coupon is no longer active.');
        }
        if ($coupon->starts_at !== null && $coupon->starts_at->isFuture()) {
            $this->reject('This coupon is not yet available.');
        }
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            $this->reject('This coupon has expired.');
        }
        if ($coupon->minimum_order_amount !== null
            && bccomp($subtotal, (string) $coupon->minimum_order_amount, 2) < 0) {
            $this->reject('Your order does not meet this coupon\'s minimum amount.');
        }
        if ($coupon->max_uses !== null
            && $coupon->usages()->count() >= $coupon->max_uses) {
            $this->reject('This coupon has reached its usage limit.');
        }
        if ($coupon->max_uses_per_customer !== null && $customer !== null
            && $coupon->usages()->where('store_customer_id', $customer->id)->count() >= $coupon->max_uses_per_customer) {
            $this->reject('You have already used this coupon the maximum number of times.');
        }
    }

    /** The discount for a subtotal (never more than the subtotal). */
    public function computeDiscount(Coupon $coupon, string $subtotal): string
    {
        if ($coupon->type === 'percentage') {
            $raw = bcdiv(bcmul($subtotal, (string) $coupon->value, 4), '100', 2);
        } else {
            $raw = (string) $coupon->value;
        }

        return bccomp($raw, $subtotal, 2) > 0 ? $subtotal : $raw;
    }

    public function recordUsage(Coupon $coupon, ?StoreCustomer $customer, StoreOrder $order, string $discount): CouponUsage
    {
        return $coupon->usages()->create([
            'store_customer_id' => $customer?->id,
            'store_order_id' => $order->id,
            'discount_amount' => $discount,
            'used_at' => now(),
        ]);
    }

    /** @return never */
    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['coupon' => $message]);
    }
}

<?php

namespace App\Services;

use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Merchant order billing is independent of community posting/organization plans. */
class MerchantSubscriptions
{
    public function ready(): bool
    {
        return (bool) config('platform_wallet.subscriptions.enabled', false)
            && app(PlatformWalletFunding::class)->configured() && ! config('platform_wallet.fawaterak.test_mode')
            && app(PlatformWallet::class)->feesEnabled();
    }

    public function price(string $cycle): int
    {
        abort_unless(in_array($cycle, ['monthly', 'yearly'], true), 422);
        $amount = (int) config('platform_wallet.subscriptions.'.$cycle.'_milli', $cycle === 'monthly' ? 100000 : 600000);
        if ($amount < 10 || $amount > 1000000 || $amount % 10 !== 0) {
            throw new \LogicException('Invalid merchant subscription price.');
        }

        return $amount;
    }

    public function quote(string $cycle): string
    {
        return hash('sha256', 'merchant-subscription:v1:'.$cycle.':'.$this->price($cycle));
    }

    public function active(User $user): ?object
    {
        return DB::table('platform_merchant_subscriptions')->where('user_id', $user->id)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->latest('ends_at')->first();
    }

    public function present(object $row): array
    {
        return ['id' => $row->id, 'cycle' => $row->cycle, 'price_milli' => (int) $row->price_milli,
            'starts_at' => Carbon::parse($row->starts_at)->toIso8601String(), 'ends_at' => Carbon::parse($row->ends_at)->toIso8601String(),
            'renewal' => $row->renewal_of_id !== null];
    }

    private function owner(User $user): void
    {
        abort_unless($user->is_active && ! $user->pending_approval && ! $user->parent_user_id && $user->hasAnyRole(['Merchant', 'Supplier']), 403);
    }

    public function purchase(User $user, string $cycle, string $quote, string $requestKey, bool $autoRenew): object
    {
        $this->owner($user);

        return DB::transaction(function () use ($user, $cycle, $quote, $requestKey, $autoRenew) {
            $this->owner(User::whereKey($user->id)->lockForUpdate()->firstOrFail());
            $prior = DB::table('platform_merchant_subscriptions')->where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($prior) {
                abort_unless($prior->cycle === $cycle && (bool) $prior->initial_auto_renew === $autoRenew && hash_equals(hash('sha256', 'merchant-subscription:v1:'.$cycle.':'.$prior->price_milli), $quote), 409, 'Subscription request changed.');

                return $prior;
            }
            abort_unless($this->ready(), 503, 'MERCHANT_SUBSCRIPTIONS_UNAVAILABLE');
            abort_unless(hash_equals($this->quote($cycle), $quote), 409, 'Subscription price changed. Refresh the offer.');
            abort_if($this->active($user), 409, 'A subscription is already active.');
            $price = $this->price($cycle);
            $entry = app(PlatformWallet::class)->subscriptionCharge($user, $price, 'subscription:'.$user->id.':'.$requestKey, false);
            $id = (string) Str::uuid();
            DB::table('platform_merchant_subscriptions')->insert(['id' => $id, 'user_id' => $user->id, 'request_key' => $requestKey,
                'wallet_entry_id' => $entry->id, 'cycle' => $cycle, 'price_milli' => $price, 'initial_auto_renew' => $autoRenew, 'order_boundary' => (int) DB::table('store_orders')->max('id'),
                'starts_at' => now(), 'ends_at' => $this->end(now(), $cycle), 'created_at' => now(), 'updated_at' => now()]);
            $pref = DB::table('platform_subscription_preferences')->where('user_id', $user->id)->first();
            DB::table('platform_subscription_preferences')->updateOrInsert(['user_id' => $user->id], ['auto_renew' => $autoRenew,
                'version' => (int) ($pref->version ?? 0) + 1, 'created_at' => $pref->created_at ?? now(), 'updated_at' => now()]);

            return DB::table('platform_merchant_subscriptions')->where('id', $id)->firstOrFail();
        });
    }

    private function end(Carbon $start, string $cycle): Carbon
    {
        return $cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    public function preferences(User $user, bool $autoRenew, int $version): object
    {
        $this->owner($user);

        return DB::transaction(function () use ($user, $autoRenew, $version) {
            $this->owner(User::whereKey($user->id)->lockForUpdate()->firstOrFail());
            $pref = DB::table('platform_subscription_preferences')->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $pref->version === $version, 409, 'Subscription preference changed.');
            if ($autoRenew) {
                abort_unless($this->ready() && $this->active($user), 422, 'No renewable active subscription.');
            }
            DB::table('platform_subscription_preferences')->where('user_id', $user->id)->update(['auto_renew' => $autoRenew, 'version' => $version + 1, 'updated_at' => now()]);

            return DB::table('platform_subscription_preferences')->where('user_id', $user->id)->firstOrFail();
        });
    }

    /** A single new period, never a chain of backdated charges after downtime. */
    public function renew(User $user, ?int $orderBoundary = null): ?object
    {
        if (! $this->ready() || ! $user->is_active || $user->pending_approval || $user->parent_user_id || ! $user->hasAnyRole(['Merchant', 'Supplier'])) {
            return null;
        }

        return DB::transaction(function () use ($user, $orderBoundary) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->is_active || $fresh->pending_approval || $fresh->parent_user_id || ! $fresh->hasAnyRole(['Merchant', 'Supplier'])) {
                return null;
            }
            if ($active = $this->active($user)) {
                return $active;
            }
            $pref = DB::table('platform_subscription_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
            $last = DB::table('platform_merchant_subscriptions')->where('user_id', $user->id)->latest('ends_at')->lockForUpdate()->first();
            if (! $pref || ! $pref->auto_renew || ! $last || Carbon::parse($last->ends_at)->isFuture()) {
                return null;
            }
            // Renewal honors the accepted price. Disabling auto-renew stops future charges.
            $entry = app(PlatformWallet::class)->subscriptionCharge($user, (int) $last->price_milli, 'subscription:renew:'.$last->id, true);
            $id = (string) Str::uuid();
            DB::table('platform_merchant_subscriptions')->insert(['id' => $id, 'user_id' => $user->id, 'renewal_of_id' => $last->id,
                'wallet_entry_id' => $entry->id, 'cycle' => $last->cycle, 'price_milli' => $last->price_milli, 'initial_auto_renew' => true,
                'order_boundary' => $orderBoundary ?? (int) DB::table('store_orders')->max('id'), 'starts_at' => now(), 'ends_at' => $this->end(now(), $last->cycle), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('platform_merchant_subscriptions')->where('id', $id)->firstOrFail();
        });
    }

    public function exempt(StoreOrder $order, User $user): bool
    {
        if (DB::table('platform_order_fee_exemptions')->where('store_order_id', $order->id)->exists()) {
            return true;
        }
        if (DB::table('platform_wallet_entries')->where('store_order_id', $order->id)->where('type', 'order_fee')->exists()) {
            return false;
        }
        $active = $this->active($user) ?? $this->renew($user, $order->id - 1);
        if (! $active || $order->id <= (int) $active->order_boundary) {
            return false;
        }
        DB::table('platform_order_fee_exemptions')->insertOrIgnore(['store_order_id' => $order->id, 'user_id' => $user->id, 'subscription_id' => $active->id, 'created_at' => now()]);

        return true;
    }
}

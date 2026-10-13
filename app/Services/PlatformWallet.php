<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Rbac\UserScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Platform USD accounting is separate from store customer payments and legacy B2B balances. */
class PlatformWallet
{
    /** Cutover is explicit and immutable, rather than charging historical orders on deployment. */
    public function activateOrderFees(): int
    {
        return DB::transaction(function () {
            $policy = DB::table('platform_wallet_policy')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($policy->activated_at !== null) {
                return (int) $policy->legacy_order_id;
            }
            $boundary = (int) DB::table('store_orders')->max('id');
            DB::table('platform_wallet_policy')->where('id', 1)->update(['legacy_order_id' => $boundary, 'activated_at' => now()]);

            return $boundary;
        });
    }

    public function feesEnabled(): bool
    {
        return (bool) config('platform_wallet.fees_enabled') && DB::table('platform_wallet_policy')->where('id', 1)->whereNotNull('activated_at')->exists();
    }

    public function ordersLocked(User $owner): bool
    {
        if (app(MerchantSubscriptions::class)->active($owner)) {
            return false;
        }
        $fee = (int) config('platform_wallet.fee_milli');
        $limit = (int) config('platform_wallet.debt_order_limit');

        return $this->feesEnabled() && $fee > 0 && $limit > 0
            && (int) DB::table('platform_wallet_accounts')->where('user_id', $owner->id)->value('balance_milli') <= -$fee * $limit;
    }

    public function assertOrderVisible(StoreOrder $order): void
    {
        if (! config('platform_wallet.fees_enabled')) {
            return;
        }
        $owner = $order->store?->owner;
        abort_if($owner && $this->ordersLocked($owner) && DB::table('platform_wallet_entries')->where('store_order_id', $order->id)->where('type', 'order_fee')->exists(), 402, 'PLATFORM_WALLET_FUNDING_REQUIRED');
    }

    public function assertBridgeVisible(Order $order, User $viewer): void
    {
        if (! config('platform_wallet.fees_enabled') || ! $order->store_order_id || $viewer->hasAnyRole(['Admin', 'Staff', 'Manager'])) {
            return;
        }
        $source = StoreOrder::withoutGlobalScopes()->whereKey($order->store_order_id)->where('store_id', $order->store_id)->first();
        if ($source && (int) $source->store?->owner_user_id === UserScope::effectiveMerchantUserId($viewer)) {
            $this->assertOrderVisible($source);
        }
    }

    public function profile(User $user): object
    {
        return DB::transaction(function () use ($user) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            DB::table('platform_referral_profiles')->insertOrIgnore(['user_id' => $user->id, 'code' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('platform_referral_profiles')->where('user_id', $user->id)->firstOrFail();
        });
    }

    public function attachSignup(User $user, ?string $code): void
    {
        if (! $code || (! $user->hasAnyRole(['Merchant', 'Supplier']) && ! in_array($user->registration_role, ['Merchant', 'Supplier'], true))) {
            return;
        }
        $profile = DB::table('platform_referral_profiles')->join('users', 'users.id', '=', 'platform_referral_profiles.user_id')
            ->where('code', strtolower($code))->where('users.is_active', true)->whereNull('users.deleted_at')
            ->where('users.id', '<', $user->id)->select('platform_referral_profiles.*')->first();
        if ($profile) {
            DB::table('platform_referrals')->insertOrIgnore(['profile_id' => $profile->id, 'invitee_user_id' => $user->id, 'commission_basis_points' => $this->rate(), 'created_at' => now()]);
        }
    }

    public function rate(): int
    {
        $rate = (int) config('platform_wallet.commission_basis_points');
        if ($rate < 0 || $rate > 10000) {
            throw new \LogicException('Invalid platform referral rate.');
        }

        return $rate;
    }

    /** Caller must have independently confirmed receipt; clients cannot credit themselves. */
    public function funding(User $user, int $amount, string $reference, ?User $operator = null): object
    {
        if ($amount <= 0 || $amount > 600000) {
            throw ValidationException::withMessages(['amount_milli' => 'Invalid confirmed funding amount.']);
        }

        return DB::transaction(function () use ($user, $amount, $reference, $operator) {
            $account = $this->lockAccount($user);
            $prior = DB::table('platform_wallet_entries')->where('event_key', 'topup:'.$reference)->first();
            if ($prior) {
                return $this->replay($prior, $account->id, 'topup', $amount, null, null);
            }
            $entry = $this->append($account, 'topup:'.$reference, 'topup', $amount, null, null, $operator?->id);
            $referral = DB::table('platform_referrals')->where('invitee_user_id', $user->id)->first();
            if ($referral && config('platform_wallet.commissions_enabled')) {
                $profile = DB::table('platform_referral_profiles')->where('id', $referral->profile_id)->lockForUpdate()->first();
                DB::table('platform_commission_entries')->insert(['profile_id' => $profile->id, 'event_key' => 'earning:'.$entry->id, 'type' => 'earning', 'amount_milli' => intdiv($amount * $referral->commission_basis_points, 10000), 'wallet_entry_id' => $entry->id, 'recorded_by_user_id' => $operator?->id, 'created_at' => now()]);
            }

            return $entry;
        });
    }

    public function refund(User $user, int $fundingId, int $amount, string $reference, ?User $operator = null): object
    {
        return DB::transaction(function () use ($user, $fundingId, $amount, $reference, $operator) {
            $account = $this->lockAccount($user);
            $original = DB::table('platform_wallet_entries')->where('id', $fundingId)->where('account_id', $account->id)->where('type', 'topup')->first();
            abort_unless($original, 404);
            $existing = DB::table('platform_wallet_entries')->where('event_key', 'refund:'.$reference)->first();
            if ($existing) {
                return $this->replay($existing, $account->id, 'refund', -$amount, $fundingId, null);
            }
            $before = -(int) DB::table('platform_wallet_entries')->where('parent_entry_id', $fundingId)->where('type', 'refund')->sum('amount_milli');
            if ($amount <= 0 || $before + $amount > $original->amount_milli) {
                throw ValidationException::withMessages(['amount_milli' => 'Refund exceeds the confirmed funding.']);
            }
            $entry = $this->append($account, 'refund:'.$reference, 'refund', -$amount, $fundingId, null, $operator?->id);
            $earning = DB::table('platform_commission_entries')->where('wallet_entry_id', $fundingId)->where('type', 'earning')->first();
            if ($earning) {
                DB::table('platform_referral_profiles')->where('id', $earning->profile_id)->lockForUpdate()->first();
                $referral = DB::table('platform_referrals')->where('invitee_user_id', $user->id)->first();
                $target = (int) $earning->amount_milli - intdiv(((int) $original->amount_milli - $before - $amount) * $referral->commission_basis_points, 10000);
                $reversed = -(int) DB::table('platform_commission_entries')->where('earning_entry_id', $earning->id)->where('type', 'reversal')->sum('amount_milli');
                DB::table('platform_commission_entries')->insert(['profile_id' => $earning->profile_id, 'event_key' => 'reversal:'.$entry->id, 'type' => 'reversal', 'amount_milli' => -($target - $reversed), 'wallet_entry_id' => $entry->id, 'earning_entry_id' => $earning->id, 'recorded_by_user_id' => $operator?->id, 'created_at' => now()]);
            }

            return $entry;
        });
    }

    public function orderFee(StoreOrder $order): void
    {
        if (! $this->feesEnabled() || $order->id <= (int) DB::table('platform_wallet_policy')->where('id', 1)->value('legacy_order_id')) {
            return;
        }
        $amount = (int) config('platform_wallet.fee_milli');
        if ($amount < 0 || $amount > 1000000) {
            throw new \LogicException('Invalid platform order fee.');
        }
        $user = $order->store->owner;
        if ($user && $amount > 0) {
            DB::transaction(function () use ($user, $order, $amount) {
                $account = $this->lockAccount($user);
                if (app(MerchantSubscriptions::class)->exempt($order, $user)) {
                    return;
                }
                $this->append($account, 'order:'.$order->id, 'order_fee', -$amount, null, $order->id);
            });
        }
    }

    public function referredStores(int $profile): int
    {
        return DB::table('platform_referrals')->where('profile_id', $profile)->whereExists(function ($q) {
            $q->selectRaw('1')->from('stores')->whereColumn('stores.owner_user_id', 'platform_referrals.invitee_user_id');
        })->count();
    }

    public function subscriptionCharge(User $user, int $amount, string $eventKey, bool $allowDebt): object
    {
        if ($amount <= 0 || $amount > 1000000 || ! str_starts_with($eventKey, 'subscription:') || strlen($eventKey) > 140) {
            throw new \LogicException('Invalid subscription charge.');
        }

        return DB::transaction(function () use ($user, $amount, $eventKey, $allowDebt) {
            $account = $this->lockAccount($user);
            $prior = DB::table('platform_wallet_entries')->where('event_key', $eventKey)->first();
            if ($prior) {
                return $this->replay($prior, $account->id, 'subscription', -$amount, null, null);
            }
            abort_if(! $allowDebt && (int) $account->balance_milli < $amount, 402, 'PLATFORM_WALLET_FUNDING_REQUIRED');

            return $this->append($account, $eventKey, 'subscription', -$amount);
        });
    }

    /** Records an operator-confirmed cash payout; never credits the spendable wallet. */
    public function payout(User $user, int $amount, string $reference, ?User $operator = null): object
    {
        return DB::transaction(function () use ($user, $amount, $reference, $operator) {
            $profile = DB::table('platform_referral_profiles')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($profile, 404);
            $prior = DB::table('platform_commission_entries')->where('event_key', 'payout:'.$reference)->first();
            if ($prior) {
                abort_unless((int) $prior->profile_id === (int) $profile->id && $prior->type === 'payout' && (int) $prior->amount_milli === -$amount, 409);

                return $prior;
            }
            $available = (int) DB::table('platform_commission_entries')->where('profile_id', $profile->id)->sum('amount_milli');
            if ($amount <= 0 || $amount > $available || $this->referredStores($profile->id) < (int) config('platform_wallet.minimum_referred_stores')) {
                throw ValidationException::withMessages(['amount_milli' => 'Insufficient commission or referred stores.']);
            }
            $id = DB::table('platform_commission_entries')->insertGetId(['profile_id' => $profile->id, 'event_key' => 'payout:'.$reference, 'type' => 'payout', 'amount_milli' => -$amount, 'recorded_by_user_id' => $operator?->id, 'created_at' => now()]);

            return DB::table('platform_commission_entries')->where('id', $id)->firstOrFail();
        });
    }

    private function lockAccount(User $user): object
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        DB::table('platform_wallet_accounts')->insertOrIgnore(['user_id' => $user->id, 'currency' => 'USD', 'balance_milli' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('platform_wallet_accounts')->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function replay(object $entry, int $account, string $type, int $amount, ?int $parent, ?int $order): object
    {
        abort_unless((int) $entry->account_id === $account && $entry->type === $type && (int) $entry->amount_milli === $amount && ($entry->parent_entry_id === null ? null : (int) $entry->parent_entry_id) === $parent && ($entry->store_order_id === null ? null : (int) $entry->store_order_id) === $order, 409);

        return $entry;
    }

    private function append(object $account, string $key, string $type, int $amount, ?int $parent = null, ?int $order = null, ?int $operator = null): object
    {
        $prior = DB::table('platform_wallet_entries')->where('event_key', $key)->first();
        if ($prior) {
            return $this->replay($prior, $account->id, $type, $amount, $parent, $order);
        }
        $balance = (int) $account->balance_milli + $amount;
        DB::table('platform_wallet_accounts')->where('id', $account->id)->update(['balance_milli' => $balance, 'updated_at' => now()]);
        $id = DB::table('platform_wallet_entries')->insertGetId(['account_id' => $account->id, 'event_key' => $key, 'type' => $type, 'amount_milli' => $amount, 'balance_after_milli' => $balance, 'parent_entry_id' => $parent, 'store_order_id' => $order, 'recorded_by_user_id' => $operator, 'created_at' => now()]);

        return DB::table('platform_wallet_entries')->where('id', $id)->firstOrFail();
    }
}

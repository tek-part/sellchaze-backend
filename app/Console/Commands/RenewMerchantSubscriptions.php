<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MerchantSubscriptions;
use Illuminate\Console\Command;

class RenewMerchantSubscriptions extends Command
{
    protected $signature = 'merchant-subscriptions:renew {--limit=50}';

    protected $description = 'Renew opted-in merchant plans with immutable wallet charges';

    public function handle(MerchantSubscriptions $billing): int
    {
        if (! $billing->ready()) {
            return self::SUCCESS;
        }
        $ids = User::query()->where('is_active', true)->where('pending_approval', false)->whereNull('parent_user_id')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['Merchant', 'Supplier']))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('platform_subscription_preferences')->whereColumn('user_id', 'users.id')->where('auto_renew', true))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('platform_merchant_subscriptions')->whereColumn('user_id', 'users.id')->where('ends_at', '<=', now()))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('platform_merchant_subscriptions')->whereColumn('user_id', 'users.id')->where('ends_at', '>', now()))
            ->orderBy('id')->limit(min(100, max(1, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            if ($user = User::find($id)) {
                $billing->renew($user);
            }
        }

        return self::SUCCESS;
    }
}

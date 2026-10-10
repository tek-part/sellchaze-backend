<?php

namespace App\Console\Commands;

use App\Models\StorePhoneChallenge;
use Illuminate\Console\Command;

class PruneCheckoutPhoneChallenges extends Command
{
    protected $signature = 'checkout-otp:prune';

    protected $description = 'Clear expired OTP digests and prune unused challenges older than30 days.';

    public function handle(): int
    {
        StorePhoneChallenge::withoutGlobalScopes()->whereNotNull('code_hash')->where('expires_at', '<=', now())->update(['code_hash' => null]);
        $removed = StorePhoneChallenge::withoutGlobalScopes()->whereNull('store_order_id')->where('created_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$removed} unused phone challenges.");

        return self::SUCCESS;
    }
}

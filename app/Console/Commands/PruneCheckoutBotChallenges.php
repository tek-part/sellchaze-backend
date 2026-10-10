<?php

namespace App\Console\Commands;

use App\Models\StoreBotChallenge;
use Illuminate\Console\Command;

class PruneCheckoutBotChallenges extends Command
{
    protected $signature = 'checkout-bot:prune';

    protected $description = 'Prune unused bot checks older than30 days; retain consumed order audit.';

    public function handle(): int
    {
        $count = StoreBotChallenge::withoutGlobalScopes()->whereNull('store_order_id')->where('created_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$count} unused bot checks.");

        return self::SUCCESS;
    }
}

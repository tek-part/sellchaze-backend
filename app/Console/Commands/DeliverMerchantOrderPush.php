<?php

namespace App\Console\Commands;

use App\Services\Commerce\MerchantOrderPush;
use Illuminate\Console\Command;

class DeliverMerchantOrderPush extends Command
{
    protected $signature = 'merchant-push:deliver {--limit=50}';

    protected $description = 'Deliver due merchant order notifications without exposing subscription credentials';

    public function handle(MerchantOrderPush $push): int
    {
        $this->info('Processed '.$push->deliver((int) $this->option('limit')).' delivery records.');

        return self::SUCCESS;
    }
}

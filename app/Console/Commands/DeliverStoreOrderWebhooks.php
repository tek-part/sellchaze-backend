<?php

namespace App\Console\Commands;

use App\Services\Commerce\StoreOrderWebhooks;
use Illuminate\Console\Command;

class DeliverStoreOrderWebhooks extends Command
{
    protected $signature = 'store-webhooks:deliver {--limit=50}';

    protected $description = 'Deliver due merchant webhooks with bounded retries';

    public function handle(StoreOrderWebhooks $webhooks): int
    {
        $this->info('Processed '.$webhooks->deliver((int) $this->option('limit')).' webhook delivery records.');

        return self::SUCCESS;
    }
}

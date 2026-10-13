<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireStoreApiKeyReceipts extends Command
{
    protected $signature = 'store-api-keys:expire-receipts';

    protected $description = 'Clear expired encrypted creation receipts without changing API keys.';

    public function handle(): int
    {
        $count = DB::table('store_api_keys')->whereNotNull('creation_receipt')->where('receipt_expires_at', '<=', now())
            ->update(['creation_receipt' => null, 'receipt_expires_at' => null]);
        $this->info("Expired {$count} API key creation receipts.");

        return self::SUCCESS;
    }
}

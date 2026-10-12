<?php

namespace App\Console\Commands;

use App\Services\Commerce\StoreSheetsDispatch;
use Illuminate\Console\Command;

class DispatchStoreSheets extends Command
{
    protected $signature = 'store-sheets:dispatch {--limit=50}';

    protected $description = 'Deliver pending store order rows to explicitly configured Google Sheets';

    public function handle(StoreSheetsDispatch $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 100) {
            $this->error('Limit must be between1 and100.');

            return self::FAILURE;
        }
        $this->line(json_encode($dispatch->run($limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

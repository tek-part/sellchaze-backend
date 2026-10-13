<?php

namespace App\Console\Commands;

use App\Services\Commerce\StoreOrderExports;
use Illuminate\Console\Command;

class GenerateStoreOrderExports extends Command
{
    protected $signature = 'store-orders:export {--limit=5} {--prune}';

    protected $description = 'Generate private order exports and expire temporary files';

    public function handle(StoreOrderExports $exports): int
    {
        if ($this->option('prune')) {
            $this->info('Removed '.$exports->pruneOrphanedFiles().' expired export files.');

            return self::SUCCESS;
        }
        $this->info('Processed '.$exports->process((int) $this->option('limit')).' order export requests.');

        return self::SUCCESS;
    }
}

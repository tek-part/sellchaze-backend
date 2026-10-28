<?php

namespace App\Console\Commands;

use App\Services\Commerce\PhoneBlockImports;
use Illuminate\Console\Command;

class ProcessStorePhoneBlockImports extends Command
{
    protected $signature = 'store-phone-blocks:import {--limit=3}';

    protected $description = 'Process queued blocked-phone imports and resume interrupted ones';

    public function handle(PhoneBlockImports $imports): int
    {
        $this->info('Processed '.$imports->process((int) $this->option('limit')).' blocked-phone imports.');

        return self::SUCCESS;
    }
}

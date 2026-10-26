<?php

namespace App\Jobs;

use App\Services\Commerce\PhoneBlockImports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Starts an uploaded blocked-phone import; the scheduler resumes it if this worker stops early. */
class ProcessStorePhoneBlockImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $importId) {}

    public function handle(PhoneBlockImports $imports): void
    {
        $imports->run($this->importId);
    }
}

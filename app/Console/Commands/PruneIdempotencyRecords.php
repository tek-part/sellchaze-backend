<?php

namespace App\Console\Commands;

use App\Models\IdempotencyRecord;
use Illuminate\Console\Command;

class PruneIdempotencyRecords extends Command
{
    protected $signature = 'idempotency:prune {--pretend : Report expired records without deleting them}';

    protected $description = 'Delete expired API idempotency records';

    public function handle(): int
    {
        $query = IdempotencyRecord::query()->where('expires_at', '<=', now());
        $count = (clone $query)->count();

        if (! $this->option('pretend')) {
            $query->delete();
        }

        $verb = $this->option('pretend') ? 'Found' : 'Pruned';
        $this->info("{$verb} {$count} expired idempotency record(s).");

        return self::SUCCESS;
    }
}

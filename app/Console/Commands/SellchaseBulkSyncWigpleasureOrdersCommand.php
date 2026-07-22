<?php

namespace App\Console\Commands;

use App\Services\Wigpleasure\WigpleasureOrdersBulkSyncService;
use Illuminate\Console\Command;

class SellchaseBulkSyncWigpleasureOrdersCommand extends Command
{
    protected $signature = 'sellchase:bulk-sync-wigpleasure-orders
                            {--status=* : One or more Wigpleasure store statuses (default: all valid)}
                            {--dry-run : List statuses that would be synced and exit}';

    protected $description = 'Pull orders from the Wigpleasure export API into Sellchase (updates wigpleasure_store_status and other fields).';

    public function handle(WigpleasureOrdersBulkSyncService $bulk): int
    {
        $passed = $this->option('status');
        $statuses = is_array($passed) ? array_values(array_filter($passed)) : [];
        $toSync = $statuses !== [] ? $statuses : WigpleasureOrdersBulkSyncService::VALID_STATUSES;

        if ($this->option('dry-run')) {
            $this->info('Statuses: '.implode(', ', $toSync));

            return self::SUCCESS;
        }

        try {
            $result = $bulk->sync($toSync);
            $this->info(sprintf(
                'created=%d updated=%d skipped=%d',
                $result['created'],
                $result['updated'],
                $result['skipped']
            ));
            if ($result['errors'] !== []) {
                $this->warn('errors='.count($result['errors']));
                foreach (array_slice($result['errors'], 0, 10) as $err) {
                    $this->line(($err['wigpleasure_order_id'] ?? '?').': '.($err['message'] ?? ''));
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

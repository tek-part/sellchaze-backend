<?php

namespace App\Console\Commands;

use App\Models\StoreTrackingEvent;
use App\Services\Commerce\StoreTrackingDispatch;
use Illuminate\Console\Command;

class DispatchStoreTracking extends Command
{
    protected $signature = 'store:tracking-deliver {--limit=50} {--prune : Remove only completed/cancelled delivery payloads older than thirty days}';

    protected $description = 'Deliver queued storefront Conversion API events independently of checkout';

    public function handle(StoreTrackingDispatch $dispatch): int
    {
        if ($this->option('prune')) {
            $count = StoreTrackingEvent::where('occurred_at', '<', now()->subDays(30))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('store_tracking_deliveries')->whereColumn('store_tracking_event_id', 'store_tracking_events.id')->whereIn('status', ['pending', 'retry', 'sending']))->delete();
            $this->line('Expired completed tracking payloads removed: '.$count);

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Choose a limit from 1 to 500.');

            return self::FAILURE;
        }
        $this->line(json_encode($dispatch->run($limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

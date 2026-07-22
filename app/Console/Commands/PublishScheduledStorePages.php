<?php

namespace App\Console\Commands;

use App\Models\Scopes\StoreScope;
use App\Models\StorePage;
use App\Services\PageBuilder\StorePageService;
use Illuminate\Console\Command;

/**
 * Phase 4E (Task 7): automatically publish scheduled pages when publish_at arrives.
 * Runs across all tenants, so it bypasses the fail-closed StoreScope explicitly
 * (there is no CurrentStore in a scheduled command).
 */
class PublishScheduledStorePages extends Command
{
    protected $signature = 'store-pages:publish-due';

    protected $description = 'Publish store pages whose scheduled publish_at time has arrived.';

    public function handle(StorePageService $service): int
    {
        $due = StorePage::query()
            ->withoutGlobalScope(StoreScope::class)
            ->where('status', 'scheduled')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->get();

        foreach ($due as $page) {
            $service->publish($page);
        }

        $this->info("Published {$due->count()} scheduled page(s).");

        return self::SUCCESS;
    }
}

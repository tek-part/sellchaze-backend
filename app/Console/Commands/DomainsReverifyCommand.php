<?php

namespace App\Console\Commands;

use App\Jobs\Domains\RefreshDomainVerificationJob;
use App\Models\StoreDomain;
use Illuminate\Console\Command;

/**
 * Daily re-verification sweep for every custom domain.
 *
 * Dispatches one job per domain rather than checking inline, so a slow resolver
 * cannot stall the scheduler and each check retries independently. Dispatch is
 * spread over a window so a large tenant base does not produce a DNS thundering
 * herd at exactly midnight.
 */
class DomainsReverifyCommand extends Command
{
    protected $signature = 'domains:reverify {--chunk=200} {--spread=3600}';

    protected $description = 'Re-verify DNS ownership for all custom domains and disable stale ones';

    public function handle(): int
    {
        $spread = max(0, (int) $this->option('spread'));

        $query = StoreDomain::query()
            ->where('type', 'custom')
            // Disabled domains are excluded: the owner must re-enable them
            // explicitly, so re-checking would be wasted DNS traffic.
            ->where('status', '!=', StoreDomain::STATUS_DISABLED);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No custom domains to re-verify.');

            return self::SUCCESS;
        }

        $index = 0;

        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($domains) use (&$index, $spread, $total): void {
            foreach ($domains as $domain) {
                $delay = $total > 1 ? (int) round($spread * ($index / $total)) : 0;
                RefreshDomainVerificationJob::dispatch($domain->id)->delay(now()->addSeconds($delay));
                $index++;
            }
        });

        $this->info("Queued {$index} domain re-verification job(s) spread over {$spread}s.");

        return self::SUCCESS;
    }
}

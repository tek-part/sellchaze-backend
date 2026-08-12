<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('outbox:publish --limit=200')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('idempotency:prune')
            ->dailyAt('01:45')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('operations:retention')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Phase 4E: publish scheduled storefront pages when their time arrives.
        $schedule->command('store-pages:publish-due')->everyMinute()->withoutOverlapping();

        // Rebuild the public sitemap and notify Google. Joining a sector already
        // triggers this inline; the nightly run is the safety net that also picks
        // up profile, product and city changes.
        $schedule->command('sitemap:generate --ping')
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->onOneServer();

        // ---- Custom domains (Sprint 2) ----------------------------------
        // Each command only DISPATCHES jobs, so these finish in milliseconds
        // regardless of how many domains exist; the queue does the real work.

        // Daily DNS re-verification. Off-peak, spread over an hour so a large
        // tenant base never becomes a DNS thundering herd.
        $schedule->command('domains:reverify')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->onOneServer();

        // Renew inside the 30-day window, retry failures, poll pending issuance.
        // Twice daily so a renewal failure gets a same-day second chance.
        $schedule->command('domains:renew-certificates')
            ->twiceDaily(4, 16)
            ->withoutOverlapping()
            ->onOneServer();

        // Expiry notifications (90/60/30/15/7/1 days, then expired).
        $schedule->command('domains:monitor-certificates')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

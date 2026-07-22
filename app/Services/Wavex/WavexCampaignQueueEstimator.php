<?php

namespace App\Services\Wavex;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WavexCampaignQueueEstimator
{
    /**
     * Estimate remaining time for a running campaign using the database `jobs` table when
     * QUEUE_CONNECTION=database, plus campaign delay_seconds and pending recipient count.
     *
     * Model: the next job runs at available_at; after each send the chain dispatches with
     * delay_seconds. Remaining wall time ≈ seconds_until_next_job + (pending - 1) * delay_seconds.
     *
     * @return array{
     *     estimated_remaining_seconds: int,
     *     estimated_completion_at: string|null,
     *     next_job_available_at: string|null,
     *     seconds_until_next_job: int,
     *     queue_job_found: bool
     * }
     */
    public static function estimate(int $campaignId, int $delaySeconds, int $pendingCount): array
    {
        $delaySeconds = max(1, $delaySeconds);
        $now = time();

        $secondsUntilNextJob = 0;
        $nextJobAt = null;
        $queueJobFound = false;

        if ($pendingCount > 0 && Schema::hasTable('jobs')) {
            $needle = '"campaignId";i:'.$campaignId.';';
            $row = DB::table('jobs')
                ->where('queue', 'wavex')
                ->where('payload', 'like', '%ProcessWavexCampaignStepJob%')
                ->where('payload', 'like', '%'.$needle.'%')
                ->orderBy('available_at')
                ->first(['available_at']);

            if ($row !== null) {
                $queueJobFound = true;
                $availableAt = (int) $row->available_at;
                $secondsUntilNextJob = max(0, $availableAt - $now);
                $nextJobAt = Carbon::createFromTimestamp($availableAt);
            }
        }

        $estimatedRemaining = $pendingCount <= 0
            ? 0
            : $secondsUntilNextJob + max(0, $pendingCount - 1) * $delaySeconds;

        $completion = $estimatedRemaining > 0
            ? Carbon::now()->addSeconds($estimatedRemaining)
            : null;

        return [
            'estimated_remaining_seconds' => $estimatedRemaining,
            'estimated_completion_at' => $completion?->toIso8601String(),
            'next_job_available_at' => $nextJobAt?->toIso8601String(),
            'seconds_until_next_job' => $secondsUntilNextJob,
            'queue_job_found' => $queueJobFound,
        ];
    }
}

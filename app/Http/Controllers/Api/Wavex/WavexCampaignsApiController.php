<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWavexCampaignStepJob;
use App\Models\WavexCampaign;
use App\Models\WavexCampaignRecipient;
use App\Models\WavexContactGroup;
use App\Models\WavexSetting;
use App\Services\Wavex\WavexCampaignQueueEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WavexCampaignsApiController extends Controller
{
    /* ─────────────────────── Queue Status Dashboard ─────────────────────── */

    public function queueStatus(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // ── 1. Queue worker health ──
        $pendingJobs  = DB::table('jobs')->where('queue', 'wavex')->count();
        $failedJobs   = DB::table('failed_jobs')->where('queue', 'wavex')->count();

        // Check if a job was processed recently (worker alive indicator)
        $lastJobReserved = DB::table('jobs')
            ->where('queue', 'wavex')
            ->whereNotNull('reserved_at')
            ->exists();

        // Check last successful send across all campaigns to determine worker liveness
        $lastSentAt = WavexCampaignRecipient::query()
            ->whereHas('campaign', fn($q) => $q->where('user_id', $userId))
            ->whereNotNull('sent_at')
            ->max('sent_at');

        $workerActive = $lastJobReserved; // a reserved job means a worker picked it up

        // If no reserved jobs but there are pending jobs older than 2 min, worker is likely down
        if (! $workerActive && $pendingJobs > 0) {
            $oldestPending = DB::table('jobs')
                ->where('queue', 'wavex')
                ->min('created_at');
            if ($oldestPending && now()->timestamp - $oldestPending > 120) {
                $workerActive = false;
            } elseif ($pendingJobs === 0) {
                // No pending jobs = nothing to process = worker may be idle (healthy)
                $workerActive = true;
            }
        }

        // If no pending jobs at all, worker is considered healthy (idle)
        if ($pendingJobs === 0 && ! $lastJobReserved) {
            $workerActive = true;
        }

        // ── 2. Aggregate stats from campaign recipients ──
        $stats = WavexCampaignRecipient::query()
            ->whereHas('campaign', fn($q) => $q->where('user_id', $userId))
            ->selectRaw("
                COUNT(CASE WHEN status = 'sent' THEN 1 END) as total_sent,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as total_failed,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as total_pending,
                COUNT(CASE WHEN status = 'queued' THEN 1 END) as total_queued,
                COUNT(CASE WHEN status = 'skipped' THEN 1 END) as total_skipped,
                COUNT(*) as grand_total
            ")
            ->first();

        // ── 3. Active campaigns info ──
        $activeCampaigns = WavexCampaign::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['running', 'paused'])
            ->count();

        $runningCampaigns = WavexCampaign::query()
            ->where('user_id', $userId)
            ->where('status', 'running')
            ->count();

        return response()->json([
            'data' => [
                'worker_active'      => $workerActive,
                'pending_jobs'       => $pendingJobs,
                'failed_jobs'        => $failedJobs,
                'last_sent_at'       => $lastSentAt,
                'queue_connection'   => config('queue.default'),
                'active_campaigns'   => $activeCampaigns,
                'running_campaigns'  => $runningCampaigns,
                'stats' => [
                    'sent'    => (int) ($stats->total_sent ?? 0),
                    'failed'  => (int) ($stats->total_failed ?? 0),
                    'pending' => (int) ($stats->total_pending ?? 0),
                    'queued'  => (int) ($stats->total_queued ?? 0),
                    'skipped' => (int) ($stats->total_skipped ?? 0),
                    'total'   => (int) ($stats->grand_total ?? 0),
                ],
            ],
        ]);
    }

    /* ─────────────────────── CRUD ─────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $q = WavexCampaign::query()->where('user_id', $request->user()->id)->latest();

        return response()->json($q->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message_body' => ['required', 'string', 'max:65535'],
            'template_id' => ['nullable', 'exists:wavex_templates,id'],
            'contact_group_id' => ['nullable', 'integer', 'exists:wavex_contact_groups,id'],
            'delay_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'recipients' => ['required_without:contact_group_id', 'array', 'min:1'],
            'recipients.*.phone' => ['required', 'string', 'max:32'],
            'recipients.*.display_name' => ['nullable', 'string', 'max:255'],
            'recipients.*.jid' => ['nullable', 'string', 'max:128'],
        ]);

        if (! empty($data['contact_group_id']) && ! empty($data['recipients'] ?? [])) {
            throw ValidationException::withMessages([
                'contact_group_id' => ['Use either contact_group_id or recipients, not both.'],
            ]);
        }

        $defaultDelay = (int) WavexSetting::current()->default_campaign_delay_seconds;

        $campaign = DB::transaction(function () use ($request, $data, $defaultDelay) {
            $recipientRows = [];
            $contactGroupId = null;

            if (! empty($data['contact_group_id'])) {
                $group = WavexContactGroup::query()
                    ->where('user_id', $request->user()->id)
                    ->whereKey($data['contact_group_id'])
                    ->firstOrFail();
                $contactGroupId = $group->id;
                $order = 0;
                foreach ($group->members()->orderBy('sort_order')->orderBy('id')->cursor() as $m) {
                    $recipientRows[] = [
                        'sort_order' => $order++,
                        'phone' => $m->phone,
                        'display_name' => $m->display_name,
                        'jid' => null,
                    ];
                }
                if ($recipientRows === []) {
                    throw ValidationException::withMessages([
                        'contact_group_id' => ['The selected contact group has no members.'],
                    ]);
                }
            } else {
                $order = 0;
                foreach ($data['recipients'] as $row) {
                    $recipientRows[] = [
                        'sort_order' => $order++,
                        'phone' => $row['phone'],
                        'display_name' => $row['display_name'] ?? null,
                        'jid' => $row['jid'] ?? null,
                    ];
                }
            }

            $c = WavexCampaign::query()->create([
                'user_id' => $request->user()->id,
                'name' => $data['name'],
                'template_id' => $data['template_id'] ?? null,
                'contact_group_id' => $contactGroupId,
                'message_body' => $data['message_body'],
                'delay_seconds' => $data['delay_seconds'] ?? $defaultDelay,
                'status' => 'draft',
                'total_recipients' => count($recipientRows),
            ]);

            foreach ($recipientRows as $row) {
                WavexCampaignRecipient::query()->create([
                    'campaign_id' => $c->id,
                    'sort_order' => $row['sort_order'],
                    'phone' => $row['phone'],
                    'display_name' => $row['display_name'],
                    'jid' => $row['jid'],
                    'status' => 'pending',
                ]);
            }

            return $c;
        });

        return response()->json(['data' => $campaign->load('recipients')], 201);
    }

    public function show(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign),
        ]);
    }

    public function start(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if ($wavexCampaign->status === 'running') {
            return response()->json(['message' => 'Already running.'], 422);
        }
        if ($wavexCampaign->status === 'paused') {
            return response()->json(['message' => 'Campaign is paused. Use resume instead.'], 422);
        }
        if ($wavexCampaign->recipients()->where('status', 'pending')->doesntExist()) {
            return response()->json(['message' => 'No pending recipients.'], 422);
        }

        $effectiveDelay = WavexSetting::campaignMessageDelaySeconds($wavexCampaign);
        $wavexCampaign->update([
            'status' => 'running',
            'started_at' => now(),
            'cancelled_at' => null,
            'delay_seconds' => $effectiveDelay,
        ]);

        ProcessWavexCampaignStepJob::dispatch($wavexCampaign->id);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function pause(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if ($wavexCampaign->status !== 'running') {
            return response()->json(['message' => 'Campaign is not running.'], 422);
        }

        $wavexCampaign->update(['status' => 'paused']);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function resume(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if ($wavexCampaign->status !== 'paused') {
            return response()->json(['message' => 'Campaign is not paused.'], 422);
        }

        if ($wavexCampaign->recipients()->where('status', 'pending')->doesntExist()) {
            $wavexCampaign->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return response()->json([
                'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
            ]);
        }

        $effectiveDelay = WavexSetting::campaignMessageDelaySeconds($wavexCampaign);
        $wavexCampaign->update([
            'status' => 'running',
            'cancelled_at' => null,
            'delay_seconds' => $effectiveDelay,
        ]);

        ProcessWavexCampaignStepJob::dispatch($wavexCampaign->id);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function retryFailed(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if ($wavexCampaign->status === 'running') {
            return response()->json(['message' => 'Pause or stop the campaign before retrying failed recipients.'], 422);
        }

        $retried = DB::transaction(function () use ($wavexCampaign) {
            $ids = WavexCampaignRecipient::query()
                ->where('campaign_id', $wavexCampaign->id)
                ->where('status', 'failed')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            WavexCampaignRecipient::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'pending',
                    'error_message' => null,
                    'sent_at' => null,
                ]);

            $wavexCampaign->failed_count = WavexCampaignRecipient::query()
                ->where('campaign_id', $wavexCampaign->id)
                ->where('status', 'failed')
                ->count();
            $wavexCampaign->last_error = null;
            $wavexCampaign->save();

            return $ids->count();
        });

        return response()->json([
            'message' => 'OK',
            'retried' => $retried,
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function skipRecipient(Request $request, WavexCampaign $wavexCampaign, int $recipientId): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        $recipient = WavexCampaignRecipient::query()
            ->where('campaign_id', $wavexCampaign->id)
            ->whereKey($recipientId)
            ->firstOrFail();

        if ($recipient->status !== 'pending') {
            return response()->json(['message' => 'Only pending recipients can be excluded from sending.'], 422);
        }

        $recipient->update([
            'status' => 'skipped',
            'error_message' => null,
        ]);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function cancel(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if (! in_array($wavexCampaign->status, ['running', 'paused'], true)) {
            return response()->json(['message' => 'Nothing to cancel.'], 422);
        }

        $wavexCampaign->update([
            'cancelled_at' => now(),
            'status' => 'cancelled',
        ]);

        return response()->json([
            'data' => $this->campaignPayloadWithQueueProgress($wavexCampaign->fresh()),
        ]);
    }

    public function importCsv(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $path = $request->file('file')->getRealPath();
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return response()->json(['message' => 'Could not read file.'], 400);
        }

        $header = fgetcsv($fh);
        $phoneIdx = 0;
        $nameIdx = 1;
        if (is_array($header)) {
            foreach ($header as $i => $col) {
                $l = strtolower(trim((string) $col));
                if (in_array($l, ['phone', 'mobile', 'tel', 'رقم'], true)) {
                    $phoneIdx = $i;
                }
                if (in_array($l, ['name', 'الاسم', 'display'], true)) {
                    $nameIdx = $i;
                }
            }
        }

        $order = (int) $wavexCampaign->recipients()->max('sort_order') + 1;
        $added = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $phone = trim((string) ($row[$phoneIdx] ?? ''));
            if ($phone === '') {
                continue;
            }
            $name = isset($row[$nameIdx]) ? trim((string) $row[$nameIdx]) : null;
            WavexCampaignRecipient::query()->create([
                'campaign_id' => $wavexCampaign->id,
                'sort_order' => $order++,
                'phone' => $phone,
                'display_name' => $name ?: null,
                'status' => 'pending',
            ]);
            $added++;
        }
        fclose($fh);

        $wavexCampaign->update([
            'total_recipients' => $wavexCampaign->recipients()->count(),
        ]);

        return response()->json(['message' => 'OK', 'imported' => $added]);
    }

    public function destroy(Request $request, WavexCampaign $wavexCampaign): JsonResponse
    {
        $this->authorizeCampaign($request, $wavexCampaign);

        if ($wavexCampaign->status === 'running') {
            return response()->json(['message' => 'Cannot delete a running campaign. Cancel it first.'], 422);
        }

        $wavexCampaign->recipients()->delete();
        $wavexCampaign->delete();

        return response()->json(['message' => 'OK']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $campaigns = WavexCampaign::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids'])
            ->where('status', '!=', 'running')
            ->get();

        $deleted = 0;
        foreach ($campaigns as $c) {
            $c->recipients()->delete();
            $c->delete();
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }

    private function authorizeCampaign(Request $request, WavexCampaign $wavexCampaign): void
    {
        if ((int) $wavexCampaign->user_id !== (int) $request->user()->id) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayloadWithQueueProgress(WavexCampaign $wavexCampaign): array
    {
        $wavexCampaign->unsetRelation('recipients');
        $wavexCampaign->refresh();

        $recipients = WavexCampaignRecipient::query()
            ->where('campaign_id', $wavexCampaign->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $pending = $recipients->where('status', 'pending')->count();
        $delay = WavexSetting::campaignMessageDelaySeconds($wavexCampaign);
        $queue = WavexCampaignQueueEstimator::estimate((int) $wavexCampaign->id, $delay, $pending);

        $base = $wavexCampaign->toArray();
        $base['delay_seconds'] = $delay;
        $base['recipients'] = $recipients->values()->all();
        $base['queue_progress'] = array_merge($queue, [
            'pending_recipients' => $pending,
        ]);

        return $base;
    }
}

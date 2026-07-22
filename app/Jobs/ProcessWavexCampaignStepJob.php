<?php

namespace App\Jobs;

use App\Models\WavexCampaign;
use App\Models\WavexSetting;
use App\Models\WavexTemplate;
use App\Services\Wavex\WawpClient;
use App\Support\Wavex\WavexTemplatePlainBody;
use App\Support\WavexPhone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessWavexCampaignStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $campaignId)
    {
        $this->queue = 'wavex';
    }

    public function handle(): void
    {
        $campaign = WavexCampaign::query()->with(['recipients', 'template'])->find($this->campaignId);
        if (! $campaign || $campaign->cancelled_at) {
            return;
        }

        if ($campaign->status !== 'running') {
            return;
        }

        $recipient = $campaign->recipients()
            ->where('status', 'pending')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $recipient) {
            $campaign->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $chatId = $recipient->jid;
            if ($chatId === null || trim((string) $chatId) === '') {
                $chatId = WavexPhone::toChatId($recipient->phone);
            }
            $chatId = trim((string) $chatId);

            $raw = (string) $campaign->message_body;
            $raw = str_replace('{{name}}', (string) ($recipient->display_name ?? ''), $raw);
            $caption = WavexTemplatePlainBody::fromHtml($raw);

            $client = WawpClient::fromSettings();
            $template = $campaign->template;
            $res = $this->sendCampaignMessage($client, $chatId, $caption, $template);

            if ($res['ok'] ?? false) {
                $payload = $res['data'] ?? null;
                if (is_array($payload) && WawpClient::wawpPayloadIsUpstreamQueueOnly($payload)) {
                    $recipient->update([
                        'status' => 'queued',
                        'sent_at' => null,
                        'error_message' => null,
                    ]);
                    $campaign->increment('queued_count');
                    Log::info('Wavex campaign: Wawp accepted into upstream queue.', [
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'job_id' => $payload['job_id'] ?? null,
                    ]);
                } else {
                    $recipient->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'error_message' => null,
                    ]);
                    $campaign->increment('sent_count');
                }
            } else {
                $err = is_array($res['data'] ?? null) ? json_encode($res['data']) : (string) ($res['data'] ?? 'Unknown error');
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => $err,
                ]);
                $campaign->increment('failed_count');
                $campaign->update(['last_error' => $err]);
            }
        } catch (\Throwable $e) {
            Log::warning('Wavex campaign step failed', ['campaign' => $this->campaignId, 'e' => $e->getMessage()]);
            $recipient->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $campaign->increment('failed_count');
            $campaign->update(['last_error' => $e->getMessage()]);
        }

        $campaign->refresh();

        $hasPending = $campaign->recipients()
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            $delaySec = max(1, (int) WavexSetting::campaignMessageDelaySeconds($campaign));
            self::dispatch($this->campaignId)
                ->delay(now()->addSeconds($delaySec))
                ->onQueue('wavex');

            return;
        }

        // Only mark completed when all recipients are in a final state (sent/failed/skipped)
        $hasQueued = $campaign->recipients()
            ->where('status', 'queued')
            ->exists();

        $campaign->update([
            'status' => $hasQueued ? 'paused' : 'completed',
            'completed_at' => $hasQueued ? null : now(),
            'last_error' => $hasQueued
                ? 'Sending finished but ' . $campaign->recipients()->where('status', 'queued')->count() . ' recipients are still queued on Wawp. They may be delivered later.'
                : null,
        ]);
    }

    /**
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function sendCampaignMessage(WawpClient $client, string $chatId, string $caption, ?WavexTemplate $template): array
    {
        if ($template === null || ! $template->media_path || ! $template->media_type) {
            return $client->sendText($chatId, $caption);
        }

        if (! Storage::disk('public')->exists($template->media_path)) {
            Log::warning('Wavex campaign: template media missing on disk; not falling back to text-only send.', [
                'template_id' => $template->id,
                'media_path' => $template->media_path,
                'media_type' => $template->media_type,
            ]);

            return [
                'ok' => false,
                'status' => 422,
                'data' => [
                    'message' => 'Template media file is missing on this server (storage/app/public). Re-upload the template attachment or sync storage; text-only was not sent to avoid a false "sent" state.',
                    'code' => 'wavex_template_media_missing',
                ],
            ];
        }

        $mime = (string) ($template->media_mime ?: 'application/octet-stream');
        $filename = (string) ($template->media_original_name ?: basename($template->media_path));

        return match ($template->media_type) {
            'image' => $client->sendImageData(
                $chatId,
                base64_encode(Storage::disk('public')->get($template->media_path)),
                $filename,
                $mime,
                $caption,
            ),
            'video' => $this->sendCampaignVideo($client, $chatId, $caption, $template, $filename, $mime),
            'document' => $client->sendPdfData(
                $chatId,
                base64_encode(Storage::disk('public')->get($template->media_path)),
                $filename,
                $mime,
                $caption,
            ),
            default => $client->sendText($chatId, $caption),
        };
    }

    /**
     * Prefer file[url] when the public disk URL is HTTP(S) on a public host (not localhost / private IP).
     *
     * @return array{ok: bool, status: int, data: mixed, raw?: string}
     */
    private function sendCampaignVideo(
        WawpClient $client,
        string $chatId,
        string $caption,
        WavexTemplate $template,
        string $filename,
        string $mime,
    ): array {
        $path = $template->media_path;
        $publicUrl = $this->wavexPublicMediaAbsoluteUrl($path);
        if ($this->wawpCanFetchPublicMediaUrl($publicUrl)) {
            return $client->sendVideo($chatId, $publicUrl, $filename, $mime, $caption);
        }

        $binary = Storage::disk('public')->get($path);
        $size = strlen($binary);

        if ($size > 16 * 1024 * 1024) {
            return [
                'ok' => false,
                'status' => 422,
                'data' => [
                    'message' => 'Video file exceeds 16 MB limit for base64 upload. Set WAVEX_PUBLIC_STORAGE_URL to a public HTTPS base URL.',
                    'code' => 'wavex_video_too_large',
                ],
            ];
        }

        Log::info('Wavex campaign video: sending base64 to Wawp (no public URL reachable by Wawp).', [
            'template_id' => $template->id,
            'bytes' => $size,
            'public_url' => $publicUrl,
        ]);

        return $client->sendVideoData(
            $chatId,
            base64_encode($binary),
            $filename,
            $mime,
            $caption,
        );
    }

    /**
     * Absolute URL for a path on the public disk. WAVEX_PUBLIC_STORAGE_URL overrides when APP_URL is not reachable by Wawp.
     */
    private function wavexPublicMediaAbsoluteUrl(string $relativePath): string
    {
        $override = config('services.wavex.public_storage_url');
        if (is_string($override) && $override !== '') {
            return $override.'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
        }

        $publicDisk = trim((string) env('PUBLIC_DISK_URL', ''));
        if ($publicDisk !== '') {
            return rtrim($publicDisk, '/').'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
        }

        return Storage::disk('public')->url($relativePath);
    }

    /**
     * True when Wawp servers can plausibly fetch this URL (public host, not loopback / RFC1918).
     * HTTPS preferred; HTTP allowed for public hostnames so tunnel/dev setups can work if Wawp accepts it.
     */
    private function wawpCanFetchPublicMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}

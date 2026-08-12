<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ProcessCommunityMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $assetId)
    {
        $this->onQueue('media');
    }

    public function handle(): void
    {
        $asset = MediaAsset::query()->find($this->assetId);
        if (! $asset || ! $asset->object_key || ! Storage::disk($asset->disk)->exists($asset->object_key)) {
            return;
        }

        $asset->update(['status' => 'processing', 'failure_reason' => null]);
        try {
            $path = Storage::disk($asset->disk)->path($asset->object_key);
            if ($asset->kind === 'image') {
                $this->processImage($asset, $path);
            }
            if ($asset->kind === 'video') {
                try {
                    $this->processVideo($asset, $path);
                } catch (\Throwable $videoError) {
                    // The original MP4/WebM remains playable when FFmpeg is not
                    // installed on a worker. Record the degraded processing state
                    // without making the uploaded asset unusable.
                    $asset->forceFill(['metadata' => [...($asset->metadata ?? []), 'processing_warning' => mb_substr($videoError->getMessage(), 0, 500)]])->save();
                }
            }
            $asset->update(['status' => 'ready', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $asset->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    private function processImage(MediaAsset $asset, string $path): void
    {
        $info = @getimagesize($path);
        if ($info) {
            $asset->forceFill(['width' => $info[0], 'height' => $info[1], 'metadata' => [...($asset->metadata ?? []), 'orientation' => $info[0] >= $info[1] ? 'landscape' : 'portrait']])->save();
        }
    }

    private function processVideo(MediaAsset $asset, string $path): void
    {
        $ffprobe = (string) config('community.ffprobe_bin');
        $probe = new Process([$ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height,duration,codec_name,bit_rate', '-of', 'json', $path]);
        $probe->setTimeout(60);
        $probe->run();
        if ($probe->isSuccessful()) {
            $data = json_decode($probe->getOutput(), true);
            $stream = $data['streams'][0] ?? [];
            $asset->forceFill([
                'width' => isset($stream['width']) ? (int) $stream['width'] : null,
                'height' => isset($stream['height']) ? (int) $stream['height'] : null,
                'duration_ms' => isset($stream['duration']) ? (int) round(((float) $stream['duration']) * 1000) : null,
                'metadata' => [...($asset->metadata ?? []), 'codec' => $stream['codec_name'] ?? null, 'bitrate' => isset($stream['bit_rate']) ? (int) $stream['bit_rate'] : null],
            ])->save();
        }

        $ffmpeg = (string) config('community.ffmpeg_bin');
        $base = 'community/variants/'.$asset->uuid;
        Storage::disk($asset->disk)->makeDirectory($base);
        $posterKey = $base.'/poster.jpg';
        $posterPath = Storage::disk($asset->disk)->path($posterKey);
        $poster = new Process([$ffmpeg, '-y', '-ss', '00:00:01', '-i', $path, '-frames:v', '1', '-vf', 'scale=720:-2', '-q:v', '3', $posterPath]);
        $poster->setTimeout(120);
        $poster->run();
        if ($poster->isSuccessful() && is_file($posterPath)) {
            $asset->variants()->updateOrCreate(['profile' => 'poster'], ['disk' => $asset->disk, 'object_key' => $posterKey, 'mime' => 'image/jpeg', 'size_bytes' => filesize($posterPath)]);
        }

        // Always create a browser-safe MP4. Phones frequently upload HEVC even
        // inside an .mp4 container, which Chrome/Firefox cannot reliably play.
        $videoCodec = (string) config('community.ffmpeg_h264_encoder');
        $webKey = $base.'/web.mp4';
        $webPath = Storage::disk($asset->disk)->path($webKey);
        $web = new Process([$ffmpeg, '-y', '-i', $path, '-vf', 'scale=1280:-2:force_original_aspect_ratio=decrease', '-c:v', $videoCodec, '-b:v', '2500k', '-maxrate', '3200k', '-bufsize', '5000k', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $webPath]);
        $web->setTimeout(800);
        $web->run();
        if ($web->isSuccessful() && is_file($webPath)) {
            $asset->variants()->updateOrCreate(['profile' => 'web'], ['disk' => $asset->disk, 'object_key' => $webKey, 'mime' => 'video/mp4', 'size_bytes' => filesize($webPath), 'width' => min(1280, (int) ($asset->width ?: 1280))]);
        } else {
            throw new \RuntimeException('Browser video conversion failed: '.mb_substr($web->getErrorOutput(), 0, 1000));
        }

        // Produce a streamable HLS rendition. The original remains available as a
        // fallback; a later worker pool can add more ABR profiles without changing
        // the API contract.
        $hlsKey = $base.'/stream.m3u8';
        $hlsPath = Storage::disk($asset->disk)->path($hlsKey);
        $hls = new Process([$ffmpeg, '-y', '-i', $path, '-vf', 'scale=1280:-2:force_original_aspect_ratio=decrease', '-c:v', $videoCodec, '-b:v', '2500k', '-c:a', 'aac', '-b:a', '128k', '-hls_time', '4', '-hls_playlist_type', 'vod', '-hls_segment_filename', dirname($hlsPath).'/segment-%04d.ts', $hlsPath]);
        $hls->setTimeout(800);
        $hls->run();
        if ($hls->isSuccessful() && is_file($hlsPath)) {
            $asset->variants()->updateOrCreate(['profile' => 'hls'], ['disk' => $asset->disk, 'object_key' => $hlsKey, 'mime' => 'application/vnd.apple.mpegurl', 'size_bytes' => filesize($hlsPath), 'width' => min(1280, (int) ($asset->width ?: 1280))]);
        }
    }
}

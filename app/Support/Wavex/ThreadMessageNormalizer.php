<?php

namespace App\Support\Wavex;

use App\Models\WavexInboxMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ThreadMessageNormalizer
{
    /**
     * @return list<array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}>
     */
    public static function fromWawpPayload(mixed $data): array
    {
        $rows = self::extractMessageRows($data);
        $out = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $norm = self::normalizeWawpRow($row, $i);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}>
     */
    public static function fromInbox(Collection $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (! $m instanceof WavexInboxMessage) {
                continue;
            }
            $at = $m->message_at;
            $sortMs = $at ? (int) round($at->timestamp * 1000) : 0;
            $out[] = [
                'id' => 'wh-'.$m->id,
                'body' => (string) ($m->body ?? ''),
                'direction' => $m->direction === 'out' ? 'out' : 'in',
                'at' => $at?->toIso8601String(),
                'sort_ms' => $sortMs,
                'source' => 'webhook',
                'type' => data_get($m->raw, 'type') ?? data_get($m->raw, 'message.type'),
                'media_url' => data_get($m->raw, 'media_url')
                    ?? data_get($m->raw, 'url')
                    ?? data_get($m->raw, 'message.url')
                    ?? data_get($m->raw, 'deprecatedMms3Url')
                    ?? data_get($m->raw, 'message.deprecatedMms3Url'),
                'media_thumb' => data_get($m->raw, 'media_thumb'),
                'mime' => data_get($m->raw, 'mimetype') ?? data_get($m->raw, 'message.mimetype'),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}>  $wawp
     * @param  list<array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}>  $webhook
     * @return list<array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}>
     */
    public static function merge(array $wawp, array $webhook): array
    {
        $merged = array_merge($wawp, $webhook);
        usort($merged, fn ($a, $b) => ($a['sort_ms'] ?? 0) <=> ($b['sort_ms'] ?? 0));

        $seen = [];
        $deduped = [];
        foreach ($merged as $row) {
            $id = $row['id'] ?? '';
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $deduped[] = $row;
        }

        return $deduped;
    }

    private static function extractMessageRows(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        if (! is_array($data)) {
            return [];
        }
        foreach (['messages', 'data', 'items', 'rows'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $inner = $data[$key];
                if (array_is_list($inner)) {
                    return $inner;
                }
            }
        }

        return [];
    }

    /**
     * @return array{id: string, body: string, direction: string, at: string|null, sort_ms: int, source: string, type?: string, media_url?: string|null, media_thumb?: string|null, mime?: string|null}|null
     */
    private static function normalizeWawpRow(array $row, int $fallbackIndex): ?array
    {
        $hasMediaHint = self::rowIndicatesMedia($row);

        $body = $row['body'] ?? $row['text'] ?? $row['message'] ?? $row['content'] ?? null;
        if ($body !== null && ! is_string($body)) {
            $body = is_array($body) ? json_encode($body) : (string) $body;
        }
        $body = $body !== null ? trim((string) $body) : '';

        $type = (string) ($row['type'] ?? data_get($row, '_data.type') ?? '');

        $mediaUrl = self::extractWawpMediaUrl($row);
        $mime = self::extractWawpMime($row);
        $mediaThumb = self::extractWawpThumbnail($row);

        if ($type === '' && $hasMediaHint && is_string($mime) && $mime !== '') {
            $ml = strtolower($mime);
            if (str_starts_with($ml, 'image/')) {
                $type = 'image';
            } elseif (str_starts_with($ml, 'video/')) {
                $type = 'video';
            } elseif (str_starts_with($ml, 'audio/')) {
                $type = 'audio';
            } else {
                $type = 'document';
            }
        }

        if ($type === '' && $hasMediaHint) {
            if (self::nestedHasKey($row, 'imageMessage') || self::nestedHasKey($row, 'stickerMessage')) {
                $type = 'image';
            } elseif (self::nestedHasKey($row, 'videoMessage')) {
                $type = 'video';
            } elseif (self::nestedHasKey($row, 'audioMessage') || self::nestedHasKey($row, 'pttMessage')) {
                $type = 'audio';
            } elseif (self::nestedHasKey($row, 'documentMessage')) {
                $type = 'document';
            }
        }

        if ($body === '' && ! $hasMediaHint && $type === '' && ! is_string($mediaUrl)) {
            return null;
        }

        $fromMe = $row['fromMe'] ?? $row['from_me'] ?? data_get($row, 'key.fromMe');
        $direction = ($fromMe === true || $fromMe === 1 || $fromMe === '1') ? 'out' : 'in';

        $id = $row['id'] ?? $row['messageId'] ?? data_get($row, 'key.id');
        $id = $id !== null ? (string) $id : 'wawp-'.$fallbackIndex;

        $t = $row['t'] ?? $row['timestamp'] ?? $row['messageTimestamp'] ?? data_get($row, 'messageTimestamp');
        $sortMs = 0;
        $at = null;
        if (is_numeric($t)) {
            $num = (float) $t;
            if ($num < 1e12) {
                $num *= 1000;
            }
            $sortMs = (int) $num;
            try {
                $at = Carbon::createFromTimestampMs($sortMs)->toIso8601String();
            } catch (\Throwable) {
                $at = null;
            }
        }

        // When downloadMedia=true, Wawp may return media as base64 data
        $mediaData = self::extractWawpMediaData($row, $mime);
        $finalMediaUrl = is_string($mediaUrl) && $mediaUrl !== '' ? $mediaUrl : null;

        // If no URL but we have base64 data, create a data URI
        if ($finalMediaUrl === null && is_string($mediaData) && $mediaData !== '') {
            $dataMime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
            $finalMediaUrl = 'data:'.$dataMime.';base64,'.$mediaData;
        }

        $finalThumb = is_string($mediaThumb) && $mediaThumb !== '' ? $mediaThumb : null;

        return [
            'id' => 'wawp:'.$id,
            'body' => $body !== '' ? $body : ($finalMediaUrl !== null ? '[media]' : ''),
            'direction' => $direction,
            'at' => $at,
            'sort_ms' => $sortMs,
            'source' => 'wawp',
            'type' => $type !== '' ? $type : null,
            'media_url' => $finalMediaUrl,
            'media_thumb' => $finalThumb,
            'mime' => is_string($mime) && $mime !== '' ? $mime : null,
        ];
    }

    private static function rowIndicatesMedia(array $row): bool
    {
        if (! empty($row['hasMedia']) || ! empty($row['mimetype']) || ! empty($row['media_url']) || ! empty($row['url']) || ! empty($row['mediaUrl'])) {
            return true;
        }
        foreach (['_data.Message', '_data.message', '_data.RawMessage', 'message'] as $prefix) {
            $msg = data_get($row, $prefix);
            if (! is_array($msg)) {
                continue;
            }
            foreach (['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage', 'pttMessage'] as $k) {
                if (! empty($msg[$k])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function nestedHasKey(array $row, string $leaf): bool
    {
        foreach (['_data.Message', '_data.message', '_data.RawMessage', 'message'] as $prefix) {
            if (! empty(data_get($row, $prefix.'.'.$leaf))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function wawpMediaUrlPaths(): array
    {
        $suffixes = ['URL', 'url', 'Url', 'directPath'];
        $containers = ['_data.Message', '_data.message', '_data.RawMessage', '_data.rawMessage', 'message'];
        $kinds = ['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage'];

        $paths = ['media_url', 'url', 'mediaUrl', 'fileUrl', 'downloadUrl', 'deprecatedMms3Url',
            'media.url', '_data.deprecatedMms3Url', '_data.clientUrl', '_data.mediaUrl'];

        foreach ($containers as $c) {
            foreach ($kinds as $kind) {
                foreach ($suffixes as $suf) {
                    $paths[] = $c.'.'.$kind.'.'.$suf;
                }
            }
        }

        return $paths;
    }

    /**
     * Extract base64 media data from Wawp response (when downloadMedia=true).
     */
    private static function extractWawpMediaData(array $row, ?string $mime): ?string
    {
        // Only check for actual full-resolution media data, NOT thumbnails
        $paths = [
            'media_data', 'mediaData',
            '_data.body', '_data.mediaData',
        ];
        foreach ($paths as $path) {
            $v = data_get($row, $path);
            if (is_string($v) && strlen($v) > 100 && !str_starts_with($v, 'http') && !str_starts_with($v, '<')) {
                // Looks like base64 data
                if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', substr($v, 0, 200))) {
                    return $v;
                }
                if (str_starts_with($v, 'data:')) {
                    return $v;
                }
            }
        }

        return null;
    }

    private static function extractWawpMediaUrl(array $row): ?string
    {
        foreach (self::wawpMediaUrlPaths() as $path) {
            $v = data_get($row, $path);
            if (is_string($v) && str_starts_with($v, 'http')) {
                return $v;
            }
        }

        return null;
    }

    private static function extractWawpMime(array $row): ?string
    {
        $paths = [
            'mimetype', 'mimeType', 'media.mimetype', '_data.mimetype',
            '_data.Message.imageMessage.mimetype', '_data.Message.videoMessage.mimetype',
            '_data.Message.audioMessage.mimetype', '_data.Message.documentMessage.mimetype',
            '_data.message.imageMessage.mimetype', '_data.message.videoMessage.mimetype',
            '_data.message.audioMessage.mimetype', '_data.message.documentMessage.mimetype',
        ];
        foreach ($paths as $path) {
            $v = data_get($row, $path);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private static function extractWawpThumbnail(array $row): ?string
    {
        $paths = [
            '_data.Message.imageMessage.JPEGThumbnail',
            '_data.Message.videoMessage.JPEGThumbnail',
            '_data.message.imageMessage.JPEGThumbnail',
            '_data.message.videoMessage.JPEGThumbnail',
            '_data.Message.imageMessage.jpegThumbnail',
            'media.thumbnail',
        ];
        foreach ($paths as $path) {
            $raw = data_get($row, $path);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            if (str_starts_with($raw, 'data:')) {
                return $raw;
            }

            return 'data:image/jpeg;base64,'.$raw;
        }

        return null;
    }
}

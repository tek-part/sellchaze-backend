<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexInboxMessage;
use App\Models\WavexSetting;
use App\Services\Wavex\WawpClient;
use App\Support\Wavex\ThreadMessageNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class WavexChatsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            // Accepted for API compatibility; not sent to Wawp — v2/chats rejects unknown query keys (400).
            'sortBy' => ['nullable', 'string', 'max:32'],
            'sortOrder' => ['nullable', 'string', 'in:ASC,DESC'],
        ]);

        $extra = array_filter([
            'limit' => $q['limit'] ?? 30,
            'offset' => $q['offset'] ?? 0,
        ], fn ($v) => $v !== null && $v !== '');

        $client = WawpClient::fromSettings();
        $res = $client->listChats($extra);

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }

    /**
     * Unified message thread: Wawp GET /v2/chats/messages + local webhook rows for the same chat.
     */
    public function messages(Request $request, string $chatId): JsonResponse
    {
        $q = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'download_media' => ['nullable', 'boolean'],
        ]);

        $limit = $q['limit'] ?? 50;
        $offset = $q['offset'] ?? 0;
        $client = WawpClient::fromSettings();
        $res = $client->getChatMessages($chatId, [
            'limit' => $limit,
            'offset' => $offset,
            'downloadMedia' => true,
        ]);

        $wawpNorm = $res['ok']
            ? ThreadMessageNormalizer::fromWawpPayload($res['data'] ?? null)
            : [];

        $instanceId = WavexSetting::current()->instance_id;
        $inbox = WavexInboxMessage::query()
            ->when($instanceId, fn ($q2) => $q2->where('instance_id', $instanceId))
            ->where('chat_id', $chatId)
            ->orderBy('message_at')
            ->orderBy('id')
            ->get();

        $hookNorm = ThreadMessageNormalizer::fromInbox($inbox);
        $merged = ThreadMessageNormalizer::merge($wawpNorm, $hookNorm);

        return response()->json([
            'ok' => $res['ok'],
            'wawp_status' => $res['status'] ?? null,
            'data' => [
                'chat_id' => $chatId,
                'messages' => $merged,
            ],
        ], 200);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'max:128'],
            'message' => ['required', 'string', 'max:4096'],
        ]);

        $client = WawpClient::fromSettings();
        $res = $client->sendText($data['chat_id'], $data['message']);

        return response()->json([
            'ok' => $res['ok'],
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }

    public function profilePicture(Request $request): JsonResponse
    {
        $q = $request->validate([
            'contact_id' => ['required', 'string', 'max:128'],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $client = WawpClient::fromSettings();
        $res = $client->contactProfilePicture($q['contact_id'], (bool) ($q['refresh'] ?? false));
        $url = is_array($res['data'] ?? null)
            ? (data_get($res['data'], 'url')
                ?? data_get($res['data'], 'data.url')
                ?? data_get($res['data'], 'profilePictureURL')
                ?? data_get($res['data'], 'profile_picture_url'))
            : null;

        return response()->json([
            'ok' => $res['ok'],
            'url' => $url,
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }

    /**
     * Proxy chat media (Wawp file URLs or WhatsApp CDN) so the SPA can display images with JWT auth.
     */
    public function proxyMedia(Request $request): Response
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:8192'],
        ]);

        $client = WawpClient::fromSettings();
        $res = $client->fetchBinary($data['url']);

        if (! $res['ok']) {
            return response('Media unavailable', $res['status'] >= 400 && $res['status'] < 600 ? $res['status'] : 502);
        }

        return response($res['body'], 200, [
            'Content-Type' => $res['content_type'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function sendMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat_id' => ['required', 'string', 'max:128'],
            'caption' => ['nullable', 'string', 'max:4096'],
            'file' => ['required', 'file', 'max:16384'],
        ]);

        $upload = $request->file('file');
        $mime = $upload->getMimeType() ?: 'application/octet-stream';
        $original = $upload->getClientOriginalName() ?: 'file';
        $bin = @file_get_contents($upload->getRealPath());
        if (! is_string($bin) || $bin === '') {
            return response()->json([
                'ok' => false,
                'data' => ['message' => 'Failed to read uploaded media file.'],
            ], 422);
        }
        $base64 = base64_encode($bin);

        $client = WawpClient::fromSettings();
        $chatId = $data['chat_id'];
        $caption = $data['caption'] ?? null;
        $filenameForWawp = basename($original);

        $isImage = str_starts_with($mime, 'image/')
            && in_array(strtolower($mime), ['image/jpeg', 'image/jpg', 'image/png'], true);

        $lower = strtolower($original);
        $isAudio = str_starts_with($mime, 'audio/')
            || str_ends_with($lower, '.ogg')
            || str_ends_with($lower, '.opus')
            || str_ends_with($lower, '.webm')
            || str_ends_with($lower, '.mp3')
            || str_ends_with($lower, '.m4a')
            || str_ends_with($lower, '.wav');
        $isVideo = str_starts_with($mime, 'video/')
            || str_ends_with($lower, '.mp4')
            || str_ends_with($lower, '.mov')
            || str_ends_with($lower, '.mkv');

        if ($isImage) {
            $res = $client->sendImageData($chatId, $base64, $filenameForWawp, $mime, $caption);
        } elseif ($isVideo) {
            // Save video to public storage and send URL to Wawp (base64 causes 502)
            $tempDir = 'wavex-chat-uploads/' . date('Y-m-d');
            $storedPath = $upload->store($tempDir, 'public');
            $publicStorageUrl = config('services.wavex.public_storage_url');
            if ($publicStorageUrl) {
                $videoUrl = $publicStorageUrl . '/' . $storedPath;
                $res = $client->sendVideo($chatId, $videoUrl, $filenameForWawp, $mime, $caption);
            } else {
                // Fallback: try APP_URL
                $appUrl = rtrim(config('app.url'), '/');
                $videoUrl = $appUrl . '/storage/' . $storedPath;
                // Check if URL is reachable by Wawp
                $host = strtolower(parse_url($videoUrl, PHP_URL_HOST) ?: '');
                if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    $res = $client->sendVideo($chatId, $videoUrl, $filenameForWawp, $mime, $caption);
                } else {
                    // Last resort: base64
                    $res = $client->sendVideoData($chatId, $base64, $filenameForWawp, $mime, $caption);
                }
            }
            // Cleanup temp file after 5 min (async)
            if ($storedPath) {
                dispatch(function () use ($storedPath) {
                    Storage::disk('public')->delete($storedPath);
                })->delay(now()->addMinutes(5));
            }
        } elseif ($isAudio) {
            $voiceMime = str_contains(strtolower($mime), 'ogg') ? 'audio/ogg; codecs=opus' : $mime;
            $res = $client->sendVoiceData($chatId, $base64, $filenameForWawp, $voiceMime, true);
        } else {
            $res = $client->sendPdfData($chatId, $base64, $filenameForWawp, $mime, $caption);
        }

        $msg = null;
        if (is_array($res['data'] ?? null)) {
            $msg = data_get($res['data'], 'message')
                ?? data_get($res['data'], 'error')
                ?? data_get($res['data'], 'errors.0.message')
                ?? data_get($res['data'], 'data.message');
        } elseif (is_string($res['data'] ?? null)) {
            $msg = $res['data'];
        }
        $sentMessage = null;
        if ($res['ok'] && is_array($res['data'] ?? null)) {
            $norm = ThreadMessageNormalizer::fromWawpPayload([$res['data']]);
            $sentMessage = $norm[0] ?? null;
        }

        return response()->json([
            'ok' => $res['ok'],
            'message' => $msg,
            'sent_message' => $sentMessage,
            'data' => $res['data'],
        ], $res['ok'] ? 200 : $res['status']);
    }
}

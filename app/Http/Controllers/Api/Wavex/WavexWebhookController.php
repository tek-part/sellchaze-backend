<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexInboxMessage;
use App\Models\WavexSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WavexWebhookController extends Controller
{
    /**
     * Inbound webhook from Wawp (configure URL in Wawp dashboard).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        try {
            $s = WavexSetting::current();
        } catch (\Throwable) {
            return response()->json(['ok' => true]);
        }

        $instanceId = $s->instance_id ?? $payload['instance_id'] ?? 'unknown';

        // Wawp sends different payload shapes depending on event type
        // Common: { event, data: { from, to, body, fromMe, ... } }
        // Also:   { event, chatId, from, body, fromMe, ... }
        // Also:   { from, body, ... } (flat)
        $data = $payload['data'] ?? $payload;

        // Extract chat_id - try nested 'data' first, then top-level
        $chatId = $this->extractField($payload, $data, ['chatId', 'from', 'chat_id', 'jid']);
        if ($chatId === null || $chatId === '') {
            // Try key.remoteJid for message.any events
            $chatId = data_get($payload, 'data.key.remoteJid')
                ?? data_get($payload, 'key.remoteJid')
                ?? 'unknown';
        }

        // Extract body
        $body = $this->extractField($payload, $data, ['body', 'message', 'text', 'caption']);
        if (is_array($body)) {
            $body = json_encode($body);
        }

        // Extract direction
        $fromMe = data_get($data, 'fromMe')
            ?? data_get($data, 'key.fromMe')
            ?? data_get($payload, 'fromMe')
            ?? data_get($payload, 'data.key.fromMe')
            ?? false;
        $direction = ($fromMe === true || $fromMe === 1 || $fromMe === '1' || $fromMe === 'true') ? 'out' : 'in';

        // Extract message type for media detection
        $type = data_get($data, 'type')
            ?? data_get($payload, 'type')
            ?? null;

        // Only store actual message events, skip ack/status/typing etc.
        $event = strtolower((string) ($payload['event'] ?? ''));
        $skipEvents = ['message.ack', 'presence', 'typing', 'session.status'];
        if ($event !== '' && in_array($event, $skipEvents, true)) {
            return response()->json(['ok' => true]);
        }

        WavexInboxMessage::query()->create([
            'instance_id' => (string) $instanceId,
            'chat_id' => (string) $chatId,
            'direction' => $direction,
            'body' => $body !== null ? (string) $body : null,
            'raw' => is_array($payload) ? $payload : ['raw' => $payload],
            'message_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Try multiple field names in both nested data and top-level payload.
     */
    private function extractField(array $payload, array $data, array $keys): mixed
    {
        // Try nested data first (most Wawp payloads use { data: { ... } })
        foreach ($keys as $key) {
            $v = $data[$key] ?? null;
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        // Try top-level payload
        foreach ($keys as $key) {
            $v = $payload[$key] ?? null;
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }
}

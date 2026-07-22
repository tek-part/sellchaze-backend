<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WavexSettingsApiController extends Controller
{
    public function show(): JsonResponse
    {
        $s = WavexSetting::current();

        return response()->json([
            'data' => [
                'base_url' => $s->base_url,
                'instance_id' => $s->instance_id,
                'access_token_masked' => $s->accessTokenMasked(),
                'connection_status' => $s->connection_status,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
                'default_campaign_delay_seconds' => (int) $s->default_campaign_delay_seconds,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $trimmed = rtrim(trim((string) $value), '/');
                    if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
                        $fail('The API base URL must be a valid URL (e.g. https://api.wawp.net).');

                        return;
                    }
                    $host = strtolower((string) parse_url($trimmed, PHP_URL_HOST));
                    if ($host === '') {
                        $fail('The API base URL must include a host name.');

                        return;
                    }
                    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
                        $fail('The API base URL cannot point to localhost or .local — use https://api.wawp.net for Wawp.');
                    }
                },
            ],
            'instance_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'default_campaign_delay_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ]);

        $s = WavexSetting::current();
        if (! empty($data['base_url'])) {
            $s->base_url = rtrim($data['base_url'], '/');
        }
        if (array_key_exists('instance_id', $data)) {
            $s->instance_id = $data['instance_id'] ?: null;
        }
        if (! empty($data['access_token'] ?? null)) {
            $s->assignAccessToken($data['access_token']);
        }
        if (array_key_exists('default_campaign_delay_seconds', $data) && $data['default_campaign_delay_seconds'] !== null) {
            $s->default_campaign_delay_seconds = $data['default_campaign_delay_seconds'];
        }
        $s->save();

        return $this->show();
    }
}

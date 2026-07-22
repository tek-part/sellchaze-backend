<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class GoogleSettingsApiController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:1024'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
        ]);

        Setting::set('google_client_id', trim($validated['client_id']));
        $secret = $validated['client_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            Setting::set('google_client_secret', $secret);
        }
        Setting::clearCache();

        Config::set('services.google.client_id', (string) Setting::get('google_client_id', ''));
        Config::set('services.google.client_secret', (string) Setting::get('google_client_secret', ''));
        Config::set('services.google.redirect', url('/auth/google/callback'));

        return response()->json([
            'message' => __('Google OAuth settings saved.'),
            ...$this->payload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $clientId = (string) (config('services.google.client_id') ?? '');
        $callbackUrl = url('/auth/google/callback');
        $frontend = rtrim((string) config('sellchase.frontend_url', ''), '/');

        $secretDisplay = $this->effectiveClientSecretForDisplay();

        return [
            'client_id' => $clientId,
            'client_secret' => $secretDisplay,
            'client_secret_configured' => filled($secretDisplay),
            'callback_url' => $callbackUrl,
            'redirect_uri_effective' => filled(config('services.google.redirect'))
                ? (string) config('services.google.redirect')
                : $callbackUrl,
            'javascript_origins' => array_values(array_filter([$frontend !== '' ? $frontend : null])),
            'oauth_configured' => filled($clientId),
            'client_id_masked' => $this->maskClientId($clientId),
        ];
    }

    /**
     * Prefer DB; then runtime config; then .env — for admin UI display only.
     */
    private function effectiveClientSecretForDisplay(): string
    {
        $db = Setting::where('key', 'google_client_secret')->value('value');
        if (is_string($db) && $db !== '') {
            return $db;
        }

        $cfg = (string) (config('services.google.client_secret') ?? '');
        if ($cfg !== '') {
            return $cfg;
        }

        return (string) env('GOOGLE_CLIENT_SECRET', '');
    }

    private function maskClientId(string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        if (strlen($id) <= 16) {
            return substr($id, 0, 4).'…';
        }

        return substr($id, 0, 10).'…'.substr($id, -8);
    }
}

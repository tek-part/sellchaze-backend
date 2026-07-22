<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingsSummaryApiController extends Controller
{
    /**
     * Non-secret mail / OAuth hints for admin UI (configure real values in .env on the server).
     */
    public function show(): JsonResponse
    {
        $mailerKey = (string) config('mail.default', 'smtp');
        $mailerConfig = config("mail.mailers.{$mailerKey}", []);

        return response()->json([
            'mail' => [
                'default_mailer' => $mailerKey,
                'host' => $mailerConfig['host'] ?? null,
                'port' => $mailerConfig['port'] ?? null,
                'encryption' => $mailerConfig['encryption'] ?? null,
                'username_configured' => filled($mailerConfig['username'] ?? null),
                'password_configured' => filled($mailerConfig['password'] ?? null),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
            ],
            'google' => [
                'oauth_configured' => filled(config('services.google.client_id')),
                'client_id_masked' => $this->maskClientId(config('services.google.client_id')),
                'callback_url' => url('/auth/google/callback'),
            ],
        ]);
    }

    private function maskClientId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (strlen($id) <= 16) {
            return substr($id, 0, 4).'…';
        }

        return substr($id, 0, 10).'…'.substr($id, -8);
    }
}

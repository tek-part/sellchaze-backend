<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AppliesMailConfig;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailSettingsApiController extends Controller
{
    use AppliesMailConfig;

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mail_mailer' => ['nullable', 'string', 'in:smtp,log,array'],
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'string', 'max:10'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', 'string', 'in:null,none,tls,ssl'],
            'mail_from_address' => ['required', 'string', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $keys = [
            'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
            'mail_encryption', 'mail_from_address', 'mail_from_name',
        ];
        foreach ($keys as $key) {
            $value = $validated[$key] ?? null;
            if ($key === 'mail_encryption' && in_array($value, ['null', 'none'], true)) {
                $value = null;
            }
            Setting::set($key, $value ?? '');
        }
        if (! empty($validated['mail_password'])) {
            Setting::set('mail_password', $validated['mail_password']);
        }
        Setting::clearCache();

        $this->applyMailConfigFromSettings();

        return response()->json([
            'message' => __('Email settings saved successfully.'),
            ...$this->payload(),
        ]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'email'],
        ]);
        $to = (string) $request->input('to');

        try {
            $this->applyMailConfigFromSettings();
            Mail::mailer('smtp')->raw(
                __('This is a test email from :app. If you received this, your email configuration is working.', ['app' => config('app.name')]),
                function ($message) use ($to) {
                    $message->to($to)
                        ->subject(__('Test email from :app', ['app' => config('app.name')]));
                }
            );

            return response()->json([
                'message' => __('Test email sent to :email.', ['email' => $to]),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => __('Failed to send test email: :message', ['message' => $e->getMessage()]),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $mailHost = Setting::get('mail_host');
        $hasHost = $mailHost !== null && $mailHost !== '';

        return [
            'mail_mailer' => (string) Setting::get('mail_mailer', config('mail.default', 'smtp')),
            'mail_host' => (string) Setting::get('mail_host', ''),
            'mail_port' => (string) Setting::get('mail_port', ''),
            'mail_username' => (string) Setting::get('mail_username', ''),
            'mail_username_configured' => filled(Setting::get('mail_username')),
            'mail_password_configured' => filled(Setting::get('mail_password')),
            'mail_encryption' => (string) (Setting::get('mail_encryption') ?? ''),
            'mail_from_address' => (string) Setting::get('mail_from_address', config('mail.from.address', '')),
            'mail_from_name' => (string) Setting::get('mail_from_name', config('mail.from.name', config('app.name'))),
            'smtp_configured' => $hasHost,
        ];
    }
}

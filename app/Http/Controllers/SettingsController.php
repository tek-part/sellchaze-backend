<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:settings-view', ['only' => ['general', 'email', 'google', 'apis']]);
        $this->middleware('permission:settings-edit', ['only' => ['updateGeneral', 'updateEmail', 'updateGoogle', 'sendTestEmail']]);
    }

    /**
     * General settings page.
     */
    public function general()
    {
        $appName = Setting::get('app_name', config('app.name'));
        return view('pages.settings.general', [
            'title' => __('General settings'),
            'breadcrumb' => __('Settings'),
            'app_name' => $appName,
        ]);
    }

    /**
     * Update general settings.
     */
    public function updateGeneral(Request $request)
    {
        $request->validate([
            'app_name' => ['nullable', 'string', 'max:255'],
        ]);
        if ($request->has('app_name')) {
            Setting::set('app_name', $request->app_name);
        }
        return redirect()->route('settings.general')
            ->with('success', __('Settings saved successfully.'));
    }

    /**
     * Email settings page.
     */
    public function email()
    {
        $settings = [
            'mail_mailer' => Setting::get('mail_mailer', config('mail.default', 'smtp')),
            'mail_host' => Setting::get('mail_host', env('MAIL_HOST', 'mail.wigpleasure.com')),
            'mail_port' => Setting::get('mail_port', env('MAIL_PORT', '465')),
            'mail_username' => Setting::get('mail_username', env('MAIL_USERNAME', 'sellchase@wigpleasure.com')),
            'mail_password' => Setting::get('mail_password', env('MAIL_PASSWORD', '')),
            'mail_encryption' => Setting::get('mail_encryption', env('MAIL_ENCRYPTION', 'ssl')),
            'mail_from_address' => Setting::get('mail_from_address', config('mail.from.address', 'hello@example.com')),
            'mail_from_name' => Setting::get('mail_from_name', config('mail.from.name', config('app.name'))),
        ];
        return view('pages.settings.email', [
            'title' => __('Email settings'),
            'breadcrumb' => __('Settings'),
            'settings' => $settings,
        ]);
    }

    /**
     * Update email settings.
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
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
            $value = $request->input($key);
            if ($key === 'mail_encryption' && in_array($value, ['null', 'none'], true)) {
                $value = null;
            }
            Setting::set($key, $value ?? '');
        }
        if ($request->filled('mail_password')) {
            Setting::set('mail_password', $request->mail_password);
        }
        Setting::clearCache();
        return redirect()->route('settings.email')
            ->with('success', __('Email settings saved successfully.'));
    }

    /**
     * Apply mail config from database so sending uses SMTP from settings.
     */
    private function applyMailConfigFromSettings(): void
    {
        $mailHost = Setting::get('mail_host');
        if ($mailHost === null || $mailHost === '') {
            return;
        }
        Config::set('mail.default', Setting::get('mail_mailer', 'smtp'));
        Config::set('mail.from.address', Setting::get('mail_from_address', config('mail.from.address')));
        Config::set('mail.from.name', Setting::get('mail_from_name', config('mail.from.name')));
        Config::set('mail.mailers.smtp.host', $mailHost);
        Config::set('mail.mailers.smtp.port', Setting::get('mail_port', 465));
        Config::set('mail.mailers.smtp.username', Setting::get('mail_username'));
        Config::set('mail.mailers.smtp.password', Setting::get('mail_password'));
        $enc = Setting::get('mail_encryption');
        Config::set('mail.mailers.smtp.encryption', $enc === 'null' || $enc === 'none' ? null : $enc);
    }

    /**
     * Send a test email to the given address.
     */
    public function sendTestEmail(Request $request)
    {
        $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        $to = $request->test_email;
        try {
            $this->applyMailConfigFromSettings();
            Mail::mailer('smtp')->raw(
                __('This is a test email from :app. If you received this, your email configuration is working.', ['app' => config('app.name')]),
                function ($message) use ($to) {
                    $message->to($to)
                        ->subject(__('Test email from :app', ['app' => config('app.name')]));
                }
            );
            return redirect()->route('settings.email')
                ->with('success', __('Test email sent to :email.', ['email' => $to]));
        } catch (\Throwable $e) {
        return redirect()->route('settings.email')
            ->with('error', __('Failed to send test email: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Google OAuth settings page.
     */
    public function google()
    {
        $settings = [
            'google_client_id' => Setting::get('google_client_id', config('services.google.client_id', '')),
            'google_client_secret' => Setting::get('google_client_secret', config('services.google.client_secret', '')),
        ];
        $redirectUri = url('auth/google/callback');
        return view('pages.settings.google', [
            'title' => __('Google OAuth settings'),
            'breadcrumb' => __('Settings'),
            'settings' => $settings,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Update Google OAuth settings.
     */
    public function updateGoogle(Request $request)
    {
        $request->validate([
            'google_client_id' => ['nullable', 'string', 'max:255'],
            'google_client_secret' => ['nullable', 'string', 'max:255'],
        ]);
        Setting::set('google_client_id', $request->input('google_client_id', ''));
        Setting::set('google_client_secret', $request->input('google_client_secret', ''));
        Setting::clearCache();
        return redirect()->route('settings.google')
            ->with('success', __('Google settings saved successfully.'));
    }

    /**
     * Store APIs settings page - lists all APIs that integrate the store with this dashboard.
     */
    public function apis()
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $apis = [
            [
                'name' => 'Order API v1',
                'method' => 'POST',
                'path' => '/api/v1/orders',
                'url' => $baseUrl . '/api/v1/orders',
                'description' => __('Receive orders from the store (recommended).'),
                'auth' => 'X-API-Key',
                'headers' => [
                    'X-API-Key' => __('Your API key'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ],
            [
                'name' => 'Order API',
                'method' => 'POST',
                'path' => '/api/orders',
                'url' => $baseUrl . '/api/orders',
                'description' => __('Receive orders from the store (legacy).'),
                'auth' => 'X-API-Key',
                'headers' => [
                    'X-API-Key' => __('Your API key'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ],
        ];
        return view('pages.settings.apis', [
            'title' => __('Store APIs'),
            'breadcrumb' => __('Settings'),
            'apis' => $apis,
        ]);
    }

    /**
     * Website settings page (title, description, logo).
     */
    public function website()
    {
        $settings = [
            'site_title' => Setting::get('site_title', config('app.name')),
            'site_description' => Setting::get('site_description', ''),
            'site_logo' => Setting::get('site_logo', ''),
        ];
        return view('pages.settings.website', [
            'title' => __('Website settings'),
            'breadcrumb' => __('Settings'),
            'settings' => $settings,
        ]);
    }

    /**
     * Update website settings.
     */
    public function updateWebsite(Request $request)
    {
        $request->validate([
            'site_title' => ['nullable', 'string', 'max:255'],
            'site_description' => ['nullable', 'string', 'max:500'],
            'site_logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ]);

        if ($request->has('site_title')) {
            Setting::set('site_title', $request->input('site_title', ''));
        }
        if ($request->has('site_description')) {
            Setting::set('site_description', $request->input('site_description', ''));
        }

        if ($request->hasFile('site_logo')) {
            $path = $request->file('site_logo')->store('website', 'public');
            Setting::set('site_logo', $path);
        }
        if ($request->has('remove_logo') && $request->remove_logo) {
            Setting::set('site_logo', '');
        }

        Setting::clearCache();
        return redirect()->route('settings.website')
            ->with('success', __('Settings saved successfully.'));
    }
}

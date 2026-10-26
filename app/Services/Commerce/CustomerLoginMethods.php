<?php

namespace App\Services\Commerce;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * G2: which customer sign-in methods a storefront offers. E-mail is always on; WhatsApp codes need
 * the store's verified WhatsApp connection and Google needs the platform OAuth client plus the
 * administrator's switch. Both are off until the merchant turns them on.
 */
class CustomerLoginMethods
{
    /** Administrator switch, set once the storefront callback URL is registered with Google. */
    public const GOOGLE_SETTING = 'google_storefront_login_enabled';

    public function configuration(Store $store): array
    {
        return array_replace(['phone_otp' => false, 'google' => false, 'version' => 1], $store->customer_login_configuration ?? []);
    }

    public function whatsappReady(Store $store): bool
    {
        return (bool) app(CheckoutPhoneVerification::class)->configuration($store)['verified'] && ! empty($store->phone_otp_credentials);
    }

    public static function googleReady(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'))
            && filter_var(Setting::get(self::GOOGLE_SETTING, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function phoneEnabled(Store $store): bool
    {
        return (bool) $this->configuration($store)['phone_otp'] && $this->whatsappReady($store);
    }

    public function googleEnabled(Store $store): bool
    {
        return (bool) $this->configuration($store)['google'] && self::googleReady();
    }

    public function publicMethods(Store $store): array
    {
        return ['email' => true, 'phone' => $this->phoneEnabled($store), 'google' => $this->googleEnabled($store),
            'phone_country' => app(OrderLimits::class)->configured($store)['phone_country'],
            'expires_in' => CheckoutPhoneVerification::TTL, 'resend_in' => CheckoutPhoneVerification::COOLDOWN];
    }

    public function settings(Store $store): array
    {
        $config = $this->configuration($store);

        return ['phone_otp' => (bool) $config['phone_otp'], 'google' => (bool) $config['google'], 'version' => (int) $config['version'],
            'whatsapp_ready' => $this->whatsappReady($store), 'google_ready' => self::googleReady(),
            'phone_otp_active' => $this->phoneEnabled($store), 'google_active' => $this->googleEnabled($store)];
    }

    public function save(Store $store, array $data): Store
    {
        $message = fn (string $ar, string $en) => app(CheckoutPhoneVerification::class)->message($ar, $en);
        if ($data['phone_otp'] && ! $this->whatsappReady($store) && ! $this->configuration($store)['phone_otp']) {
            throw ValidationException::withMessages(['phone_otp' => $message('اربط اتصال واتساب وتحقق منه أولًا.', 'Connect and verify the store WhatsApp connection first.')]);
        }
        if ($data['google'] && ! self::googleReady() && ! $this->configuration($store)['google']) {
            throw ValidationException::withMessages(['google' => $message('تسجيل الدخول بجوجل غير متاح على المنصة بعد.', 'Google sign-in is not available on the platform yet.')]);
        }

        return DB::transaction(function () use ($store, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $this->configuration($store)['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            $store->update(['customer_login_configuration' => ['phone_otp' => (bool) $data['phone_otp'], 'google' => (bool) $data['google'],
                'version' => (int) $data['version'] + 1]]);

            return $store;
        });
    }
}

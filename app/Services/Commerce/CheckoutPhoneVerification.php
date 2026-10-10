<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StorePhoneChallenge;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Phone possession for checkout, not customer sign-in or a second authentication factor. */
class CheckoutPhoneVerification
{
    public const TTL = 300;

    public const COOLDOWN = 60;

    public function configuration(Store $store): array
    {
        return array_replace(['enabled' => false, 'version' => 1, 'generation' => null, 'verified' => false], $store->phone_otp_configuration ?? []);
    }

    public function publicConfiguration(Store $store): array
    {
        return ['required' => (bool) $this->configuration($store)['enabled'], 'channel' => 'whatsapp', 'expires_in' => self::TTL, 'resend_in' => self::COOLDOWN];
    }

    public function settings(Store $store): array
    {
        $config = $this->configuration($store);

        return ['enabled' => (bool) $config['enabled'], 'version' => $config['version'], 'connection_verified' => (bool) $config['verified'],
            'credentials_configured' => ! empty($store->phone_otp_credentials), 'phone_country' => app(OrderLimits::class)->configured($store)['phone_country'],
            'expires_in' => self::TTL, 'resend_in' => self::COOLDOWN];
    }

    public function save(Store $store, array $data): Store
    {
        $credentials = $data['credentials'] ?? (($data['clear_credentials'] ?? false) ? null : $store->phone_otp_credentials);
        $verified = ! empty($credentials) && $this->configuration($store)['verified'];
        if (isset($data['credentials']) || ($data['verify_connection'] ?? false)) {
            $verified = ! empty($credentials) && app(StoreDigitalWhatsappClient::class)->verify($credentials);
            if (! $verified) {
                throw ValidationException::withMessages(['credentials' => $this->message('تعذّر التحقق من اتصال واتساب. راجع بيانات الاتصال.', 'Unable to verify the WhatsApp connection. Review its credentials.')]);
            }
        }
        if ($data['enabled'] && (! $credentials || ! $verified)) {
            throw ValidationException::withMessages(['enabled' => $this->message('تحقق من اتصال واتساب قبل تفعيل التأكيد.', 'Verify the WhatsApp connection before enabling verification.')]);
        }

        return DB::transaction(function () use ($store, $data, $credentials, $verified) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->configuration($store)['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            $store->update(['phone_otp_credentials' => $credentials, 'phone_otp_configuration' => ['enabled' => (bool) $data['enabled'],
                'version' => (int) $data['version'] + 1, 'generation' => (string) Str::uuid(), 'verified' => (bool) $verified]]);

            return $store;
        });
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public function message(string $ar, string $en): string
    {
        return app(LocaleContext::class)->current() === 'ar' ? $ar : $en;
    }

    private function usable(Store $store, string $phone): void
    {
        $config = $this->configuration($store);
        abort_unless($config['enabled'] && $config['verified'] && $store->phone_otp_credentials, 422, $this->message('تأكيد الهاتف غير متاح حاليًا.', 'Phone verification is currently unavailable.'));
        app(PhoneBlocking::class)->assertAllowed($store, $phone);
        abort_if(app(PhoneBlocking::class)->isBlocked($store, $phone, 'otp'), 422, $this->message('تأكيد الهاتف غير متاح لهذا الرقم. تواصل مع المتجر.', 'Phone verification is unavailable for this number. Contact the store.'));
    }

    /** Durable claim is committed before external I/O. Same nonce retries never resend. */
    public function send(Store $store, string $rawPhone, string $token, string $ip): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = $this->hash($token);
        [$challenge, $credentials, $dispatch] = DB::transaction(function () use ($store, $rawPhone, $code, $tokenHash, $ip) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $phone = app(OrderLimits::class)->normalizePhone($rawPhone, app(OrderLimits::class)->configured($store)['phone_country']);
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => $this->message('أدخل رقم واتساب صحيحًا مع كود الدولة.', 'Enter a valid WhatsApp number with its country code.')]);
            }
            $this->usable($store, $phone);
            $query = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id);
            $existing = (clone $query)->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->phone_normalized === $phone, 409, 'This verification request belongs to a different phone.');

                return [$existing, [], false];
            }
            $phoneQuery = (clone $query)->where('phone_normalized', $phone);
            $recent = (clone $phoneQuery)->orderByDesc('id')->lockForUpdate()->first();
            abort_if($recent && $recent->resend_at->isFuture(), 429, $this->message('انتظر دقيقة قبل طلب رمز جديد.', 'Wait one minute before requesting another code.'));
            $ipHash = $this->hash($ip);
            $hour = now()->subHour();
            abort_if((clone $phoneQuery)->where('created_at', '>', $hour)->count() >= 3
                || (clone $phoneQuery)->where('created_at', '>', now()->subDay())->count() >= 10
                || (clone $query)->where('ip_hash', $ipHash)->where('created_at', '>', $hour)->count() >= 10
                || (clone $query)->where('created_at', '>', $hour)->count() >= 300,
                429, $this->message('تم بلوغ حد إرسال الرموز. حاول لاحقًا.', 'The verification send limit was reached. Try again later.'));
            (clone $phoneQuery)->whereIn('state', ['dispatching', 'sent', 'verified'])->update(['state' => 'superseded', 'code_hash' => null, 'updated_at' => now()]);
            $challenge = StorePhoneChallenge::create(['store_id' => $store->id, 'phone_normalized' => $phone, 'token_hash' => $tokenHash,
                'code_hash' => $this->hash($tokenHash.':'.$code), 'ip_hash' => $ipHash, 'generation' => $this->configuration($store)['generation'],
                'expires_at' => now()->addSeconds(self::TTL), 'resend_at' => now()->addSeconds(self::COOLDOWN), 'state' => 'dispatching']);

            return [$challenge, $store->phone_otp_credentials, true];
        });
        if ($dispatch) {
            $text = $this->message('رمز تأكيد طلبك: ', 'Your checkout verification code: ').$code.$this->message('. صالح لمدة 5 دقائق. لا تشاركه مع أي شخص.', '. Valid for5 minutes. Do not share it.');
            $result = app(StoreDigitalWhatsappClient::class)->send($credentials, ltrim($challenge->phone_normalized, '+'), $text);
            $challenge = DB::transaction(function () use ($store, $challenge, $result) {
                $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $challenge = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id)->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                if ($challenge->state === 'dispatching') {
                    $valid = $this->configuration($store)['generation'] === $challenge->generation;
                    $state = ! $valid ? 'superseded' : ($result['state'] === 'accepted' ? 'sent' : ($result['state'] === 'unknown' ? 'uncertain' : 'failed'));
                    $challenge->update(['state' => $state, 'code_hash' => $state === 'sent' ? $challenge->code_hash : null]);
                }

                return $challenge;
            });
        }

        return $this->response($challenge);
    }

    private function response(StorePhoneChallenge $challenge): array
    {
        return ['state' => $challenge->state, 'phone' => $challenge->phone_normalized, 'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_at' => $challenge->resend_at->toIso8601String(), 'proof_expires_at' => $challenge->proof_expires_at?->toIso8601String()];
    }

    public function verify(Store $store, string $token, string $code): array
    {
        // Failed attempt counts must commit; validation errors are raised AFTER transaction.
        $result = DB::transaction(function () use ($store, $token, $code) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $challenge = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id)->where('token_hash', $this->hash($token))->lockForUpdate()->first();
            abort_unless($challenge, 422, $this->message('الرمز غير صحيح أو انتهت صلاحيته.', 'The code is incorrect or expired.'));
            $this->usable($store, $challenge->phone_normalized);
            $config = $this->configuration($store);
            if ($challenge->generation !== $config['generation'] || $challenge->state !== 'sent' || ! $challenge->expires_at->isFuture() || $challenge->attempts >= 5) {
                if ($challenge->state === 'verified' && $challenge->generation === $config['generation'] && $challenge->proof_expires_at?->isFuture()) {
                    return $this->response($challenge);
                }

                return ['invalid' => true];
            }
            $challenge->increment('attempts');
            if (! hash_equals((string) $challenge->code_hash, $this->hash($challenge->token_hash.':'.$code))) {
                if ($challenge->attempts >= 5) {
                    $challenge->update(['state' => 'failed', 'code_hash' => null]);
                }

                return ['invalid' => true];
            }
            $challenge->update(['state' => 'verified', 'code_hash' => null, 'verified_at' => now(), 'proof_expires_at' => now()->addSeconds(self::TTL)]);

            return $this->response($challenge);
        });
        if (isset($result['invalid'])) {
            throw ValidationException::withMessages(['code' => $this->message('الرمز غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا عند الحاجة.', 'The code is incorrect or expired. Request another code if needed.')]);
        }

        return $result;
    }

    /** Store is locked by checkout. Consumption rolls back with any later checkout failure. */
    public function consume(Store $store, ?string $phone, ?string $token): ?StorePhoneChallenge
    {
        if (! $this->configuration($store)['enabled']) {
            return null;
        }
        if ($phone === null || ! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw ValidationException::withMessages(['phone_verification' => $this->message('أكد رقم الهاتف عبر واتساب قبل إنشاء الطلب.', 'Verify your phone through WhatsApp before placing this order.')]);
        }
        $this->usable($store, $phone);
        $challenge = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id)->where('token_hash', $this->hash($token))->lockForUpdate()->first();
        if (! $challenge || $challenge->phone_normalized !== $phone || $challenge->generation !== $this->configuration($store)['generation']
            || $challenge->state !== 'verified' || ! $challenge->proof_expires_at?->isFuture()) {
            throw ValidationException::withMessages(['phone_verification' => $this->message('انتهى تأكيد الهاتف أو تغير الرقم. أعد التأكيد.', 'Phone verification expired or the phone changed. Verify again.')]);
        }
        $challenge->update(['state' => 'used']);

        return $challenge;
    }
}

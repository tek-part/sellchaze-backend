<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StorePhoneChallenge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * G2: storefront sign-in / sign-up with a code sent through the store's WhatsApp connection.
 *
 * Codes reuse the checkout challenge table (purpose "login"): hashed, 5 minutes, 5 attempts, one
 * resend a minute and shared per-phone/IP/store send limits. Sending answers the same whether or not
 * an account exists. A verified phone signs in to the store's account with that phone (an existing
 * account whose saved phone matches is linked), or creates one once the customer gives a name.
 */
class CustomerPhoneLogin
{
    public const PURPOSE = 'login';

    public function __construct(private readonly CheckoutPhoneVerification $otp, private readonly CustomerLoginMethods $methods) {}

    private function usable(Store $store, string $phone): void
    {
        abort_unless($this->methods->phoneEnabled($store), 422, $this->otp->message('تسجيل الدخول عبر واتساب غير متاح حاليًا.', 'WhatsApp sign-in is currently unavailable.'));
        app(PhoneBlocking::class)->assertAllowed($store, $phone);
        abort_if(app(PhoneBlocking::class)->isBlocked($store, $phone, 'otp'), 422, $this->otp->message('تسجيل الدخول برمز واتساب غير متاح لهذا الرقم. تواصل مع المتجر.', 'WhatsApp sign-in is unavailable for this number. Contact the store.'));
    }

    public function send(Store $store, string $rawPhone, string $token, string $ip): array
    {
        abort_unless($this->methods->phoneEnabled($store), 422, $this->otp->message('تسجيل الدخول عبر واتساب غير متاح حاليًا.', 'WhatsApp sign-in is currently unavailable.'));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = $this->otp->hash($token);
        [$challenge, $credentials, $dispatch] = DB::transaction(function () use ($store, $rawPhone, $code, $tokenHash, $ip) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $phone = app(OrderLimits::class)->normalizePhone($rawPhone, app(OrderLimits::class)->configured($store)['phone_country']);
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => $this->otp->message('أدخل رقم واتساب صحيحًا مع كود الدولة.', 'Enter a valid WhatsApp number with its country code.')]);
            }
            $this->usable($store, $phone);
            $query = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id);
            $existing = (clone $query)->where('token_hash', $tokenHash)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->purpose === self::PURPOSE && $existing->phone_normalized === $phone, 409, 'This verification request belongs to a different phone.');

                return [$existing, [], false];
            }
            $phoneQuery = (clone $query)->where('phone_normalized', $phone);
            $recent = (clone $phoneQuery)->where('purpose', self::PURPOSE)->orderByDesc('id')->lockForUpdate()->first();
            abort_if($recent && $recent->resend_at->isFuture(), 429, $this->otp->message('انتظر دقيقة قبل طلب رمز جديد.', 'Wait one minute before requesting another code.'));
            $ipHash = $this->otp->hash($ip);
            $hour = now()->subHour();
            // Shared with checkout codes: one budget per phone, IP and store.
            abort_if((clone $phoneQuery)->where('created_at', '>', $hour)->count() >= 3
                || (clone $phoneQuery)->where('created_at', '>', now()->subDay())->count() >= 10
                || (clone $query)->where('ip_hash', $ipHash)->where('created_at', '>', $hour)->count() >= 10
                || (clone $query)->where('created_at', '>', $hour)->count() >= 300,
                429, $this->otp->message('تم بلوغ حد إرسال الرموز. حاول لاحقًا.', 'The verification send limit was reached. Try again later.'));
            (clone $phoneQuery)->where('purpose', self::PURPOSE)->whereIn('state', ['dispatching', 'sent', 'verified'])->update(['state' => 'superseded', 'code_hash' => null, 'updated_at' => now()]);
            $challenge = StorePhoneChallenge::create(['store_id' => $store->id, 'purpose' => self::PURPOSE, 'phone_normalized' => $phone, 'token_hash' => $tokenHash,
                'code_hash' => $this->otp->hash($tokenHash.':'.$code), 'ip_hash' => $ipHash, 'generation' => $this->otp->configuration($store)['generation'] ?? '',
                'expires_at' => now()->addSeconds(CheckoutPhoneVerification::TTL), 'resend_at' => now()->addSeconds(CheckoutPhoneVerification::COOLDOWN), 'state' => 'dispatching']);

            return [$challenge, $store->phone_otp_credentials, true];
        });
        if ($dispatch) {
            $text = $this->otp->message('رمز تسجيل الدخول إلى '.$store->name.': ', 'Your sign-in code for '.$store->name.': ').$code
                .$this->otp->message('. صالح لمدة 5 دقائق. لا تشاركه مع أي شخص.', '. Valid for 5 minutes. Do not share it.');
            $result = app(StoreDigitalWhatsappClient::class)->send($credentials, ltrim($challenge->phone_normalized, '+'), $text);
            $challenge = DB::transaction(function () use ($store, $challenge, $result) {
                $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $challenge = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id)->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                if ($challenge->state === 'dispatching') {
                    $valid = ($this->otp->configuration($store)['generation'] ?? '') === $challenge->generation;
                    $state = ! $valid ? 'superseded' : ($result['state'] === 'accepted' ? 'sent' : ($result['state'] === 'unknown' ? 'uncertain' : 'failed'));
                    $challenge->update(['state' => $state, 'code_hash' => $state === 'sent' ? $challenge->code_hash : null]);
                }

                return $challenge;
            });
        }

        return ['state' => $challenge->state, 'phone' => $challenge->phone_normalized, 'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_at' => $challenge->resend_at->toIso8601String()];
    }

    /**
     * @return array{customer: StoreCustomer, token: string}|array{name_required: true}
     */
    public function verify(Store $store, string $token, ?string $code, ?string $name): array
    {
        $result = DB::transaction(function () use ($store, $token, $code, $name) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $challenge = StorePhoneChallenge::withoutGlobalScopes()->where('store_id', $store->id)->where('purpose', self::PURPOSE)
                ->where('token_hash', $this->otp->hash($token))->lockForUpdate()->first();
            if (! $challenge) {
                return ['invalid' => true];
            }
            $this->usable($store, $challenge->phone_normalized);
            $generation = $this->otp->configuration($store)['generation'] ?? '';
            // A proven phone waiting for the customer's name needs no second code.
            $proven = $challenge->state === 'verified' && $challenge->generation === $generation && $challenge->proof_expires_at?->isFuture();
            if (! $proven) {
                if ($code === null || $challenge->generation !== $generation || $challenge->state !== 'sent' || ! $challenge->expires_at->isFuture() || $challenge->attempts >= 5) {
                    return ['invalid' => true];
                }
                $challenge->increment('attempts');
                if (! hash_equals((string) $challenge->code_hash, $this->otp->hash($challenge->token_hash.':'.$code))) {
                    if ($challenge->attempts >= 5) {
                        $challenge->update(['state' => 'failed', 'code_hash' => null]);
                    }

                    return ['invalid' => true];
                }
            }
            $customer = $this->find($store, $challenge->phone_normalized);
            $name = is_string($name) ? trim($name) : '';
            if (! $customer && $name === '') {
                if (! $proven) {
                    $challenge->update(['state' => 'verified', 'code_hash' => null, 'verified_at' => now(), 'proof_expires_at' => now()->addSeconds(CheckoutPhoneVerification::TTL)]);
                }

                return ['name_required' => true];
            }
            if ($customer && ! $customer->is_active) {
                $challenge->update(['state' => 'used', 'code_hash' => null]);

                return ['unavailable' => true];
            }
            $customer ??= $this->create($store, $challenge->phone_normalized, $name);
            $challenge->update(['state' => 'used', 'code_hash' => null, 'verified_at' => $challenge->verified_at ?? now()]);
            $customer->forceFill(['last_login_at' => now(), 'phone_normalized' => $challenge->phone_normalized])->save();

            return ['customer' => $customer, 'token' => app(CustomerAuthService::class)->issueToken($customer)];
        });
        if (isset($result['invalid'])) {
            throw ValidationException::withMessages(['code' => $this->otp->message('الرمز غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا عند الحاجة.', 'The code is incorrect or expired. Request another code if needed.')]);
        }
        if (isset($result['unavailable'])) {
            throw ValidationException::withMessages(['code' => $this->otp->message('تعذّر تسجيل الدخول. تواصل مع المتجر.', 'We could not sign you in. Contact the store.')]);
        }

        return $result;
    }

    /** The store account behind a verified phone: its verified identity, else an account whose saved phone matches. */
    public function find(Store $store, string $phone): ?StoreCustomer
    {
        $accounts = StoreCustomer::query()->withoutGlobalScopes()->where('store_id', $store->id);
        $verified = (clone $accounts)->where('phone_normalized', $phone)->first();
        if ($verified) {
            return $verified;
        }
        $country = app(OrderLimits::class)->configured($store)['phone_country'];
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -7);

        // Saved phones are stored as typed; narrow by the last digits, then compare normalized.
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', '')";

        return $accounts->whereNull('phone_normalized')->whereNotNull('phone')->whereRaw($digits.' LIKE ?', ['%'.$tail])->orderBy('id')->limit(50)->get()
            ->first(fn (StoreCustomer $account) => app(OrderLimits::class)->normalizePhone($account->phone, $country) === $phone);
    }

    private function create(Store $store, string $phone, string $name): StoreCustomer
    {
        try {
            return DB::transaction(fn () => StoreCustomer::create(['store_id' => $store->id, 'name' => mb_substr($name, 0, 255), 'phone' => $phone,
                'phone_normalized' => $phone, 'email' => null, 'password' => null, 'is_active' => true]));
        } catch (QueryException) {
            // Another request created the same phone identity first.
            return StoreCustomer::query()->withoutGlobalScopes()->where('store_id', $store->id)->where('phone_normalized', $phone)->firstOrFail();
        }
    }
}

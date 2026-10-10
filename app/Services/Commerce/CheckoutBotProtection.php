<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreBotChallenge;
use App\Services\Stores\StoreDomainResolver;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutBotProtection
{
    public const ACTION = 'sellchaze_checkout';

    public function configuration(Store $store): array
    {
        return array_replace(['enabled' => false, 'version' => 1, 'generation' => null], $store->bot_protection_configuration ?? []);
    }

    public function hostnames(Store $store): array
    {
        $hosts = $store->servableDomains()->pluck('host')->all();
        if ($store->slug) {
            $hosts[] = $store->slug.'.'.app(StoreDomainResolver::class)->baseDomain();
        }
        if (app()->environment(['local', 'testing'])) {
            $hosts = array_merge($hosts, ['localhost', '127.0.0.1']);
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    public function publicConfiguration(Store $store): array
    {
        $config = $this->configuration($store);

        return ['required' => (bool) $config['enabled'], 'provider' => 'turnstile', 'action' => self::ACTION,
            'site_key' => $config['enabled'] ? ($store->bot_protection_credentials['site_key'] ?? null) : null,
            'revision' => $config['version']];
    }

    public function settings(Store $store): array
    {
        return $this->publicConfiguration($store) + ['enabled' => (bool) $this->configuration($store)['enabled'],
            'version' => $this->configuration($store)['version'], 'credentials_configured' => ! empty($store->bot_protection_credentials),
            'allowed_hostnames' => $this->hostnames($store), 'provider_acceptance' => 'not_verified_by_settings_save'];
    }

    public function save(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->configuration($store)['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            $keys = $data['credentials'] ?? (($data['clear_credentials'] ?? false) ? null : $store->bot_protection_credentials);
            if (! app()->environment(['local', 'testing']) && $keys && (preg_match('/^[123]x0{18,}/', $keys['site_key']) || preg_match('/^[123]x0{18,}/', $keys['secret_key']))) {
                throw ValidationException::withMessages(['credentials' => 'Turnstile test keys cannot be saved in production.']);
            }
            if ($data['enabled'] && (! $keys || ! $this->hostnames($store))) {
                throw ValidationException::withMessages(['enabled' => $this->message('أضف مفاتيح Turnstile ونطاق المتجر قبل التفعيل.', 'Add Turnstile keys and a store hostname before enabling protection.')]);
            }
            $store->update(['bot_protection_credentials' => $keys, 'bot_protection_configuration' => ['enabled' => (bool) $data['enabled'],
                'version' => (int) $data['version'] + 1, 'generation' => (string) Str::uuid()]]);

            return $store;
        });
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function message(string $ar, string $en): string
    {
        return app(CheckoutPhoneVerification::class)->message($ar, $en);
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['bot_proof' => $this->message('أكمل فحص الحماية قبل إنشاء الطلب. أعد المحاولة إذا انتهت صلاحيته.', 'Complete the security check before placing the order. Retry if it expired.')]);
    }

    /** Persist the claim before I/O; same request never spends the provider token twice. */
    public function verify(Store $store, string $nonce, string $token, string $ip): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $nonce) || ! $token || strlen($token) > 2048) {
            $this->unavailable();
        }
        $nonceHash = $this->hash($nonce);
        $tokenHash = $this->hash($token);
        [$challenge, $keys, $dispatch] = DB::transaction(function () use ($store, $nonceHash, $tokenHash, $ip) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->configuration($store)['enabled'] && $store->bot_protection_credentials, 422, 'Bot protection is unavailable.');
            $query = StoreBotChallenge::withoutGlobalScopes()->where('store_id', $store->id);
            $existing = (clone $query)->where('nonce_hash', $nonceHash)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->provider_token_hash, $tokenHash) && hash_equals($existing->ip_hash, $this->hash($ip)), 409, 'Verification request changed.');

                return [$existing, [], false];
            }
            $ipHash = $this->hash($ip);
            abort_if((clone $query)->where('ip_hash', $ipHash)->where('created_at', '>', now()->subMinutes(10))->count() >= 40
                || (clone $query)->where('created_at', '>', now()->subHour())->count() >= 1000, 429, 'Security verification limit reached. Try later.');
            // Global token digest prevents the same provider proof being claimed by another tenant.
            abort_if(StoreBotChallenge::withoutGlobalScopes()->where('provider_token_hash', $tokenHash)->exists(), 422, 'Security verification cannot be reused.');
            try {
                $challenge = StoreBotChallenge::create(['store_id' => $store->id, 'nonce_hash' => $nonceHash, 'provider_token_hash' => $tokenHash,
                    'ip_hash' => $ipHash, 'generation' => $this->configuration($store)['generation'], 'state' => 'checking']);
            } catch (UniqueConstraintViolationException) {
                $this->unavailable();
            }

            return [$challenge, $store->bot_protection_credentials, true];
        });
        if ($dispatch) {
            $data = null;
            try {
                $response = Http::asJson()->acceptJson()->timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                    ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $keys['secret_key'], 'response' => $token, 'remoteip' => $ip]);
                if ($response->successful() && is_array($response->json())) {
                    $data = $response->json();
                }
            } catch (\Throwable) { /* Unknown delivery/response fails closed, without private error logging. */
            }
            $expiry = null;
            try {
                if (is_array($data) && ($data['success'] ?? null) === true && ($data['action'] ?? null) === self::ACTION
                    && is_string($data['cdata'] ?? null) && hash_equals($nonce, $data['cdata'])
                    && is_string($data['challenge_ts'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $data['challenge_ts'])) {
                    $timestamp = Carbon::parse($data['challenge_ts']);
                    if ($timestamp->lte(now()->addSeconds(5)) && $timestamp->copy()->addSeconds(300)->isFuture()) {
                        $expiry = $timestamp->addSeconds(300);
                    }
                }
            } catch (\Throwable) { /* Missing/malformed provider fields never grant a proof. */
            }
            $challenge = DB::transaction(function () use ($store, $challenge, $data, $expiry) {
                $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $challenge = StoreBotChallenge::withoutGlobalScopes()->where('store_id', $store->id)->whereKey($challenge->id)->lockForUpdate()->firstOrFail();
                $valid = $expiry && $expiry->isFuture() && $this->configuration($store)['enabled']
                    && $this->configuration($store)['generation'] === $challenge->generation
                    && is_string($data['hostname'] ?? null) && in_array(strtolower($data['hostname']), $this->hostnames($store), true);
                $challenge->update(['state' => $valid ? 'verified' : 'failed', 'expires_at' => $valid ? $expiry : null]);

                return $challenge;
            });
        }
        $store->refresh();
        $valid = $challenge->state === 'verified' && $challenge->expires_at?->isFuture()
            && $this->configuration($store)['enabled'] && $challenge->generation === $this->configuration($store)['generation'];

        return ['state' => $valid ? 'verified' : ($challenge->state === 'checking' ? 'checking' : 'failed'), 'expires_at' => $valid ? $challenge->expires_at->toIso8601String() : null];
    }

    /** Called only under checkout's Store lock; later order failure rolls consumption back. */
    public function consume(Store $store, ?string $nonce, ?string $ip): ?StoreBotChallenge
    {
        if (! $this->configuration($store)['enabled']) {
            return null;
        }
        if (! is_string($nonce) || ! preg_match('/^[a-f0-9]{64}$/', $nonce) || ! is_string($ip)) {
            $this->unavailable();
        }
        $proof = StoreBotChallenge::withoutGlobalScopes()->where('store_id', $store->id)->where('nonce_hash', $this->hash($nonce))->lockForUpdate()->first();
        if (! $proof || $proof->state !== 'verified' || ! $proof->expires_at?->isFuture()
            || $proof->generation !== $this->configuration($store)['generation'] || ! hash_equals($proof->ip_hash, $this->hash($ip))) {
            $this->unavailable();
        }
        $proof->update(['state' => 'used']);

        return $proof;
    }
}

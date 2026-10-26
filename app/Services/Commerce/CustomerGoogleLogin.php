<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerLoginCode;
use App\Models\StoreCustomerPasswordReset;
use App\Models\StoreCustomerToken;
use App\Models\StorePhoneChallenge;
use App\Services\Stores\StoreDomainResolver;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * G2: Google sign-in for storefront customers on any store host or custom domain.
 *
 * Google only redirects to registered URIs, so every store uses one fixed callback on the API host
 * (APP_URL). The storefront asks for a start URL; its state is HMAC-signed, expires in 10 minutes
 * and binds the store, the storefront origin, a nonce and the SHA-256 of a browser-held verifier.
 * The callback exchanges Google's code server-side, verifies the ID token (signature via Google's
 * tokeninfo, audience, issuer, expiry, nonce, verified e-mail), links or creates the customer within
 * that store and returns to the storefront with a one-time code. Only the browser that started the
 * flow (it holds the verifier) can exchange that code, within 60 seconds, for a customer token.
 */
class CustomerGoogleLogin
{
    public const STATE_TTL = 600;

    /** Short: the code crosses the storefront page before the boot script clears it from the URL. */
    public const CODE_TTL = 60;

    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const TOKEN_INFO = 'https://oauth2.googleapis.com/tokeninfo';

    public static function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/storefront-oauth/google/callback';
    }

    /** The Google consent URL for this storefront origin; `challenge` is hex SHA-256 of the browser's verifier. */
    public function start(Store $store, string $origin, string $challenge): string
    {
        $nonce = Str::random(32);
        $state = $this->sign(['s' => $store->id, 'o' => $origin, 'c' => $challenge, 'n' => $nonce, 'e' => now()->addSeconds(self::STATE_TTL)->getTimestamp()]);

        return self::AUTHORIZE.'?'.http_build_query(['client_id' => (string) config('services.google.client_id'), 'redirect_uri' => self::callbackUrl(),
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce, 'prompt' => 'select_account']);
    }

    /** Verified state payload, or null when tampered with or expired. */
    public function state(?string $state): ?array
    {
        if (! is_string($state) || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $state, $parts)
            || ! hash_equals($this->mac($parts[1]), $parts[2])) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($payload) || ! is_int($payload['s'] ?? null) || ! is_string($payload['o'] ?? null) || ! is_string($payload['c'] ?? null)
            || ! is_string($payload['n'] ?? null) || ! is_int($payload['e'] ?? null) || $payload['e'] < now()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /** The storefront store behind a verified state, still on the same host and still offering Google. */
    public function store(array $state): ?Store
    {
        $host = parse_url($state['o'], PHP_URL_HOST);
        $resolved = is_string($host) ? app(StoreDomainResolver::class)->resolveContext($host) : null;
        if (! $resolved || $resolved->store->id !== $state['s'] || ! app(CustomerLoginMethods::class)->googleEnabled($resolved->store)) {
            return null;
        }

        return $resolved->store;
    }

    /** Exchange Google's code, sign the customer in to the store and return a one-time storefront code. */
    public function complete(Store $store, array $state, string $code): string
    {
        $identity = $this->identity($code, $state['n']);
        app(CurrentStore::class)->set($store);
        $customer = $this->customer($store, $identity);
        $plain = Str::random(64);
        StoreCustomerLoginCode::create(['store_id' => $store->id, 'store_customer_id' => $customer->id, 'code_hash' => hash('sha256', $plain),
            'verifier_hash' => $state['c'], 'expires_at' => now()->addSeconds(self::CODE_TTL)]);

        return $plain;
    }

    /** One use, this store, the browser holding the verifier, within a minute. */
    public function exchange(Store $store, string $code, string $verifier): ?StoreCustomer
    {
        return DB::transaction(function () use ($store, $code, $verifier) {
            $row = StoreCustomerLoginCode::query()->withoutGlobalScopes()->where('store_id', $store->id)
                ->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $row || $row->used_at !== null) {
                return null;
            }
            $row->update(['used_at' => now()]);
            if (! $row->expires_at->isFuture() || ! hash_equals($row->verifier_hash, hash('sha256', $verifier))) {
                return null;
            }
            $customer = StoreCustomer::query()->withoutGlobalScopes()->where('store_id', $store->id)->whereKey($row->store_customer_id)->first();
            if (! $customer?->is_active) {
                return null;
            }
            $customer->forceFill(['last_login_at' => now()])->save();

            return $customer;
        });
    }

    /** @return array{sub: string, email: string, name: string} */
    private function identity(string $code, string $nonce): array
    {
        $clientId = (string) config('services.google.client_id');
        $token = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5)->post(self::TOKEN, ['code' => $code, 'client_id' => $clientId,
            'client_secret' => (string) config('services.google.client_secret'), 'redirect_uri' => self::callbackUrl(), 'grant_type' => 'authorization_code']);
        $idToken = $token->successful() ? $token->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('Google did not return an ID token.');
        }
        $info = Http::acceptJson()->timeout(15)->connectTimeout(5)->get(self::TOKEN_INFO, ['id_token' => $idToken]);
        $claims = $info->successful() ? $info->json() : null;
        if (! is_array($claims) || ($claims['aud'] ?? null) !== $clientId
            || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || (int) ($claims['exp'] ?? 0) < now()->getTimestamp() || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || ! in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || ! filter_var($claims['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The Google ID token was not accepted.');
        }
        $email = Str::lower(trim($claims['email']));

        return ['sub' => $claims['sub'], 'email' => $email, 'name' => trim((string) ($claims['name'] ?? '')) ?: Str::before($email, '@')];
    }

    /**
     * Linked Google account, else the store account with that e-mail, else a new account.
     *
     * Registration never proves the inbox, so an account found only by an unverified e-mail may have
     * been created by someone else who added their own credentials. Google proves the inbox: the
     * account passes to its owner — every credential another person could have added is revoked (see
     * revokeCredentials; the owner can set a password and confirm a phone from the account page)
     * before it is linked and marked verified.
     */
    private function customer(Store $store, array $identity): StoreCustomer
    {
        return DB::transaction(function () use ($store, $identity) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $accounts = StoreCustomer::query()->withoutGlobalScopes()->where('store_id', $store->id);
            $customer = (clone $accounts)->where('google_sub', $identity['sub'])->lockForUpdate()->first();
            if ($customer) {
                if (! $customer->is_active) {
                    throw new RuntimeException('The account is inactive.');
                }

                return $customer;
            }
            $customer = (clone $accounts)->whereRaw('LOWER(email) = ?', [$identity['email']])->orderBy('id')->lockForUpdate()->first();
            if ($customer) {
                if ($customer->google_sub !== null) {
                    throw new RuntimeException('This e-mail belongs to an account linked to another Google identity.');
                }
                if (! $customer->is_active) {
                    throw new RuntimeException('The account is inactive.');
                }
                if ($customer->email_verified_at === null) {
                    $this->revokeCredentials($store, $customer);
                }
                $customer->forceFill(['google_sub' => $identity['sub'], 'email_verified_at' => $customer->email_verified_at ?? now()])->save();

                return $customer;
            }
            try {
                return StoreCustomer::create(['store_id' => $store->id, 'name' => mb_substr($identity['name'], 0, 255), 'email' => $identity['email'],
                    'google_sub' => $identity['sub'], 'email_verified_at' => now(), 'password' => null, 'is_active' => true]);
            } catch (QueryException) {
                throw new RuntimeException('The account could not be created.');
            }
        });
    }

    /**
     * Everything that could sign someone else in to this account: session tokens, reset links,
     * unredeemed Google codes, the password, the WhatsApp sign-in phone and phone confirmations in
     * flight. Profile data (addresses, orders, wishlist) stays with the account.
     */
    private function revokeCredentials(Store $store, StoreCustomer $customer): void
    {
        foreach ([StoreCustomerToken::class, StoreCustomerPasswordReset::class, StoreCustomerLoginCode::class] as $model) {
            $model::query()->withoutGlobalScopes()->where('store_id', $store->id)->where('store_customer_id', $customer->id)->delete();
        }
        StorePhoneChallenge::query()->withoutGlobalScopes()->where('store_id', $store->id)->where('store_customer_id', $customer->id)
            ->whereIn('state', ['dispatching', 'sent', 'verified'])->update(['state' => 'superseded', 'code_hash' => null, 'updated_at' => now()]);
        $customer->password = null;
        $customer->phone_normalized = null;
    }

    private function sign(array $payload): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return $body.'.'.$this->mac($body);
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', 'storefront-google-state|'.$body, (string) config('app.key'));
    }
}

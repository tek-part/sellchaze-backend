<?php

namespace App\Services;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * HS256 JWT without external deps (compatible with SPA Bearer auth).
 */
class JwtTokenService
{
    public function __construct(
        private string $secret
    ) {
        if ($this->secret === '' || $this->secret === 'base64:') {
            throw new \RuntimeException('JWT secret not configured. Set JWT_SECRET in .env');
        }
    }

    public static function fromConfig(): self
    {
        $secret = self::normalizeSecret((string) config('sellchase.jwt.secret', ''));
        if ($secret === '') {
            $key = config('app.key', '');
            if (is_string($key) && str_starts_with($key, 'base64:')) {
                $secret = (string) base64_decode(substr($key, 7), true);
            } elseif (is_string($key)) {
                $secret = self::normalizeSecret($key);
            }
        }
        if ($secret === '') {
            throw new \RuntimeException('Set JWT_SECRET in .env or ensure APP_KEY is set.');
        }

        return new self($secret);
    }

    private static function normalizeSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '' || $secret === 'base64:') {
            return '';
        }

        if (! str_starts_with($secret, 'base64:')) {
            return $secret;
        }

        $decoded = base64_decode(substr($secret, 7), true);
        if ($decoded === false) {
            return '';
        }

        return $decoded;
    }

    /**
     * Access token lifetime in seconds (for API `expires_in`).
     */
    public static function accessTokenExpiresInSeconds(): int
    {
        $minutes = max(1, (int) config('sellchase.jwt.access_ttl_minutes', 60));

        return $minutes * 60;
    }

    private static function refreshTtlSeconds(): int
    {
        $hours = max(1, (int) config('sellchase.jwt.refresh_ttl_hours', 24));

        return $hours * 3600;
    }

    public function issueAccessToken(User $user, ?string $sessionId = null): string
    {
        $payload = [
            'sub' => (string) $user->id,
            'typ' => 'access',
            'iat' => time(),
            'exp' => time() + self::accessTokenExpiresInSeconds(),
        ];
        if ($sessionId) {
            $payload['sid'] = $sessionId;
        }

        return $this->encode($payload);
    }

    /**
     * @param  array{ip_address?: string|null, user_agent?: string|null, device_name?: string|null}|array<string,mixed>  $meta
     */
    public function issueRefreshToken(User $user, ?string $sessionId = null, array $meta = []): string
    {
        $sessionId = $sessionId ?: (string) Str::uuid();
        $jti = bin2hex(random_bytes(32));
        $refreshTtl = self::refreshTtlSeconds();
        Cache::put('jwt_refresh:'.$jti, $user->id, now()->addSeconds($refreshTtl));
        $payload = [
            'user_id' => $user->id,
            'refresh_jti' => $jti,
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
            'device_name' => $meta['device_name'] ?? null,
            'last_used_at' => now(),
            'expires_at' => now()->addSeconds($refreshTtl),
            'revoked_at' => null,
        ];

        if ($this->authSessionsHasGeoColumns()) {
            $geo = GeoIpService::lookup($meta['ip_address'] ?? null);
            $payload = array_merge($payload, [
                'country' => $geo['country'],
                'region' => $geo['region'],
                'city' => $geo['city'],
                'timezone' => $geo['timezone'],
                'isp' => $geo['isp'],
                'lat' => $geo['lat'],
                'lon' => $geo['lon'],
            ]);
        }

        AuthSession::query()->updateOrCreate(
            ['id' => $sessionId],
            $payload
        );

        return $this->encode([
            'sub' => (string) $user->id,
            'typ' => 'refresh',
            'sid' => $sessionId,
            'jti' => $jti,
            'iat' => time(),
            'exp' => time() + $refreshTtl,
        ]);
    }

    public function validateAccessToken(string $token): ?User
    {
        $payload = $this->decode($token);
        if (! $payload || ($payload['typ'] ?? '') !== 'access') {
            return null;
        }
        $id = (int) ($payload['sub'] ?? 0);
        if ($id < 1) {
            return null;
        }

        return User::query()->find($id);
    }

    public function accessSessionId(string $token): ?string
    {
        $payload = $this->decode($token);
        if (! $payload || ($payload['typ'] ?? '') !== 'access') {
            return null;
        }

        $sid = (string) ($payload['sid'] ?? '');

        return $sid !== '' ? $sid : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function refreshPayload(string $token): ?array
    {
        $payload = $this->decode($token);
        if (! $payload || ($payload['typ'] ?? '') !== 'refresh') {
            return null;
        }
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '' || ! Cache::has('jwt_refresh:'.$jti)) {
            return null;
        }

        return $payload;
    }

    public function validateRefreshToken(string $token): ?User
    {
        $payload = $this->refreshPayload($token);
        if (! $payload) {
            return null;
        }
        $id = (int) ($payload['sub'] ?? 0);
        if ($id < 1) {
            return null;
        }

        return User::query()->find($id);
    }

    public function revokeRefreshToken(string $token): void
    {
        $payload = $this->decode($token);
        if (! $payload || ($payload['typ'] ?? '') !== 'refresh') {
            return;
        }

        $jti = (string) ($payload['jti'] ?? '');
        $sid = (string) ($payload['sid'] ?? '');

        if ($jti !== '') {
            Cache::forget('jwt_refresh:'.$jti);
            AuthSession::query()->where('refresh_jti', $jti)->update(['revoked_at' => now()]);
        }

        if ($sid !== '') {
            AuthSession::query()->where('id', $sid)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }

    public function revokeSessionById(User $user, string $sessionId): bool
    {
        $session = AuthSession::query()
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $session) {
            return false;
        }

        Cache::forget('jwt_refresh:'.$session->refresh_jti);
        $session->revoked_at = now();
        $session->save();

        return true;
    }

    public function revokeOtherSessions(User $user, ?string $currentSessionId): int
    {
        $query = AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at');
        if ($currentSessionId) {
            $query->where('id', '!=', $currentSessionId);
        }
        $sessions = $query->get(['id', 'refresh_jti']);

        foreach ($sessions as $session) {
            Cache::forget('jwt_refresh:'.$session->refresh_jti);
        }

        return AuthSession::query()
            ->whereIn('id', $sessions->pluck('id'))
            ->update(['revoked_at' => now()]);
    }

    private function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            $this->b64url(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->b64url(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signing = $segments[0].'.'.$segments[1];
        $signature = hash_hmac('sha256', $signing, $this->secret, true);
        $segments[] = $this->b64url($signature);

        return implode('.', $segments);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $sig] = $parts;
        $expected = hash_hmac('sha256', $h.'.'.$p, $this->secret, true);
        if (! hash_equals($expected, $this->b64urlDecode($sig))) {
            return null;
        }
        $payload = json_decode($this->b64urlDecode($p), true);
        if (! is_array($payload)) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $pad = 4 - (strlen($data) % 4);
        if ($pad > 0 && $pad < 4) {
            $data .= str_repeat('=', $pad);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    private function authSessionsHasGeoColumns(): bool
    {
        static $hasGeoColumns;

        if (is_bool($hasGeoColumns)) {
            return $hasGeoColumns;
        }

        $columns = ['country', 'region', 'city', 'timezone', 'isp', 'lat', 'lon'];
        $hasGeoColumns = Schema::hasColumns('auth_sessions', $columns);

        return $hasGeoColumns;
    }
}

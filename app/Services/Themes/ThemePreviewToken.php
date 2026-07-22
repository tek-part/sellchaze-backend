<?php

namespace App\Services\Themes;

use Illuminate\Support\Facades\Config;

/**
 * Signed, expiring preview tokens. A token binds {store_id, store_theme_id, exp}
 * and is HMAC-signed with the app key, so it cannot be forged or guessed. Used to
 * let an owner preview an installed theme on the public storefront host without
 * touching the active theme, visitors, or the page cache.
 */
class ThemePreviewToken
{
    private function key(): string
    {
        $key = (string) Config::get('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return $key ?: 'sellchase-preview';
    }

    public function make(int $storeId, int $storeThemeId, int $ttlSeconds = 1800, int $versionId = 0): string
    {
        $exp = time() + $ttlSeconds;
        $payload = $storeId.':'.$storeThemeId.':'.$versionId.':'.$exp;
        $sig = hash_hmac('sha256', $payload, $this->key());

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.'.$sig;
    }

    /** @return int|null the store_theme_id if valid for this store, else null */
    public function verify(?string $token, int $storeId): ?int
    {
        return $this->verifyContext($token, $storeId)['store_theme_id'] ?? null;
    }

    /** @return array{store_theme_id:int,version_id:int}|null */
    public function verifyContext(?string $token, int $storeId): ?array
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }
        [$encoded, $sig] = explode('.', $token, 2);
        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $this->key());
        if (! hash_equals($expected, $sig)) {
            return null; // forged/tampered
        }

        $parts = explode(':', $payload);
        if (count($parts) !== 4) {
            return null;
        }
        [$tokenStoreId, $storeThemeId, $versionId, $exp] = array_map('intval', $parts);

        if ($tokenStoreId !== $storeId || $exp < time()) {
            return null; // wrong store or expired
        }

        return ['store_theme_id' => $storeThemeId, 'version_id' => $versionId];
    }
}

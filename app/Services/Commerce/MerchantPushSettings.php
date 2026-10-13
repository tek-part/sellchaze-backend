<?php

namespace App\Services\Commerce;

use Minishlink\WebPush\VAPID;
use Throwable;

class MerchantPushSettings
{
    public function auth(): array
    {
        return ['subject' => (string) config('merchant_push.subject'), 'publicKey' => (string) config('merchant_push.public_key'), 'privateKey' => (string) config('merchant_push.private_key')];
    }

    public function ready(): bool
    {
        $auth = $this->auth();
        $subjectValid = str_starts_with($auth['subject'], 'mailto:')
            ? (bool) filter_var(substr($auth['subject'], 7), FILTER_VALIDATE_EMAIL)
            : (str_starts_with($auth['subject'], 'https://') && (bool) filter_var($auth['subject'], FILTER_VALIDATE_URL));
        if (! $subjectValid) {
            return false;
        }
        try {
            VAPID::validate($auth);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', (string) config('merchant_push.public_key'));
    }

    public function endpointAllowed(string $endpoint): bool
    {
        if (strlen($endpoint) > 4096 || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.notify.windows.com');
    }

    public function validKey(mixed $value, int $bytes): bool
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) && strlen($decoded) === $bytes && ($bytes !== 65 || ord($decoded[0]) === 4);
    }
}

<?php

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/** Country lookup stays on this server. No visitor IP is sent to a third party. */
class VisitorCurrency
{
    public function country(?string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        $path = storage_path('app/geoip/dbip-country-lite.mmdb');
        if (! is_file($path)) {
            return null;
        }
        try {
            $process = new Process(['mmdblookup', '--file', $path, '--ip', $ip, 'country', 'iso_code']);
            $process->setTimeout(1)->run();

            return $process->isSuccessful() && preg_match('/"([A-Z]{2})"/', $process->getOutput(), $match) ? $match[1] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function choose(?string $country, array $supported, string $fallback): string
    {
        $code = match (strtoupper($country ?? '')) {
            'AE' => 'AED', 'SA' => 'SAR', 'KW' => 'KWD', 'BH' => 'BHD', 'OM' => 'OMR', 'QA' => 'QAR',
            'GB' => 'GBP', 'EG' => 'EGP', 'CA' => 'CAD', 'AU' => 'AUD', 'US' => 'USD',
            'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK' => 'EUR',
            default => $fallback,
        };

        return in_array($code, $supported, true) ? $code : $fallback;
    }

    /** One daily exchange-rate snapshot; a failed refresh retains the last good snapshot. */
    public function rates(): array
    {
        $key = 'storefront:public-fx:usd';
        $snapshot = Cache::get($key, []);
        if (($snapshot['fetched_at'] ?? 0) > time() - 86400) {
            return $snapshot['rates'] ?? [];
        }
        if (! Cache::add($key.':refresh', true, 60)) {
            return $snapshot['rates'] ?? [];
        }
        try {
            $data = Http::connectTimeout(2)->timeout(4)->acceptJson()->get('https://open.er-api.com/v6/latest/USD')->throw()->json();
            $rates = is_array($data['rates'] ?? null) ? $data['rates'] : [];
            if (($data['result'] ?? '') === 'success' && ($data['base_code'] ?? '') === 'USD' && ($rates['USD'] ?? null) === 1 && ($rates['AED'] ?? 0) > 0) {
                Cache::forever($key, ['rates' => $rates, 'fetched_at' => time(), 'updated_at' => $data['time_last_update_unix'] ?? null]);

                return $rates;
            }
        } catch (\Throwable) {
            // Existing database multipliers remain available on the first-run failure.
        }

        return $snapshot['rates'] ?? [];
    }
}

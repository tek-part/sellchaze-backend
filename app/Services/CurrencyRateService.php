<?php

namespace App\Services;

use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Http;

class CurrencyRateService
{
    public function normalizeCode(?string $currencyCode): string
    {
        return strtoupper(trim((string) $currencyCode));
    }

    public function getEffectiveRateToUsd(?string $currencyCode): ?float
    {
        $code = $this->normalizeCode($currencyCode);
        if ($code === '' || $code === 'USD') {
            return 1.0;
        }

        $row = CurrencyRate::query()->where('currency_code', $code)->first();
        if (! $row) {
            return null;
        }

        return (float) $row->rate_to_usd;
    }

    public function convertToUsd(float $amount, ?string $currencyCode): ?float
    {
        $rateToUsd = $this->getEffectiveRateToUsd($currencyCode);
        if ($rateToUsd === null) {
            return null;
        }

        return round($amount * $rateToUsd, 6);
    }

    public function upsertManualRate(string $currencyCode, float $rateToUsd): CurrencyRate
    {
        $code = $this->normalizeCode($currencyCode);

        return CurrencyRate::query()->updateOrCreate(
            ['currency_code' => $code],
            [
                'rate_to_usd' => $rateToUsd,
                'source' => 'manual',
                'is_manual_override' => true,
            ]
        );
    }

    public function createCurrency(string $currencyCode, ?float $rateToUsd = null): CurrencyRate
    {
        $code = $this->normalizeCode($currencyCode);

        return CurrencyRate::query()->firstOrCreate(
            ['currency_code' => $code],
            [
                'rate_to_usd' => $rateToUsd ?? ($code === 'USD' ? 1 : 0),
                'source' => $rateToUsd !== null ? 'manual' : 'pending',
                'is_manual_override' => $rateToUsd !== null,
            ]
        );
    }

    /**
     * @return array{updated:int, skipped_manual:int, currencies:string[]}
     */
    public function refreshFromApi(): array
    {
        $url = (string) config('services.currency_rates.url');
        $apiKey = (string) config('services.currency_rates.api_key');
        $base = strtoupper((string) config('services.currency_rates.base', 'USD'));
        $timeout = (int) config('services.currency_rates.timeout', 15);

        $query = ['base' => $base];
        if ($apiKey !== '') {
            $query['access_key'] = $apiKey;
        }

        $response = Http::timeout(max($timeout, 5))
            ->acceptJson()
            ->get($url, $query)
            ->throw();

        $json = $response->json();
        $rates = is_array($json['rates'] ?? null) ? $json['rates'] : [];
        if ($rates === []) {
            return ['updated' => 0, 'skipped_manual' => 0, 'currencies' => []];
        }

        $updated = 0;
        $skippedManual = 0;
        $currencies = [];
        $baseRateToUsd = $base === 'USD' ? 1.0 : (isset($rates['USD']) ? (float) $rates['USD'] : null);
        if ($baseRateToUsd === null || $baseRateToUsd <= 0) {
            return ['updated' => 0, 'skipped_manual' => 0, 'currencies' => []];
        }

        foreach ($rates as $code => $baseToCurrency) {
            $currency = $this->normalizeCode((string) $code);
            $baseToCurrency = (float) $baseToCurrency;
            if ($currency === '' || $baseToCurrency <= 0) {
                continue;
            }

            $rateToUsd = $currency === 'USD'
                ? 1.0
                : ($baseRateToUsd / $baseToCurrency);

            $existing = CurrencyRate::query()->where('currency_code', $currency)->first();
            if ($existing?->is_manual_override) {
                $skippedManual++;

                continue;
            }

            CurrencyRate::query()->updateOrCreate(
                ['currency_code' => $currency],
                [
                    'rate_to_usd' => round($rateToUsd, 8),
                    'source' => 'api',
                    'is_manual_override' => false,
                ]
            );
            $updated++;
            $currencies[] = $currency;
        }

        CurrencyRate::query()->updateOrCreate(
            ['currency_code' => 'USD'],
            ['rate_to_usd' => 1, 'source' => 'api', 'is_manual_override' => false]
        );

        return [
            'updated' => $updated,
            'skipped_manual' => $skippedManual,
            'currencies' => array_values(array_unique($currencies)),
        ];
    }
}

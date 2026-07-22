<?php

namespace Database\Seeders;

use App\Models\CurrencyRate;
use Illuminate\Database\Seeder;

/**
 * Seed default store currencies. Rates are approximate placeholders;
 * use Settings → Currencies → "Refresh rates from API" for live values.
 */
class CurrencyRatesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['currency_code' => 'USD', 'rate_to_usd' => 1.0],
            ['currency_code' => 'AED', 'rate_to_usd' => 0.272261],
            ['currency_code' => 'SAR', 'rate_to_usd' => 0.266667],
            ['currency_code' => 'BHD', 'rate_to_usd' => 2.652582],
            ['currency_code' => 'EUR', 'rate_to_usd' => 1.085],
            ['currency_code' => 'KWD', 'rate_to_usd' => 3.257],
            ['currency_code' => 'OMR', 'rate_to_usd' => 2.597],
            ['currency_code' => 'QAR', 'rate_to_usd' => 0.274725],
        ];

        foreach ($rows as $row) {
            CurrencyRate::query()->updateOrCreate(
                ['currency_code' => $row['currency_code']],
                [
                    'rate_to_usd' => $row['rate_to_usd'],
                    'source' => 'seed',
                    'is_manual_override' => false,
                ]
            );
        }
    }
}

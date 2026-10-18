<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StorePaymentGateway;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A merchant's explicit, post-tax payment adjustment, frozen on each order. */
class PaymentPriceAdjustment
{
    public static function configuration(?array $input): array
    {
        $data = array_replace(['enabled' => false, 'type' => 'fixed', 'direction' => 'add', 'value' => '0.00'], $input ?? []);
        Validator::make($data, [
            'enabled' => ['required', 'boolean'], 'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'direction' => ['required', Rule::in(['add', 'subtract'])],
            'value' => ['required', 'max:64', 'regex:/^[0-9]{1,10}(?:\.[0-9]+)?$/'],
        ])->validate();
        $data['enabled'] = (bool) $data['enabled'];
        $scale = max(2, strlen(explode('.', (string) $data['value'])[1] ?? ''));
        $data['value'] = bcadd((string) $data['value'], '0', $scale);
        if ($data['enabled'] && (bccomp($data['value'], '0', $scale) <= 0
            || ($data['type'] === 'percentage' && bccomp($data['value'], '100', $scale) >= 0)
            || ($data['type'] === 'fixed' && bccomp($data['value'], ManualStoreOrders::MAX_MONEY, $scale) > 0))) {
            throw ValidationException::withMessages(['value' => 'Enter a positive fixed amount or a percentage strictly between 0 and 100.']);
        }

        return $data;
    }

    public function forMethod(Store $store, string $gateway, array $totals): array
    {
        $setting = StorePaymentGateway::where('store_id', $store->id)->where('gateway', $gateway)->where('enabled', true)->first();
        $rule = $setting ? self::configuration($setting->presentation['price_adjustment'] ?? null) : null;

        return $this->apply($totals, $store->currency ?: 'USD', $gateway, $rule);
    }

    /** A percentage uses the final base total, including delivery and existing tax. */
    public function apply(array $totals, string $currency, string $gateway, ?array $rule): array
    {
        $snapshot = null;
        $amount = '0.00';
        $base = $totals['grand_total'];
        if ($rule && $rule['enabled']) {
            $rule = self::configuration($rule);
            $precision = min(2, PaymentAmounts::decimals($currency));
            $value = $rule['type'] === 'percentage' ? bcdiv(bcmul($base, $rule['value'], 6), '100', 6) : $rule['value'];
            $value = bcadd(bcadd($value, $precision === 0 ? '0.5' : '0.005', $precision), '0', 2);
            if ($rule['direction'] === 'subtract') {
                $value = bccomp($value, $base, 2) > 0 ? $base : $value;
                $amount = bcsub('0', $value, 2);
            } else {
                $amount = $value;
            }
            $snapshot = ['version' => 1, 'gateway' => $gateway, 'currency' => strtoupper($currency), 'rule' => $rule, 'base_total' => $base, 'amount' => $amount];
        }
        $grand = bcadd($base, $amount, 2);
        if (bccomp($grand, ManualStoreOrders::MAX_MONEY, 2) > 0) {
            throw ValidationException::withMessages(['payment_method' => 'The adjusted total exceeds the supported order amount.']);
        }

        return array_replace($totals, ['payment_adjustment_total' => $amount, 'payment_adjustment' => $snapshot, 'grand_total' => $grand]);
    }

    public function token(Store $store, ?array $snapshot): ?string
    {
        return $snapshot === null ? null : hash_hmac('sha256', json_encode([$store->id, $snapshot], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function assertQuoted(Store $store, ?array $snapshot, ?string $token): void
    {
        $expected = $this->token($store, $snapshot);
        if (($expected !== null || $token !== null) && ($expected === null || $token === null || ! hash_equals($expected, $token))) {
            throw ValidationException::withMessages(['payment_method' => 'Payment pricing changed. Refresh the total and review it before placing the order.']);
        }
    }
}

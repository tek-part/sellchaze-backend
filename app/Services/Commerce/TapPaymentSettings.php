<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/** Deema shares its store's Tap connection; no second copy of a secret is stored. */
class TapPaymentSettings
{
    public static function connection(StorePaymentGateway $setting): ?StorePaymentGateway
    {
        return $setting->gateway === 'deema'
            ? StorePaymentGateway::query()->where('store_id', $setting->store_id)->where('gateway', 'tap')->first()
            : $setting;
    }

    public static function configured(StorePaymentGateway $setting, ?string $currency = null): bool
    {
        try {
            self::validate($setting, $currency);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public static function validate(StorePaymentGateway $setting, ?string $currency = null): StorePaymentGateway
    {
        $connection = self::connection($setting);
        $secret = trim((string) data_get($connection?->credentials, 'secret_key'));
        if ($secret === '') {
            throw ValidationException::withMessages(['payment_method' => 'Configure the Tap connection before enabling this payment method.']);
        }
        if ((str_starts_with($secret, 'sk_live_') && $connection->test_mode)
            || (str_starts_with($secret, 'sk_test_') && ! $connection->test_mode)) {
            throw ValidationException::withMessages(['payment_method' => 'The Tap secret key does not match the selected sandbox or live environment.']);
        }
        if ($setting->gateway === 'deema' && $currency !== null && strtoupper($currency) !== 'KWD') {
            throw ValidationException::withMessages(['payment_method' => 'Deema through Tap is available in KWD only.']);
        }

        return $connection;
    }

    /** @return array{country_code:string,number:string} */
    public static function phone(?string $raw): array
    {
        $raw = trim((string) $raw);
        if (str_starts_with($raw, '00')) {
            $raw = '+'.substr($raw, 2);
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($raw, null);
            if (str_starts_with($raw, '+') && $util->isValidNumber($number)) {
                return ['country_code' => (string) $number->getCountryCode(), 'number' => $util->getNationalSignificantNumber($number)];
            }
        } catch (NumberParseException) {
            // The checkout field and server start share the same international-number requirement.
        }
        throw ValidationException::withMessages(['customer_phone' => 'Enter a valid international phone number with its country code for Deema.']);
    }

    /** @return array{currency:string,minimum:string,maximum:?string} */
    public static function amountLimits(StorePaymentGateway $setting): array
    {
        $test = self::connection($setting)?->test_mode !== false;

        return ['currency' => 'KWD', 'minimum' => $test ? '100.000' : '10.000', 'maximum' => $test ? '200.000' : null];
    }

    /** Quote and placement share the final amount actually collected after all discounts/tender. */
    public static function assertCheckoutAmount(Store $store, string $gateway, string $amount): void
    {
        if ($gateway !== 'deema') {
            return;
        }
        $setting = StorePaymentGateway::where('store_id', $store->id)->where('gateway', 'deema')->where('enabled', true)->first();
        if (! $setting) {
            throw ValidationException::withMessages(['payment_method' => 'Deema is not available for this checkout.']);
        }
        self::assertAmount($setting, $amount, $store->currency ?: 'USD');
    }

    public static function assertAmount(StorePaymentGateway $setting, string $amount, string $currency): void
    {
        $connection = self::validate($setting, $currency);
        PaymentAmounts::minor($amount, $currency);
        if ($setting->gateway === 'deema' && ($connection->test_mode
            ? bccomp($amount, '100', 3) < 0 || bccomp($amount, '200', 3) > 0
            : bccomp($amount, '10', 3) < 0)) {
            throw ValidationException::withMessages(['payment_method' => $connection->test_mode
                ? 'Deema sandbox payments must be between 100 and 200 KWD.'
                : 'Deema live payments require at least 10 KWD.']);
        }
    }

    public static function contract(StorePaymentGateway $setting, StoreOrder $order): array
    {
        $connection = self::validate($setting, $order->currency);

        return ['version' => 1, 'store_id' => (int) $setting->store_id, 'store_order_id' => (int) $order->id,
            'gateway' => $setting->gateway, 'source_id' => $setting->gateway === 'deema' ? 'src_deema' : 'src_all', 'test_mode' => (bool) $connection->test_mode];
    }

    /** A server-retrieved charge must match exact money and references, including legacy Tap attempts. */
    public static function assertCharge(StorePaymentTransaction $transaction, array $event): void
    {
        $order = StoreOrder::forStore($transaction->store_id)->whereKey($transaction->store_order_id)->firstOrFail();
        abort_unless(is_string($event['currency'] ?? null) && (is_string($event['amount'] ?? null) || is_int($event['amount'] ?? null) || is_float($event['amount'] ?? null)), 422, 'Invalid Tap payment money.');
        $currency = strtoupper($event['currency']);
        abort_unless($currency === strtoupper($transaction->currency), 422, 'Tap payment currency mismatch.');
        try {
            $amount = PaymentAmounts::minor((string) ($event['amount'] ?? ''), $currency);
            $expected = PaymentAmounts::minor((string) $transaction->amount, $currency);
        } catch (ValidationException) {
            abort(422, 'Invalid Tap payment amount.');
        }
        abort_unless($amount === $expected, 422, 'Tap payment amount mismatch.');
        abort_unless((string) data_get($event, 'reference.transaction') === (string) $transaction->id
            && (string) data_get($event, 'reference.order') === (string) $order->order_number, 422, 'Tap payment reference mismatch.');
        foreach (['transaction_id' => $transaction->id, 'store_id' => $transaction->store_id, 'store_order_id' => $transaction->store_order_id, 'gateway' => $transaction->gateway] as $key => $expectedValue) {
            $value = data_get($event, 'metadata.'.$key);
            if ($value !== null || data_get($transaction->metadata, 'tap.version') === 1) {
                abort_unless((string) $value === (string) $expectedValue, 422, 'Tap payment metadata mismatch.');
            }
        }
        if (data_get($transaction->metadata, 'tap.version') === 1) {
            abort_unless((string) data_get($event, 'metadata.tap_contract_version') === '1'
                && is_bool($event['live_mode'] ?? null)
                && $event['live_mode'] === ! data_get($transaction->metadata, 'tap.test_mode'), 422, 'Tap payment environment mismatch.');
        }
        if ($transaction->gateway === 'deema') {
            abort_unless(data_get($event, 'source.id') === 'src_deema', 422, 'Deema payment source mismatch.');
            $methods = array_filter([data_get($event, 'source.payment_method'), data_get($event, 'payment.method')], fn ($value) => $value !== null);
            abort_unless($methods !== [] && collect($methods)->every(fn ($method) => is_string($method) && strtoupper($method) === 'DEEMA'), 422, 'Deema payment method mismatch.');
        }
    }
}

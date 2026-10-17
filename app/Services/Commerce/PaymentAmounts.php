<?php

namespace App\Services\Commerce;

use Illuminate\Validation\ValidationException;

/** Exact provider units; never round a customer charge using floating point. */
class PaymentAmounts
{
    public static function decimals(string $currency, bool $stripe = false): int
    {
        $currency = strtoupper($currency);
        if ($stripe && in_array($currency, ['ISK', 'UGX'], true)) {
            return 2;
        }
        if (in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'], true)) {
            return 3;
        }
        $zero = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        if ($stripe) {
            $zero[] = 'MGA';
        }

        return in_array($currency, $zero, true) ? 0 : 2;
    }

    public static function minor(string $amount, string $currency, bool $stripe = false): int
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/', $amount)) {
            throw ValidationException::withMessages(['payment_method' => 'Invalid payment amount.']);
        }
        $factor = (string) (10 ** self::decimals($currency, $stripe));
        $scaled = bcmul($amount, $factor, 3);
        $integer = bcadd($scaled, '0', 0);
        $wholeStripe = ! $stripe || ! in_array(strtoupper($currency), ['ISK', 'UGX'], true) || bccomp($amount, bcadd($amount, '0', 0), 3) === 0;
        if (bccomp($scaled, $integer, 3) !== 0 || ! $wholeStripe) {
            throw ValidationException::withMessages(['payment_method' => 'The order amount has unsupported fractional units for this currency.']);
        }

        return (int) $integer;
    }
}

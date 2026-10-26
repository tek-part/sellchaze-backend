<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;

class BankTransferInstructions
{
    /** Transfer methods show up to two targets (e.g. a bank account and an e-wallet / InstaPay number). */
    public const TARGET_LIMIT = 2;

    public const TARGET_METHODS = ['bank_transfer', 'transfer_receipt'];

    public static function snapshot(StorePaymentGateway $setting): array
    {
        $fields = [];
        foreach (['account_name', 'bank_name', 'iban', 'swift_code'] as $key) {
            $value = $setting->credentials[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = $value;
            }
        }

        return ['fields' => $fields, 'targets' => self::targets($setting->presentation ?? []), 'notes' => (string) $setting->notes, 'test_mode' => (bool) $setting->test_mode];
    }

    /**
     * The merchant's transfer targets: a label, the number to copy and an optional http(s) link such as an
     * InstaPay payment URL. Anything else (javascript:, data:, relative) is dropped, never rendered.
     *
     * @return list<array{label:string,number:string,link:?string}>
     */
    public static function targets(?array $presentation): array
    {
        $rows = is_array($presentation['transfer_targets'] ?? null) ? $presentation['transfer_targets'] : [];
        $targets = [];
        foreach ($rows as $row) {
            $label = is_array($row) && is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $number = is_array($row) && is_string($row['number'] ?? null) ? trim($row['number']) : '';
            if ($label === '' || $number === '') {
                continue;
            }
            $targets[] = ['label' => $label, 'number' => $number, 'link' => self::safeLink($row['link'] ?? null)];
            if (count($targets) === self::TARGET_LIMIT) {
                break;
            }
        }

        return $targets;
    }

    /** An absolute http(s) URL with a host, or null. */
    public static function safeLink(mixed $url): ?string
    {
        if (! is_string($url) || ($url = trim($url)) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && ! empty($parts['host']) ? $url : null;
    }

    public static function forOrder(StoreOrder $order): ?array
    {
        if (! in_array($order->payment_method, self::TARGET_METHODS, true) || in_array($order->payment_status, ['paid', 'deposit_paid'], true)
            || in_array($order->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true)) {
            return null;
        }

        return StorePaymentTransaction::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
            ->where('gateway', $order->payment_method)->latest('id')->first()?->metadata['bank_transfer'] ?? null;
    }
}

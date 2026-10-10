<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;

class BankTransferInstructions
{
    public static function snapshot(StorePaymentGateway $setting): array
    {
        $fields = [];
        foreach (['account_name', 'bank_name', 'iban', 'swift_code'] as $key) {
            $value = $setting->credentials[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = $value;
            }
        }

        return ['fields' => $fields, 'notes' => (string) $setting->notes, 'test_mode' => (bool) $setting->test_mode];
    }

    public static function forOrder(StoreOrder $order): ?array
    {
        if ($order->payment_method !== 'bank_transfer' || $order->payment_status === 'paid' || $order->status === 'cancelled') {
            return null;
        }

        return StorePaymentTransaction::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)
            ->where('gateway', 'bank_transfer')->latest('id')->first()?->metadata['bank_transfer'] ?? null;
    }
}

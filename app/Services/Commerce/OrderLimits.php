<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class OrderLimits
{
    /** @return array{max_product_quantity:int,max_orders_per_phone_24h:int,phone_country:string} */
    public function configured(Store $store): array
    {
        $config = $store->order_limits ?? [];

        return ['max_product_quantity' => (int) ($config['max_product_quantity'] ?? 0),
            'max_orders_per_phone_24h' => (int) ($config['max_orders_per_phone_24h'] ?? 0),
            'phone_country' => (string) ($config['phone_country'] ?? 'EG')];
    }

    public function productLimit(Store $store): ?int
    {
        return $this->configured($store)['max_product_quantity'] ?: null;
    }

    public function assertQuantity(Store $store, int $quantity): void
    {
        $limit = $this->productLimit($store);
        if ($limit !== null && $quantity > $limit) {
            throw ValidationException::withMessages(['quantity' => "At most {$limit} units of the same product are allowed per order, including all variants and customizations."]);
        }
    }

    public function normalizePhone(?string $raw, string $country): ?string
    {
        if (! filled($raw)) {
            return null;
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($raw, $country);

            return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
        } catch (NumberParseException) {
            return null;
        }
    }

    /** Caller holds the store row lock before cart/product locks, until order insertion commits. */
    public function assertPhone(Store $store, ?string $raw): ?string
    {
        $config = $this->configured($store);
        $phone = $this->normalizePhone($raw, $config['phone_country']);
        $limit = $config['max_orders_per_phone_24h'];
        if ($limit === 0) {
            return $phone;
        }
        if ($phone === null) {
            throw ValidationException::withMessages(['customer_phone' => 'Enter a valid phone number, including its country code for a foreign number.']);
        }
        $end = now();
        $start = $end->copy()->subHours(24);
        // Locking reads see committed orders even if the caller already opened a MySQL snapshot.
        $recent = StoreOrder::withoutGlobalScopes()->where('store_id', $store->id)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('placed_at', [$start, $end])->where('placed_at', '>', $start)
                    ->orWhere(function ($legacy) use ($start, $end) {
                        $legacy->whereNull('placed_at')->where('created_at', '>', $start)->where('created_at', '<=', $end);
                    });
            });
        $ids = (clone $recent)->where('customer_phone_normalized', $phone)->limit($limit)->lockForUpdate()->pluck('id')->all();
        $count = count($ids);
        if ($count < $limit) {
            // Legacy orders remain covered without a destructive historical backfill.
            foreach ((clone $recent)->whereNull('customer_phone_normalized')->orderBy('id')->lockForUpdate()->lazyById(200) as $order) {
                if ($this->normalizePhone($order->customer_phone, $config['phone_country']) === $phone && ++$count >= $limit) {
                    break;
                }
            }
        }
        if ($count >= $limit) {
            throw ValidationException::withMessages(['customer_phone' => "This phone number has reached the store's limit of {$limit} orders in 24 hours. Try again after an earlier order leaves this window."]);
        }

        return $phone;
    }
}

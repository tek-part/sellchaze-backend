<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StorePaymentGateway;

/** One field contract for the merchant editor, public form and checkout validation. */
class CheckoutFields
{
    public const PATHS = [
        'name' => 'customer_name', 'phone' => 'customer_phone', 'email' => 'customer_email',
        'country' => 'shipping_address.country', 'city' => 'shipping_address.city',
        'address' => 'shipping_address.line1', 'phone_alt' => 'shipping_address.phone_alt',
        'notes' => 'notes', 'national_address' => 'shipping_address.national_address',
        'postal_code' => 'shipping_address.postal_code',
    ];

    /** @return list<array<string,mixed>> */
    public function defaults(bool $legacy = false): array
    {
        $labels = [
            'name' => ['الاسم بالكامل', 'Full name'], 'phone' => ['رقم الهاتف', 'Phone number'],
            'country' => ['الدولة', 'Country'], 'city' => ['المدينة / المحافظة', 'City / region'],
            'email' => ['البريد الإلكتروني', 'Email address'], 'address' => ['العنوان بالتفصيل', 'Delivery address'],
            'phone_alt' => ['رقم هاتف بديل', 'Alternative phone'], 'notes' => ['ملاحظات الطلب', 'Order notes'],
            'national_address' => ['العنوان الوطني', 'National address'], 'postal_code' => ['الرمز البريدي', 'Postal code'],
        ];
        $fields = [];
        foreach ($labels as $key => [$ar, $en]) {
            $required = in_array($key, $legacy ? ['name', 'email', 'address', 'city'] : ['name', 'phone', 'address'], true);
            $fields[] = ['key' => $key, 'label' => ['ar' => $ar, 'en' => $en], 'hint' => ['ar' => '', 'en' => ''],
                'enabled' => $required || ($legacy && in_array($key, ['phone', 'notes', 'country', 'postal_code'], true)),
                'required' => $required, 'position' => count($fields)];
        }

        return $fields;
    }

    /** @return list<array<string,mixed>> */
    public function configured(Store $store): array
    {
        return $store->checkout_fields ?? $this->defaults(true);
    }

    public function paymentMethod(Store $store, ?string $requested): string
    {
        if (filled($requested)) {
            return $requested;
        }

        return StorePaymentGateway::query()->where('store_id', $store->id)->where('enabled', true)
            ->orderBy('sort_order')->value('gateway') ?? 'cod';
    }

    /** @return list<array<string,mixed>> */
    public function effective(Store $store, ?string $paymentMethod, bool $requiresShipping = true, bool $hasDigital = false, bool $requiresWhatsapp = false): array
    {
        $online = ! in_array($this->paymentMethod($store, $paymentMethod), ['cod', 'bank_transfer'], true);

        $regions = $requiresShipping && app(StoreShipping::class)->regionsEnabled($store);
        $phoneCountry = app(OrderLimits::class)->configured($store)['phone_country'];
        $phoneRequired = app(OrderLimits::class)->configured($store)['max_orders_per_phone_24h'] > 0;
        $blockPhoneRequired = app(PhoneBlocking::class)->hasActive($store);

        return collect($this->configured($store))->map(function (array $field) use ($online, $regions, $requiresShipping, $hasDigital, $requiresWhatsapp, $phoneRequired, $phoneCountry, $blockPhoneRequired) {
            $field['payment_required'] = $online && in_array($field['key'], ['name', 'email'], true);
            $field['digital_required'] = ($hasDigital && $field['key'] === 'email') || ($requiresWhatsapp && $field['key'] === 'phone');
            $field['order_limit_required'] = $phoneRequired && $field['key'] === 'phone';
            $field['phone_block_required'] = $blockPhoneRequired && $field['key'] === 'phone';
            if ($field['order_limit_required'] || $field['phone_block_required']) {
                $field['hint'] = ['ar' => "أدخل رقمًا محليًا للدولة {$phoneCountry}، أو رقمًا دوليًا يبدأ بكود الدولة.", 'en' => "Enter a local number for {$phoneCountry}, or an international number with its country code."];
            }
            if ($requiresWhatsapp && $field['key'] === 'phone') {
                $field['hint'] = ['ar' => 'أدخل رقم واتساب مع كود الدولة، مثل +201001234567.', 'en' => 'Enter your WhatsApp number with its country code, e.g. +201001234567.'];
            }
            if ($field['payment_required'] || $field['digital_required'] || $field['order_limit_required'] || $field['phone_block_required']) {
                $field['enabled'] = $field['required'] = true;
            }

            $field['shipping_region'] = $regions && $field['key'] === 'city';
            if ($regions && in_array($field['key'], ['country', 'city'], true)) {
                $field['enabled'] = $field['required'] = $field['key'] === 'city';
            }
            if (! $requiresShipping && str_starts_with(self::PATHS[$field['key']], 'shipping_address.')) {
                $field['enabled'] = $field['required'] = false;
            }

            return $field;
        })->sortBy('position')->values()->all();
    }

    /** @return array<string,list<string|\Closure>> */
    public function rules(Store $store, ?string $paymentMethod, bool $requiresShipping = true, bool $hasDigital = false, bool $requiresWhatsapp = false): array
    {
        $rules = [
            'shipping_address' => $requiresShipping ? ['nullable', 'array:name,line1,line2,city,state,country,postal_code,phone_alt,national_address'] : ['exclude'],
            'shipping_address.name' => ['nullable', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
        ];
        foreach ($this->effective($store, $paymentMethod, $requiresShipping, $hasDigital, $requiresWhatsapp) as $field) {
            $key = $field['key'];
            $max = match ($key) {
                'notes' => 2000, 'phone', 'phone_alt' => 50, 'city' => 120,
                'postal_code' => 32, 'country' => 2, default => 255,
            };
            $presence = $field['required'] ? 'required' : 'nullable';
            // Existing integrations may omit shipping entirely until merchants save a configuration.
            if ($store->checkout_fields === null && in_array($key, ['address', 'city'], true)) {
                $presence = 'required_with:shipping_address';
            }
            $rules[self::PATHS[$key]] = (! $field['enabled'] || ($field['shipping_region'] ?? false)) ? ['exclude'] : [$presence, $key === 'email' ? 'email' : 'string', 'max:'.$max];
            if ($key === 'country' && $field['enabled']) {
                $rules[self::PATHS[$key]][] = 'regex:/^[A-Za-z]{2}$/';
            }
        }

        if ($requiresWhatsapp) {
            $rules['customer_phone'][] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || StoreDigitalWhatsappClient::phone($value) === null) {
                    $fail('Enter a valid international WhatsApp number with its country code.');
                }
            };
        }
        if (app(OrderLimits::class)->configured($store)['max_orders_per_phone_24h'] > 0 || app(PhoneBlocking::class)->hasActive($store)) {
            $rules['customer_phone'][] = function (string $attribute, mixed $value, \Closure $fail) use ($store): void {
                $limits = app(OrderLimits::class);
                if (! is_string($value) || $limits->normalizePhone($value, $limits->configured($store)['phone_country']) === null) {
                    $fail('Enter a valid phone number, including its country code for a foreign number.');
                }
            };
        }

        return $rules;
    }
}

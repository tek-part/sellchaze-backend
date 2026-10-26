<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class MerchantGeneralSettings
{
    public function revision(Store $store): string
    {
        $fields = ['site_title', 'description', 'header_mode', 'header_text', 'primary_color', 'font_family', 'logo', 'banner', 'favicon',
            'default_locale', 'supported_locales', 'currency', 'supported_currencies', 'timezone',
            'shipping_enabled', 'shipping_flat_rate', 'shipping_configuration', 'shopping_preferences',
            'order_limits', 'bot_protection_configuration', 'bot_protection_credentials'];
        $state = [];
        foreach ($fields as $field) {
            $state[$field] = $store->getRawOriginal($field);
        }

        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function data(Store $store): array
    {
        $shopping = app(ShoppingPreferences::class)->configured($store);
        $limits = app(OrderLimits::class)->configured($store);
        $bot = app(CheckoutBotProtection::class)->settings($store);

        return ['revision' => $this->revision($store), 'site_title' => $store->site_title ?? '',
            'header_mode' => $store->header_mode ?? 'theme', 'header_text' => $store->header_text ?? '', 'primary_color' => $store->primary_color ?? '', 'font_family' => $store->font_family ?? '',
            'default_locale' => $store->default_locale ?: 'en', 'currency' => $store->currency ?: 'USD', 'timezone' => $store->timezone ?: 'UTC',
            'shipping_flat_rate' => $store->shipping_enabled ? (string) ($store->shipping_flat_rate ?: '0.00') : '0.00',
            'phone_country' => $limits['phone_country'], 'disable_auto_select_shipping' => ! $shopping['auto_select_shipping_region'],
            'disable_auto_select_variants' => ! $shopping['auto_select_variants'], 'disable_bot_protection' => ! $bot['enabled'],
            'bot_credentials_configured' => $bot['credentials_configured'], 'max_product_quantity' => $limits['max_product_quantity'],
            'max_orders_per_phone_24h' => $limits['max_orders_per_phone_24h'], 'logo_url' => $store->logoUrl(), 'banner_url' => $store->bannerUrl(), 'favicon_url' => $store->identity()['favicon_url']];
    }

    public function choices(Store $store): array
    {
        return ['countries' => PhoneNumberUtil::getInstance()->getSupportedRegions(),
            'currencies' => DB::table('currency_rates')->orderBy('currency_code')->pluck('currency_code')->push($store->currency ?: 'USD')->unique()->values()->all(),
            'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC)];
    }

    /** Old media is removed only after the row commits, and only if no store field still references it. */
    public function cleanOldMedia(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (! str_starts_with($path, 'stores/') || str_contains($path, '..')) {
                continue;
            }
            try {
                $used = Store::where(fn ($query) => $query->where('logo', $path)->orWhere('banner', $path)->orWhere('favicon', $path))->exists();
                if (! $used) {
                    Storage::disk('public')->delete($path);
                }
            } catch (Throwable $error) {
                report($error);
            }
        }
    }
}

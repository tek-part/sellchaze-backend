<?php

namespace App\Services\Commerce;

use App\Models\Category;
use App\Models\PlatformWalletPayment;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;

class MerchantDashboardGuide
{
    public const KEYS = ['wallet', 'categories', 'products', 'home', 'domain', 'logo', 'address', 'font', 'color', 'seo'];

    public function preferences(Store $store): array
    {
        return $store->dashboard_preferences ?? ['version' => 1, 'completed' => [], 'hidden_panels' => []];
    }

    public function state(Store $store): array
    {
        $preferences = $this->preferences($store);
        $home = StorePage::forStore($store)->where('slug', 'home')->first();
        $detected = [
            'wallet' => PlatformWalletPayment::where('user_id', $store->owner_user_id)->where('status', 'paid')->where('test_mode', false)->whereNotNull('wallet_entry_id')->where('amount_milli', '>', 0)->exists(),
            'categories' => Category::withoutGlobalScopes()->where('store_id', $store->id)->exists(),
            'products' => Product::forStore($store)->exists(),
            'home' => (bool) ($store->theme_settings || ($home && $home->sections()->exists())),
            'domain' => StoreDomain::where('store_id', $store->id)->where('type', 'custom')->where('status', 'verified')->exists(),
            'logo' => (bool) $store->logo,
            'address' => trim((string) ($store->google_merchant_configuration['address'] ?? '')) !== '',
            'font' => (bool) $store->font_family,
            'color' => (bool) $store->primary_color,
            'seo' => (bool) ($store->site_title && $store->description) || (bool) (($home?->seo['title'] ?? '') || ($home?->seo['description'] ?? '')),
        ];
        $completed = [];
        foreach (self::KEYS as $key) {
            $completed[$key] = $preferences['completed'][$key] ?? $detected[$key];
        }
        $done = count(array_filter($completed));

        return ['preferences' => $preferences, 'completed' => $completed, 'detected' => $detected,
            'done_count' => $done, 'total' => count(self::KEYS), 'percent' => $done * 10,
            'resources' => $this->resources()];
    }

    private function resources(): array
    {
        $result = [];
        foreach (['tutorials_url', 'updates_url', 'android_url', 'ios_url'] as $key) {
            $url = config('merchant_dashboard.'.$key);
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            if (($key === 'android_url' && ($host !== 'play.google.com' || ($parts['path'] ?? '') !== '/store/apps/details'))
                || ($key === 'ios_url' && $host !== 'apps.apple.com')) {
                continue;
            }
            if ($key === 'android_url') {
                parse_str($parts['query'] ?? '', $query);
                if (! is_string($query['id'] ?? null) || ! preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/', $query['id'])) {
                    continue;
                }
            }
            if ($key === 'ios_url' && ! preg_match('~/id[0-9]+$~', rtrim($parts['path'] ?? '', '/'))) {
                continue;
            }
            $result[$key] = $url;
        }

        return $result;
    }
}

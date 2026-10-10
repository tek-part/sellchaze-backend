<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Store;
use App\Support\Localization\LocalizedValue;
use App\Support\ProductDescription;

class ThankYouPage
{
    public function configured(Store $store): array
    {
        $config = $store->thank_you_configuration ?? [];

        return ['enabled' => (bool) ($config['enabled'] ?? false),
            'content' => ['ar' => (string) ($config['content']['ar'] ?? ''), 'en' => (string) ($config['content']['en'] ?? '')],
            'show_home_button' => (bool) ($config['show_home_button'] ?? true),
            'category_id' => isset($config['category_id']) ? (int) $config['category_id'] : null,
            'version' => max(1, (int) ($config['version'] ?? 1))];
    }

    /** Resolve the category again: removed, hidden and foreign categories never become an all-products shelf. */
    public function publicConfiguration(Store $store, string $locale): array
    {
        $config = $this->configured($store);
        if (! $config['enabled']) {
            return ['enabled' => false, 'content_html' => '', 'show_home_button' => true, 'category' => null];
        }
        $category = $config['category_id'] ? Category::withoutGlobalScopes()->where('store_id', $store->id)
            ->where('is_active', true)->whereNotNull('slug')->where('slug', '!=', '')->find($config['category_id']) : null;

        return ['enabled' => true,
            'content_html' => ProductDescription::clean(LocalizedValue::pick($config['content'], $locale, $store->default_locale ?: 'en'), true),
            'show_home_button' => $config['show_home_button'],
            'category' => $category ? ['slug' => $category->slug, 'name' => $category->translated('name', $locale)] : null];
    }
}

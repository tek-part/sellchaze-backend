<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Support\Localization\LocalizedValue;
use App\Support\ProductDescription;

class UiBlocks
{
    public const SLOTS = ['before_header', 'after_header', 'before_product_gallery', 'after_product_gallery', 'before_checkout', 'after_checkout', 'before_footer', 'after_footer'];

    public function configured(Store $store): array
    {
        $config = $store->ui_blocks_configuration ?? [];
        $blocks = [];
        foreach (self::SLOTS as $slot) {
            $block = $config['blocks'][$slot] ?? [];
            $blocks[$slot] = ['editor' => ($block['editor'] ?? '') === 'html' ? 'html' : 'rich',
                'content' => ['ar' => (string) ($block['content']['ar'] ?? ''), 'en' => (string) ($block['content']['en'] ?? '')]];
        }

        return ['enabled' => (bool) ($config['enabled'] ?? false), 'version' => max(1, (int) ($config['version'] ?? 1)), 'blocks' => $blocks];
    }

    public function publicConfiguration(Store $store, string $locale): array
    {
        $config = $this->configured($store);
        $blocks = [];
        foreach (self::SLOTS as $slot) {
            $blocks[$slot] = $config['enabled'] ? (ProductDescription::clean(LocalizedValue::pick($config['blocks'][$slot]['content'], $locale, $store->default_locale ?: 'en'), true) ?? '') : '';
        }

        return ['enabled' => $config['enabled'], 'blocks' => $blocks];
    }
}

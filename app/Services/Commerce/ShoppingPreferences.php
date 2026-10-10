<?php

namespace App\Services\Commerce;

use App\Models\Store;

class ShoppingPreferences
{
    public function configured(?Store $store): array
    {
        $config = $store?->shopping_preferences ?? [];
        $shipping = $store?->shipping_configuration ?? [];

        return ['auto_select_variants' => (bool) ($config['auto_select_variants'] ?? true),
            'auto_select_shipping_region' => (bool) ($shipping['auto_select_region'] ?? true),
            'version' => (int) ($config['version'] ?? 1)];
    }
}

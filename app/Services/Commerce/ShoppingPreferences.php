<?php

namespace App\Services\Commerce;

use App\Models\Store;

class ShoppingPreferences
{
    public function configured(?Store $store): array
    {
        $config = $store?->shopping_preferences ?? [];

        return ['auto_select_variants' => (bool) ($config['auto_select_variants'] ?? true),
            'version' => (int) ($config['version'] ?? 1)];
    }
}

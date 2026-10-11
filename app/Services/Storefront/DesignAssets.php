<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StoreDesignAsset;

class DesignAssets
{
    public function configuration(Store $store): array
    {
        $config = $store->design_assets_configuration ?? [];

        return ['version' => max(1, (int) ($config['version'] ?? 1)), 'enabled' => (bool) ($config['enabled'] ?? false), 'assets' => array_values($config['assets'] ?? [])];
    }

    public function publicManifest(Store $store): array
    {
        $config = $this->configuration($store);
        $assets = [];
        if ($config['enabled']) {
            $rows = StoreDesignAsset::where('store_id', $store->id)->whereIn('id', $config['assets'])->get()->keyBy('id');
            foreach ($config['assets'] as $id) {
                $asset = $rows->get($id);
                if ($asset) {
                    $assets[] = ['id' => $asset->public_id, 'kind' => $asset->kind, 'url' => $asset->publicPath()];
                }
            }
        }

        return ['enabled' => $config['enabled'], 'assets' => $assets];
    }
}

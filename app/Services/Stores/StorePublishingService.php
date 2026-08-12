<?php

namespace App\Services\Stores;

use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class StorePublishingService
{
    /** @return array{ready: bool, checks: array<string, bool>} */
    public function readiness(Store $store): array
    {
        $checks = [
            'profile' => filled($store->name) && filled($store->currency),
            'verified_primary_domain' => $store->servableDomains()->where('is_primary', true)->exists(),
            'active_theme' => $store->activeStoreTheme()->exists(),
            'active_product' => Product::query()
                ->withoutGlobalScope(ProductScope::class)
                ->where('is_active', true)
                ->where('store_id', $store->id)
                ->exists(),
        ];

        return ['ready' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    public function publish(Store $store): Store
    {
        $readiness = $this->readiness($store);
        if (! $readiness['ready']) {
            $missing = array_keys(array_filter($readiness['checks'], fn (bool $passed): bool => ! $passed));
            throw ValidationException::withMessages([
                'store' => ['Store is not ready to publish: '.implode(', ', $missing).'.'],
            ]);
        }
        $store->update(['status' => 'active']);

        return $store->refresh();
    }

    public function unpublish(Store $store): Store
    {
        $store->update(['status' => 'draft']);

        return $store->refresh();
    }
}

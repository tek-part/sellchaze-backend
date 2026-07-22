<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;

/**
 * Owner management of product variants. Runs under the ScopeToStore tenant, so
 * StoreScope auto-scopes queries and BelongsToStore auto-fills store_id.
 * Mirrors StorefrontProductService (whitelisted fill + storefront cache flush).
 */
class StorefrontProductVariantService
{
    public function create(Product $product, array $data): ProductVariant
    {
        $variant = new ProductVariant;
        $this->fill($variant, $data);
        $variant->store_product_id = $product->id;
        $variant->save(); // store_id auto-filled by BelongsToStore

        $this->flush($product);

        return $variant;
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $this->fill($variant, $data);
        $variant->save();

        $this->flush($variant->product);

        return $variant;
    }

    public function delete(ProductVariant $variant): void
    {
        $product = $variant->product;
        $variant->delete();

        $this->flush($product);
    }

    private function fill(ProductVariant $variant, array $data): void
    {
        foreach (['name', 'sku', 'barcode', 'price_override', 'weight', 'options', 'is_active', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $variant->{$key} = $data[$key];
            }
        }
    }

    private function flush(?Product $product): void
    {
        if ($product === null) {
            return;
        }
        StorefrontService::forgetHomepage((int) $product->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $product->store_id);
    }
}

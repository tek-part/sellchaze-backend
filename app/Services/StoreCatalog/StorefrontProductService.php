<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Owner management of store products. Runs under the ScopeToStore tenant, so
 * StoreScope auto-scopes queries and BelongsToStore auto-fills store_id.
 * Slugs are unique per store.
 */
class StorefrontProductService
{
    public function create(array $data, ?UploadedFile $image = null): Product
    {
        $product = new Product;
        $this->fill($product, $data);
        $product->slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
        if ($image) {
            $product->image = $this->storeImage($image);
        }
        $product->save(); // store_id auto-filled by BelongsToStore

        StorefrontService::forgetHomepage((int) $product->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $product->store_id);

        return $product;
    }

    public function update(Product $product, array $data, ?UploadedFile $image = null): Product
    {
        $this->fill($product, $data);
        if (! empty($data['slug'])) {
            $product->slug = $this->uniqueSlug($data['slug'], $product->id);
        }
        if ($image) {
            $this->deleteImage($product->image);
            $product->image = $this->storeImage($image);
        }
        $product->save();

        StorefrontService::forgetHomepage((int) $product->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $product->store_id);

        return $product;
    }

    public function delete(Product $product): void
    {
        $storeId = (int) $product->store_id;
        $this->deleteImage($product->image);
        $product->delete();
        StorefrontService::forgetHomepage($storeId);
        app(StorefrontPageCache::class)->flushStore($storeId);
    }

    private function fill(Product $product, array $data): void
    {
        foreach (['name', 'sku', 'barcode', 'description', 'short_description', 'price', 'compare_price', 'category_id', 'is_active', 'is_featured', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $product->{$key} = $data[$key];
            }
        }
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        return Slug::unique($base, function (string $slug) use ($ignoreId) {
            return Product::query() // StoreScope constrains this to the current store
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
        }, 'product');
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store('store-catalog', 'public');
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}

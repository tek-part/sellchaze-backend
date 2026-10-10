<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\ProductDescription;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Owner management of store products. Runs under the ScopeToStore tenant, so
 * StoreScope auto-scopes queries and BelongsToStore auto-fills store_id.
 * Slugs are unique per store.
 */
class StorefrontProductService
{
    public function create(array $data, ?UploadedFile $image = null, array $gallery = []): Product
    {
        return $this->persist(null, $data, $image, $gallery);
    }

    public function update(Product $product, array $data, ?UploadedFile $image = null, array $gallery = []): Product
    {
        return $this->persist($product, $data, $image, $gallery);
    }

    private function persist(?Product $product, array $data, ?UploadedFile $image, array $gallery): Product
    {
        $createdFiles = [];
        try {
            return DB::transaction(function () use ($product, $data, $image, $gallery, &$createdFiles) {
                $current = $product ? Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail() : new Product;
                $removed = $current->exists ? $current->media()->whereIn('id', $data['remove_media_ids'] ?? [])->get() : collect();
                if ($removed->count() !== count($data['remove_media_ids'] ?? [])) {
                    throw ValidationException::withMessages(['remove_media_ids' => 'Every removed image must belong to this product.']);
                }
                $remaining = $current->exists ? $current->media()->whereNotIn('id', $data['remove_media_ids'] ?? [])->get() : collect();
                if ($remaining->count() + count($gallery) > 100) {
                    throw ValidationException::withMessages(['gallery' => 'A product supports at most 100 media files.']);
                }
                if (isset($data['media_order']) && ($remaining->count() !== count($data['media_order']) || $remaining->whereIn('id', $data['media_order'])->count() !== $remaining->count())) {
                    throw ValidationException::withMessages(['media_order' => 'Refresh the product and order all its remaining media files.']);
                }
                $this->fill($current, $data);
                if (! $current->exists || ! empty($data['slug'])) {
                    $current->slug = $this->uniqueSlug($data['slug'] ?? $data['name'], $current->id);
                }
                $oldImage = null;
                if ($image || ! empty($data['remove_image'])) {
                    $oldImage = $current->image;
                    $current->image = $image ? $this->storeImage($image) : null;
                    if ($current->image) {
                        $createdFiles[] = $current->image;
                    }
                }
                $current->save();
                foreach ($removed as $media) {
                    $media->delete();
                }
                foreach ($data['media_order'] ?? [] as $position => $id) {
                    $current->media()->whereKey($id)->update(['position' => $position + 1]);
                }
                $position = (int) ($current->media()->max('position') ?? 0);
                foreach ($gallery as $file) {
                    $path = $this->storeImage($file);
                    $createdFiles[] = $path;
                    ProductMedia::create(['store_id' => $current->store_id, 'store_product_id' => $current->id,
                        'type' => $file->getMimeType() === 'video/mp4' ? 'video' : 'gallery', 'disk' => 'public', 'path' => $path, 'alt' => $current->name,
                        'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'position' => ++$position]);
                }
                DB::afterCommit(function () use ($current, $oldImage, $removed) {
                    $this->deleteImage($oldImage);
                    foreach ($removed as $media) {
                        $this->deleteMediaFiles($media);
                    }
                    StorefrontService::forgetHomepage((int) $current->store_id);
                    app(StorefrontPageCache::class)->flushStore((int) $current->store_id);
                });

                return $current;
            });
        } catch (\Throwable $exception) {
            foreach ($createdFiles as $path) {
                // A post-commit cache failure must never delete files referenced by committed rows.
                if (! Product::query()->where('image', $path)->exists() && ! ProductMedia::query()->where('path', $path)->exists()) {
                    $this->deleteImage($path);
                }
            }
            throw $exception;
        }
    }

    public function delete(Product $product): void
    {
        $storeId = (int) $product->store_id;
        DB::transaction(function () use ($product, $storeId) {
            $current = Product::query()->where('store_id', $storeId)->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($current->reserved_quantity > 0 || $current->variants()->where('reserved_quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['inventory' => 'Ship or cancel reserved orders before deleting this product.']);
            }
            $media = $current->media()->get();
            $image = $current->image;
            $current->delete();
            DB::afterCommit(function () use ($media, $image, $storeId) {
                $this->deleteImage($image);
                foreach ($media as $item) {
                    $this->deleteMediaFiles($item);
                }
                StorefrontService::forgetHomepage($storeId);
                app(StorefrontPageCache::class)->flushStore($storeId);
            });
        });
    }

    private function fill(Product $product, array $data): void
    {
        if (array_key_exists('description', $data)) {
            $data['description'] = ProductDescription::clean($data['description']);
            // The editor hydrates the legacy long copy first; an explicit edit becomes canonical.
            $product->long_description = null;
        }
        foreach ($data['translations']['description'] ?? [] as $locale => $html) {
            $data['translations']['description'][$locale] = ProductDescription::clean($html);
        }
        foreach (['name', 'sku', 'barcode', 'description', 'short_description', 'price', 'compare_price', 'cost', 'weight', 'seo_title', 'seo_description', 'category_id', 'is_active', 'is_featured', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $product->{$key} = $data[$key];
            }
        }
        if (array_key_exists('translations', $data)) {
            $product->fillTranslations($data['translations'] ?? []);
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
        if ($path && str_starts_with($path, 'store-catalog/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function deleteMediaFiles(ProductMedia $media): void
    {
        if (($media->disk ?: 'public') !== 'public') {
            return;
        }
        foreach ([$media->path, $media->webp_path, $media->thumbnail_path] as $path) {
            $this->deleteImage($path);
        }
    }
}

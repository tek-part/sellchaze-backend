<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCatalogOperation;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\ProductPreferences;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\ProductDescription;
use App\Support\ProductOptionDisplay;
use App\Support\Slug;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
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
                $hash = null;
                if (! $product && isset($data['creation_key'])) {
                    $storeId = app(CurrentStore::class)->id();
                    Store::query()->whereKey($storeId)->lockForUpdate()->firstOrFail();
                    $hash = hash('sha256', json_encode([Arr::except($data, ['image', 'gallery']), $image ? hash_file('sha256', $image->getRealPath()) : null,
                        array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $gallery)], JSON_THROW_ON_ERROR));
                    $receipt = StoreCatalogOperation::query()->where('idempotency_key', $data['creation_key'])->first();
                    if ($receipt) {
                        abort_unless($receipt->operation === 'editor-create' && hash_equals($receipt->payload_hash, $hash), 409, 'This creation attempt has already saved different data. Refresh your product list.');

                        return Product::query()->findOr($receipt->result['id'], fn () => abort(410, 'This product was already created and subsequently removed. Start a new creation.'));
                    }
                }
                $current = $product ? Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail() : new Product;
                $advanced = array_intersect(['product_preferences', 'display_priority', 'catalog_hidden'], array_keys($data));
                if ($product && $advanced !== []) {
                    abort_unless(isset($data['preferences_version']) && hash_equals(ProductPreferences::version($current), $data['preferences_version']), 409, 'Product settings changed. Reload the product before saving.');
                }
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
                if (array_key_exists('option_display', $data) && $product) {
                    ProductOptionDisplay::validate($current, $data['option_display'], $data['remove_media_ids'] ?? []);
                    $current->option_display = $data['option_display'];
                }
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
                if (array_key_exists('category_ids', $data) || array_key_exists('category_id', $data)) {
                    $ids = array_values(array_unique(array_merge($data['category_ids'] ?? [], $current->category_id ? [$current->category_id] : [])));
                    $current->additionalCategories()->sync(array_fill_keys($ids, ['store_id' => $current->store_id]));
                }
                app(DigitalProducts::class)->appendCodes($current, $data['digital_codes'] ?? []);
                foreach ($removed as $media) {
                    $media->delete();
                }
                foreach ($data['media_order'] ?? [] as $position => $id) {
                    $current->media()->whereKey($id)->update(['position' => $position + 1]);
                }
                $position = (int) ($current->media()->max('position') ?? 0);
                $uploadedMedia = [];
                foreach ($gallery as $file) {
                    $path = $this->storeImage($file);
                    $createdFiles[] = $path;
                    $uploadedMedia[] = ProductMedia::create(['store_id' => $current->store_id, 'store_product_id' => $current->id,
                        'type' => $file->getMimeType() === 'video/mp4' ? 'video' : 'gallery', 'disk' => 'public', 'path' => $path, 'alt' => $current->name,
                        'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'position' => ++$position]);
                }
                if (! $product && isset($data['creation'])) {
                    app(ProductCreationDraft::class)->apply($current, $data['creation'], (int) auth()->id());
                }
                if (! $product && (array_key_exists('option_display', $data) || ! empty($data['creation']['gallery_images']))) {
                    $display = app(ProductCreationDraft::class)->displayImages($data['option_display'] ?? [], $data['creation']['gallery_images'] ?? [], $uploadedMedia);
                    ProductOptionDisplay::validate($current, $display, []);
                    $current->option_display = $display;
                    $current->save();
                }
                if ($hash !== null) {
                    StoreCatalogOperation::create(['store_id' => $current->store_id, 'actor_id' => auth()->id(),
                        'idempotency_key' => $data['creation_key'], 'operation' => 'editor-create', 'payload_hash' => $hash,
                        'result' => ['id' => $current->id]]);
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
        if (array_key_exists('product_preferences', $data)) {
            $product->product_preferences = ProductPreferences::canonical($data['product_preferences']);
        }
        foreach (['display_priority', 'catalog_hidden'] as $key) {
            if (array_key_exists($key, $data)) {
                $product->{$key} = $data[$key];
            }
        }
        app(DigitalProducts::class)->configure($product, $data);
        if (array_key_exists('personalization_fields', $data)) {
            $product->personalization_fields = $data['personalization_fields'];
        }
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
            // Duplicates keep paths; portable imports can retain the same asset's public URL.
            $publicUrl = Storage::disk('public')->url($path);
            $references = array_values(array_unique([$path, $publicUrl, url($publicUrl)]));
            if (Product::withoutGlobalScopes()->whereIn('image', $references)->exists()
                || ProductVariant::withoutGlobalScopes()->whereIn('image', $references)->exists()
                || ProductMedia::withoutGlobalScopes()->where(fn ($q) => $q->whereIn('path', $references)->orWhereIn('webp_path', $references)->orWhereIn('thumbnail_path', $references))->exists()) {
                return;
            }
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

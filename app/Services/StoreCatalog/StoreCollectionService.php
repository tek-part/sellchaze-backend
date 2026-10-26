<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\StoreCollection;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\Catalog\CollectionRules;
use App\Support\ProductDescription;
use App\Support\Slug;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Merchant writes for store collections. Every query runs under the current store's StoreScope. */
class StoreCollectionService
{
    public function create(array $data, ?UploadedFile $image = null): StoreCollection
    {
        return $this->persist(new StoreCollection(['type' => 'manual']), $data, $image);
    }

    public function update(StoreCollection $collection, array $data, ?UploadedFile $image = null): StoreCollection
    {
        return $this->persist($collection, $data, $image);
    }

    private function persist(StoreCollection $collection, array $data, ?UploadedFile $image): StoreCollection
    {
        $created = null;
        try {
            return DB::transaction(function () use ($collection, $data, $image, &$created) {
                $default = $collection->translationDefaultLocale();
                if (array_key_exists('type', $data)) {
                    $collection->is_automated = $data['type'] === 'automated';
                }
                if (array_key_exists('rules', $data)) {
                    $collection->rules = $data['rules'] ? CollectionRules::normalize($data['rules']) : null;
                }
                if (array_key_exists('match', $data)) {
                    $collection->rules_match = $data['match'];
                }
                if ($collection->is_automated && ! $collection->usesRules()) {
                    throw ValidationException::withMessages(['rules' => 'Add at least one condition to an automated collection.']);
                }
                foreach (['sort_order' => 'sort_order', 'published' => 'is_active', 'seo_title' => 'seo_title', 'seo_description' => 'seo_description', 'position' => 'position'] as $input => $column) {
                    if (array_key_exists($input, $data)) {
                        $collection->{$column} = $data[$input];
                    }
                }
                if ($collection->is_automated && $collection->sort_order === 'manual') {
                    $collection->sort_order = 'best_selling';
                }

                $translations = $data['translations'] ?? null;
                if (is_array($translations)) {
                    foreach ($translations['description'] ?? [] as $locale => $html) {
                        $translations['description'][$locale] = ProductDescription::clean($html, true);
                    }
                    $collection->fillTranslations(array_intersect_key($translations, array_flip(['name', 'description'])));
                }
                if (array_key_exists('title', $data)) {
                    $collection->name = $data['title'];
                    $collection->setTranslations('name', array_merge($collection->translationsFor('name'), [$default => $data['title']]));
                }
                if (array_key_exists('description', $data)) {
                    $collection->description = ProductDescription::clean($data['description'], true);
                    $collection->setTranslations('description', array_merge($collection->translationsFor('description'), [$default => $collection->description]));
                }

                if (! empty($data['handle'])) {
                    $collection->slug = $data['handle'];
                } elseif (! $collection->exists) {
                    $collection->slug = Slug::unique((string) $collection->name, fn (string $slug) => StoreCollection::query()->where('slug', $slug)->exists(), 'collection');
                }

                $old = null;
                if ($image || ! empty($data['remove_image'])) {
                    $old = $collection->image;
                    $collection->image = $image ? ($created = $image->store('store-catalog/collections', 'public')) : null;
                }
                $collection->save();

                if (array_key_exists('product_ids', $data)) {
                    $this->syncProducts($collection, $data['product_ids'] ?? []);
                }

                $storeId = (int) $collection->store_id;
                DB::afterCommit(function () use ($old, $storeId) {
                    $this->deleteImage($old);
                    $this->flush($storeId);
                });

                return $collection->refresh();
            });
        } catch (\Throwable $exception) {
            if ($created) {
                $this->deleteImage($created);
            }
            throw $exception;
        }
    }

    /**
     * Replace a manual collection's products, keeping the given order.
     *
     * @param  array<int, int|string>  $productIds
     */
    public function syncProducts(StoreCollection $collection, array $productIds): void
    {
        if ($collection->is_automated) {
            throw ValidationException::withMessages(['product_ids' => 'Products of an automated collection come from its conditions.']);
        }
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $owned = Product::query()->where('products.store_id', $collection->store_id)->whereIn('products.id', $ids)->pluck('products.id')->all();
        if (count($owned) !== count($ids)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose products from this store only.']);
        }
        $rows = [];
        foreach ($ids as $position => $id) {
            $rows[$id] = ['position' => $position, 'store_id' => $collection->store_id];
        }
        DB::transaction(function () use ($collection, $rows) {
            $collection->products()->sync($rows);
            $collection->touch();
        });
        $storeId = (int) $collection->store_id;
        DB::afterCommit(fn () => $this->flush($storeId));
    }

    /** @param  list<int>  $ids */
    public function setPublished(array $ids, bool $published): int
    {
        $updated = StoreCollection::query()->whereIn('id', $ids)->update(['is_active' => $published, 'updated_at' => now()]);
        $this->flush((int) app(CurrentStore::class)->id());

        return $updated;
    }

    /** @param  list<int>  $ids */
    public function deleteMany(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $collections = StoreCollection::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($collections->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => 'A selected collection is unavailable. Refresh the list.']);
            }
            $images = $collections->pluck('image')->filter()->all();
            DB::table('store_collection_product')->whereIn('store_collection_id', $collections->modelKeys())->delete();
            StoreCollection::query()->whereIn('id', $collections->modelKeys())->delete();
            $storeId = (int) app(CurrentStore::class)->id();
            DB::afterCommit(function () use ($images, $storeId) {
                foreach ($images as $image) {
                    $this->deleteImage($image);
                }
                $this->flush($storeId);
            });

            return $collections->count();
        });
    }

    private function flush(int $storeId): void
    {
        StorefrontService::forgetHomepage($storeId);
        StorefrontService::forgetCollections($storeId);
        app(StorefrontPageCache::class)->flushStore($storeId);
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'store-catalog/collections/') && ! StoreCollection::withoutGlobalScopes()->where('image', $path)->exists()) {
            Storage::disk('public')->delete($path);
        }
    }
}

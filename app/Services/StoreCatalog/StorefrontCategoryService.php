<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\Slug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Owner management of store categories. Runs under the ScopeToStore tenant, so
 * StoreScope auto-scopes queries and BelongsToStore auto-fills store_id.
 * Slugs are unique per store.
 */
class StorefrontCategoryService
{
    public function create(array $data, ?UploadedFile $image = null): Category
    {
        $category = new Category;
        $this->fill($category, $data);
        $category->slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
        if ($image) {
            $category->image = $this->storeImage($image);
        }
        $category->save();

        StorefrontService::forgetHomepage((int) $category->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $category->store_id);

        return $category;
    }

    public function update(Category $category, array $data, ?UploadedFile $image = null): Category
    {
        $this->fill($category, $data);
        if (! empty($data['slug'])) {
            $category->slug = $this->uniqueSlug($data['slug'], $category->id);
        }
        if ($image) {
            $this->deleteImage($category->image);
            $category->image = $this->storeImage($image);
        }
        $category->save();

        StorefrontService::forgetHomepage((int) $category->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $category->store_id);

        return $category;
    }

    public function delete(Category $category): void
    {
        $storeId = (int) $category->store_id;
        $this->deleteImage($category->image);
        $category->delete();
        StorefrontService::forgetHomepage($storeId);
        app(StorefrontPageCache::class)->flushStore($storeId);
    }

    private function fill(Category $category, array $data): void
    {
        foreach (['name', 'description', 'is_active', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $category->{$key} = $data[$key];
            }
        }
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        return Slug::unique($base, function (string $slug) use ($ignoreId) {
            return Category::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
        }, 'category');
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

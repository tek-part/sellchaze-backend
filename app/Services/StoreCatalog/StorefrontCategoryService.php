<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\ProductDescription;
use App\Support\Slug;
use App\Support\StoreCategoryVersion;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StorefrontCategoryService
{
    public function create(array $data, ?UploadedFile $image = null): Category
    {
        return $this->persist(null, $data, $image);
    }

    public function update(Category $category, array $data, ?UploadedFile $image = null): Category
    {
        return $this->persist($category, $data, $image);
    }

    private function persist(?Category $category, array $data, ?UploadedFile $image): Category
    {
        $created = null;
        try {
            return DB::transaction(function () use ($category, $data, $image, &$created) {
                // One store lock serializes graph edits, preventing concurrent A->B / B->A cycles.
                $store = Store::query()->whereKey(app(CurrentStore::class)->id())->lockForUpdate()->firstOrFail();
                $current = $category ? Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail() : new Category;
                if ($current->exists && isset($data['edit_version']) && ! hash_equals(StoreCategoryVersion::for($current), $data['edit_version'])) {
                    throw ValidationException::withMessages(['edit_version' => 'This category changed. Reload it before saving again.']);
                }
                $this->assertParent($current->id, array_key_exists('parent_id', $data) ? $data['parent_id'] : $current->parent_id);
                // Canonical legacy labels must agree with the edited default name.
                if (array_key_exists('name', $data)) {
                    $current->name_en = $current->name_ar = $data['name'];
                    $current->setTranslations('name', array_merge($current->translationsFor('name'), ['en' => $data['name'], 'ar' => $data['name'], $current->translationDefaultLocale() => $data['name']]));
                }
                foreach (['name', 'parent_id', 'is_active', 'show_in_header', 'is_hidden', 'position', 'seo_title', 'seo_description'] as $key) {
                    if (array_key_exists($key, $data)) {
                        $current->{$key} = $data[$key];
                    }
                }
                if (array_key_exists('description', $data)) {
                    $current->description = ProductDescription::clean($data['description']);
                    $current->setTranslations('description', array_merge($current->translationsFor('description'), [$current->translationDefaultLocale() => $current->description]));
                }
                if (! $current->exists || ! empty($data['slug'])) {
                    $current->slug = Slug::unique($data['slug'] ?? $data['name'], fn ($slug) => Category::query()->where('slug', $slug)->when($current->exists, fn ($q) => $q->where('id', '!=', $current->id))->exists(), 'category');
                }
                $old = null;
                if ($image || ! empty($data['remove_image'])) {
                    $old = $current->image;
                    $current->image = $image ? ($created = $image->store('store-catalog/categories', 'public')) : null;
                }
                $current->save();
                DB::afterCommit(function () use ($old, $store) {
                    $this->deleteImage($old);
                    $this->flush((int) $store->id);
                });

                return $current->refresh();
            });
        } catch (\Throwable $exception) {
            if ($created) {
                $this->deleteImage($created);
            }
            throw $exception;
        }
    }

    private function assertParent(?int $id, ?int $parentId): void
    {
        $visited = $id === null ? [] : [$id => true];
        $depth = 0;
        while ($parentId !== null) {
            if (isset($visited[$parentId]) || ++$depth > 20) {
                throw ValidationException::withMessages(['parent_id' => 'Choose a parent outside this category tree, within 20 levels.']);
            }
            $visited[$parentId] = true;
            $parent = Category::query()->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'The parent must belong to this store.']);
            }
            $parentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }
    }

    /** Category deletion never cascades into products or another store's graph. */
    public function delete(Category $category): void
    {
        $this->deleteMany([['id' => $category->id, 'version' => StoreCategoryVersion::for($category)]]);
    }

    public function deleteMany(array $targets): int
    {
        return DB::transaction(function () use ($targets) {
            $store = Store::query()->whereKey(app(CurrentStore::class)->id())->lockForUpdate()->firstOrFail();
            $versions = collect($targets)->keyBy('id');
            $categories = Category::query()->whereIn('id', $versions->keys())->orderBy('id')->lockForUpdate()->get();
            if ($categories->count() !== $versions->count()) {
                throw ValidationException::withMessages(['categories' => 'A selected category is unavailable. Refresh the list.']);
            }
            foreach ($categories as $category) {
                abort_unless(request()->user()?->can('delete', $category), 403);
                if (! hash_equals(StoreCategoryVersion::for($category), $versions[$category->id]['version'])) {
                    throw ValidationException::withMessages(['categories' => 'A selected category changed. Refresh the list.']);
                }
            }
            $ids = $categories->modelKeys();
            foreach ([Product::withoutGlobalScopes()->whereIn('category_id', $ids), Category::withoutGlobalScopes()->whereIn('parent_id', $ids)] as $query) {
                if ($query->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', '!=', $store->id))->exists()) {
                    throw ValidationException::withMessages(['categories' => 'Invalid cross-store associations must be reviewed before deletion.']);
                }
            }
            Product::query()->whereIn('category_id', $ids)->update(['category_id' => null]);
            Category::query()->whereIn('parent_id', $ids)->update(['parent_id' => null]);
            $images = $categories->pluck('image')->filter()->all();
            Category::query()->whereIn('id', $ids)->delete();
            DB::afterCommit(function () use ($images, $store) {
                foreach ($images as $image) {
                    $this->deleteImage($image);
                }
                $this->flush((int) $store->id);
            });

            return count($ids);
        });
    }

    private function flush(int $storeId): void
    {
        StorefrontService::forgetHomepage($storeId);
        app(StorefrontPageCache::class)->flushStore($storeId);
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'store-catalog/') && ! Category::withoutGlobalScopes()->where('image', $path)->exists() && ! Product::withoutGlobalScopes()->where('image', $path)->exists()) {
            Storage::disk('public')->delete($path);
        }
    }
}

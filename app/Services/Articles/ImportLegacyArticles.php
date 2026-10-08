<?php

namespace App\Services\Articles;

use App\Models\Store;
use App\Models\StoreArticle;
use App\Models\StoreContentPage;
use App\Services\Storefront\StorefrontPageCache;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Facades\DB;

class ImportLegacyArticles
{
    public function import(Store $store, ?int $actorId): array
    {
        return DB::transaction(function () use ($store, $actorId) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $legacy = StoreContentPage::query()->where('store_id', $store->id)->where('key', 'blog')->lockForUpdate()->first();
            if (! $legacy) {
                return [];
            }
            $original = $legacy->data ?? [];
            $default = LocaleContext::storeDefault($store);
            $locales = array_unique([...LocaleContext::storeSupported($store), ...array_keys($original)]);
            $groups = [];
            $reader = app(StoreBlogContent::class);
            foreach ($locales as $locale) {
                $copy = $original[$locale] ?? $original[$default] ?? (array_values($original)[0] ?? []);
                foreach ($reader->legacyPosts(is_array($copy['posts'] ?? null) ? $copy['posts'] : []) as $position => $post) {
                    $slug = $post['slug'];
                    abort_if(mb_strlen($slug) > 180, 422, 'An existing article URL is too long to import. Shorten it in the existing content editor first.');
                    unset($post['slug']);
                    $groups[$slug]['draft'][$locale] = $post;
                    $groups[$slug]['positions'][$locale] = $position;
                }
            }
            if ($groups === []) {
                return [];
            }
            abort_if(StoreArticle::query()->where('store_id', $store->id)->whereIn('slug', array_keys($groups))->exists(), 409, 'An imported URL already exists. No articles were moved.');
            $ids = [];
            foreach ($groups as $slug => $group) {
                $row = StoreArticle::create([
                    'store_id' => $store->id, 'slug' => $slug, 'draft' => $group['draft'],
                    'legacy_positions' => $group['positions'],
                    'publication' => $legacy->is_published ? $group['draft'] : null,
                    'publish_at' => $legacy->is_published ? now() : null,
                ]);
                $ids[] = $row->id;
            }
            DB::table('store_article_imports')->insert([
                'store_id' => $store->id, 'actor_id' => $actorId,
                'original_content' => json_encode($original, JSON_THROW_ON_ERROR),
                'was_published' => $legacy->is_published,
                'article_ids' => json_encode($ids, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $remaining = $original;
            foreach ($remaining as &$copy) {
                // Original content, including non-rendering empty rows, is retained in the import backup.
                $copy['posts'] = [];
            }
            unset($copy);
            $legacy->update(['data' => $remaining]);
            DB::afterCommit(fn () => app(StorefrontPageCache::class)->flushStore($store->id));

            return $ids;
        });
    }
}

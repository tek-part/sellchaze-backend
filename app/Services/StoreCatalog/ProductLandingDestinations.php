<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\StorePage;
use App\Services\Storefront\PublishedPageResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductLandingDestinations
{
    private static function query(int $store, string $type, bool $public = true): Builder
    {
        $query = match ($type) {
            'product' => Product::withoutGlobalScopes(),
            'category' => Category::withoutGlobalScopes(),
            'page' => StorePage::withoutGlobalScopes()->with('funnel'),
            default => throw ValidationException::withMessages(['type' => 'Choose a product, category or page.']),
        };
        $query->where('store_id', $store);
        if ($public) {
            if ($type === 'page') {
                $query->where('template', '!=', 'home')->where(fn ($q) => $q->where('status', 'published')->orWhere(fn ($scheduled) => $scheduled->where('status', 'scheduled')->where('publish_at', '<=', now())));
            } else {
                $query->where('is_active', true)->whereNotNull('slug')->where('slug', '!=', '');
                if ($type === 'category') {
                    $query->where('is_hidden', false);
                }
            }
        }

        return $query;
    }

    public static function canonical(array $destination, Product $product): array
    {
        if (! self::query((int) $product->store_id, $destination['type'], false)->whereKey($destination['id'])->exists()) {
            throw ValidationException::withMessages(['document' => 'Every selected destination must belong to this store.']);
        }

        return ['type' => $destination['type'], 'id' => (int) $destination['id']];
    }

    public static function url(int $store, array $destination): ?string
    {
        $row = self::query($store, $destination['type'])->find($destination['id']);
        if (! $row) {
            return null;
        }

        if ($row instanceof StorePage) {
            return $row->publicPath();
        }

        return ($row instanceof Product || $row instanceof Category) && $row->slug
            ? ($row instanceof Product ? '/products/' : '/collections/').rawurlencode($row->slug)
            : null;
    }

    public static function documentUrls(Product $product): array
    {
        $urls = [];
        $walk = function (array $value) use (&$walk, &$urls, $product): void {
            if (isset($value['destination'])) {
                $destination = $value['destination'];
                $key = $destination['type'].':'.$destination['id'];
                if (! array_key_exists($key, $urls)) {
                    $urls[$key] = self::url((int) $product->store_id, $destination);
                }
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk(ProductLandingPages::document($product));

        return $urls;
    }

    /** Public-safe labels only; product editing never grants access to draft page contents. */
    public static function options(int $store, string $type, string $search, int $page, ?int $selected): array
    {
        $query = self::query($store, $type);
        $title = $type === 'page'
            ? DB::table('store_page_publications')->select('snapshot->page->title')->whereColumn('store_id', 'store_pages.store_id')->whereColumn('store_page_id', 'store_pages.id')->orderByDesc('version')->limit(1)
            : null;
        if ($title) {
            $query->select('store_pages.*')->selectSub($title, 'landing_public_title');
        }
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            if ($title) {
                $query->whereRaw('COALESCE(('.$title->toSql().'), title) LIKE ? ESCAPE \'!\'', [...$title->getBindings(), $pattern]);
            } else {
                $query->whereRaw('name LIKE ? ESCAPE \'!\'', [$pattern]);
            }
        }
        $rows = $query->orderBy('id')->offset(($page - 1) * 30)->limit(31)->get();
        $more = $rows->count() > 30;
        $rows = $rows->take(30);
        $picked = $selected ? self::query($store, $type)->find($selected) : null;
        $label = static function ($row) use ($type): array {
            $name = $row instanceof StorePage ? ($row->landing_public_title ?? app(PublishedPageResolver::class)->latestPublication($row)['page']['title'] ?? $row->title) : $row->name;

            return ['id' => $row->id, 'name' => $name, 'url' => $row instanceof StorePage ? $row->publicPath() : ($type === 'product' ? '/products/' : '/collections/').rawurlencode((string) $row->slug)];
        };

        return ['data' => $rows->map($label)->values()->all(), 'selected' => $picked ? $label($picked) : null, 'meta' => ['page' => $page, 'has_more' => $more]];
    }
}

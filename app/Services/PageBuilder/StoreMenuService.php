<?php

namespace App\Services\PageBuilder;

use App\Models\Store;
use App\Models\StoreMenu;
use App\Models\StoreMenuItem;
use App\Services\Storefront\StorefrontPageCache;
use App\Support\Localization\LocaleContext;
use App\Support\Localization\LocalizedValue;
use Illuminate\Support\Facades\DB;

/**
 * Navigation menus (Task 4/5): header/footer/custom menus with nested, typed items
 * (internal | category | product | url).
 *
 * Labels are localized (E4): an item label may arrive as a string or `{locale: label}`.
 * Both are stored — `label_i18n` keeps the map, `label` keeps the store-default pick —
 * and tree() resolves the label for the requested locale with fallback.
 */
class StoreMenuService
{
    public function __construct(private readonly StorefrontPageCache $cache) {}

    public function upsertMenu(Store $store, string $handle, string $name): StoreMenu
    {
        return StoreMenu::updateOrCreate(
            ['store_id' => $store->id, 'handle' => $handle],
            ['name' => $name],
        );
    }

    /** @param array<int,array{label:string|array<string,string>,type?:string,target?:string,children?:array}> $items */
    public function syncItems(StoreMenu $menu, array $items): StoreMenu
    {
        $default = $this->defaultLocale($menu);
        DB::transaction(function () use ($menu, $items, $default) {
            $menu->items()->delete();
            $this->createItems($menu, $items, null, $default);
        });
        $this->cache->flushStore($menu->store_id);

        return $menu->refresh();
    }

    public function delete(StoreMenu $menu): void
    {
        $storeId = $menu->store_id;
        $menu->delete();
        $this->cache->flushStore($storeId);
    }

    /**
     * Resolve a menu into a nested tree for rendering, labels picked for `$locale`
     * (request locale → store default → stored `label`). `label_i18n` is included
     * so the dashboard editor can show every locale.
     */
    public function tree(StoreMenu $menu, ?string $locale = null): array
    {
        $items = $menu->items()->get();
        $fallback = $this->defaultLocale($menu);
        $locale ??= app(LocaleContext::class)->has() ? app(LocaleContext::class)->current() : $fallback;

        return $this->buildTree($items, null, $locale, $fallback);
    }

    private function defaultLocale(StoreMenu $menu): string
    {
        $store = $menu->relationLoaded('store') ? $menu->store : Store::query()->find($menu->store_id);

        return $store ? LocaleContext::storeDefault($store) : app(LocaleContext::class)->fallback();
    }

    private function createItems(StoreMenu $menu, array $items, ?int $parentId, string $default): void
    {
        $position = 0;
        foreach ($items as $item) {
            $rawLabel = $item['label'] ?? null;
            $labelI18n = is_array($rawLabel) ? LocalizedValue::normalize($rawLabel, $default) : null;
            $label = is_array($rawLabel)
                ? LocalizedValue::pick($labelI18n, $default, $default)
                : trim((string) ($rawLabel ?? ''));

            $row = StoreMenuItem::create([
                'store_menu_id' => $menu->id,
                'store_id' => $menu->store_id,
                'parent_id' => $parentId,
                'label' => $label !== '' ? $label : 'Item',
                'label_i18n' => $labelI18n === [] ? null : $labelI18n,
                'type' => in_array($item['type'] ?? 'url', StoreMenuItem::TYPES, true) ? ($item['type'] ?? 'url') : 'url',
                'target' => $item['target'] ?? null,
                'position' => $position++,
            ]);
            if (! empty($item['children']) && is_array($item['children'])) {
                $this->createItems($menu, $item['children'], $row->id, $default);
            }
        }
    }

    private function buildTree($items, ?int $parentId, string $locale, string $fallback): array
    {
        return $items->where('parent_id', $parentId)->sortBy('position')->values()->map(function (StoreMenuItem $item) use ($items, $locale, $fallback) {
            $picked = LocalizedValue::pick($item->label_i18n, $locale, $fallback);

            return [
                'label' => $picked !== '' ? $picked : $item->label,
                'label_i18n' => $item->label_i18n ?: [$fallback => $item->label],
                'type' => $item->type,
                'target' => $item->target,
                'url' => $this->resolveUrl($item),
                'children' => $this->buildTree($items, $item->id, $locale, $fallback),
            ];
        })->all();
    }

    private function resolveUrl(StoreMenuItem $item): string
    {
        return match ($item->type) {
            'category' => '/categories/'.ltrim((string) $item->target, '/'),
            'product' => '/products/'.ltrim((string) $item->target, '/'),
            'internal' => '/'.ltrim((string) $item->target, '/'),
            default => (string) ($item->target ?? '#'),
        };
    }
}

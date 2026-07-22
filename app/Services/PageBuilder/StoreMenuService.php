<?php

namespace App\Services\PageBuilder;

use App\Models\Store;
use App\Models\StoreMenu;
use App\Models\StoreMenuItem;
use App\Services\Storefront\StorefrontPageCache;
use Illuminate\Support\Facades\DB;

/**
 * Navigation menus (Task 4/5): header/footer/custom menus with nested, typed items
 * (internal | category | product | url).
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

    /** @param array<int,array{label:string,type?:string,target?:string,children?:array}> $items */
    public function syncItems(StoreMenu $menu, array $items): StoreMenu
    {
        DB::transaction(function () use ($menu, $items) {
            $menu->items()->delete();
            $this->createItems($menu, $items, null);
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

    /** Resolve a menu into a nested tree for rendering. */
    public function tree(StoreMenu $menu): array
    {
        $items = $menu->items()->get();

        return $this->buildTree($items, null);
    }

    private function createItems(StoreMenu $menu, array $items, ?int $parentId): void
    {
        $position = 0;
        foreach ($items as $item) {
            $row = StoreMenuItem::create([
                'store_menu_id' => $menu->id,
                'store_id' => $menu->store_id,
                'parent_id' => $parentId,
                'label' => $item['label'] ?? 'Item',
                'type' => in_array($item['type'] ?? 'url', StoreMenuItem::TYPES, true) ? $item['type'] : 'url',
                'target' => $item['target'] ?? null,
                'position' => $position++,
            ]);
            if (! empty($item['children']) && is_array($item['children'])) {
                $this->createItems($menu, $item['children'], $row->id);
            }
        }
    }

    private function buildTree($items, ?int $parentId): array
    {
        return $items->where('parent_id', $parentId)->sortBy('position')->values()->map(function (StoreMenuItem $item) use ($items) {
            return [
                'label' => $item->label,
                'type' => $item->type,
                'target' => $item->target,
                'url' => $this->resolveUrl($item),
                'children' => $this->buildTree($items, $item->id),
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreMenu;
use App\Services\PageBuilder\StoreMenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Navigation menus (Tasks 4 & 5): header/footer/custom menus with nested, typed
 * items (internal | category | product | url). Owner/admin, tenant-scoped.
 */
class StoreMenusApiController extends Controller
{
    public function __construct(private readonly StoreMenuService $service) {}

    /** GET /stores/{store}/menus */
    public function index(Request $request, Store $store): JsonResponse
    {
        $menus = StoreMenu::query()->where('store_id', $store->id)->orderBy('handle')->get()
            ->map(fn (StoreMenu $m) => ['id' => $m->id, 'handle' => $m->handle, 'name' => $m->name]);

        return response()->json(['data' => $menus], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /stores/{store}/menus/{handle} — resolved nested tree. */
    public function show(Request $request, Store $store, string $handle): JsonResponse
    {
        $menu = StoreMenu::query()->where('store_id', $store->id)->where('handle', $handle)->first();
        abort_if($menu === null, 404, 'Menu not found.');

        return response()->json([
            'menu' => ['id' => $menu->id, 'handle' => $menu->handle, 'name' => $menu->name],
            'items' => $this->service->tree($menu),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** PUT /stores/{store}/menus/{handle} — upsert menu + replace its item tree. */
    public function upsert(Request $request, Store $store, string $handle): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9\-]+$/', $handle), 422, 'Invalid handle.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['present', 'array'],
        ]);

        $menu = $this->service->upsertMenu($store, $handle, $data['name']);
        $menu = $this->service->syncItems($menu, $data['items']);

        return response()->json([
            'menu' => ['id' => $menu->id, 'handle' => $menu->handle, 'name' => $menu->name],
            'items' => $this->service->tree($menu),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, string $handle): JsonResponse
    {
        $menu = StoreMenu::query()->where('store_id', $store->id)->where('handle', $handle)->first();
        abort_if($menu === null, 404);
        $this->service->delete($menu);

        return response()->json(['message' => 'Deleted.'], 200);
    }
}

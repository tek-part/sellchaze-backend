<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreContentPage;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use App\Support\StoreContent\ContentPageSchema;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Editable "system" storefront pages (about/contact/faq/shipping/returns/blog).
 * Dashboard endpoints (store-scoped) list + save the typed payload; the storefront
 * endpoint (host-resolved) reads it so themes can merge it over their defaults.
 */
class StoreContentPagesApiController extends Controller
{
    /** The dashboard tenant store, set by ResolveOwnStore (/my-store) or ScopeToStore (/stores/{store}). */
    private function scopedStore(): Store
    {
        $store = app(CurrentStore::class)->get();
        if (! $store) {
            throw new NotFoundHttpException;
        }

        return $store;
    }

    /** Dashboard: the full set of system pages with their schema + current data. */
    public function index(Request $request): JsonResponse
    {
        $store = $this->scopedStore();
        $rows = StoreContentPage::query()->where('store_id', $store->id)->get()->keyBy('key');

        $pages = [];
        foreach (ContentPageSchema::all() as $key => $def) {
            $row = $rows->get($key);
            $pages[] = [
                'key' => $key,
                'label' => $def['label'],
                'path' => $def['path'],
                'fields' => $def['fields'],
                'data' => $row?->data ?? (object) [],
                'is_published' => $row?->is_published ?? true,
                'customized' => $row !== null,
            ];
        }

        return response()->json(['data' => $pages], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Dashboard: save one page's payload. */
    public function update(Request $request, string $key): JsonResponse
    {
        if (! ContentPageSchema::has($key)) {
            throw new NotFoundHttpException;
        }

        $store = $this->scopedStore();

        $data = $request->input('data', []);
        if (! is_array($data)) {
            $data = [];
        }

        $row = StoreContentPage::query()->updateOrCreate(
            ['store_id' => $store->id, 'key' => $key],
            ['data' => $data, 'is_published' => $request->boolean('is_published', true)],
        );

        $this->flushStorefront($store->id);

        return response()->json([
            'data' => [
                'key' => $row->key,
                'data' => $row->data ?? (object) [],
                'is_published' => $row->is_published,
                'customized' => true,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Storefront (host-resolved): read one page's published payload, or null. */
    public function storefront(Request $request, string $key): JsonResponse
    {
        $store = app(CurrentStore::class)->get();
        if (! $store || ! ContentPageSchema::has($key)) {
            throw new NotFoundHttpException;
        }

        $row = StoreContentPage::query()
            ->where('store_id', $store->id)
            ->where('key', $key)
            ->where('is_published', true)
            ->first();

        return response()->json(['data' => $row?->data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function flushStorefront(int $storeId): void
    {
        StorefrontService::forgetHomepage($storeId);
        app(StorefrontPageCache::class)->flushStore($storeId);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreReusableSection;
use App\Services\PageBuilder\ReusableSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable/global sections (Task 3). Editing one updates every page that
 * references it. Owner/admin, tenant-scoped via store.scope.
 */
class StoreReusableSectionsApiController extends Controller
{
    public function __construct(private readonly ReusableSectionService $service) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $rows = StoreReusableSection::query()->orderBy('name')->get()
            ->map(fn (StoreReusableSection $s) => $this->toArray($s));

        return response()->json(['data' => $rows], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:40'],
            'settings' => ['nullable', 'array'],
        ]);
        $section = $this->service->upsert($store, $data);

        return response()->json(['data' => $this->toArray($section)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, Store $store, int $reusable): JsonResponse
    {
        $existing = StoreReusableSection::query()->findOrFail($reusable);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:40'],
            'settings' => ['sometimes', 'array'],
        ]);
        $section = $this->service->upsert($store, $data, $existing);

        return response()->json(['data' => $this->toArray($section)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Store $store, int $reusable): JsonResponse
    {
        $this->service->delete(StoreReusableSection::query()->findOrFail($reusable));

        return response()->json(['message' => 'Deleted.'], 200);
    }

    private function toArray(StoreReusableSection $s): array
    {
        return ['id' => $s->id, 'key' => $s->key, 'name' => $s->name, 'type' => $s->type, 'settings' => $s->settings];
    }
}

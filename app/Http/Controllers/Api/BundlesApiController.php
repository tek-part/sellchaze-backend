<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Product;
use App\Support\ProductImageUrl;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BundlesApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bundle::query()->withCount('products');

        if (! UserScope::isAdmin($request->user())) {
            $query->where('user_id', UserScope::effectiveMerchantUserId($request->user()));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $data = $paginator->getCollection()->map(static function (Bundle $b) {
            return [
                'id' => $b->id,
                'name' => $b->name,
                'description' => $b->description,
                'user_id' => $b->user_id,
                'products_count' => (int) $b->products_count,
                'created_at' => $b->created_at,
                'updated_at' => $b->updated_at,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Bundle $bundle): JsonResponse
    {
        if (! UserScope::isAdmin($request->user())) {
            if ((int) $bundle->user_id !== UserScope::effectiveMerchantUserId($request->user())) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        }

        $bundle->load(['products.category:id,name,name_en,name_ar']);

        $locale = $this->bundleRequestLocale($request);

        $products = $bundle->products->map(function (Product $p) use ($locale) {
            $thumb = ProductImageUrl::thumbUrl($p->image);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'image_thumb_url' => $thumb,
                'quantity' => (int) ($p->pivot->quantity ?? 1),
                'category' => $p->category ? [
                    'id' => $p->category->id,
                    'name_en' => $p->category->name_en,
                    'name_ar' => $p->category->name_ar,
                    'name' => $p->category->labelForLocale($locale),
                ] : null,
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'id' => $bundle->id,
                'name' => $bundle->name,
                'description' => $bundle->description,
                'user_id' => $bundle->user_id,
                'products' => $products,
                'created_at' => $bundle->created_at,
                'updated_at' => $bundle->updated_at,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $bundle = Bundle::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'user_id' => UserScope::isAdmin($request->user())
                ? ($request->input('user_id') ?? $request->user()->id)
                : UserScope::effectiveMerchantUserId($request->user()),
        ]);

        $this->syncBundleItems($bundle, $validated['items'] ?? []);

        return $this->show($request, $bundle->fresh());
    }

    public function update(Request $request, Bundle $bundle): JsonResponse
    {
        $this->assertCanManageBundle($request, $bundle);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $bundle->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        if (array_key_exists('items', $validated)) {
            $this->syncBundleItems($bundle, $validated['items'] ?? []);
        }

        return $this->show($request, $bundle->fresh());
    }

    public function destroy(Request $request, Bundle $bundle): JsonResponse
    {
        $this->assertCanManageBundle($request, $bundle);
        $bundle->delete();

        return response()->json(['message' => 'Deleted.'], 200);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:bundles,id'],
        ]);

        $deleted = 0;
        foreach ($validated['ids'] as $id) {
            $bundle = Bundle::query()->find($id);
            if (! $bundle) {
                continue;
            }
            if (! $this->userOwnsBundle($request, $bundle)) {
                continue;
            }
            $bundle->delete();
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted], 200);
    }

    private function assertCanManageBundle(Request $request, Bundle $bundle): void
    {
        if (! $this->userOwnsBundle($request, $bundle)) {
            abort(403, 'Forbidden.');
        }
    }

    private function userOwnsBundle(Request $request, Bundle $bundle): bool
    {
        if (UserScope::isAdmin($request->user())) {
            return true;
        }

        return (int) $bundle->user_id === UserScope::effectiveMerchantUserId($request->user());
    }

    /**
     * @param  array<int, array{product_id: int, quantity?: int}>  $items
     */
    private function syncBundleItems(Bundle $bundle, array $items): void
    {
        $bundle->products()->detach();
        foreach ($items as $row) {
            $bundle->products()->attach((int) $row['product_id'], [
                'quantity' => (int) ($row['quantity'] ?? 1),
            ]);
        }
    }

    private function bundleRequestLocale(Request $request): string
    {
        $q = $request->query('locale');
        if (is_string($q) && $q !== '') {
            $c = strtolower(substr(trim($q), 0, 2));

            return $c === 'ar' ? 'ar' : 'en';
        }
        $accept = (string) $request->header('Accept-Language', 'en');
        $first = strtolower(trim(explode(',', explode(';', $accept)[0])[0] ?? 'en'));
        $first = substr(str_replace('_', '-', $first), 0, 2);

        return $first === 'ar' ? 'ar' : 'en';
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriesApiController extends Controller
{
    private function requestDisplayLocale(Request $request): string
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

    /**
     * @return array{id:int, name:string, name_en:?string, name_ar:?string, products_count:int, created_at:mixed, updated_at:mixed}
     */
    private function categoryToArray(Category $category, Request $request, int $productsCount = 0): array
    {
        $locale = $this->requestDisplayLocale($request);

        return [
            'id' => $category->id,
            'name_en' => $category->name_en,
            'name_ar' => $category->name_ar,
            'name' => $category->labelForLocale($locale),
            'products_count' => $productsCount,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    private function mergeLegacyNameIntoTranslations(Request $request): void
    {
        $name = $request->input('name');
        if (! is_string($name) || trim($name) === '') {
            return;
        }
        $trim = trim($name);
        if (! $request->filled('name_en')) {
            $request->merge(['name_en' => $trim]);
        }
        if (! $request->filled('name_ar')) {
            $request->merge(['name_ar' => $trim]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->withCount('products');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('name_en', 'like', $term)
                    ->orWhere('name_ar', 'like', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $paginator = $query->orderByRaw('COALESCE(NULLIF(name_en, ""), name) ASC')->paginate($perPage);

        $data = $paginator->getCollection()->map(function (Category $c) use ($request) {
            return $this->categoryToArray($c, $request, (int) ($c->products_count ?? 0));
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

    public function store(Request $request): JsonResponse
    {
        $this->mergeLegacyNameIntoTranslations($request);

        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
        ]);

        $category = Category::query()->create($validated);

        return response()->json(['data' => $this->categoryToArray($category, $request, 0)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Category $category, Request $request): JsonResponse
    {
        $category->loadCount('products');

        return response()->json([
            'data' => $this->categoryToArray($category, $request, (int) ($category->products_count ?? 0)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->mergeLegacyNameIntoTranslations($request);

        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
        ]);

        $category->update($validated);
        $category->loadCount('products');

        return response()->json([
            'data' => $this->categoryToArray($category, $request, (int) ($category->products_count ?? 0)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Category has products. Remove or reassign products first.',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Deleted.'], 200);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $deleted = 0;
        $skipped = 0;

        foreach ($validated['ids'] as $id) {
            $category = Category::query()->find($id);
            if (! $category) {
                continue;
            }
            if ($category->products()->exists()) {
                $skipped++;

                continue;
            }
            $category->delete();
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted, 'skipped' => $skipped], 200);
    }
}

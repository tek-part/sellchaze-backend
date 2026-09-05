<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FlushesOwnerStorefront;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Rbac\UserScope;
use App\Support\Localization\TranslationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategoriesApiController extends Controller
{
    use FlushesOwnerStorefront;

    /** The owner whose categories the actor manages (admins are not narrowed). */
    private function ownerId(Request $request): ?int
    {
        if (UserScope::isAdmin($request->user())) {
            return null;
        }

        return UserScope::effectiveMerchantUserId($request->user());
    }

    /** Block a non-admin from touching a category owned by someone else. */
    private function authorizeOwnership(Request $request, Category $category): void
    {
        $ownerId = $this->ownerId($request);
        if ($ownerId !== null && (int) $category->user_id !== $ownerId) {
            throw new NotFoundHttpException;
        }
    }

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
            'translations' => $category->translationsPayload(),
            'image' => $category->image,
            'image_url' => $category->imageUrl(),
            'products_count' => $productsCount,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    /** Save a category image to the public storage dir (served without the storage:link symlink). */
    private function storeCategoryImage($file): string
    {
        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$file->getClientOriginalExtension();
        $dir = public_path('storage/uploads/categories');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        Image::make($file->getRealPath())->save($dir.'/'.$filename, 90);

        return 'uploads/categories/'.$filename;
    }

    private function deleteCategoryImageFiles(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }
        $full = public_path('storage/'.$path);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * Accept three input shapes for the bilingual name and normalise to name_en/name_ar:
     *   1. `translations.name.{en,ar}` (the LocaleTabs editor; JSON string on multipart);
     *   2. explicit `name_en`/`name_ar`;
     *   3. a single legacy `name` copied into whichever of the two is missing.
     */
    private function mergeLegacyNameIntoTranslations(Request $request): void
    {
        TranslationRules::decodeRequest($request);

        $translated = $request->input('translations.name');
        if (is_array($translated)) {
            foreach (['en', 'ar'] as $locale) {
                $value = $translated[$locale] ?? null;
                if (is_string($value) && trim($value) !== '' && ! $request->filled("name_{$locale}")) {
                    $request->merge(["name_{$locale}" => trim($value)]);
                }
            }
        }

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

    /** @return array<string, mixed> translations payload (name/description) or null */
    private function translationsInput(array $validated): ?array
    {
        return is_array($validated['translations'] ?? null) ? $validated['translations'] : null;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->withCount('products');

        if (($ownerId = $this->ownerId($request)) !== null) {
            $query->where('user_id', $ownerId);
        }

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
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ] + TranslationRules::for(['name', 'description']));

        // Own the category to the actor's catalog so it appears in their scoped
        // list and storefront. Admins own the categories they create too.
        $category = new Category([
            'name_en' => $validated['name_en'],
            'name_ar' => $validated['name_ar'],
            'user_id' => UserScope::effectiveMerchantUserId($request->user()),
        ]);
        if (($translations = $this->translationsInput($validated)) !== null) {
            $category->fillTranslations($translations);
        }
        $category->save();

        if ($request->hasFile('image')) {
            $category->image = $this->storeCategoryImage($request->file('image'));
            $category->save();
        }

        $this->flushOwnerStorefront((int) $category->user_id);

        return response()->json(['data' => $this->categoryToArray($category, $request, 0)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Category $category, Request $request): JsonResponse
    {
        $this->authorizeOwnership($request, $category);
        $category->loadCount('products');

        return response()->json([
            'data' => $this->categoryToArray($category, $request, (int) ($category->products_count ?? 0)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeOwnership($request, $category);
        $this->mergeLegacyNameIntoTranslations($request);

        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ] + TranslationRules::for(['name', 'description']));

        $category->fill([
            'name_en' => $validated['name_en'],
            'name_ar' => $validated['name_ar'],
        ]);
        if (($translations = $this->translationsInput($validated)) !== null) {
            $category->fillTranslations($translations);
        }
        $category->save();

        if ($request->hasFile('image')) {
            $this->deleteCategoryImageFiles($category->image);
            $category->image = $this->storeCategoryImage($request->file('image'));
            $category->save();
        } elseif ($request->boolean('remove_image')) {
            $this->deleteCategoryImageFiles($category->image);
            $category->image = null;
            $category->save();
        }

        $category->loadCount('products');
        $this->flushOwnerStorefront((int) $category->user_id);

        return response()->json([
            'data' => $this->categoryToArray($category, $request, (int) ($category->products_count ?? 0)),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeOwnership($request, $category);

        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Category has products. Remove or reassign products first.',
            ], 422);
        }

        $ownerId = (int) $category->user_id;
        $category->delete();
        $this->flushOwnerStorefront($ownerId);

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
        $ownerId = $this->ownerId($request);

        foreach ($validated['ids'] as $id) {
            $category = Category::query()->find($id);
            if (! $category) {
                continue;
            }
            if ($ownerId !== null && (int) $category->user_id !== $ownerId) {
                $skipped++;

                continue;
            }
            if ($category->products()->exists()) {
                $skipped++;

                continue;
            }
            $ownerId = (int) $category->user_id;
            $category->delete();
            $this->flushOwnerStorefront($ownerId);
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted, 'skipped' => $skipped], 200);
    }
}

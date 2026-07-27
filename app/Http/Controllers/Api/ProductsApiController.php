<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FlushesOwnerStorefront;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\ProductImageUrl;
use App\Models\ProductAttributes;
use App\Models\ProductMedia;
use App\Services\ProductDeletionService;
use App\Services\Rbac\UserScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class ProductsApiController extends Controller
{
    use FlushesOwnerStorefront;

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with(['category:id,name,name_en,name_ar']);

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->get('category_id'));
        }

        if ($request->filled('search')) {
            $raw = $request->string('search')->trim();
            $term = '%'.$raw.'%';
            $query->where(function ($q) use ($term, $raw) {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
                if (ctype_digit($raw)) {
                    $q->orWhere('id', (int) $raw);
                }
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if (! UserScope::isAdmin($request->user())) {
            $query->where('user_id', UserScope::effectiveMerchantUserId($request->user()));
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $data = $paginator->getCollection()->map(fn (Product $p) => $this->serializeProduct($p, $request))->values()->all();

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
        $this->normalizeArrayInputs($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'attribute_ids' => ['nullable', 'array'],
            'attribute_ids.*' => ['integer', 'exists:attributes,id'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $product = Product::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
            'category_id' => $validated['category_id'],
            'user_id' => $request->user()->id,
        ]);

        if ($request->hasFile('image')) {
            $product->image = $this->storeProductImage($request->file('image'));
            $product->save();
        }

        foreach ($validated['attribute_ids'] ?? [] as $attributeId) {
            ProductAttributes::query()->create([
                'attribute_id' => $attributeId,
                'product_id' => $product->id,
            ]);
        }

        $this->syncGalleryMedia($request, $product);

        $product->load(['category:id,name,name_en,name_ar', 'product_attributes.attribute:id,name', 'media']);
        $this->flushOwnerStorefront((int) $product->user_id);

        return response()->json(['data' => $this->serializeProductWithAttributes($product, $request)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->assertCanManageProduct($request, $product);
        $product->load([
            'category:id,name,name_en,name_ar',
            'product_attributes.attribute:id,name',
            'media',
        ]);

        return response()->json(['data' => $this->serializeProductWithAttributes($product, $request)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->assertCanManageProduct($request, $product);
        $this->normalizeArrayInputs($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'attribute_ids' => ['nullable', 'array'],
            'attribute_ids.*' => ['integer', 'exists:attributes,id'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'remove_media_ids' => ['nullable'],
        ]);

        $product->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
            'category_id' => $validated['category_id'],
        ]);

        if ($request->hasFile('image')) {
            $this->deleteProductImageFiles($product->image);
            $product->image = $this->storeProductImage($request->file('image'));
            $product->save();
        } elseif ($request->boolean('remove_image')) {
            $this->deleteProductImageFiles($product->image);
            $product->image = null;
            $product->save();
        }

        ProductAttributes::query()->where('product_id', $product->id)->delete();
        foreach ($validated['attribute_ids'] ?? [] as $attributeId) {
            ProductAttributes::query()->create([
                'attribute_id' => $attributeId,
                'product_id' => $product->id,
            ]);
        }

        $this->syncGalleryMedia($request, $product);

        $product->load(['category:id,name,name_en,name_ar', 'product_attributes.attribute:id,name', 'media']);
        $this->flushOwnerStorefront((int) $product->user_id);

        return response()->json(['data' => $this->serializeProductWithAttributes($product, $request)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->assertCanManageProduct($request, $product);
        $ownerId = (int) $product->user_id;
        app(ProductDeletionService::class)->delete($product);
        $this->flushOwnerStorefront($ownerId);

        return response()->json(['message' => 'Deleted.'], 200);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:products,id'],
        ]);

        $deleted = 0;
        foreach ($validated['ids'] as $id) {
            $product = Product::query()->find($id);
            if (! $product) {
                continue;
            }
            if (! UserScope::isAdmin($request->user())
                && (int) $product->user_id !== UserScope::effectiveMerchantUserId($request->user())) {
                continue;
            }
            $ownerId = (int) $product->user_id;
            app(ProductDeletionService::class)->delete($product);
            $this->flushOwnerStorefront($ownerId);
            $deleted++;
        }

        return response()->json(['message' => 'OK', 'deleted' => $deleted], 200);
    }

    private function assertCanManageProduct(Request $request, Product $product): void
    {
        if (UserScope::isAdmin($request->user())) {
            return;
        }
        if ((int) $product->user_id !== UserScope::effectiveMerchantUserId($request->user())) {
            abort(403, 'Forbidden.');
        }
    }

    private function normalizeArrayInputs(Request $request): void
    {
        // multipart/form-data cannot natively encode arrays; accept comma-separated or attribute_ids[] keys
        $ids = $request->input('attribute_ids');
        if (is_string($ids)) {
            $parsed = array_values(array_filter(array_map('intval', explode(',', $ids)), fn ($n) => $n > 0));
            $request->merge(['attribute_ids' => $parsed]);
        }
    }

    /**
     * Apply gallery changes from the request: delete media in remove_media_ids[]
     * and store any newly uploaded gallery[] files as ordered ProductMedia rows.
     */
    private function syncGalleryMedia(Request $request, Product $product): void
    {
        $removeIds = $request->input('remove_media_ids', []);
        if (is_string($removeIds)) {
            $removeIds = array_filter(array_map('intval', explode(',', $removeIds)));
        }
        $removeIds = array_values(array_filter(array_map('intval', (array) $removeIds), fn ($n) => $n > 0));

        if ($removeIds) {
            foreach ($product->media()->whereIn('id', $removeIds)->get() as $m) {
                $this->deleteGalleryFile($m->path);
                $m->delete();
            }
        }

        $files = $request->file('gallery', []);
        if ($files) {
            $position = (int) ($product->media()->max('position') ?? 0);
            foreach ((array) $files as $file) {
                if (! $file) {
                    continue;
                }
                // Stored under public/storage/uploads/... exactly like the cover image,
                // so it's served without depending on the storage:link symlink.
                $path = $this->storeGalleryImage($file);
                ProductMedia::query()->create([
                    'store_id' => $product->store_id,
                    'store_product_id' => $product->id,
                    'type' => 'gallery',
                    'disk' => 'public',
                    'path' => $path,
                    'alt' => $product->name,
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'position' => ++$position,
                ]);
            }
        }
    }

    /** Save a gallery image to the public storage dir; returns the disk-relative path. */
    private function storeGalleryImage($file): string
    {
        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$file->getClientOriginalExtension();
        $dir = public_path('storage/uploads/products/gallery');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        Image::make($file->getRealPath())->save($dir.'/'.$filename, 90);

        return 'uploads/products/gallery/'.$filename;
    }

    private function deleteGalleryFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }
        // Current convention (public/storage/uploads/...).
        $full = public_path('storage/'.$path);
        if (is_file($full)) {
            @unlink($full);
        }
        // Legacy convention (storage/app/public/... via the public disk).
        Storage::disk('public')->delete($path);
    }

    private function storeProductImage($file): string
    {
        $filename = md5($file->getClientOriginalName().microtime(true)).'.'.$file->getClientOriginalExtension();
        $originalPath = public_path('storage/uploads/products/original');
        $thumbPath = public_path('storage/uploads/products/thumbnails');
        if (! is_dir($originalPath)) {
            mkdir($originalPath, 0755, true);
        }
        if (! is_dir($thumbPath)) {
            mkdir($thumbPath, 0755, true);
        }

        Image::make($file->getRealPath())->save($originalPath.'/'.$filename, 90);
        Image::make($file->getRealPath())
            ->resize(600, 600, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->save($thumbPath.'/'.$filename, 85);

        return $filename;
    }

    private function deleteProductImageFiles(?string $filename): void
    {
        if (! $filename || str_contains($filename, '/') || str_starts_with($filename, 'http')) {
            return;
        }
        $orig = public_path('storage/uploads/products/original/'.$filename);
        $thumb = public_path('storage/uploads/products/thumbnails/'.$filename);
        if (is_file($orig)) {
            @unlink($orig);
        }
        if (is_file($thumb)) {
            @unlink($thumb);
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

    private function serializeProduct(Product $p, ?Request $request = null): array
    {
        $thumb = ProductImageUrl::thumbUrl($p->image);
        $original = null;
        if ($p->image) {
            if (preg_match('#\Ahttps?://#i', $p->image)) {
                $original = $p->image;
            } elseif (! str_contains($p->image, '/')) {
                $original = url('/storage/uploads/products/original/'.$p->image);
            } else {
                $original = $thumb;
            }
        }

        $locale = $request ? $this->requestDisplayLocale($request) : 'en';
        $cat = $p->category;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'image' => $p->image,
            'image_thumb_url' => $thumb,
            'image_url' => $original,
            'category_id' => $p->category_id,
            'category' => $cat ? [
                'id' => $cat->id,
                'name_en' => $cat->name_en,
                'name_ar' => $cat->name_ar,
                'name' => $cat->labelForLocale($locale),
            ] : null,
            'user_id' => $p->user_id,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ];
    }

    private function serializeProductWithAttributes(Product $product, Request $request): array
    {
        $data = $this->serializeProduct($product, $request);
        $data['attributes'] = $product->product_attributes
            ->map(fn ($pa) => [
                'id' => $pa->attribute_id,
                'name' => $pa->attribute?->name,
            ])
            ->filter(fn ($a) => ! empty($a['name']))
            ->values()
            ->all();
        $data['attribute_ids'] = collect($data['attributes'])->pluck('id')->values()->all();

        $data['media'] = $product->media
            ->map(fn (ProductMedia $m) => ['id' => $m->id, 'url' => $m->url()])
            ->filter(fn ($m) => ! empty($m['url']))
            ->values()
            ->all();

        return $data;
    }
}

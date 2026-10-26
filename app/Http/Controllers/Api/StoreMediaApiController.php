<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreMediaAsset;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreMediaApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $rows = StoreMediaAsset::query()->where('store_id', $store->id)
            ->when($request->filled('search'), fn ($query) => $query->where('original_name', 'like', '%'.$request->string('search').'%'))
            ->latest('id')->paginate(min(60, max(12, (int) $request->get('per_page', 30))));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (StoreMediaAsset $asset) => $this->asset($asset)),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:12288']]);
        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = StoreMediaAsset::withTrashed()->where('store_id', $store->id)->where('checksum_sha256', $checksum)->first();
        if ($existing) {
            if ($existing->trashed()) $existing->restore();
            return response()->json(['data' => $this->asset($existing->fresh()), 'deduplicated' => true]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('stores/'.$store->id.'/media/'.now()->format('Y/m'), Str::uuid().'.'.$extension, 'public');
        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];
        $asset = StoreMediaAsset::create([
            'store_id' => $store->id,
            'uploaded_by_user_id' => $request->user()?->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => $request->string('alt_text')->trim()->value() ?: null,
            'checksum_sha256' => $checksum,
        ]);

        return response()->json(['data' => $this->asset($asset)], 201);
    }

    public function update(Request $request, int $asset): JsonResponse
    {
        $model = $this->find($asset);
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:500']]);
        $model->update(['alt_text' => $data['alt_text'] ?? null]);

        return response()->json(['data' => $this->asset($model)]);
    }

    public function destroy(Request $request, int $asset): JsonResponse
    {
        $this->find($asset)->delete();

        return response()->json(['message' => 'Removed from media library.']);
    }

    private function find(int $id): StoreMediaAsset
    {
        return StoreMediaAsset::query()->where('store_id', $this->currentStore()->id)->findOrFail($id);
    }

    private function currentStore(): Store
    {
        return app(CurrentStore::class)->get() ?? throw new NotFoundHttpException;
    }

    private function asset(StoreMediaAsset $asset): array
    {
        $url = Storage::disk($asset->disk)->url($asset->path);
        if (! str_starts_with($url, 'http')) $url = url($url);

        return [
            'id' => $asset->id, 'url' => $url, 'name' => $asset->original_name,
            'mime' => $asset->mime, 'size_bytes' => $asset->size_bytes,
            'width' => $asset->width, 'height' => $asset->height,
            'alt_text' => $asset->alt_text, 'created_at' => $asset->created_at,
        ];
    }
}

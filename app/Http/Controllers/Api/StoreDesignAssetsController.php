<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreDesignAsset;
use App\Services\Storefront\DesignAssets;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StoreDesignAssetsController extends Controller
{
    use ResolvesStorefront;

    private function authorizedStore(Request $request): Store
    {
        $store = $this->currentStore($request);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.themes.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    private function row(StoreDesignAsset $asset, Store $store): array
    {
        $urls = app(StorefrontUrlGenerator::class);
        $url = $urls->canonicalScheme().'://'.$urls->publicHost($store).$asset->publicPath();

        return ['id' => $asset->id, 'name' => $asset->name, 'kind' => $asset->kind, 'size_bytes' => $asset->size_bytes, 'url' => $url, 'public_path' => $asset->publicPath(), 'checksum_sha256' => $asset->checksum_sha256, 'archived' => $asset->trashed(), 'created_at' => $asset->created_at];
    }

    public function index(Request $request, Store $store, DesignAssets $assets): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $request->validate(['search' => ['nullable', 'string', 'max:180'], 'kind' => ['nullable', 'in:css,js'], 'archived' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $rows = StoreDesignAsset::where('store_id', $store->id);
        if ($request->boolean('archived')) {
            $rows->onlyTrashed();
        }
        if ($request->filled('search')) {
            $rows->where('name', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('kind')) {
            $rows->where('kind', $request->string('kind')->value());
        }
        $page = $rows->latest('id')->paginate((int) $request->input('per_page', 20));
        $configuration = $assets->configuration($store);
        $selected = StoreDesignAsset::where('store_id', $store->id)->whereIn('id', $configuration['assets'])->get();

        return response()->json(['data' => $page->getCollection()->map(fn ($asset) => $this->row($asset, $store)), 'selected' => $selected->map(fn ($asset) => $this->row($asset, $store)), 'configuration' => $configuration, 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function upload(Request $request, Store $store): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $file = $request->file('file');
        $kind = strtolower($file->getClientOriginalExtension());
        $content = file_get_contents($file->getRealPath());
        if (! in_array($kind, ['css', 'js'], true) || $content === false || trim($content) === '' || str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'Upload a non-empty UTF-8 CSS or JavaScript file up to 2 MB.']);
        }
        $checksum = hash('sha256', $content);
        $createdPath = null;
        try {
            $result = DB::transaction(function () use ($store, $file, $kind, $content, $checksum, &$createdPath) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
                $existing = StoreDesignAsset::withTrashed()->where('store_id', $store->id)->where('kind', $kind)->where('checksum_sha256', $checksum)->first();
                if ($existing) {
                    abort_unless(Storage::disk('local')->exists($existing->path), 409, 'The original file is missing.');
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    return [$existing, false];
                }
                $id = (string) Str::uuid();
                $createdPath = 'store-design-assets/'.$store->id.'/'.$id.'.'.$kind;
                abort_unless(Storage::disk('local')->put($createdPath, $content), 503, 'Unable to store this file.');
                $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180);

                return [StoreDesignAsset::create(['store_id' => $store->id, 'public_id' => $id, 'name' => $name, 'kind' => $kind, 'path' => $createdPath, 'size_bytes' => strlen($content), 'checksum_sha256' => $checksum]), true];
            });
        } catch (\Throwable $error) {
            if ($createdPath !== null) {
                Storage::disk('local')->delete($createdPath);
            }
            throw $error;
        }

        return response()->json(['data' => $this->row($result[0], $store), 'deduplicated' => ! $result[1]], $result[1] ? 201 : 200);
    }

    public function settings(Request $request, Store $store, DesignAssets $assets): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'assets' => ['present', 'array', 'max:40'], 'assets.*' => ['integer', 'distinct']]);
        $store = DB::transaction(function () use ($store, $data, $assets) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            abort_unless($assets->configuration($locked)['version'] === (int) $data['version'], 409, 'Design files changed. Reload before saving.');
            if (StoreDesignAsset::where('store_id', $store->id)->whereIn('id', $data['assets'])->count() !== count($data['assets'])) {
                throw ValidationException::withMessages(['assets' => 'Select available files from this store.']);
            }
            $locked->update(['design_assets_configuration' => ['version' => (int) $data['version'] + 1, 'enabled' => (bool) $data['enabled'], 'assets' => array_map('intval', $data['assets'])]]);

            return $locked;
        });

        return response()->json(['data' => $assets->configuration($store)]);
    }

    public function archive(Request $request, Store $store, int $asset, DesignAssets $assets): JsonResponse
    {
        $store = $this->authorizedStore($request);
        DB::transaction(function () use ($store, $asset, $assets) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $model = StoreDesignAsset::where('store_id', $store->id)->findOrFail($asset);
            $model->delete();
            $config = $assets->configuration($locked);
            $config['version']++;
            $config['assets'] = array_values(array_filter($config['assets'], fn ($id) => (int) $id !== $asset));
            $locked->update(['design_assets_configuration' => $config]);
        });

        return response()->json(['message' => 'Archived. Restore before selecting it again.']);
    }

    public function restore(Request $request, Store $store, int $asset): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $model = DB::transaction(function () use ($store, $asset) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $model = StoreDesignAsset::onlyTrashed()->where('store_id', $store->id)->findOrFail($asset);
            abort_unless(Storage::disk('local')->exists($model->path), 409, 'The original file is missing.');
            $model->restore();

            return $model;
        });

        return response()->json(['data' => $this->row($model, $store)]);
    }

    public function manifest(Request $request, DesignAssets $assets): JsonResponse
    {
        return response()->json(['data' => $assets->publicManifest($this->currentStore($request))], 200, ['Cache-Control' => 'no-store']);
    }

    public function content(Request $request, string $file): Response
    {
        $store = $this->currentStore($request);
        [$id, $kind] = explode('.', $file);
        $asset = StoreDesignAsset::where('store_id', $store->id)->where('public_id', $id)->where('kind', $kind)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return response(Storage::disk('local')->get($asset->path), 200, ['Content-Type' => ($kind === 'css' ? 'text/css' : 'text/javascript').'; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}

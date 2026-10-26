<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeVersion;
use App\Services\Themes\ThemeAssetService;
use App\Services\Themes\ThemePublishingService;
use App\Services\Themes\ThemePackageService;
use App\Services\Themes\ThemeVersionPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin theme administration (Tasks 5 & 7): publishing workflow transitions
 * (draft -> review -> approved -> published -> deprecated) and marketplace assets.
 * Mounted under role:Admin.
 */
class AdminThemesController extends Controller
{
    public function __construct(
        private readonly ThemePublishingService $publishing,
        private readonly ThemeAssetService $assets,
        private readonly ThemePackageService $packages,
        private readonly ThemeVersionPublishingService $versionPublishing,
    ) {}

    /** GET /admin/themes — all themes incl. non-published, with status history. */
    public function index(): JsonResponse
    {
        $themes = Theme::query()->with(['assets', 'versions'])->withCount('versions')->orderBy('name')->get()->map(fn (Theme $t): array => [
            'id' => $t->id,
            'key' => $t->key,
            'name' => $t->name,
            'status' => $t->status,
            'is_marketplace' => $t->is_marketplace,
            'is_featured' => $t->is_featured,
            'description' => $t->description,
            'author' => $t->author,
            'preview_image' => $t->preview_image,
            'price' => $t->price,
            'currency' => $t->currency,
            'license_type' => $t->license_type,
            'support_days' => $t->support_days,
            'installs_count' => $t->installs_count,
            'versions_count' => $t->versions_count,
            'assets' => $t->assets->sortBy('position')->map(fn (ThemeAsset $asset) => [
                'id' => $asset->id,
                'type' => $asset->type,
                'url' => $asset->url(),
                'width' => $asset->width,
                'height' => $asset->height,
                'position' => $asset->position,
            ])->values()->all(),
            'versions' => $t->versions->sort(fn ($a, $b) => version_compare($b->version, $a->version))->map(fn (ThemeVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'bundle_url' => $version->bundle_url,
                'bundle_integrity' => $version->bundle_integrity,
                'bundle_size' => $version->bundle_size,
                'published_at' => $version->published_at,
            ])->values()->all(),
        ]);

        return response()->json(['data' => $themes], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** PATCH /admin/themes/{theme}/commercial — marketplace listing and licensing terms. */
    public function updateCommercial(Request $request, Theme $theme): JsonResponse
    {
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency', 'USD')))]);
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'license_type' => ['required', Rule::in(['free', 'lifetime'])],
            'support_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'is_marketplace' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ]);

        if ((float) $data['price'] > 0 && $data['license_type'] === 'free') {
            throw ValidationException::withMessages([
                'license_type' => ['A paid theme must use a lifetime license.'],
            ]);
        }

        if ((float) $data['price'] === 0.0) {
            $data['license_type'] = 'free';
        }

        $theme->forceFill($data)->save();

        return response()->json(['data' => [
            'id' => $theme->id,
            'price' => $theme->price,
            'currency' => $theme->currency,
            'license_type' => $theme->license_type,
            'support_days' => $theme->support_days,
            'is_marketplace' => (bool) $theme->is_marketplace,
            'is_featured' => (bool) $theme->is_featured,
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function storeVersion(Request $request, Theme $theme): JsonResponse
    {
        $request->validate([
            'manifest' => ['required', 'file', 'max:1024'],
            'bundle' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $version = $this->packages->upload($theme, $request->file('manifest'), $request->file('bundle'), $request->user()?->id);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            'bundle_url' => $version->bundle_url,
            'bundle_integrity' => $version->bundle_integrity,
            'bundle_size' => $version->bundle_size,
        ]], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function transitionVersion(Request $request, Theme $theme, ThemeVersion $version): JsonResponse
    {
        abort_if((int) $version->theme_id !== (int) $theme->id, 404);
        $data = $request->validate(['to' => ['required', 'string'], 'notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $version = $this->versionPublishing->transition($version, $data['to'], $request->user()?->id, $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $version->id, 'status' => $version->status, 'published_at' => $version->published_at]]);
    }

    /** GET /admin/themes/{theme}/history — status-change audit trail. */
    public function history(Theme $theme): JsonResponse
    {
        $rows = $theme->statusChanges()->with('actor:id,name')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($c) => [
                'from' => $c->from_status,
                'to' => $c->to_status,
                'actor' => $c->actor?->name,
                'notes' => $c->notes,
                'at' => $c->created_at,
            ]);

        return response()->json(['data' => $rows], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /admin/themes/{theme}/transition {to, notes} — publishing workflow. */
    public function transition(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $theme = $this->publishing->transition($theme, $data['to'], $request->user()?->id, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $theme->id, 'status' => $theme->status]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /admin/themes/{theme}/assets {type, image} */
    public function storeAsset(Request $request, Theme $theme): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'image' => ['required', 'image'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $asset = $this->assets->store($theme, $request->file('image'), $data['type'], $data['position'] ?? 0);
            if ($asset->type === 'preview') {
                $theme->forceFill(['preview_image' => $asset->url()])->save();
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $asset->id, 'type' => $asset->type, 'url' => $asset->url(),
            'width' => $asset->width, 'height' => $asset->height,
        ]], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /admin/themes/{theme}/assets/{asset} */
    public function destroyAsset(Theme $theme, ThemeAsset $asset): JsonResponse
    {
        abort_if((int) $asset->theme_id !== (int) $theme->id, 404);
        $wasPreview = $asset->type === 'preview';
        $this->assets->delete($asset);
        if ($wasPreview) {
            $replacement = $theme->assets()->where('type', 'preview')->orderBy('position')->first();
            $theme->forceFill(['preview_image' => $replacement?->url()])->save();
        }

        return response()->json(['message' => 'Deleted.'], 200);
    }
}

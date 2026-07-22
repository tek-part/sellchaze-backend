<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Services\Themes\ThemeAssetService;
use App\Services\Themes\ThemePublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    /** GET /admin/themes — all themes incl. non-published, with status history. */
    public function index(): JsonResponse
    {
        $themes = Theme::query()->withCount('versions')->orderBy('name')->get()->map(fn (Theme $t) => [
            'id' => $t->id,
            'key' => $t->key,
            'name' => $t->name,
            'status' => $t->status,
            'is_marketplace' => $t->is_marketplace,
            'versions_count' => $t->versions_count,
        ]);

        return response()->json(['data' => $themes], 200, [], JSON_UNESCAPED_UNICODE);
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
        $this->assets->delete($asset);

        return response()->json(['message' => 'Deleted.'], 200);
    }
}

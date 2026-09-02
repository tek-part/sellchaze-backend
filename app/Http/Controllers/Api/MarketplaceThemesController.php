<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Services\Themes\ThemeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marketplace catalog (Task 6) — infrastructure only, no payments. Lists published
 * themes with featured/new/popular filters + categories, and a detail view with
 * screenshots, changelog, versions, and compatibility.
 */
class MarketplaceThemesController extends Controller
{
    public function __construct(private readonly ThemeRegistry $registry) {}

    /** GET /marketplace/themes?filter=featured|new|popular&category= */
    public function index(Request $request): JsonResponse
    {
        $query = Theme::query()->where('status', 'published');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        match ($request->query('filter')) {
            'featured' => $query->where('is_featured', true)->orderByDesc('installs_count'),
            'popular' => $query->orderByDesc('installs_count'),
            'new' => $query->orderByDesc('id'),
            default => $query->orderByDesc('is_featured')->orderBy('name'),
        };

        $themes = $query->with('assets')->limit(60)->get()->map(fn (Theme $t) => $this->card($t));

        return response()->json([
            'data' => $themes,
            'categories' => Theme::query()->where('status', 'published')->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /marketplace/themes/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        $theme = Theme::query()->where('key', $key)->where('status', 'published')->firstOrFail();
        $theme->load('assets', 'versions');

        return response()->json([
            'theme' => array_merge($this->card($theme), [
                'description' => $theme->description,
                'screenshots' => $theme->assets->where('type', '!=', 'logo')->map(fn ($a) => $a->url())->values(),
            ]),
            'versions' => $theme->versions->sortByDesc('id')->map(fn ($v) => [
                'version' => $v->version,
                'changelog' => $v->changelog,
                'min_platform_version' => $v->min_platform_version,
                'max_platform_version' => $v->max_platform_version,
                'supported_features' => $v->supported_features,
                'published_at' => $v->published_at,
            ])->values(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function card(Theme $theme): array
    {
        $logo = $theme->relationLoaded('assets') ? $theme->assets->firstWhere('type', 'logo') : null;

        return [
            'id' => $theme->id,
            'key' => $theme->key,
            'name' => $theme->name,
            'author' => $theme->author,
            'category' => $theme->category,
            'is_featured' => $theme->is_featured,
            'installs_count' => $theme->installs_count,
            'latest_version' => $this->registry->resolveThemeVersion($theme)?->version,
            'logo_url' => $logo?->url(),
            'price' => $theme->price,
            'currency' => $theme->currency,
            'license_type' => $theme->license_type,
            'support_days' => $theme->support_days,
        ];
    }
}

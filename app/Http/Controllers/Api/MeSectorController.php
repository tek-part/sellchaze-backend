<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Lets the signed-in supplier/merchant choose which directory sectors they appear under (one or
 * many) and which is primary (drives "your sector first" feed ordering + default directory
 * placement). This is the seam that fills the public directory with real suppliers — without it a
 * supplier is registered but invisible to the directory.
 */
class MeSectorController extends Controller
{
    use ResolvesLocale;

    /** The full sector tree for the picker + the user's current selection. */
    public function index(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $user = $request->user();

        $roots = Sector::query()->active()->roots()->orderBy('position')
            ->with(['children' => fn ($q) => $q->where('is_active', true)])->get()
            ->map(fn (Sector $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->nameLocalized($locale),
                'icon' => $s->icon,
                'children' => $s->children->map(fn (Sector $c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->nameLocalized($locale),
                ])->values()->all(),
            ])->values()->all();

        return response()->json([
            'sectors' => $roots,
            'selected' => $user->sectors()->pluck('sectors.id')->map(fn ($id) => (int) $id)->values()->all(),
            'primary_sector_id' => $user->primary_sector_id ? (int) $user->primary_sector_id : null,
        ]);
    }

    /** Replace the user's sector membership + primary. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sector_ids' => ['present', 'array'],
            'sector_ids.*' => ['integer', 'distinct', 'exists:sectors,id'],
            'primary_sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['sector_ids'])));
        $primary = $data['primary_sector_id'] ?? null;

        // Primary must be one of the selected sectors; otherwise fall back to the first selected.
        if ($primary !== null && ! in_array($primary, $ids, true)) {
            $primary = null;
        }
        if ($primary === null && count($ids) > 0) {
            $primary = $ids[0];
        }

        $user = $request->user();

        // Sync the pivot with the correct is_primary flag on each row.
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['is_primary' => $id === $primary];
        }
        $user->sectors()->sync($sync);
        $user->primary_sector_id = $primary;
        $user->save();

        // Directory counts/stats are membership-derived — drop the cached aggregates.
        Cache::forget('directory.sector_counts');
        Cache::forget('directory.stats');

        // Joining (or moving between) sectors changes which public pages exist,
        // so rebuild the sitemap and let Google know. Deferred until after the
        // response, and best-effort: indexing must never fail the user's save.
        app()->terminating(function () {
            try {
                $generator = app(\App\Services\Seo\SitemapGenerator::class);
                if ($generator->generate() !== null) {
                    $generator->ping();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('Sitemap refresh failed: '.$e->getMessage());
            }
        });

        return response()->json([
            'message' => 'Sectors updated.',
            'selected' => $ids,
            'primary_sector_id' => $primary,
        ]);
    }
}

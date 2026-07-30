<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Sector;
use App\Support\Feed\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The community feed. Visible to every registered user. Three scopes:
 *   - scope=all (default): the whole feed, the viewer's own sector floated to the top, then recent.
 *   - scope=mine: only posts in the viewer's sector(s).
 *   - ?sector={slug}: only posts in that sector (and its specialties).
 */
class FeedController extends Controller
{
    use ResolvesLocale;

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $viewerId = $viewer?->id;
        $locale = $this->resolveLocale($request);

        $q = Post::query()->published()
            ->withFeedRelations()
            ->withExists(['likes as liked' => fn (Builder $l) => $l->where('user_id', $viewerId)]);

        $scope = (string) $request->query('scope', 'all');

        if ($sectorSlug = $request->query('sector')) {
            $node = Sector::query()->where('slug', $sectorSlug)->first();
            $ids = $node ? $node->children()->pluck('id')->push($node->id)->all() : [-1];
            $q->whereIn('sector_id', $ids)->orderByDesc('published_at');
        } elseif ($scope === 'mine') {
            $ids = $viewer?->sectors()->pluck('sectors.id')->all() ?: [];
            if ($viewer?->primary_sector_id) {
                $ids[] = $viewer->primary_sector_id;
            }
            $q->whereIn('sector_id', array_values(array_unique($ids)) ?: [-1])->orderByDesc('published_at');
        } else {
            // Default "all": your sector first, then everyone else, newest within each band.
            $primary = (int) ($viewer?->primary_sector_id ?? 0);
            if ($primary > 0) {
                $q->orderByRaw('CASE WHEN sector_id = ? THEN 0 ELSE 1 END', [$primary]);
            }
            $q->orderByDesc('published_at');
        }

        $rows = $q->paginate((int) min(30, max(5, (int) $request->query('per_page', 10))));

        return response()->json([
            'data' => collect($rows->items())->map(fn (Post $p) => PostPresenter::card($p, $viewerId, $locale))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }
}

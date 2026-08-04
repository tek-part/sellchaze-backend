<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unauthenticated endpoints backing the public Supplier Directory (sellchaze.com/suppliers) at three
 * levels: the sector hub, an industry (sector) page, and a specialty page. Read-only; safe to
 * prerender. Localises sector copy to the request locale (?lang=ar or Accept-Language).
 */
class SuppliersDirectoryController extends Controller
{
    use ResolvesLocale;

    /** Level 1 — the 8 sector cards + platform stats. */
    public function index(Request $request): JsonResponse
    {
        $this->applyLocale($request);

        $counts = $this->supplierCountsBySector();

        $sectors = Sector::query()->active()->roots()->orderBy('position')->get()
            ->map(fn (Sector $s) => [
                'slug' => $s->slug,
                'name' => $s->nameLocalized(),
                'icon' => $s->icon,
                'suppliers_count' => (int) ($counts[$s->id] ?? 0),
            ])->values()->all();

        return response()->json([
            'sectors' => $sectors,
            'stats' => $this->platformStats(),
        ]);
    }

    /** Level 2 — one industry page: sector meta + SEO + specialties + paginated suppliers. */
    public function sector(Request $request, string $sector): JsonResponse
    {
        $this->applyLocale($request);

        $node = Sector::query()->active()->roots()->where('slug', $sector)->first();
        if (! $node) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $children = $node->children()->where('is_active', true)->get();
        $childCounts = $this->supplierCountsBySector();
        $sectorIds = $children->pluck('id')->push($node->id)->all();

        return response()->json([
            'sector' => $this->sectorMeta($node),
            'specialties' => $children->map(fn (Sector $c) => [
                'slug' => $c->slug,
                'name' => $c->nameLocalized(),
                'suppliers_count' => (int) ($childCounts[$c->id] ?? 0),
            ])->values()->all(),
            'suppliers' => $this->paginatedSuppliers($request, $sectorIds),
        ]);
    }

    /** Level 3 — one specialty page: specialty meta + SEO + its suppliers. */
    public function specialty(Request $request, string $sector, string $specialty): JsonResponse
    {
        $this->applyLocale($request);

        $parent = Sector::query()->active()->roots()->where('slug', $sector)->first();
        if (! $parent) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $node = $parent->children()->where('is_active', true)->where('slug', $specialty)->first();
        if (! $node) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'sector' => ['slug' => $parent->slug, 'name' => $parent->nameLocalized()],
            'specialty' => $this->sectorMeta($node),
            'suppliers' => $this->paginatedSuppliers($request, [$node->id]),
        ]);
    }

    /** Flat, filterable supplier search across the whole directory (?sector=&q=&verified=&page=). */
    public function suppliers(Request $request): JsonResponse
    {
        $this->applyLocale($request);

        $sectorIds = null;
        if ($slug = $request->query('sector')) {
            $node = Sector::query()->where('slug', $slug)->first();
            if ($node) {
                $sectorIds = $node->children()->pluck('id')->push($node->id)->all();
            } else {
                $sectorIds = [-1]; // unknown sector → empty result, not "all"
            }
        }

        return response()->json($this->paginatedSuppliers($request, $sectorIds));
    }

    /** Platform-wide directory counters. */
    public function stats(): JsonResponse
    {
        return response()->json($this->platformStats());
    }

    /**
     * "Similar suppliers" for a supplier profile: other eligible suppliers that
     * share a sector, preferring the same city. Powers the internal-linking
     * block on every supplier page (directory → sector → city → supplier).
     */
    public function similar(Request $request, string $username): JsonResponse
    {
        $this->applyLocale($request);

        $user = User::query()
            ->whereHas('profile', fn ($q) => $q->where('username', $username))
            ->with('profile')
            ->first();

        if (! $user) {
            return response()->json(['suppliers' => []]);
        }

        $sectorIds = DB::table('supplier_sector')->where('user_id', $user->id)->pluck('sector_id')->all();
        if ($sectorIds === []) {
            return response()->json(['suppliers' => []]);
        }

        $city = $user->profile?->city;
        $limit = (int) min(12, max(3, (int) $request->query('limit', 6)));

        $rows = $this->eligibleSuppliers($sectorIds)
            ->where('users.id', '<>', $user->id)
            ->with(['profile', 'primarySector'])
            ->withCount('products')
            // Same city first, then verified, then newest — a stable, useful order.
            ->orderByRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM profiles p WHERE p.user_id = users.id AND p.city = ?) THEN 0 ELSE 1 END',
                [$city]
            )
            ->orderByDesc('is_verified')
            ->orderByDesc('users.created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'suppliers' => $rows->map(fn (User $u) => $this->supplierCard($u))->values()->all(),
        ]);
    }

    /**
     * Cities that currently have directory-eligible suppliers, with counts.
     * City landing pages are generated from this list, so a city page only ever
     * exists once a supplier from that city has registered — no thin pages.
     * Optionally scoped to a sector (?sector=slug).
     */
    public function cities(Request $request): JsonResponse
    {
        $this->applyLocale($request);

        $sectorIds = null;
        if ($slug = $request->query('sector')) {
            $node = Sector::query()->where('slug', $slug)->first();
            $sectorIds = $node ? $node->children()->pluck('id')->push($node->id)->all() : [-1];
        }

        $rows = $this->eligibleSuppliers($sectorIds)
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->whereNotNull('profiles.city')
            ->where('profiles.city', '<>', '')
            ->select('profiles.city', DB::raw('COUNT(DISTINCT users.id) as suppliers_count'))
            ->groupBy('profiles.city')
            ->orderByDesc('suppliers_count')
            ->get();

        $cities = $rows
            ->map(fn ($r) => [
                'city' => $r->city,
                'slug' => Str::slug($r->city),
                'suppliers_count' => (int) $r->suppliers_count,
            ])
            ->filter(fn ($c) => $c['slug'] !== '')
            ->values()
            ->all();

        return response()->json(['cities' => $cities]);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<int, string> $extra */
    private function sectorMeta(Sector $s): array
    {
        return [
            'slug' => $s->slug,
            'name' => $s->nameLocalized(),
            'icon' => $s->icon,
            'intro' => $s->introLocalized(),
            'seo_title' => $s->seoTitleLocalized(),
            'seo_description' => $s->seoDescriptionLocalized(),
        ];
    }

    /**
     * Base query for directory-eligible suppliers: active, not pending. Optionally constrained to a
     * set of sector ids via the supplier_sector pivot.
     *
     * @param array<int, int>|null $sectorIds
     * @return Builder<User>
     */
    private function eligibleSuppliers(?array $sectorIds): Builder
    {
        $q = User::query()
            ->where('is_active', true)
            ->where(function (Builder $w) {
                $w->whereNull('pending_approval')->orWhere('pending_approval', false);
            });

        if ($sectorIds !== null) {
            $q->whereExists(function ($sub) use ($sectorIds) {
                $sub->select(DB::raw(1))->from('supplier_sector')
                    ->whereColumn('supplier_sector.user_id', 'users.id')
                    ->whereIn('supplier_sector.sector_id', $sectorIds);
            });
        } else {
            // Whole-directory listing: only users that belong to at least one sector.
            $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))->from('supplier_sector')
                    ->whereColumn('supplier_sector.user_id', 'users.id');
            });
        }

        return $q;
    }

    /**
     * @param array<int, int>|null $sectorIds
     * @return array<string, mixed>
     */
    private function paginatedSuppliers(Request $request, ?array $sectorIds): array
    {
        $perPage = (int) min(48, max(6, (int) $request->query('per_page', 12)));

        $q = $this->eligibleSuppliers($sectorIds)
            ->with(['profile', 'primarySector'])
            ->withCount('products');

        if ($request->boolean('verified')) {
            $q->where('is_verified', true);
        }
        // City landing pages ("<specialty> suppliers in <city>"): matched on the
        // profile's city, slugified so the URL segment stays clean.
        if ($city = trim((string) $request->query('city', ''))) {
            $q->whereExists(function ($sub) use ($city) {
                $sub->select(DB::raw(1))->from('profiles')
                    ->whereColumn('profiles.user_id', 'users.id')
                    ->whereRaw(
                        "LOWER(REPLACE(REPLACE(TRIM(profiles.city), ' ', '-'), '_', '-')) = ?",
                        [Str::slug($city)]
                    );
            });
        }
        if ($term = trim((string) $request->query('q', ''))) {
            $q->where(function (Builder $w) use ($term) {
                $like = '%'.$term.'%';
                $w->where('users.name', 'like', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))->from('profiles')
                            ->whereColumn('profiles.user_id', 'users.id')
                            ->where(function ($p) use ($like) {
                                $p->where('company', 'like', $like)->orWhere('tagline', 'like', $like);
                            });
                    });
            });
        }

        $rows = $q->orderByDesc('is_verified')->orderByDesc('users.created_at')->paginate($perPage);

        return [
            'data' => collect($rows->items())->map(fn (User $u) => $this->supplierCard($u))->values()->all(),
            'meta' => [
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function supplierCard(User $u): array
    {
        $profile = $u->profile;
        $public = $profile && $profile->is_public;

        return [
            'id' => $u->id,
            'name' => $u->name,
            'company' => $profile?->company,
            'tagline' => $profile?->tagline,
            'city' => $profile?->city,
            'country' => $profile?->country,
            'is_verified' => (bool) ($u->is_verified ?? false),
            'products_count' => (int) ($u->products_count ?? 0),
            'photo' => $profile?->photo
                ? asset('storage/uploads/users/original/'.$profile->photo)
                : (! empty($u->avatar) ? $u->avatar : null),
            'primary_sector' => $u->primarySector ? [
                'slug' => $u->primarySector->slug,
                'name' => $u->primarySector->nameLocalized(),
            ] : null,
            // Deep link to the existing public profile page only when the supplier opted public.
            'username' => $public ? $profile->username : null,
        ];
    }

    /**
     * DISTINCT supplier count per sector id (a sector's own id gets its direct links only).
     *
     * Locale-agnostic numeric aggregate over the whole directory — cached briefly since it runs on
     * every hit of the public sector pages.
     */
    private function supplierCountsBySector(): array
    {
        return Cache::remember('directory.sector_counts', 300, function () {
            // Count direct links per sector, then roll child counts up into their parent.
            $direct = DB::table('supplier_sector')
                ->join('users', 'users.id', '=', 'supplier_sector.user_id')
                ->where('users.is_active', true)
                ->where(function ($w) {
                    $w->whereNull('users.pending_approval')->orWhere('users.pending_approval', false);
                })
                ->select('supplier_sector.sector_id', DB::raw('COUNT(DISTINCT supplier_sector.user_id) as c'))
                ->groupBy('supplier_sector.sector_id')
                ->pluck('c', 'supplier_sector.sector_id')
                ->toArray();

            // Roll up: a root sector's shown count = its own + all its specialties'.
            $parents = Sector::query()->select('id', 'parent_id')->get();
            $rolled = $direct;
            foreach ($parents as $s) {
                if ($s->parent_id) {
                    $rolled[$s->parent_id] = ($rolled[$s->parent_id] ?? 0) + ($direct[$s->id] ?? 0);
                }
            }

            return $rolled;
        });
    }

    /** @return array<string, int|array> */
    private function platformStats(): array
    {
        // Only the locale-agnostic counters are cached; `latest` carries localized sector names and
        // is rebuilt per request.
        $aggregates = Cache::remember('directory.stats', 300, fn () => [
            'suppliers' => $this->eligibleSuppliers(null)->count(),
            'products' => (int) DB::table('supplier_sector')
                ->join('products', 'products.user_id', '=', 'supplier_sector.user_id')
                ->distinct('products.id')->count('products.id'),
            'sectors' => Sector::query()->roots()->where('is_active', true)->count(),
        ]);

        $latest = $this->eligibleSuppliers(null)
            ->with(['profile', 'primarySector'])
            ->orderByDesc('users.created_at')
            ->limit(6)->get()
            ->map(fn (User $u) => $this->supplierCard($u))->values()->all();

        return [
            'suppliers' => $aggregates['suppliers'],
            'products' => $aggregates['products'],
            'sectors' => $aggregates['sectors'],
            'latest' => $latest,
        ];
    }
}

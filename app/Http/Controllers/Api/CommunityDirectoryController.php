<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesLocale;
use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Community lookups for signed-in members: the sector list behind the feed filter and the
 * people search behind @mentions. Only members with a public profile are searchable.
 */
class CommunityDirectoryController extends Controller
{
    use ResolvesLocale;

    /** GET /community/sectors — top-level sectors for the feed filter. */
    public function sectors(Request $request): JsonResponse
    {
        $this->applyLocale($request);

        $sectors = Sector::query()->active()->roots()->orderBy('position')->get()
            ->map(fn (Sector $s) => ['slug' => $s->slug, 'name' => $s->nameLocalized(), 'icon' => $s->icon])
            ->values()->all();

        return response()->json(['sectors' => $sectors]);
    }

    /** GET /community/members?q= — public members matching a name, company or username. */
    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer'],
        ]);
        $perPage = min(20, max(1, (int) ($data['per_page'] ?? 6)));

        $query = User::query()
            ->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('pending_approval')->orWhere('pending_approval', false))
            ->whereKeyNot($request->user()->id)
            ->whereHas('profile', fn (Builder $p) => $p->where('is_public', true)->whereNotNull('username'))
            ->with('profile');

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('users.name', 'like', $like)
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('profiles')
                        ->whereColumn('profiles.user_id', 'users.id')
                        ->where(fn ($p) => $p->where('company', 'like', $like)->orWhere('username', 'like', $like)));
            });
        }

        $rows = $query->orderByDesc('is_verified')->orderBy('users.name')->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(function (User $u) {
                $profile = $u->profile;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'company' => $profile->company,
                    'city' => $profile->city,
                    'is_verified' => (bool) ($u->is_verified ?? false),
                    'photo' => $profile->photo
                        ? asset('storage/uploads/users/original/'.$profile->photo)
                        : (! empty($u->avatar) ? $u->avatar : null),
                    'username' => $profile->username,
                ];
            })->values()->all(),
        ]);
    }
}

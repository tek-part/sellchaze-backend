<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityGroup;
use App\Support\Feed\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommunityGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewerId = $request->user()->id;
        $q = CommunityGroup::query()->with('sector:id,slug,name,name_en,name_ar')->withExists(['members as joined' => fn ($m) => $m->where('users.id', $viewerId)->where('community_group_memberships.status', 'active')]);
        if ($request->boolean('joined')) $q->whereHas('members', fn ($m) => $m->where('users.id', $viewerId)->where('community_group_memberships.status', 'active'));
        if ($request->filled('q')) $q->where(fn ($x) => $x->where('name', 'like', '%'.$request->string('q').'%')->orWhere('description', 'like', '%'.$request->string('q').'%'));
        $rows = $q->orderByDesc('members_count')->orderByDesc('id')->cursorPaginate(min(30, max(5, (int) $request->integer('per_page', 12))));
        return response()->json(['data' => collect($rows->items())->map(fn ($g) => $this->card($g))->all(), 'meta' => ['next_cursor' => $rows->nextCursor()?->encode()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'], 'sector_id' => ['nullable', 'integer', 'exists:sectors,id'], 'privacy' => ['nullable', Rule::in(['public', 'private'])], 'organization_id' => ['nullable', 'integer', 'exists:organizations,id'], 'rules' => ['nullable', 'array', 'max:20']]);
        if (! empty($data['organization_id'])) abort_unless($request->user()->organizationMemberships()->where('organization_id', $data['organization_id'])->where('status', 'active')->exists(), 403);
        $group = DB::transaction(function () use ($request, $data) {
            $base = Str::slug($data['name']) ?: 'group';
            $slug = $base; $i = 2;
            while (CommunityGroup::query()->where('slug', $slug)->exists()) $slug = $base.'-'.$i++;
            $group = CommunityGroup::create([...$data, 'slug' => $slug, 'owner_user_id' => $request->user()->id, 'privacy' => $data['privacy'] ?? 'public']);
            $group->members()->attach($request->user()->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            return $group;
        });
        return response()->json(['data' => $this->card($group->load('sector'))], 201);
    }

    public function show(Request $request, CommunityGroup $group): JsonResponse
    {
        $joined = $group->members()->where('users.id', $request->user()->id)->wherePivot('status', 'active')->exists();
        abort_if($group->privacy === 'private' && ! $joined, 403);
        $posts = $group->posts()->published()->withFeedRelations()->orderByDesc('published_at')->cursorPaginate(12);
        return response()->json(['data' => [...$this->card($group->load('sector')), 'joined' => $joined, 'posts' => collect($posts->items())->map(fn ($p) => PostPresenter::card($p, $request->user()->id))->all(), 'next_cursor' => $posts->nextCursor()?->encode()]]);
    }

    public function join(Request $request, CommunityGroup $group): JsonResponse
    {
        $status = $group->privacy === 'public' ? 'active' : 'pending';
        DB::transaction(function () use ($request, $group, $status) {
            $exists = $group->members()->where('users.id', $request->user()->id)->exists();
            $group->members()->syncWithoutDetaching([$request->user()->id => ['role' => 'member', 'status' => $status, 'joined_at' => $status === 'active' ? now() : null]]);
            if (! $exists && $status === 'active') $group->increment('members_count');
        });
        return response()->json(['joined' => $status === 'active', 'status' => $status]);
    }

    public function leave(Request $request, CommunityGroup $group): JsonResponse
    {
        abort_if((int) $group->owner_user_id === (int) $request->user()->id, 422, 'The group owner cannot leave.');
        DB::transaction(function () use ($request, $group) {
            $active = $group->members()->where('users.id', $request->user()->id)->wherePivot('status', 'active')->exists();
            $group->members()->detach($request->user()->id);
            if ($active && $group->members_count > 1) $group->decrement('members_count');
        });
        return response()->json(['joined' => false]);
    }

    private function card(CommunityGroup $group): array
    {
        return ['id' => $group->id, 'name' => $group->name, 'slug' => $group->slug, 'description' => $group->description, 'avatar_url' => $group->avatar_url, 'cover_url' => $group->cover_url, 'privacy' => $group->privacy, 'members_count' => (int) $group->members_count, 'posts_count' => (int) $group->posts_count, 'is_verified' => (bool) $group->is_verified, 'joined' => (bool) ($group->joined ?? false), 'sector' => $group->sector ? ['slug' => $group->sector->slug, 'name' => $group->sector->name] : null];
    }
}

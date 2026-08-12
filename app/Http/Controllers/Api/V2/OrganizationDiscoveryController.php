<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationDiscoveryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:business,factory,distributor,merchant,supplier'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $mine = $request->user()->organizationMemberships()->where('status', 'active')->pluck('organization_id');
        $query = Organization::query()
            ->where('status', 'active')
            ->whereNotIn('id', $mine)
            ->with(['stores' => fn ($stores) => $stores->where('status', 'active')->select('id', 'organization_id', 'name', 'slug')])
            ->withCount(['memberships as team_size' => fn ($members) => $members->where('status', 'active'), 'stores']);
        if (! empty($data['q'])) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%';
            $query->where(fn ($row) => $row->where('name', 'like', $needle)->orWhere('legal_name', 'like', $needle));
        }
        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }

        return response()->json($query->orderByDesc('stores_count')->orderBy('name')->paginate($data['per_page'] ?? 24));
    }

    public function show(Request $request, Organization $organization)
    {
        abort_unless($organization->status === 'active', 404);
        $following = \App\Models\OrganizationFollow::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        return response()->json(['data' => [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'legal_name' => $organization->legal_name,
            'type' => $organization->type,
            'country_code' => $organization->country_code,
            'headline' => $organization->headline,
            'about' => $organization->about,
            'website' => $organization->website,
            'logo_url' => $organization->logo_url,
            'cover_url' => $organization->cover_url,
            'locations' => $organization->locations ?? [],
            'capabilities' => $organization->capabilities ?? [],
            'featured_products' => $organization->featured_products ?? [],
            'certificates' => $organization->certificates ?? [],
            'is_verified' => (bool) $organization->is_verified,
            'following' => $following,
            'followers_count' => \App\Models\OrganizationFollow::query()->where('organization_id', $organization->id)->count(),
        ]]);
    }
}

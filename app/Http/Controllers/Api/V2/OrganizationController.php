<?php

namespace App\Http\Controllers\Api\V2;

use App\Actions\Organizations\CreateOrganizationAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = $request->user()->organizations()
            ->wherePivot('status', 'active')
            ->with('memberships')
            ->withCount(['stores', 'memberships'])
            ->orderBy('organizations.name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return OrganizationResource::collection($organizations);
    }

    public function store(Request $request, CreateOrganizationAction $create)
    {
        $this->authorize('create', Organization::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash:ascii', 'unique:organizations,slug'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(['business', 'factory', 'distributor', 'merchant', 'supplier'])],
            'country_code' => ['nullable', 'string', 'size:2'],
            'default_locale' => ['nullable', Rule::in(['ar', 'en'])],
            'default_currency' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $data['slug'] ??= Str::slug($data['name']).'-'.Str::lower(Str::random(6));

        return (new OrganizationResource($create->execute($request->user(), $data)))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return new OrganizationResource($organization->load('memberships')->loadCount(['stores', 'memberships']));
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('update', $organization);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'slug' => ['sometimes', 'required', 'string', 'max:160', 'alpha_dash:ascii', Rule::unique('organizations')->ignore($organization)],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'type' => ['sometimes', Rule::in(['business', 'factory', 'distributor', 'merchant', 'supplier'])],
            'country_code' => ['nullable', 'string', 'size:2'],
            'default_locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'default_currency' => ['sometimes', 'string', 'max:8'],
            'timezone' => ['sometimes', 'timezone'],
            'headline' => ['nullable', 'string', 'max:240'],
            'about' => ['nullable', 'string', 'max:10000'],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'cover_url' => ['nullable', 'url:http,https', 'max:2048'],
            'locations' => ['nullable', 'array', 'max:20'],
            'locations.*.label' => ['required_with:locations', 'string', 'max:160'],
            'locations.*.country_code' => ['nullable', 'string', 'size:2'],
            'capabilities' => ['nullable', 'array', 'max:50'],
            'capabilities.*' => ['string', 'max:160'],
            'featured_products' => ['nullable', 'array', 'max:20'],
            'certificates' => ['nullable', 'array', 'max:20'],
        ]);
        $organization->update($data);

        return new OrganizationResource($organization->load('memberships')->loadCount(['stores', 'memberships']));
    }
}

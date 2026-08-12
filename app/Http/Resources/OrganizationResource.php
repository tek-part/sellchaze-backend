<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $request->user()
            ? $this->memberships->firstWhere('user_id', $request->user()->id)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'type' => $this->type,
            'status' => $this->status,
            'country_code' => $this->country_code,
            'default_locale' => $this->default_locale,
            'default_currency' => $this->default_currency,
            'timezone' => $this->timezone,
            'profile' => [
                'headline' => $this->headline,
                'about' => $this->about,
                'website' => $this->website,
                'logo_url' => $this->logo_url,
                'cover_url' => $this->cover_url,
                'locations' => $this->locations ?? [],
                'capabilities' => $this->capabilities ?? [],
                'featured_products' => $this->featured_products ?? [],
                'certificates' => $this->certificates ?? [],
                'is_verified' => (bool) $this->is_verified,
                'verified_at' => $this->verified_at,
            ],
            'membership' => $membership ? [
                'role' => $membership->role,
                'status' => $membership->status,
                'permissions' => $membership->permissions,
                'store_ids' => $membership->store_ids,
            ] : null,
            'stores_count' => $this->whenCounted('stores'),
            'members_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

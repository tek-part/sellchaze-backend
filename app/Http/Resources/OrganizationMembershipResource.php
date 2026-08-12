<?php

namespace App\Http\Resources;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationMembership */
class OrganizationMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'role' => $this->role,
            'status' => $this->status,
            'permissions' => $this->permissions,
            'store_ids' => $this->store_ids,
            'joined_at' => $this->joined_at,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'avatar' => $this->user->avatar,
            ],
        ];
    }
}

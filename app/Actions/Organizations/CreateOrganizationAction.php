<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\DB;

class CreateOrganizationAction
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $owner, array $attributes): Organization
    {
        return DB::transaction(function () use ($owner, $attributes): Organization {
            $organization = Organization::query()->create($attributes);
            $organization->memberships()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $this->outbox->record('OrganizationCreated', 'organization', $organization->id, [
                'organization_id' => $organization->id,
                'owner_user_id' => $owner->id,
                'name' => $organization->name,
            ]);

            return $organization->load('memberships.user');
        });
    }
}

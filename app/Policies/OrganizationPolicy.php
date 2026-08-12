<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->is_active !== false;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->membershipRole($user, $organization) !== null || $user->hasRole('Admin');
    }

    public function update(User $user, Organization $organization): bool
    {
        return in_array($this->membershipRole($user, $organization), ['owner', 'admin'], true)
            || $user->hasRole('Admin');
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->update($user, $organization);
    }

    public function manageConnections(User $user, Organization $organization): bool
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        $membership = $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        return $membership !== null && (
            in_array($membership->role, ['owner', 'admin'], true)
            || in_array('manage_connections', $membership->permissions ?? [], true)
        );
    }

    private function membershipRole(User $user, Organization $organization): ?string
    {
        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }
}

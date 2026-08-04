<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Stores\StoreProvisioner;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns a registration intent into a usable account: assigns the real role,
 * clears the pending flag and provisions the owner's store.
 *
 * Shared by the two routes into an approved account so they can never drift:
 *   - self-service sign-up (Merchant/Supplier are approved immediately)
 *   - an administrator approving a pending registration
 */
class RegistrationApprover
{
    /** Roles a visitor may self-select when signing up. */
    public const SELF_SERVICE_ROLES = ['Merchant', 'Supplier'];

    public function __construct(private readonly StoreProvisioner $provisioner) {}

    /**
     * Business owners onboard themselves; anything with internal access (Staff)
     * still waits for an administrator.
     */
    public function isSelfServiceRole(?string $role): bool
    {
        return in_array($this->normalizeRole($role), self::SELF_SERVICE_ROLES, true);
    }

    /** Canonical role name, or null when the value is not one we accept. */
    public function normalizeRole(?string $role): ?string
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        return match (strtolower(trim($role))) {
            'staff' => 'Staff',
            'supplier' => 'Supplier',
            'merchant' => 'Merchant',
            default => null,
        };
    }

    /**
     * Grant the role and finish setup. Idempotent enough to be safe on retry:
     * syncRoles and store provisioning both settle to the same end state.
     *
     * @param  string  $context  what triggered this, for the activity log
     */
    public function approve(User $user, string $role, string $context = 'staff_user.registration_approved'): void
    {
        $guard = config('auth.defaults.guard', 'web');
        Role::findOrCreate($role, $guard);

        $user->syncRoles([$role]);
        $user->pending_approval = false;
        $user->registration_role = null;
        $user->save();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // One Merchant/Supplier = one Store — provision it now so the onboarding
        // checklist has something to measure progress against.
        $this->provisioner->ensureFor($user->fresh());

        ActivityLogger::log($context, $user, ['role' => $role]);
    }
}

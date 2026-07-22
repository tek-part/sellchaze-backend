<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Services\Rbac\UserScope;

/**
 * Ownership rules for stores. A non-admin may only touch stores whose
 * owner_user_id matches their effective (tenant-root) account.
 */
class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // listing is scoped to the owner in the controller
    }

    public function view(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    public function create(User $user): bool
    {
        if (UserScope::isAdmin($user)) {
            return true;
        }

        // One Merchant/Supplier = one Store. A non-admin owner may create their
        // store only while they own none; a second store is never permitted.
        if ($user->hasAnyRole(['Merchant', 'Supplier', 'Manager'])) {
            return ! Store::query()
                ->where('owner_user_id', UserScope::effectiveMerchantUserId($user))
                ->exists();
        }

        return false;
    }

    public function update(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    /**
     * Connecting a custom domain is materially more sensitive than editing a
     * store's name — it changes the public identity customers reach and the
     * host the platform will serve — so it gets its own ability rather than
     * riding on `update`.
     */
    public function manageDomains(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    private function owns(User $user, Store $store): bool
    {
        if (UserScope::isAdmin($user)) {
            return true;
        }

        return (int) $store->owner_user_id === UserScope::effectiveMerchantUserId($user);
    }
}

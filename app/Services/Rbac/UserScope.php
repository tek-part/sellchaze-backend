<?php

namespace App\Services\Rbac;

use App\Models\User;
use App\Services\Stores\StoreManagers;

/**
 * Central place for admin vs merchant scoping (replaces hardcoded user id checks).
 */
class UserScope
{
    public static function isAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('Admin');
    }

    /** Platform staff who may list every merchant account (admin Merchants page). */
    public static function seesAllMerchants(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return self::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager']);
    }

    /**
     * The account that owns the merchant data a user works on: store managers and employees act for
     * their parent merchant, everyone else for themselves.
     */
    public static function effectiveMerchantUserId(User $user): int
    {
        if (self::isAdmin($user)) {
            return (int) $user->id;
        }

        if ($user->hasRole(StoreManagers::ROLE)) {
            return (int) (StoreManagers::parentOwner($user)?->id ?? 0);
        }

        if ($user->hasRole('Employee') && ! empty($user->parent_user_id)) {
            return (int) $user->parent_user_id;
        }

        return (int) $user->id;
    }
}

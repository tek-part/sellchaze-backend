<?php

namespace App\Services\Rbac;

use App\Models\User;

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

    public static function isSupplier(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('Supplier');
    }

    /**
     * Full supplier directory (all supplier accounts + pending supplier sign-ups), same as admin in SuppliersApiController.
     */
    public static function seesAdminSupplierDirectory(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return self::isAdmin($user) || $user->hasAnyRole(['Staff', 'Manager']);
    }

    /**
     * User id used for listing orders/products for non-admin (merchant context).
     */
    public static function effectiveMerchantUserId(User $user): int
    {
        if (self::isAdmin($user)) {
            return (int) $user->id;
        }

        return b2bListingsUserId();
    }
}

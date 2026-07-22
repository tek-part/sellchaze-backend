<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Models\User;
use App\Services\StoreService;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the "one Merchant = one Store / one Supplier = one
 * Store" rule. ensureFor() is idempotent and concurrency-safe: it returns the
 * owner's existing store or creates exactly one, inside a transaction with a
 * row lock on the owner, so two concurrent first-hits can never race a duplicate
 * into existence. It is the automatic, lifecycle-driven replacement for the old
 * manual "Create Store" workflow.
 */
class StoreProvisioner
{
    public function __construct(private readonly StoreService $stores) {}

    /**
     * Guarantee the given user's tenant-root has exactly one store, creating it
     * if missing. Returns null for accounts that never own a store (Admin,
     * Staff, Customer, or an Employee whose parent is not an owner).
     */
    public function ensureFor(User $user): ?Store
    {
        $owner = $this->resolveOwner($user);
        if (! $owner) {
            return null;
        }

        // Fast path: already provisioned (no transaction/lock needed).
        $existing = Store::query()->where('owner_user_id', $owner->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($owner) {
            // Serialise concurrent first-hits on the owner row, then re-check
            // inside the lock so exactly one store is ever created.
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $store = Store::query()->where('owner_user_id', $owner->id)->first();
            if ($store) {
                return $store;
            }

            return $this->stores->createForOwner($owner, [
                'name' => $this->defaultName($owner),
                'status' => 'draft',
            ]);
        });
    }

    /**
     * The tenant-root user that would own the store, or null if this user type
     * never owns one. Employees inherit their parent merchant/supplier's store
     * rather than owning their own.
     */
    private function resolveOwner(User $user): ?User
    {
        if ($user->hasRole('Employee') && $user->parent_user_id) {
            $parent = $user->parent()->first();

            return $parent && $parent->hasAnyRole(['Merchant', 'Supplier']) ? $parent : null;
        }

        return $user->hasAnyRole(['Merchant', 'Supplier']) ? $user : null;
    }

    private function defaultName(User $owner): string
    {
        $name = trim((string) $owner->name);

        return $name !== '' ? "{$name}'s Store" : 'My Store';
    }
}

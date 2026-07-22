<?php

namespace App\Support\Tenancy;

use App\Models\Store;

/**
 * Request-scoped holder for the "current store" tenant. Set by ResolveStoreFromHost
 * (public storefront) or ScopeToStore (owner management). Registered as a *scoped*
 * container binding so Octane resets it between requests — this is the Octane-safe
 * successor to the removed app()->instance('storefront.store') binding.
 *
 * The StoreScope global scope reads this to isolate every store-owned query.
 */
class CurrentStore
{
    private ?Store $store = null;

    public function set(?Store $store): void
    {
        $this->store = $store;
    }

    public function get(): ?Store
    {
        return $this->store;
    }

    public function id(): ?int
    {
        return $this->store?->id;
    }

    public function has(): bool
    {
        return $this->store !== null;
    }

    public function forget(): void
    {
        $this->store = null;
    }
}

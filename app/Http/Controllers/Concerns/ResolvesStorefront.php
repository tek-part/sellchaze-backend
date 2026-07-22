<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use App\Models\StoreCustomer;
use Illuminate\Http\Request;

/**
 * Shared storefront request context. Both values are placed on the request by
 * middleware — the Store by ResolveStoreFromHost (resolve.store) and the
 * StoreCustomer by AuthenticateStoreCustomer (store.customer) — so these
 * helpers only assert their presence; they never perform lookups themselves.
 *
 * Extracted from the eight storefront controllers that previously each carried
 * an identical private copy of these checks.
 */
trait ResolvesStorefront
{
    /** The host-resolved store for this request (404 if the host has none). */
    protected function currentStore(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404, 'No store for this host.');

        return $store;
    }

    /** The authenticated store customer (401 if the bearer token is missing/invalid). */
    protected function currentCustomer(Request $request): StoreCustomer
    {
        $customer = $request->attributes->get('store_customer');
        abort_unless($customer instanceof StoreCustomer, 401, 'Unauthenticated.');

        return $customer;
    }
}

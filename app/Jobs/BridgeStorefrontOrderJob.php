<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Bridges a placed storefront order into the B2B orders pipeline. Dispatched
 * afterCommit from CheckoutService::place so a bridge failure can never roll
 * back the customer's order. Carries scalar ids only (no SerializesModels) and
 * sets the CurrentStore tenant itself because StoreOrder is fail-closed without one.
 */
class BridgeStorefrontOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $storeOrderId, public int $storeId) {}

    public function handle(StorefrontOrderBridge $bridge, CurrentStore $current): void
    {
        $store = Store::query()->find($this->storeId);
        if ($store === null) {
            return;
        }

        $previous = $current->get();
        $current->set($store);
        try {
            $storeOrder = StoreOrder::query()->with('items')->find($this->storeOrderId);
            if ($storeOrder !== null) {
                $bridge->bridge($storeOrder, $store);
            }
        } finally {
            $current->set($previous);
        }
    }
}

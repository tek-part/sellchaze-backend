<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\OrderLimits;
use App\Services\Storefront\StorefrontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use libphonenumber\PhoneNumberUtil;

class StoreOrderLimitsController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, OrderLimits $limits): JsonResponse
    {
        return response()->json(['data' => $limits->configured($this->store($request)), 'phone_countries' => PhoneNumberUtil::getInstance()->getSupportedRegions()]);
    }

    public function update(Request $request, OrderLimits $limits): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['max_product_quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_orders_per_phone_24h' => ['required', 'integer', 'min:0', 'max:100000'],
            'phone_country' => ['required', 'string', Rule::in(PhoneNumberUtil::getInstance()->getSupportedRegions())]]);
        $config = ['max_product_quantity' => (int) $data['max_product_quantity'],
            'max_orders_per_phone_24h' => (int) $data['max_orders_per_phone_24h'], 'phone_country' => $data['phone_country']];
        DB::transaction(function () use ($store, $config) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail()->update(['order_limits' => $config]);
        });
        StorefrontService::forgetHomepage($store->id);
        $request->attributes->set('store', $store->fresh());

        return $this->index($request, $limits);
    }
}

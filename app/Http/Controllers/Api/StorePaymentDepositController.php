<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\OrderDeposits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** G1: the store's deposit (عربون) setting, edited with the payment methods. */
class StorePaymentDepositController extends Controller
{
    public function index(Request $request, OrderDeposits $deposits): JsonResponse
    {
        return response()->json(['data' => $deposits->status($this->store($request))]);
    }

    public function update(Request $request, OrderDeposits $deposits): JsonResponse
    {
        $store = $this->store($request);

        return response()->json(['data' => $deposits->save($store, $request->validate(OrderDeposits::rules()))]);
    }

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store') ?? $request->route('store');
        abort_unless($store instanceof Store, 404, 'Store not found.');

        return $store;
    }
}

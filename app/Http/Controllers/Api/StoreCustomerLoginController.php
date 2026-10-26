<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CustomerLoginMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** G2: the merchant's storefront customer sign-in methods (store.settings.manage). */
class StoreCustomerLoginController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);

        return $store;
    }

    public function index(Request $request, CustomerLoginMethods $methods): JsonResponse
    {
        return response()->json(['data' => $methods->settings($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, CustomerLoginMethods $methods): JsonResponse
    {
        $data = $request->validate(['phone_otp' => ['required', 'boolean'], 'google' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $methods->settings($methods->save($this->store($request), $data))], 200, ['Cache-Control' => 'private, no-store']);
    }
}

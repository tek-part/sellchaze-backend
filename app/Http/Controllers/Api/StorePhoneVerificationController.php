<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CheckoutPhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorePhoneVerificationController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, CheckoutPhoneVerification $otp): JsonResponse
    {
        return response()->json(['data' => $otp->settings($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, CheckoutPhoneVerification $otp): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'],
            'clear_credentials' => ['sometimes', 'boolean'], 'verify_connection' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'required', 'array:instance_id,access_token'],
            'credentials.instance_id' => ['required_with:credentials', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'credentials.access_token' => ['required_with:credentials', 'string', 'max:2048', 'regex:/^[!-~]+$/']]);
        abort_if(isset($data['credentials']) && ($data['clear_credentials'] ?? false), 422, 'Choose either replacement or removal of credentials.');

        return response()->json(['data' => $otp->settings($otp->save($store, $data))], 200, ['Cache-Control' => 'private, no-store']);
    }
}

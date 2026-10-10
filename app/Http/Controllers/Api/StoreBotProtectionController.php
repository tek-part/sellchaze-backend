<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CheckoutBotProtection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreBotProtectionController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        $user = $request->user();
        abort_unless($store instanceof Store, 404);
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, CheckoutBotProtection $protection): JsonResponse
    {
        return response()->json(['data' => $protection->settings($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, CheckoutBotProtection $protection): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'], 'clear_credentials' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'required', 'array:site_key,secret_key'],
            'credentials.site_key' => ['required_with:credentials', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'credentials.secret_key' => ['required_with:credentials', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/']]);
        abort_if(isset($data['credentials']) && ($data['clear_credentials'] ?? false), 422, 'Choose either replacement or removal.');

        return response()->json(['data' => $protection->settings($protection->save($this->store($request), $data))], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\GoogleMerchantSettings;
use App\Services\Commerce\ProductFeeds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleMerchantController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function show(Request $request, Store $store, GoogleMerchantSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->configuration($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Store $store, GoogleMerchantSettings $settings): JsonResponse
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'], 'cod_disabled' => ['required', 'boolean'],
            'email' => ['present', 'nullable', 'email:rfc', 'max:255'], 'phone' => ['present', 'nullable', 'string', 'max:60', 'regex:/^[+0-9 ()-]+$/D'],
            'address' => ['present', 'nullable', 'string', 'max:1000'], 'policies' => ['required', 'array:shipping,returns,cod']];
        foreach (GoogleMerchantSettings::POLICIES as $kind) {
            $rules['policies.'.$kind] = ['required', 'array:title,content'];
            $rules['policies.'.$kind.'.title'] = ['present', 'nullable', 'string', 'max:200'];
            $rules['policies.'.$kind.'.content'] = ['present', 'nullable', 'string', 'max:20000'];
        }

        return response()->json(['data' => $settings->save($this->store($request), $request->validate($rules))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function feedInformation(Request $request, Store $store, ProductFeeds $feeds): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(ProductFeeds::CHANNELS)]]);

        return response()->json(['data' => $feeds->information($this->store($request), $data['channel'])], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, Store $store, ProductFeeds $feeds): StreamedResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(ProductFeeds::CHANNELS)]]);
        $store = $this->store($request);

        return $feeds->download($store, $data['channel'], $store->default_locale ?: 'en');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\StoreTrackingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreTrackingController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function pixels(Request $request, Store $store, StoreTrackingSettings $settings): JsonResponse
    {
        return $this->response($settings->publicConfiguration($this->store($request)));
    }

    public function updatePixels(Request $request, Store $store, StoreTrackingSettings $settings): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'facebook' => ['present', 'array', 'max:10'], 'tiktok' => ['present', 'array', 'max:10'], 'snapchat' => ['present', 'array', 'max:10'], 'facebook.*' => ['string', 'max:64'], 'tiktok.*' => ['string', 'max:64'], 'snapchat.*' => ['string', 'max:64']]);

        return $this->response($settings->pixels($this->store($request), $data));
    }

    public function google(Request $request, Store $store, StoreTrackingSettings $settings): JsonResponse
    {
        return $this->pixels($request, $store, $settings);
    }

    public function updateGoogle(Request $request, Store $store, StoreTrackingSettings $settings): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'google' => ['present', 'array', 'max:10'], 'google.*' => ['string', 'max:64']]);

        return $this->response($settings->google($this->store($request), $data));
    }

    public function conversions(Request $request, Store $store, StoreTrackingSettings $settings): JsonResponse
    {
        return $this->response($settings->conversions($this->store($request)));
    }

    public function saveConversion(Request $request, Store $store, StoreTrackingSettings $settings, ?string $destination = null): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'provider' => ['required', Rule::in(StoreTrackingSettings::PROVIDERS)], 'pixel_id' => ['required', 'string', 'max:64'], 'token' => [$destination ? 'nullable' : 'required', 'string', 'max:4096', 'regex:/^[A-Za-z0-9._~+\/=-]+$/D'], 'note' => ['nullable', 'string', 'max:240'], 'enabled' => ['required', 'boolean']]);

        return $this->response($settings->saveConversion($this->store($request), $data, $destination));
    }

    public function removeConversion(Request $request, Store $store, StoreTrackingSettings $settings, string $destination): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->response($settings->removeConversion($this->store($request), $destination, (int) $data['version']));
    }
}

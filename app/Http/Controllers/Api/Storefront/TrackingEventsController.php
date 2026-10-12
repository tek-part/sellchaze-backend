<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\QuantityOffers;
use App\Services\Commerce\StoreTrackingEvents;
use App\Services\Commerce\StoreTrackingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackingEventsController extends Controller
{
    use ResolvesStorefront;

    public function configuration(Request $request, StoreTrackingSettings $settings): JsonResponse
    {
        $store = $this->currentStore($request);

        return response()->json(['data' => $settings->publicConfiguration($store) + ['enabled' => $settings->enabled($store)]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, StoreTrackingEvents $events): JsonResponse
    {
        $data = $request->validate(['advertising_allowed' => ['required', 'boolean'], 'event_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(['page_view', 'view_item', 'add_to_cart', 'begin_checkout'])],
            'page_path' => ['sometimes', 'string', 'max:512', 'regex:~^/(?!/)[A-Za-z0-9_/%.-]*$~D'],
            'items' => ['required_unless:event,page_view', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999']] + QuantityOffers::inputRules('items.*.'));
        if ($data['event'] === 'page_view') {
            unset($data['items']);
        }

        return response()->json(['data' => $events->capture($this->currentStore($request), $request, $data)], 202, ['Cache-Control' => 'private, no-store']);
    }
}

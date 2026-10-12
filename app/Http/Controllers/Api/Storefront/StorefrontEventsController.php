<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\StorefrontEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontEventsController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request, StorefrontEvents $events): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:visit,add_to_cart,checkout_started'],
            'source' => ['sometimes', 'in:storefront,funnel,cross_sell'],
            'product_id' => ['required_unless:kind,visit', 'prohibited_if:kind,visit', 'integer', 'min:1'],
            'quantity' => ['required_if:kind,add_to_cart', 'prohibited_unless:kind,add_to_cart', 'integer', 'between:1,999'],
        ]);
        $events->capture($this->currentStore($request), $data);

        return response()->json(['message' => 'Recorded.'], 202, ['Cache-Control' => 'private, no-store']);
    }
}

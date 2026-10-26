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

    /** Crawlers, link previewers, headless browsers and HTTP libraries never count as product views. */
    private const BOT = '/bot\b|bot\/|crawl|spider|slurp|preview|facebookexternalhit|whatsapp\/|headless|lighthouse|pingdom|curl\/|wget\/|python-requests|python-urllib|go-http-client|okhttp|java\//i';

    public function store(Request $request, StorefrontEvents $events): JsonResponse
    {
        $label = ['nullable', 'string', 'regex:/\S/u', 'not_regex:/[\p{C}<>]/u'];
        $data = $request->validate([
            'event_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:visit,add_to_cart,checkout_started,product_view'],
            'source' => ['sometimes', 'in:storefront,funnel,cross_sell'],
            'product_id' => ['required_unless:kind,visit', 'prohibited_if:kind,visit', 'integer', 'min:1'],
            'quantity' => ['required_if:kind,add_to_cart', 'prohibited_unless:kind,add_to_cart', 'integer', 'between:1,999'],
            // G4: landing campaign labels only (never URLs or click ids), attached to the event and its session.
            'utm_source' => [...$label, 'max:100'], 'utm_medium' => [...$label, 'max:100'], 'utm_campaign' => [...$label, 'max:200'],
        ]);
        $agent = (string) $request->userAgent();
        $events->capture($this->currentStore($request), $data, $agent === '' || preg_match(self::BOT, $agent) === 1);

        return response()->json(['message' => 'Recorded.'], 202, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\StorefrontEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class StorefrontEventsController extends Controller
{
    use ResolvesStorefront;

    /** Crawlers, link previewers, headless browsers and HTTP libraries never count as product views. */
    private const BOT = '/bot\b|bot\/|crawl|spider|slurp|preview|facebookexternalhit|whatsapp\/|headless|lighthouse|pingdom|curl\/|wget\/|python-requests|python-urllib|go-http-client|okhttp|java\//i';

    /** Per IP and store, on top of the route throttle: [per minute, per day]. Visits keep the route limit only. */
    public const BUDGETS = ['product_view' => [30, 500], 'add_to_cart' => [30, 300], 'checkout_started' => [20, 100]];

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
            'utm_source' => [...$label, 'max:100'], 'utm_medium' => [...$label, 'max:100'], 'utm_campaign' => [...$label, 'max:200'], // stored normalised, 64 chars max
        ]);
        $store = $this->currentStore($request);
        // Browser privacy signals are honoured server side too: nothing is recorded.
        if ($request->header('Sec-GPC') === '1' || $request->header('DNT') === '1') {
            return $this->accepted();
        }
        $agent = (string) $request->userAgent();
        $bot = $agent === '' || preg_match(self::BOT, $agent) === 1;
        // Bots never count as product views; funnel events past their per-IP budget are dropped quietly.
        if (($data['kind'] === 'product_view' && $bot) || ! $this->withinBudget($data['kind'], $store->id, (string) $request->ip())) {
            return $this->accepted();
        }
        $events->capture($store, $data, ! $bot);

        return $this->accepted();
    }

    private function withinBudget(string $kind, int $store, string $ip): bool
    {
        if (! isset(self::BUDGETS[$kind])) {
            return true;
        }
        [$minute, $day] = self::BUDGETS[$kind];
        $key = 'sf-events:'.$kind.':'.$store.':'.hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($key.':minute', $minute) || RateLimiter::tooManyAttempts($key.':day', $day)) {
            return false;
        }
        RateLimiter::hit($key.':minute', 60);
        RateLimiter::hit($key.':day', 86400);

        return true;
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['message' => 'Recorded.'], 202, ['Cache-Control' => 'private, no-store']);
    }
}

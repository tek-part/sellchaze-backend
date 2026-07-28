<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Models\Scopes\StoreScope;
use App\Models\StoreContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public storefront engagement capture: newsletter opt-in and contact-form messages.
 * Guest-friendly (no auth); both are tenant-scoped to the host-resolved store.
 */
class StorefrontEngagementController extends Controller
{
    use ResolvesStorefront;

    /** POST /storefront/newsletter */
    public function subscribe(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        NewsletterSubscriber::withoutGlobalScope(StoreScope::class)->updateOrCreate(
            ['store_id' => $store->id, 'email' => mb_strtolower($validated['email'])],
            ['is_active' => true],
        );

        return response()->json(['message' => 'Subscribed.'], 201);
    }

    /** POST /storefront/contact */
    public function contact(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        StoreContactMessage::withoutGlobalScope(StoreScope::class)->create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
        ]);

        return response()->json(['message' => 'Message received.'], 201);
    }
}

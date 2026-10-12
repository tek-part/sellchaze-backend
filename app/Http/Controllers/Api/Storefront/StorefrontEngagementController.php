<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Models\PhoneNewsletterSubscriber;
use App\Models\Scopes\StoreScope;
use App\Models\Store;
use App\Models\StoreContactMessage;
use App\Services\Commerce\OrderLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'email' => ['required_without:phone', 'prohibits:phone,name', 'email', 'max:255'],
            'name' => ['required_with:phone', 'string', 'max:255'],
            'phone' => ['required_without:email', 'string', 'max:40'],
        ]);

        DB::transaction(function () use ($store, $validated): void {
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            if (isset($validated['email'])) {
                NewsletterSubscriber::withoutGlobalScope(StoreScope::class)->updateOrCreate(
                    ['store_id' => $locked->id, 'email' => mb_strtolower($validated['email'])],
                    ['is_active' => true],
                );

                return;
            }
            $limits = app(OrderLimits::class);
            $phone = $limits->normalizePhone($validated['phone'], $limits->configured($locked)['phone_country']);
            if ($phone === null) {
                throw ValidationException::withMessages(['phone' => app()->getLocale() === 'ar' ? 'أدخل رقم هاتف صالحًا مع كود الدولة للأرقام الأجنبية.' : 'Enter a valid phone number, including the country code for a foreign number.']);
            }
            $subscriber = PhoneNewsletterSubscriber::withoutGlobalScope(StoreScope::class)->firstOrCreate(
                ['store_id' => $locked->id, 'phone_normalized' => $phone],
                ['name' => $validated['name'], 'phone' => $validated['phone'], 'is_active' => true],
            );
            // An anonymous repeated opt-in must not overwrite an existing subscriber's identity.
            if (! $subscriber->is_active) {
                $subscriber->update(['is_active' => true]);
            }
        });

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

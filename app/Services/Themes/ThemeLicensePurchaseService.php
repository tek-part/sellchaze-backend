<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreThemeLicense;
use App\Models\Theme;
use App\Models\ThemeLicensePurchase;
use App\Models\ThemeLicensePurchaseEvent;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ThemeLicensePurchaseService
{
    public function __construct(private readonly ThemeLicenseService $licenses) {}

    public function createCheckout(Store $store, Theme $theme, User $user): array
    {
        if ($this->licenses->isLicensed($store, $theme)) {
            return ['licensed' => true, 'checkout_url' => null, 'purchase' => null];
        }

        if ((float) $theme->price <= 0) {
            $this->licenses->assertCanInstall($store, $theme, $user->id);

            return ['licensed' => true, 'checkout_url' => null, 'purchase' => null];
        }

        $secret = trim((string) config('services.theme_marketplace.stripe_secret'));
        if ($secret === '') {
            throw ValidationException::withMessages([
                'payment' => ['Theme marketplace billing is not configured.'],
            ]);
        }

        $purchase = ThemeLicensePurchase::create([
            'store_id' => $store->id,
            'theme_id' => $theme->id,
            'purchaser_user_id' => $user->id,
            'provider' => 'stripe',
            'status' => ThemeLicensePurchase::PENDING,
            'amount' => $theme->price,
            'currency' => strtoupper((string) $theme->currency),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $successUrl = (string) config('services.theme_marketplace.success_url');
        $cancelUrl = (string) config('services.theme_marketplace.cancel_url');

        $response = Http::withToken($secret)
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->withHeaders(['Idempotency-Key' => $purchase->idempotency_key])
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => (string) $purchase->id,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($purchase->currency),
                        'unit_amount' => $this->minorAmount($purchase->amount, $purchase->currency),
                        'product_data' => [
                            'name' => (string) $theme->name,
                            'description' => 'Lifetime theme license for '.$store->name,
                        ],
                    ],
                ]],
                'metadata' => [
                    'purchase_id' => (string) $purchase->id,
                    'store_id' => (string) $store->id,
                    'theme_id' => (string) $theme->id,
                    'user_id' => (string) $user->id,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'purchase_id' => (string) $purchase->id,
                        'store_id' => (string) $store->id,
                        'theme_id' => (string) $theme->id,
                    ],
                ],
            ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            $this->markCheckoutFailure($purchase, $response);
            throw ValidationException::withMessages([
                'payment' => [(string) ($response->json('error.message') ?: 'Stripe could not create the theme checkout session.')],
            ]);
        }

        $purchase->forceFill([
            'status' => ThemeLicensePurchase::CHECKOUT_CREATED,
            'checkout_session_id' => (string) $response->json('id'),
            'expires_at' => $response->json('expires_at')
                ? now()->setTimestamp((int) $response->json('expires_at'))
                : $purchase->expires_at,
            'metadata' => ['payment_status' => $response->json('payment_status')],
        ])->save();

        return [
            'licensed' => false,
            'checkout_url' => (string) $response->json('url'),
            'purchase' => $purchase->fresh(),
        ];
    }

    public function handleStripeWebhook(string $payload, string $signature): void
    {
        $secret = trim((string) config('services.theme_marketplace.stripe_webhook_secret'));
        if ($secret === '') {
            throw new RuntimeException('Theme marketplace Stripe webhook is not configured.');
        }

        $this->assertStripeSignature($payload, $signature, $secret);
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $object = (array) data_get($event, 'data.object', []);
        $purchaseId = (int) data_get($object, 'metadata.purchase_id', 0);

        if ($eventId === '' || $type === '') {
            throw new RuntimeException('Stripe event is missing its id or type.');
        }

        if ($purchaseId <= 0 && is_string($object['payment_intent'] ?? null)) {
            $purchaseId = (int) ThemeLicensePurchase::query()
                ->where('payment_intent_id', $object['payment_intent'])
                ->value('id');
        }

        // A dedicated endpoint may still receive unrelated Stripe events when
        // dashboard event filters are broad. Acknowledge, but never mutate.
        if ($purchaseId <= 0) {
            return;
        }

        DB::transaction(function () use ($eventId, $type, $object, $purchaseId): void {
            if (ThemeLicensePurchaseEvent::where('provider_event_id', $eventId)->exists()) {
                return;
            }

            $purchase = ThemeLicensePurchase::query()->lockForUpdate()->findOrFail($purchaseId);
            $eventRecord = ThemeLicensePurchaseEvent::create([
                'theme_license_purchase_id' => $purchase->id,
                'provider' => 'stripe',
                'provider_event_id' => $eventId,
                'event_type' => $type,
                'status' => 'processing',
            ]);

            try {
                if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                    $this->completePurchase($purchase, $object);
                } elseif ($type === 'checkout.session.expired') {
                    $purchase->forceFill(['status' => ThemeLicensePurchase::EXPIRED])->save();
                } elseif ($type === 'checkout.session.async_payment_failed') {
                    $purchase->forceFill([
                        'status' => ThemeLicensePurchase::FAILED,
                        'failed_at' => now(),
                        'failure_reason' => 'Stripe reported an asynchronous payment failure.',
                    ])->save();
                } elseif ($type === 'charge.refunded') {
                    $this->applyRefund($purchase, $object);
                } elseif ($type === 'charge.dispute.created') {
                    $purchase->forceFill(['status' => ThemeLicensePurchase::DISPUTED])->save();
                    $this->setLicenseStatus($purchase, 'suspended');
                } elseif ($type === 'charge.dispute.closed') {
                    $this->closeDispute($purchase, $object);
                }

                $eventRecord->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
            } catch (\Throwable $e) {
                $eventRecord->forceFill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)])->save();
                throw $e;
            }
        });
    }

    private function completePurchase(ThemeLicensePurchase $purchase, array $session): void
    {
        if ($purchase->status === ThemeLicensePurchase::PAID) {
            return;
        }

        if (($session['payment_status'] ?? null) !== 'paid') {
            throw new RuntimeException('Stripe checkout is not paid.');
        }

        $sessionId = (string) ($session['id'] ?? '');
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        $amount = (int) ($session['amount_total'] ?? -1);
        if ($sessionId === '' || $currency !== strtoupper($purchase->currency)
            || $amount !== $this->minorAmount($purchase->amount, $purchase->currency)) {
            throw new RuntimeException('Stripe checkout amount or currency does not match the theme purchase.');
        }

        if ($purchase->checkout_session_id && ! hash_equals($purchase->checkout_session_id, $sessionId)) {
            throw new RuntimeException('Stripe checkout session does not match the theme purchase.');
        }

        StoreThemeLicense::updateOrCreate(
            ['store_id' => $purchase->store_id, 'theme_id' => $purchase->theme_id],
            [
                'acquired_by_user_id' => $purchase->purchaser_user_id,
                'status' => 'active',
                'source' => 'stripe',
                'price_paid' => $purchase->amount,
                'currency' => $purchase->currency,
                'order_reference' => $sessionId,
                'starts_at' => now(),
                'expires_at' => null,
            ]
        );

        $purchase->forceFill([
            'status' => ThemeLicensePurchase::PAID,
            'checkout_session_id' => $sessionId,
            'payment_intent_id' => $session['payment_intent'] ?? null,
            'paid_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    private function applyRefund(ThemeLicensePurchase $purchase, array $charge): void
    {
        $fullyRefunded = (bool) ($charge['refunded'] ?? false)
            || (int) ($charge['amount_refunded'] ?? 0) >= $this->minorAmount($purchase->amount, $purchase->currency);

        if (! $fullyRefunded) {
            return;
        }

        $purchase->forceFill(['status' => ThemeLicensePurchase::REFUNDED])->save();
        $this->setLicenseStatus($purchase, 'revoked');
    }

    private function closeDispute(ThemeLicensePurchase $purchase, array $dispute): void
    {
        $status = (string) ($dispute['status'] ?? '');
        if ($status === 'won') {
            $purchase->forceFill(['status' => ThemeLicensePurchase::PAID])->save();
            $this->setLicenseStatus($purchase, 'active');

            return;
        }

        if ($status === 'lost') {
            $purchase->forceFill(['status' => ThemeLicensePurchase::REVOKED])->save();
            $this->setLicenseStatus($purchase, 'revoked');
        }
    }

    private function setLicenseStatus(ThemeLicensePurchase $purchase, string $status): void
    {
        StoreThemeLicense::query()
            ->where('store_id', $purchase->store_id)
            ->where('theme_id', $purchase->theme_id)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private function assertStripeSignature(string $payload, string $header, string $secret): void
    {
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp <= 0 || abs(time() - $timestamp) > 300 || $signatures === []) {
            throw new RuntimeException('Invalid or expired Stripe signature.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new RuntimeException('Invalid Stripe signature.');
    }

    private function minorAmount(string|float|int $amount, string $currency): int
    {
        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $factor = in_array(strtoupper($currency), $zeroDecimal, true) ? 1 : 100;

        return (int) round((float) $amount * $factor);
    }

    private function markCheckoutFailure(ThemeLicensePurchase $purchase, Response $response): void
    {
        $purchase->forceFill([
            'status' => ThemeLicensePurchase::FAILED,
            'failed_at' => now(),
            'failure_reason' => Str::limit((string) ($response->json('error.message') ?: 'Stripe checkout creation failed.'), 1000),
        ])->save();
    }
}

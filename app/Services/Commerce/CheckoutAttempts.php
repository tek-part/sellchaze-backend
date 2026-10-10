<?php

namespace App\Services\Commerce;

use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\StoreCheckoutAttempt;
use App\Models\StoreOrder;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutAttempts
{
    public const ATTRIBUTE = 'checkout_attempt';

    public const HEADER = 'Idempotency-Key';

    private function key(Request $request): string
    {
        $key = strtolower((string) $request->header(self::HEADER));
        abort_unless(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $key), 422, 'Use a random UUID v4 checkout key.');

        return hash('sha256', $key);
    }

    private function owner(Request $request): string
    {
        $customer = app(CustomerAuthService::class)->resolve($request);

        return hash('sha256', json_encode([$customer?->id, $request->header(CartService::TOKEN_HEADER)], JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    /** Claim before FormRequest validation so finished requests replay after settings change. */
    public function begin(Request $request): StoreCheckoutAttempt|JsonResponse
    {
        $storeId = app(CurrentStore::class)->id();
        abort_unless($storeId, 404);
        $key = $this->key($request);
        $owner = $this->owner($request);
        // Only the JSON body defines the operation; locale/query parameters do not.
        $hash = hash_hmac('sha256', json_encode($this->canonical($request->json()->all()), JSON_THROW_ON_ERROR), (string) config('app.key'));
        $lease = (string) Str::uuid();

        return DB::transaction(function () use ($storeId, $key, $owner, $hash, $lease) {
            StoreCheckoutAttempt::query()->insertOrIgnore(['store_id' => $storeId, 'key_hash' => $key, 'owner_hash' => $owner,
                'request_hash' => $hash, 'lease' => $lease, 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $attempt = StoreCheckoutAttempt::where('store_id', $storeId)->where('key_hash', $key)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($attempt->owner_hash, $owner) && hash_equals($attempt->request_hash, $hash), 409, 'This checkout key belongs to a different request or customer.');
            if ($attempt->response_status !== null || $attempt->store_order_id !== null) {
                return $this->replay($attempt);
            }
            if ($attempt->lease !== $lease) {
                if ($attempt->claimed_at->greaterThan(now()->subMinutes(2))) {
                    return $this->pending();
                }
                $attempt->update(['lease' => $lease, 'claimed_at' => now()]);
            }

            return $attempt;
        });
    }

    /** Must be the first lock in the order-creation transaction. Old workers are fenced out. */
    public function lock(Request $request): ?StoreCheckoutAttempt
    {
        $claim = $request->attributes->get(self::ATTRIBUTE);
        if (! $claim instanceof StoreCheckoutAttempt) {
            return null;
        }
        $current = StoreCheckoutAttempt::where('store_id', $claim->store_id)->whereKey($claim->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->lease === $claim->lease && $current->store_order_id === null && $current->response_status === null, 409, 'This checkout attempt has already been handled. Recover its result.');

        return $current;
    }

    public function finish(StoreCheckoutAttempt $claim, JsonResponse $response): void
    {
        DB::transaction(function () use ($claim, $response) {
            $current = StoreCheckoutAttempt::where('store_id', $claim->store_id)->whereKey($claim->id)->where('lease', $claim->lease)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $status = $response->getStatusCode();
            if ($current->store_order_id === null) {
                // A proven validation/authorization rejection permits a corrected submission.
                if ($status >= 400 && $status < 500) {
                    $current->delete();
                    $response->setData($response->getData(true) + ['checkout_rejected' => true]);
                }

                return;
            }
            if ($status < 500) {
                $current->update(['response_status' => $status, 'response_body' => $response->getData(true)]);
            }
        });
    }

    public function recover(Request $request): JsonResponse
    {
        $attempt = StoreCheckoutAttempt::where('store_id', app(CurrentStore::class)->id())->where('key_hash', $this->key($request))->first();
        abort_unless($attempt && hash_equals($attempt->owner_hash, $this->owner($request)), 404, 'No checkout result is available for this key.');

        return $this->replay($attempt, true);
    }

    private function pending(): JsonResponse
    {
        return response()->json(['checkout_pending' => true, 'message' => 'This checkout is still being processed. Recover the same request shortly.'], 409, ['Retry-After' => '3', 'Cache-Control' => 'private, no-store']);
    }

    private function replay(StoreCheckoutAttempt $attempt, bool $recover = false): JsonResponse
    {
        $order = $attempt->store_order_id !== null
            ? StoreOrder::query()->where('store_id', $attempt->store_id)->whereKey($attempt->store_order_id)->with('items')->first()
            : null;
        if ($attempt->store_order_id !== null) {
            abort_unless($order, 410, 'This order is no longer available. This checkout key cannot create another order.');
            abort_if($order->status === 'cancelled', 409, 'This order was cancelled. Contact the store before placing another order.');
        }
        // Recover online payment through its current transaction, not an old cached redirect.
        if ($order !== null && $recover && ! in_array($order->payment_method, ['cod', 'bank_transfer'], true)) {
            return $this->resumePayment($attempt, $order);
        }
        if ($attempt->response_status !== null) {
            $body = $attempt->response_body;
            // Keep the saved result, but renew private attachment links on every replay.
            // A cached signed URL expires while the immutable order snapshot survives.
            if ($order !== null && isset($body['data']['items']) && is_array($body['data']['items'])) {
                $snapshots = $order->items->keyBy('id');
                $body['data']['items'] = array_map(function ($line) use ($snapshots, $attempt) {
                    $item = $snapshots->get($line['id'] ?? null);
                    if ($item !== null) {
                        $line['personalization'] = ProductPersonalization::present($item->personalization ?? [], $attempt->store_id);
                    }

                    return $line;
                }, $body['data']['items']);
            }
            if (isset($body['payment_retry']) && $attempt->store_order_id) {
                $body['payment_retry'] = ['token' => app(PaymentRetryToken::class)->make($attempt->store_id, $attempt->store_order_id), 'expires_in' => 7200];
            }

            return response()->json($body, $attempt->response_status, ['Idempotency-Replayed' => 'true', 'Cache-Control' => 'private, no-store']);
        }
        if ($order !== null) {
            return $this->resumePayment($attempt, $order);
        }
        if ($attempt->claimed_at->lessThanOrEqualTo(now()->subMinutes(2))) {
            return response()->json(['checkout_retry' => true, 'message' => 'Resume the original checkout with the same key and details.'], 409, ['Cache-Control' => 'private, no-store']);
        }

        return $this->pending();
    }

    private function resumePayment(StoreCheckoutAttempt $attempt, StoreOrder $order): JsonResponse
    {
        return response()->json(['message' => 'Your order is saved. Resume its payment without placing another order.',
            'data' => new StoreOrderResource($order), 'payment_retry' => ['token' => app(PaymentRetryToken::class)->make($attempt->store_id, $order->id), 'expires_in' => 7200]], 422, ['Idempotency-Replayed' => 'true', 'Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhookDelivery;
use App\Models\User;
use App\Services\Commerce\ManualStoreOrders;
use App\Services\Commerce\OrderLimits;
use App\Services\Commerce\PhoneBlocking;
use App\Services\Commerce\StoreOrderWebhooks;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantOrderActionsController extends Controller
{
    public function show(Request $request, Store $store, int $order): JsonResponse
    {
        $this->authorizeActor($store, $request);
        $row = StoreOrder::withTrashed()->forStore($store)->findOrFail($order);
        app(PlatformWallet::class)->assertOrderVisible($row);

        return $this->response($row);
    }

    public function archive(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['archived' => ['required', 'boolean'], 'expected_updated_at' => ['required', 'date']]);
        $data['archived'] = (bool) $data['archived'];
        $row = DB::transaction(function () use ($store, $request, $order, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($store, $request);
            $row = StoreOrder::withTrashed()->forStore($store)->whereKey($order)->lockForUpdate()->firstOrFail();
            app(PlatformWallet::class)->assertOrderVisible($row);
            if ($row->trashed() === $data['archived']) {
                return $row;
            }
            abort_unless($row->updated_at->equalTo($data['expected_updated_at']), 409, 'Order changed. Reload before archiving or restoring it.');
            abort_unless(in_array($row->status, ['cancelled', 'delivered'], true), 422, 'Only completed or cancelled orders can be archived.');
            if ($data['archived']) {
                $row->delete();
                StoreOrderWebhookDelivery::where('store_id', $store->id)->where('store_order_id', $row->id)->whereIn('status', ['queued', 'retry', 'blocked'])
                    ->update(['status' => 'skipped', 'payload' => null, 'updated_at' => now()]);
            } else {
                $row->restore();
            }
            $this->record($row, $request, $data['archived'] ? 'merchant_archive' : 'merchant_restore');

            return $row;
        });

        return $this->response($row);
    }

    public function review(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['classification' => ['nullable', Rule::in(['moderate_risk', 'high_risk', 'spam'])],
            'version' => ['required', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:2000']]);
        $row = DB::transaction(function () use ($store, $request, $order, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($store, $request);
            $row = $this->find($store, $order);
            $review = DB::table('store_order_reviews')->where('store_id', $store->id)->where('store_order_id', $row->id)->lockForUpdate()->first();
            abort_unless(($review->version ?? 0) === $data['version'], 409, 'Review changed. Reload before updating it.');
            $classification = $data['classification'] ?? null;
            if ($review && $review->classification === $classification) {
                return $row;
            }
            DB::table('store_order_reviews')->updateOrInsert(['store_order_id' => $row->id], ['store_id' => $store->id,
                'classification' => $classification, 'version' => ($review->version ?? 0) + 1, 'actor_id' => $request->user()->id,
                'created_at' => $review->created_at ?? now(), 'updated_at' => now()]);
            $this->record($row, $request, 'merchant_review_'.($classification ?? 'clear'), $data['note'] ?? null);

            return $row;
        });

        return $this->response($row);
    }

    public function blockPhone(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($store, $request, $order, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($store, $request);
            $row = $this->find($store, $order);
            abort_unless($row->customer_phone, 422, 'This order has no customer phone number.');
            $limits = app(OrderLimits::class);
            $normalized = $limits->normalizePhone($row->customer_phone, $limits->configured($store)['phone_country']);
            $before = DB::table('store_phone_blocks')->where('store_id', $store->id)->where('scope', 'checkout')->where('phone_normalized', $normalized)->where('active', true)->exists();
            app(PhoneBlocking::class)->add($store, $row->customer_phone, $data['note'] ?? null, (int) $request->user()->id);
            if (! $before) {
                $this->record($row, $request, 'merchant_phone_block', $data['note'] ?? null);
            }
        });

        return response()->json(['blocked' => true]);
    }

    public function resendWebhook(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $count = DB::transaction(function () use ($store, $request, $order, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($store, $request);
            $row = $this->find($store, $order);
            $key = 'resend:'.hash('sha256', $row->id.':'.$store->owner_user_id.':'.$request->user()->id.':'.Str::lower($data['request_key']));
            $existing = StoreOrderWebhookDelivery::where('store_id', $store->id)->where('store_order_id', $row->id)->where('event_key', $key)->count();
            if ($existing) {
                return $existing;
            }
            $count = app(StoreOrderWebhooks::class)->resend($row, $key);
            abort_unless($count > 0, 422, 'No enabled order webhook is configured for this store.');
            $this->record($row, $request, 'merchant_webhook_resend');

            return $count;
        });

        return response()->json(['deliveries' => $count], 202);
    }

    private function authorizeActor(Store $store, Request $request): void
    {
        $actor = User::findOrFail($request->user()->id);
        abort_unless(app(ManualStoreOrders::class)->authorized($store, $actor), 403);
    }

    private function find(Store $store, int $id): StoreOrder
    {
        $row = StoreOrder::forStore($store)->whereKey($id)->lockForUpdate()->firstOrFail();
        app(PlatformWallet::class)->assertOrderVisible($row);

        return $row;
    }

    private function record(StoreOrder $row, Request $request, string $source, ?string $note = null): void
    {
        $row->statusChanges()->create(['store_id' => $row->store_id, 'from_status' => $row->status, 'to_status' => $row->status,
            'actor_id' => $request->user()->id, 'source' => $source, 'notes' => $note]);
    }

    private function response(StoreOrder $row): JsonResponse
    {
        return response()->json(['data' => new MerchantOrderResource($row->load(['items', 'statusChanges.actor', 'b2bOrder']))]);
    }
}

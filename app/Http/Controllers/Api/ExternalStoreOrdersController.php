<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderWebhook;
use App\Models\StoreOrderWebhookDelivery;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalStoreOrdersController extends Controller
{
    public function byNumber(Request $request, Store $store, string $number): JsonResponse
    {
        $order = StoreOrder::where('store_id', $store->id)->where('order_number', $number)->firstOrFail();

        return app(MerchantOrderController::class)->show($request, $store, $order->id);
    }

    public function destroy(Request $request, Store $store, int $order): JsonResponse
    {
        DB::transaction(function () use ($store, $order) {
            $row = StoreOrder::where('store_id', $store->id)->whereKey($order)->lockForUpdate()->firstOrFail();
            app(PlatformWallet::class)->assertOrderVisible($row);
            $row->delete();
            StoreOrderWebhookDelivery::where('store_id', $store->id)->where('store_order_id', $row->id)->whereIn('status', ['queued', 'retry', 'blocked'])
                ->update(['status' => 'skipped', 'payload' => null, 'updated_at' => now()]);
        });

        return response()->json(['deleted' => true]);
    }

    public function deleteWebhook(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);
        $count = DB::transaction(function () use ($store, $data) {
            $rows = StoreOrderWebhook::where('store_id', $store->id)->where('user_id', $store->owner_user_id)->where('url', $data['url'])->lockForUpdate()->get();
            foreach ($rows as $row) {
                $row->update(['enabled' => false, 'version' => $row->version + 1]);
                $row->delete();
                StoreOrderWebhookDelivery::where('store_id', $store->id)->where('webhook_id', $row->id)->whereIn('status', ['queued', 'retry', 'blocked'])
                    ->update(['status' => 'skipped', 'payload' => null, 'updated_at' => now()]);
            }

            return $rows->count();
        });

        return response()->json(['deleted' => $count]);
    }
}

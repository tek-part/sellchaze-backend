<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrderSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderApiController extends Controller
{
    /**
     * Store orders from wigpleasure (professional API).
     * Accepts full order payload with customer, shipping, payment, products.
     */
    public function store(Request $request, OrderSyncService $syncService)
    {
        $validator = Validator::make($request->all(), [
            'wigpleasure_order_id' => 'required|integer',
            'customer' => 'required|array',
            'customer.name' => 'required|string|max:255',
            'customer.email' => 'required|email',
            'customer.phone' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|array',
            'shipping_address.address_1' => 'nullable|string',
            'shipping_address.address_2' => 'nullable|string',
            'shipping_address.city' => 'nullable|string|max:100',
            'shipping_address.state' => 'nullable|string|max:100',
            'shipping_address.zip' => 'nullable|string|max:20',
            'shipping_address.country' => 'nullable|string|max:2',
            'shipping_address.type' => 'nullable|in:customer_direct,warehouse',
            'payment' => 'nullable|array',
            'payment.method' => 'nullable|string|max:50',
            'payment.type' => 'nullable|in:full,partial_cod',
            'payment.paid_amount' => 'nullable|numeric|min:0',
            'payment.currency' => 'nullable|string|max:10',
            'payment.transaction_id' => 'nullable|string|max:255',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.qty' => 'required|integer|min:1',
            'products.*.title' => 'nullable|string',
            'products.*.title_en' => 'nullable|string',
            'products.*.title_ar' => 'nullable|string',
            'products.*.image_url' => 'nullable|string',
            'products.*.selections' => 'nullable|array',
            'products.*.wigpleasure_category_id' => 'nullable|integer|min:1',
            'products.*.category_id' => 'nullable|integer|min:1',
            'products.*.category_name' => 'nullable|string|max:255',
            'products.*.category_name_en' => 'nullable|string|max:255',
            'products.*.category_name_ar' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'store_status' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wigpleasureOrderId = (int) $request->wigpleasure_order_id;

        try {
            $result = $syncService->sync($request->all(), $request->attributes->get('api_user'));

            $statusCode = isset($result['message']) && $result['message'] === 'Order already synced' ? 200 : 201;

            return response()->json($result, $statusCode);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('SELLCHASE API: Failed to create orders', [
                'wigpleasure_order_id' => $wigpleasureOrderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create orders: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update already-synced orders by wigpleasure_order_id.
     */
    public function updateByWigpleasureOrderId(int $wigpleasureOrderId, Request $request, OrderSyncService $syncService)
    {
        $validator = Validator::make(array_merge($request->all(), [
            'wigpleasure_order_id' => $wigpleasureOrderId,
        ]), [
            'wigpleasure_order_id' => 'required|integer',
            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.email' => 'nullable|email',
            'customer.phone' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|array',
            'shipping_address.address_1' => 'nullable|string',
            'shipping_address.address_2' => 'nullable|string',
            'shipping_address.city' => 'nullable|string|max:100',
            'shipping_address.state' => 'nullable|string|max:100',
            'shipping_address.zip' => 'nullable|string|max:20',
            'shipping_address.country' => 'nullable|string|max:2',
            'shipping_address.type' => 'nullable|in:customer_direct,warehouse',
            'payment' => 'nullable|array',
            'payment.method' => 'nullable|string|max:50',
            'payment.type' => 'nullable|in:full,partial_cod',
            'payment.paid_amount' => 'nullable|numeric|min:0',
            'payment.currency' => 'nullable|string|max:10',
            'payment.transaction_id' => 'nullable|string|max:255',
            'products' => 'nullable|array',
            'products.*.product_id' => 'nullable|integer',
            'products.*.qty' => 'nullable|integer|min:1',
            'products.*.title' => 'nullable|string',
            'products.*.title_en' => 'nullable|string',
            'products.*.title_ar' => 'nullable|string',
            'products.*.image_url' => 'nullable|string',
            'products.*.selections' => 'nullable|array',
            'products.*.wigpleasure_category_id' => 'nullable|integer|min:1',
            'products.*.category_id' => 'nullable|integer|min:1',
            'products.*.category_name' => 'nullable|string|max:255',
            'products.*.category_name_en' => 'nullable|string|max:255',
            'products.*.category_name_ar' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'store_status' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $syncService->updateSyncedOrder($request->all(), $wigpleasureOrderId);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            Log::error('SELLCHASE API: Failed to update synced orders', [
                'wigpleasure_order_id' => $wigpleasureOrderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update orders: '.$e->getMessage(),
            ], 500);
        }
    }
}

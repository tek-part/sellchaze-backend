<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class OrderApiController extends Controller
{
    /**
     * Store a new order from the website.
     * Expects: product_id, quantity, attributes, user_id (customer), shipping_address, shipping_type,
     * payment_method, payment_type, paid_amount, notes (optional), ref_number (optional)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'attributes' => 'required|array',
            'user_id' => 'required|exists:users,id',
            'shipping_address' => 'nullable|string',
            'shipping_type' => 'nullable|in:customer_direct,warehouse',
            'payment_method' => 'nullable|string|max:50',
            'payment_type' => 'nullable|in:full,partial_cod',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'ref_number' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $order = new Order;
        $order->code = random_alphanumeric(10);
        $order->source = Order::SOURCE_MERCHANT_DIRECT;
        $order->quantity = $request->quantity;
        $order->user_id = $request->user_id;
        $order->product_id = $request->product_id;
        $order->attributes = serialize($request->attributes);
        $order->status = 'pending';
        $order->notes = $request->notes ?? '';
        $order->ref_number = $request->ref_number;
        $order->shipping_address = $request->shipping_address;
        $order->shipping_type = $request->shipping_type ?? 'customer_direct';
        $order->payment_method = $request->payment_method;
        $order->payment_type = $request->payment_type ?? 'full';
        $order->paid_amount = $request->paid_amount;
        $order->delivery_path = ($request->payment_type === 'partial_cod') ? 'pickup_point' : 'customer_direct';
        $order->save();

        $product = $order->product;
        $supplierIds = [];
        if ($product && Schema::hasColumn('products', 'user_id') && $product->user_id) {
            $supplierIds = [(int) $product->user_id];
        }
        if (empty($supplierIds)) {
            $supplierIds = User::whereHas('products', fn ($q) => $q->where('products.id', $order->product_id))
                ->pluck('id')
                ->unique()
                ->values()
                ->all();
        }
        if (empty($supplierIds)) {
            return response()->json([
                'success' => false,
                'message' => __('No supplier could be resolved for this product. Use the store sync API or assign a product owner.'),
            ], 422);
        }
        foreach ($supplierIds as $supplierId) {
            OrderSuppliers::create([
                'order_id' => $order->id,
                'customer' => $request->user_id,
                'supplier' => $supplierId,
            ]);
        }

        if ($request->payment_method && $request->paid_amount > 0) {
            $slug = strtolower(str_replace([' ', '-'], '_', $request->payment_method));
            $gateway = PaymentGateway::firstOrCreate(
                ['slug' => $slug],
                ['name' => (string) $request->payment_method]
            );
            DB::transaction(function () use ($gateway, $request, $order) {
                $wallet = $gateway->wallet;
                if (! $wallet) {
                    $wallet = $gateway->wallet()->create(['balance' => 0]);
                }
                GatewayTransaction::create([
                    'gateway_id' => $gateway->id,
                    'type' => 'order_payment',
                    'amount' => (float) $request->paid_amount,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'notes' => 'Order '.$order->code,
                ]);
                $wallet->increment('balance', (float) $request->paid_amount);
            });
        }

        return response()->json([
            'success' => true,
            'order' => [
                'id' => $order->id,
                'code' => $order->code,
                'status' => $order->status,
                'delivery_path' => $order->delivery_path,
            ],
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantOrderResource;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\OrderDeposits;
use App\Services\Commerce\OrderPaymentMethods;
use App\Services\PlatformWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** G1: change an unpaid order's payment method, and record the remainder of a deposit collected on delivery. */
class MerchantOrderPaymentController extends Controller
{
    /** GET orders/{order}/payment/methods — methods the order can move to, with the totals each gives. */
    public function methods(Request $request, Store $store, int $order): JsonResponse
    {
        return response()->json(['data' => app(OrderPaymentMethods::class)->options($store, $this->find($order))]);
    }

    /** POST orders/{order}/payment/method */
    public function change(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'string', Rule::in(OrderPaymentMethods::TARGETS)],
            'expected_grand_total' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'], 'note' => ['nullable', 'string', 'max:2000']]);
        $model = app(OrderPaymentMethods::class)->change($store, $this->find($order), $data['payment_method'], (int) $request->user()->id,
            $data['note'] ?? null, isset($data['expected_grand_total']) ? (string) $data['expected_grand_total'] : null);

        return response()->json(['data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor']))]);
    }

    /** POST orders/{order}/payment/collect-remainder */
    public function collectRemainder(Request $request, Store $store, int $order): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:200'], 'amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'collected' => ['required', 'accepted'], 'note' => ['nullable', 'string', 'max:2000']]);
        $model = app(OrderDeposits::class)->collectRemainder($this->find($order), $data['reference'], (string) $data['amount'], $data['currency'],
            (int) $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => new MerchantOrderResource($model->load(['items', 'statusChanges.actor']))]);
    }

    private function find(int $id): StoreOrder
    {
        $order = StoreOrder::query()->find($id); // StoreScope-isolated to the current store
        abort_if($order === null, 404, 'Order not found.');
        app(PlatformWallet::class)->assertOrderVisible($order);

        return $order;
    }
}

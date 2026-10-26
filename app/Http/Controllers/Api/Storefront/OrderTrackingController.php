<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutPhoneVerification;
use App\Services\Commerce\GuestOrderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** G2: POST /storefront/orders/track — order number + the phone or e-mail used on it. */
class OrderTrackingController extends Controller
{
    use ResolvesStorefront;

    public function __invoke(Request $request, GuestOrderTracking $tracking, CheckoutPhoneVerification $text): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate(['order_number' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'], 'contact' => ['required', 'string', 'max:255']]);
        $order = $tracking->find($store, $data['order_number'], $data['contact']);
        // One answer for a missing order, a wrong contact and an exhausted attempt budget.
        if ($order === null) {
            return response()->json(['message' => $text->message('لم نعثر على طلب بهذه البيانات. تأكد من رقم الطلب والهاتف أو البريد المستخدم فيه.',
                'We could not find an order with these details. Check the order number and the phone or e-mail used on it.')], 404, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json(['data' => $tracking->present($order)], 200, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutPhoneVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    use ResolvesStorefront;

    public function send(Request $request, CheckoutPhoneVerification $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return response()->json(['data' => $otp->send($this->currentStore($request), $data['phone'], $data['token'], $request->ip() ?? '')], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function verify(Request $request, CheckoutPhoneVerification $otp): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        return response()->json(['data' => $otp->verify($this->currentStore($request), $data['token'], $data['code'])], 200, ['Cache-Control' => 'private, no-store']);
    }
}

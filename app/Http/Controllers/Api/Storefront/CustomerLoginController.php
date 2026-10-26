<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StoreCustomerResource;
use App\Services\Commerce\CheckoutPhoneVerification;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\CustomerGoogleLogin;
use App\Services\Commerce\CustomerLoginMethods;
use App\Services\Commerce\CustomerPhoneLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** G2: storefront sign-in methods beyond e-mail — WhatsApp code and Google. */
class CustomerLoginController extends Controller
{
    use ResolvesStorefront;

    private const NO_STORE = ['Cache-Control' => 'private, no-store'];

    /** GET /storefront/auth/methods */
    public function methods(Request $request, CustomerLoginMethods $methods): JsonResponse
    {
        return response()->json(['data' => $methods->publicMethods($this->currentStore($request))], 200, self::NO_STORE);
    }

    /** POST /storefront/auth/phone/send — same answer whether or not an account uses the phone. */
    public function sendPhoneCode(Request $request, CustomerPhoneLogin $login): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return response()->json(['data' => $login->send($this->currentStore($request), $data['phone'], $data['token'], $request->ip() ?? '')], 200, self::NO_STORE);
    }

    /** POST /storefront/auth/phone/verify — signs in, or asks for a name before creating the account. */
    public function verifyPhoneCode(Request $request, CustomerPhoneLogin $login): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'name' => ['nullable', 'string', 'max:255']]);
        $result = $login->verify($this->currentStore($request), $data['token'], $data['code'] ?? null, $data['name'] ?? null);
        if (isset($result['name_required'])) {
            return response()->json(['data' => ['status' => 'name_required']], 200, self::NO_STORE);
        }

        return response()->json(['data' => new StoreCustomerResource($result['customer']), 'token' => $result['token']], 200, self::NO_STORE, JSON_UNESCAPED_UNICODE);
    }

    /** POST /storefront/account/phone/send (store.customer) — a code to confirm the customer's phone. */
    public function sendAccountPhoneCode(Request $request, CustomerPhoneLogin $login): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:50'], 'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return response()->json(['data' => $login->send($this->currentStore($request), $data['phone'], $data['token'], $request->ip() ?? '', CustomerPhoneLogin::VERIFY_PURPOSE, $customer)], 200, self::NO_STORE);
    }

    /** POST /storefront/account/phone/verify (store.customer) — the confirmed phone becomes a sign-in phone. */
    public function verifyAccountPhone(Request $request, CustomerPhoneLogin $login): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $customer = $login->verifyPhone($this->currentStore($request), $this->currentCustomer($request), $data['token'], $data['code']);

        return response()->json(['data' => new StoreCustomerResource($customer)], 200, self::NO_STORE, JSON_UNESCAPED_UNICODE);
    }

    /** GET /storefront/auth/google/start?challenge= — the Google consent URL for this storefront. */
    public function startGoogle(Request $request, CustomerGoogleLogin $google, CustomerLoginMethods $methods): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate(['challenge' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        abort_unless($methods->googleEnabled($store), 404);

        return response()->json(['data' => ['url' => $google->start($store, $request->getSchemeAndHttpHost(), $data['challenge'])]], 200, self::NO_STORE);
    }

    /** POST /storefront/auth/google/exchange — the one-time code from the callback, for the browser that started it. */
    public function exchangeGoogle(Request $request, CustomerGoogleLogin $google, CheckoutPhoneVerification $text): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/'], 'verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43,128}$/']]);
        $customer = $google->exchange($store, $data['code'], $data['verifier']);
        if (! $customer) {
            throw ValidationException::withMessages(['code' => $text->message('انتهت محاولة تسجيل الدخول بجوجل. حاول مرة أخرى.', 'The Google sign-in expired. Try again.')]);
        }
        $token = app(CustomerAuthService::class)->issueToken($customer);

        return response()->json(['data' => new StoreCustomerResource($customer), 'token' => $token], 200, self::NO_STORE, JSON_UNESCAPED_UNICODE);
    }
}

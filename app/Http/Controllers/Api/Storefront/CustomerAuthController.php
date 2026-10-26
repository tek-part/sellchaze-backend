<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ChangePasswordRequest;
use App\Http\Requests\Storefront\LoginCustomerRequest;
use App\Http\Requests\Storefront\RegisterCustomerRequest;
use App\Http\Requests\Storefront\UpdateCustomerRequest;
use App\Http\Resources\Storefront\StoreCustomerResource;
use App\Models\StoreCustomer;
use App\Services\Commerce\CustomerAuthService;
use App\Services\Commerce\CustomerPasswordResetService;
use App\Services\Commerce\OrderLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5: store-customer registration, login, and profile. Store-scoped —
 * an email is unique per store and tokens authenticate only within their store.
 */
class CustomerAuthController extends Controller
{
    use ResolvesStorefront;

    public function __construct(private readonly CustomerAuthService $auth) {}

    /** POST /storefront/auth/register */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $email = Str::lower(trim($request->input('email')));

        if (StoreCustomer::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already registered.']);
        }

        $result = $this->auth->register($store, $request->validated());

        return $this->authResponse($result, 201);
    }

    /** POST /storefront/auth/login */
    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $result = $this->auth->login($store, $request->input('email'), $request->input('password'));

        return $this->authResponse($result, 200);
    }

    /** POST /storefront/auth/logout (store.customer) */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(['message' => 'Logged out.']);
    }

    /** GET /storefront/account (store.customer) */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new StoreCustomerResource($this->currentCustomer($request))]);
    }

    /** PATCH /storefront/account (store.customer) */
    public function update(UpdateCustomerRequest $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $customer->fill($request->validated());
        // A WhatsApp-verified phone stops being the sign-in identity once the customer types another number.
        if ($customer->isDirty('phone') && $customer->phone_normalized !== null) {
            $store = $this->currentStore($request);
            $normalized = app(OrderLimits::class)->normalizePhone($customer->phone, app(OrderLimits::class)->configured($store)['phone_country']);
            if ($normalized !== $customer->phone_normalized) {
                $customer->phone_normalized = null;
            }
        }
        $customer->save();

        return response()->json(['data' => new StoreCustomerResource($customer)]);
    }

    /** PUT /storefront/account/password (store.customer) */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $this->auth->changePassword(
            $customer,
            (string) $request->input('current_password', ''),
            (string) $request->input('password'),
        );

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * POST /storefront/auth/forgot-password — always the same answer, whether or
     * not the address has an account here, so emails cannot be probed.
     */
    public function forgotPassword(Request $request, CustomerPasswordResetService $resets): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $resets->sendLink($this->currentStore($request), $data['email']);

        return response()->json(['message' => 'If an account exists for this email, a reset link is on its way.']);
    }

    /** POST /storefront/auth/reset-password */
    public function resetPassword(Request $request, CustomerPasswordResetService $resets): JsonResponse
    {
        $this->currentStore($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);
        $resets->reset($data['email'], $data['token'], $data['password']);

        return response()->json(['message' => 'Your password has been reset.']);
    }

    /** @param array{customer: StoreCustomer, token: string} $result */
    private function authResponse(array $result, int $status): JsonResponse
    {
        return response()->json([
            'data' => new StoreCustomerResource($result['customer']),
            'token' => $result['token'],
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }
}

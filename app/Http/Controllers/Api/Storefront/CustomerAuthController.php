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
        $customer->fill($request->validated())->save();

        return response()->json(['data' => new StoreCustomerResource($customer)]);
    }

    /** PUT /storefront/account/password (store.customer) */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);
        $this->auth->changePassword(
            $customer,
            (string) $request->input('current_password'),
            (string) $request->input('password'),
        );

        return response()->json(['message' => 'Password updated.']);
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

<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 store-customer authentication. Opaque bearer tokens (SHA-256 hashed
 * at rest) scoped to the current store — a token issued by store A can never
 * authenticate against store B because the lookup runs under StoreScope.
 */
class CustomerAuthService
{
    /** @return array{customer: StoreCustomer, token: string} */
    public function register(Store $store, array $data): array
    {
        $customer = StoreCustomer::create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'token' => $this->issueToken($customer)];
    }

    /** @return array{customer: StoreCustomer, token: string} */
    public function login(Store $store, string $email, string $password): array
    {
        $customer = StoreCustomer::query()->where('email', Str::lower(trim($email)))->first();

        if ($customer === null || ! $customer->is_active || ! Hash::check($password, $customer->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $customer->forceFill(['last_login_at' => now()])->save();

        return ['customer' => $customer, 'token' => $this->issueToken($customer)];
    }

    public function changePassword(StoreCustomer $customer, string $current, string $new): void
    {
        if (! Hash::check($current, $customer->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $customer->forceFill(['password' => Hash::make($new)])->save();
    }

    public function issueToken(StoreCustomer $customer): string
    {
        $plain = Str::random(48);
        $customer->tokens()->create([
            'name' => 'storefront',
            'token_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }

    /** Resolve the bearer token on the request to a StoreCustomer (or null). */
    public function resolve(Request $request): ?StoreCustomer
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            return null;
        }

        $token = StoreCustomerToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if ($token === null) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->customer;
    }

    public function logout(Request $request): void
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            return;
        }
        StoreCustomerToken::query()->where('token_hash', hash('sha256', $plain))->delete();
    }
}

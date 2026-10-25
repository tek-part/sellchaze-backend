<?php

namespace App\Services\Commerce;

use App\Mail\StoreCustomerPasswordResetMail;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerPasswordReset;
use App\Models\StoreCustomerToken;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset for store customers. Customers are not platform users, so the
 * framework's `users` broker cannot serve them: links are issued per store and
 * only work on the store that issued them.
 */
class CustomerPasswordResetService
{
    public const EXPIRES_MINUTES = 60;

    /** Minimum gap between two links for the same customer. */
    public const THROTTLE_SECONDS = 60;

    public function __construct(private readonly StorefrontUrlGenerator $urls) {}

    /**
     * Email a reset link when the address belongs to an active customer of this
     * store. Callers answer the same way either way, so addresses cannot be probed.
     */
    public function sendLink(Store $store, string $email): void
    {
        $email = Str::lower(trim($email));
        $customer = StoreCustomer::query()->where('email', $email)->where('is_active', true)->first();
        if ($customer === null) {
            return;
        }

        $recent = StoreCustomerPasswordReset::query()
            ->where('store_customer_id', $customer->id)
            ->where('created_at', '>', now()->subSeconds(self::THROTTLE_SECONDS))
            ->exists();
        if ($recent) {
            return;
        }

        $token = Str::random(64);
        DB::transaction(function () use ($customer, $token) {
            // Only the newest link works.
            StoreCustomerPasswordReset::query()->where('store_customer_id', $customer->id)->delete();
            StoreCustomerPasswordReset::create([
                'store_customer_id' => $customer->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
            ]);
        });

        $url = $this->resetUrl($store, $token, $email);
        if ($url !== null) {
            Mail::to($email, $customer->name)->queue(new StoreCustomerPasswordResetMail($store, $customer, $url));
        }
    }

    /** Set a new password from an emailed link and sign the customer out everywhere. */
    public function reset(string $email, string $token, string $password): StoreCustomer
    {
        $email = Str::lower(trim($email));
        $customer = StoreCustomer::query()->where('email', $email)->where('is_active', true)->first();
        $reset = $customer === null ? null : StoreCustomerPasswordReset::query()
            ->where('store_customer_id', $customer->id)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($customer === null || $reset === null) {
            throw ValidationException::withMessages([
                'token' => 'This password reset link is invalid or has expired.',
            ]);
        }

        DB::transaction(function () use ($customer, $reset, $password) {
            $customer->forceFill(['password' => Hash::make($password)])->save();
            $reset->forceFill(['used_at' => now()])->save();
            StoreCustomerPasswordReset::query()->where('store_customer_id', $customer->id)->whereKeyNot($reset->id)->delete();
            // The old password may be compromised: end every session.
            StoreCustomerToken::query()->where('store_customer_id', $customer->id)->delete();
        });

        return $customer;
    }

    private function resetUrl(Store $store, string $token, string $email): ?string
    {
        $base = $this->urls->publicUrl($store, '/reset-password');

        return $base === null ? null : $base.'?'.http_build_query(['token' => $token, 'email' => $email]);
    }
}

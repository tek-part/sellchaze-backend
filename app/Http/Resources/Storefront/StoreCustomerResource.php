<?php

namespace App\Http\Resources\Storefront;

use App\Models\StoreCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreCustomer
 */
class StoreCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified_at' => $this->email_verified_at,
            // G2: lets the account page offer "set a password" and show linked sign-in methods.
            'has_password' => $this->password !== null,
            'phone_verified' => $this->phone_normalized !== null,
            'google_linked' => $this->google_sub !== null,
            'created_at' => $this->created_at,
        ];
    }
}

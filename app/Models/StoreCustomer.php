<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 5: a retail customer of a single store. Not a platform User.
 * G2: e-mail and password are optional for accounts created by WhatsApp code or Google; the
 * verified phone (`phone_normalized`) and Google subject (`google_sub`) are unique per store.
 */
class StoreCustomer extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'name', 'email', 'password', 'phone', 'is_active',
        'email_verified_at', 'last_login_at', 'phone_normalized', 'google_sub',
    ];

    protected $hidden = ['password', 'phone_normalized', 'google_sub'];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /** @return HasMany<StoreCustomerToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(StoreCustomerToken::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<StoreOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }
}

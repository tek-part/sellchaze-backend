<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'last_login_at',
        'last_login_ip',
        'is_active',
        'pending_approval',
        'registration_role',
        'notify_login_email',
        'parent_user_id',
        'is_verified',
        'verified_at',
        'verified_by_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'pending_approval' => 'boolean',
        'notify_login_email' => 'boolean',
        'notification_preferences' => 'array',
    ];

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * The single store this user owns (one Merchant/Supplier = one Store).
     * Null for accounts that never own a store (Admin, Staff, Customer,
     * Employee). Use StoreProvisioner to resolve/auto-create it in request flow.
     *
     * @return HasOne<Store, $this>
     */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_user_id');
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** @return HasMany<LoginHistory, $this> */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Product, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<OrderQuotations, $this> */
    public function quotations(): HasMany
    {
        return $this->hasMany(OrderQuotations::class, 'supplier_user_id', 'id');
    }

    /** @return HasMany<OrderSuppliers, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(OrderSuppliers::class);
    }

    /**
     * Suppliers linked to this user as merchant (pivot merchant_supplier).
     *
     * @return BelongsToMany<User, $this>
     */
    public function suppliersAsMerchant(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'merchant_supplier', 'merchant_id', 'supplier_id')
            ->withPivot(['status', 'invited_by_user_id'])
            ->withTimestamps();
    }

    /**
     * Merchants linked to this user as supplier (pivot merchant_supplier).
     *
     * @return BelongsToMany<User, $this>
     */
    public function merchantsAsSupplier(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'merchant_supplier', 'supplier_id', 'merchant_id')
            ->withPivot(['status', 'invited_by_user_id'])
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /** @return HasMany<User, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasksAssigned(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to_user_id');
    }

    /** @return HasMany<Task, $this> */
    public function tasksCreated(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_by_user_id');
    }

    /** @return HasMany<VerificationRequest, $this> */
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isEmployee(): bool
    {
        return $this->hasRole('Employee');
    }

    public function isMerchant(): bool
    {
        return $this->hasRole(['Merchant', 'Customer']);
    }

    public function isSupplier(): bool
    {
        return $this->hasRole('Supplier');
    }

    /**
     * Generate a new API key for this user.
     */
    public function generateApiKey(): string
    {
        $key = Str::random(64);
        $this->forceFill(['api_key' => $key])->save();

        return $key;
    }

    /**
     * Get a masked version of the API key for display.
     */
    public function getApiKeyForDisplay(): ?string
    {
        $key = $this->getRawApiKey();
        if (! $key || strlen($key) < 12) {
            return null;
        }

        return substr($key, 0, 8).'...'.substr($key, -4);
    }

    /**
     * Get the raw API key (use only when necessary, e.g. for copy in own profile).
     */
    public function getRawApiKey(): ?string
    {
        return $this->attributes['api_key'] ?? null;
    }
}

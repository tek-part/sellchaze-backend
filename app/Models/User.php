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
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::saved(fn (User $user) => Cache::forget('jwt_access_user:'.$user->id));
        static::deleted(fn (User $user) => Cache::forget('jwt_access_user:'.$user->id));
    }

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
        'primary_sector_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
     * The single store this user owns (one Merchant = one Store).
     * Null for accounts that never own a store (Admin, Staff, Customer,
     * Employee). Use StoreProvisioner to resolve/auto-create it in request flow.
     *
     * @return HasOne<Store, $this>
     */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_user_id')
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    /** @return HasMany<Store, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'owner_user_id');
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'permissions', 'store_ids', 'joined_at'])
            ->withTimestamps();
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

    /**
     * Community sectors this member belongs to (one or many; the pivot keeps its historical name).
     *
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class, 'supplier_sector', 'user_id', 'sector_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** The member's primary sector (drives "your sector first" feed ordering). @return BelongsTo<Sector, $this> */
    public function primarySector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'primary_sector_id');
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

    /**
     * The role label the community surfaces show under a name. Query-free when
     * the `roles` relation is eager-loaded (Spatie reads the loaded collection).
     */
    public function communityRole(): string
    {
        return $this->isMerchant() ? 'merchant' : 'user';
    }

    /** Members who follow this user. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followed_id', 'follower_id')->withTimestamps();
    }

    /** Members this user follows. */
    public function followingUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followed_id')->withTimestamps();
    }
}

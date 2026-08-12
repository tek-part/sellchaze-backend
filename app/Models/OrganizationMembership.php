<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMembership extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'role', 'status', 'permissions',
        'store_ids', 'invited_by_user_id', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'store_ids' => 'array',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function canManageMembers(): bool
    {
        return $this->status === 'active' && in_array($this->role, ['owner', 'admin'], true);
    }
}

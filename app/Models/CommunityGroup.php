<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityGroup extends Model
{
    protected $fillable = ['owner_user_id', 'organization_id', 'sector_id', 'name', 'slug', 'description', 'avatar_url', 'cover_url', 'privacy', 'rules', 'members_count', 'posts_count', 'is_verified'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_verified' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_group_memberships')->withPivot(['role', 'status', 'joined_at'])->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

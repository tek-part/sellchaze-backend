<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $owner_user_id
 * @property int|null $sector_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $avatar_url
 * @property string|null $cover_url
 * @property string $privacy
 * @property int $members_count
 * @property int $posts_count
 * @property bool $is_verified
 * @property Sector|null $sector
 */
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

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_group_memberships')->withPivot(['role', 'status', 'joined_at'])->withTimestamps();
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

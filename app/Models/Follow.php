<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member following another company.
 *
 * @property int $follower_id
 * @property int $followed_id
 */
class Follow extends Model
{
    protected $fillable = ['follower_id', 'followed_id'];

    /** @return BelongsTo<User, $this> */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /** @return BelongsTo<User, $this> */
    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}

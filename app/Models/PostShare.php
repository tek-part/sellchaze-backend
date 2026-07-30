<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a re-share of a post, with an optional caption.
 *
 * @property int $post_id
 * @property int $user_id
 * @property string|null $caption
 */
class PostShare extends Model
{
    protected $fillable = ['post_id', 'user_id', 'caption'];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}

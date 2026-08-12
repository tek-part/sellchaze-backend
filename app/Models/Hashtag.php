<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hashtag extends Model
{
    protected $fillable = ['slug', 'label', 'posts_count', 'trend_score'];
    protected function casts(): array { return ['trend_score' => 'float']; }
    public function posts(): BelongsToMany { return $this->belongsToMany(Post::class, 'post_hashtag'); }
}

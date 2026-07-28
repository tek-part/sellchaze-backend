<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * A storefront newsletter opt-in. Store-scoped (tenant-isolated) via BelongsToStore.
 */
class NewsletterSubscriber extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'email', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

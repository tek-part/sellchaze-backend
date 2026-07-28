<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * A message submitted through a storefront contact form. Store-scoped via BelongsToStore.
 */
class StoreContactMessage extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'email', 'subject', 'message', 'is_read'];

    protected $casts = ['is_read' => 'boolean'];
}

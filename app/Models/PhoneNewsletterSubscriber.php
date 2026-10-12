<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class PhoneNewsletterSubscriber extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'name', 'phone', 'phone_normalized', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOrderReview extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer'];
}

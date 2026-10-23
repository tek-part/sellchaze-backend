<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = ['username',
        'biography',
        'photo',
        'cover_photo',
        'cover_color',
        'website',
        'tagline',
        'is_public',
        'company',
        'country',
        'city',
        'address',
        'gender',
        'phone',
        'whatsapp',
        'birthdate',
        'social_media',
        'products',
        'active',
        'online',
        'private',
        'user_id',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

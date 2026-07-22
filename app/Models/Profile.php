<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = ['username',
        'biography',
        'photo',
        'cover_photo',
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

    /**
     * Accepted quotations (deals) where this user is the customer.
     */
    public function acceptedQuotations()
    {
        return $this->hasMany(OrderQuotations::class, 'customer_user_id', 'user_id')
            ->where('status', 'accepted');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string,string|null>|null $contacts */
class MarketplaceServiceProvider extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['contacts', 'user_id'];

    protected $casts = ['contacts' => 'array', 'skills' => 'array', 'portfolio' => 'array', 'contacts_public' => 'boolean', 'published' => 'boolean', 'version' => 'integer'];

    /** @return BelongsTo<User,$this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WavexContactGroup extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WavexContactGroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WavexContactGroupMember::class, 'contact_group_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<WavexCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(WavexCampaign::class, 'contact_group_id');
    }
}

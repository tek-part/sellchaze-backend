<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WavexContactGroupMember extends Model
{
    protected $fillable = [
        'contact_group_id',
        'phone',
        'display_name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo(WavexContactGroup::class, 'contact_group_id');
    }
}

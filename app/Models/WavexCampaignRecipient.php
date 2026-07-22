<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WavexCampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'sort_order',
        'phone',
        'jid',
        'display_name',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WavexCampaign::class, 'campaign_id');
    }
}

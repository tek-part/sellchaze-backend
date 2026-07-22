<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WavexCampaign extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'template_id',
        'contact_group_id',
        'message_body',
        'delay_seconds',
        'status',
        'total_recipients',
        'sent_count',
        'queued_count',
        'failed_count',
        'started_at',
        'completed_at',
        'cancelled_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WavexTemplate::class, 'template_id');
    }

    /** @return BelongsTo<WavexContactGroup, $this> */
    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo(WavexContactGroup::class, 'contact_group_id');
    }

    /** @return HasMany<WavexCampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(WavexCampaignRecipient::class, 'campaign_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

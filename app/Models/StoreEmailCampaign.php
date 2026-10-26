<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A merchant e-mail marketing campaign: content blocks + an audience definition. Only a draft is
 * editable; scheduling freezes it, and sending snapshots its recipients
 * ({@see StoreEmailCampaignRecipient}).
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string|null $subject
 * @property string|null $preheader
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property array<int, array<string, mixed>>|null $content
 * @property string $locale
 * @property array<string, mixed>|null $audience
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_reason
 * @property list<string>|null $link_urls
 * @property int $recipients_count
 * @property int $sent_count
 * @property int $failed_count
 * @property int|null $opened_count withCount on the campaign list
 * @property int|null $clicked_count withCount on the campaign list
 */
class StoreEmailCampaign extends Model
{
    use BelongsToStore;

    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled', 'failed'];

    protected $fillable = [
        'store_id', 'name', 'subject', 'preheader', 'from_name', 'reply_to', 'content', 'locale', 'audience',
        'status', 'scheduled_at', 'created_by',
    ];

    protected $attributes = ['status' => 'draft', 'locale' => 'en'];

    protected $casts = [
        'content' => 'array',
        'audience' => 'array',
        'link_urls' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    /** @return HasMany<StoreEmailCampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(StoreEmailCampaignRecipient::class, 'campaign_id')->withoutGlobalScopes();
    }
}

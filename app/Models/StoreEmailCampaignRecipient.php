<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One address of a sending campaign. Status moves queued -> sending -> sent|failed|bounced exactly once
 * (the queued -> sending claim is atomic), so a retried job never e-mails the same address twice;
 * "unsubscribed" is set later from the unsubscribe link. The random token identifies the row in
 * unsubscribe, open and click URLs.
 *
 * @property int $id
 * @property int $store_id
 * @property int $campaign_id
 * @property string $email
 * @property string|null $customer_key
 * @property string $status
 * @property string $token
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 * @property int $clicks_count
 * @property Carbon|null $unsubscribed_at
 * @property string|null $error
 */
class StoreEmailCampaignRecipient extends Model
{
    use BelongsToStore;

    public const STATUSES = ['queued', 'sending', 'sent', 'failed', 'bounced', 'skipped', 'unsubscribed'];

    /** Addresses with one of these outcomes in any campaign of the store are never e-mailed again. */
    public const SUPPRESSED = ['unsubscribed', 'bounced'];

    protected $fillable = ['store_id', 'campaign_id', 'email', 'customer_key', 'status', 'token'];

    protected $hidden = ['token'];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'sending_at' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'clicks_count' => 'integer',
    ];

    /** @return BelongsTo<StoreEmailCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(StoreEmailCampaign::class, 'campaign_id')->withoutGlobalScopes();
    }
}

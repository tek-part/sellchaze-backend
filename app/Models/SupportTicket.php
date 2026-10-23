<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A merchant's conversation with the platform support team.
 *
 * Status flow: open (waiting for support) ⇄ awaiting_merchant (support replied) → resolved → closed.
 * A merchant reply re-opens a resolved ticket; closed tickets are final.
 */
class SupportTicket extends Model
{
    public const CATEGORIES = ['general', 'billing', 'store_setup', 'domain', 'payments', 'shipping', 'technical', 'account'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'awaiting_merchant', 'resolved', 'closed'];

    protected $fillable = [
        'reference', 'owner_user_id', 'opened_by_user_id', 'store_id', 'assigned_to_user_id',
        'subject', 'category', 'priority', 'status', 'last_reply_by', 'last_reply_at',
        'merchant_read_at', 'support_read_at', 'resolved_at',
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'merchant_read_at' => 'datetime',
        'support_read_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            $ticket->reference ??= 'SC-'.Str::upper(Str::random(8));
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function unreadForMerchant(): bool
    {
        return $this->last_reply_by === 'support' && ($this->merchant_read_at === null || $this->merchant_read_at->lt($this->last_reply_at));
    }

    public function unreadForSupport(): bool
    {
        return $this->last_reply_by === 'merchant' && ($this->support_read_at === null || $this->support_read_at->lt($this->last_reply_at));
    }
}

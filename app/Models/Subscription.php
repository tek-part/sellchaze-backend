<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's subscription to a plan (billing lifecycle). Matches the live
 * `subscriptions` schema: billing cycle, trial/period windows, gateway linkage.
 *
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property string $status
 * @property string $billing_cycle
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $gateway_slug
 * @property string|null $gateway_subscription_id
 * @property string|null $gateway_customer_id
 * @property int|null $last_invoice_id
 * @property array|null $metadata
 */
class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_cycle', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancelled_at',
        'gateway_slug', 'gateway_subscription_id', 'gateway_customer_id',
        'last_invoice_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function lastInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'last_invoice_id');
    }

    public function isCurrentlyActive(): bool
    {
        if (! in_array($this->status, ['active', 'trialing'], true)) {
            return false;
        }

        return $this->current_period_end === null || $this->current_period_end->isFuture();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A merchant-built draft order (Shopify "Draft orders"). Editable while open or
 * invoiced; completing it creates exactly one real StoreOrder.
 *
 * @property int $id
 * @property int $store_id
 * @property int $sequence
 * @property string $number
 * @property string $status
 * @property int|null $store_customer_id
 * @property array{name?:string|null,email?:string|null,phone?:string|null}|null $customer
 * @property array<string,mixed>|null $shipping_address
 * @property list<array<string,mixed>> $line_items
 * @property array<string,mixed>|null $applied_discount
 * @property array<string,mixed>|null $shipping_line
 * @property array<string,mixed>|null $totals
 * @property string|null $currency
 * @property string $grand_total
 * @property string|null $note
 * @property list<string>|null $tags
 * @property string|null $invoice_token_hash
 * @property Carbon|null $invoice_sent_at
 * @property Carbon|null $expires_at
 * @property int|null $completed_order_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by_id
 * @property int|null $updated_by_id
 * @property int|null $completed_by_id
 */
class StoreDraftOrder extends Model
{
    use BelongsToStore;

    public const STATUSES = ['open', 'invoice_sent', 'completed', 'cancelled'];

    public const EDITABLE = ['open', 'invoice_sent'];

    public const FIRST_SEQUENCE = 1001;

    protected $fillable = [
        'store_id', 'sequence', 'number', 'status', 'store_customer_id', 'customer', 'shipping_address', 'line_items',
        'applied_discount', 'shipping_line', 'totals', 'currency', 'grand_total', 'note', 'tags', 'invoice_token_hash',
        'invoice_sent_at', 'expires_at', 'completed_order_id', 'completed_at', 'cancelled_at', 'created_by_id', 'updated_by_id', 'completed_by_id',
    ];

    protected $hidden = ['invoice_token_hash'];

    protected $casts = [
        'customer' => 'array',
        'shipping_address' => 'array',
        'line_items' => 'array',
        'applied_discount' => 'array',
        'shipping_line' => 'array',
        'totals' => 'array',
        'tags' => 'array',
        'grand_total' => 'decimal:2',
        'sequence' => 'integer',
        'invoice_sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function editable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', 'draft-invoice:v1:'.$token);
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function completedOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'completed_order_id');
    }
}

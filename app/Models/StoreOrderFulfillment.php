<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A parcel handed to a carrier: some units of some lines of a storefront order, with its own
 * carrier, tracking and delivery state. Inventory for those units is committed when it is created.
 *
 * @property int $id
 * @property int $store_id
 * @property int $store_order_id
 * @property string $status
 * @property string $carrier
 * @property string|null $carrier_name
 * @property string|null $tracking_number
 * @property string|null $custom_tracking_url
 * @property list<array{store_order_item_id:int,quantity:int}> $items
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property bool $notify_customer
 * @property string|null $note
 * @property string $source
 * @property int|null $actor_id
 * @property int|null $store_shipment_id
 * @property int $version
 */
class StoreOrderFulfillment extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending', 'in_transit', 'delivered', 'failed', 'cancelled'];

    /** Statuses a merchant can set through an update (cancel has its own endpoint). */
    public const UPDATABLE_STATUSES = ['pending', 'in_transit', 'delivered', 'failed'];

    /** Statuses whose units still count as shipped. */
    public const ACTIVE = ['pending', 'in_transit', 'delivered', 'failed'];

    /**
     * Carrier key => display name and public tracking page template (`{number}` is URL-encoded).
     * A null template means the merchant supplies the tracking URL.
     */
    public const CARRIERS = [
        'bosta' => ['name' => 'Bosta', 'template' => 'https://bosta.co/tracking-shipments?shipment-number={number}'],
        'aramex' => ['name' => 'Aramex', 'template' => 'https://www.aramex.com/track/results?ShipmentNumber={number}'],
        'smsa' => ['name' => 'SMSA Express', 'template' => 'https://www.smsaexpress.com/trackingdetails?tracknumbers={number}'],
        'dhl' => ['name' => 'DHL', 'template' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={number}'],
        'fedex' => ['name' => 'FedEx', 'template' => 'https://www.fedex.com/fedextrack/?trknbr={number}'],
        'ups' => ['name' => 'UPS', 'template' => 'https://www.ups.com/track?tracknum={number}'],
        'j_and_t' => ['name' => 'J&T Express', 'template' => null],
        'mylerz' => ['name' => 'Mylerz', 'template' => null],
        'other' => ['name' => 'Other', 'template' => null],
    ];

    protected $fillable = ['store_id', 'store_order_id', 'status', 'carrier', 'carrier_name', 'tracking_number', 'custom_tracking_url', 'items',
        'shipped_at', 'delivered_at', 'cancelled_at', 'notify_customer', 'note', 'source', 'actor_id', 'store_shipment_id', 'version'];

    protected $casts = [
        'items' => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'notify_customer' => 'boolean',
        'version' => 'integer',
    ];

    public function carrierLabel(): string
    {
        $name = trim((string) $this->carrier_name);

        return $name !== '' ? $name : (self::CARRIERS[$this->carrier]['name'] ?? ucfirst($this->carrier));
    }

    /** The merchant's URL wins; otherwise the carrier template, when there is a tracking number. */
    public function trackingUrl(): ?string
    {
        if ($this->custom_tracking_url) {
            return $this->custom_tracking_url;
        }
        $template = self::CARRIERS[$this->carrier]['template'] ?? null;
        $number = trim((string) $this->tracking_number);

        return $template && $number !== '' ? str_replace('{number}', rawurlencode($number), $template) : null;
    }

    /** @return BelongsTo<StoreOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

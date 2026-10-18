<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $store_id
 * @property int $store_page_id
 * @property int|null $product_id
 * @property string $template_key
 * @property string|null $public_id
 * @property array|null $browser_pixels
 * @property-read StorePage $page
 * @property-read Product|null $product
 */
class StoreFunnel extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'store_page_id', 'product_id', 'template_key', 'browser_pixels'];

    protected $casts = ['browser_pixels' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $funnel) {
            $funnel->public_id = (string) Str::uuid();
        });
    }

    /** @return BelongsTo<StorePage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(StorePage::class, 'store_page_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $store_id
 * @property int $store_page_id
 * @property int|null $product_id
 * @property string $template_key
 * @property-read StorePage $page
 * @property-read Product|null $product
 */
class StoreFunnel extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'store_page_id', 'product_id', 'template_key'];

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

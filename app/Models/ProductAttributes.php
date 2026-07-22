<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributes extends Model
{
    use HasFactory;

    protected $hidden = ['updated_at', 'deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['product_id', 'attribute_id'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Attribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}

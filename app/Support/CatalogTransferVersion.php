<?php

namespace App\Support;

use App\Models\Product;

/** A portable/destructive selection also covers description, options, images and memberships. */
final class CatalogTransferVersion
{
    public static function for(Product $product): string
    {
        $product->loadMissing(['variants', 'media', 'additionalCategories']);
        $ignored = ['stock_quantity', 'reserved_quantity', 'track_inventory', 'created_at', 'updated_at', 'rating_avg', 'rating_count', 'sales_count', 'views_count'];
        $attributes = fn ($model) => collect($model->getAttributes())->except($ignored)->map(fn ($value, $key) => $model->getAttribute($key))->all();
        $value = [$attributes($product), $product->variants->sortBy('id')->map($attributes)->values()->all(),
            $product->media->sortBy('id')->map($attributes)->values()->all(), $product->additionalCategories->pluck('id')->sort()->values()->all()];

        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}

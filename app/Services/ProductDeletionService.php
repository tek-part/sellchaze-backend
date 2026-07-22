<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttributes;
use Illuminate\Support\Facades\File;

class ProductDeletionService
{
    public function delete(Product $product): void
    {
        ProductAttributes::where('product_id', $product->id)->delete();

        if ($product->image) {
            $imagePath = public_path('storage/uploads/products/original/').$product->image;
            $thumbPath = public_path('storage/uploads/products/thumbnails/').$product->image;

            if (File::exists($imagePath)) {
                File::delete($imagePath);
            }

            if (File::exists($thumbPath)) {
                File::delete($thumbPath);
            }
        }

        $product->delete();
    }
}

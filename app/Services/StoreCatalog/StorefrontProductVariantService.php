<?php

namespace App\Services\StoreCatalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner management of product variants. Runs under the ScopeToStore tenant, so
 * StoreScope auto-scopes queries and BelongsToStore auto-fills store_id.
 * Mirrors StorefrontProductService (whitelisted fill + storefront cache flush).
 */
class StorefrontProductVariantService
{
    public function create(Product $product, array $data): ProductVariant
    {
        $variant = DB::transaction(function () use ($product, $data) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertConversionAllowed($current);
            $this->assertDistinctOptions($current, $data['options'] ?? []);
            $variant = new ProductVariant;
            $this->fill($variant, $data);
            $this->setImage($current, $variant, $data);
            $variant->store_product_id = $current->id;
            $variant->save();
            // Include database defaults in the editorial version returned immediately after creation.
            $variant->refresh();

            return $variant;
        });

        $this->flush($product);

        return $variant;
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $variant = DB::transaction(function () use ($variant, $data) {
            $product = Product::query()->whereKey($variant->store_product_id)->lockForUpdate()->firstOrFail();
            $current = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($current, $data['edit_version'] ?? null);
            $this->assertDistinctOptions($product, $data['options'] ?? $current->options ?? [], $current->id);
            $this->fill($current, $data);
            $this->setImage($product, $current, $data);
            $current->save();

            return $current->setRelation('product', $product);
        });

        $this->flush($variant->product);

        return $variant;
    }

    /** Add missing combinations only; retries never reset stock, prices or variant IDs. */
    public function generate(Product $product, array $axes): array
    {
        $counts = DB::transaction(function () use ($product, $axes) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $combinations = [[]];
            foreach ($axes as $axis) {
                $next = [];
                foreach ($combinations as $combination) {
                    foreach ($axis['values'] as $value) {
                        $next[] = $combination + [$axis['name'] => $value];
                        if (count($next) > 200) {
                            throw ValidationException::withMessages(['axes' => 'Generate at most 200 combinations per request.']);
                        }
                    }
                }
                $combinations = $next;
            }
            $existing = $current->variants()->get();
            $known = $existing->mapWithKeys(fn (ProductVariant $variant) => [$this->optionsKey($variant->options ?? []) => true])->all();
            $missing = array_values(array_filter($combinations, fn (array $options) => ! isset($known[$this->optionsKey($options)])));
            if ($existing->count() + count($missing) > 500) {
                throw ValidationException::withMessages(['axes' => 'A product supports at most 500 generated options.']);
            }
            if ($missing !== []) {
                $this->assertConversionAllowed($current);
            }
            $position = (int) ($existing->max('position') ?? -1);
            foreach ($missing as $options) {
                $name = implode(' / ', array_values($options));
                if (mb_strlen($name) > 255) {
                    throw ValidationException::withMessages(['axes' => 'Shorten property values so each combination name fits 255 characters.']);
                }
                $current->variants()->create(['store_id' => $current->store_id, 'name' => $name,
                    'options' => $options, 'position' => ++$position, 'price_override' => null,
                    'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 0]);
            }

            return ['created' => count($missing), 'existing' => count($combinations) - count($missing)];
        });
        $this->flush($product);

        return $counts;
    }

    private function assertConversionAllowed(Product $product): void
    {
        if ($product->reserved_quantity > 0) {
            throw ValidationException::withMessages(['inventory' => 'Ship or cancel base-product reservations before adding options.']);
        }
        if ($product->stock_quantity > 0 && ! $product->variants()->exists()) {
            throw ValidationException::withMessages(['inventory' => 'Reconcile base-product stock to zero before allocating stock to new options.']);
        }
    }

    public function bulkUpdate(Product $product, array $targets, array $changes): int
    {
        $count = DB::transaction(function () use ($product, $targets, $changes) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variants = $current->variants()->whereIn('id', array_column($targets, 'id'))->lockForUpdate()->get()->keyBy('id');
            if ($variants->count() !== count($targets)) {
                throw ValidationException::withMessages(['variants' => 'All selected options must still belong to this product. Refresh the list.']);
            }
            foreach ($targets as $target) {
                $variant = $variants->get($target['id']);
                $this->assertVersion($variant, $target['version']);
                $this->fill($variant, $changes);
                $this->setImage($current, $variant, $changes);
                $variant->save();
            }

            return $variants->count();
        });
        $this->flush($product);

        return $count;
    }

    private function assertVersion(ProductVariant $variant, ?string $version): void
    {
        if ($version !== null && ! hash_equals($variant->editVersion(), $version)) {
            throw ValidationException::withMessages(['edit_version' => 'An option has changed. Refresh the list and review the changes before saving again.']);
        }
    }

    private function setImage(Product $product, ProductVariant $variant, array $data): void
    {
        if (! array_key_exists('image_media_id', $data)) {
            return;
        }
        $media = $data['image_media_id'] === null ? null : $product->media()->whereIn('type', ['cover', 'gallery'])->whereKey($data['image_media_id'])->first();
        if ($data['image_media_id'] !== null && ! $media) {
            throw ValidationException::withMessages(['image_media_id' => 'Choose an image from this product gallery.']);
        }
        $variant->image_media_id = $media?->id;
        $variant->image = null;
        $variant->setRelation('imageMedia', $media);
    }

    private function optionsKey(array $options): string
    {
        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[mb_strtolower(trim((string) $key))] = mb_strtolower(trim((string) $value));
        }
        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertDistinctOptions(Product $product, array $options, ?int $ignoreId = null): void
    {
        if ($options === []) {
            return;
        }
        $key = $this->optionsKey($options);
        foreach ($product->variants()->get() as $variant) {
            if ($variant->id !== $ignoreId && $this->optionsKey($variant->options ?? []) === $key) {
                throw ValidationException::withMessages(['options' => 'This combination already exists. Edit the existing option instead.']);
            }
        }
    }

    public function delete(ProductVariant $variant): void
    {
        $product = $variant->product;
        DB::transaction(function () use ($variant) {
            Product::query()->where('store_id', $variant->store_id)->whereKey($variant->store_product_id)->lockForUpdate()->firstOrFail();
            $current = ProductVariant::query()->where('store_id', $variant->store_id)->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if ($current->reserved_quantity > 0) {
                throw ValidationException::withMessages(['inventory' => 'Ship or cancel reserved orders before deleting this option.']);
            }
            $current->delete();
        });

        $this->flush($product);
    }

    private function fill(ProductVariant $variant, array $data): void
    {
        foreach (['name', 'sku', 'barcode', 'price_override', 'compare_price', 'cost', 'weight', 'options', 'is_active', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $variant->{$key} = $data[$key];
            }
        }
        if (array_key_exists('translations', $data)) {
            $variant->fillTranslations($data['translations'] ?? []);
        }
    }

    private function flush(?Product $product): void
    {
        if ($product === null) {
            return;
        }
        StorefrontService::forgetHomepage((int) $product->store_id);
        app(StorefrontPageCache::class)->flushStore((int) $product->store_id);
    }
}

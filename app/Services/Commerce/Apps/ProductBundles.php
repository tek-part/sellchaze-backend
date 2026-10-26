<?php

namespace App\Services\Commerce\Apps;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Frequently bought together" bundles: two to four fixed products sold together at a percentage
 * off. The discount is evaluated by the DiscountEngine like an automatic discount, so it never
 * stacks with another product/order discount and is recalculated at quote and placement.
 */
class ProductBundles implements MerchantAppSettings
{
    public function column(): string
    {
        return 'bundles_configuration';
    }

    public function configured(Store $store): array
    {
        return array_replace(['version' => 1, 'enabled' => false, 'bundles' => []], $store->bundles_configuration ?? []);
    }

    public function rules(Store $store): array
    {
        return ['enabled' => ['required', 'boolean'], 'bundles' => ['present', 'array', 'max:20'],
            'bundles.*' => ['required', 'array:id,title,product_ids,discount_percent,active'],
            'bundles.*.id' => ['nullable', 'uuid', 'distinct'],
            'bundles.*.title' => ['required', 'array:ar,en'],
            'bundles.*.title.ar' => ['required', 'string', 'max:120'], 'bundles.*.title.en' => ['required', 'string', 'max:120'],
            'bundles.*.product_ids' => ['required', 'array', 'min:2', 'max:4'],
            'bundles.*.product_ids.*' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $store->id)],
            'bundles.*.discount_percent' => ['required', 'integer', 'min:1', 'max:90'],
            'bundles.*.active' => ['required', 'boolean']];
    }

    public function normalize(Store $store, array $data): array
    {
        $bundles = [];
        foreach ($data['bundles'] as $index => $row) {
            $ids = array_values(array_map('intval', $row['product_ids']));
            if (count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(["bundles.$index.product_ids" => 'Choose different products for a bundle.']);
            }
            // Variant and personalization choices cannot be made from a one-click bundle, so bundles hold simple products.
            $personalized = Product::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('id', $ids)->get(['id', 'personalization_fields'])
                ->contains(fn (Product $product) => ! empty($product->personalization_fields));
            if ($personalized || ProductVariant::withoutGlobalScopes()->where('store_id', $store->id)->whereIn('store_product_id', $ids)->exists()) {
                throw ValidationException::withMessages(["bundles.$index.product_ids" => 'Bundles can only contain products without variants or personalization.']);
            }
            $bundles[] = ['id' => $row['id'] ?? (string) Str::uuid(), 'title' => ['ar' => trim($row['title']['ar']), 'en' => trim($row['title']['en'])],
                'product_ids' => $ids, 'discount_percent' => (int) $row['discount_percent'], 'active' => (bool) $row['active']];
        }
        if ($data['enabled'] && ! collect($bundles)->contains(fn (array $bundle) => $bundle['active'])) {
            throw ValidationException::withMessages(['bundles' => 'Add an active bundle before enabling the app.']);
        }

        return ['enabled' => (bool) $data['enabled'], 'bundles' => $bundles];
    }

    public function enabled(Store $store): bool
    {
        $config = $this->configured($store);

        return $config['enabled'] && collect($config['bundles'])->contains(fn (array $bundle) => $bundle['active']);
    }

    public function meta(Store $store): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function active(Store $store): array
    {
        return $this->enabled($store) ? array_values(array_filter($this->configured($store)['bundles'], fn (array $bundle) => $bundle['active'])) : [];
    }

    private function title(array $bundle): string
    {
        return $bundle['title'][app(LocaleContext::class)->current() === 'ar' ? 'ar' : 'en'] ?: $bundle['title']['en'];
    }

    /**
     * The best complete bundle in these lines: one unit of every bundle product forms a set, and each
     * set is discounted on the cheapest unit price of each product.
     *
     * @param  list<array{product_id:int,quantity:int,unit_price:string}>  $lines
     * @return array{id:string,title:string,amount:string}|null
     */
    public function discount(Store $store, array $lines): ?array
    {
        $best = null;
        foreach ($this->active($store) as $bundle) {
            $sets = PHP_INT_MAX;
            $setPrice = '0.00';
            foreach ($bundle['product_ids'] as $productId) {
                $matching = array_filter($lines, fn (array $line) => (int) $line['product_id'] === (int) $productId && (int) $line['quantity'] > 0);
                if ($matching === []) {
                    $sets = 0;
                    break;
                }
                $sets = min($sets, array_sum(array_map(fn (array $line) => (int) $line['quantity'], $matching)));
                $prices = array_map(fn (array $line) => (string) $line['unit_price'], $matching);
                usort($prices, fn (string $a, string $b) => bccomp($a, $b, 2));
                $setPrice = bcadd($setPrice, $prices[0], 2);
            }
            if ($sets < 1) {
                continue;
            }
            $amount = bcdiv(bcmul(bcmul($setPrice, (string) $sets, 2), (string) $bundle['discount_percent'], 4), '100', 2);
            if (bccomp($amount, '0.00', 2) > 0 && ($best === null || bccomp($amount, $best['amount'], 2) > 0)) {
                $best = ['id' => $bundle['id'], 'title' => $this->title($bundle), 'amount' => $amount];
            }
        }

        return $best;
    }

    /** Active bundles that include a product, for its storefront page. */
    public function publicFor(Store $store, int $productId): array
    {
        $bundles = array_values(array_filter($this->active($store), fn (array $bundle) => in_array($productId, $bundle['product_ids'], true)));
        if ($bundles === []) {
            return [];
        }
        $products = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)
            ->whereIn('id', array_merge(...array_column($bundles, 'product_ids')))->get()->keyBy('id');
        $result = [];
        foreach ($bundles as $bundle) {
            if (collect($bundle['product_ids'])->contains(fn (int $id) => ! $products->has($id))) {
                continue;
            }
            $result[] = ['id' => $bundle['id'], 'title' => $this->title($bundle), 'discount_percent' => $bundle['discount_percent'],
                'products' => array_map(function (int $id) use ($products) {
                    $product = $products->get($id);
                    $available = ! $product->track_inventory || $product->stock_quantity - $product->reserved_quantity > 0;

                    return ['id' => $product->id, 'name' => $product->translated('name'), 'slug' => $product->slug, 'price' => bcadd((string) $product->price, '0', 2),
                        'compare_price' => $product->compare_price, 'image_url' => $product->imageUrl(), 'available' => $available];
                }, $bundle['product_ids'])];
        }

        return $result;
    }
}

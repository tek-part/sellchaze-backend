<?php

namespace App\Services\StoreCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StorePriceChange;
use App\Models\StorePriceChangeItem;
use App\Models\User;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk price changes for one category or a product selection. A preview returns a fingerprint of the
 * exact prices it was computed from; applying requires the same fingerprint, runs in one transaction and
 * records every previous price so the whole change can be reverted in one action.
 */
class CatalogPriceChanges
{
    public const MAX_PRODUCTS = 5000;

    public const MAX_VARIANTS = 25000;

    private const MAX_PRICE = '999999999.99';

    /** @param array<string, mixed> $input */
    public function preview(array $input): array
    {
        $plan = $this->plan($input, false);

        return ['product_count' => $plan['product_count'], 'variant_count' => $plan['variant_count'],
            'out_of_range' => $plan['out_of_range'], 'compare_below_price' => $plan['compare_below_price'],
            'fingerprint' => $plan['fingerprint'], 'samples' => array_slice($plan['samples'], 0, 25)];
    }

    /** @param array<string, mixed> $input */
    public function apply(array $input, Store $store, User $actor): StorePriceChange
    {
        $change = DB::transaction(function () use ($input, $store, $actor) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $previous = StorePriceChange::query()->where('idempotency_key', $input['idempotency_key'])->first();
            if ($previous) {
                abort_unless(hash_equals((string) ($previous->operation['fingerprint'] ?? ''), $input['fingerprint']), 409, 'This request key was already used for a different price change.');

                return $previous;
            }
            $plan = $this->plan($input, true);
            if (! hash_equals($plan['fingerprint'], $input['fingerprint'])) {
                abort(409, 'Prices or products changed since the preview. Preview the change again before applying it.');
            }
            if ($plan['product_count'] === 0) {
                throw ValidationException::withMessages(['scope' => 'No products match this price change.']);
            }
            if ($plan['out_of_range'] > 0) {
                throw ValidationException::withMessages(['value' => 'Some prices would fall below zero or exceed the maximum price. Adjust the change and preview again.']);
            }
            $change = StorePriceChange::create(['store_id' => $store->id, 'actor_id' => $actor->id, 'idempotency_key' => $input['idempotency_key'], 'status' => 'applied',
                'scope' => $plan['scope'], 'operation' => $this->operation($input) + ['fingerprint' => $plan['fingerprint']],
                'product_count' => $plan['product_count'], 'variant_count' => $plan['variant_count']]);
            foreach (array_chunk($plan['writes'], 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $write) {
                    $rows[] = ['store_price_change_id' => $change->id, 'store_id' => $store->id, 'product_id' => $write['product_id'], 'variant_id' => $write['variant_id'],
                        'old_price' => $write['old_price'], 'new_price' => $write['new_price'], 'old_compare_price' => $write['old_compare_price'], 'new_compare_price' => $write['new_compare_price']];
                    $this->write($write['variant_id'], $write['product_id'], $write['new_price'], $write['new_compare_price']);
                }
                StorePriceChangeItem::query()->insert($rows);
            }
            $this->flush($store);

            return $change;
        });

        return $change->load('actor:id,name');
    }

    /** Restores the recorded prices; a price edited after the change is left alone and reported. */
    public function revert(int $id, Store $store, User $actor): StorePriceChange
    {
        $change = DB::transaction(function () use ($id, $store, $actor) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $change = StorePriceChange::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($change->status === 'reverted') {
                return $change;
            }
            $restored = $skipped = 0;
            StorePriceChangeItem::query()->where('store_price_change_id', $change->id)->orderBy('id')->chunkById(500, function ($items) use (&$restored, &$skipped) {
                $products = Product::query()->whereIn('id', $items->pluck('product_id')->unique()->all())->lockForUpdate()->get()->keyBy('id');
                $variantIds = $items->pluck('variant_id')->filter()->unique()->all();
                $variants = $variantIds === [] ? collect() : ProductVariant::query()->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');
                foreach ($items as $item) {
                    $model = $item->variant_id ? $variants->get($item->variant_id) : $products->get($item->product_id);
                    if (! $model) {
                        $skipped++;

                        continue;
                    }
                    $price = $item->variant_id ? $model->price_override : $model->price;
                    if (! $this->same($price, $item->new_price) || ! $this->same($model->compare_price, $item->new_compare_price)) {
                        $skipped++;

                        continue;
                    }
                    $this->write($item->variant_id, $item->product_id, $this->money($item->old_price), $this->money($item->old_compare_price));
                    $restored++;
                }
            });
            $change->update(['status' => 'reverted', 'reverted_by' => $actor->id, 'reverted_at' => now(), 'revert_result' => ['restored' => $restored, 'skipped' => $skipped]]);
            $this->flush($store);

            return $change;
        });

        return $change->load('actor:id,name');
    }

    public function present(StorePriceChange $change): array
    {
        return ['id' => $change->id, 'status' => $change->status, 'scope' => $change->scope,
            'operation' => array_diff_key($change->operation ?? [], ['fingerprint' => true]),
            'product_count' => $change->product_count, 'variant_count' => $change->variant_count,
            'actor' => $change->actor?->name, 'created_at' => $change->created_at, 'reverted_at' => $change->reverted_at, 'revert_result' => $change->revert_result];
    }

    /** @param array<string, mixed> $input */
    private function plan(array $input, bool $lock): array
    {
        [$products, $scope] = $this->selection($input['scope'], $lock);
        if ($products->count() > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['scope' => 'A price change supports at most '.self::MAX_PRODUCTS.' products. Choose a smaller category or selection.']);
        }
        $applyCompare = (bool) ($input['apply_compare'] ?? false);
        $applyVariants = (bool) ($input['apply_variants'] ?? false);
        $variants = $applyVariants && $products->isNotEmpty()
            ? ProductVariant::query()->whereIn('store_product_id', $products->modelKeys())->where(fn ($q) => $q->whereNotNull('price_override')->orWhereNotNull('compare_price'))
                ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->groupBy('store_product_id')
            : collect();
        if ($variants->sum(fn ($rows) => $rows->count()) > self::MAX_VARIANTS) {
            throw ValidationException::withMessages(['scope' => 'A price change supports at most '.self::MAX_VARIANTS.' option prices. Choose a smaller category or selection.']);
        }
        $writes = $samples = [];
        $fingerprint = [$this->operation($input), $scope];
        $outOfRange = $compareBelow = $variantCount = 0;
        foreach ($products as $product) {
            $price = $this->money($product->price) ?? '0.00';
            $compare = $this->money($product->compare_price);
            $newPrice = $this->adjust($price, $input);
            $newCompare = $applyCompare && $compare !== null ? $this->adjust($compare, $input) : $compare;
            $outOfRange += (int) ($newPrice === null) + (int) ($applyCompare && $compare !== null && $newCompare === null);
            if ($newPrice !== null && $newCompare !== null && bccomp($newCompare, $newPrice, 2) <= 0 && ($compare === null || bccomp($compare, $price, 2) > 0)) {
                $compareBelow++;
            }
            $entry = [$product->id, $price, $compare, []];
            $writes[] = ['product_id' => $product->id, 'variant_id' => null, 'old_price' => $price, 'new_price' => $newPrice ?? $price,
                'old_compare_price' => $compare, 'new_compare_price' => $newCompare ?? $compare];
            $changedVariants = 0;
            foreach ($variants->get($product->id, collect()) as $variant) {
                $override = $this->money($variant->price_override);
                $variantCompare = $this->money($variant->compare_price);
                $nextOverride = $override !== null ? $this->adjust($override, $input) : null;
                $nextCompare = $applyCompare && $variantCompare !== null ? $this->adjust($variantCompare, $input) : $variantCompare;
                if ($override === null && ! ($applyCompare && $variantCompare !== null)) {
                    continue;
                }
                $outOfRange += (int) ($override !== null && $nextOverride === null) + (int) ($applyCompare && $variantCompare !== null && $nextCompare === null);
                $entry[3][] = [$variant->id, $override, $variantCompare];
                $writes[] = ['product_id' => $product->id, 'variant_id' => $variant->id, 'old_price' => $override, 'new_price' => $override === null ? null : ($nextOverride ?? $override),
                    'old_compare_price' => $variantCompare, 'new_compare_price' => $nextCompare ?? $variantCompare];
                $changedVariants++;
            }
            $variantCount += $changedVariants;
            $fingerprint[] = $entry;
            if (count($samples) < 25) {
                $samples[] = ['id' => $product->id, 'name' => $product->name, 'price' => $price, 'new_price' => $newPrice,
                    'compare_price' => $compare, 'new_compare_price' => $applyCompare ? $newCompare : $compare, 'variants' => $changedVariants];
            }
        }

        return ['product_count' => $products->count(), 'variant_count' => $variantCount, 'out_of_range' => $outOfRange, 'compare_below_price' => $compareBelow,
            'fingerprint' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)), 'samples' => $samples, 'writes' => $writes, 'scope' => $scope];
    }

    /** @return array{0: Collection<int, Product>, 1: array<string, mixed>} */
    private function selection(array $scope, bool $lock): array
    {
        if ($scope['type'] === 'category') {
            $category = Category::query()->whereKey($scope['category_id'])->first();
            if (! $category) {
                throw ValidationException::withMessages(['scope.category_id' => 'Choose a category from this store.']);
            }
            $query = Product::query()->inCategories([$category->id]);
            $described = ['type' => 'category', 'category_id' => $category->id, 'category_name' => $category->name];
        } else {
            $ids = array_values(array_unique(array_map('intval', $scope['product_ids'])));
            $query = Product::query()->whereIn('id', $ids);
            $described = ['type' => 'products', 'product_ids' => $ids];
        }
        $products = $query->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get(['id', 'store_id', 'name', 'price', 'compare_price']);
        if ($scope['type'] === 'products' && $products->count() !== count($described['product_ids'])) {
            throw ValidationException::withMessages(['scope.product_ids' => 'Refresh the list: a selected product is unavailable.']);
        }

        return [$products, $described];
    }

    /** @param array<string, mixed> $input */
    private function operation(array $input): array
    {
        return ['direction' => $input['direction'], 'kind' => $input['kind'], 'value' => $this->decimal((string) $input['value'], 4),
            'apply_compare' => (bool) ($input['apply_compare'] ?? false), 'apply_variants' => (bool) ($input['apply_variants'] ?? false),
            'rounding' => $input['rounding'] ?? 'none'];
    }

    /** New price as a 2-decimal string, or null when it would leave the allowed range. */
    private function adjust(string $price, array $input): ?string
    {
        $value = $this->decimal((string) $input['value'], 4);
        $delta = $input['kind'] === 'percent' ? bcdiv(bcmul($price, $value, 8), '100', 8) : $value;
        $next = $input['direction'] === 'increase' ? bcadd($price, $delta, 8) : bcsub($price, $delta, 8);
        if (bccomp($next, '0', 8) < 0) {
            return null;
        }
        $next = match ($input['rounding'] ?? 'none') {
            'round' => bcadd(bcadd($next, '0.5', 8), '0', 0),
            'floor' => bcadd($next, '0', 0),
            'ceil' => bccomp(bcadd($next, '0', 0), $next, 8) === 0 ? bcadd($next, '0', 0) : bcadd(bcadd($next, '0', 0), '1', 0),
            default => bcadd(bcadd($next, '0.005', 8), '0', 2),
        };
        $next = bcadd($next, '0', 2);

        return bccomp($next, self::MAX_PRICE, 2) > 0 ? null : $next;
    }

    private function write(?int $variantId, int $productId, ?string $price, ?string $compare): void
    {
        if ($variantId) {
            ProductVariant::query()->whereKey($variantId)->update(['price_override' => $price, 'compare_price' => $compare, 'updated_at' => now()]);
        } else {
            Product::query()->whereKey($productId)->update(['price' => $price ?? '0.00', 'compare_price' => $compare]);
        }
    }

    private function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal((string) $value, 2);
    }

    private function decimal(string $value, int $scale): string
    {
        return bcadd(is_numeric($value) ? $value : '0', '0', $scale);
    }

    private function same(mixed $current, mixed $expected): bool
    {
        $current = $this->money($current);
        $expected = $this->money($expected);

        return $current === null || $expected === null ? $current === $expected : bccomp($current, $expected, 2) === 0;
    }

    private function flush(Store $store): void
    {
        DB::afterCommit(function () use ($store) {
            StorefrontService::forgetHomepage((int) $store->id);
            app(StorefrontPageCache::class)->flushStore((int) $store->id);
        });
    }
}

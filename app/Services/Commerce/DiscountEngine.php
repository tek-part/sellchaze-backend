<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Services\Commerce\Apps\ProductBundles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One place that decides which discounts an order gets, for the live quote, the cart and the
 * placed order alike.
 *
 * Rules (Shopify defaults): an entered code wins; otherwise the best automatic discount applies.
 * Product/order discounts never stack with each other; a free-shipping discount combines with
 * either. Amounts act only on the products in a discount's scope and never exceed the subtotal.
 */
class DiscountEngine
{
    public function __construct(private readonly CouponService $coupons) {}

    /**
     * @param  list<array{product_id: int, quantity: int, unit_price: string}>  $lines
     * @param  array{phone?: ?string, email?: ?string}  $guest  contact of a guest checkout
     * @return array{discount: string, free_shipping: bool, coupon: ?Coupon, applied: list<array{id: int, title: string, code: ?string, method: string, type: string, amount: string, bundle_id?: string}>, usages: list<array{coupon: Coupon, amount: string}>}
     */
    public function evaluate(Store $store, array $lines, ?Coupon $coupon, ?StoreCustomer $customer, array $guest = [], bool $lock = false): array
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, bcmul($line['unit_price'], (string) $line['quantity'], 2), 2);
        }
        $categories = $this->categories($store, array_map(fn (array $line) => (int) $line['product_id'], $lines));

        $order = null;
        $shipping = null;
        if ($coupon !== null) {
            $this->coupons->validate($coupon, $customer, $subtotal, $guest);
            $this->assertApplies($coupon, $lines, $categories);
            $entry = ['coupon' => $coupon, 'amount' => $this->amount($coupon, $lines, $categories)];
            $coupon->type === 'free_shipping' ? $shipping = $entry : $order = $entry;
        }

        $now = now();
        $automatic = Coupon::query()
            ->where('store_id', $store->id)
            ->where('method', 'automatic')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
        foreach ($automatic as $candidate) {
            if (! $this->eligible($candidate, $lines, $categories, $customer, $subtotal, $guest)) {
                continue;
            }
            if ($candidate->type === 'free_shipping') {
                $shipping ??= ['coupon' => $candidate, 'amount' => '0.00'];

                continue;
            }
            if ($coupon !== null && $coupon->type !== 'free_shipping') {
                continue; // an entered code is the order's discount
            }
            $amount = $this->amount($candidate, $lines, $categories);
            if (bccomp($amount, '0.00', 2) > 0 && ($order === null || bccomp($amount, $order['amount'], 2) > 0)) {
                $order = ['coupon' => $candidate, 'amount' => $amount];
            }
        }

        // A complete product bundle competes with automatic discounts; an entered code still wins.
        if ($coupon === null || $coupon->type === 'free_shipping') {
            $bundle = app(ProductBundles::class)->discount($store, $lines);
            if ($bundle !== null && ($order === null || bccomp($bundle['amount'], $order['amount'], 2) > 0)) {
                $order = ['bundle' => $bundle, 'amount' => $bundle['amount']];
            }
        }

        $entries = array_values(array_filter([$order, $shipping]));
        $discount = $order === null ? '0.00' : (bccomp($order['amount'], $subtotal, 2) > 0 ? $subtotal : $order['amount']);
        if ($order !== null) {
            $entries[0]['amount'] = $discount;
        }
        $usages = array_values(array_filter($entries, fn (array $entry) => isset($entry['coupon'])));

        return [
            'discount' => $discount,
            'free_shipping' => $shipping !== null,
            'coupon' => $coupon,
            'applied' => array_map(fn (array $entry) => isset($entry['bundle']) ? [
                'id' => 0,
                'bundle_id' => $entry['bundle']['id'],
                'title' => $entry['bundle']['title'],
                'code' => null,
                'method' => 'automatic',
                'type' => 'bundle',
                'amount' => $entry['amount'],
            ] : [
                'id' => (int) $entry['coupon']->id,
                'title' => $entry['coupon']->label(),
                'code' => $entry['coupon']->method === 'code' ? $entry['coupon']->code : null,
                'method' => (string) $entry['coupon']->method,
                'type' => (string) $entry['coupon']->type,
                'amount' => $entry['amount'],
            ], $entries),
            'usages' => $usages,
        ];
    }

    /**
     * Cart lines in the engine's shape.
     *
     * @param  iterable<object{store_product_id: int, quantity: int, unit_price: mixed}>  $items
     * @return list<array{product_id: int, quantity: int, unit_price: string}>
     */
    public static function linesFromCart(iterable $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = ['product_id' => (int) $item->store_product_id, 'quantity' => (int) $item->quantity, 'unit_price' => (string) $item->unit_price];
        }

        return $lines;
    }

    /**
     * An evaluation without its free-shipping discount, for an order whose delivery costs nothing
     * anyway: nothing was waived, so the discount is neither reported nor used up.
     *
     * @param  array{discount: string, free_shipping: bool, coupon: ?Coupon, applied: list<array<string, mixed>>, usages: list<array{coupon: Coupon, amount: string}>}  $result
     * @return array{discount: string, free_shipping: bool, coupon: ?Coupon, applied: list<array<string, mixed>>, usages: list<array{coupon: Coupon, amount: string}>}
     */
    public static function withoutFreeShipping(array $result): array
    {
        $result['free_shipping'] = false;
        $result['applied'] = array_values(array_filter($result['applied'], fn (array $entry) => $entry['type'] !== 'free_shipping'));
        $result['usages'] = array_values(array_filter($result['usages'], fn (array $usage) => $usage['coupon']->type !== 'free_shipping'));

        return $result;
    }

    /** The discount amount for one discount on these lines (0.00 for free shipping). */
    public function amount(Coupon $coupon, array $lines, array $categories): string
    {
        if ($coupon->type === 'free_shipping') {
            return '0.00';
        }
        if ($coupon->type === 'buy_x_get_y') {
            return $this->buyXGetY((array) $coupon->bxgy, $lines, $categories);
        }
        $eligible = $this->scopedSubtotal($coupon->applies_to, $coupon->target_ids, $lines, $categories);
        $raw = $coupon->type === 'percentage'
            ? $this->percentOf($eligible, (string) $coupon->value)
            : (string) $coupon->value;

        return bccomp($raw, $eligible, 2) > 0 ? $eligible : $raw;
    }

    private function eligible(Coupon $coupon, array $lines, array $categories, ?StoreCustomer $customer, string $subtotal, array $guest): bool
    {
        try {
            $this->coupons->validate($coupon, $customer, $subtotal, $guest);
            $this->assertApplies($coupon, $lines, $categories);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** Scope and minimum-quantity rules that a cart must meet before a discount can apply. */
    private function assertApplies(Coupon $coupon, array $lines, array $categories): void
    {
        [$scope, $ids] = $coupon->type === 'buy_x_get_y'
            ? [(string) ($coupon->bxgy['buy_applies_to'] ?? 'all'), (array) ($coupon->bxgy['buy_ids'] ?? [])]
            : [(string) $coupon->applies_to, (array) ($coupon->target_ids ?? [])];
        $quantity = 0;
        foreach ($lines as $line) {
            if ($this->inScope($scope, $ids, (int) $line['product_id'], $categories)) {
                $quantity += (int) $line['quantity'];
            }
        }
        if ($quantity === 0) {
            throw ValidationException::withMessages(['coupon' => 'This discount does not apply to the items in your cart.']);
        }
        if ($coupon->minimum_quantity !== null && $quantity < $coupon->minimum_quantity) {
            throw ValidationException::withMessages(['coupon' => "Add at least {$coupon->minimum_quantity} eligible items to use this discount."]);
        }
        if ($coupon->type === 'buy_x_get_y' && bccomp($this->amount($coupon, $lines, $categories), '0.00', 2) <= 0) {
            $buy = (int) ($coupon->bxgy['buy_quantity'] ?? 1);
            $get = (int) ($coupon->bxgy['get_quantity'] ?? 1);
            throw ValidationException::withMessages(['coupon' => "Buy {$buy} and add {$get} eligible items to get this offer."]);
        }
    }

    private function scopedSubtotal(?string $scope, ?array $ids, array $lines, array $categories): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            if ($this->inScope((string) $scope, (array) $ids, (int) $line['product_id'], $categories)) {
                $total = bcadd($total, bcmul($line['unit_price'], (string) $line['quantity'], 2), 2);
            }
        }

        return $total;
    }

    /**
     * Buy X items from one scope, get Y items from another at a percentage off (100 = free).
     * As many sets apply as the cart can fill (up to the per-order limit). The cheapest eligible
     * items are discounted, chosen first; each set's paid items come from what is left, so a unit
     * is never counted on both sides and an item in both scopes is given away only while enough
     * remain to pay for every set. Works on whole lines rather than single units, so a large cart
     * costs no more than a small one.
     */
    private function buyXGetY(array $config, array $lines, array $categories): string
    {
        $buyQuantity = max(1, (int) ($config['buy_quantity'] ?? 1));
        $getQuantity = max(1, (int) ($config['get_quantity'] ?? 1));
        $percent = min(100, max(1, (int) ($config['get_percent'] ?? 100)));
        $maxSets = isset($config['max_uses_per_order']) ? max(1, (int) $config['max_uses_per_order']) : PHP_INT_MAX;

        // Get-eligible lines, split by whether they could also pay; buy-only units are just counted.
        $getOnly = [];
        $both = [];
        $buyOnlyUnits = 0;
        foreach ($lines as $line) {
            $quantity = min(999, (int) $line['quantity']);
            if ($quantity < 1) {
                continue;
            }
            $buys = $this->inScope((string) ($config['buy_applies_to'] ?? 'all'), (array) ($config['buy_ids'] ?? []), (int) $line['product_id'], $categories);
            $gets = $this->inScope((string) ($config['get_applies_to'] ?? 'all'), (array) ($config['get_ids'] ?? []), (int) $line['product_id'], $categories);
            $bucket = ['price' => (string) $line['unit_price'], 'quantity' => $quantity];
            if ($gets && $buys) {
                $both[] = $bucket;
            } elseif ($gets) {
                $getOnly[] = $bucket;
            } elseif ($buys) {
                $buyOnlyUnits += $quantity;
            }
        }
        $getOnlyUnits = array_sum(array_column($getOnly, 'quantity'));
        $bothUnits = array_sum(array_column($both, 'quantity'));

        $sets = min($maxSets,
            intdiv($getOnlyUnits + $bothUnits, $getQuantity),
            intdiv($buyOnlyUnits + $bothUnits, $buyQuantity),
            intdiv($getOnlyUnits + $buyOnlyUnits + $bothUnits, $buyQuantity + $getQuantity));
        if ($sets < 1) {
            return '0.00';
        }
        $cheapestFirst = fn (array $a, array $b) => bccomp($a['price'], $b['price'], 2);
        usort($getOnly, $cheapestFirst);
        usort($both, $cheapestFirst);

        $needed = $sets * $getQuantity;
        // Units in both scopes that can be discounted while every set still has enough units to pay.
        $bothSpare = $buyOnlyUnits + $bothUnits - $sets * $buyQuantity;
        $discount = '0.00';
        $i = 0;
        $j = 0;
        while ($needed > 0) {
            $takeBoth = $j < count($both) && $bothSpare > 0
                && ($i >= count($getOnly) || bccomp($both[$j]['price'], $getOnly[$i]['price'], 2) < 0);
            if ($takeBoth) {
                $take = min($needed, $both[$j]['quantity'], $bothSpare);
                $price = $both[$j++]['price'];
                $bothSpare -= $take;
            } elseif ($i < count($getOnly)) {
                $take = min($needed, $getOnly[$i]['quantity']);
                $price = $getOnly[$i++]['price'];
            } else {
                break;
            }
            $needed -= $take;
            $discount = bcadd($discount, $this->percentOf(bcmul($price, (string) $take, 2), (string) $percent), 2);
        }

        return $discount;
    }

    /** A percentage of an amount, rounded half-up to cents (bcmath alone would truncate 4.9995 to 4.99). */
    private function percentOf(string $amount, string $percent): string
    {
        return bcadd(bcdiv(bcmul($amount, $percent, 6), '100', 6), '0.005', 2);
    }

    private function inScope(string $scope, array $ids, int $productId, array $categories): bool
    {
        $ids = array_map('intval', $ids);

        return match ($scope) {
            'products' => in_array($productId, $ids, true),
            'categories' => array_intersect($categories[$productId] ?? [], $ids) !== [],
            default => true,
        };
    }

    /**
     * Each product's categories: its primary category plus any extra store categories.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<int>>
     */
    private function categories(Store $store, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return [];
        }
        $map = [];
        foreach (DB::table('products')->where('store_id', $store->id)->whereIn('id', $productIds)->whereNotNull('category_id')->get(['id', 'category_id']) as $row) {
            $map[(int) $row->id][] = (int) $row->category_id;
        }
        foreach (DB::table('store_product_categories')->where('store_id', $store->id)->whereIn('product_id', $productIds)->get(['product_id', 'category_id']) as $row) {
            $map[(int) $row->product_id][] = (int) $row->category_id;
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $map);
    }
}

<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\StoreCatalog\ProductLandingPages;
use Illuminate\Validation\ValidationException;

/** Per-product presentation overrides; prices, reservations and order totals remain authoritative. */
final class ProductPreferences
{
    public const FLAGS = ['is_skip_cart', 'is_fixed_bottom_buy', 'is_reviews_enabled', 'is_one_page_checkout', 'is_free_shipping',
        'is_fake_visitors', 'is_fake_stock', 'is_fake_timer', 'is_header_hidden', 'is_quantity_hidden',
        'is_checkout_before_description', 'hide_related_products'];

    public static function rules(): array
    {
        $keys = array_merge(self::FLAGS, ['buy_now_text', 'fake_visitors_min', 'fake_visitors_max', 'fake_stock_count', 'fake_timer_hours', 'pixel_price']);
        $rules = ['product_preferences' => ['sometimes', 'nullable', 'array:'.implode(',', $keys)],
            'preferences_version' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'display_priority' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'catalog_hidden' => ['sometimes', 'boolean'],
            'product_preferences.buy_now_text' => ['nullable', 'string', 'max:120'],
            'product_preferences.pixel_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2']];
        foreach (self::FLAGS as $key) {
            $rules['product_preferences.'.$key] = ['sometimes', 'nullable', 'boolean'];
        }
        foreach (['fake_visitors_min', 'fake_visitors_max', 'fake_stock_count', 'fake_timer_hours'] as $key) {
            $rules['product_preferences.'.$key] = ['sometimes', 'nullable', 'integer', 'min:'.($key === 'fake_timer_hours' ? '1' : '0'), 'max:'.($key === 'fake_timer_hours' ? '720' : '1000000')];
        }

        return $rules;
    }

    public static function canonical(?array $input): ?array
    {
        $data = array_filter($input ?? [], fn ($value) => $value !== null && $value !== '');
        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = (bool) $data[$key];
            }
        }
        foreach (['fake_visitors_min', 'fake_visitors_max', 'fake_stock_count', 'fake_timer_hours'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (isset($data['pixel_price'])) {
            $data['pixel_price'] = bcadd((string) $data['pixel_price'], '0', 2);
        }
        if (($data['fake_visitors_min'] ?? 20) > ($data['fake_visitors_max'] ?? 70)) {
            throw ValidationException::withMessages(['product_preferences.fake_visitors_max' => 'The maximum visitor count must not be lower than the minimum.']);
        }
        if (($data['is_checkout_before_description'] ?? false) && ! ($data['is_one_page_checkout'] ?? false)) {
            throw ValidationException::withMessages(['product_preferences.is_checkout_before_description' => 'Enable same-page checkout before moving its form above the description.']);
        }
        ksort($data);

        return $data ?: null;
    }

    public static function version(Product $product): string
    {
        $data = $product->product_preferences ?? [];
        ksort($data);

        return hash('sha256', json_encode([$data, (int) $product->display_priority, (bool) $product->catalog_hidden], JSON_THROW_ON_ERROR));
    }

    public static function flag(Product $product, string $key): bool
    {
        return ($product->product_preferences[$key] ?? null) === true;
    }

    public static function onePage(Product $product): bool
    {
        return self::flag($product, 'is_one_page_checkout') || ProductLandingPages::hasCheckout($product);
    }

    public static function publicPayload(Product $product): array
    {
        $data = $product->product_preferences ?? [];
        unset($data['pixel_price']);

        return $data;
    }

    /** A product promise covers its own shipping only; mixed physical baskets retain the delivery fee. */
    public static function freeForCart(Cart $cart): bool
    {
        $ids = $cart->items->pluck('store_product_id')->unique();
        $products = Product::query()->where('store_id', $cart->store_id)->whereIn('id', $ids)->get();
        if ($products->count() !== $ids->count()) {
            return false;
        }
        $physical = $products->where('digital_type', 'physical');

        return $physical->isNotEmpty() && $physical->every(fn (Product $product) => self::flag($product, 'is_free_shipping'));
    }
}

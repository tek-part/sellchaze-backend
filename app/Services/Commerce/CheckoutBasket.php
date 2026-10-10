<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Read checkout requirements without creating, merging or changing a cart. */
class CheckoutBasket
{
    /** @return array{requires_shipping:bool,has_digital:bool,requires_whatsapp:bool} */
    public function fromIds(Store $store, array $ids, bool $strict = false): array
    {
        $fallback = ['requires_shipping' => true, 'has_digital' => false, 'requires_whatsapp' => false];
        if ($ids === [] || count($ids) > 100 || collect($ids)->contains(fn ($id) => ! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id < 1)) {
            return $fallback;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $products = Product::query()->where('store_id', $store->id)->where('is_active', true)->whereIn('id', $ids)->get();
        if ($products->count() !== count($ids)) {
            if ($strict) {
                throw ValidationException::withMessages(['product_ids' => 'One or more products are not available.']);
            }

            return $fallback;
        }

        return ['requires_shipping' => $products->contains(fn (Product $product) => $product->digital_type === 'physical'),
            'has_digital' => $products->contains(fn (Product $product) => $product->digital_type !== 'physical'),
            'requires_whatsapp' => $products->contains(function (Product $product) use ($store): bool {
                $settings = app(DigitalDeliverySettings::class)->effective($store, (int) $product->id);

                return $product->digital_type !== 'physical' && $settings['enabled'] && $settings['whatsapp_enabled'];
            })];
    }

    /** @return array{requires_shipping:bool,has_digital:bool,requires_whatsapp:bool} */
    public function forRequest(Store $store, Request $request): array
    {
        if ($request->has('items')) {
            $items = $request->input('items');

            return $this->fromIds($store, is_array($items) ? array_column($items, 'product_id') : []);
        }
        $guest = app(CartService::class)->findByToken($request->header(CartService::TOKEN_HEADER));
        $customer = app(CustomerAuthService::class)->resolve($request);
        $cart = $customer ? Cart::query()->where('store_id', $store->id)->where('store_customer_id', $customer->id)->where('status', 'active')->latest('id')->first() : null;
        // Customer checkout merges the guest cart into the account cart. Inspect
        // both here so those additional lines cannot bypass shipping requirements.
        $ids = [];
        foreach ([$cart, $guest] as $existing) {
            if ($existing && (int) $existing->store_id === (int) $store->id) {
                $ids = array_merge($ids, $existing->items()->pluck('store_product_id')->all());
            }
        }

        return $this->fromIds($store, $ids);
    }
}

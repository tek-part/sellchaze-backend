<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 cart engine. Every query runs inside the CurrentStore tenant
 * (StoreScope), so a cart or item can never cross a store boundary. A cart is
 * owned by a logged-in StoreCustomer or by a guest identified by an opaque
 * token carried in the `X-Cart-Token` header.
 */
class CartService
{
    public const TOKEN_HEADER = 'X-Cart-Token';

    /**
     * Find or create the active cart for this request. When a customer is
     * given, their cart is authoritative and any guest cart (by token) is
     * merged into it.
     */
    public function resolve(Request $request, Store $store, ?StoreCustomer $customer): Cart
    {
        $guestCart = $this->findByToken($request->header(self::TOKEN_HEADER));

        if ($customer !== null) {
            $cart = Cart::query()
                ->where('store_customer_id', $customer->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if ($cart === null) {
                $cart = $this->create($store, $customer);
            }
            if ($guestCart && $guestCart->id !== $cart->id) {
                $this->merge($guestCart, $cart);
            }

            return $cart->load('items');
        }

        return ($guestCart ?? $this->create($store, null))->load('items');
    }

    public function findByToken(?string $token): ?Cart
    {
        if (! $token) {
            return null;
        }

        return Cart::query()->where('token', $token)->where('status', 'active')->first();
    }

    public function create(Store $store, ?StoreCustomer $customer): Cart
    {
        return Cart::create([
            'store_customer_id' => $customer?->id,
            'token' => (string) Str::uuid(),
            'currency' => $store->currency ?: 'USD',
            'status' => 'active',
        ]);
    }

    /**
     * Add a product to the cart, snapshotting name + price. Existing lines have
     * their quantity increased. Fails closed if the product is not a live,
     * purchasable product of this store.
     */
    public function addItem(Cart $cart, int $productId, int $quantity): CartItem
    {
        $quantity = max(1, $quantity);
        $product = $this->purchasableProduct($productId);

        $item = $cart->items()->where('store_product_id', $product->id)->first();

        if ($item) {
            $item->quantity += $quantity;
            $item->unit_price = $product->price; // refresh snapshot to current price
            $item->name = $product->name;
            $item->save();

            return $item;
        }

        return $cart->items()->create([
            'store_product_id' => $product->id,
            'name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => $quantity,
        ]);
    }

    public function updateItem(Cart $cart, CartItem $item, int $quantity): ?CartItem
    {
        $this->assertItemInCart($cart, $item);

        if ($quantity <= 0) {
            $item->delete();

            return null;
        }

        $item->quantity = $quantity;
        $item->save();

        return $item;
    }

    public function removeItem(Cart $cart, CartItem $item): void
    {
        $this->assertItemInCart($cart, $item);
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    /** Move guest-cart lines into the target cart, then abandon the guest cart. */
    private function merge(Cart $from, Cart $to): void
    {
        foreach ($from->items()->get() as $line) {
            $existing = $to->items()->where('store_product_id', $line->store_product_id)->first();
            if ($existing) {
                $existing->quantity += $line->quantity;
                $existing->save();
            } else {
                $to->items()->create([
                    'store_product_id' => $line->store_product_id,
                    'name' => $line->name,
                    'unit_price' => $line->unit_price,
                    'quantity' => $line->quantity,
                ]);
            }
        }
        $from->items()->delete();
        $from->update(['status' => 'abandoned']);
    }

    private function purchasableProduct(int $productId): Product
    {
        $product = Product::query()->where('is_active', true)->find($productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                'store_product_id' => 'This product is not available.',
            ]);
        }

        return $product;
    }

    private function assertItemInCart(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            abort(404, 'Item not found in this cart.');
        }
    }
}

<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Store;
use App\Models\StoreCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly PurchasableSelection $selections) {}

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
    public function addItem(Cart $cart, int $productId, int $quantity, ?int $variantId = null): CartItem
    {
        return DB::transaction(function () use ($cart, $productId, $quantity, $variantId) {
            // Serializes additions even for the base-product line (SQL unique indexes
            // permit repeated NULL variant IDs on both MySQL and SQLite).
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
            }
            $selection = $this->selections->resolve(Store::findOrFail($cart->store_id), $productId, $variantId);
            $item = $cart->items()->where('store_product_id', $productId)->where('variant_id', $variantId)->first();
            $count = ($item?->quantity ?? 0) + $quantity;
            if ($quantity < 1 || $count > 999) {
                throw ValidationException::withMessages(['quantity' => 'Choose between 1 and 999 items.']);
            }
            $values = ['store_product_id' => $productId, 'variant_id' => $variantId,
                'name' => $selection['name'], 'unit_price' => $selection['price'], 'quantity' => $count];
            if ($item) {
                $item->update($values);

                return $item;
            }

            return $cart->items()->create($values);
        });
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
        DB::transaction(function () use ($from, $to) {
            $locked = Cart::query()->whereIn('id', [$from->id, $to->id])->orderBy('id')->lockForUpdate()->get();
            if ($locked->count() !== 2 || $locked->contains(fn (Cart $cart) => $cart->status !== 'active')) {
                throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
            }
            foreach ($from->items()->get() as $line) {
                $existing = $to->items()->where('store_product_id', $line->store_product_id)->where('variant_id', $line->variant_id)->first();
                if ($existing) {
                    if ($existing->quantity + $line->quantity > 999) {
                        throw ValidationException::withMessages(['quantity' => 'Choose between 1 and 999 items.']);
                    }
                    $existing->quantity += $line->quantity;
                    $existing->save();
                } else {
                    $to->items()->create([
                        'store_product_id' => $line->store_product_id,
                        'variant_id' => $line->variant_id,
                        'name' => $line->name,
                        'unit_price' => $line->unit_price,
                        'quantity' => $line->quantity,
                    ]);
                }
            }
            $from->items()->delete();
            $from->update(['status' => 'abandoned']);
        });
    }

    private function assertItemInCart(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            abort(404, 'Item not found in this cart.');
        }
    }
}

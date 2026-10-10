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
    public function addItem(Cart $cart, int $productId, int $quantity, ?int $variantId = null, array $personalization = []): CartItem
    {
        return DB::transaction(function () use ($cart, $productId, $quantity, $variantId, $personalization) {
            // Serializes additions even for the base-product line (SQL unique indexes
            // permit repeated NULL variant IDs on both MySQL and SQLite).
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
            }
            $store = Store::findOrFail($cart->store_id);
            $selection = $this->selections->resolve($store, $productId, $variantId);
            app(OrderLimits::class)->assertQuantity($store, (int) $cart->items()->where('store_product_id', $productId)->sum('quantity') + $quantity);
            $custom = app(ProductPersonalization::class)->resolve($selection['product'], $personalization);
            $sameSku = $cart->items()->where('store_product_id', $productId)->where('variant_id', $variantId);
            $totalCount = (int) (clone $sameSku)->sum('quantity') + $quantity;
            $item = $sameSku->where('personalization_key', $custom['key'])->first();
            $count = ($item?->quantity ?? 0) + $quantity;
            if ($quantity < 1 || $totalCount > 999) {
                throw ValidationException::withMessages(['quantity' => 'Choose between 1 and 999 items.']);
            }
            app(StoreInventory::class)->assertAvailable($selection['variant'] ?? $selection['product'], $totalCount);
            app(DigitalProducts::class)->assertAvailable($selection['product'], (int) $cart->items()->where('store_product_id', $productId)->sum('quantity') + $quantity);
            $values = ['store_product_id' => $productId, 'variant_id' => $variantId,
                'personalization' => $custom['values'], 'personalization_key' => $custom['key'],
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
        return DB::transaction(function () use ($cart, $item, $quantity) {
            $this->lockActive($cart);
            $this->assertItemInCart($cart, $item);

            if ($quantity <= 0) {
                $item->delete();

                return null;
            }

            $store = Store::findOrFail($cart->store_id);
            $selection = $this->selections->resolve($store, $item->store_product_id, $item->variant_id);
            app(OrderLimits::class)->assertQuantity($store, (int) $cart->items()->where('store_product_id', $item->store_product_id)->whereKeyNot($item->id)->sum('quantity') + $quantity);
            app(ProductPersonalization::class)->resolve($selection['product'], $item->personalization ?? []);
            $total = (int) $cart->items()->where('store_product_id', $item->store_product_id)->where('variant_id', $item->variant_id)->whereKeyNot($item->id)->sum('quantity') + $quantity;
            app(StoreInventory::class)->assertAvailable($selection['variant'] ?? $selection['product'], $total);
            app(DigitalProducts::class)->assertAvailable($selection['product'], (int) $cart->items()->where('store_product_id', $item->store_product_id)->whereKeyNot($item->id)->sum('quantity') + $quantity);
            $item->quantity = $quantity;
            $item->save();

            return $item;
        });
    }

    public function removeItem(Cart $cart, CartItem $item): void
    {
        DB::transaction(function () use ($cart, $item) {
            $this->lockActive($cart);
            $this->assertItemInCart($cart, $item);
            $item->delete();
        });
    }

    public function clear(Cart $cart): void
    {
        DB::transaction(function () use ($cart) {
            $this->lockActive($cart);
            $cart->items()->delete();
        });
    }

    private function lockActive(Cart $cart): void
    {
        $current = Cart::query()->where('store_id', $cart->store_id)->whereKey($cart->id)->lockForUpdate()->firstOrFail();
        if ($current->status !== 'active') {
            throw ValidationException::withMessages(['cart' => 'This cart is no longer active.']);
        }
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
                $this->addItem($to, (int) $line->store_product_id, $line->quantity, $line->variant_id, $line->personalization ?? []);
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

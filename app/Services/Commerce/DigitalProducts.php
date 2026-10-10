<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Storefront\StorefrontService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DigitalProducts
{
    public static function rules(): array
    {
        return [
            'digital_type' => ['sometimes', 'required', 'in:physical,link,codes'],
            'digital_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2000'],
            'digital_codes' => ['sometimes', 'array', 'list', 'max:1000'],
            'digital_codes.*' => ['required', 'string', 'max:2000'],
        ];
    }

    public function configure(Product $product, array $data): void
    {
        $type = $data['digital_type'] ?? $product->digital_type ?? 'physical';
        if ($product->exists && $type !== $product->digital_type) {
            throw ValidationException::withMessages(['digital_type' => 'The product delivery type cannot change after creation.']);
        }
        if (($type !== 'link' && filled($data['digital_url'] ?? null)) || ($type !== 'codes' && ! empty($data['digital_codes']))) {
            throw ValidationException::withMessages(['digital_type' => 'Delivery data must match the product type.']);
        }
        $product->digital_type = $type;
        if (array_key_exists('digital_url', $data)) {
            $product->digital_url = $data['digital_url'];
        }
        if ($type === 'link' && ! filled($product->digital_url)) {
            throw ValidationException::withMessages(['digital_url' => 'Enter the digital delivery link.']);
        }
    }

    /** Caller holds the product lock and a transaction, also when replenishing codes. */
    public function appendCodes(Product $product, array $codes): void
    {
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '' || preg_match('/[\x00-\x1f\x7f]/u', $code)) {
                throw ValidationException::withMessages(['digital_codes' => 'Enter a nonempty code on each line, without control characters.']);
            }
            $digest = hash_hmac('sha256', $code, (string) config('app.key'));
            if ($this->codes($product)->where('digest', $digest)->exists()) {
                throw ValidationException::withMessages(['digital_codes' => 'A code already exists for this product. No codes were added.']);
            }
            ProductDigitalCode::create(['store_id' => $product->store_id, 'product_id' => $product->id, 'value' => $code, 'digest' => $digest]);
        }
    }

    public function available(Product $product): ?int
    {
        return $product->digital_type === 'codes' ? $this->codes($product)->whereNull('store_order_item_id')->count() : null;
    }

    public function sales(Product $product): int
    {
        return (int) StoreOrderItem::query()->where('store_id', $product->store_id)->where('store_product_id', $product->id)
            ->whereNotNull('digital_delivery')->whereHas('order', fn ($query) => $query->where('payment_status', 'paid')->where('status', '!=', 'cancelled'))->sum('quantity');
    }

    public function assertAvailable(Product $product, int $quantity): void
    {
        $available = $this->available($product);
        if ($available !== null && $available < $quantity) {
            throw ValidationException::withMessages(['items' => 'Not enough digital codes are available. Please review your cart.']);
        }
    }

    /** Checkout holds the product lock; code assignments and order creation are atomic. */
    public function reserve(Product $product, StoreOrderItem $item): void
    {
        if ($product->digital_type === 'physical') {
            return;
        }
        if ($product->digital_type === 'link') {
            $item->update(['digital_delivery' => ['type' => 'link', 'values' => [$product->digital_url]]]);

            return;
        }
        $codes = $this->codes($product)->whereNull('store_order_item_id')->orderBy('id')->limit($item->quantity)->lockForUpdate()->get();
        if ($codes->count() !== $item->quantity) {
            throw ValidationException::withMessages(['items' => 'Not enough digital codes are available.']);
        }
        foreach ($codes as $code) {
            $code->update(['store_order_item_id' => $item->id]);
        }
        $item->update(['digital_delivery' => ['type' => 'codes', 'values' => $codes->map(fn ($code) => $code->value)->all()]]);
        $this->flushAfterCommit($item->store_id);
    }

    /** Paid codes are never resold, including after a refund or cancellation. */
    public function cancel(StoreOrder $order): void
    {
        if ($order->payment_status === 'paid') {
            return;
        }
        $ids = $order->items()->whereNotNull('digital_delivery')->pluck('id');
        $changed = ProductDigitalCode::query()->where('store_id', $order->store_id)->whereIn('store_order_item_id', $ids)->update(['store_order_item_id' => null]);
        if ($changed > 0) {
            $this->flushAfterCommit($order->store_id);
        }
    }

    public static function present(StoreOrderItem $item, StoreOrder $order): ?array
    {
        $snapshot = $item->digital_delivery;
        if ($snapshot === null) {
            return null;
        }
        $released = $order->payment_status === 'paid' && $order->status !== 'cancelled';

        return ['type' => $snapshot['type'], 'status' => $order->status === 'cancelled' ? 'cancelled' : ($released ? 'ready' : 'awaiting_payment'), 'values' => $released ? $snapshot['values'] : []];
    }

    /** @return Builder<ProductDigitalCode> */
    private function codes(Product $product): Builder
    {
        return ProductDigitalCode::query()->where('store_id', $product->store_id)->where('product_id', $product->id);
    }

    private function flushAfterCommit(int $storeId): void
    {
        DB::afterCommit(function () use ($storeId) {
            StorefrontService::forgetHomepage($storeId);
            app(StorefrontPageCache::class)->flushStore($storeId);
        });
    }
}

<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDraftOrder;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft orders: merchant-built quotes priced by the same engines as checkout
 * (PurchasableSelection, DiscountEngine, StoreShipping, PricingCalculator).
 * Calculating never reserves stock; only {@see DraftOrderConversion} does.
 */
class DraftOrders
{
    public const MAX_INVOICE_DAYS = 90;

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $money = ['numeric', 'decimal:0,2', 'min:0', 'max:'.ManualStoreOrders::MAX_MONEY];

        return [
            'store_customer_id' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'array:name,email,phone'],
            'customer.name' => ['nullable', 'string', 'max:200'],
            'customer.email' => ['nullable', 'email', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'array:name,line1,line2,city,state,country,postal_code,phone_alt,national_address'],
            'shipping_address.*' => ['nullable', 'string', 'max:255'],
            'line_items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'line_items.*' => ['array:type,product_id,variant_id,title,quantity,unit_price,requires_shipping,sku'],
            'line_items.*.type' => ['required', 'in:product,custom'],
            'line_items.*.product_id' => ['required_if:line_items.*.type,product', 'nullable', 'integer', 'min:1'],
            'line_items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'line_items.*.title' => ['required_if:line_items.*.type,custom', 'nullable', 'string', 'max:255'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'line_items.*.unit_price' => ['required_if:line_items.*.type,custom', 'nullable', ...$money],
            'line_items.*.requires_shipping' => ['nullable', 'boolean'],
            'line_items.*.sku' => ['nullable', 'string', 'max:120'],
            'applied_discount' => ['nullable', 'array:type,code,value_type,value,reason'],
            'applied_discount.type' => ['required_with:applied_discount', 'in:code,manual'],
            'applied_discount.code' => ['required_if:applied_discount.type,code', 'nullable', 'string', 'max:100'],
            'applied_discount.value_type' => ['required_if:applied_discount.type,manual', 'nullable', 'in:fixed,percentage'],
            'applied_discount.value' => ['required_if:applied_discount.type,manual', 'nullable', ...$money],
            'applied_discount.reason' => ['nullable', 'string', 'max:255'],
            'shipping_line' => ['nullable', 'array:type,shipping_region_id,shipping_option_id,title,price'],
            'shipping_line.type' => ['required_with:shipping_line', 'in:rate,custom'],
            'shipping_line.shipping_region_id' => ['nullable', 'uuid'],
            'shipping_line.shipping_option_id' => ['nullable', 'uuid'],
            'shipping_line.title' => ['required_if:shipping_line.type,custom', 'nullable', 'string', 'max:120'],
            'shipping_line.price' => ['required_if:shipping_line.type,custom', 'nullable', ...$money],
            'note' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40', 'distinct'],
        ];
    }

    /**
     * Turn validated editor input into the stored draft shape. Product lines take the live
     * catalog price unless the merchant set an explicit unit price.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function normalize(Store $store, array $data): array
    {
        $customerId = isset($data['store_customer_id']) ? (int) $data['store_customer_id'] : null;
        if ($customerId !== null && ! StoreCustomer::query()->where('store_id', $store->id)->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages(['store_customer_id' => 'This customer is not available.']);
        }
        $lines = [];
        foreach (array_values($data['line_items']) as $index => $line) {
            $lines[] = $this->line($store, $line, $index);
        }
        $discount = null;
        if (! empty($data['applied_discount'])) {
            $input = $data['applied_discount'];
            if ($input['type'] === 'code') {
                $discount = ['type' => 'code', 'code' => mb_strtoupper(trim((string) $input['code']))];
            } else {
                $value = bcadd((string) $input['value'], '0', 2);
                if ($input['value_type'] === 'percentage' && bccomp($value, '100', 2) > 0) {
                    throw ValidationException::withMessages(['applied_discount.value' => 'A percentage discount cannot exceed 100.']);
                }
                $discount = ['type' => 'manual', 'value_type' => $input['value_type'], 'value' => $value, 'reason' => $this->text($input['reason'] ?? null)];
            }
        }
        $shipping = null;
        if (! empty($data['shipping_line'])) {
            $input = $data['shipping_line'];
            $shipping = $input['type'] === 'custom'
                ? ['type' => 'custom', 'title' => trim((string) $input['title']), 'price' => bcadd((string) $input['price'], '0', 2)]
                : ['type' => 'rate', 'shipping_region_id' => $input['shipping_region_id'] ?? null, 'shipping_option_id' => $input['shipping_option_id'] ?? null];
        }
        $customer = array_filter([
            'name' => $this->text($data['customer']['name'] ?? null),
            'email' => $this->text($data['customer']['email'] ?? null),
            'phone' => $this->text($data['customer']['phone'] ?? null),
        ], fn ($value) => $value !== null);
        $address = array_filter(array_map(fn ($value) => $this->text($value), $data['shipping_address'] ?? []), fn ($value) => $value !== null);
        $tags = array_values(array_unique(array_filter(array_map(fn ($tag) => $this->text($tag), $data['tags'] ?? []))));

        return [
            'store_customer_id' => $customerId,
            'customer' => $customer ?: null,
            'shipping_address' => $address ?: null,
            'line_items' => $lines,
            'applied_discount' => $discount,
            'shipping_line' => $shipping,
            'note' => $this->text($data['note'] ?? null),
            'tags' => $tags ?: null,
        ];
    }

    /**
     * Price a stored-shape draft. Validates availability and stock without reserving.
     *
     * @param  array<string,mixed>  $draft  normalized draft attributes
     * @return array{totals: array<string,mixed>, context: array<string,mixed>}
     */
    public function compute(Store $store, array $draft, bool $lock = false): array
    {
        $currency = $store->currency ?: 'USD';
        $subtotal = '0.00';
        $lines = [];
        $engineLines = [];
        $stockCounts = [];
        $productCounts = [];
        $requiresShipping = false;
        $hasDigital = false;
        $requiresWhatsapp = false;
        $allPhysicalFree = true;
        $physicalProducts = 0;
        $stocks = [];
        foreach (array_values($draft['line_items'] ?? []) as $index => $line) {
            $quantity = (int) $line['quantity'];
            $price = bcadd((string) $line['unit_price'], '0', 2);
            $stock = null;
            $product = null;
            if ($line['type'] === 'product') {
                $selection = app(PurchasableSelection::class)->resolve($store, (int) $line['product_id'], isset($line['variant_id']) ? (int) $line['variant_id'] : null, $lock);
                $product = $selection['product'];
                $stock = $selection['variant'] ?? $product;
                $productCounts[$product->id] = ($productCounts[$product->id] ?? 0) + $quantity;
                $stockKey = $product->id.':'.($selection['variant']?->id ?? 'base');
                $stockCounts[$stockKey] = ($stockCounts[$stockKey] ?? 0) + $quantity;
                $this->assertStock($stock, $stockCounts[$stockKey], $index, (string) $line['title']);
                if ($product->digital_type === 'physical') {
                    $requiresShipping = true;
                    $physicalProducts++;
                    $allPhysicalFree = $allPhysicalFree && ProductPreferences::flag($product, 'is_free_shipping');
                } else {
                    $hasDigital = true;
                    app(DigitalProducts::class)->assertAvailable($product, $productCounts[$product->id]);
                    $delivery = app(DigitalDeliverySettings::class)->effective($store, (int) $product->id);
                    $requiresWhatsapp = $requiresWhatsapp || ($delivery['enabled'] && $delivery['whatsapp_enabled']);
                }
            } else {
                $requiresShipping = $requiresShipping || (bool) ($line['requires_shipping'] ?? true);
                $allPhysicalFree = $allPhysicalFree && ! ($line['requires_shipping'] ?? true);
            }
            $lineTotal = bcmul($price, (string) $quantity, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $this->assertMoney($subtotal);
            $lines[] = array_replace($line, ['unit_price' => $price, 'line_total' => $lineTotal]);
            $engineLines[] = ['product_id' => $line['type'] === 'product' ? (int) $line['product_id'] : 0, 'quantity' => $quantity, 'unit_price' => $price];
            $stocks[$index] = ['product' => $product, 'stock' => $stock];
        }

        // Discounts: an entered code or the automatic discounts run through the checkout engine;
        // a manual merchant discount replaces both.
        $customer = isset($draft['store_customer_id']) ? StoreCustomer::query()->where('store_id', $store->id)->find($draft['store_customer_id']) : null;
        $applied = [];
        $usages = [];
        $freeShipping = false;
        $discount = '0.00';
        $input = $draft['applied_discount'] ?? null;
        if (($input['type'] ?? null) === 'manual') {
            $raw = $input['value_type'] === 'percentage'
                ? bcdiv(bcmul($subtotal, (string) $input['value'], 4), '100', 2)
                : bcadd((string) $input['value'], '0', 2);
            $discount = bccomp($raw, $subtotal, 2) > 0 ? $subtotal : $raw;
            $applied[] = ['id' => null, 'title' => $input['reason'] ?: 'Custom discount', 'code' => null, 'method' => 'manual',
                'type' => $input['value_type'], 'value' => $input['value'], 'amount' => $discount];
        } else {
            $coupon = null;
            if (($input['type'] ?? null) === 'code') {
                $coupon = Coupon::query()->where('store_id', $store->id)->where('method', 'code')->where('code', $input['code'])->where('is_active', true)
                    ->when($lock, fn ($query) => $query->lockForUpdate())->first();
                if ($coupon === null) {
                    throw ValidationException::withMessages(['applied_discount.code' => 'This discount code is not available.']);
                }
            }
            try {
                $result = app(DiscountEngine::class)->evaluate($store, $engineLines, $coupon, $customer, array_filter([
                    'phone' => app(OrderLimits::class)->normalizePhone($draft['customer']['phone'] ?? null, app(OrderLimits::class)->configured($store)['phone_country']),
                    'email' => $draft['customer']['email'] ?? null,
                ]), $lock);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(['applied_discount.code' => collect($error->errors())->flatten()->first() ?? 'This discount cannot be applied.']);
            }
            $discount = $result['discount'];
            $freeShipping = $result['free_shipping'];
            $applied = $result['applied'];
            $usages = $result['usages'];
        }

        $shippingTotal = '0.00';
        $shipping = null;
        $line = $draft['shipping_line'] ?? null;
        if ($requiresShipping && $line !== null) {
            if ($line['type'] === 'custom') {
                $shippingTotal = $freeShipping ? '0.00' : bcadd((string) $line['price'], '0', 2);
                $shipping = ['type' => 'custom', 'title' => $line['title'], 'base_rate' => bcadd((string) $line['price'], '0', 2), 'free_shipping_applied' => $freeShipping, 'details' => ['mode' => 'manual', 'base_rate' => bcadd((string) $line['price'], '0', 2)]];
            } else {
                try {
                    $quote = app(StoreShipping::class)->quote($store, bcsub($subtotal, $discount, 2), array_filter([
                        'shipping_region_id' => $line['shipping_region_id'] ?? null, 'shipping_option_id' => $line['shipping_option_id'] ?? null,
                    ]), $freeShipping, $physicalProducts > 0 && $allPhysicalFree);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(['shipping_line' => collect($error->errors())->flatten()->first() ?? 'Choose an available delivery rate.']);
                }
                $shippingTotal = $quote['amount'];
                $title = $quote['details']['option']['name'] ?? $quote['details']['region']['name'] ?? null;
                $shipping = ['type' => 'rate', 'title' => is_array($title) ? ($title[app()->getLocale()] ?? $title[$store->default_locale ?: 'en'] ?? $title['en'] ?? null) : $title,
                    'base_rate' => $quote['details']['base_rate'], 'free_shipping_applied' => $quote['details']['free_shipping_applied'], 'details' => $quote['details'], 'address' => $quote['address']];
            }
        }

        $priced = app(PricingCalculator::class)->forStore($store, $subtotal, $discount, [], false);
        $grand = bcadd($priced['grand_total'], $shippingTotal, 2);
        $this->assertMoney($grand);

        return [
            'totals' => [
                'currency' => $currency,
                'line_items' => $lines,
                'subtotal' => $subtotal,
                'discount_total' => $priced['discount_total'],
                'discounts' => $applied,
                'shipping_total' => $shippingTotal,
                'shipping' => $shipping,
                'tax_total' => $priced['tax_total'],
                'tax_included' => (bool) $store->tax_prices_include,
                'tax_rate' => $store->tax_enabled ? (string) ($store->tax_rate ?: '0') : '0',
                'grand_total' => $grand,
                'requires_shipping' => $requiresShipping,
                'has_digital' => $hasDigital,
                'requires_whatsapp' => $requiresWhatsapp,
            ],
            'context' => ['stocks' => $stocks, 'usages' => $usages, 'product_counts' => $productCounts, 'customer' => $customer],
        ];
    }

    /** Create a draft with the next per-store number (D-1001, D-1002, ...). */
    public function create(Store $store, array $attributes, ?int $actorId): StoreDraftOrder
    {
        return DB::transaction(function () use ($store, $attributes, $actorId) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $sequence = max(StoreDraftOrder::FIRST_SEQUENCE - 1, (int) StoreDraftOrder::forStore($store)->max('sequence')) + 1;

            return StoreDraftOrder::create($attributes + [
                'store_id' => $store->id, 'sequence' => $sequence, 'number' => 'D-'.$sequence, 'status' => 'open',
                'currency' => $store->currency ?: 'USD', 'created_by_id' => $actorId, 'updated_by_id' => $actorId,
            ]);
        });
    }

    /** @param array<string,mixed> $totals */
    public static function snapshot(array $totals): array
    {
        return ['totals' => $totals, 'grand_total' => $totals['grand_total'], 'currency' => $totals['currency']];
    }

    /** @return array{token:string,url:string} */
    public function issueInvoice(Store $store, StoreDraftOrder $draft, int $days, ?int $actorId): array
    {
        $token = bin2hex(random_bytes(24));
        $url = app(StorefrontUrlGenerator::class)->publicUrl($store, '/invoices/'.$token);
        if ($url === null) {
            throw ValidationException::withMessages(['invoice' => 'Publish the store or connect a domain before sharing an invoice link.']);
        }
        // Each invoice rotates the link: only the hash is stored, so older links stop working.
        $draft->update(['invoice_token_hash' => StoreDraftOrder::hashToken($token), 'invoice_sent_at' => now(),
            'expires_at' => now()->addDays($days), 'status' => 'invoice_sent', 'updated_by_id' => $actorId]);

        return ['token' => $token, 'url' => $url];
    }

    /** Ready-to-send texts; the merchant shares them — the platform never sends. */
    public function invoiceMessages(Store $store, StoreDraftOrder $draft, string $url): array
    {
        $name = trim((string) ($draft->customer['name'] ?? ''));
        $total = $draft->currency.' '.$draft->grand_total;

        return [
            'en' => ($name !== '' ? "Hi {$name},\n" : "Hello,\n")."Here is your invoice {$draft->number} from {$store->name} for {$total}.\nReview and pay securely: {$url}",
            'ar' => ($name !== '' ? "مرحباً {$name}،\n" : "مرحباً،\n")."هذه فاتورتك {$draft->number} من {$store->name} بقيمة {$total}.\nراجعها وادفع بأمان: {$url}",
        ];
    }

    /** Merchant-facing representation. */
    public function present(StoreDraftOrder $draft): array
    {
        return [
            'id' => $draft->id,
            'number' => $draft->number,
            'status' => $draft->status,
            'store_customer_id' => $draft->store_customer_id,
            'customer' => $draft->customer ?? (object) [],
            'shipping_address' => $draft->shipping_address ?? (object) [],
            'line_items' => $draft->line_items,
            'applied_discount' => $draft->applied_discount,
            'shipping_line' => $draft->shipping_line,
            'totals' => $draft->totals,
            'currency' => $draft->currency,
            'grand_total' => $draft->grand_total,
            'note' => $draft->note,
            'tags' => $draft->tags ?? [],
            'invoice_sent_at' => $draft->invoice_sent_at,
            'expires_at' => $draft->expires_at,
            'expired' => $draft->expired(),
            'completed_order_id' => $draft->completed_order_id,
            'completed_order_number' => $draft->completed_order_id ? $draft->completedOrder()->withoutGlobalScopes()->value('order_number') : null,
            'completed_at' => $draft->completed_at,
            'cancelled_at' => $draft->cancelled_at,
            'editable' => $draft->editable(),
            'created_at' => $draft->created_at,
            'updated_at' => $draft->updated_at,
        ];
    }

    private function line(Store $store, array $line, int $index): array
    {
        $quantity = (int) $line['quantity'];
        if ($line['type'] === 'custom') {
            return ['type' => 'custom', 'title' => trim((string) $line['title']), 'sku' => $this->text($line['sku'] ?? null),
                'quantity' => $quantity, 'unit_price' => bcadd((string) $line['unit_price'], '0', 2),
                'requires_shipping' => (bool) ($line['requires_shipping'] ?? true)];
        }
        try {
            $selection = app(PurchasableSelection::class)->resolve($store, (int) $line['product_id'], isset($line['variant_id']) ? (int) $line['variant_id'] : null);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(["line_items.{$index}.product_id" => collect($error->errors())->flatten()->first() ?? 'This product is not available.']);
        }
        $product = $selection['product'];
        $variant = $selection['variant'];
        $override = isset($line['unit_price']) && $line['unit_price'] !== '';

        return ['type' => 'product', 'product_id' => $product->id, 'variant_id' => $variant?->id, 'title' => $selection['name'],
            'product_name' => $product->name, 'variant_name' => $variant?->name, 'variant_options' => $variant?->options,
            'sku' => $variant?->sku ?? $product->sku, 'quantity' => $quantity,
            'unit_price' => $override ? bcadd((string) $line['unit_price'], '0', 2) : $selection['price'], 'catalog_price' => $selection['price'],
            'price_override' => $override, 'requires_shipping' => $product->digital_type === 'physical', 'digital' => $product->digital_type !== 'physical',
            'image_url' => $variant?->imageUrl() ?? $product->imageUrl()];
    }

    private function assertStock(Product|ProductVariant $stock, int $quantity, int $index, string $title): void
    {
        if ($stock->track_inventory && $stock->stock_quantity - $stock->reserved_quantity < $quantity) {
            $available = max(0, $stock->stock_quantity - $stock->reserved_quantity);
            throw ValidationException::withMessages(["line_items.{$index}.quantity" => "Only {$available} of \"{$title}\" available."]);
        }
    }

    private function assertMoney(string $amount): void
    {
        if (bccomp($amount, ManualStoreOrders::MAX_MONEY, 2) > 0) {
            throw ValidationException::withMessages(['line_items' => 'The draft total exceeds the supported amount.']);
        }
    }

    private function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}

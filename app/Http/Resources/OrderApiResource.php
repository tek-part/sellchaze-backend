<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\User;
use App\Support\AttributeBadgeCache;
use App\Support\ProductImageUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderApiResource extends JsonResource
{
    /**
     * List/index payload stays compact; detail adds rich fields when `deliveries` is eager-loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'code' => $this->code,
            'ref_number' => $this->ref_number,
            'status' => $this->status,
            'source' => $this->source ?? Order::SOURCE_MERCHANT_DIRECT,
            'store_id' => $this->store_id,
            'store_order_id' => $this->store_order_id,
            'store_order_number' => $this->storeOrderNumber(),
            'wigpleasure_order_id' => $this->wigpleasure_order_id,
            'wigpleasure_store_status' => $this->wigpleasure_store_status,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'assigned_supplier_id' => $this->assigned_supplier_id,
            'assigned_supplier' => $this->whenLoaded('assignedSupplier', fn () => $this->assignedSupplier ? [
                'id' => $this->assignedSupplier->id,
                'name' => $this->assignedSupplier->name,
                'email' => $this->assignedSupplier->email,
            ] : null),
            'is_late' => $this->computeIsLate(),
            'created_at' => $this->created_at?->toIso8601String(),
            // when() rather than whenLoaded(): a loaded-but-null product must still reach the snapshot fallback.
            'product' => $this->when($this->resource->relationLoaded('product'), fn () => $this->serializeProductBrief($request)),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'suppliers' => $this->whenLoaded('suppliers', fn () => $this->serializeSuppliersBrief()),
        ];

        // Detail payload (quotations, deliveries, rich fields) when either relation was eager-loaded.
        // Relying only on `deliveries` hid `quotations` if `deliveries` was missing from `with()`.
        $detailLoaded = $this->resource->relationLoaded('deliveries')
            || $this->resource->relationLoaded('quotations');
        if (! $detailLoaded) {
            return $base;
        }

        return array_merge($base, [
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'shipping_address' => $this->shipping_address,
            'shipping_address_json' => $this->enrichedShippingAddressJson(),
            'shipping_type' => $this->shipping_type,
            'payment_method' => $this->payment_method,
            'payment_type' => $this->payment_type,
            'paid_amount' => $this->paid_amount,
            'currency' => $this->currency,
            'payment_transaction_id' => $this->payment_transaction_id,
            'delivery_path' => $this->delivery_path,
            'wigpleasure_products' => $this->wigpleasureProductsArray($request),
            'storefront_items' => $this->storefrontItemsArray(),
            'attribute_badges' => $this->mergedAttributeBadges($request),
            'order_images' => $this->orderImageGallery(),
            'quotations' => $this->whenLoaded('quotations', fn () => $this->serializeQuotations()),
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->serializeDeliveries()),
            'suppliers' => $this->whenLoaded('suppliers', fn () => $this->serializeSuppliersDetail()),
            'tickets_count' => (int) ($this->resource->tickets_count ?? 0),
        ]);
    }

    /**
     * Storefront-bridged orders carry the order number in ref_number; prefer the live relation when loaded.
     */
    private function storeOrderNumber(): ?string
    {
        if (($this->source ?? null) !== Order::SOURCE_STOREFRONT) {
            return null;
        }
        if ($this->resource->relationLoaded('storeOrder') && $this->storeOrder) {
            return $this->storeOrder->order_number;
        }

        return $this->ref_number ?: null;
    }

    /**
     * Immutable line snapshot taken when a storefront order was bridged.
     *
     * @return list<array<string, mixed>>
     */
    private function storefrontItemsArray(): array
    {
        $raw = $this->storefront_items;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            $row['image_thumb_url'] = ProductImageUrl::thumbUrl($row['image'] ?? null);

            return $row;
        }, $raw));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeProductBrief(Request $request): ?array
    {
        $p = $this->product;
        if ($p === null) {
            // Product deleted (or otherwise unresolvable): fall back to the storefront snapshot.
            $items = $this->storefrontItemsArray();
            $first = $items[0] ?? null;
            if (! is_array($first)) {
                return null;
            }
            $name = (string) ($first['name'] ?? 'Product');

            return [
                'id' => $first['product_id'] ?? $this->product_id,
                'name' => $name,
                'name_en' => $name,
                'name_ar' => $name,
                'image' => $first['image'] ?? null,
                'image_thumb_url' => $first['image_thumb_url'] ?? null,
                'storefront_url' => null,
            ];
        }
        $thumb = ProductImageUrl::thumbUrl($p->image);
        $line = $this->matchingWigpleasureProductLine((int) $p->id);
        $nameEn = isset($line['title_en']) ? (string) $line['title_en'] : null;
        $nameAr = isset($line['title_ar']) ? (string) $line['title_ar'] : null;
        $fallbackName = $p->name;
        $storefrontUrl = null;
        if (is_array($line)) {
            $storefrontUrl = $this->wigpleasureStorefrontUrlForRow($line, $request);
        }

        return [
            'id' => $p->id,
            'name' => $fallbackName,
            'name_en' => $nameEn ?: ($fallbackName ?: $nameAr),
            'name_ar' => $nameAr ?: ($fallbackName ?: $nameEn),
            'image' => $p->image,
            'image_thumb_url' => $thumb,
            'storefront_url' => $storefrontUrl,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeSuppliersBrief(): array
    {
        return $this->suppliers->map(function ($s) {
            /** @var User|null $supplierUser */
            $supplierUser = $s->relationLoaded('supplier') ? $s->getRelation('supplier') : null;

            return [
                'id' => $s->id,
                'customer' => $s->getRawOriginal('customer'),
                'supplier' => $s->getRawOriginal('supplier'),
                'supplier_name' => $supplierUser?->name,
                'seen' => (bool) $s->seen,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeSuppliersDetail(): array
    {
        return $this->suppliers->map(function ($s) {
            /** @var User|null $customerUser */
            $customerUser = $s->relationLoaded('customer') ? $s->getRelation('customer') : null;
            /** @var User|null $supplierUser */
            $supplierUser = $s->relationLoaded('supplier') ? $s->getRelation('supplier') : null;

            return [
                'id' => $s->id,
                'customer_id' => $s->getRawOriginal('customer'),
                'supplier_id' => $s->getRawOriginal('supplier'),
                // Scalar supplier + supplier_name: list views use these when quotations are eager-loaded
                // (detail branch replaces brief suppliers); Order detail still uses nested customer / supplier_user.
                'supplier' => $s->getRawOriginal('supplier'),
                'supplier_name' => $supplierUser?->name,
                'seen' => (bool) $s->seen,
                'customer' => $customerUser instanceof User ? [
                    'id' => $customerUser->id,
                    'name' => $customerUser->name,
                    'email' => $customerUser->email,
                ] : null,
                'supplier_user' => $supplierUser instanceof User ? [
                    'id' => $supplierUser->id,
                    'name' => $supplierUser->name,
                    'email' => $supplierUser->email,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeQuotations(): array
    {
        return $this->quotations->map(function ($q) {
            return [
                'id' => $q->id,
                'price' => $q->price,
                'currency' => $q->currency,
                'status' => $q->status,
                'notes' => $q->notes,
                'delivery_date' => $q->delivery_date,
                'shipping_company' => $q->shipping_company,
                'tracking_number' => $q->tracking_number,
                'shipped_at' => $q->shipped_at?->toIso8601String(),
                'price_includes_shipping' => $q->price_includes_shipping,
                'supplier_user_id' => $q->supplier_user_id,
                'customer_user_id' => $q->customer_user_id,
                'supplier_user' => $q->relationLoaded('supplierUser') && $q->supplierUser ? [
                    'id' => $q->supplierUser->id,
                    'name' => $q->supplierUser->name,
                    'email' => $q->supplierUser->email,
                ] : null,
                'customer_user' => $q->relationLoaded('customer') && $q->customer ? [
                    'id' => $q->customer->id,
                    'name' => $q->customer->name,
                    'email' => $q->customer->email,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeDeliveries(): array
    {
        return $this->deliveries->map(function ($d) {
            $company = $d->relationLoaded('shippingCompany') ? $d->shippingCompany : null;

            return [
                'id' => $d->id,
                'segment' => $d->segment ?: 'to_customer',
                'shipping_company_id' => $d->shipping_company_id,
                'delivery_company' => $d->delivery_company,
                'tracking_number' => $d->tracking_number,
                'status' => $d->status,
                'cod_amount' => $d->cod_amount,
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'notes' => $d->notes,
                'shipping_company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function resolvedAttributeBadges(): array
    {
        $raw = $this->attributes;
        if ($raw === null || $raw === '') {
            return [];
        }
        $attrs = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($attrs)) {
            return [];
        }
        $cache = app(AttributeBadgeCache::class);
        $badges = [];
        foreach ($attrs as $attributeId => $attributeValues) {
            $attributeModel = $cache->attribute($attributeId);
            if (! $attributeModel) {
                continue;
            }
            if (is_array($attributeValues)) {
                foreach ($attributeValues as $valueId) {
                    $valueModel = $cache->value($valueId);
                    if ($valueModel) {
                        $badges[] = ['name' => $attributeModel->name, 'value' => $valueModel->value];
                    }
                }
            } else {
                $valueModel = $cache->value($attributeValues);
                if ($valueModel) {
                    $badges[] = ['name' => $attributeModel->name, 'value' => $valueModel->value];
                }
            }
        }

        return $badges;
    }

    /**
     * @return list<array<string, string>>
     */
    private function orderImageGallery(): array
    {
        $out = [];
        if (! empty($this->image)) {
            $u = $this->image;
            $isExt = str_starts_with((string) $u, 'http://') || str_starts_with((string) $u, 'https://');
            $out[] = [
                'title' => 'Order image',
                'thumb_url' => $isExt ? $u : url('/storage/uploads/orders/thumbnails/'.$u),
                'original_url' => $isExt ? $u : url('/storage/uploads/orders/original/'.$u),
            ];
        }
        foreach ($this->wigpleasureProductsArray() as $wp) {
            $u = $wp['image_url'] ?? $wp['image_path'] ?? null;
            if (empty($u)) {
                continue;
            }
            $out[] = [
                'title' => (string) ($wp['title'] ?? 'Product'),
                'thumb_url' => $u,
                'original_url' => $u,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wigpleasureProductsArray(?Request $request = null): array
    {
        $raw = $this->wigpleasure_products;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_map(function ($row) use ($request) {
            if (! is_array($row)) {
                return $row;
            }
            $title = isset($row['title']) ? (string) $row['title'] : null;
            $titleEn = isset($row['title_en']) ? (string) $row['title_en'] : null;
            $titleAr = isset($row['title_ar']) ? (string) $row['title_ar'] : null;
            $fallback = $titleEn ?: ($titleAr ?: $title);
            $row['title'] = $fallback;
            $row['title_en'] = $titleEn ?: $fallback;
            $row['title_ar'] = $titleAr ?: $fallback;
            if (! isset($row['image_url']) && isset($row['image_path'])) {
                $row['image_url'] = $row['image_path'];
            }
            $row['storefront_url'] = $this->wigpleasureStorefrontUrlForRow($row, $request);

            return $row;
        }, $decoded);
    }

    private function requestPreferredLocale(?Request $request): string
    {
        if (! $request instanceof Request) {
            return 'ar';
        }
        $q = strtolower(trim((string) $request->query('locale', '')));
        if (in_array($q, ['ar', 'en'], true)) {
            return $q;
        }
        $al = strtolower((string) $request->header('Accept-Language', ''));
        if (str_starts_with($al, 'en')) {
            return 'en';
        }

        return 'ar';
    }

    private function wigpleasureStorefrontUrlForRow(array $row, ?Request $request): ?string
    {
        $slug = trim((string) ($row['product_slug'] ?? ''));
        $variant = trim((string) ($row['variant_uid'] ?? ''));
        if ($slug === '' || $variant === '') {
            return null;
        }
        $base = (string) config('services.wigpleasure_storefront_url', 'https://wigpleasure.com');
        $base = rtrim($base, '/');
        $locale = $this->requestPreferredLocale($request);

        return $base.'/'.$locale.'/products/'.rawurlencode($slug).'?variant='.rawurlencode($variant);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingWigpleasureProductLine(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        foreach ($this->wigpleasureProductsArray() as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['product_id'] ?? 0) === $productId) {
                return $row;
            }
        }

        $rows = $this->wigpleasureProductsArray();

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /**
     * Ensures warehouse pickup shows a label when the storefront omitted warehouse_name.
     *
     * @return array<string, mixed>|string|null
     */
    private function enrichedShippingAddressJson(): mixed
    {
        $parsed = $this->parseShippingAddressJson();
        if (! is_array($parsed)) {
            return $parsed;
        }
        $type = strtolower(trim((string) ($parsed['type'] ?? '')));
        if ($type === 'warehouse') {
            $name = trim((string) ($parsed['warehouse_name'] ?? ''));
            $wid = $parsed['warehouse_id'] ?? null;
            if ($name === '' && $wid !== null && $wid !== '' && (int) $wid > 0) {
                $parsed['warehouse_name'] = 'Warehouse #'.(int) $wid;
            }
        }

        return $parsed;
    }

    /**
     * Human-readable badges from wigpleasure product lines (selections), when Sellchase attribute IDs do not match.
     *
     * @return list<array{name: string, value: string, name_en?: string, name_ar?: string, value_en?: string, value_ar?: string}>
     */
    private function badgesFromWigpleasureSelections(?Request $request = null): array
    {
        $lines = $this->wigpleasureProductsArray($request);
        if ($lines === []) {
            return [];
        }
        $multi = count($lines) > 1;
        $locale = $this->requestPreferredLocale($request);
        $badges = [];
        foreach ($lines as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $selections = $row['selections'] ?? [];
            if (! is_array($selections)) {
                continue;
            }
            $lineLabelEn = '';
            $lineLabelAr = '';
            if ($multi) {
                $lineLabelEn = trim((string) ($row['title_en'] ?? ''));
                $lineLabelAr = trim((string) ($row['title_ar'] ?? ''));
                $fallback = trim((string) ($row['title'] ?? ''));
                if ($lineLabelEn === '') {
                    $lineLabelEn = $fallback;
                }
                if ($lineLabelAr === '') {
                    $lineLabelAr = $fallback;
                }
                if ($lineLabelEn === '') {
                    $lineLabelEn = 'Item '.($idx + 1);
                }
                if ($lineLabelAr === '') {
                    $lineLabelAr = $lineLabelEn;
                }
            }
            foreach ($selections as $sel) {
                if (! is_array($sel)) {
                    continue;
                }
                $nBase = trim((string) ($sel['variation'] ?? $sel['name'] ?? ''));
                $vBase = trim((string) ($sel['value'] ?? $sel['label'] ?? ''));
                if ($nBase === '' && $vBase === '') {
                    continue;
                }
                if ($nBase === '') {
                    $nBase = $vBase;
                }
                $nEn = trim((string) ($sel['variation_en'] ?? $sel['name_en'] ?? ''));
                $nAr = trim((string) ($sel['variation_ar'] ?? $sel['name_ar'] ?? ''));
                $vEn = trim((string) ($sel['value_en'] ?? $sel['label_en'] ?? ''));
                $vAr = trim((string) ($sel['value_ar'] ?? $sel['label_ar'] ?? ''));
                if ($nEn === '') {
                    $nEn = $nBase;
                }
                if ($nAr === '') {
                    $nAr = $nBase;
                }
                if ($vEn === '') {
                    $vEn = $vBase;
                }
                if ($vAr === '') {
                    $vAr = $vBase;
                }
                if ($nBase === '') {
                    $nBase = $nEn;
                }
                if ($lineLabelEn !== '') {
                    $nEn = $lineLabelEn.' — '.$nEn;
                }
                if ($lineLabelAr !== '') {
                    $nAr = $lineLabelAr.' — '.$nAr;
                }
                $name = $locale === 'en' ? $nEn : $nAr;
                $value = $locale === 'en' ? $vEn : $vAr;
                $badges[] = [
                    'name' => $name,
                    'value' => $value,
                    'name_en' => $nEn,
                    'name_ar' => $nAr,
                    'value_en' => $vEn,
                    'value_ar' => $vAr,
                ];
            }
        }

        return $badges;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function mergedAttributeBadges(Request $request): array
    {
        $fromDb = $this->resolvedAttributeBadges();
        $fromWig = $this->badgesFromWigpleasureSelections($request);
        // Storefront selections carry bilingual labels; serialized Sellchase `attributes` are often
        // a single-locale copy and duplicate the same facts in Arabic — prefer wigpleasure lines.
        if ($fromWig !== []) {
            return $fromWig;
        }

        return $fromDb;
    }

    /**
     * Late = accepted quotation's delivery_date has passed and order isn't already delivered/cancelled.
     */
    private function computeIsLate(): bool
    {
        $status = strtolower((string) $this->status);
        if (in_array($status, ['delivered', 'completed', 'cancelled', 'canceled', 'refunded'], true)) {
            return false;
        }
        if (! $this->resource->relationLoaded('quotations')) {
            return false;
        }
        $accepted = $this->quotations->first(fn ($q) => strtolower((string) $q->status) === 'accepted');
        if (! $accepted || empty($accepted->delivery_date)) {
            return false;
        }
        try {
            $deliveryDate = Carbon::parse($accepted->delivery_date)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        return $deliveryDate->lt(Carbon::now()->startOfDay());
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function parseShippingAddressJson(): mixed
    {
        $raw = $this->shipping_address_json;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : (string) $raw;
    }
}

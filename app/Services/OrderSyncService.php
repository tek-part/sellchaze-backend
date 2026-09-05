<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\GatewayTransaction;
use App\Models\MerchantWigpleasureCategorySupplier;
use App\Models\Order;
use App\Models\OrderSuppliers;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\SupplierRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderSyncService
{
    private SupplierRoutingService $routing;

    public function __construct(?SupplierRoutingService $routing = null)
    {
        $this->routing = $routing ?? app(SupplierRoutingService::class);
    }

    /**
     * Update existing synced order rows.
     *
     * @return array{success: bool, updated: int, order_ids: int[], codes: string[]}
     */
    public function updateSyncedOrder(array $payload, int $wigpleasureOrderId): array
    {
        $existingOrders = $this->findExistingOrdersByWigpleasureId($wigpleasureOrderId);
        if ($existingOrders->isEmpty()) {
            return [
                'success' => true,
                'updated' => 0,
                'order_ids' => [],
                'codes' => [],
            ];
        }

        $customer = (array) ($payload['customer'] ?? []);
        $shippingAddress = (array) ($payload['shipping_address'] ?? []);
        $payment = (array) ($payload['payment'] ?? []);
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : null;

        $paymentType = $payment['type'] ?? null;
        $deliveryPath = $this->resolveDeliveryPath($paymentType, (string) ($shippingAddress['type'] ?? ''));
        $shippingAddressJson = ! empty($shippingAddress) ? json_encode($shippingAddress) : null;
        $shippingAddressText = ! empty($shippingAddress) ? $this->formatShippingAddress($shippingAddress) : null;
        $wigpleasureProducts = $products ? $this->serializeWigpleasureProducts($products) : null;

        $baseUpdate = [
            'notes' => $payload['notes'] ?? null,
            'shipping_address' => $shippingAddressText,
            'shipping_type' => $shippingAddress['type'] ?? null,
            'payment_method' => $payment['method'] ?? null,
            'payment_type' => $paymentType,
            'delivery_path' => $deliveryPath,
            'customer_name' => $customer['name'] ?? null,
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'shipping_address_json' => $shippingAddressJson,
            'currency' => $payment['currency'] ?? null,
            'wigpleasure_products' => $wigpleasureProducts,
        ];

        if (array_key_exists('store_status', $payload)) {
            $ss = $payload['store_status'];
            $baseUpdate['wigpleasure_store_status'] = (is_string($ss) && $ss !== '') ? $ss : null;
        }

        $updated = 0;
        $firstOrderId = $existingOrders->sortBy('id')->first()?->id;
        $paymentMethod = isset($payment['method']) ? (string) $payment['method'] : null;
        $paidAmount = isset($payment['paid_amount']) ? (float) $payment['paid_amount'] : 0.0;
        foreach ($existingOrders as $order) {
            $update = $baseUpdate;
            if ((int) $order->id === (int) $firstOrderId) {
                $update['paid_amount'] = $payment['paid_amount'] ?? null;
                $update['payment_transaction_id'] = $payment['transaction_id'] ?? null;
            }
            $update = $this->filterOrderDataToExistingColumns($update);
            $order->update($update);
            $updated++;
        }

        if ($firstOrderId) {
            $this->syncGatewayPaymentForOrder(
                (int) $firstOrderId,
                $paymentMethod,
                $paidAmount,
                'Order ORD-'.$wigpleasureOrderId.' (wigpleasure)'
            );
        }

        return [
            'success' => true,
            'updated' => $updated,
            'order_ids' => $existingOrders->pluck('id')->toArray(),
            'codes' => $existingOrders->pluck('code')->toArray(),
        ];
    }

    /**
     * Sync order from wigpleasure payload.
     *
     * @return array{success: bool, order_ids: int[], codes: string[], message?: string, status?: string}
     */
    public function sync(array $payload, ?User $apiUser = null): array
    {
        $wigpleasureOrderId = (int) ($payload['wigpleasure_order_id'] ?? 0);

        $existingOrders = $this->findExistingOrdersByWigpleasureId($wigpleasureOrderId);
        if ($existingOrders->isNotEmpty()) {
            Log::info('SELLCHASE API: Order already synced — applying updateSyncedOrder', ['wigpleasure_order_id' => $wigpleasureOrderId]);
            $updated = $this->updateSyncedOrder($payload, $wigpleasureOrderId);

            return [
                'success' => true,
                'message' => 'Order already synced',
                'order_ids' => $updated['order_ids'],
                'codes' => $updated['codes'],
            ];
        }

        $customer = $payload['customer'] ?? [];
        $shippingAddress = $payload['shipping_address'] ?? [];
        $payment = $payload['payment'] ?? [];
        $products = $payload['products'] ?? [];
        $notes = $payload['notes'] ?? '';

        $shippingType = $shippingAddress['type'] ?? 'customer_direct';
        $paymentType = $payment['type'] ?? 'full';
        $paidAmount = (float) ($payment['paid_amount'] ?? 0);
        $paymentMethod = $payment['method'] ?? null;
        $currency = $payment['currency'] ?? 'AED';
        $paymentTransactionId = $payment['transaction_id'] ?? null;

        $deliveryPath = $this->resolveDeliveryPath($paymentType, (string) $shippingType);
        $shippingAddressJson = ! empty($shippingAddress) ? json_encode($shippingAddress) : null;
        $shippingAddressText = $this->formatShippingAddress($shippingAddress);

        $refNumber = strtoupper(Str::random(10));

        $merchantUser = $apiUser ?? $this->resolveDefaultMerchantUser();
        $orderOwnerId = (int) ($merchantUser?->id ?? 0);
        if ($orderOwnerId <= 0) {
            throw ValidationException::withMessages([
                'merchant' => [
                    __('Unable to resolve order owner. Add a user with the Merchant or Admin role, or set SELLCHASE_MERCHANT_USER_ID in .env to a valid user id.'),
                ],
            ]);
        }

        $acceptedSupplierIds = $this->getAcceptedSupplierUserIds($merchantUser);

        $wigpleasureProducts = $this->serializeWigpleasureProducts($products);

        $orderIds = [];
        $codes = [];
        $firstOrderId = null;

        DB::transaction(function () use (
            $products,
            $orderOwnerId,
            $merchantUser,
            $acceptedSupplierIds,
            $wigpleasureOrderId,
            $customer,
            $shippingAddressText,
            $shippingAddressJson,
            $shippingType,
            $paymentMethod,
            $paymentType,
            $paidAmount,
            $currency,
            $paymentTransactionId,
            $deliveryPath,
            $refNumber,
            $wigpleasureProducts,
            $notes,
            &$orderIds,
            &$codes,
            &$firstOrderId
        ) {
            $attributesMap = $this->buildAttributesMap();
            $firstLine = $products[0] ?? [];
            $attributes = $this->resolveAttributes($firstLine['selections'] ?? [], $attributesMap);
            $resolvedSupplierIds = [];
            $totalQuantity = 0;

            foreach ($products as $lineNum => $p) {
                $lineNumber = $lineNum + 1;
                $totalQuantity += (int) ($p['qty'] ?? 1);
                $lineSupplierIds = $this->resolveSupplierIdsForLine($p, $merchantUser, $acceptedSupplierIds);
                if (empty($lineSupplierIds)) {
                    Log::warning('OrderSyncService: no supplier resolved for line, keeping order unassigned for this line', [
                        'line' => $lineNumber,
                        'wigpleasure_order_id' => $wigpleasureOrderId,
                        'product_id' => (int) ($p['product_id'] ?? 0),
                    ]);
                } else {
                    $resolvedSupplierIds = array_values(array_unique(array_merge($resolvedSupplierIds, $lineSupplierIds)));
                }

                $this->ensureProductExists(
                    (int) ($p['product_id'] ?? 0),
                    $p['title_en'] ?? ($p['title_ar'] ?? ($p['title'] ?? ('Product '.($p['product_id'] ?? 0)))),
                    $p['image_url'] ?? null,
                    $merchantUser,
                    isset($p['wigpleasure_category_id']) ? (int) $p['wigpleasure_category_id'] : null,
                    isset($p['category_name']) ? (string) $p['category_name'] : null,
                    isset($p['category_name_en']) ? (string) $p['category_name_en'] : null,
                    isset($p['category_name_ar']) ? (string) $p['category_name_ar'] : null,
                );
            }

            $orderData = [
                'code' => 'ORD-'.$wigpleasureOrderId.'-1',
                'source' => Order::SOURCE_EXTERNAL_STORE,
                'quantity' => max($totalQuantity, 1),
                'image' => $firstLine['image_url'] ?? null,
                'product_id' => (int) ($firstLine['product_id'] ?? 0),
                'user_id' => $orderOwnerId,
                'attributes' => $attributes ? serialize($attributes) : 'a:0:{}',
                'status' => 'pending',
                'ref_number' => $refNumber,
                'notes' => $notes,
                'shipping_address' => $shippingAddressText,
                'shipping_type' => $shippingType,
                'payment_method' => $paymentMethod,
                'payment_type' => $paymentType,
                'paid_amount' => $paidAmount,
                'delivery_path' => $deliveryPath,
            ];

            if (Schema::hasColumn('orders', 'wigpleasure_order_id')) {
                $orderData['wigpleasure_order_id'] = $wigpleasureOrderId;
            }
            if (Schema::hasColumn('orders', 'wigpleasure_products')) {
                $orderData['wigpleasure_products'] = $wigpleasureProducts;
            }
            if (Schema::hasColumn('orders', 'customer_name')) {
                $orderData['customer_name'] = $customer['name'] ?? null;
            }
            if (Schema::hasColumn('orders', 'customer_email')) {
                $orderData['customer_email'] = $customer['email'] ?? null;
            }
            if (Schema::hasColumn('orders', 'customer_phone')) {
                $orderData['customer_phone'] = $customer['phone'] ?? null;
            }
            if (Schema::hasColumn('orders', 'shipping_address_json')) {
                $orderData['shipping_address_json'] = $shippingAddressJson;
            }
            if (Schema::hasColumn('orders', 'currency')) {
                $orderData['currency'] = $currency;
            }
            if (Schema::hasColumn('orders', 'payment_transaction_id')) {
                $orderData['payment_transaction_id'] = $paymentTransactionId;
            }
            if (Schema::hasColumn('orders', 'wigpleasure_store_status')) {
                $ss = $payload['store_status'] ?? null;
                $orderData['wigpleasure_store_status'] = (is_string($ss) && $ss !== '') ? $ss : null;
            }

            $orderCode = $orderData['code'] ?? 'ORD-'.$wigpleasureOrderId.'-1';
            $orderData = $this->filterOrderDataToExistingColumns($orderData);
            $order = Order::create($orderData);
            $orderIds[] = $order->id;
            $codes[] = $order->code ?? $orderCode;
            $firstOrderId = $order->id;

            if (Schema::hasTable('order_suppliers') && ! empty($resolvedSupplierIds)) {
                // Match assignSupplier() / Blade orders.out: pivot.customer = order owner (merchant).
                $orderCustomerId = (int) $order->user_id;
                foreach ($resolvedSupplierIds as $supplierId) {
                    OrderSuppliers::create([
                        'order_id' => $order->id,
                        'customer' => $orderCustomerId,
                        'supplier' => $supplierId,
                    ]);
                }
            } elseif (! Schema::hasTable('order_suppliers')) {
                Log::warning('OrderSyncService: order_suppliers table does not exist - run migrations in laravel-orders');
            }

            if ($firstOrderId) {
                $methodForGateway = ($paymentMethod !== null && $paymentMethod !== '')
                    ? (string) $paymentMethod
                    : null;
                $this->syncGatewayPaymentForOrder(
                    (int) $firstOrderId,
                    $methodForGateway,
                    (float) $paidAmount,
                    'Order ORD-'.$wigpleasureOrderId.' (wigpleasure)'
                );
            }
        });

        Log::info('SELLCHASE API: Orders created successfully', [
            'wigpleasure_order_id' => $wigpleasureOrderId,
            'order_ids' => $orderIds,
        ]);

        return [
            'success' => true,
            'order_ids' => $orderIds,
            'codes' => $codes,
            'status' => 'pending',
        ];
    }

    /**
     * Align gateway wallet + order_payment transaction with the persisted order row.
     * Call after SPA/admin updates so gateways reflect paid_amount + payment_method even if the row
     * was filled before syncGatewayPayment ran or payment arrived later.
     */
    public function reconcileGatewayForOrder(Order $order): void
    {
        if (! Schema::hasTable('payment_gateways') || ! Schema::hasTable('gateway_transactions')) {
            return;
        }

        $paid = (float) ($order->paid_amount ?? 0);
        $method = $order->payment_method;
        $methodStr = (is_string($method) && trim($method) !== '') ? trim($method) : null;

        $existingTx = GatewayTransaction::query()
            ->where('reference_type', 'order')
            ->where('reference_id', (int) $order->id)
            ->where('type', 'order_payment')
            ->latest('id')
            ->first();

        if ($paid <= 0 && ! $existingTx) {
            return;
        }

        if ($paid > 0 && $methodStr && $existingTx) {
            $expectedSlug = strtolower(str_replace([' ', '-'], '_', $methodStr));
            $gateway = PaymentGateway::query()->find($existingTx->gateway_id);
            if ($gateway && $gateway->slug === $expectedSlug && abs((float) $existingTx->amount - $paid) < 0.00001) {
                return;
            }
        }

        $wigId = (int) ($order->wigpleasure_order_id ?? 0);
        $code = trim((string) ($order->code ?? ''));
        $notes = $wigId > 0
            ? 'Order ORD-'.$wigId.' (wigpleasure)'
            : ($code !== '' ? 'Order '.$code : 'Order #'.$order->id);

        $this->syncGatewayPaymentForOrder(
            (int) $order->id,
            $methodStr,
            $paid,
            $notes
        );
    }

    private function syncGatewayPaymentForOrder(int $orderId, ?string $paymentMethod, float $paidAmount, string $transactionNotes): void
    {
        if (! Schema::hasTable('payment_gateways') || ! Schema::hasTable('gateway_transactions')) {
            return;
        }

        $existingTx = GatewayTransaction::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', 'order_payment')
            ->latest('id')
            ->first();

        if (blank($paymentMethod) || $paidAmount <= 0) {
            if ($existingTx) {
                DB::transaction(function () use ($existingTx) {
                    $oldGateway = PaymentGateway::query()->with('wallet')->find($existingTx->gateway_id);
                    if ($oldGateway?->wallet) {
                        $oldGateway->wallet->decrement('balance', (float) $existingTx->amount);
                    }
                    $existingTx->delete();
                });
            }

            return;
        }

        $slug = strtolower(str_replace([' ', '-'], '_', (string) $paymentMethod));
        $gateway = PaymentGateway::firstOrCreate(
            ['slug' => $slug],
            ['name' => (string) $paymentMethod]
        );
        if (blank($gateway->name) || $gateway->name !== (string) $paymentMethod) {
            $gateway->update(['name' => (string) $paymentMethod]);
        }

        DB::transaction(function () use ($gateway, $existingTx, $paidAmount, $orderId, $transactionNotes) {
            $targetWallet = $gateway->wallet;
            if (! $targetWallet) {
                $targetWallet = $gateway->wallet()->create(['balance' => 0]);
            }

            if ($existingTx) {
                $oldGateway = PaymentGateway::query()->with('wallet')->find($existingTx->gateway_id);
                if ($oldGateway?->wallet) {
                    $oldGateway->wallet->decrement('balance', (float) $existingTx->amount);
                }

                $existingTx->update([
                    'gateway_id' => $gateway->id,
                    'amount' => $paidAmount,
                    'notes' => $transactionNotes,
                ]);
            } else {
                GatewayTransaction::create([
                    'gateway_id' => $gateway->id,
                    'type' => 'order_payment',
                    'amount' => $paidAmount,
                    'reference_type' => 'order',
                    'reference_id' => $orderId,
                    'notes' => $transactionNotes,
                ]);
            }

            $targetWallet->increment('balance', $paidAmount);
        });
    }

    private function serializeWigpleasureProducts(array $products): string
    {
        return json_encode(array_map(function ($p) {
            $titleEn = isset($p['title_en']) ? trim((string) $p['title_en']) : null;
            $titleAr = isset($p['title_ar']) ? trim((string) $p['title_ar']) : null;
            $title = isset($p['title']) ? trim((string) $p['title']) : null;
            $resolvedTitle = $titleEn ?: ($titleAr ?: $title);

            $slug = isset($p['product_slug']) ? trim((string) $p['product_slug']) : '';
            $variantUid = isset($p['variant_uid']) ? trim((string) $p['variant_uid']) : '';

            return [
                'product_id' => $p['product_id'] ?? 0,
                'product_slug' => $slug !== '' ? $slug : null,
                'variant_uid' => $variantUid !== '' ? $variantUid : null,
                'wigpleasure_category_id' => $p['wigpleasure_category_id'] ?? $p['category_id'] ?? null,
                'qty' => $p['qty'] ?? 1,
                'title' => $resolvedTitle,
                'title_en' => $titleEn ?: $resolvedTitle,
                'title_ar' => $titleAr ?: $resolvedTitle,
                'image_url' => $p['image_url'] ?? null,
                'selections' => $p['selections'] ?? [],
            ];
        }, $products));
    }

    /**
     * Find existing orders by wigpleasure_order_id.
     * Wrapped in try-catch - Process may connect to different DB than CLI.
     */
    private function findExistingOrdersByWigpleasureId(int $wigpleasureOrderId)
    {
        try {
            $columns = Schema::getColumnListing('orders');
            if (in_array('wigpleasure_order_id', $columns)) {
                return Order::where('wigpleasure_order_id', $wigpleasureOrderId)->get();
            }
            if (in_array('code', $columns)) {
                return Order::where('code', 'like', 'ORD-'.$wigpleasureOrderId.'-%')->get();
            }
        } catch (\Throwable $e) {
            Log::warning('SELLCHASE API: Could not check existing orders', ['error' => $e->getMessage()]);
        }

        return collect();
    }

    /**
     * Filter order data to only include columns that exist in the orders table.
     * Handles servers with different schema (e.g. missing code, shipping columns).
     */
    private function filterOrderDataToExistingColumns(array $orderData): array
    {
        $existingColumns = Schema::getColumnListing('orders');

        return array_intersect_key($orderData, array_flip($existingColumns));
    }

    private function formatShippingAddress(array $address): string
    {
        if (($address['type'] ?? null) === 'warehouse') {
            $warehouse = trim((string) ($address['warehouse_name'] ?? ''));
            if ($warehouse !== '') {
                return 'Warehouse: '.$warehouse;
            }
        }
        $parts = array_filter([
            $address['address_1'] ?? '',
            $address['address_2'] ?? '',
            $address['city'] ?? '',
            $address['state'] ?? '',
            $address['zip'] ?? '',
            $address['country'] ?? '',
        ]);

        return implode(', ', $parts) ?: '';
    }

    private function resolveDeliveryPath(?string $paymentType, ?string $shippingType): string
    {
        if ($shippingType === 'warehouse') {
            return 'pickup_point';
        }
        if ($paymentType === 'partial_cod') {
            return 'pickup_point';
        }

        return 'customer_direct';
    }

    private function findOrCreateCustomerUser(array $customer): User
    {
        $email = $customer['email'] ?? null;
        $name = $customer['name'] ?? 'Website Customer';

        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                return $user;
            }
        }

        // إنشاء عميل جديد باسمه وإيميله الحقيقي من الطلب (وليس الأدمن)
        return User::create([
            'name' => $name,
            'email' => $email ?? 'guest_'.uniqid().'@wigpleasure.com',
            'password' => bcrypt(Str::random(32)),
        ]);
    }

    /**
     * @return int[]
     */
    private function getAcceptedSupplierUserIds(?User $merchantUser): array
    {
        return $this->routing->acceptedSupplierIds($merchantUser);
    }

    private function extractWigpleasureCategoryId(array $p): ?int
    {
        foreach (['wigpleasure_category_id', 'category_id'] as $key) {
            if (! array_key_exists($key, $p) || $p[$key] === '' || $p[$key] === null) {
                continue;
            }
            $v = (int) $p[$key];
            if ($v > 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  int[]  $ids
     * @return int[]
     */
    private function filterOutAdminSupplierIds(array $ids): array
    {
        return $this->routing->filterOutAdminSupplierIds($ids);
    }

    /**
     * Per-line supplier resolution: category routing for merchant, then all accepted partners, then catalogue owner.
     *
     * @return int[]
     */
    private function resolveSupplierIdsForLine(array $p, ?User $merchantUser, array $acceptedSupplierIds): array
    {
        $productId = (int) ($p['product_id'] ?? 0);
        $catId = $this->extractWigpleasureCategoryId($p);

        if ($merchantUser && $catId && Schema::hasTable('merchant_wigpleasure_category_suppliers')) {
            $mappedIds = MerchantWigpleasureCategorySupplier::query()
                ->where('merchant_id', $merchantUser->id)
                ->where('wigpleasure_category_id', $catId)
                ->pluck('supplier_user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            // When a category id is present, route only to suppliers mapped for that category.
            if ($mappedIds === []) {
                Log::warning('OrderSyncService: no category routing found for line', [
                    'merchant_id' => $merchantUser->id,
                    'wigpleasure_category_id' => $catId,
                    'product_id' => $productId,
                ]);

                return [];
            }

            $intersect = array_values(array_intersect($mappedIds, $acceptedSupplierIds));
            $filtered = $this->filterOutAdminSupplierIds($intersect);
            if ($filtered === []) {
                Log::warning('OrderSyncService: mapped suppliers are not accepted partners for line', [
                    'merchant_id' => $merchantUser->id,
                    'wigpleasure_category_id' => $catId,
                    'mapped_supplier_ids' => $mappedIds,
                ]);

                return [];
            }

            return $filtered;
        }

        if (! empty($acceptedSupplierIds)) {
            $filtered = $this->filterOutAdminSupplierIds($acceptedSupplierIds);
            if ($filtered !== []) {
                return $filtered;
            }
        }

        if ($productId > 0 && Schema::hasColumn('products', 'user_id')) {
            $ownerId = Product::where('id', $productId)->value('user_id');
            if ($ownerId) {
                $fromOwner = $this->filterOutAdminSupplierIds([(int) $ownerId]);
                if ($fromOwner !== []) {
                    return $fromOwner;
                }
            }
        }

        if ($productId > 0) {
            try {
                $fromPivot = User::whereHas('products', fn ($q) => $q->where('products.id', $productId))
                    ->pluck('id')
                    ->unique()
                    ->values()
                    ->all();
                $fromPivot = $this->filterOutAdminSupplierIds($fromPivot);
                if ($fromPivot !== []) {
                    return $fromPivot;
                }
            } catch (\Throwable $e) {
                Log::warning('OrderSyncService: Could not resolve suppliers from products', ['error' => $e->getMessage()]);
            }
        }

        $fallback = $this->resolveFallbackSupplierIds($merchantUser, $acceptedSupplierIds);
        if ($fallback !== []) {
            return $fallback;
        }

        return [];
    }

    /**
     * @param  int[]  $acceptedSupplierIds
     * @return int[]
     */
    private function resolveFallbackSupplierIds(?User $merchantUser, array $acceptedSupplierIds): array
    {
        return $this->routing->fallbackSupplierIds($merchantUser, $acceptedSupplierIds);
    }

    private function buildAttributesMap(): array
    {
        $attributes = Attribute::with('attribute_values')->get();
        $map = [];
        foreach ($attributes as $attr) {
            foreach ($attr->attribute_values as $val) {
                $key = trim($attr->name).':'.trim($val->value ?? '');
                $map[$key] = ['attr_id' => $attr->id, 'val_id' => $val->id];
            }
        }

        return $map;
    }

    private function ensureProductExists(
        int $productId,
        string $name,
        ?string $imageUrl,
        ?User $merchantUser,
        ?int $wigpleasureCategoryId = null,
        ?string $categoryName = null,
        ?string $categoryNameEn = null,
        ?string $categoryNameAr = null,
    ): void {
        if (Product::where('id', $productId)->exists()) {
            return;
        }
        $defaultUserId = $this->defaultGhostProductOwnerId($merchantUser);
        $categoryId = $this->resolveOrCreateCategoryForWigpleasureSync(
            $wigpleasureCategoryId,
            $categoryName,
            $categoryNameEn,
            $categoryNameAr,
        );
        try {
            DB::table('products')->insert([
                'id' => $productId,
                'name' => $name,
                'description' => null,
                'image' => $imageUrl,
                'user_id' => $defaultUserId,
                'category_id' => $categoryId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                throw $e;
            }
        }
    }

    /**
     * Ensures a Sellchase categories row exists (same as creating via /categories), then returns its id.
     */
    private function resolveOrCreateCategoryForWigpleasureSync(
        ?int $wigpleasureCategoryId,
        ?string $categoryNameLegacy,
        ?string $categoryNameEn,
        ?string $categoryNameAr,
    ): int {
        $en = trim((string) ($categoryNameEn ?? ''));
        $ar = trim((string) ($categoryNameAr ?? ''));
        if ($en === '' && $ar === '') {
            $legacy = trim((string) ($categoryNameLegacy ?? ''));
            if ($legacy !== '') {
                $en = $legacy;
                $ar = $legacy;
            }
        }
        if ($en === '' && $ar === '') {
            $slug = $wigpleasureCategoryId !== null && $wigpleasureCategoryId > 0
                ? 'Wigpleasure #'.$wigpleasureCategoryId
                : 'Wigpleasure';
            $en = $slug;
            $ar = $slug;
        }
        if ($en === '') {
            $en = $ar;
        }
        if ($ar === '') {
            $ar = $en;
        }
        if (mb_strlen($en) > 255) {
            $en = mb_substr($en, 0, 255);
        }
        if (mb_strlen($ar) > 255) {
            $ar = mb_substr($ar, 0, 255);
        }

        $existing = Category::query()->where('name_en', $en)->first()
            ?? Category::query()->where('name_ar', $ar)->first();

        if ($existing) {
            $dirty = false;
            if ($en !== '' && trim((string) $existing->name_en) === '') {
                $existing->name_en = $en;
                $dirty = true;
            }
            if ($ar !== '' && trim((string) $existing->name_ar) === '') {
                $existing->name_ar = $ar;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            return (int) $existing->id;
        }

        $category = Category::query()->create([
            'name_en' => $en,
            'name_ar' => $ar,
        ]);

        return (int) $category->id;
    }

    private function defaultGhostProductOwnerId(?User $merchantUser): int
    {
        if ($merchantUser) {
            return (int) $merchantUser->id;
        }
        $mid = User::role('Merchant')->orderBy('id')->value('id');
        if ($mid) {
            return (int) $mid;
        }
        $any = User::min('id');

        return $any ? (int) $any : 1;
    }

    private function resolveAttributes(array $selections, array $attributesMap): array
    {
        $result = [];
        foreach ($selections as $sel) {
            $variation = $sel['variation'] ?? $sel['name'] ?? '';
            $value = $sel['value'] ?? $sel['label'] ?? '';
            $key = trim($variation).':'.trim($value);
            if (isset($attributesMap[$key])) {
                $result[$attributesMap[$key]['attr_id']] = (string) $attributesMap[$key]['val_id'];
            }
        }

        return $result;
    }

    /**
     * Single-store Wigpleasure sync: owner when no API key / api_user on the request.
     * Prefers Merchant; falls back to Admin when the DB has no merchant (common after Admin-only seed).
     */
    private function resolveDefaultMerchantUser(): ?User
    {
        $configuredId = config('services.sellchase_merchant_user_id');
        if ($configuredId !== null && $configuredId !== '') {
            $user = User::find((int) $configuredId);
            if ($user && ($user->hasRole('Merchant') || $user->hasRole('Admin'))) {
                return $user;
            }
        }

        $merchant = User::whereHas('roles', fn ($q) => $q->where('name', 'Merchant'))
            ->orderBy('id')
            ->first();
        if ($merchant) {
            return $merchant;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))
            ->orderBy('id')
            ->first();
        if ($admin) {
            Log::info('SELLCHASE API: Wigpleasure sync using Admin as order owner (no Merchant user in database).');

            return $admin;
        }

        return null;
    }
}

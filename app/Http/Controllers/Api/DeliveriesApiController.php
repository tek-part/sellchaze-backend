<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderQuotations;
use App\Models\ShippingCompany;
use App\Models\User;
use App\Services\DeliveryNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveriesApiController extends Controller
{
    public const SEGMENT_TO_WAREHOUSE = 'to_warehouse';

    public const SEGMENT_TO_CUSTOMER = 'to_customer';

    public function index(Request $request): JsonResponse
    {
        $query = OrderDelivery::query()
            ->with([
                'order:id,code,customer_name,customer_phone,customer_email,status,shipping_type,delivery_path,shipping_address_json',
                'shippingCompany:id,name,code,tracking_url_template',
            ])
            ->orderByDesc('created_at');

        $user = $request->user();
        if ($user instanceof User && $user->hasRole('Supplier')) {
            $query->whereHas('order.quotations', function ($q) use ($user) {
                $q->where('supplier_user_id', (int) $user->id)
                    ->whereIn('status', ['accepted', 'deal']);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('tracking_number', 'like', $term)
                    ->orWhere('delivery_company', 'like', $term)
                    ->orWhereHas('order', function ($oq) use ($term) {
                        $oq->where('code', 'like', $term)
                            ->orWhere('customer_name', 'like', $term)
                            ->orWhere('customer_phone', 'like', $term);
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (OrderDelivery $d) => $this->toArray($d))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, OrderDelivery $delivery): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User && $user->hasRole('Supplier')) {
            $linkedOrder = Order::query()->find($delivery->order_id);
            if (! $linkedOrder || ! $this->supplierHasAcceptedQuotationForOrder($user, $linkedOrder)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $delivery->load([
            'order:id,code,customer_name,customer_phone,customer_email,status,shipping_type,delivery_path,shipping_address_json',
            'shippingCompany:id,name,code,tracking_url_template',
        ]);

        return response()->json(['data' => $this->toArray($delivery)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_code' => ['required', 'string', 'exists:orders,code'],
            'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'segment' => ['nullable', 'string', 'in:to_warehouse,to_customer'],
        ]);

        $order = Order::query()->where('code', $validated['order_code'])->firstOrFail();
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $segment = $validated['segment'] ?? null;
        $shippingType = strtolower(trim((string) ($order->shipping_type ?? 'customer_direct')));

        if ($user->hasRole('Supplier')) {
            if (! $this->supplierHasAcceptedQuotationForOrder($user, $order)) {
                return response()->json(['message' => 'Only the winning supplier can create deliveries for this order.'], 403);
            }
            if ($shippingType === 'warehouse') {
                $segment = $segment ?: self::SEGMENT_TO_WAREHOUSE;
                if ($segment !== self::SEGMENT_TO_WAREHOUSE) {
                    return response()->json(['message' => 'For warehouse orders the supplier must register the shipment to the warehouse (segment to_warehouse).'], 422);
                }
            } else {
                // customer_direct (and pickup_point / partial_cod without warehouse — treat as direct)
                $segment = $segment ?: self::SEGMENT_TO_CUSTOMER;
                if ($segment !== self::SEGMENT_TO_CUSTOMER) {
                    return response()->json(['message' => 'Invalid segment for direct-to-customer orders.'], 422);
                }
            }
        } else {
            $segment = self::SEGMENT_TO_CUSTOMER;
            if ($shippingType === 'warehouse') {
                $first = OrderDelivery::query()
                    ->where('order_id', $order->id)
                    ->where('segment', self::SEGMENT_TO_WAREHOUSE)
                    ->first();
                if (! $first || $first->status !== 'delivered') {
                    return response()->json(['message' => 'The warehouse leg must be delivered before registering shipment to the customer.'], 422);
                }
                if (OrderDelivery::query()->where('order_id', $order->id)->where('segment', self::SEGMENT_TO_CUSTOMER)->exists()) {
                    return response()->json(['message' => 'Customer shipment already exists for this order.'], 422);
                }
            } else {
                // Direct-to-customer: admin may register the only shipment if none exists (e.g. from deliveries list).
                if (OrderDelivery::query()->where('order_id', $order->id)->exists()) {
                    return response()->json(['message' => 'This order already has a delivery. Edit it instead.'], 422);
                }
            }
        }

        if (OrderDelivery::query()->where('order_id', $order->id)->where('segment', $segment)->exists()) {
            return response()->json(['message' => 'A delivery for this segment already exists.'], 422);
        }

        $company = ShippingCompany::query()->findOrFail($validated['shipping_company_id']);
        $tracking = isset($validated['tracking_number']) ? trim((string) $validated['tracking_number']) : '';
        $status = $tracking !== '' ? 'in_transit' : 'pending';

        $delivery = OrderDelivery::create([
            'order_id' => $order->id,
            'segment' => $segment,
            'shipping_company_id' => $company->id,
            'delivery_company' => $company->code,
            'tracking_number' => $tracking === '' ? null : $tracking,
            'cod_amount' => $validated['cod_amount'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $status,
        ]);

        $this->syncOrderStatusFromDelivery($order, $delivery);

        $delivery->load([
            'order:id,code,customer_name,customer_phone,customer_email,status,shipping_type,delivery_path,shipping_address_json',
            'shippingCompany:id,name,code,tracking_url_template',
        ]);

        try {
            DeliveryNotificationService::notify($delivery, 'created');
        } catch (\Throwable) {
            /* optional notifications */
        }

        return response()->json(['data' => $this->toArray($delivery)], 201);
    }

    public function update(Request $request, OrderDelivery $delivery): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $linkedOrder = Order::query()->find($delivery->order_id);
        if (! $linkedOrder) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($user->hasRole('Supplier')) {
            if (! $this->supplierHasAcceptedQuotationForOrder($user, $linkedOrder)) {
                return response()->json(['message' => 'Only the winning supplier can update this delivery.'], 403);
            }
            if (! $this->supplierMayEditDeliverySegment($linkedOrder, $delivery)) {
                return response()->json(['message' => 'Suppliers may only update the warehouse leg for warehouse orders.'], 403);
            }
        } else {
            if (! $this->adminMayEditDelivery($linkedOrder, $delivery)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'in:pending,in_transit,delivered,failed'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'shipping_company_id' => ['sometimes', 'nullable', 'integer', 'exists:shipping_companies,id'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $prevTracking = trim((string) ($delivery->tracking_number ?? ''));

        if (array_key_exists('shipping_company_id', $validated) && $validated['shipping_company_id'] !== null) {
            $company = ShippingCompany::query()->findOrFail((int) $validated['shipping_company_id']);
            $delivery->shipping_company_id = $company->id;
            $delivery->delivery_company = $company->code;
        }

        if (array_key_exists('tracking_number', $validated)) {
            $tn = trim((string) ($validated['tracking_number'] ?? ''));
            $delivery->tracking_number = $tn === '' ? null : $tn;
        }

        if (array_key_exists('status', $validated)) {
            $delivery->status = $validated['status'];
            if ($validated['status'] === 'delivered') {
                $delivery->delivered_at = now();
            } elseif (in_array($validated['status'], ['pending', 'in_transit', 'failed'], true)) {
                $delivery->delivered_at = null;
            }
        }

        $newTracking = trim((string) ($delivery->tracking_number ?? ''));

        if ($prevTracking === '' && $newTracking !== '' && $delivery->status === 'pending') {
            $delivery->status = 'in_transit';
            if ($delivery->delivered_at) {
                $delivery->delivered_at = null;
            }
        }

        if (array_key_exists('cod_amount', $validated)) {
            $delivery->cod_amount = $validated['cod_amount'];
        }
        if (array_key_exists('notes', $validated)) {
            $delivery->notes = $validated['notes'];
        }

        $delivery->save();

        $this->syncOrderStatusFromDelivery($linkedOrder, $delivery);

        $delivery->load([
            'order:id,code,customer_name,customer_phone,customer_email,status,shipping_type,delivery_path,shipping_address_json',
            'shippingCompany:id,name,code,tracking_url_template',
        ]);

        try {
            DeliveryNotificationService::notify($delivery, 'updated');
        } catch (\Throwable) {
            /* optional notifications */
        }

        return response()->json(['data' => $this->toArray($delivery)]);
    }

    private function supplierMayEditDeliverySegment(Order $order, OrderDelivery $delivery): bool
    {
        $shippingType = strtolower(trim((string) ($order->shipping_type ?? 'customer_direct')));
        if ($shippingType === 'warehouse') {
            return $delivery->segment === self::SEGMENT_TO_WAREHOUSE;
        }

        return $delivery->segment === self::SEGMENT_TO_CUSTOMER;
    }

    private function adminMayEditDelivery(Order $order, OrderDelivery $delivery): bool
    {
        $shippingType = strtolower(trim((string) ($order->shipping_type ?? 'customer_direct')));
        if ($shippingType !== 'warehouse') {
            return true;
        }

        if ($delivery->segment === self::SEGMENT_TO_WAREHOUSE) {
            return true;
        }

        if ($delivery->segment !== self::SEGMENT_TO_CUSTOMER) {
            return false;
        }

        $first = OrderDelivery::query()
            ->where('order_id', $order->id)
            ->where('segment', self::SEGMENT_TO_WAREHOUSE)
            ->first();

        return $first && $first->status === 'delivered';
    }

    /**
     * Keep orders.status aligned with shipment data: tracking / in-transit → shipped;
     * customer leg delivered → completed; warehouse leg delivered → awaiting_shipping (next leg).
     */
    private function syncOrderStatusFromDelivery(Order $order, OrderDelivery $delivery): void
    {
        $order->refresh();
        $delivery->refresh();

        if (in_array($order->status, ['cancelled', 'rejected'], true)) {
            return;
        }

        $tracking = trim((string) ($delivery->tracking_number ?? ''));
        $ds = (string) $delivery->status;
        $seg = $delivery->segment === self::SEGMENT_TO_WAREHOUSE ? self::SEGMENT_TO_WAREHOUSE : self::SEGMENT_TO_CUSTOMER;

        if ($ds === 'failed') {
            return;
        }

        if ($seg === self::SEGMENT_TO_CUSTOMER && $ds === 'delivered') {
            $order->status = 'completed';
            $order->save();

            return;
        }

        if ($seg === self::SEGMENT_TO_WAREHOUSE && $ds === 'delivered') {
            $order->status = 'awaiting_shipping';
            $order->save();

            return;
        }

        $hasShippingInfo = $tracking !== '' || $ds === 'in_transit';
        if ($hasShippingInfo && in_array($order->status, ['awaiting_shipping', 'shipped', 'deal', 'accepted', 'pending'], true)) {
            $order->status = 'shipped';
            $order->save();
        }
    }

    private function supplierHasAcceptedQuotationForOrder(User $user, Order $order): bool
    {
        return OrderQuotations::query()
            ->where('order_id', $order->id)
            ->where('supplier_user_id', (int) $user->id)
            ->whereIn('status', ['accepted', 'deal'])
            ->exists();
    }

    private function toArray(OrderDelivery $d): array
    {
        $order = $d->relationLoaded('order') ? $d->order : null;
        $company = $d->relationLoaded('shippingCompany') ? $d->shippingCompany : null;

        $trackingUrl = null;
        if ($company && $company->tracking_url_template && $d->tracking_number) {
            $trackingUrl = str_replace('{tracking}', $d->tracking_number, $company->tracking_url_template);
        }

        return [
            'id' => $d->id,
            'order_id' => $d->order_id,
            'segment' => $d->segment ?: self::SEGMENT_TO_CUSTOMER,
            'shipping_company_id' => $d->shipping_company_id,
            'delivery_company' => $d->delivery_company,
            'tracking_number' => $d->tracking_number,
            'tracking_url' => $trackingUrl,
            'status' => $d->status,
            'cod_amount' => $d->cod_amount !== null ? (string) $d->cod_amount : null,
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'notes' => $d->notes,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
            'order' => $order ? [
                'code' => $order->code,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_email' => $order->customer_email,
                'status' => $order->status,
                'shipping_type' => $order->shipping_type,
                'delivery_path' => $order->delivery_path,
                'shipping_address_json' => $this->parseShippingAddressJson($order->shipping_address_json),
            ] : null,
            'shipping_company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'tracking_url_template' => $company->tracking_url_template,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function parseShippingAddressJson(mixed $raw): mixed
    {
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

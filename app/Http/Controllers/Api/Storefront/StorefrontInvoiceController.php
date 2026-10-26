<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StoreOrderResource;
use App\Models\Store;
use App\Models\StoreDraftOrder;
use App\Models\StorePaymentGateway;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\DraftOrderConversion;
use App\Services\Commerce\HostedPaymentGateways;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\PaymentGatewayCatalog;
use App\Services\Commerce\PaymentPriceAdjustment;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\RegionalHostedPayments;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Public draft-order invoice: the token is the capability; foreign, expired or closed drafts are 404. */
class StorefrontInvoiceController extends Controller
{
    use ResolvesStorefront;

    private const HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function show(Request $request, string $token): JsonResponse
    {
        $store = $this->currentStore($request);
        $draft = $this->find($store, $token);
        $totals = $draft->totals ?? [];

        return response()->json(['data' => [
            'number' => $draft->number,
            'status' => $draft->status,
            'currency' => $draft->currency,
            'expires_at' => $draft->expires_at,
            'store' => ['name' => $store->name],
            'customer' => ['name' => $draft->customer['name'] ?? null, 'email' => $draft->customer['email'] ?? null, 'phone' => $draft->customer['phone'] ?? null],
            'shipping_address' => $draft->shipping_address ?? (object) [],
            'line_items' => array_map(fn (array $line) => [
                'title' => $line['title'], 'variant_name' => $line['variant_name'] ?? null, 'sku' => $line['sku'] ?? null,
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'] ?? null, 'image_url' => $line['image_url'] ?? null,
            ], $totals['line_items'] ?? []),
            'totals' => [
                'subtotal' => $totals['subtotal'] ?? '0.00', 'discount_total' => $totals['discount_total'] ?? '0.00',
                'discounts' => array_map(fn (array $row) => ['title' => $row['title'], 'code' => $row['code'] ?? null, 'amount' => $row['amount']], $totals['discounts'] ?? []),
                'shipping_total' => $totals['shipping_total'] ?? '0.00', 'shipping_title' => $totals['shipping']['title'] ?? null,
                'tax_total' => $totals['tax_total'] ?? '0.00', 'tax_included' => (bool) ($totals['tax_included'] ?? false), 'grand_total' => $draft->grand_total,
            ],
            'requires_shipping' => (bool) ($totals['requires_shipping'] ?? true),
            'has_digital' => (bool) ($totals['has_digital'] ?? false),
            'fields' => app(CheckoutFields::class)->effective($store, null, (bool) ($totals['requires_shipping'] ?? true), (bool) ($totals['has_digital'] ?? false), (bool) ($totals['requires_whatsapp'] ?? false)),
            'payment_methods' => $this->methods($store, $draft)->map(fn (StorePaymentGateway $setting) => [
                'slug' => $setting->gateway, ...PaymentGatewayCatalog::presentation($setting),
                'test_mode' => $setting->gateway !== 'cod' && $setting->test_mode,
                'grand_total' => app(PaymentPriceAdjustment::class)->forMethod($store, $setting->gateway, ['grand_total' => (string) $draft->grand_total])['grand_total'],
            ])->values(),
        ]], 200, self::HEADERS, JSON_UNESCAPED_UNICODE);
    }

    public function checkout(Request $request, string $token, DraftOrderConversion $conversion, StorePaymentService $payments, PaymentRetryToken $retryTokens): JsonResponse
    {
        $store = $this->currentStore($request);
        $draft = $this->find($store, $token, true);
        $totals = $draft->totals ?? [];
        $requiresShipping = (bool) ($totals['requires_shipping'] ?? true);
        $method = $request->validate(['payment_method' => ['required', 'string', 'max:80']])['payment_method'];
        $setting = $this->methods($store, $draft)->first(fn (StorePaymentGateway $row) => $row->gateway === $method);
        if ($setting === null) {
            throw ValidationException::withMessages(['payment_method' => 'The selected payment method is not available for this invoice.']);
        }
        if (in_array($setting->gateway, HostedPaymentGateways::PROVIDERS, true)) {
            app(RegionalHostedPayments::class)->validate($setting, strtoupper((string) $store->currency));
        }
        $rules = app(CheckoutFields::class)->rules($store, $method, $requiresShipping, (bool) ($totals['has_digital'] ?? false), (bool) ($totals['requires_whatsapp'] ?? false));
        $data = Validator::make($request->all(), $rules + ['payment_method' => ['required', 'string', 'max:80']])->validate();

        $result = $conversion->complete($store, $draft->id, [
            'source' => 'invoice', 'payment_method' => $setting->gateway,
            'customer' => ['name' => $data['customer_name'] ?? null, 'email' => $data['customer_email'] ?? null, 'phone' => $data['customer_phone'] ?? null],
            'shipping_address' => $requiresShipping ? array_filter($data['shipping_address'] ?? [], fn ($value) => filled($value)) : null,
            'notes' => $data['notes'] ?? null,
        ]);
        $order = $result['order'];
        if (! $result['created']) {
            $setting = $this->methods($store, $draft)->first(fn (StorePaymentGateway $row) => $row->gateway === $order->payment_method) ?? $setting;
        }
        $body = ['data' => new StoreOrderResource($order), 'receipt' => app(OrderReceipt::class)->make($order), 'created' => $result['created']];
        try {
            $body['payment'] = $payments->start($store, $order, $setting);
        } catch (ValidationException $exception) {
            return response()->json($body + [
                'message' => 'Your order was created, but the payment session could not start. Retry payment without placing another order.',
                'errors' => $exception->errors(),
                'payment_retry' => ['token' => $retryTokens->make($store->id, $order->id), 'expires_in' => 7200],
            ], 422, self::HEADERS, JSON_UNESCAPED_UNICODE);
        }

        return response()->json($body, $result['created'] ? 201 : 200, self::HEADERS, JSON_UNESCAPED_UNICODE);
    }

    private function find(Store $store, string $token, bool $allowCompleted = false): StoreDraftOrder
    {
        abort_unless(preg_match('/^[a-f0-9]{48}$/', $token) === 1, 404);
        $draft = StoreDraftOrder::forStore($store)->where('invoice_token_hash', StoreDraftOrder::hashToken($token))->first();
        abort_if($draft === null || $draft->status === 'cancelled', 404);
        if ($draft->status === 'completed') {
            // A repeated confirmation of the same invoice returns its order; viewing a paid invoice does not.
            abort_unless($allowCompleted, 409, 'This invoice has already been completed.');

            return $draft;
        }
        abort_if($draft->expired(), 404);

        return $draft;
    }

    /** @return Collection<int, StorePaymentGateway> */
    private function methods(Store $store, StoreDraftOrder $draft)
    {
        $requiresShipping = (bool) ($draft->totals['requires_shipping'] ?? true);
        $methods = StorePaymentGateway::query()->where('store_id', $store->id)->where('enabled', true)
            ->when(! $requiresShipping, fn ($query) => $query->where('gateway', '!=', 'cod'))
            ->orderBy('sort_order')->get()
            ->filter(fn (StorePaymentGateway $setting) => PaymentGatewayCatalog::offered($setting, $store->currency))->values();
        if ($methods->isEmpty() && $requiresShipping) {
            $methods->push((new StorePaymentGateway)->forceFill(['store_id' => $store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => false, 'sort_order' => 0, 'credentials' => []]));
        }

        return $methods;
    }
}

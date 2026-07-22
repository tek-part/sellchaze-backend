<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentGatewayApiResource;
use App\Models\GatewayTransaction;
use App\Models\PaymentGateway;
use App\Services\CurrencyRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GatewaysApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rateService = app(CurrencyRateService::class);
        $query = PaymentGateway::query()
            ->with('wallet')
            ->withCount(['transactions as paid_orders_count' => function ($q) {
                $q->where('type', 'order_payment')->where('reference_type', 'order');
            }])
            ->withSum(['transactions as paid_orders_total_amount' => function ($q) {
                $q->where('type', 'order_payment')->where('reference_type', 'order');
            }], 'amount')
            ->orderBy('name');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function (PaymentGateway $gateway) use ($rateService) {
            $wallet = $this->computeUsdWalletBalance($gateway->id, $rateService);
            $gateway->setAttribute('wallet_balance', $wallet['wallet_balance_usd']);

            return $gateway;
        });

        return PaymentGatewayApiResource::collection($paginator)->response();
    }

    public function show(PaymentGateway $gateway, Request $request): JsonResponse
    {
        $gateway->load('wallet')
            ->loadCount(['transactions as paid_orders_count' => function ($q) {
                $q->where('type', 'order_payment')->where('reference_type', 'order');
            }])
            ->loadSum(['transactions as paid_orders_total_amount' => function ($q) {
                $q->where('type', 'order_payment')->where('reference_type', 'order');
            }], 'amount');

        $txQuery = GatewayTransaction::query()
            ->where('gateway_id', $gateway->id)
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $txQuery->where('type', $request->string('type')->toString());
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $transactions = $txQuery->paginate($perPage);
        $orderReferenceIds = collect($transactions->items())
            ->where('reference_type', 'order')
            ->pluck('reference_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $orderCodes = $orderReferenceIds->isEmpty()
            ? collect()
            : DB::table('orders')
                ->whereIn('id', $orderReferenceIds->all())
                ->get(['id', 'code', 'currency'])
                ->keyBy('id');
        $currencyTotals = DB::table('gateway_transactions as gt')
            ->join('orders as o', 'o.id', '=', 'gt.reference_id')
            ->where('gt.gateway_id', $gateway->id)
            ->where('gt.type', 'order_payment')
            ->where('gt.reference_type', 'order')
            ->selectRaw("COALESCE(NULLIF(o.currency, ''), 'N/A') as currency, SUM(gt.amount) as total_amount, COUNT(*) as orders_count")
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) $row->currency,
                'total_amount' => (float) $row->total_amount,
                'orders_count' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        $rateService = app(CurrencyRateService::class);
        $wallet = $this->computeUsdWalletBalance($gateway->id, $rateService);
        $gateway->setAttribute('wallet_balance', $wallet['wallet_balance_usd']);
        $missingRateCurrencies = [];
        $usdTotalAmount = 0.0;
        $serializedTransactions = collect($transactions->items())->map(function ($tx) use ($orderCodes, $rateService, &$missingRateCurrencies, &$usdTotalAmount) {
            $currency = $tx->reference_type === 'order'
                ? ($orderCodes[(int) $tx->reference_id]->currency ?? null)
                : null;
            $rateToUsd = $currency ? $rateService->getEffectiveRateToUsd((string) $currency) : null;
            $amountUsd = null;
            if ($currency && $rateToUsd !== null) {
                $amountUsd = round(((float) $tx->amount) * $rateToUsd, 6);
                $usdTotalAmount += $amountUsd;
            } elseif ($currency) {
                $missingRateCurrencies[] = strtoupper((string) $currency);
            }

            return [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'reference_type' => $tx->reference_type,
                'reference_id' => $tx->reference_id,
                'reference_code' => $tx->reference_type === 'order'
                    ? ($orderCodes[(int) $tx->reference_id]->code ?? null)
                    : null,
                'currency' => $currency,
                'amount_usd' => $amountUsd,
                'applied_rate_to_usd' => $rateToUsd,
                'notes' => $tx->notes,
                'created_at' => optional($tx->created_at)?->toISOString(),
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                ...PaymentGatewayApiResource::make($gateway)->resolve(),
                'usd_total_amount' => round($usdTotalAmount, 6),
                'wallet_balance_usd' => $wallet['wallet_balance_usd'],
                'transactions' => $serializedTransactions,
                'currency_totals' => $currencyTotals,
                'missing_rate_currencies' => array_values(array_unique(array_merge($missingRateCurrencies, $wallet['missing_rate_currencies']))),
            ],
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'from' => $transactions->firstItem(),
                'last_page' => $transactions->lastPage(),
                'path' => $transactions->path(),
                'per_page' => $transactions->perPage(),
                'to' => $transactions->lastItem(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function update(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('payment_gateways', 'slug')->ignore($gateway->id)],
        ]);

        $name = trim((string) $validated['name']);
        $slug = Str::slug((string) ($validated['slug'] ?? $name), '_');

        $gateway->update([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'success' => true,
            'data' => PaymentGatewayApiResource::make($gateway->fresh('wallet')),
        ]);
    }

    public function destroy(PaymentGateway $gateway): JsonResponse
    {
        $gateway->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Sum all gateway transactions after converting each amount to USD.
     *
     * For non-order references, amount is considered already in USD.
     *
     * @return array{wallet_balance_usd: float, missing_rate_currencies: string[]}
     */
    private function computeUsdWalletBalance(int $gatewayId, CurrencyRateService $rateService): array
    {
        $txRows = GatewayTransaction::query()
            ->where('gateway_id', $gatewayId)
            ->get(['amount', 'reference_type', 'reference_id']);

        $orderIds = $txRows
            ->where('reference_type', 'order')
            ->pluck('reference_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $orderCurrencies = $orderIds->isEmpty()
            ? collect()
            : DB::table('orders')
                ->whereIn('id', $orderIds->all())
                ->pluck('currency', 'id');

        $totalUsd = 0.0;
        $missing = [];
        foreach ($txRows as $tx) {
            if ($tx->reference_type !== 'order') {
                $totalUsd += (float) $tx->amount;

                continue;
            }

            $currency = strtoupper(trim((string) ($orderCurrencies[(int) $tx->reference_id] ?? '')));
            if ($currency === '') {
                $missing[] = 'N/A';

                continue;
            }

            $rateToUsd = $rateService->getEffectiveRateToUsd($currency);
            if ($rateToUsd === null) {
                $missing[] = $currency;

                continue;
            }

            $totalUsd += ((float) $tx->amount * $rateToUsd);
        }

        return [
            'wallet_balance_usd' => round($totalUsd, 6),
            'missing_rate_currencies' => array_values(array_unique($missing)),
        ];
    }
}

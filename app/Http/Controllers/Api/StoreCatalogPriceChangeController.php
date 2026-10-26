<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePriceChange;
use App\Services\StoreCatalog\CatalogPriceChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bulk price changes for a category or selected products, with preview, audit trail and one-step revert. */
class StoreCatalogPriceChangeController extends Controller
{
    public function __construct(private readonly CatalogPriceChanges $changes) {}

    public function index(Request $request, Store $store): JsonResponse
    {
        $rows = StorePriceChange::query()->with('actor:id,name')->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($row) => $this->changes->present($row))->all()], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function preview(Request $request, Store $store): JsonResponse
    {
        return response()->json(['data' => $this->changes->preview($this->validated($request))], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function apply(Request $request, Store $store): JsonResponse
    {
        $data = $this->validated($request, ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'idempotency_key' => ['required', 'uuid']]);

        return response()->json(['data' => $this->changes->present($this->changes->apply($data, $store, $request->user()))], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function revert(Request $request, Store $store, int $change): JsonResponse
    {
        return response()->json(['data' => $this->changes->present($this->changes->revert($change, $store, $request->user()))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function validated(Request $request, array $extra = []): array
    {
        $percentDecrease = $request->input('kind') === 'percent' && $request->input('direction') === 'decrease';

        return $request->validate([
            'scope' => ['required', 'array:type,category_id,product_ids'],
            'scope.type' => ['required', 'in:category,products'],
            'scope.category_id' => ['exclude_unless:scope.type,category', 'required', 'integer', 'min:1'],
            'scope.product_ids' => ['exclude_unless:scope.type,products', 'required', 'array', 'list', 'min:1', 'max:'.CatalogPriceChanges::MAX_PRODUCTS],
            'scope.product_ids.*' => ['integer', 'min:1', 'distinct'],
            'direction' => ['required', 'in:increase,decrease'],
            'kind' => ['required', 'in:percent,amount'],
            'value' => ['required', 'numeric', 'gt:0', 'max:'.($percentDecrease ? '100' : '999999999.99'), 'decimal:0,4'],
            'apply_compare' => ['sometimes', 'boolean'],
            'apply_variants' => ['sometimes', 'boolean'],
            'rounding' => ['sometimes', 'in:none,round,ceil,floor'],
        ] + $extra);
    }
}

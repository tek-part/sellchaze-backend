<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\Rbac\UserScope;
use App\Services\WarehouseStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransfersApiController extends Controller
{
    public function __construct(
        private readonly WarehouseStockService $stockService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockTransfer::query()
            ->with([
                'fromWarehouse',
                'toWarehouse',
                'lines.product' => fn ($q) => $q->select('id', 'name', 'image'),
            ]);

        if (! UserScope::isAdmin($request->user())) {
            $merchantId = UserScope::effectiveMerchantUserId($request->user());
            $query->whereDoesntHave('lines.product', fn ($q) => $q->where('user_id', '!=', $merchantId));
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $data = StockTransferResource::collection($paginator->getCollection())->resolve();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $transfer = $this->stockService->transfer(
            $request->user(),
            (int) $validated['from_warehouse_id'],
            (int) $validated['to_warehouse_id'],
            $validated['lines'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'data' => (new StockTransferResource($transfer))->resolve(),
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }
}

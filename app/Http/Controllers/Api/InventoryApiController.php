<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseInventoryResource;
use App\Models\WarehouseInventory;
use App\Services\Rbac\UserScope;
use App\Services\WarehouseStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryApiController extends Controller
{
    public function __construct(
        private readonly WarehouseStockService $stockService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WarehouseInventory::query()
            ->with([
                'warehouse',
                'product' => fn ($q) => $q->select('id', 'name', 'image', 'category_id', 'user_id'),
            ])
            ->whereHas('product', function ($q) use ($request) {
                if (! UserScope::isAdmin($request->user())) {
                    $q->where('user_id', UserScope::effectiveMerchantUserId($request->user()));
                }
            });

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->get('warehouse_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->get('product_id'));
        }

        if ($request->boolean('low_stock')) {
            $threshold = (int) config('warehouse.low_stock_threshold', 5);
            $query->whereRaw('(qty - COALESCE(reserved_qty, 0)) <= ?', [$threshold]);
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $data = WarehouseInventoryResource::collection($paginator->getCollection())->resolve();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'low_stock_threshold' => (int) config('warehouse.low_stock_threshold', 5),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['sometimes', 'integer', 'min:0'],
            'reserved_qty' => ['sometimes', 'integer', 'min:0'],
        ]);

        $line = $this->stockService->createLine(
            $request->user(),
            (int) $validated['warehouse_id'],
            (int) $validated['product_id'],
            (int) ($validated['qty'] ?? 0),
            (int) ($validated['reserved_qty'] ?? 0)
        );

        $line->load([
            'warehouse',
            'product' => fn ($q) => $q->select('id', 'name', 'image', 'category_id', 'user_id'),
        ]);

        return response()->json([
            'data' => (new WarehouseInventoryResource($line))->resolve(),
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, int $inventory): JsonResponse
    {
        $row = $this->resolveInventory($request, $inventory);

        $validated = $request->validate([
            'qty' => ['required', 'integer', 'min:0'],
            'reserved_qty' => ['required', 'integer', 'min:0'],
        ]);

        $updated = $this->stockService->updateLine(
            $row,
            $request->user(),
            (int) $validated['qty'],
            (int) $validated['reserved_qty']
        );

        $updated->load([
            'warehouse',
            'product' => fn ($q) => $q->select('id', 'name', 'image', 'category_id', 'user_id'),
        ]);

        return response()->json([
            'data' => (new WarehouseInventoryResource($updated))->resolve(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, int $inventory): JsonResponse
    {
        $row = $this->resolveInventory($request, $inventory);
        $this->stockService->assertCanManageProduct($request->user(), (int) $row->product_id);
        $row->delete();

        return response()->json(['message' => 'Deleted.'], 200);
    }

    private function resolveInventory(Request $request, int $id): WarehouseInventory
    {
        $query = WarehouseInventory::query()->whereKey($id);

        if (! UserScope::isAdmin($request->user())) {
            $merchantId = UserScope::effectiveMerchantUserId($request->user());
            $query->whereHas('product', fn ($q) => $q->where('user_id', $merchantId));
        }

        /** @var WarehouseInventory $row */
        $row = $query->firstOrFail();

        return $row;
    }
}

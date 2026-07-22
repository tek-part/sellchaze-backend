<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class WarehousesApiController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Warehouse::query()
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => WarehouseResource::collection($rows)->resolve(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}

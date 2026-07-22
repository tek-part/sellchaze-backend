<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderSummaryResource;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = (new DashboardStatsService($user))->build();

        $recentOrders = OrderSummaryResource::collection($stats['recent_orders'])->resolve($request);

        return response()->json([
            'is_admin' => $stats['is_admin'],
            'dashboard_mode' => $stats['is_admin'] ? 'admin' : ($stats['is_supplier'] ? 'supplier' : 'merchant'),
            'counts' => [
                'orders_out' => $stats['orders_out_count'],
                'orders_in' => $stats['orders_in_count'],
                'orders_assigned_total' => $stats['orders_assigned_total_count'],
                'orders_awaiting_quotation' => $stats['orders_awaiting_quotation_count'],
                'quotations_out' => $stats['quotations_out_count'],
                'quotations_in' => $stats['quotations_in_count'],
                'deals_out' => $stats['deals_out_count'],
                'deals_in' => $stats['deals_in_count'],
                'users' => $stats['users_count'],
                'products' => $stats['products_count'],
                'categories' => $stats['categories_count'],
                'bundles' => $stats['bundles_count'],
            ],
            'orders_chart' => $stats['orders_chart'],
            'orders_by_status' => $stats['orders_by_status'],
            'recent_orders' => $recentOrders,
        ]);
    }
}

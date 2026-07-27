<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\Product;
use App\Models\User;
use App\Services\Rbac\UserScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardStatsService
{
    public function __construct(private User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $isAdmin = UserScope::isAdmin($this->user);
        $isSupplier = UserScope::isSupplier($this->user);
        $isSupplierOnly = $isSupplier && ! $isAdmin;
        $effectiveUserId = UserScope::effectiveMerchantUserId($this->user);
        $storeWideDashboard = $isAdmin || $this->user->hasAnyRole(['Staff', 'Manager']);

        if ($storeWideDashboard) {
            // Must match Api\OrdersApiController: direction=in is all orders for admin/internal team; direction=out requires a supplier assignment.
            $ordersInCount = Order::count();
            $ordersOutCount = Order::whereHas('suppliers', fn ($q) => $q->whereNotNull('supplier'))->count();
            // out/in are identical for the store-wide view; compute each aggregate once
            // (the two queries were byte-identical) and reuse — same values, fewer queries.
            $quotationsTotalCount = OrderQuotations::count();
            $quotationsOutCount = $quotationsTotalCount;
            $quotationsInCount = $quotationsTotalCount;
            $acceptedDealsCount = OrderQuotations::where('status', 'accepted')->count();
            $dealsOutCount = $acceptedDealsCount;
            $dealsInCount = $acceptedDealsCount;
            $ordersAssignedTotal = null;
            $ordersAwaitingQuotation = null;
        } elseif ($isSupplierOnly) {
            $supplierId = (int) $this->user->id;
            $supplierOrdersBaseQuery = Order::whereHas('suppliers', fn ($q) => $q->where('supplier', $supplierId));

            $ordersOutCount = (clone $supplierOrdersBaseQuery)->count();
            $ordersInCount = null;
            $ordersAssignedTotal = null;
            $ordersAwaitingQuotation = (int) countOrders('supplier');
            $quotationsOutCount = OrderQuotations::where('supplier_user_id', $supplierId)->count();
            $quotationsInCount = null;
            $dealsOutCount = null;
            $dealsInCount = OrderQuotations::where('supplier_user_id', $supplierId)->where('status', 'accepted')->count();
        } else {
            $ordersOutCount = (int) countOrders('customer');
            $ordersInCount = (int) countOrders('supplier');
            $quotationsOutCount = OrderQuotations::where('supplier_user_id', $effectiveUserId)->count();
            $quotationsInCount = Order::whereHas('suppliers', fn ($q) => $q->where('customer', $effectiveUserId))->whereHas('quotations')->count();
            $dealsOutCount = OrderQuotations::where('customer_user_id', $effectiveUserId)->where('status', 'accepted')->count();
            $dealsInCount = OrderQuotations::where('supplier_user_id', $effectiveUserId)->where('status', 'accepted')->count();
            $ordersAssignedTotal = null;
            $ordersAwaitingQuotation = null;
        }

        $usersCount = $isAdmin ? User::count() : null;
        $productsCount = $isSupplierOnly
            ? null
            : ($storeWideDashboard ? Product::count() : Product::where('user_id', $effectiveUserId)->count());
        $categoriesCount = $isSupplierOnly
            ? null
            : ($storeWideDashboard ? Category::count() : Category::where('user_id', $effectiveUserId)->count());
        $bundlesCount = $isSupplierOnly
            ? null
            : (Schema::hasTable('bundles')
                ? ($storeWideDashboard ? Bundle::count() : Bundle::where('user_id', $effectiveUserId)->count())
                : 0);

        $ordersBaseQuery = Order::query();
        if ($isSupplierOnly) {
            $supplierId = (int) $this->user->id;
            $ordersBaseQuery->whereHas('suppliers', fn ($q) => $q->where('supplier', $supplierId));
        } elseif (! $storeWideDashboard) {
            $ordersBaseQuery->where(function ($q) use ($effectiveUserId) {
                $q->where('user_id', $effectiveUserId)
                    ->orWhereHas('suppliers', fn ($sq) => $sq->where('customer', $effectiveUserId))
                    ->orWhereHas('suppliers', fn ($sq) => $sq->where('supplier', $effectiveUserId));
            });
        }

        $recentOrders = (clone $ordersBaseQuery)->with(['product', 'user'])
            ->latest()
            ->take(10)
            ->get();

        $ordersChartData = (clone $ordersBaseQuery)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'orders' => (int) $row->count])
            ->toArray();

        $filledChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->format('Y-m-d');
            $found = collect($ordersChartData)->firstWhere('date', $d);
            $filledChartData[] = ['date' => $d, 'orders' => $found ? $found['orders'] : 0];
        }

        $ordersByStatus = (clone $ordersBaseQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status ?? 'pending', 'count' => (int) $row->count])
            ->toArray();

        return [
            'orders_out_count' => $ordersOutCount,
            'orders_in_count' => $ordersInCount,
            'orders_assigned_total_count' => $ordersAssignedTotal,
            'orders_awaiting_quotation_count' => $ordersAwaitingQuotation,
            'quotations_out_count' => $quotationsOutCount,
            'quotations_in_count' => $quotationsInCount,
            'deals_out_count' => $dealsOutCount,
            'deals_in_count' => $dealsInCount,
            'users_count' => $usersCount,
            'products_count' => $productsCount,
            'categories_count' => $categoriesCount,
            'bundles_count' => $bundlesCount,
            'recent_orders' => $recentOrders,
            'orders_chart' => $filledChartData,
            'orders_by_status' => $ordersByStatus,
            'is_admin' => $isAdmin,
            'is_supplier' => $isSupplierOnly,
        ];
    }
}

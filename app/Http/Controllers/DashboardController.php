<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderQuotations;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $userId = $user->id;
        $isAdmin = ((int) $userId === 1) || ($user->email === 'admin@admin.com') || $user->hasRole('Admin');
        $effectiveUserId = $isAdmin ? $userId : b2bListingsUserId();

        if ($isAdmin) {
            $ordersOutCount = Order::count();
            $ordersInCount = Order::count();
            $quotationsOutCount = OrderQuotations::count();
            $quotationsInCount = OrderQuotations::count();
            $dealsOutCount = OrderQuotations::where('status', 'accepted')->count();
            $dealsInCount = OrderQuotations::where('status', 'accepted')->count();
        } else {
            $ordersOutCount = (int) countOrders('customer');
            $ordersInCount = (int) countOrders('supplier');
            $quotationsOutCount = OrderQuotations::where('supplier_user_id', $effectiveUserId)->count();
            $quotationsInCount = Order::whereHas('suppliers', fn ($q) => $q->where('customer', $effectiveUserId))->whereHas('quotations')->count();
            $dealsOutCount = OrderQuotations::where('customer_user_id', $effectiveUserId)->where('status', 'accepted')->count();
            $dealsInCount = OrderQuotations::where('supplier_user_id', $effectiveUserId)->where('status', 'accepted')->count();
        }

        $usersCount = $isAdmin ? User::count() : null;
        $productsCount = $isAdmin ? Product::count() : Product::where('user_id', $effectiveUserId)->count();
        $categoriesCount = Category::count();

        $ordersBaseQuery = Order::query();
        if (! $isAdmin) {
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

        // Chart data: orders per day (last 7 days)
        $ordersChartData = (clone $ordersBaseQuery)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'orders' => (int) $row->count])
            ->toArray();

        // Fill missing days with 0
        $filledChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->format('Y-m-d');
            $found = collect($ordersChartData)->firstWhere('date', $d);
            $filledChartData[] = ['date' => $d, 'orders' => $found ? $found['orders'] : 0];
        }

        // Orders by status (for pie chart)
        $ordersByStatus = (clone $ordersBaseQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status ?? 'pending', 'count' => (int) $row->count])
            ->toArray();

        return view('pages.dashboards.index', [
            'title' => 'Dashboard',
            'breadcrumb' => 'Dashboard',
            'ordersOutCount' => $ordersOutCount,
            'ordersInCount' => $ordersInCount,
            'quotationsOutCount' => $quotationsOutCount,
            'quotationsInCount' => $quotationsInCount,
            'dealsOutCount' => $dealsOutCount,
            'dealsInCount' => $dealsInCount,
            'usersCount' => $usersCount,
            'productsCount' => $productsCount,
            'categoriesCount' => $categoriesCount,
            'recentOrders' => $recentOrders,
            'ordersChartData' => $filledChartData,
            'ordersByStatus' => $ordersByStatus,
        ]);
    }
}

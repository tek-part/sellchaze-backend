<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6F: read-only merchant analytics over the storefront commerce tables.
 * Every query filters explicitly by the owner-resolved store id, so it is
 * tenant-safe independent of the global StoreScope, and uses the query builder
 * (stdClass rows) with SQL-side aggregation — constant query counts, no N+1.
 *
 * "Completed" revenue uses the terminal success status `delivered` (verified:
 * the StoreOrder lifecycle has no separate `completed` status).
 */
class StoreAnalyticsService
{
    private const REVENUE_STATUS = 'delivered';

    private const CACHE_TTL = 60; // seconds — bounded staleness; invalidated on order/review writes

    public static function cacheKey(int $storeId): string
    {
        return "analytics:{$storeId}:overview";
    }

    /** Invalidate a store's cached analytics overview. */
    public static function forget(int $storeId): void
    {
        Cache::forget(self::cacheKey($storeId));
    }

    /** @return array<string, mixed> */
    public function overview(Store $store): array
    {
        return Cache::remember(self::cacheKey($store->id), self::CACHE_TTL, fn () => $this->buildOverview($store));
    }

    /** @return array<string, mixed> */
    private function buildOverview(Store $store): array
    {
        $sid = $store->id;
        $revenue = $this->revenue($sid);
        $orders = $this->orderStatusCounts($sid);
        $totalOrders = array_sum($orders);

        $deliveredCount = $orders['delivered_orders'];
        $totalItems = (int) DB::table('store_order_items as i')
            ->join('store_orders as o', 'i.store_order_id', '=', 'o.id')
            ->where('o.store_id', $sid)->where('o.status', self::REVENUE_STATUS)
            ->sum('i.quantity');

        return [
            'revenue' => $revenue,
            'orders' => $orders,
            'sales' => [
                'total_orders' => $totalOrders,
                'average_order_value' => $deliveredCount > 0 ? bcdiv($revenue['total_revenue'], (string) $deliveredCount, 2) : '0.00',
                'total_items_sold' => $totalItems,
            ],
            'customers' => $this->customerSummary($store),
            'coupons' => $this->couponAnalytics($sid),
            'reviews' => $this->reviewAnalytics($sid),
            'top_products' => $this->topProducts($store, 5),
        ];
    }

    /** @return array{today_revenue:string, week_revenue:string, month_revenue:string, total_revenue:string} */
    private function revenue(int $sid): array
    {
        $row = DB::table('store_orders')
            ->where('store_id', $sid)->where('status', self::REVENUE_STATUS)
            ->selectRaw('COALESCE(SUM(grand_total),0) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN placed_at >= ? THEN grand_total ELSE 0 END),0) AS today', [Carbon::today()])
            ->selectRaw('COALESCE(SUM(CASE WHEN placed_at >= ? THEN grand_total ELSE 0 END),0) AS week', [Carbon::now()->startOfWeek()])
            ->selectRaw('COALESCE(SUM(CASE WHEN placed_at >= ? THEN grand_total ELSE 0 END),0) AS month', [Carbon::now()->startOfMonth()])
            ->first();

        return [
            'today_revenue' => $this->money($row?->today),
            'week_revenue' => $this->money($row?->week),
            'month_revenue' => $this->money($row?->month),
            'total_revenue' => $this->money($row?->total),
        ];
    }

    /** @return array<string, int> */
    private function orderStatusCounts(int $sid): array
    {
        $rows = DB::table('store_orders')->where('store_id', $sid)
            ->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status');

        $out = [];
        foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $status) {
            $out["{$status}_orders"] = (int) ($rows[$status] ?? 0);
        }

        return $out;
    }

    /** @return array{total_customers:int, returning_customers:int, new_customers_this_month:int} */
    public function customerSummary(Store $store): array
    {
        $sid = $store->id;

        $row = DB::table('store_customers')->where('store_id', $sid)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END),0) AS new_month', [Carbon::now()->startOfMonth()])
            ->first();

        $returningSub = DB::table('store_orders')->where('store_id', $sid)
            ->whereNotNull('store_customer_id')
            ->groupBy('store_customer_id')->havingRaw('COUNT(*) >= 2')
            ->select('store_customer_id');

        return [
            'total_customers' => (int) ($row?->total ?? 0),
            'returning_customers' => DB::query()->fromSub($returningSub, 't')->count(),
            'new_customers_this_month' => (int) ($row?->new_month ?? 0),
        ];
    }

    /** @return array{total_coupon_usage:string, coupon_redemptions:int, top_coupons:array<int,array<string,mixed>>} */
    private function couponAnalytics(int $sid): array
    {
        $totals = DB::table('coupon_usages')->where('store_id', $sid)
            ->selectRaw('COUNT(*) AS redemptions, COALESCE(SUM(discount_amount),0) AS total_discount')->first();

        $top = DB::table('coupon_usages as cu')->join('coupons as c', 'cu.coupon_id', '=', 'c.id')
            ->where('cu.store_id', $sid)
            ->groupBy('c.id', 'c.code')
            ->selectRaw('c.id, c.code, COUNT(*) AS redemptions, COALESCE(SUM(cu.discount_amount),0) AS total_discount')
            ->orderByDesc('redemptions')->limit(5)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => $r->code,
                'redemptions' => (int) $r->redemptions,
                'total_discount' => $this->money($r->total_discount),
            ])->all();

        return [
            'total_coupon_usage' => $this->money($totals?->total_discount),
            'coupon_redemptions' => (int) ($totals?->redemptions ?? 0),
            'top_coupons' => $top,
        ];
    }

    /** @return array{total_reviews:int, average_rating:float|null, review_distribution:array<int,int>} */
    private function reviewAnalytics(int $sid): array
    {
        $rows = DB::table('product_reviews')->where('store_id', $sid)->where('status', 'approved')
            ->groupBy('rating')->selectRaw('rating, COUNT(*) AS c')->pluck('c', 'rating');

        $distribution = [];
        $total = 0;
        $weighted = 0;
        for ($r = 1; $r <= 5; $r++) {
            $count = (int) ($rows[$r] ?? 0);
            $distribution[$r] = $count;
            $total += $count;
            $weighted += $r * $count;
        }

        return [
            'total_reviews' => $total,
            'average_rating' => $total > 0 ? round($weighted / $total, 2) : null,
            'review_distribution' => $distribution,
        ];
    }

    /** @return array<int, array{id:int, name:string, quantity_sold:int, revenue_generated:string}> */
    public function topProducts(Store $store, int $limit): array
    {
        return DB::table('store_order_items as i')
            ->join('store_orders as o', 'i.store_order_id', '=', 'o.id')
            ->join('products as p', 'i.store_product_id', '=', 'p.id')
            ->where('o.store_id', $store->id)->where('o.status', self::REVENUE_STATUS)
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.id, p.name, SUM(i.quantity) AS quantity_sold, COALESCE(SUM(i.line_total),0) AS revenue_generated')
            ->orderByDesc('quantity_sold')->limit($limit)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'quantity_sold' => (int) $r->quantity_sold,
                'revenue_generated' => $this->money($r->revenue_generated),
            ])->all();
    }

    /** @return array<int, array{category:string, category_id:int, quantity_sold:int, revenue_generated:string}> */
    public function topCategories(Store $store, int $limit): array
    {
        return DB::table('store_order_items as i')
            ->join('store_orders as o', 'i.store_order_id', '=', 'o.id')
            ->join('products as p', 'i.store_product_id', '=', 'p.id')
            ->join('categories as c', 'p.category_id', '=', 'c.id')
            ->where('o.store_id', $store->id)->where('o.status', self::REVENUE_STATUS)
            ->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, SUM(i.quantity) AS quantity_sold, COALESCE(SUM(i.line_total),0) AS revenue_generated')
            ->orderByDesc('quantity_sold')->limit($limit)->get()
            ->map(fn ($r) => [
                'category_id' => (int) $r->id,
                'category' => (string) $r->name,
                'quantity_sold' => (int) $r->quantity_sold,
                'revenue_generated' => $this->money($r->revenue_generated),
            ])->all();
    }

    /** Paginated customers with lifetime (delivered) spend + order count. */
    public function customers(Store $store, int $perPage): LengthAwarePaginator
    {
        $sid = $store->id;

        return DB::table('store_customers as sc')
            ->leftJoin('store_orders as o', fn ($j) => $j
                ->on('o.store_customer_id', '=', 'sc.id')
                ->where('o.store_id', '=', $sid)
                ->where('o.status', '=', self::REVENUE_STATUS))
            ->where('sc.store_id', $sid)
            ->groupBy('sc.id', 'sc.name', 'sc.email', 'sc.created_at')
            ->selectRaw('sc.id, sc.name, sc.email, sc.created_at, COUNT(o.id) AS orders_count, COALESCE(SUM(o.grand_total),0) AS total_spent')
            ->orderByDesc('total_spent')
            ->paginate($perPage);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

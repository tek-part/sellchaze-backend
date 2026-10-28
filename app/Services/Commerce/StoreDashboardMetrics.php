<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StoreDashboardMetrics
{
    public function build(Store $store, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->addDay()->startOfDay();
        $trackingStarted = DB::table('storefront_events')->where('store_id', $store->id)->min('occurred_at');
        $firstTrackedDay = $trackingStarted ? Carbon::parse($trackingStarted)->toDateString() : null;
        $orders = DB::table('store_orders')->where('store_id', $store->id)->where('placed_at', '>=', $start)->where('placed_at', '<', $end);
        $events = DB::table('storefront_events')->where('store_id', $store->id)->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end);
        $money = new StoreReportingMoney($store);
        $salesSql = $money->sum('grand_total', 'currency', "status='delivered'");
        $merchandiseSql = $money->sum('subtotal-discount_total', 'currency', "status='delivered'");
        $costSql = $money->sum('i.unit_cost*i.quantity', 'o.currency');
        $lineSql = $money->sum('i.line_total', 'o.currency');
        $report = $money->report((clone $orders)->where('status', 'delivered'));
        $dailyReports = $money->reports((clone $orders)->where('status', 'delivered'), 'grand_total', 'currency', [DB::raw('DATE(placed_at) as day')]);
        $counts = (clone $orders)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as delivered, $salesSql as sales, $merchandiseSql as merchandise")->first();
        $eventCounts = (clone $events)->groupBy('kind')->selectRaw('kind, COUNT(*) as actions, COUNT(DISTINCT session_key) as sessions')->get()->keyBy('kind');
        $visits = (int) ($eventCounts->get('visit')?->sessions ?? 0);
        $visitSessions = (clone $events)->where('kind', 'visit')->select('session_key')->distinct();
        $startedSessions = (clone $events)->where('kind', 'checkout_started')->whereIn('session_key', clone $visitSessions)->select('session_key')->distinct();
        $started = (clone $startedSessions)->count('session_key');
        $purchased = (clone $orders)->where('status', '!=', 'cancelled')->whereIn('analytics_session_hash', clone $visitSessions)->distinct()->count('analytics_session_hash');
        $crossSell = (clone $events)->where('kind', 'add_to_cart')->where('source', 'cross_sell')->count();
        $crossSessions = (clone $events)->where('kind', 'add_to_cart')->where('source', 'cross_sell')->whereIn('session_key', clone $startedSessions)->distinct()->count('session_key');
        $missed = DB::table('store_checkout_leads')->where('store_id', $store->id)->whereNull('converted_at')
            ->where('last_activity_at', '<=', now()->subMinutes(CheckoutLeads::INACTIVE_MINUTES))
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->where(fn (Builder $query) => $query->whereNotNull('customer_phone')->orWhereNotNull('customer_email'))->count();
        $costs = DB::table('store_order_items as i')->whereNull('i.superseded_at')->join('store_orders as o', 'o.id', '=', 'i.store_order_id')
            ->where('o.store_id', $store->id)->where('i.store_id', $store->id)->where('o.status', 'delivered')
            ->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end)
            ->selectRaw("$costSql as cost, COUNT(DISTINCT o.id) as orders_with_items, SUM(CASE WHEN i.unit_cost IS NULL THEN 1 ELSE 0 END) as unknown")->first();
        $delivered = (int) ($counts?->delivered ?? 0);
        $sales = StoreReportingMoney::decimal($counts?->sales);
        $profit = $delivered > (int) ($costs?->orders_with_items ?? 0) || (int) ($costs?->unknown ?? 0) > 0 || $counts?->merchandise === null || $costs?->cost === null ? null : bcsub(StoreReportingMoney::decimal($counts->merchandise), StoreReportingMoney::decimal($costs->cost), 2);
        $dailyOrders = (clone $orders)->selectRaw("DATE(placed_at) as day, COUNT(*) as orders, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as delivered, $salesSql as sales")->groupByRaw('DATE(placed_at)')->get()->keyBy('day');
        $dailyEvents = (clone $events)->selectRaw('DATE(occurred_at) as day, kind, COUNT(DISTINCT session_key) as sessions')->groupByRaw('DATE(occurred_at), kind')->get()->groupBy('day');
        $daily = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $key = $day->toDateString();
            $order = $dailyOrders->get($key);
            $kinds = $dailyEvents->get($key)?->keyBy('kind');
            $daySales = $order ? StoreReportingMoney::decimal($order->sales) : '0.00';
            $dayDelivered = (int) ($order?->delivered ?? 0);
            $trafficKnown = $firstTrackedDay !== null && $key >= $firstTrackedDay;
            $daily[] = ['date' => $key, 'orders' => (int) ($order?->orders ?? 0), 'sales' => $daySales, 'reporting_money' => $dailyReports[$key] ?? $money->empty(),
                'average' => StoreReportingMoney::average($daySales, $dayDelivered),
                'visits' => $trafficKnown ? (int) ($kinds?->get('visit')?->sessions ?? 0) : null,
                'checkout_started' => $trafficKnown ? (int) ($kinds?->get('checkout_started')?->sessions ?? 0) : null];
        }
        $top = DB::table('store_order_items as i')->whereNull('i.superseded_at')->join('store_orders as o', 'o.id', '=', 'i.store_order_id')
            ->leftJoin('products as p', fn ($join) => $join->on('p.id', '=', 'i.store_product_id')->on('p.store_id', '=', 'i.store_id'))
            ->where('o.store_id', $store->id)->where('i.store_id', $store->id)->where('o.status', 'delivered')->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end)
            ->selectRaw("i.store_product_id as product_id, COALESCE(p.name,MAX(i.name)) as name, SUM(i.quantity) as quantity, $lineSql as revenue")
            ->groupBy('i.store_product_id', 'p.name')->orderByDesc('quantity')->limit(5)->get()->map(fn ($row) => ['product_id' => $row->product_id, 'name' => $row->name, 'quantity' => (int) $row->quantity, 'revenue' => StoreReportingMoney::decimal($row->revenue)])->all();

        $lineReports = $money->reports(DB::table('store_order_items as i')->join('store_orders as o', 'o.id', '=', 'i.store_order_id')->where('o.store_id', $store->id)->where('i.store_id', $store->id)->whereNull('i.superseded_at')->where('o.status', 'delivered')->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end), 'i.line_total', 'o.currency', ['i.store_product_id']);
        $top = array_map(fn ($row) => $row + ['reporting_money' => $lineReports[$row['product_id']] ?? $money->empty()], $top);

        return ['period' => ['from' => $from, 'to' => $to, 'timezone' => config('app.timezone')], 'currency' => $store->currency ?: 'USD',
            'reporting_money' => $report,
            'summary' => ['visits' => $visits, 'orders' => (int) ($counts?->total ?? 0), 'average_order_value' => StoreReportingMoney::average($sales, $delivered),
                'add_to_cart' => (int) ($eventCounts->get('add_to_cart')?->actions ?? 0), 'checkout_started' => (int) ($eventCounts->get('checkout_started')?->actions ?? 0),
                'cross_sell' => $crossSell, 'new_orders' => (int) ($counts?->pending ?? 0), 'missed_orders' => $missed,
                'conversion_rate' => $this->rate($purchased, $visits), 'conversion_with_missed_rate' => $this->rate((int) ($counts?->total ?? 0), (int) ($counts?->total ?? 0) + $missed),
                'total_sales' => $sales, 'net_profit' => $profit],
            'daily' => $daily, 'low_stock' => $this->lowStock($store), 'top_products' => $top,
            'funnel' => ['visits' => $visits, 'started' => $started, 'purchased' => $purchased,
                'start_rate' => $this->rate($started, $visits), 'purchase_rate' => $this->rate($purchased, $visits), 'cross_sell_rate' => $this->rate($crossSessions, $started)],
            'tracking_started_at' => $trackingStarted,
            'profit_complete' => $profit !== null];
    }

    private function rate(int $part, int $whole): string
    {
        return $whole ? bcdiv(bcmul((string) $part, '100', 4), (string) $whole, 2) : '0.00';
    }

    /** SKU inventory respects reservations and the shared unused-code pool. No code values leave SQL. */
    private function lowStock(Store $store): array
    {
        $pool = DB::table('product_digital_codes')->where('store_id', $store->id)->whereNull('store_order_item_id')->selectRaw('product_id, COUNT(*) as available')->groupBy('product_id');
        $threshold = 'CASE WHEN p.reorder_level>5 THEN p.reorder_level ELSE 5 END';
        $query = function (bool $variants) use ($store, $pool, $threshold): Builder {
            $query = DB::table('products as p')->where('p.store_id', $store->id)->where('p.is_active', true);
            if ($variants) {
                $query->join('store_product_variants as v', fn ($join) => $join->on('v.store_product_id', '=', 'p.id')->on('v.store_id', '=', 'p.store_id'))->where('v.is_active', true);
            } else {
                $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('store_product_variants as v')->whereColumn('v.store_product_id', 'p.id')->whereColumn('v.store_id', 'p.store_id'));
            }
            $sku = $variants ? 'v' : 'p';
            $stock = "CASE WHEN {$sku}.stock_quantity-{$sku}.reserved_quantity<0 THEN 0 ELSE {$sku}.stock_quantity-{$sku}.reserved_quantity END";
            $available = "CASE WHEN p.digital_type='codes' THEN CASE WHEN {$sku}.track_inventory=1 AND ($stock)<COALESCE(pool.available,0) THEN ($stock) ELSE COALESCE(pool.available,0) END ELSE ($stock) END";

            return $query->leftJoinSub(clone $pool, 'pool', fn ($join) => $join->on('pool.product_id', '=', 'p.id'))
                ->where(fn (Builder $q) => $q->where($sku.'.track_inventory', true)->orWhere('p.digital_type', 'codes'))
                ->whereRaw("($available)<=($threshold)")
                ->selectRaw('p.id as product_id, '.($variants ? 'v.id' : 'NULL').' as variant_id, p.name, '.($variants ? 'v.name' : 'NULL').' as variant_name, ('.$available.') as available');
        };

        return $query(false)->unionAll($query(true))->orderBy('available')->limit(10)->get()->map(fn ($row) => ['product_id' => (int) $row->product_id,
            'variant_id' => $row->variant_id === null ? null : (int) $row->variant_id,
            'name' => $row->name.($row->variant_name ? ' — '.$row->variant_name : ''), 'available' => (int) $row->available])->all();
    }
}

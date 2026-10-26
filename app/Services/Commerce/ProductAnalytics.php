<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * G4 (#49, #7): per-product funnel and daily ordered-products reports.
 *
 * Storefront traffic is read only from `store_product_daily_metrics`, a rollup
 * incremented once per new event (views and cart/checkout sessions are counted
 * once per visitor session and product). Orders are read from real orders in the
 * store-local date range, excluding cancelled and deleted orders.
 */
class ProductAnalytics
{
    public const MAX_PRODUCTS = 500;

    public const MAX_SEGMENTS = 50;

    public const OTHER = ['utm_source' => '(other)', 'utm_medium' => null, 'utm_campaign' => null];

    public function timezone(Store $store): string
    {
        $zone = (string) ($store->timezone ?: config('app.timezone'));

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : (string) config('app.timezone');
    }

    /**
     * Campaign labels are stored lowercase, at most 64 characters, letters/digits and
     * " _ . + -" only, so arbitrary visitor text cannot multiply segments.
     *
     * @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string}
     */
    public function normaliseCampaign(array $data): array
    {
        $clean = function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }
            $value = mb_strtolower(trim($value));
            $value = (string) preg_replace('/[^\p{L}\p{N} _.+-]+/u', '-', $value);
            $value = trim((string) preg_replace(['/\s+/u', '/-{2,}/'], [' ', '-'], $value), ' -');

            $value = rtrim(mb_substr($value, 0, 64), ' -');

            return $value === '' ? null : $value;
        };

        return ['utm_source' => $clean($data['utm_source'] ?? null), 'utm_medium' => $clean($data['utm_medium'] ?? null), 'utm_campaign' => $clean($data['utm_campaign'] ?? null)];
    }

    /**
     * A store keeps at most MAX_SEGMENTS distinct campaigns per day; further campaigns
     * that day are counted under OTHER ("(other)" cannot come from a normalised label).
     *
     * @param  array{utm_source:?string,utm_medium:?string,utm_campaign:?string}  $campaign
     * @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string}
     */
    public function boundedCampaign(Store $store, array $campaign): array
    {
        if (! array_filter($campaign)) {
            return $campaign;
        }
        $day = now($this->timezone($store))->toDateString();
        $rows = DB::table('store_product_daily_metrics')->where('store_id', $store->id)->where('day', $day);
        if ((clone $rows)->where('segment', $this->segment($campaign))->exists()) {
            return $campaign;
        }

        return (clone $rows)->distinct()->count('segment') >= self::MAX_SEGMENTS ? self::OTHER : $campaign;
    }

    /** @param array{utm_source:?string,utm_medium:?string,utm_campaign:?string} $campaign */
    public function increment(Store $store, int $productId, string $session, string $kind, int $quantity, array $campaign): void
    {
        $column = ['product_view' => 'views', 'add_to_cart' => 'carts', 'checkout_started' => 'checkouts'][$kind] ?? null;
        if ($column === null) {
            return;
        }
        // Views are already unique per session and product; carts and checkouts count the session once
        // through a unique (session, step, product) row, while repeated adds still add units.
        $first = $kind === 'product_view' || DB::table('storefront_session_products')->insertOrIgnore([
            'store_id' => $store->id, 'session_key' => $session, 'kind' => $kind, 'product_id' => $productId, 'created_at' => now(),
        ]) === 1;
        $units = $kind === 'add_to_cart' ? max(0, $quantity) : 0;
        $counts = ['views' => 0, 'carts' => 0, 'cart_units' => $units, 'checkouts' => 0];
        $updates = [];
        if ($first) {
            $counts[$column] = 1;
            $updates[$column] = DB::raw($column.' + 1');
        }
        if ($units) {
            $updates['cart_units'] = DB::raw('cart_units + '.$units);
        }
        if (! $updates) {
            return;
        }
        DB::table('store_product_daily_metrics')->upsert([[
            'store_id' => $store->id, 'day' => now($this->timezone($store))->toDateString(), 'product_id' => $productId, 'segment' => $this->segment($campaign),
        ] + $campaign + $counts + ['updated_at' => now()]], ['store_id', 'day', 'product_id', 'segment'], $updates + ['updated_at' => now()]);
    }

    /** @param array{utm_source:?string,utm_medium:?string,utm_campaign:?string} $campaign */
    private function segment(array $campaign): string
    {
        return sha1(implode("\n", [$campaign['utm_source'] ?? '', $campaign['utm_medium'] ?? '', $campaign['utm_campaign'] ?? '']));
    }

    /** @param array{utm_source?:?string,utm_medium?:?string,utm_campaign?:?string} $filters */
    public function funnel(Store $store, string $from, string $to, array $filters = []): array
    {
        // Filters match the stored labels, which are normalised; "(other)" is the overflow segment.
        $filters = array_filter(array_map(fn ($value) => $value === self::OTHER['utm_source'] ? $value : $this->normaliseCampaign(['utm_source' => $value])['utm_source'],
            array_intersect_key($filters, array_flip(['utm_source', 'utm_medium', 'utm_campaign']))), fn ($value) => $value !== null && $value !== '');
        $traffic = DB::table('store_product_daily_metrics')->where('store_id', $store->id)->whereBetween('day', [$from, $to]);
        foreach ($filters as $column => $value) {
            $traffic->where($column, $value);
        }
        $events = (clone $traffic)->groupBy('product_id')
            ->selectRaw('product_id, SUM(views) as views, SUM(carts) as carts, SUM(cart_units) as cart_units, SUM(checkouts) as checkouts')
            ->get()->keyBy('product_id');
        $orders = $this->orderLines($store, $from, $to)->whereNotNull('i.store_product_id');
        $this->attribution($orders, $filters);
        $sales = $orders->groupBy('i.store_product_id')
            ->selectRaw('i.store_product_id as product_id, COUNT(DISTINCT o.id) as orders, SUM(i.quantity) as units, SUM(i.line_total) as revenue')
            ->get()->keyBy('product_id');
        $ids = $events->keys()->merge($sales->keys())->map(fn ($id) => (int) $id)->unique()->values();
        $names = DB::table('products')->where('store_id', $store->id)->whereIn('id', $ids)->pluck('name', 'id');
        $rows = $ids->map(function (int $id) use ($events, $sales, $names) {
            $event = $events->get($id);
            $sale = $sales->get($id);

            return $this->row(['product_id' => $id, 'name' => $names->get($id)], $event, $sale);
        })->sort(fn ($a, $b) => [$b['orders'], $b['views'], $a['product_id']] <=> [$a['orders'], $a['views'], $b['product_id']])->values();
        $totals = $this->row([], (object) ['views' => $rows->sum('views'), 'carts' => $rows->sum('carts'), 'cart_units' => $rows->sum('cart_units'), 'checkouts' => $rows->sum('checkouts')],
            (object) ['orders' => $this->countOrders($store, $from, $to, $filters), 'units' => $rows->sum('units'), 'revenue' => $rows->reduce(fn ($sum, $row) => bcadd($sum, $row['revenue'], 2), '0.00')]);
        $options = fn (string $column) => DB::table('store_product_daily_metrics')->where('store_id', $store->id)->whereBetween('day', [$from, $to])
            ->whereNotNull($column)->distinct()->orderBy($column)->limit(100)->pluck($column)->all();

        return ['period' => ['from' => $from, 'to' => $to, 'timezone' => $this->timezone($store)], 'currency' => $store->currency ?: 'USD',
            'filters' => $filters, 'totals' => $totals, 'products' => $rows->take(self::MAX_PRODUCTS)->all(), 'truncated' => $rows->count() > self::MAX_PRODUCTS,
            'options' => ['utm_source' => $options('utm_source'), 'utm_medium' => $options('utm_medium'), 'utm_campaign' => $options('utm_campaign')],
            'tracking_started_on' => DB::table('store_product_daily_metrics')->where('store_id', $store->id)->min('day')];
    }

    /** Products and variants ordered on one store-local day (#7). */
    public function orderedOn(Store $store, string $date): array
    {
        $groups = $this->orderLines($store, $date, $date)
            ->groupBy('i.store_product_id', 'i.variant_id', 'i.variant_name', 'i.sku')
            ->selectRaw('i.store_product_id as product_id, i.variant_id, i.variant_name, i.sku, MAX(i.name) as item_name, MIN(i.id) as sample_id, SUM(i.quantity) as quantity, COUNT(DISTINCT o.id) as orders')
            ->get();
        $options = DB::table('store_order_items')->where('store_id', $store->id)->whereIn('id', $groups->pluck('sample_id'))->pluck('variant_options', 'id');
        $names = DB::table('products')->where('store_id', $store->id)->whereIn('id', $groups->pluck('product_id')->filter())->pluck('name', 'id');
        $rows = $groups->map(function ($row) use ($options, $names) {
            $decoded = json_decode((string) $options->get($row->sample_id), true);

            return ['product_id' => $row->product_id === null ? null : (int) $row->product_id, 'name' => (string) ($names->get($row->product_id) ?? $row->item_name),
                'variant_id' => $row->variant_id === null ? null : (int) $row->variant_id, 'variant_name' => $row->variant_name, 'sku' => $row->sku,
                'options' => $this->options($decoded), 'quantity' => (int) $row->quantity, 'orders' => (int) $row->orders];
        })->sortBy([['name', 'asc'], ['variant_name', 'asc']])->values();

        return ['date' => $date, 'timezone' => $this->timezone($store), 'items' => $rows->all(),
            'totals' => ['quantity' => $rows->sum('quantity'), 'orders' => $this->countOrders($store, $date, $date), 'products' => $rows->pluck('product_id')->unique()->count()]];
    }

    /** @return list<array{name:string,value:string}> */
    private function options(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $name = $value['name'] ?? $value['option'] ?? (is_string($key) ? $key : null);
                $value = $value['value'] ?? null;
            } else {
                $name = is_string($key) ? $key : null;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $result[] = ['name' => (string) ($name ?? ''), 'value' => (string) $value];
            }
        }

        return $result;
    }

    private function orderLines(Store $store, string $from, string $to): Builder
    {
        [$start, $end] = $this->bounds($store, $from, $to);

        return DB::table('store_order_items as i')->join('store_orders as o', 'o.id', '=', 'i.store_order_id')
            ->where('o.store_id', $store->id)->where('i.store_id', $store->id)->whereNull('i.superseded_at')
            ->whereNull('o.deleted_at')->where('o.status', '!=', 'cancelled')
            ->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end);
    }

    private function countOrders(Store $store, string $from, string $to, array $filters = []): int
    {
        [$start, $end] = $this->bounds($store, $from, $to);
        $orders = DB::table('store_orders as o')->where('o.store_id', $store->id)->whereNull('o.deleted_at')->where('o.status', '!=', 'cancelled')
            ->where('o.placed_at', '>=', $start)->where('o.placed_at', '<', $end);
        $this->attribution($orders, $filters);

        return $orders->distinct()->count('o.id');
    }

    /**
     * Orders follow the campaign of their visitor session; orders placed before
     * session campaigns existed fall back to the source/campaign recorded on the order.
     */
    private function attribution(Builder $orders, array $filters): void
    {
        if (! $filters) {
            return;
        }
        $orders->leftJoin('storefront_session_campaigns as sc', fn ($join) => $join->on('sc.store_id', '=', 'o.store_id')->on('sc.session_key', '=', 'o.analytics_session_hash'));
        $orders->where(function (Builder $query) use ($filters) {
            $query->where(function (Builder $session) use ($filters) {
                $session->whereNotNull('sc.id');
                foreach ($filters as $column => $value) {
                    $session->where('sc.'.$column, $value);
                }
            });
            if (! isset($filters['utm_medium'])) {
                $query->orWhere(function (Builder $order) use ($filters) {
                    $order->whereNull('sc.id');
                    foreach (['utm_source' => 'source', 'utm_campaign' => 'campaign'] as $column => $key) {
                        if (isset($filters[$column])) {
                            $order->where('o.marketing_attribution->'.$key, $filters[$column]);
                        }
                    }
                });
            }
        });
    }

    /** @return array{0:Carbon,1:Carbon} UTC bounds of an inclusive store-local date range. */
    private function bounds(Store $store, string $from, string $to): array
    {
        $zone = $this->timezone($store);
        $start = Carbon::createFromFormat('Y-m-d', $from, $zone)->startOfDay()->utc();
        $end = Carbon::createFromFormat('Y-m-d', $to, $zone)->addDay()->startOfDay()->utc();

        return [$start, $end];
    }

    private function row(array $base, ?object $event, ?object $sale): array
    {
        $views = (int) ($event->views ?? 0);
        $carts = (int) ($event->carts ?? 0);
        $orders = (int) ($sale->orders ?? 0);

        return $base + ['views' => $views, 'carts' => $carts, 'cart_units' => (int) ($event->cart_units ?? 0), 'checkouts' => (int) ($event->checkouts ?? 0),
            'orders' => $orders, 'units' => (int) ($sale->units ?? 0), 'revenue' => $this->money($sale->revenue ?? 0),
            'cart_rate' => $this->rate($carts, $views), 'checkout_rate' => $this->rate((int) ($event->checkouts ?? 0), $views), 'conversion_rate' => $this->rate($orders, $views)];
    }

    private function rate(int $part, int $whole): ?string
    {
        return $whole ? bcdiv(bcmul((string) $part, '100', 4), (string) $whole, 2) : null;
    }

    private function money(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value))) {
            return bcadd((string) $value, '0', 2);
        }

        return number_format((float) $value, 2, '.', '');
    }
}

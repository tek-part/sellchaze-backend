<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shopify-style customer list built from orders, so guest checkouts (most cash-on-delivery stores)
 * count as customers too. A customer is identified by normalized phone, else lower-cased email,
 * else the single order. Cancelled orders are excluded from counts and totals.
 */
class StoreCustomerDirectory
{
    public const SORTS = ['last_order_at', 'first_order_at', 'orders_count', 'total_spent', 'name'];

    private const KEY = "COALESCE(NULLIF(customer_phone_normalized, ''), LOWER(NULLIF(customer_email, '')), CONCAT('order:', id))";

    public static function key(): string
    {
        // SQLite has no CONCAT(); use the ANSI operator there.
        return DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(NULLIF(customer_phone_normalized, ''), LOWER(NULLIF(customer_email, '')), 'order:' || id)"
            : self::KEY;
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(Store $store, array $filters, int $perPage): LengthAwarePaginator
    {
        $key = self::key();
        $query = StoreOrder::query()->withoutGlobalScopes()->where('store_id', $store->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->selectRaw("$key as customer_key")
            ->selectRaw('MAX(customer_name) as name, MAX(customer_email) as email, MAX(customer_phone) as phone, MAX(store_customer_id) as account_id')
            ->selectRaw('COUNT(*) as orders_count, SUM(grand_total) as total_spent, MIN(COALESCE(placed_at, created_at)) as first_order_at, MAX(COALESCE(placed_at, created_at)) as last_order_at, MAX(id) as last_order_id')
            ->groupByRaw($key);

        if (filled($filters['search'] ?? null)) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('customer_name', 'like', $term)->orWhere('customer_email', 'like', $term)
                ->orWhere('customer_phone', 'like', $term)->orWhere('customer_phone_normalized', 'like', $term));
        }
        $having = [
            'orders_min' => ['COUNT(*) >= ?', 'int'], 'orders_max' => ['COUNT(*) <= ?', 'int'],
            'spent_min' => ['SUM(grand_total) >= CAST(? AS DECIMAL(14,2))', 'float'], 'spent_max' => ['SUM(grand_total) <= CAST(? AS DECIMAL(14,2))', 'float'],
        ];
        foreach ($having as $name => [$sql, $type]) {
            if (isset($filters[$name]) && $filters[$name] !== '') {
                $query->havingRaw($sql, [$type === 'int' ? (int) $filters[$name] : (float) $filters[$name]]);
            }
        }
        if (filled($filters['last_order_from'] ?? null)) {
            $query->havingRaw('MAX(COALESCE(placed_at, created_at)) >= ?', [Carbon::parse($filters['last_order_from'])->startOfDay()]);
        }
        if (filled($filters['last_order_to'] ?? null)) {
            $query->havingRaw('MAX(COALESCE(placed_at, created_at)) <= ?', [Carbon::parse($filters['last_order_to'])->endOfDay()]);
        }
        if (filled($filters['first_order_from'] ?? null)) {
            $query->havingRaw('MIN(COALESCE(placed_at, created_at)) >= ?', [Carbon::parse($filters['first_order_from'])->startOfDay()]);
        }
        if (($filters['account'] ?? null) === 'registered') {
            $query->havingRaw('MAX(store_customer_id) IS NOT NULL');
        } elseif (($filters['account'] ?? null) === 'guest') {
            $query->havingRaw('MAX(store_customer_id) IS NULL');
        }

        $sort = in_array($filters['sort_by'] ?? null, self::SORTS, true) ? $filters['sort_by'] : 'last_order_at';
        $direction = ($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $direction)->orderBy('customer_key')->paginate($perPage);

        // City and address of each customer's most recent order (one extra query per page).
        $latest = StoreOrder::query()->withoutGlobalScopes()->where('store_id', $store->id)
            ->whereIn('id', collect($page->items())->pluck('last_order_id'))->get(['id', 'shipping_address'])->keyBy('id');
        $page->setCollection($page->getCollection()->map(fn (StoreOrder $model) => self::row($model->getAttributes(), $latest)));

        return $page;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, StoreOrder>  $latest
     * @return array<string, mixed>
     */
    private static function row(array $row, Collection $latest): array
    {
        return [
            'key' => (string) $row['customer_key'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'account_id' => $row['account_id'] ? (int) $row['account_id'] : null,
            'orders_count' => (int) $row['orders_count'],
            'total_spent' => round((float) $row['total_spent'], 2),
            'first_order_at' => $row['first_order_at'] ? Carbon::parse($row['first_order_at'])->toIso8601String() : null,
            'last_order_at' => $row['last_order_at'] ? Carbon::parse($row['last_order_at'])->toIso8601String() : null,
            'city' => self::city($latest->get($row['last_order_id'])?->shipping_address),
        ];
    }

    private static function city(?array $address): ?string
    {
        foreach (['city', 'state', 'country'] as $field) {
            if (isset($address[$field]) && is_scalar($address[$field]) && trim((string) $address[$field]) !== '') {
                return trim((string) $address[$field]);
            }
        }

        return null;
    }
}

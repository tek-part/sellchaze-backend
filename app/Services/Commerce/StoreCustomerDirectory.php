<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shopify-style customer list built from orders, so guest checkouts (most cash-on-delivery stores)
 * count as customers too. A customer is identified by normalized phone, else lower-cased email,
 * else the single order. Cancelled orders are excluded from counts and totals.
 *
 * Each grouped customer is joined (one row each, by key) to the merchant's profile (tags, note,
 * contact overrides) and to the newsletter subscriber lists that hold marketing consent.
 */
class StoreCustomerDirectory
{
    public const SORTS = ['last_order_at', 'first_order_at', 'orders_count', 'total_spent', 'name'];

    private const KEY = "COALESCE(NULLIF(customer_phone_normalized, ''), LOWER(NULLIF(customer_email, '')), CONCAT('order:', id))";

    /** Consent channel e-mail: the merchant's override, else the address on the customer's orders. */
    private const EMAIL = "LOWER(COALESCE(NULLIF(p.email, ''), c.order_email))";

    public function __construct(private readonly StoreCustomerProfiles $profiles) {}

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
        $page = $this->query($store, $filters)->paginate($perPage);
        $page->setCollection($this->rows($store, collect($page->items())));

        return $page;
    }

    /**
     * Every matching row in list order, in chunks (CSV export).
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(Collection<int, array<string, mixed>>): void  $callback
     */
    public function each(Store $store, array $filters, callable $callback, int $chunk = 500): void
    {
        $this->query($store, $filters)->chunk($chunk, function (Collection $rows) use ($store, $callback): void {
            $callback($this->rows($store, $rows));
        });
    }

    /** @param  array<string, mixed>  $filters */
    public function query(Store $store, array $filters): QueryBuilder
    {
        $key = self::key();
        $money = new StoreReportingMoney($store);
        $spent = $money->sum();
        $orders = StoreOrder::query()->withoutGlobalScopes()->where('store_id', $store->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')
            ->selectRaw("$key as customer_key")
            ->selectRaw('MAX(customer_name) as order_name, MAX(customer_email) as order_email, MAX(customer_phone) as order_phone, MAX(store_customer_id) as account_id')
            ->selectRaw("COUNT(*) as orders_count, $spent as total_spent, MIN(COALESCE(placed_at, created_at)) as first_order_at, MAX(COALESCE(placed_at, created_at)) as last_order_at, MAX(id) as last_order_id")
            ->groupByRaw($key);

        if (filled($filters['search'] ?? null)) {
            $term = '%'.trim((string) $filters['search']).'%';
            $orders->where(fn (Builder $q) => $q->where('customer_name', 'like', $term)->orWhere('customer_email', 'like', $term)
                ->orWhere('customer_phone', 'like', $term)->orWhere('customer_phone_normalized', 'like', $term)
                // Contact overrides the merchant typed on the customer page are searchable too.
                ->orWhereRaw("$key IN (SELECT customer_key FROM store_customer_profiles WHERE store_id = ? AND (name LIKE ? OR email LIKE ? OR phone LIKE ?))", [$store->id, $term, $term, $term]));
        }
        $having = [
            'orders_min' => ['COUNT(*) >= ?', 'int'], 'orders_max' => ['COUNT(*) <= ?', 'int'],
            'spent_min' => ["$spent >= CAST(? AS DECIMAL(14,2))", 'decimal'], 'spent_max' => ["$spent <= CAST(? AS DECIMAL(14,2))", 'decimal'],
        ];
        foreach ($having as $name => [$sql, $type]) {
            if (isset($filters[$name]) && $filters[$name] !== '') {
                $orders->havingRaw($sql, [$type === 'int' ? (int) $filters[$name] : (string) $filters[$name]]);
            }
        }
        if (filled($filters['last_order_from'] ?? null)) {
            $orders->havingRaw('MAX(COALESCE(placed_at, created_at)) >= ?', [Carbon::parse($filters['last_order_from'])->startOfDay()]);
        }
        if (filled($filters['last_order_to'] ?? null)) {
            $orders->havingRaw('MAX(COALESCE(placed_at, created_at)) <= ?', [Carbon::parse($filters['last_order_to'])->endOfDay()]);
        }
        if (filled($filters['first_order_from'] ?? null)) {
            $orders->havingRaw('MIN(COALESCE(placed_at, created_at)) >= ?', [Carbon::parse($filters['first_order_from'])->startOfDay()]);
        }
        if (($filters['account'] ?? null) === 'registered') {
            $orders->havingRaw('MAX(store_customer_id) IS NOT NULL');
        } elseif (($filters['account'] ?? null) === 'guest') {
            $orders->havingRaw('MAX(store_customer_id) IS NULL');
        }

        // One profile and at most one subscriber per channel per key (unique indexes), so the joins never fan out.
        $query = DB::query()->fromSub($orders->toBase(), 'c')
            ->leftJoin('store_customer_profiles as p', fn (JoinClause $join) => $join->on('p.customer_key', '=', 'c.customer_key')->where('p.store_id', '=', $store->id))
            ->leftJoin('phone_newsletter_subscribers as pns', fn (JoinClause $join) => $join->on('pns.phone_normalized', '=', 'c.customer_key')
                ->where('pns.store_id', '=', $store->id)->where('pns.is_active', '=', true))
            ->leftJoin('newsletter_subscribers as ns', fn (JoinClause $join) => $join->on('ns.email', '=', DB::raw(self::EMAIL))
                ->where('ns.store_id', '=', $store->id)->where('ns.is_active', '=', true))
            ->select('c.*')
            ->selectRaw("COALESCE(NULLIF(p.name, ''), c.order_name) as name, COALESCE(NULLIF(p.email, ''), c.order_email) as email, COALESCE(NULLIF(p.phone, ''), c.order_phone) as phone")
            ->selectRaw('p.tags as tags, CASE WHEN pns.id IS NOT NULL OR ns.id IS NOT NULL THEN 1 ELSE 0 END as accepts_marketing');

        $tags = $this->profiles->canonicalTags($store, self::list($filters['tag'] ?? null));
        if ($tags !== []) {
            $query->where(function (QueryBuilder $q) use ($tags): void {
                foreach ($tags as $tag) {
                    $q->orWhereJsonContains('p.tags', $tag);
                }
            });
        }
        if (($filters['marketing'] ?? null) === 'subscribed') {
            $query->where(fn (QueryBuilder $q) => $q->whereNotNull('pns.id')->orWhereNotNull('ns.id'));
        } elseif (($filters['marketing'] ?? null) === 'not_subscribed') {
            $query->whereNull('pns.id')->whereNull('ns.id');
        }

        $sort = in_array($filters['sort_by'] ?? null, self::SORTS, true) ? $filters['sort_by'] : 'last_order_at';
        $direction = ($filters['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'total_spent') {
            $query->orderByRaw('c.total_spent IS NULL');
        }

        return $query->orderBy($sort === 'name' ? 'name' : 'c.'.$sort, $direction)->orderBy('c.customer_key');
    }

    /**
     * A filter given as a comma list ("vip,wholesale") or as an array.
     *
     * @return list<string>
     */
    public static function list(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $items), fn (string $item) => $item !== ''));
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Store $store, Collection $rows): Collection
    {
        // City and address of each customer's most recent order (one extra query per page).
        $latest = StoreOrder::query()->withoutGlobalScopes()->where('store_id', $store->id)
            ->whereIn('id', $rows->pluck('last_order_id'))->get(['id', 'shipping_address'])->keyBy('id');

        $money = new StoreReportingMoney($store);
        $query = DB::table('store_orders')->where('store_id', $store->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')->whereIn(DB::raw(self::key()), $rows->pluck('customer_key'));
        $reports = $money->reports($query, 'grand_total', 'currency', [DB::raw(self::key().' as customer_key')]);

        return $rows->map(fn ($row) => self::row((array) $row, $latest) + ['reporting_money' => $reports[$row->customer_key] ?? $money->empty(), 'currency' => $money->currency])->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, StoreOrder>  $latest
     * @return array<string, mixed>
     */
    private static function row(array $row, Collection $latest): array
    {
        $tags = is_string($row['tags'] ?? null) ? json_decode($row['tags'], true) : null;

        return [
            'key' => (string) $row['customer_key'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'account_id' => $row['account_id'] ? (int) $row['account_id'] : null,
            'orders_count' => (int) $row['orders_count'],
            'total_spent' => StoreReportingMoney::decimal($row['total_spent']),
            'first_order_at' => $row['first_order_at'] ? Carbon::parse($row['first_order_at'])->toIso8601String() : null,
            'last_order_at' => $row['last_order_at'] ? Carbon::parse($row['last_order_at'])->toIso8601String() : null,
            'city' => self::city($latest->get($row['last_order_id'])?->shipping_address),
            'tags' => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
            'accepts_marketing' => (bool) $row['accepts_marketing'],
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

<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\PlatformWallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantOrderQuery
{
    public const SORTABLE = ['id', 'created_at', 'placed_at', 'grand_total', 'status', 'order_number', 'customer_name'];

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in(StoreOrder::STATUSES)],
            'payment_status' => ['nullable', 'in:pending,paid,failed'],
            'classification' => ['nullable', Rule::in(['high_risk', 'moderate_risk', 'spam'])],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'order_number' => ['nullable', 'string', 'max:200'],
            'customer' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:200'],
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'source' => ['nullable', 'string', 'max:200'],
            'campaign' => ['nullable', 'string', 'max:200'],
            'coupon' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
    }

    /** @param array<string, mixed> $filters
     * @return Builder<StoreOrder>
     */
    public function build(Store $store, array $filters): Builder
    {
        $query = StoreOrder::forStore($store);
        if ($store->owner && app(PlatformWallet::class)->ordersLocked($store->owner)) {
            $query->whereNotIn('store_orders.id', DB::table('platform_wallet_entries')->where('type', 'order_fee')->whereNotNull('store_order_id')->select('store_order_id'));
        }
        foreach (['status', 'payment_status', 'order_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($key === 'order_id' ? 'store_orders.id' : $key, $filters[$key]);
            }
        }
        if (isset($filters['classification']) && $filters['classification'] !== '') {
            $query->whereHas('merchantReview', fn (Builder $review) => $review->where('store_id', $store->id)->where('classification', $filters['classification']));
        }
        if (isset($filters['order_number']) && $filters['order_number'] !== '') {
            $query->where('order_number', 'like', '%'.$filters['order_number'].'%');
        }
        foreach (['customer' => ['customer_name', 'customer_email'], 'search' => ['order_number', 'customer_name', 'customer_email', 'customer_phone']] as $key => $columns) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where(function (Builder $inner) use ($columns, $filters, $key) {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', '%'.$filters[$key].'%');
                    }
                });
            }
        }
        if (isset($filters['customer_phone']) && $filters['customer_phone'] !== '') {
            $query->where('customer_phone', 'like', '%'.$filters['customer_phone'].'%');
        }
        if (isset($filters['address']) && $filters['address'] !== '') {
            $query->where(function (Builder $inner) use ($filters) {
                foreach (['line1', 'line2', 'address', 'city', 'state', 'country', 'postal_code', 'street', 'governorate', 'area', 'country_name', 'governorate_name', 'area_name'] as $key) {
                    $inner->orWhere('shipping_address->'.$key, 'like', '%'.$filters['address'].'%');
                }
            });
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->whereDate('placed_at', $operator, $filters[$key]);
            }
        }
        foreach (['source', 'campaign'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where('marketing_attribution->'.$key, 'like', '%'.$filters[$key].'%');
            }
        }
        if (isset($filters['product_id']) && $filters['product_id'] !== '') {
            $query->whereIn('store_orders.id', DB::table('store_order_items')->whereNull('superseded_at')->where('store_id', $store->id)->where('store_product_id', $filters['product_id'])->select('store_order_id'));
        }
        if (isset($filters['coupon']) && $filters['coupon'] !== '') {
            $query->where(fn (Builder $inner) => $inner->where('coupon_code', 'like', '%'.$filters['coupon'].'%')
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('coupon_code')->whereIn('store_orders.id', DB::table('coupon_usages')->join('coupons', 'coupon_usages.coupon_id', '=', 'coupons.id')
                    ->where('coupon_usages.store_id', $store->id)->where('coupons.store_id', $store->id)->where('coupons.code', 'like', '%'.$filters['coupon'].'%')->select('coupon_usages.store_order_id'))));
        }

        return $query;
    }
}

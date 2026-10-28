<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StoreCustomersApiController;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\MerchantOrderQuery;
use App\Services\Commerce\ProductAnalytics;
use App\Services\Commerce\StoreAnalyticsService;
use App\Services\Commerce\StoreCustomerDirectory;
use App\Services\Commerce\StoreCustomerProfiles;
use App\Services\Commerce\StoreDashboardMetrics;
use App\Services\Commerce\StoreReportingMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreReportingMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private StoreCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 27)->startOfDay());
        $owner = User::factory()->create();
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Fixture', 'slug' => 'fixture-money', 'currency' => 'KWD', 'status' => 'active', 'timezone' => 'UTC', 'theme_settings' => ['reporting_money_policy' => self::policy()]]);
        $category = Category::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Fixture', 'slug' => 'fixture-category']);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Fixture', 'slug' => 'fixture-product', 'price' => 10, 'is_active' => true]);
        $this->customer = StoreCustomer::create(['store_id' => $this->store->id, 'name' => 'Fixture', 'email' => 'fixture@example.test', 'password' => 'fixture-only-password']);
    }

    private static function policy(): array
    {
        return ['version' => 'cbk-2026-10-27-v1', 'reporting_currency' => 'KWD', 'rates' => ['USD' => '0.308150', 'KWD' => '1'], 'source_url' => 'https://www.cbk.gov.kw/en/monetary-policy/market-operations/exchange-rates', 'effective_date' => '2026-10-27'];
    }

    private function order(string $currency, string $amount, string $status = 'delivered', ?string $cost = '0.00'): StoreOrder
    {
        $order = StoreOrder::create(['store_id' => $this->store->id, 'store_customer_id' => $this->customer->id, 'order_number' => 'FX-'.StoreOrder::withoutGlobalScopes()->count(), 'currency' => $currency, 'customer_name' => 'Fixture', 'customer_email' => $this->customer->email, 'status' => $status, 'subtotal' => $amount, 'grand_total' => $amount, 'placed_at' => now()]);
        StoreOrderItem::create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_product_id' => $this->product->id, 'name' => 'Snapshot', 'unit_price' => $amount, 'line_total' => $amount, 'quantity' => 1, 'unit_cost' => $cost]);

        return $order;
    }

    public function test_mixed_history_uses_one_pinned_equivalent_across_reports_customer_filters_csv_and_keeps_snapshots(): void
    {
        $this->order('USD', '100.00');
        $this->order('KWD', '10.00');
        $ordersBefore = DB::table('store_orders')->get()->toJson();
        $linesBefore = DB::table('store_order_items')->get()->toJson();
        $dashboard = app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27');
        $this->assertSame('40.82', $dashboard['summary']['total_sales']);
        $this->assertSame('20.41', $dashboard['summary']['average_order_value']);
        $this->assertSame('40.82', $dashboard['daily'][0]['sales']);
        $this->assertSame('40.82', $dashboard['top_products'][0]['revenue']);
        $this->assertSame('100.00', $dashboard['reporting_money']['totals_by_currency']['USD']['total']);
        $this->assertSame('10.00', $dashboard['reporting_money']['totals_by_currency']['KWD']['total']);
        $this->assertSame(1, $dashboard['reporting_money']['totals_by_currency']['USD']['count']);
        $analytics = app(StoreAnalyticsService::class);
        $this->assertSame('40.82', $analytics->overview($this->store)['revenue']['total_revenue']);
        $this->assertSame('40.82', $analytics->overview($this->store)['revenue']['today_revenue']);
        $this->assertSame('40.82', $analytics->topProducts($this->store, 5)[0]['revenue_generated']);
        $this->assertSame('40.82', $analytics->topCategories($this->store, 5)[0]['revenue_generated']);
        $this->assertSame('40.82', $analytics->customers($this->store, 5)->items()[0]->total_spent);
        $funnel = app(ProductAnalytics::class)->funnel($this->store, '2026-10-27', '2026-10-27');
        $this->assertSame('40.82', $funnel['totals']['revenue']);
        $this->assertSame('40.82', $funnel['products'][0]['revenue']);
        $directory = app(StoreCustomerDirectory::class);
        $row = $directory->paginate($this->store, ['spent_min' => '40.82', 'spent_max' => '40.82', 'sort_by' => 'total_spent'], 25)->items()[0];
        $this->assertSame('40.82', $row['total_spent']);
        $this->assertSame(0, $directory->paginate($this->store, ['spent_min' => '40.83'], 25)->total());
        $profile = app(StoreCustomerProfiles::class)->show($this->store, 'fixture@example.test');
        $this->assertSame('40.82', $profile['stats']['total_spent']);
        $this->assertSame('20.41', $profile['stats']['average_order_value']);
        $response = app(StoreCustomersApiController::class)->export(Request::create('/', 'GET'), $this->store, $directory);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('40.82,20.41,', $csv);
        $this->assertStringContainsString('Reporting currency', $csv);
        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringContainsString('cbk-2026-10-27-v1', $csv);
        $this->assertSame($ordersBefore, DB::table('store_orders')->get()->toJson());
        $this->assertSame($linesBefore, DB::table('store_order_items')->get()->toJson());
    }

    public function test_unknown_currency_keeps_original_bucket_and_nulls_entire_equivalent_and_profit(): void
    {
        $this->order('USD', '100.00');
        $this->order('EUR', '5.00', cost: null);
        $dashboard = app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27');
        $this->assertNull($dashboard['summary']['total_sales']);
        $this->assertNull($dashboard['summary']['average_order_value']);
        $this->assertNull($dashboard['summary']['net_profit']);
        $this->assertNull($dashboard['daily'][0]['sales']);
        $this->assertNull($dashboard['top_products'][0]['revenue']);
        $this->assertFalse($dashboard['reporting_money']['complete']);
        $this->assertSame('5.00', $dashboard['reporting_money']['totals_by_currency']['EUR']['total']);
        $funnel = app(ProductAnalytics::class)->funnel($this->store, '2026-10-27', '2026-10-27');
        $this->assertNull($funnel['totals']['revenue']);
        $this->assertNull(app(StoreCustomerProfiles::class)->show($this->store, 'fixture@example.test')['stats']['total_spent']);
        $this->assertSame(0, app(StoreCustomerDirectory::class)->paginate($this->store, ['spent_min' => '0'], 25)->total());
    }

    public function test_rounding_is_per_record_and_cancelled_customer_semantics_remain_distinct_from_delivered_dashboard(): void
    {
        $this->order('USD', '0.01');
        $this->order('USD', '0.01');
        $this->order('KWD', '10.00', 'pending');
        $this->order('KWD', '20.00', 'cancelled');
        $this->assertSame('0.00', app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27')['summary']['total_sales']);
        $this->assertSame('10.00', app(StoreCustomerProfiles::class)->show($this->store, 'fixture@example.test')['stats']['total_spent']);
        $this->assertSame('10.00', app(ProductAnalytics::class)->funnel($this->store, '2026-10-27', '2026-10-27')['totals']['revenue']);
    }

    public function test_amount_sort_customer_ranking_and_coupon_discounts_use_the_identical_equivalent(): void
    {
        $usd = $this->order('USD', '100.00');
        $kwd = $this->order('KWD', '35.00');
        $unknown = $this->order('EUR', '1.00');
        $other = StoreCustomer::create(['store_id' => $this->store->id, 'name' => 'Other fixture', 'email' => 'other@example.test', 'password' => 'fixture-only-password']);
        $kwd->forceFill(['store_customer_id' => $other->id, 'customer_email' => $other->email])->save();
        $unknown->forceFill(['store_customer_id' => null, 'customer_email' => 'unknown@example.test'])->save();
        $query = app(MerchantOrderQuery::class);
        $orders = StoreOrder::withoutGlobalScopes()->where('store_id', $this->store->id);
        $this->assertSame([$usd->id, $kwd->id, $unknown->id], $query->sort(clone $orders, $this->store, 'grand_total', 'asc')->pluck('id')->all());
        $this->assertSame([$kwd->id, $usd->id, $unknown->id], $query->sort(clone $orders, $this->store, 'grand_total', 'desc')->pluck('id')->all());
        $ranked = app(StoreAnalyticsService::class)->customers($this->store, 25)->items();
        $this->assertSame($other->id, $ranked[0]->id);
        $this->assertSame('35.00', $ranked[0]->total_spent);
        $directory = app(StoreCustomerDirectory::class)->paginate($this->store, ['sort_by' => 'total_spent', 'sort_direction' => 'desc'], 25)->items();
        $this->assertSame('35.00', $directory[0]['total_spent']);
        $coupon = Coupon::create(['store_id' => $this->store->id, 'code' => 'FIXTURE', 'type' => 'fixed', 'value' => '10.00', 'is_active' => true]);
        foreach ([[$usd, '10.00'], [$kwd, '1.00']] as [$order, $discount]) {
            CouponUsage::create(['store_id' => $this->store->id, 'coupon_id' => $coupon->id, 'store_order_id' => $order->id, 'discount_amount' => $discount, 'used_at' => now()]);
        }
        $coupons = app(StoreAnalyticsService::class)->overview($this->store)['coupons'];
        $this->assertSame('4.08', $coupons['total_coupon_usage']);
        $this->assertSame('4.08', $coupons['top_coupons'][0]['total_discount']);
        $this->assertSame('10.00', $coupons['reporting_money']['totals_by_currency']['USD']['total']);
    }

    public function test_native_saved_cost_is_converted_and_missing_cost_or_missing_lines_never_invents_profit(): void
    {
        $this->order('USD', '100.00', cost: '50.00');
        $this->order('KWD', '10.00', cost: '2.00');
        $metrics = app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27');
        $this->assertSame('23.41', $metrics['summary']['net_profit']);
        $this->order('KWD', '1.00', cost: null);
        $this->assertNull(app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27')['summary']['net_profit']);
        StoreOrderItem::withoutGlobalScopes()->delete();
        $this->assertNull(app(StoreDashboardMetrics::class)->build($this->store, '2026-10-27', '2026-10-27')['summary']['net_profit']);
        $this->assertSame('13.61', StoreReportingMoney::average('40.82', 3));
        $this->assertSame('-30.82', StoreReportingMoney::convert('-100.00', '0.308150'));
    }

    public function test_directory_search_buckets_match_the_exact_order_records_in_the_filtered_scalar(): void
    {
        $order = $this->order('USD', '100.00');
        $order->forceFill(['customer_name' => 'Needle fixture'])->save();
        $this->order('KWD', '10.00');
        $row = app(StoreCustomerDirectory::class)->paginate($this->store, ['search' => 'Needle'], 25)->items()[0];
        $this->assertSame('30.82', $row['total_spent']);
        $this->assertSame($row['total_spent'], $row['reporting_money']['base_equivalent']);
        $this->assertSame(['USD'], array_keys($row['reporting_money']['totals_by_currency']));
    }

    public function test_theme_publication_cannot_drop_the_store_policy_and_policy_edits_bypass_stale_overview_cache(): void
    {
        $this->order('USD', '100.00');
        $this->assertSame('30.82', app(StoreAnalyticsService::class)->overview($this->store)['revenue']['total_revenue']);
        $this->store->theme_settings = ['brand_color' => '#ffffff'];
        $this->store->save();
        $this->assertSame(self::policy(), $this->store->fresh()->theme_settings['reporting_money_policy']);
        $policy = self::policy();
        $policy['rates']['USD'] = '0.400000';
        $policy['version'] = 'fixture-second-policy';
        $this->store->theme_settings = ['reporting_money_policy' => $policy];
        $this->store->save();
        $this->assertSame('40.00', app(StoreAnalyticsService::class)->overview($this->store)['revenue']['total_revenue']);
    }

    public function test_policy_validation_and_native_store_identity_do_not_guess_foreign_rates(): void
    {
        $this->store->theme_settings = ['reporting_money_policy' => null];
        $this->store->save();
        $this->order('KWD', '10.00');
        $this->assertSame('10.00', (new StoreReportingMoney($this->store))->report(DB::table('store_orders'))['base_equivalent']);
        $this->order('USD', '100.00');
        $this->assertNull((new StoreReportingMoney($this->store))->report(DB::table('store_orders'))['base_equivalent']);
        $this->store->theme_settings = ['reporting_money_policy' => self::policy() + ['unused' => 'ignored']];
        $policy = self::policy();
        $policy['rates']['USD'] = '1; DROP TABLE store_orders';
        $this->store->theme_settings = ['reporting_money_policy' => $policy];
        $this->assertFalse((new StoreReportingMoney($this->store))->metadata()['policy_valid']);
        $this->expectException(\InvalidArgumentException::class);
        $this->store->save();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePage;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Filters and sorting behind the dashboard index tables (categories, inventory, newsletter, A/B tests, pages, admin lists). */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Lists', 'slug' => 'lists-store', 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'lists-store.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function names(string $path, string $key = 'name'): array
    {
        return array_column($this->getJson('/api/v1/my-store/'.$path, $this->auth)->assertOk()->json('data'), $key);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4),
            'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 20, 'reserved_quantity' => 0], $attributes));
    }

    public function test_categories_filter_by_visibility_header_level_and_products(): void
    {
        $category = fn (string $name, array $attributes = []) => Category::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'name' => $name, 'slug' => Str::slug($name)], $attributes));
        $shirts = $category('Shirts', ['show_in_header' => true, 'position' => 2]);
        $category('Hidden', ['is_hidden' => true, 'show_in_header' => false, 'position' => 1]);
        $category('Summer shirts', ['parent_id' => $shirts->id, 'show_in_header' => false, 'position' => 3]);
        $this->product('Linen shirt', ['category_id' => $shirts->id]);

        $this->assertSame(['Hidden'], $this->names('catalog/categories?visibility=hidden'));
        $this->assertSame(['Shirts'], $this->names('catalog/categories?in_header=yes'));
        $this->assertSame(['Summer shirts'], $this->names('catalog/categories?level=sub'));
        $with = $this->getJson('/api/v1/my-store/catalog/categories?products=with', $this->auth)->assertOk();
        $this->assertSame(['Shirts'], array_column($with->json('data'), 'name'));
        $this->assertSame(1, $with->json('meta.total'));
        $this->assertSame(['Hidden', 'Summer shirts'], $this->names('catalog/categories?products=without&sort_by=name'));
        $this->getJson('/api/v1/my-store/catalog/categories?level=deep', $this->auth)->assertUnprocessable();
    }

    public function test_inventory_filters_by_stock_searches_sku_and_sorts(): void
    {
        $this->product('Plenty', ['stock_quantity' => 50, 'sku' => 'PL-1']);
        $this->product('Low', ['stock_quantity' => 3]);
        $this->product('Empty', ['stock_quantity' => 0]);

        $this->assertSame(['Low'], $this->names('catalog/inventory?stock=low'));
        $this->assertSame(['Empty'], $this->names('catalog/inventory?stock=out_of_stock'));
        $this->assertSame(['Plenty'], $this->names('catalog/inventory?search=PL-1'));
        $this->assertSame(['Plenty', 'Low', 'Empty'], $this->names('catalog/inventory?sort_by=stock_quantity&sort_direction=desc&per_page=50'));
    }

    public function test_newsletter_filters_by_status_and_date_and_sorts(): void
    {
        DB::table('newsletter_subscribers')->insert([
            ['store_id' => $this->store->id, 'email' => 'a@x.test', 'is_active' => true, 'created_at' => now()->subDays(40), 'updated_at' => now()],
            ['store_id' => $this->store->id, 'email' => 'b@x.test', 'is_active' => false, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);
        DB::table('phone_newsletter_subscribers')->insert(['store_id' => $this->store->id, 'name' => 'Mona', 'phone' => '+201001112222', 'phone_normalized' => '201001112222', 'is_active' => true, 'created_at' => now()->subDays(2), 'updated_at' => now()]);

        $this->assertSame(['b@x.test'], $this->names('newsletter?status=inactive', 'email'));
        $this->assertSame([null, 'b@x.test'], $this->names('newsletter?created_from='.now()->subDays(7)->toDateString().'&sort_by=created_at&sort_direction=asc', 'email'));
        $this->assertSame(['a@x.test', 'b@x.test'], $this->names('newsletter?channel=email&sort_by=email&sort_direction=asc', 'email'));
    }

    public function test_ab_tests_search_status_sort_and_total(): void
    {
        foreach ([['Hero copy', true], ['Price test', false], ['Hero image', false]] as [$name, $active]) {
            DB::table('store_ab_tests')->insert(['store_id' => $this->store->id, 'name' => $name, 'slug' => Str::slug($name), 'active' => $active, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->getJson('/api/v1/my-store/ab-tests?search=hero&sort_by=name&sort_direction=asc', $this->auth)->assertOk();
        $this->assertSame(['Hero copy', 'Hero image'], array_column($response->json('data'), 'name'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['Hero copy'], $this->names('ab-tests?status=active'));
    }

    public function test_pages_search_locale_and_sort(): void
    {
        foreach ([['About us', 'en'], ['من نحن', 'ar'], ['Summer landing', 'en']] as $i => [$title, $locale]) {
            StorePage::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'title' => $title, 'slug' => 'page-'.$i, 'status' => 'draft', 'template' => 'page', 'locale' => $locale]);
        }
        $this->assertSame(['من نحن'], $this->names('pages?locale=ar', 'title'));
        $this->assertSame(['Summer landing'], $this->names('pages?search=summer', 'title'));
        $this->assertSame(['About us', 'Summer landing', 'من نحن'], $this->names('pages?sort_by=title&sort_direction=asc', 'title'));
    }

    public function test_admin_merchant_and_user_lists_filter_by_status_role_and_sort(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'name' => 'Root']);
        $admin->assignRole('Admin');
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($admin)];
        $merchant = fn (string $name, array $attributes) => tap(User::factory()->create(array_merge(['name' => $name], $attributes)), fn ($user) => $user->assignRole('Merchant'));
        $merchant('Zed Store', ['is_active' => true, 'pending_approval' => false]);
        $merchant('Amal Shop', ['is_active' => false, 'pending_approval' => false]);
        User::factory()->create(['name' => 'Pending Co', 'pending_approval' => true, 'registration_role' => 'Merchant']);

        $merchants = fn (string $query) => array_column($this->getJson('/api/v1/merchants?'.$query, $auth)->assertOk()->json('data'), 'name');
        $this->assertSame(['Pending Co'], $merchants('status=pending'));
        $this->assertSame(['Amal Shop'], $merchants('status=inactive'));
        $this->assertContains('Zed Store', $merchants('status=active'));
        $this->assertSame('Zed Store', $merchants('sort_by=name&sort_direction=desc')[0]);

        $staff = User::factory()->create(['name' => 'Staffer', 'is_active' => false, 'pending_approval' => false]);
        $staff->assignRole('Staff');
        $users = fn (string $query) => array_column($this->getJson('/api/v1/admin/users?'.$query, $auth)->assertOk()->json('data'), 'name');
        $this->assertSame(['Staffer'], $users('role=Staff'));
        $this->assertSame(['Staffer'], $users('status=inactive&role=Staff'));
        $this->assertSame('Root', $users('sort_by=name&sort_direction=asc&role=Admin')[0]);
        $this->getJson('/api/v1/admin/users?sort_by=password', $auth)->assertUnprocessable();
    }
}

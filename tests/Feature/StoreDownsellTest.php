<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Commerce\DownsellOffers;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreDownsellTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private Coupon $coupon;

    private array $auth;

    private string $public = 'http://offer.sellchase.com/api/v1/storefront';

    private string $private = '/api/v1/my-store/downsell';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Offer', 'slug' => 'offer', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'offer.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Offer bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true]);
        $this->coupon = Coupon::create(['store_id' => $this->store->id, 'code' => 'RETURN10', 'type' => 'percentage', 'value' => 10, 'minimum_order_amount' => 100, 'is_active' => true, 'max_uses' => 1]);
    }

    private function config(array $changes = []): array
    {
        return array_replace(app(DownsellOffers::class)->configured($this->store->fresh()), ['enabled' => true, 'coupon_id' => $this->coupon->id], $changes);
    }

    public function test_read_is_optional_write_uses_versions_and_disabling_removes_public_offer(): void
    {
        $this->getJson($this->private, $this->auth)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
        $this->assertNull($this->store->fresh()->downsell_configuration);
        $this->getJson($this->public.'/downsell')->assertOk()->assertJsonPath('data', null);
        $first = $this->config();
        $this->putJson($this->private, $first, $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->private, $first, $this->auth)->assertConflict();
        $data = $this->getJson($this->public.'/downsell')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.coupon.code', 'RETURN10')->json('data');
        $this->assertArrayNotHasKey('coupon_id', $data);
        $this->assertArrayNotHasKey('product_ids', $data);
        $this->assertDatabaseCount('coupon_usages', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->putJson($this->private, $this->config(['enabled' => false]), $this->auth)->assertOk();
        $this->getJson($this->public.'/downsell')->assertJsonPath('data', null);
    }

    public function test_cross_store_coupon_products_validation_and_employee_dual_permissions(): void
    {
        $otherOwner = User::factory()->create();
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreign = Coupon::create(['store_id' => $other->id, 'code' => 'FOREIGN', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
        $foreignProduct = Product::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 100, 'is_active' => true]);
        $this->putJson($this->private, $this->config(['coupon_id' => $foreign->id]), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('coupon_id');
        $this->putJson($this->private, $this->config(['all_products' => false, 'product_ids' => [$foreignProduct->id]]), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('product_ids.0');
        foreach ([['trigger' => 'invalid'], ['background_color' => 'url(x)'], ['all_products' => false, 'product_ids' => []], ['frequency_hours' => 0], ['delay_seconds' => 601]] as $change) {
            $this->putJson($this->private, $this->config($change), $this->auth)->assertUnprocessable();
        }
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo('store.settings.manage');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->private, $headers)->assertForbidden();
        $this->putJson($this->private, $this->config(), $headers)->assertForbidden();
        $employee->givePermissionTo('store.coupons.manage');
        $this->travel(31)->seconds();
        $this->putJson($this->private, $this->config(), $headers)->assertOk();
    }

    public function test_targeting_matches_active_owned_slug_or_cart_and_never_reveals_foreign_selection(): void
    {
        $this->putJson($this->private, $this->config(['all_products' => false, 'product_ids' => [$this->product->id]]), $this->auth)->assertOk();
        $this->getJson($this->public.'/downsell')->assertJsonPath('data', null);
        $this->getJson($this->public.'/downsell?product_slug=bag')->assertJsonPath('data.coupon.code', 'RETURN10');
        $this->getJson($this->public.'/downsell?product_ids[]='.$this->product->id)->assertJsonPath('data.coupon.code', 'RETURN10');
        $this->product->update(['is_active' => false]);
        $this->getJson($this->public.'/downsell?product_slug=bag')->assertJsonPath('data', null);
    }

    public function test_activation_and_public_reads_recheck_expired_future_disabled_and_deleted_coupon(): void
    {
        foreach ([['expires_at' => now()->subMinute()], ['starts_at' => now()->addMinute(), 'expires_at' => null], ['starts_at' => null, 'is_active' => false]] as $changes) {
            $this->coupon->update($changes);
            $this->putJson($this->private, $this->config(), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('coupon_id');
        }
        $this->coupon->update(['is_active' => true]);
        $this->putJson($this->private, $this->config(), $this->auth)->assertOk();
        $this->coupon->update(['expires_at' => now()->subMinute()]);
        $this->getJson($this->public.'/downsell')->assertJsonPath('data', null);
        $this->coupon->delete();
        $this->getJson($this->public.'/downsell')->assertJsonPath('data', null);
        $this->putJson($this->private, $this->config(['enabled' => false, 'coupon_id' => null]), $this->auth)->assertOk();
    }

    public function test_real_quote_checkout_discount_and_replay_consume_only_one_use_and_expiry_cannot_bypass_checkout(): void
    {
        $this->putJson($this->private, $this->config(), $this->auth)->assertOk();
        $items = [['product_id' => $this->product->id, 'quantity' => 1]];
        $this->postJson($this->public.'/checkout/quote', ['items' => $items, 'coupon_code' => 'RETURN10'])->assertOk()->assertJsonPath('data.totals.grand_total', '90.00');
        $this->assertDatabaseCount('coupon_usages', 0);
        $payload = ['items' => $items, 'coupon_code' => 'RETURN10', 'customer_name' => 'Offer test', 'customer_email' => 'offer@example.test', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Review road', 'city' => 'Cairo', 'country' => 'EG']];
        $this->coupon->update(['expires_at' => now()->subMinute()]);
        $this->postJson($this->public.'/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->coupon->update(['expires_at' => null]);
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $created = $this->postJson($this->public.'/checkout', $payload, $key)->assertCreated();
        $this->postJson($this->public.'/checkout', $payload, $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('coupon_usages', 1);
        $this->assertDatabaseCount('store_orders', 1);
        $this->getJson($this->public.'/downsell')->assertJsonPath('data', null);
        $this->postJson($this->public.'/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
    }
}

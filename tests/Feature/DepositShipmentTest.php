<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCarrierConnection;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** G1: a deposit order ships with only its remainder to collect, even after part of the deposit was refunded. */
class DepositShipmentTest extends TestCase
{
    use RefreshDatabase;

    private StoreOrder $order;

    private array $input = ['city_id' => 'cairo', 'district_id' => 'maadi', 'address_line' => '12 Local street', 'package_size' => 'MEDIUM', 'description' => 'Ring'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'Deposit carrier', 'slug' => 'deposit-carrier', 'currency' => 'EGP', 'status' => 'active']);
        app(CurrentStore::class)->set($store);
        $product = Product::create(['store_id' => $store->id, 'user_id' => $user->id, 'name' => 'Ring', 'slug' => 'ring', 'price' => 200, 'is_active' => true]);
        $this->order = StoreOrder::create(['order_number' => 'DEP-1', 'status' => 'confirmed', 'currency' => 'EGP', 'customer_name' => 'Local Buyer', 'customer_phone' => '+20 100 000 0000',
            'shipping_address' => ['country' => 'EG', 'line1' => 'Original address'], 'payment_method' => 'paymob', 'payment_status' => 'deposit_paid', 'subtotal' => 200, 'discount_total' => 0,
            'shipping_total' => 10, 'tax_total' => 0, 'grand_total' => 210, 'deposit_total' => '50.00', 'deposit_details' => ['percentage' => '0.00', 'minimum' => '50.00'], 'deposit_paid_at' => now()]);
        $this->order->items()->create(['store_id' => $store->id, 'store_product_id' => $product->id, 'name' => 'Ring', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200]);
        StoreCarrierConnection::create(['store_id' => $store->id, 'carrier' => 'bosta', 'api_key' => 'local-test-secret', 'enabled' => true, 'pickup_location_id' => 'pickup-1',
            'pickup_locations' => [['id' => 'pickup-1', 'name' => 'Warehouse', 'is_default' => true]], 'verified_at' => now()]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v2/cities' => Http::response(['success' => true, 'data' => ['list' => [['_id' => 'cairo', 'name' => 'Cairo', 'nameAr' => 'القاهرة', 'dropOffAvailability' => true]]]]),
                $path === '/api/v2/cities/cairo/districts' => Http::response(['success' => true, 'data' => [['districtId' => 'maadi', 'districtName' => 'Maadi', 'districtOtherName' => 'المعادي', 'dropOffAvailability' => true]]]),
                str_starts_with($path, '/api/v2/deliveries') => Http::response(['success' => true, 'data' => ['_id' => 'delivery-1', 'trackingNumber' => '123456', 'businessReference' => $request['businessReference'], 'state' => ['code' => 10, 'value' => 'Pickup requested']]]),
                default => throw new \RuntimeException('Unexpected carrier endpoint'),
            };
        });
    }

    public function test_a_partly_refunded_deposit_order_ships_collecting_the_remainder(): void
    {
        $this->order->update(['payment_status' => 'partially_refunded', 'refunded_total' => '20.00']);
        $this->postJson('/api/v1/my-store/orders/'.$this->order->id.'/shipment', $this->input)->assertCreated();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['cod'] === 160);
    }

    public function test_a_deposit_order_without_its_deposit_cannot_ship_and_a_collected_remainder_is_not_collected_again(): void
    {
        $this->order->update(['payment_status' => 'pending', 'deposit_paid_at' => null]);
        $this->postJson('/api/v1/my-store/orders/'.$this->order->id.'/shipment', $this->input)->assertUnprocessable();
        $this->order->update(['payment_status' => 'paid', 'deposit_paid_at' => now(), 'remainder_collected_at' => now()]);
        $this->postJson('/api/v1/my-store/orders/'.$this->order->id.'/shipment', $this->input)->assertCreated();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['cod'] === 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Shared store, owner and checkout helpers for the App store app tests. */
trait MerchantAppFixtures
{
    protected Store $store;

    protected User $owner;

    protected Product $product;

    protected array $auth;

    protected string $storefront = 'http://apps.sellchase.com/api/v1/storefront';

    protected function setUpStore(): void
    {
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        Cache::flush();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = $this->headersFor($this->owner);
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Apps', 'slug' => 'apps', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'apps.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = $this->makeProduct('Leather bag', 100);
    }

    protected function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user), 'Accept' => 'application/json'];
    }

    protected function makeProduct(string $name, float $price, ?Store $store = null, array $attributes = []): Product
    {
        $store ??= $this->store;

        return Product::create(['store_id' => $store->id, 'user_id' => $store->owner_user_id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'price' => $price, 'is_active' => true] + $attributes);
    }

    protected function employee(array $permissions): array
    {
        $employee = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        if ($permissions) {
            $employee->givePermissionTo($permissions);
        }

        return $this->headersFor($employee);
    }

    protected function settings(string $app): array
    {
        return $this->getJson('/api/v1/my-store/apps/'.$app.'/settings', $this->auth)->assertOk()->json('data');
    }

    protected function saveSettings(string $app, array $changes): TestResponse
    {
        $current = $this->settings($app);

        return $this->putJson('/api/v1/my-store/apps/'.$app.'/settings', array_replace_recursive($current, $changes), $this->auth);
    }

    protected function checkoutPayload(array $changes = []): array
    {
        return array_replace(['customer_name' => 'Mona Ali', 'customer_email' => 'mona@example.test', 'customer_phone' => '01001234567',
            'shipping_address' => ['line1' => 'Street 9, building 4', 'city' => 'Cairo', 'country' => 'EG'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]], $changes);
    }

    protected function checkout(array $changes = [], array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->postJson($this->storefront.'/checkout', $this->checkoutPayload($changes), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    protected function order(array $attributes = [], ?Store $store = null, ?Product $product = null): StoreOrder
    {
        $store ??= $this->store;
        $product ??= $this->product;
        $order = StoreOrder::withoutGlobalScopes()->create(array_replace(['store_id' => $store->id, 'order_number' => 'APP-'.Str::uuid(), 'status' => 'pending', 'currency' => 'EGP',
            'customer_name' => 'Mona Ali', 'customer_email' => 'mona@example.test', 'customer_phone' => '01001234567', 'customer_phone_normalized' => '+201001234567',
            'shipping_address' => ['line1' => 'Street 9, building 4', 'city' => 'Cairo', 'country' => 'EG'],
            'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 100,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()->subMinutes(5)], $attributes));
        $order->items()->create(['store_id' => $store->id, 'store_product_id' => $product->id, 'name' => $product->name, 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);

        return $order;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDigitalCode;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreDomain;
use App\Models\StorePaymentGateway;
use App\Models\StorePhoneBlockEvent;
use App\Models\User;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\PhoneBlocking;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorePhoneBlockingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $endpoint = '/api/v1/my-store/blocked-phone-numbers';

    private string $base = 'http://phone-blocking.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Blocking', 'slug' => 'phone-blocking', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'phone-blocking.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Bag', 'slug' => 'bag', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 5]);
    }

    private function block(string $phone = '01001234567'): array
    {
        return $this->postJson($this->endpoint, ['phone' => $phone, 'note' => 'LOCAL review only'], $this->auth)->assertOk()->json('data');
    }

    private function payload(?string $phone = '01001234567'): array
    {
        return ['customer_name' => 'Local review', 'customer_email' => 'blocking@example.test', 'customer_phone' => $phone, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];
    }

    public function test_add_canonical_duplicates_validate_and_search_without_public_disclosure(): void
    {
        $first = $this->block();
        foreach (['+20 100 123 4567', '٠١٠٠١٢٣٤٥٦٧', '+201001234567 ext.12'] as $raw) {
            $this->assertSame($first['id'], $this->block($raw)['id']);
        }
        $this->assertDatabaseCount('store_phone_blocks', 1);
        $this->assertDatabaseCount('store_phone_block_events', 1);
        $response = $this->getJson($this->endpoint.'?q=٠١٠٠١٢٣٤٥٦٧', $this->auth)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.phone_normalized', '+201001234567');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->getJson($this->endpoint.'?q=٤٥٦٧', $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->endpoint.'?q=%25', $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->endpoint, ['phone' => '123'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson($this->endpoint, ['phone' => '+201001234568', 'note' => str_repeat('a', 501)], $this->auth)->assertUnprocessable();
        $this->getJson($this->endpoint)->assertUnauthorized();
        $this->getJson($this->base.'/blocked-phone-numbers')->assertNotFound();
    }

    public function test_unblock_reblock_note_changes_and_stale_retries_are_audited_once(): void
    {
        $block = $this->block();
        $url = $this->endpoint.'/'.$block['id'];
        $unblock = ['active' => false, 'note' => $block['note'], 'version' => 1];
        $this->putJson($url, $unblock, $this->auth)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.active', false);
        $this->putJson($url, $unblock, $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($this->endpoint, ['phone' => '01001234567'], $this->auth)->assertConflict();
        $this->putJson($url, ['active' => true, 'note' => $block['note'], 'version' => 2], $this->auth)->assertOk()->assertJsonPath('data.version', 3);
        $this->putJson($url, $unblock, $this->auth)->assertConflict();
        $this->putJson($url, ['active' => true, 'note' => '<script>safe text</script>', 'version' => 3], $this->auth)->assertOk()->assertJsonPath('data.version', 4);
        $this->getJson($url.'/history', $this->auth)->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.action', 'note_changed')->assertJsonPath('data.0.note', '<script>safe text</script>');
        $this->assertDatabaseCount('store_phone_block_events', 4);
        $this->assertSame($this->store->owner_user_id, StorePhoneBlockEvent::withoutGlobalScopes()->first()->actor_id);
        $this->getJson($this->endpoint.'?status=inactive', $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_orders_permission_tenant_lookup_and_history_are_private(): void
    {
        $block = $this->block();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->endpoint, $headers)->assertForbidden();
        $this->postJson($this->endpoint, ['phone' => '+201001234568'], $headers)->assertForbidden();
        $this->putJson($this->endpoint.'/'.$block['id'], ['active' => false, 'note' => null, 'version' => 1], $headers)->assertForbidden();
        $employee->givePermissionTo('store.orders.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->endpoint, $headers)->assertOk();
        $this->putJson($this->endpoint.'/'.$block['id'], ['active' => false, 'note' => null, 'version' => 1], $headers)->assertOk();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-blocking']);
        $otherHeaders = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson($this->endpoint, $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint.'/'.$block['id'].'/history', $otherHeaders)->assertNotFound();
        $this->putJson($this->endpoint.'/'.$block['id'], ['active' => false, 'note' => null, 'version' => 2], $otherHeaders)->assertNotFound();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/blocked-phone-numbers', $otherHeaders)->assertNotFound();
    }

    public function test_cart_direct_and_signed_in_orders_cannot_bypass_canonical_block_or_consume_stock(): void
    {
        $this->block();
        $created = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'quantity' => 2])->assertCreated();
        $cartHeaders = ['X-Cart-Token' => $created->json('data.token')];
        foreach (['cart', 'direct'] as $mode) {
            foreach (['01001234567', '+201001234567', '٠١٠٠١٢٣٤٥٦٧'] as $phone) {
                $this->postJson($this->base.'/checkout', $this->payload($phone) + ['cart_mode' => $mode], $cartHeaders)->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
            }
        }
        $this->getJson($this->base.'/cart', $cartHeaders)->assertOk()->assertJsonPath('data.items.0.quantity', 2);
        $this->postJson($this->base.'/checkout?lang=ar', $this->payload(), $cartHeaders)->assertUnprocessable()->assertJsonPath('errors.customer_phone.0', 'لا يمكن لهذا الرقم إنشاء طلبات جديدة في هذا المتجر. تواصل مع المتجر للمساعدة.');
        $customer = StoreCustomer::create(['store_id' => $this->store->id, 'name' => 'Signed review', 'email' => 'signed@example.test', 'password' => bcrypt('LocalTestPassword!'), 'phone' => '01001234568', 'is_active' => true]);
        $token = $this->postJson($this->base.'/auth/login', ['email' => $customer->email, 'password' => 'LocalTestPassword!'])->assertOk()->json('token');
        $this->postJson($this->base.'/checkout', $this->payload(), ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('store_payment_transactions', 0);
        $this->assertDatabaseCount('store_inventory_movements', 0);
        $this->assertSame(0, $this->product->fresh()->reserved_quantity);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_digital_hidden_or_invalid_phone_is_required_and_codes_remain_unallocated(): void
    {
        $fields = app(CheckoutFields::class)->defaults();
        foreach ($fields as &$field) {
            if ($field['key'] === 'phone') {
                $field['enabled'] = $field['required'] = false;
            }
        }
        $this->store->update(['checkout_fields' => $fields]);
        $this->product->update(['digital_type' => 'codes', 'track_inventory' => false]);
        ProductDigitalCode::create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'value' => 'LOCAL-PRIVATE-CODE', 'digest' => hash('sha256', 'LOCAL-PRIVATE-CODE')]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $block = $this->block();
        $fieldsResponse = $this->getJson($this->base.'/checkout/fields?product_ids[]='.$this->product->id)->assertOk();
        $phone = collect($fieldsResponse->json('data'))->firstWhere('key', 'phone');
        $this->assertTrue($phone['phone_block_required']);
        $this->assertTrue($phone['required']);
        foreach ([null, 'invalid', '+201001234567'] as $raw) {
            $this->postJson($this->base.'/checkout', $this->payload($raw) + ['payment_method' => 'bank_transfer'])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertNull(ProductDigitalCode::first()->store_order_item_id);
        $this->putJson($this->endpoint.'/'.$block['id'], ['active' => false, 'note' => $block['note'], 'version' => 1], $this->auth)->assertOk();
        $this->postJson($this->base.'/checkout', $this->payload(null) + ['payment_method' => 'bank_transfer'])->assertCreated();
    }

    public function test_already_created_checkout_replay_receipt_and_payment_retry_survive_later_block(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $payload = $this->payload() + ['payment_method' => 'bank_transfer'];
        $first = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated();
        $this->block();
        $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($this->base.'/checkout/receipt', ['token' => $first->json('receipt.token')])->assertOk();
        $retry = app(PaymentRetryToken::class)->make($this->store->id, $first->json('data.id'));
        $this->postJson($this->base.'/checkout/payment/retry', ['token' => $retry])->assertOk();
        $this->postJson($this->base.'/checkout', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_payment_transactions', 1);
    }

    public function test_authoritative_service_sees_block_added_after_cart_validation(): void
    {
        $cart = app(CartService::class)->create($this->store, null);
        app(CartService::class)->addItem($cart, $this->product->id, 1);
        app(PhoneBlocking::class)->add($this->store, '01001234567', null, $this->store->owner_user_id);
        try {
            app(CheckoutService::class)->place($this->store, $cart, null, ['name' => 'Review', 'email' => 'review@example.test', 'phone' => '+201001234567'], null, 'cod');
            $this->fail('Authoritative checkout ignored the new phone block.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_phone', $exception->errors());
        }
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertDatabaseCount('store_orders', 0);
    }

    public function test_country_changes_do_not_reinterpret_saved_canonical_blocks_or_other_stores(): void
    {
        $this->block('+44 20 7031 3000');
        $this->store->update(['order_limits' => ['max_product_quantity' => 0, 'max_orders_per_phone_24h' => 0, 'phone_country' => 'GB']]);
        $this->postJson($this->base.'/checkout', $this->payload('020 7031 3000'))->assertUnprocessable();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-phone']);
        app(PhoneBlocking::class)->assertAllowed($other, '+442070313000');
        $this->assertFalse(app(PhoneBlocking::class)->hasActive($other));
        $this->postJson($this->base.'/checkout', $this->payload('+201001234568'))->assertCreated();
    }

    public function test_list_and_history_paginate_and_foreign_search_is_empty(): void
    {
        $block = $this->block();
        for ($n = 0; $n < 27; $n++) {
            app(PhoneBlocking::class)->update($this->store, $block['id'], true, 'Note '.$n, $n + 1, $this->store->owner_user_id);
        }
        $this->getJson($this->endpoint.'/'.$block['id'].'/history?page=1', $this->auth)->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 28);
        $this->getJson($this->endpoint.'/'.$block['id'].'/history?page=2', $this->auth)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($this->endpoint.'?per_page=1&page=1', $this->auth)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->endpoint.'?q=999999', $this->auth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->endpoint.'?per_page=51', $this->auth)->assertUnprocessable();
    }
}

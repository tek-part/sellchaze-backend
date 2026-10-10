<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Mail\DigitalOrderPaidMail;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\DigitalProducts;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\PaymentRetryToken;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Services\Outbox\OutboxPublisher;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DigitalFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private string $base = 'http://fulfillment.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        Mail::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Digital store', 'slug' => 'fulfillment', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'ar']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'fulfillment.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'test_mode' => true,
            'credentials' => ['account_name' => 'Review holder', 'bank_name' => 'Review bank', 'iban' => 'TEST-ACCOUNT', 'secret_key' => 'NEVER-PUBLIC'], 'notes' => 'Use the order number']);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Digital guide', 'slug' => 'guide', 'price' => 100, 'is_active' => true, 'digital_type' => 'link', 'digital_url' => 'https://example.test/private-guide']);
    }

    private function place(?string $key = null): array
    {
        return $this->postJson($this->base.'/checkout', $this->body(), $key ? ['Idempotency-Key' => $key] : [])->assertCreated()->json();
    }

    private function body(): array
    {
        return ['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'customer_name' => 'Review buyer', 'customer_phone' => '01000000000', 'customer_email' => 'buyer@example.test', 'payment_method' => 'bank_transfer'];
    }

    private function confirm(int $id, string $reference = 'BANK-REVIEW'): TestResponse
    {
        return $this->postJson('/api/v1/my-store/orders/'.$id.'/payment/confirm-bank', ['reference' => $reference],
            ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)]);
    }

    public function test_private_receipt_requires_correct_store_unexpired_purpose_specific_token_and_preserves_bank_snapshot(): void
    {
        $data = $this->place();
        $token = $data['receipt']['token'];
        $this->postJson($this->base.'/checkout/receipt', ['token' => $token])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.payment_status', 'pending')->assertJsonPath('data.items.0.digital_delivery.values', [])
            ->assertJsonPath('bank_transfer.fields.iban', 'TEST-ACCOUNT')->assertJsonMissingPath('bank_transfer.fields.secret_key')->assertJsonMissingPath('data.timeline');
        StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', 'bank_transfer')->first()->update(['credentials' => ['iban' => 'CHANGED']]);
        $this->postJson($this->base.'/checkout/receipt', ['token' => $token])->assertOk()->assertJsonPath('bank_transfer.fields.iban', 'TEST-ACCOUNT');
        $order = StoreOrder::forStore($this->store->id)->findOrFail($data['data']['id']);
        foreach ([$token.'x', $order->order_number, app(OrderReceipt::class)->make($order, -1)['token'], app(PaymentRetryToken::class)->make($this->store->id, $order->id)] as $bad) {
            $this->postJson($this->base.'/checkout/receipt', ['token' => $bad])->assertNotFound();
        }
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-receipt', 'status' => 'active']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other-receipt.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->postJson('http://other-receipt.sellchase.com/api/v1/storefront/checkout/receipt', ['token' => $token])->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_manual_confirmation_releases_delivery_once_and_replay_refreshes_paid_state(): void
    {
        $key = (string) Str::uuid();
        $data = $this->place($key);
        $id = $data['data']['id'];
        $this->confirm($id)->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.items.0.digital_delivery.values.0', 'https://example.test/private-guide');
        $this->confirm($id)->assertOk();
        $this->confirm($id, 'DIFFERENT')->assertUnprocessable();
        $this->assertDatabaseCount('store_order_status_changes', 1);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        $this->assertDatabaseHas('store_payment_transactions', ['store_order_id' => $id, 'status' => 'paid', 'provider_reference' => 'BANK-REVIEW']);
        $this->postJson($this->base.'/checkout', $this->body(), ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.items.0.digital_delivery.status', 'ready')->assertJsonPath('payment.status', 'paid')->assertJsonPath('payment.redirect_url', null);
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('bank_transfer', null)->assertJsonPath('data.payment_status', 'paid');
        app(CurrentStore::class)->forget();
        app(OutboxPublisher::class)->publishPending();
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSent(DigitalOrderPaidMail::class, 1);
        Mail::assertSent(DigitalOrderPaidMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
        $this->assertStringNotContainsString('private-guide', OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->first()->getRawOriginal('payload'));
    }

    public function test_wrong_owner_missing_permission_cancelled_and_mismatched_amount_cannot_confirm(): void
    {
        $data = $this->place();
        $id = $data['data']['id'];
        $outsider = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $outsider->assignRole('Merchant');
        $this->postJson('/api/v1/stores/'.$this->store->id.'/orders/'.$id.'/payment/confirm-bank', ['reference' => 'BAD'], ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($outsider)])->assertForbidden();
        StorePaymentTransaction::where('store_order_id', $id)->update(['amount' => 1]);
        $this->confirm($id)->assertUnprocessable();
        StorePaymentTransaction::where('store_order_id', $id)->update(['amount' => 100]);
        app(CurrentStore::class)->set($this->store);
        app(StoreOrderService::class)->transition(StoreOrder::query()->findOrFail($id), 'cancelled');
        $this->confirm($id)->assertUnprocessable();
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('data.items.0.digital_delivery.values', [])->assertJsonPath('bank_transfer', null);
        $this->assertSame(0, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        $this->owner->syncRoles([]);
        Cache::forget('jwt_access_user:'.$this->owner->id);
        $this->confirm($id)->assertForbidden();
    }

    public function test_employee_without_order_permission_cannot_confirm_and_cancelled_paid_order_is_not_emailed(): void
    {
        $data = $this->place();
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $this->postJson('/api/v1/my-store/orders/'.$data['data']['id'].'/payment/confirm-bank', ['reference' => 'BAD'],
            ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)])->assertForbidden();
        $this->confirm($data['data']['id'])->assertOk();
        app(CurrentStore::class)->set($this->store);
        app(StoreOrderService::class)->transition(StoreOrder::query()->findOrFail($data['data']['id']), 'cancelled');
        app(OutboxPublisher::class)->publishPending();
        Mail::assertNothingSent();
    }

    public function test_code_mail_escapes_values_and_survives_deleted_catalog(): void
    {
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Code', 'slug' => 'code', 'price' => 100, 'is_active' => true, 'digital_type' => 'codes']);
        app(DigitalProducts::class)->appendCodes($this->product, ['CODE<script>alert(1)</script>']);
        $data = $this->place();
        $this->confirm($data['data']['id'])->assertOk();
        app(CurrentStore::class)->set($this->store);
        $this->product->delete();
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSent(DigitalOrderPaidMail::class, function ($mail) {
            $html = $mail->render();
            $this->assertStringContainsString('CODE&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringContainsString('#receipt=', $html);

            return true;
        });
    }

    public function test_mail_failure_is_retryable_without_disclosing_delivery_secrets(): void
    {
        $data = $this->place();
        $this->confirm($data['data']['id'])->assertOk();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('secret-private-guide'));
        app(CurrentStore::class)->forget();
        app(OutboxPublisher::class)->publishPending();
        $event = OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->firstOrFail();
        $this->assertNull($event->published_at);
        $this->assertSame(1, $event->attempts);
        $this->assertStringNotContainsString('secret-private-guide', $event->last_error);
        Mail::swap($fake);
        $this->travel(10)->seconds();
        app(OutboxPublisher::class)->publishPending();
        $this->assertNotNull($event->fresh()->published_at);
        Mail::assertSent(DigitalOrderPaidMail::class, 1);
    }

    public function test_signed_provider_webhook_records_delivery_without_tenant_context_and_duplicate_event_does_not_resend(): void
    {
        $data = $this->place();
        StoreOrder::forStore($this->store->id)->whereKey($data['data']['id'])->update(['payment_method' => 'stripe']);
        $transaction = StorePaymentTransaction::where('store_order_id', $data['data']['id'])->first();
        $transaction->update(['gateway' => 'stripe', 'provider_reference' => 'cs_review']);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'stripe', 'enabled' => true, 'credentials' => ['webhook_secret' => 'whsec_review']]);
        $json = json_encode(['id' => 'evt_review_paid', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_review', 'payment_status' => 'paid', 'payment_intent' => 'pi_review',
            'metadata' => ['transaction_id' => $transaction->id, 'store_order_id' => $data['data']['id']]]]], JSON_THROW_ON_ERROR);
        $signature = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$json, 'whsec_review');
        app(CurrentStore::class)->forget();
        $url = '/api/v1/payment-webhooks/'.$this->store->id.'/stripe';
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'bad'], $json)->assertStatus(401);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $json)->assertOk();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $json)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, OutboxMessage::where('event_type', 'StorefrontDigitalPaid')->count());
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSent(DigitalOrderPaidMail::class, 1);
        $this->postJson($this->base.'/checkout/receipt', ['token' => $data['receipt']['token']])->assertOk()->assertJsonPath('data.payment_status', 'paid');
    }
}

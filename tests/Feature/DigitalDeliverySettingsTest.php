<?php

namespace Tests\Feature;

use App\Jobs\SendDigitalWhatsapp;
use App\Mail\DigitalOrderItemMail;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\DigitalDeliverySettings;
use App\Services\Commerce\DigitalDeliveryTracking;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\JwtTokenService;
use App\Services\Outbox\OutboxPublisher;
use App\Services\Outbox\OutboxRecorder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigitalDeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $endpoint = '/api/v1/my-store/digital-delivery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Delivery review', 'slug' => 'delivery-review', 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'delivery-review.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function config(): array
    {
        return app(DigitalDeliverySettings::class)->defaults($this->store);
    }

    private function product(): Product
    {
        return Product::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Guide', 'slug' => Str::slug('guide-'.Str::random(8)), 'price' => 100, 'is_active' => true, 'digital_type' => 'codes']);
    }

    private function paidOrder(): StoreOrder
    {
        $order = StoreOrder::forStore($this->store)->create(['store_id' => $this->store->id, 'order_number' => 'ORD-REVIEW-'.Str::random(8), 'customer_name' => '<Buyer>', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+201001234567', 'payment_status' => 'paid', 'status' => 'completed', 'currency' => 'EGP', 'subtotal' => 200, 'grand_total' => 200]);
        foreach (['PRIVATE-A', 'PRIVATE-B'] as $code) {
            $product = $this->product();
            StoreOrderItem::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_product_id' => $product->id, 'name' => $product->name, 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100, 'digital_delivery' => ['type' => 'codes', 'values' => [$code]]]);
        }

        return $order;
    }

    public function test_settings_validate_variables_product_ownership_headers_and_connection_prerequisite(): void
    {
        $this->getJson($this->endpoint, $this->auth)->assertOk()->assertJsonPath('data.email_enabled', true)->assertJsonPath('connection.has_token', false);
        $config = $this->config();
        $this->putJson($this->endpoint, ['settings' => $config], $this->auth)->assertOk();
        foreach ([['email_subject' => "Subject\r\nBcc: other@example.test"], ['email_body' => '{unknown} {code_or_link}'], ['email_body' => 'No delivery value'], ['whatsapp_enabled' => true], ['overrides' => [['product_id' => 9999] + $config]]] as $changes) {
            $this->putJson($this->endpoint, ['settings' => array_replace($config, $changes)], $this->auth)->assertUnprocessable();
        }
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->getJson('/api/v1/stores/'.$this->store->id.'/digital-delivery', ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_connection_is_encrypted_verified_and_rotation_disables_all_whatsapp_overrides(): void
    {
        $this->putJson($this->endpoint.'/connection', ['instance_id' => 'review-instance', 'access_token' => 'LOCAL-TOKEN-ONLY-123'], $this->auth)->assertOk()->assertJsonPath('connection.has_token', true)->assertJsonMissing(['access_token']);
        $this->assertStringNotContainsString('LOCAL-TOKEN-ONLY-123', DB::table('stores')->where('id', $this->store->id)->value('digital_delivery_credentials'));
        $this->assertArrayNotHasKey('digital_delivery_credentials', $this->store->fresh()->toArray());
        Http::fake(['https://api.wawp.net/v2/session/info' => Http::response(['status' => 'WORKING'])]);
        $this->postJson($this->endpoint.'/verify', [], $this->auth)->assertOk();
        $config = array_replace($this->config(), ['whatsapp_enabled' => true]);
        $override = $config;
        unset($override['enabled'], $override['overrides']);
        $config['overrides'] = [['product_id' => $this->product()->id] + $override];
        $this->putJson($this->endpoint, ['settings' => $config], $this->auth)->assertOk();
        $this->putJson($this->endpoint.'/connection', ['instance_id' => 'another-instance', 'access_token' => 'LOCAL-ROTATED-TOKEN'], $this->auth)->assertOk()->assertJsonPath('connection.verified_at', null)->assertJsonPath('data.whatsapp_enabled', false)->assertJsonPath('data.overrides.0.whatsapp_enabled', false);
        Http::assertSentCount(1);
    }

    public function test_paid_fanout_uses_item_overrides_and_never_exposes_private_values_in_events(): void
    {
        $order = $this->paidOrder();
        $items = $order->items()->withoutGlobalScopes()->get();
        $config = $this->config();
        $override = $config;
        unset($override['enabled'], $override['overrides']);
        $config['overrides'] = [['product_id' => $items[1]->store_product_id] + array_replace($override, ['sender_name' => 'Product sender', 'email_subject' => 'Special {product_name}', 'email_body' => 'Second only {code_or_link} {customer_name}'])];
        $this->putJson($this->endpoint, ['settings' => $config], $this->auth)->assertOk();
        app(OutboxRecorder::class)->record('StorefrontDigitalPaid', 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id]);
        app(OutboxPublisher::class)->publishPending();
        $this->assertSame(2, OutboxMessage::where('event_type', DigitalDeliverySettings::CHANNELS['email'])->count());
        $this->assertStringNotContainsString('PRIVATE-A', OutboxMessage::all()->toJson());
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSentCount(2);
        Mail::assertSent(DigitalOrderItemMail::class, fn ($mail) => str_contains($mail->render(), 'Second only PRIVATE-B &lt;Buyer&gt;') && ! str_contains($mail->render(), 'PRIVATE-A'));
        $this->assertSame(0, app(OutboxPublisher::class)->publishPending()['published']);
        $rows = app(DigitalDeliveryTracking::class)->status($order);
        $this->assertSame(['sent', 'sent'], collect($rows)->where('channel', 'email')->pluck('state')->all());
        $this->assertFalse(collect($rows)->where('channel', 'email')->contains('can_resend', true));
    }

    public function test_whatsapp_claim_replay_never_resends_an_ambiguous_provider_result(): void
    {
        $order = $this->paidOrder();
        $item = $order->items()->withoutGlobalScopes()->firstOrFail();
        $config = array_replace($this->config(), ['email_enabled' => false, 'whatsapp_enabled' => true]);
        $this->store->update(['digital_delivery_configuration' => $config, 'digital_delivery_credentials' => ['instance_id' => 'review', 'access_token' => 'LOCAL-TOKEN-ONLY', 'verified_at' => now()->toIso8601String()]]);
        $message = app(OutboxRecorder::class)->record(DigitalDeliverySettings::CHANNELS['whatsapp'], 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_order_item_id' => $item->id, 'recipient' => $order->customer_phone], ['settings' => $config]);
        $message->update(['published_at' => now()]);
        Http::fake(['https://api.wawp.net/v2/send/text' => Http::response(['status' => 'unclear'], 502)]);
        $job = new SendDigitalWhatsapp($message->id);
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        $this->assertSame('unknown', $message->fresh()->metadata['transport_state']);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('PRIVATE-A', $message->fresh()->toJson());
        $this->assertNull(StoreDigitalWhatsappClient::phone('01001234567'));
    }

    public function test_employee_permissions_and_foreign_product_overrides_are_rejected(): void
    {
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->endpoint, $headers)->assertForbidden();
        $this->putJson($this->endpoint, ['settings' => $this->config()], $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->endpoint, $headers)->assertOk();
        $foreignStore = Store::create(['owner_user_id' => $this->store->owner_user_id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreign = Product::withoutGlobalScopes()->create(['store_id' => $foreignStore->id, 'name' => 'Foreign guide', 'slug' => 'foreign-guide', 'price' => 100, 'is_active' => true, 'digital_type' => 'codes']);
        $config = $this->config();
        $override = $config;
        unset($override['enabled'], $override['overrides']);
        $config['overrides'] = [['product_id' => $foreign->id] + $override];
        $this->putJson($this->endpoint, ['settings' => $config], $headers)->assertUnprocessable()->assertJsonValidationErrors('settings.overrides.0.product_id');
        Http::assertNothingSent();
    }

    public function test_item_email_resend_is_scoped_replay_safe_and_does_not_resend_other_products(): void
    {
        $order = $this->paidOrder();
        $this->store->update(['digital_delivery_configuration' => $this->config()]);
        app(OutboxRecorder::class)->record('StorefrontDigitalPaid', 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id]);
        app(OutboxPublisher::class)->publishPending();
        app(OutboxPublisher::class)->publishPending();
        $this->travel(61)->seconds();
        $tracking = app(DigitalDeliveryTracking::class);
        $row = collect($tracking->status($order))->firstWhere('channel', 'email');
        $this->assertTrue($row['can_resend']);
        $key = (string) Str::uuid();
        $tracking->resendEmail($order, $row['item_id'], $row['message_id'], $key, $this->store->owner_user_id);
        $tracking->resendEmail($order, $row['item_id'], $row['message_id'], $key, $this->store->owner_user_id);
        $this->assertSame(3, OutboxMessage::where('event_type', DigitalDeliverySettings::CHANNELS['email'])->count());
        $this->assertSame(1, $order->statusChanges()->withoutGlobalScopes()->where('source', 'email_resend')->count());
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSentCount(3);
    }

    public function test_whatsapp_phone_requirement_follows_selected_product_overrides(): void
    {
        $product = $this->product();
        $config = array_replace($this->config(), ['whatsapp_enabled' => true]);
        $this->store->update(['digital_delivery_configuration' => $config]);
        $url = 'http://delivery-review.sellchase.com/api/v1/storefront/checkout/fields?product_ids[]='.$product->id;
        $fields = $this->getJson($url)->assertOk()->json('data');
        $this->assertTrue(collect($fields)->firstWhere('key', 'phone')['required']);
        $this->assertStringContainsString('country code', collect($fields)->firstWhere('key', 'phone')['hint']['en']);
        $override = $config;
        unset($override['enabled'], $override['overrides']);
        $config['overrides'] = [['product_id' => $product->id] + array_replace($override, ['whatsapp_enabled' => false])];
        $this->store->update(['digital_delivery_configuration' => $config]);
        $fields = $this->getJson($url)->assertOk()->json('data');
        $this->assertFalse(collect($fields)->firstWhere('key', 'phone')['digital_required']);
    }

    public function test_whatsapp_is_not_sent_before_publication_or_for_cancelled_orders(): void
    {
        $order = $this->paidOrder();
        $item = $order->items()->withoutGlobalScopes()->firstOrFail();
        $config = array_replace($this->config(), ['whatsapp_enabled' => true]);
        $this->store->update(['digital_delivery_configuration' => $config, 'digital_delivery_credentials' => ['instance_id' => 'review', 'access_token' => 'LOCAL-TOKEN-ONLY', 'verified_at' => now()->toIso8601String()]]);
        $message = app(OutboxRecorder::class)->record(DigitalDeliverySettings::CHANNELS['whatsapp'], 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_order_item_id' => $item->id, 'recipient' => $order->customer_phone], ['settings' => DigitalDeliverySettings::snapshot($config)]);
        $job = new SendDigitalWhatsapp($message->id);
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        $this->assertArrayNotHasKey('transport_state', $message->fresh()->metadata);
        $message->update(['published_at' => now()]);
        $order->update(['status' => 'cancelled']);
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        $this->assertSame('skipped', $message->fresh()->metadata['transport_state']);
        Http::assertNothingSent();
    }

    public function test_manual_item_email_cannot_race_pending_automatic_fanout(): void
    {
        $order = $this->paidOrder();
        $this->store->update(['digital_delivery_configuration' => $this->config()]);
        app(OutboxRecorder::class)->record('StorefrontDigitalPaid', 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id]);
        $tracking = app(DigitalDeliveryTracking::class);
        $row = collect($tracking->status($order))->firstWhere('channel', 'email');
        $this->assertSame('queued', $row['state']);
        $this->assertFalse($row['can_resend']);
        $this->postJson('/api/v1/my-store/orders/'.$order->id.'/digital-delivery/email', ['item_id' => $row['item_id'], 'message_id' => null, 'request_key' => (string) Str::uuid()], $this->auth)->assertUnprocessable();
        $this->assertSame(0, OutboxMessage::where('event_type', DigitalDeliverySettings::CHANNELS['email'])->count());
        app(OutboxPublisher::class)->publishPending();
        app(OutboxPublisher::class)->publishPending();
        Mail::assertSentCount(2);
    }

    public function test_whatsapp_acceptance_keeps_reference_without_claiming_delivery_and_uses_only_store_credentials(): void
    {
        $order = $this->paidOrder();
        $item = $order->items()->withoutGlobalScopes()->firstOrFail();
        $config = array_replace($this->config(), ['whatsapp_enabled' => true]);
        $this->store->update(['digital_delivery_configuration' => $config, 'digital_delivery_credentials' => ['instance_id' => 'review', 'access_token' => 'LOCAL-TOKEN-ONLY', 'verified_at' => now()->toIso8601String()]]);
        $message = app(OutboxRecorder::class)->record(DigitalDeliverySettings::CHANNELS['whatsapp'], 'store_order', $order->id, ['store_id' => $this->store->id, 'store_order_id' => $order->id, 'store_order_item_id' => $item->id, 'recipient' => $order->customer_phone], ['settings' => DigitalDeliverySettings::snapshot($config)]);
        $message->update(['published_at' => now()]);
        Http::fake(['https://api.wawp.net/v2/send/text' => Http::response(['status' => 'queued', 'job_id' => 'local-job-123'])]);
        $job = new SendDigitalWhatsapp($message->id);
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        $job->handle(app(StoreDigitalWhatsappClient::class), app(DigitalDeliverySettings::class));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.wawp.net/v2/send/text' && $request['instance_id'] === 'review' && $request['access_token'] === 'LOCAL-TOKEN-ONLY' && $request['chatId'] === '201001234567@c.us' && str_contains($request['message'], 'PRIVATE-A') && ! str_contains($request['message'], 'PRIVATE-B'));
        Http::assertSentCount(1);
        $row = collect(app(DigitalDeliveryTracking::class)->status($order))->firstWhere('channel', 'whatsapp');
        $this->assertSame('accepted', $row['state']);
        $this->assertSame('local-job-123', $row['provider_reference']);
        $this->assertFalse($row['can_resend']);
        $this->assertStringNotContainsString('LOCAL-TOKEN-ONLY', $message->fresh()->toJson());
        $this->assertStringNotContainsString('PRIVATE-A', $message->fresh()->toJson());
    }

    public function test_recovery_dispatches_only_published_unclaimed_whatsapp_messages(): void
    {
        $recorder = app(OutboxRecorder::class);
        $type = DigitalDeliverySettings::CHANNELS['whatsapp'];
        $pending = $recorder->record($type, 'store_order', 1, [], []);
        $unclaimed = $recorder->record($type, 'store_order', 1, [], []);
        $unclaimed->update(['published_at' => now()]);
        $claimed = $recorder->record($type, 'store_order', 1, [], ['transport_state' => 'sending']);
        $claimed->update(['published_at' => now()]);
        $accepted = $recorder->record($type, 'store_order', 1, [], ['transport_state' => 'accepted']);
        $accepted->update(['published_at' => now()]);
        $email = $recorder->record(DigitalDeliverySettings::CHANNELS['email'], 'store_order', 1, [], []);
        $email->update(['published_at' => now()]);
        $this->artisan('digital-delivery:dispatch-whatsapp')->assertSuccessful();
        Queue::assertPushed(SendDigitalWhatsapp::class, fn ($job) => $job->messageId === $unclaimed->id);
        Queue::assertPushed(SendDigitalWhatsapp::class, 1);
        Http::assertNothingSent();
    }

    public function test_private_template_subject_is_masked_in_mail_logs_and_rendered_html_is_escaped(): void
    {
        $order = $this->paidOrder();
        $item = $order->items()->withoutGlobalScopes()->firstOrFail();
        $config = array_replace($this->config(), ['email_subject' => 'Secret {code_or_link}', 'email_body' => '<script>bad</script> {customer_name} {code_or_link}']);
        config(['mail.default' => 'array', 'mail.from.address' => 'platform@example.test']);
        Mail::swap(new MailManager($this->app));
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        Mail::to($order->customer_email)->send(new DigitalOrderItemMail($this->store, $order, $item, $config, (string) Str::uuid()));
        $email = $transport->messages()->first()->getOriginalMessage();
        $this->assertSame('Secret PRIVATE-A', $email->getSubject());
        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $email->getHtmlBody());
        $this->assertSame('platform@example.test', $email->getFrom()[0]->getAddress());
        $this->assertDatabaseHas('email_logs', ['template_key' => 'storefront-digital-item', 'subject' => 'Digital product delivery']);
        $this->assertDatabaseMissing('email_logs', ['subject' => 'Secret PRIVATE-A']);
    }
}

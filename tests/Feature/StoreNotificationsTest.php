<?php

namespace Tests\Feature;

use App\Jobs\SendStoreNotificationWhatsapp;
use App\Mail\StoreNotificationMail;
use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\Commerce\StoreOrderPlacement;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Services\Notifications\StoreNotificationSettings;
use App\Services\Outbox\OutboxPublisher;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private array $auth;

    private string $endpoint = '/api/v1/my-store/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'email' => 'owner@example.test']);
        $this->owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner)];
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nile Goods', 'slug' => 'nile-goods', 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nile-goods.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function order(array $attributes = []): StoreOrder
    {
        $order = StoreOrder::forStore($this->store)->create(array_merge(['store_id' => $this->store->id, 'order_number' => 'NG-'.Str::random(6), 'customer_name' => 'Mona',
            'customer_email' => 'mona@example.test', 'customer_phone' => '+20 100 123 4567', 'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'pending',
            'currency' => 'EGP', 'subtotal' => 300, 'shipping_total' => 50, 'grand_total' => 350, 'shipping_address' => ['line' => '5 Tahrir St', 'city' => 'Cairo']], $attributes));
        StoreOrderItem::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'name' => 'Linen shirt', 'unit_price' => 150, 'quantity' => 2, 'line_total' => 300]);

        return $order->fresh();
    }

    private function queued(StoreOrder $order): array
    {
        return OutboxMessage::query()->whereIn('event_type', StoreNotificationSettings::MESSAGE_TYPES)->where('aggregate_id', (string) $order->id)
            ->get()->map(fn ($message) => $message->payload['event'].':'.$message->payload['channel'].':'.$message->payload['recipient'])->sort()->values()->all();
    }

    private function verifyWhatsapp(): void
    {
        $this->store->update(['digital_delivery_credentials' => ['instance_id' => 'review', 'access_token' => 'LOCAL-TOKEN-ONLY', 'verified_at' => now()->toIso8601String()]]);
    }

    private function settings(): array
    {
        return $this->getJson($this->endpoint, $this->auth)->assertOk()->json('data');
    }

    public function test_settings_expose_catalog_and_validate_templates_recipients_and_whatsapp_prerequisite(): void
    {
        $response = $this->getJson($this->endpoint, $this->auth)->assertOk()
            ->assertJsonPath('owner_email', 'owner@example.test')
            ->assertJsonPath('connection.whatsapp_verified', false)
            ->assertJsonPath('data.events.order_confirmation.email_enabled', true)
            ->assertJsonPath('data.events.order_confirmed.email_enabled', false);
        $this->assertSame(['order_confirmation', 'order_confirmed', 'order_cancelled', 'order_refunded', 'shipping_confirmation', 'order_delivered', 'staff_new_order'], array_column($response->json('catalog'), 'key'));
        $this->assertArrayNotHasKey('whatsapp_body', $response->json('data.events.staff_new_order'));

        $settings = $this->settings();
        $settings['events']['order_confirmation']['email_body'] = 'Hi {customer} thanks';
        $this->putJson($this->endpoint, ['settings' => $settings], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('settings.events.order_confirmation.email_body');

        $settings = $this->settings();
        $settings['events']['shipping_confirmation']['whatsapp_enabled'] = true;
        $this->putJson($this->endpoint, ['settings' => $settings], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('settings.events');

        $settings = $this->settings();
        $settings['staff_recipients'] = ['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test', 'e@x.test', 'f@x.test'];
        $this->putJson($this->endpoint, ['settings' => $settings], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('settings.staff_recipients');

        $this->verifyWhatsapp();
        $settings = $this->settings();
        $settings['sender_name'] = 'Nile Goods Team';
        $settings['reply_to'] = 'help@nile.test';
        $settings['staff_recipients'] = ['OPS@nile.test'];
        $settings['events']['shipping_confirmation']['whatsapp_enabled'] = true;
        $settings['events']['order_confirmation']['email_subject'] = 'Thanks {customer_name}! Order {order_number}';
        $this->putJson($this->endpoint, ['settings' => $settings], $this->auth)->assertOk()
            ->assertJsonPath('data.staff_recipients', ['ops@nile.test'])
            ->assertJsonPath('data.events.shipping_confirmation.whatsapp_enabled', true)
            ->assertJsonPath('data.events.order_confirmation.email_subject', 'Thanks {customer_name}! Order {order_number}');
    }

    public function test_a_cod_order_queues_customer_confirmation_and_staff_alert_once_and_sends_branded_mail(): void
    {
        $this->store->update(['notification_settings' => ['staff_recipients' => ['ops@nile.test'], 'events' => ['order_confirmation' => ['email_subject' => 'Thanks {customer_name}! Order {order_number}']]]]);
        $order = $this->order();
        DB::transaction(fn () => app(StoreOrderPlacement::class)->record($order));
        app(StoreNotificationSettings::class)->orderPlaced($order);

        $this->assertSame([
            'order_confirmation:email:mona@example.test',
            'staff_new_order:email:ops@nile.test',
            'staff_new_order:email:owner@example.test',
        ], $this->queued($order));

        app(OutboxPublisher::class)->publishPending();

        Mail::assertSent(StoreNotificationMail::class, function (StoreNotificationMail $mail) use ($order) {
            if (! $mail->hasTo('mona@example.test')) {
                return false;
            }
            $html = $mail->render();

            return $mail->subject === 'Thanks Mona! Order '.$order->order_number && str_contains($html, 'Linen shirt') && str_contains($html, '350.00 EGP')
                && str_contains($html, '/order/success?number='.$order->order_number) && str_contains($html, '5 Tahrir St');
        });
        Mail::assertSent(StoreNotificationMail::class, fn ($mail) => $mail->hasTo('owner@example.test') && str_contains($mail->render(), '/store/orders/'.$order->id));
        $this->assertSame(['sent'], OutboxMessage::query()->where('event_type', 'StoreNotificationEmail')->where('payload->recipient', 'mona@example.test')->pluck('metadata')->pluck('transport_state')->all());
    }

    public function test_an_online_order_waits_for_payment_before_confirming(): void
    {
        $order = $this->order(['payment_method' => 'paymob', 'payment_status' => 'pending']);
        app(StoreNotificationSettings::class)->orderPlaced($order);
        $this->assertSame(['staff_new_order:email:owner@example.test'], $this->queued($order));

        $order->update(['payment_status' => 'paid']);
        app(StoreNotificationSettings::class)->orderPaid($order);
        app(StoreNotificationSettings::class)->orderPaid($order);
        $this->assertSame(['order_confirmation:email:mona@example.test', 'staff_new_order:email:owner@example.test'], $this->queued($order));
    }

    public function test_a_digital_order_relies_on_its_digital_receipt_instead_of_a_second_confirmation(): void
    {
        $order = $this->order();
        StoreOrderItem::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'store_order_id' => $order->id, 'name' => 'E-book', 'unit_price' => 50, 'quantity' => 1, 'line_total' => 50, 'digital_delivery' => ['type' => 'link', 'values' => ['https://example.test/book']]]);
        app(StoreNotificationSettings::class)->orderPlaced($order);
        app(StoreNotificationSettings::class)->orderPaid($order);
        $this->assertSame(['staff_new_order:email:owner@example.test'], $this->queued($order));
    }

    public function test_status_changes_queue_their_event_and_a_disabled_event_is_skipped_at_send_time(): void
    {
        $order = $this->order();
        app(StoreOrderService::class)->transition($order, 'confirmed');
        $this->assertSame([], $this->queued($order), 'order_confirmed email is off by default');

        app(StoreOrderService::class)->transition($order->fresh(), 'cancelled');
        $this->assertSame(['order_cancelled:email:mona@example.test'], $this->queued($order));

        $this->store->update(['notification_settings' => ['events' => ['order_cancelled' => ['email_enabled' => false]]]]);
        app(OutboxPublisher::class)->publishPending();
        Mail::assertNothingSent();
        $this->assertSame('skipped', OutboxMessage::query()->where('event_type', 'StoreNotificationEmail')->first()->metadata['transport_state']);
    }

    public function test_whatsapp_shipping_message_is_claimed_once_and_drops_empty_tracking_lines(): void
    {
        $this->verifyWhatsapp();
        $this->store->update(['notification_settings' => ['events' => ['shipping_confirmation' => ['email_enabled' => false, 'whatsapp_enabled' => true]]]]);
        $order = $this->order(['status' => 'processing']);
        app(StoreOrderService::class)->transition($order, 'shipped');
        $this->assertSame(['shipping_confirmation:whatsapp:201001234567'], $this->queued($order));

        app(OutboxPublisher::class)->publishPending();
        Queue::assertPushed(SendStoreNotificationWhatsapp::class);
        $message = OutboxMessage::query()->where('event_type', 'StoreNotificationWhatsapp')->firstOrFail();

        Http::fake(['https://api.wawp.net/v2/send/text' => Http::response(['status' => 'queued', 'job_id' => 'local-job-1'])]);
        $job = new SendStoreNotificationWhatsapp($message->id);
        $job->handle(app(StoreDigitalWhatsappClient::class), app(StoreNotificationSettings::class));
        $job->handle(app(StoreDigitalWhatsappClient::class), app(StoreNotificationSettings::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chatId'] === '201001234567@c.us' && str_contains($request['message'], 'has shipped')
            && ! str_contains($request['message'], 'Tracking number') && ! str_contains($request['message'], 'Carrier'));
        $this->assertSame('accepted', $message->fresh()->metadata['transport_state']);
    }

    public function test_preview_test_send_order_log_and_resend(): void
    {
        $order = $this->order();
        $preview = $this->postJson($this->endpoint.'/preview', ['event' => 'order_confirmation', 'channel' => 'email',
            'template' => ['subject' => 'Order {order_number}', 'body' => 'Hello {customer_name}']], $this->auth)->assertOk();
        $this->assertSame('Order '.$order->order_number, $preview->json('subject'));
        $this->assertStringContainsString('Hello Mona', $preview->json('html'));
        $this->assertStringContainsString('Nile Goods', $preview->json('html'));
        $this->postJson($this->endpoint.'/preview', ['event' => 'staff_new_order', 'channel' => 'whatsapp', 'template' => ['body' => 'x']], $this->auth)->assertUnprocessable();

        $this->postJson($this->endpoint.'/test', ['event' => 'shipping_confirmation', 'channel' => 'email'], $this->auth)->assertOk()->assertJsonPath('status', 'sent');
        Mail::assertSent(StoreNotificationMail::class, fn ($mail) => $mail->hasTo('owner@example.test') && $mail->render() && str_starts_with($mail->subject, '[TEST]'));
        $this->postJson($this->endpoint.'/test', ['event' => 'shipping_confirmation', 'channel' => 'whatsapp', 'recipient' => '201001234567'], $this->auth)->assertUnprocessable();

        app(StoreNotificationSettings::class)->orderPlaced($order);
        $this->postJson('/api/v1/my-store/orders/'.$order->id.'/notifications/resend', ['event' => 'order_confirmation', 'channel' => 'email'], $this->auth)->assertOk();
        $log = $this->getJson('/api/v1/my-store/orders/'.$order->id.'/notifications', $this->auth)->assertOk()->json('data');
        $this->assertCount(3, $log);
        $this->assertContains('m***@example.test', array_column($log, 'recipient'));
        $this->assertSame(['queued'], array_values(array_unique(array_column($log, 'state'))));
        $this->postJson('/api/v1/my-store/orders/'.$order->id.'/notifications/resend', ['event' => 'staff_new_order', 'channel' => 'email'], $this->auth)->assertUnprocessable();
    }

    public function test_render_drops_lines_whose_variables_are_all_empty(): void
    {
        $text = StoreNotificationSettings::render("Hi {customer_name}\nCarrier: {carrier}\nTracking: {tracking_number}\nBye", ['customer_name' => 'Mona', 'carrier' => '', 'tracking_number' => '']);
        $this->assertSame("Hi Mona\nBye", $text);
    }
}

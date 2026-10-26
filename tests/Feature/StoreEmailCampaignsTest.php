<?php

namespace Tests\Feature;

use App\Jobs\SendStoreEmailCampaignBatch;
use App\Mail\StoreEmailCampaignMail;
use App\Models\Coupon;
use App\Models\NewsletterSubscriber;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCustomerProfile;
use App\Models\StoreDomain;
use App\Models\StoreEmailCampaign;
use App\Models\StoreEmailCampaignRecipient;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Marketing\EmailCampaignContent;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreEmailCampaignsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $owner;

    private array $auth;

    private array $otherAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        [$this->owner, $this->auth, $this->store] = $this->merchant('alpha');
        [, $this->otherAuth, $this->other] = $this->merchant('beta');
        config(['sellchase.email_campaigns.per_store_per_minute' => 60, 'sellchase.email_campaigns.platform_per_minute' => 300, 'sellchase.email_campaigns.batch_size' => 50]);
        RateLimiter::clear('email-campaigns:platform');
        RateLimiter::clear('email-campaigns:store:'.$this->store->id);
    }

    /** @return array{0: User, 1: array<string, string>, 2: Store} */
    private function merchant(string $slug): array
    {
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => 'ec-'.$slug,
            'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active', 'email' => $slug.'@shop.test']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'ec-'.$slug.'.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);

        return [$owner, $auth, $store];
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/my-store/email-campaigns'.($path !== '' ? '/'.$path : '');
    }

    private function subscriber(string $email, bool $active = true, ?Store $store = null): NewsletterSubscriber
    {
        return NewsletterSubscriber::withoutGlobalScopes()->create(['store_id' => ($store ?? $this->store)->id, 'email' => $email, 'is_active' => $active]);
    }

    private function order(array $attributes): StoreOrder
    {
        return StoreOrder::withoutGlobalScopes()->create(array_merge(['store_id' => $this->store->id, 'order_number' => 'EC-'.Str::random(8), 'status' => 'delivered',
            'payment_status' => 'paid', 'payment_method' => 'cod', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100, 'placed_at' => now()->subDay()], $attributes));
    }

    private function blocks(): array
    {
        return [
            ['id' => 'h1', 'type' => 'heading', 'text' => 'Summer sale'],
            ['id' => 't1', 'type' => 'text', 'html' => '<p>Big <strong>savings</strong> <a href="https://shop.test/sale?x=1&y=2">shop now</a></p>'],
            ['id' => 'b1', 'type' => 'button', 'label' => 'Shop the sale', 'url' => 'https://shop.test/collections/sale'],
            ['id' => 'd1', 'type' => 'divider'],
        ];
    }

    private function campaign(array $attributes = []): StoreEmailCampaign
    {
        $campaign = new StoreEmailCampaign;
        $campaign->forceFill(array_merge(['store_id' => $this->store->id, 'name' => 'Summer', 'subject' => 'Summer sale is here', 'locale' => 'en',
            'content' => app(EmailCampaignContent::class)->normalize($this->store, $this->blocks()), 'audience' => ['type' => 'all'], 'status' => 'draft'], $attributes))->save();

        return $campaign;
    }

    /** Schedule now, start (snapshot) and dispatch one round. */
    private function startNow(StoreEmailCampaign $campaign): void
    {
        $this->postJson($this->url($campaign->id.'/schedule'), ['scheduled_at' => null], $this->auth)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
    }

    private function runQueuedBatches(): void
    {
        foreach (Queue::pushed(SendStoreEmailCampaignBatch::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function recipients(StoreEmailCampaign $campaign)
    {
        return StoreEmailCampaignRecipient::withoutGlobalScopes()->where('campaign_id', $campaign->id);
    }

    public function test_crud_sanitizes_html_and_validates_blocks(): void
    {
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '100.00', 'compare_price' => '150.00', 'is_active' => true]);
        $coupon = Coupon::create(['store_id' => $this->store->id, 'code' => 'SUMMER10', 'type' => 'percentage', 'value' => '10.00', 'is_active' => true]);
        $created = $this->postJson($this->url(), [
            'name' => 'Summer', 'subject' => 'Hello', 'preheader' => 'Inside: deals', 'locale' => 'ar',
            'content' => [
                ['id' => 'x1', 'type' => 'text', 'html' => '<p onclick="steal()">Hi<script>alert(1)</script> <a href="javascript:alert(1)">bad</a> <img src="https://x.test/a.png"><iframe src="https://evil.test"></iframe></p>'],
                ['type' => 'products', 'product_ids' => [$product->id]],
                ['type' => 'coupon', 'coupon_id' => $coupon->id, 'text' => 'This week only'],
                ['type' => 'image', 'src' => 'https://cdn.test/banner.jpg', 'alt' => 'Banner', 'url' => 'https://shop.test/banner'],
            ],
            'audience' => ['type' => 'tags', 'tags' => ['VIP']],
        ], $this->auth)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.audience', ['type' => 'tags', 'tags' => ['VIP']]);
        $html = $created->json('data.content.0.html');
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Hi', $html);
        $id = $created->json('data.id');

        // Invalid blocks are rejected with a field path.
        $this->putJson($this->url($id), ['content' => [['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)']]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors('content.0.url');
        $this->putJson($this->url($id), ['content' => [['type' => 'script']]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('content.0.type');
        $foreign = Product::create(['store_id' => $this->other->id, 'user_id' => $this->owner->id, 'name' => 'Other', 'slug' => 'other', 'price' => '5.00', 'is_active' => true]);
        $this->putJson($this->url($id), ['content' => [['type' => 'products', 'product_ids' => [$foreign->id]]]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors('content.0.product_ids');

        // Preview renders the store layout, RTL for Arabic, product card and coupon code; unsaved content can be previewed.
        $preview = $this->postJson($this->url($id.'/preview'), [], $this->auth)->assertOk()->json('data.html');
        $this->assertStringContainsString('dir="rtl"', $preview);
        $this->assertStringContainsString('SUMMER10', $preview);
        $this->assertStringContainsString('/products/tee', $preview);
        $this->assertStringContainsString('150.00 EGP', $preview);
        $this->assertStringContainsString('Inside: deals', $preview);
        $this->assertStringNotContainsString('<script', $preview);
        $live = $this->postJson($this->url($id.'/preview'), ['content' => [['type' => 'heading', 'text' => 'Unsaved <b>title</b>']]], $this->auth)->assertOk()->json('data.html');
        $this->assertStringContainsString('Unsaved &lt;b&gt;title&lt;/b&gt;', $live);
        $this->assertSame('text', StoreEmailCampaign::withoutGlobalScopes()->find($id)->content[0]['type']);
        // An unsaved campaign can be previewed and counted too.
        $this->assertStringContainsString('Brand new', $this->postJson($this->url('preview'), ['content' => [['type' => 'heading', 'text' => 'Brand new']]], $this->auth)->assertOk()->json('data.html'));
        $this->postJson($this->url('audience-count'), ['audience' => ['type' => 'all']], $this->auth)->assertOk()->assertJsonPath('data.count', 0);

        $this->getJson($this->url(), $this->auth)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.status_counts.draft', 1)
            ->assertJsonPath('data.0.name', 'Summer');
        $this->getJson($this->url('options'), $this->auth)->assertOk()->assertJsonPath('data.coupons.0.code', 'SUMMER10');
        $this->deleteJson($this->url($id), [], $this->auth)->assertOk();
        $this->assertDatabaseMissing('store_email_campaigns', ['id' => $id]);
    }

    public function test_audience_is_only_consenting_subscribers_deduplicated(): void
    {
        $this->subscriber('mona@example.test');
        $this->subscriber('omar@example.test');
        $this->subscriber('gone@example.test', false);
        $this->subscriber('beta@example.test', true, $this->other);
        // Customers who ordered but never subscribed are not reachable.
        $this->order(['customer_name' => 'Nora', 'customer_email' => 'nora@example.test', 'grand_total' => 900]);
        $this->order(['customer_name' => 'Mona', 'customer_email' => 'MONA@example.test', 'grand_total' => 50]);
        // An earlier unsubscribe or bounce suppresses the address even if a subscriber row is active again.
        $old = $this->campaign(['status' => 'sent']);
        $this->subscriber('bounced@example.test');
        StoreEmailCampaignRecipient::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'campaign_id' => $old->id, 'email' => 'bounced@example.test', 'status' => 'bounced', 'token' => Str::random(48)]);

        $campaign = $this->campaign();
        $this->postJson($this->url($campaign->id.'/audience-count'), [], $this->auth)->assertOk()->assertJsonPath('data.count', 2);

        Queue::fake();
        $this->startNow($campaign);
        $emails = $this->recipients($campaign)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['mona@example.test', 'omar@example.test'], $emails);
        $this->assertSame(2, $campaign->fresh()->recipients_count);
        $this->assertSame('sending', $campaign->fresh()->status);

        // Running the snapshot again never duplicates an address.
        $campaign->forceFill(['link_urls' => null])->save();
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame(2, $this->recipients($campaign)->count());
    }

    public function test_segment_and_tag_audiences_require_email_consent(): void
    {
        $this->order(['customer_name' => 'Big', 'customer_email' => 'big@example.test', 'grand_total' => 900]);
        $this->order(['customer_name' => 'Big', 'customer_email' => 'big@example.test', 'grand_total' => 900]);
        $this->order(['customer_name' => 'Small', 'customer_email' => 'small@example.test', 'grand_total' => 20]);
        $this->order(['customer_name' => 'Rich no consent', 'customer_email' => 'rich@example.test', 'grand_total' => 5000]);
        $this->order(['customer_name' => 'Vip', 'customer_phone_normalized' => '+201001112222', 'customer_email' => 'vip@example.test', 'grand_total' => 30]);
        // The same address under a second directory key (an e-mail-only guest order) is one recipient.
        $this->order(['customer_name' => 'Vip', 'customer_email' => 'VIP@example.test', 'grand_total' => 30]);
        foreach (['big@example.test', 'small@example.test', 'vip@example.test'] as $email) {
            $this->subscriber($email);
        }
        StoreCustomerProfile::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'customer_key' => '+201001112222', 'tags' => ['VIP']]);
        StoreCustomerProfile::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'customer_key' => 'rich@example.test', 'tags' => ['VIP']]);
        $campaign = $this->campaign();

        $segment = ['type' => 'segment', 'filters' => ['spent_min' => 500]];
        $this->postJson($this->url($campaign->id.'/audience-count'), ['audience' => $segment], $this->auth)->assertOk()->assertJsonPath('data.count', 1);
        $this->postJson($this->url($campaign->id.'/audience-count'), ['audience' => ['type' => 'segment', 'filters' => ['orders_min' => 1]]], $this->auth)
            ->assertOk()->assertJsonPath('data.count', 3);
        $this->postJson($this->url($campaign->id.'/audience-count'), ['audience' => ['type' => 'tags', 'tags' => ['vip']]], $this->auth)->assertOk()->assertJsonPath('data.count', 1);
        $this->postJson($this->url($campaign->id.'/audience-count'), ['audience' => ['type' => 'nobody']], $this->auth)->assertUnprocessable();

        $this->putJson($this->url($campaign->id), ['audience' => ['type' => 'tags', 'tags' => ['VIP']]], $this->auth)->assertOk();
        Queue::fake();
        $this->startNow($campaign);
        $this->assertSame(['vip@example.test'], $this->recipients($campaign)->pluck('email')->all());
        $this->assertSame('+201001112222', $this->recipients($campaign)->value('customer_key'));
    }

    public function test_test_send_is_queued_to_the_typed_address_only_and_rate_limited(): void
    {
        Mail::fake();
        $this->subscriber('mona@example.test');
        $campaign = $this->campaign();

        $this->postJson($this->url($campaign->id.'/test'), ['email' => 'Me@Merchant.test'], $this->auth)->assertStatus(202);
        Mail::assertQueued(StoreEmailCampaignMail::class, fn (StoreEmailCampaignMail $mail) => $mail->hasTo('me@merchant.test') && $mail->test && $mail->recipient === null);
        Mail::assertQueuedCount(1);
        Mail::assertNothingSent();
        $this->assertSame(0, StoreEmailCampaignRecipient::withoutGlobalScopes()->count());
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->postJson($this->url($campaign->id.'/test'), ['email' => 'not-an-email'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('email');

        // The queued test e-mail restores in a worker (no tenant set), has a [TEST] subject and direct (untracked) links.
        app(CurrentStore::class)->forget();
        $mail = unserialize(serialize(new StoreEmailCampaignMail($campaign, null, true)));
        $this->assertSame($campaign->id, $mail->campaign->id);
        $this->assertStringContainsString('href="https://shop.test/collections/sale"', $mail->render());
        $mail->build();
        $this->assertSame('[TEST] Summer sale is here', $mail->subject);

        // Per-route limit (5 a minute per user).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->url($campaign->id.'/test'), ['email' => 'me@merchant.test'], $this->auth)->assertStatus(202);
        }
        $this->postJson($this->url($campaign->id.'/test'), ['email' => 'me@merchant.test'], $this->auth)->assertStatus(429);
    }

    public function test_scheduling_dispatch_and_completion(): void
    {
        Queue::fake();
        Mail::fake();
        $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');
        $campaign = $this->campaign();
        $empty = $this->campaign(['subject' => null]);
        $this->postJson($this->url($empty->id.'/schedule'), ['scheduled_at' => null], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('subject');

        $later = now()->addHours(2)->startOfMinute();
        $this->postJson($this->url($campaign->id.'/schedule'), ['scheduled_at' => $later->toIso8601String()], $this->auth)->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        $this->putJson($this->url($campaign->id), ['name' => 'Changed'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->recipients($campaign)->count());

        $this->travelTo($later->copy()->addMinute());
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        Queue::assertPushed(SendStoreEmailCampaignBatch::class, 1);
        $this->assertSame(2, $this->recipients($campaign)->whereNotNull('dispatched_at')->count());

        $this->runQueuedBatches();
        Mail::assertSent(StoreEmailCampaignMail::class, 2);
        Mail::assertSent(StoreEmailCampaignMail::class, fn ($mail) => $mail->hasTo('a@example.test') && ! $mail->test);
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $fresh = $campaign->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame(2, $fresh->sent_count);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_retried_jobs_never_email_a_recipient_twice(): void
    {
        Queue::fake();
        Mail::fake();
        $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $ids = $this->recipients($campaign)->orderBy('id')->pluck('id')->all();

        $job = new SendStoreEmailCampaignBatch($campaign->id, $ids);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']); // a retry / duplicate delivery of the same job
        app()->call([new SendStoreEmailCampaignBatch($campaign->id, $ids), 'handle']);
        Mail::assertSent(StoreEmailCampaignMail::class, 2);

        // A recipient left "sending" by a crashed worker is never retried; it is failed after the timeout.
        $c2 = $this->campaign(['name' => 'Second']);
        $this->subscriber('c@example.test');
        StoreEmailCampaignRecipient::withoutGlobalScopes()->where('campaign_id', $campaign->id)->update(['status' => 'sent']);
        $this->startNow($c2);
        $stuck = $this->recipients($c2)->where('email', 'c@example.test')->first();
        $stuck->forceFill(['status' => 'sending', 'sending_at' => now()->subHour()])->save();
        app()->call([new SendStoreEmailCampaignBatch($c2->id, [$stuck->id]), 'handle']);
        Mail::assertNotSent(StoreEmailCampaignMail::class, fn ($mail) => $mail->hasTo('c@example.test'));
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame('failed', $stuck->fresh()->status);
    }

    public function test_per_store_and_platform_rate_caps(): void
    {
        Queue::fake();
        Mail::fake();
        config(['sellchase.email_campaigns.per_store_per_minute' => 3, 'sellchase.email_campaigns.batch_size' => 2]);
        foreach (range(1, 7) as $i) {
            $this->subscriber("s{$i}@example.test");
        }
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $this->assertSame(3, $this->recipients($campaign)->whereNotNull('dispatched_at')->count());
        Queue::assertPushed(SendStoreEmailCampaignBatch::class, 2); // 2 + 1

        // Same minute: no further dispatch for this store.
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame(3, $this->recipients($campaign)->whereNotNull('dispatched_at')->count());

        // The job enforces the cap per message too, handing the rest back to the dispatcher.
        $all = $this->recipients($campaign)->orderBy('id')->pluck('id')->all();
        RateLimiter::clear('email-campaigns:store:'.$this->store->id);
        app()->call([new SendStoreEmailCampaignBatch($campaign->id, $all), 'handle']);
        Mail::assertSent(StoreEmailCampaignMail::class, 3);
        $this->assertSame(4, $this->recipients($campaign)->where('status', 'queued')->whereNull('dispatched_at')->count());

        // A minute later the next slice goes out.
        $this->travel(61)->seconds();
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame(3, $this->recipients($campaign)->where('status', 'queued')->whereNotNull('dispatched_at')->count());

        // Platform-wide cap is shared across stores: alpha has one address left, beta two; the cap is 2.
        config(['sellchase.email_campaigns.per_store_per_minute' => 100, 'sellchase.email_campaigns.platform_per_minute' => 2]);
        $this->travel(61)->seconds();
        $this->subscriber('x1@example.test', true, $this->other);
        $this->subscriber('x2@example.test', true, $this->other);
        $otherCampaign = new StoreEmailCampaign;
        $otherCampaign->forceFill(['store_id' => $this->other->id, 'name' => 'B', 'subject' => 'B', 'content' => [['id' => 'h', 'type' => 'heading', 'text' => 'Hi', 'size' => 'large', 'align' => 'start']],
            'audience' => ['type' => 'all'], 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()])->save();
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame(2, StoreEmailCampaignRecipient::withoutGlobalScopes()->where('dispatched_at', '>=', now()->subMinute())->count());
        $this->assertSame(1, StoreEmailCampaignRecipient::withoutGlobalScopes()->where('store_id', $this->other->id)->whereNotNull('dispatched_at')->count());
        $this->assertSame(2, StoreEmailCampaignRecipient::withoutGlobalScopes()->where('store_id', $this->other->id)->count());
    }

    public function test_unsubscribe_link_headers_and_future_exclusion(): void
    {
        config(['mail.default' => 'array']);
        Queue::fake();
        $this->subscriber('mona@example.test');
        $this->subscriber('omar@example.test');
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $this->runQueuedBatches();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $email = $messages[0]->getOriginalMessage();
        $recipient = $this->recipients($campaign)->where('email', $email->getTo()[0]->getAddress())->first();
        $unsubscribe = route('email-campaigns.unsubscribe', ['token' => $recipient->token]);
        $this->assertSame('<'.$unsubscribe.'>', $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString(e($unsubscribe), $email->getHtmlBody());
        $this->assertStringContainsString($unsubscribe, $email->getTextBody());
        $this->assertSame('Summer sale is here', $email->getSubject());

        // GET shows a confirmation page (scanners prefetch links) and changes nothing.
        $this->get('/api/v1/email/unsubscribe/'.$recipient->token)->assertOk()->assertSee('Unsubscribe from marketing emails');
        $this->assertNull($recipient->fresh()->unsubscribed_at);
        // RFC 8058 one-click POST (no login).
        $this->post('/api/v1/email/unsubscribe/'.$recipient->token, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('You have been unsubscribed');
        $this->assertSame('unsubscribed', $recipient->fresh()->status);
        $this->assertFalse((bool) NewsletterSubscriber::withoutGlobalScopes()->where('store_id', $this->store->id)->where('email', $recipient->email)->value('is_active'));
        $this->post('/api/v1/email/unsubscribe/'.$recipient->token)->assertOk(); // idempotent
        $this->get('/api/v1/email/unsubscribe/'.Str::random(48))->assertNotFound();

        // Excluded from the next campaign even after re-subscribing.
        NewsletterSubscriber::withoutGlobalScopes()->where('email', $recipient->email)->update(['is_active' => true]);
        $next = $this->campaign(['name' => 'Next']);
        $this->postJson($this->url($next->id.'/audience-count'), [], $this->auth)->assertOk()->assertJsonPath('data.count', 1);

        $report = $this->getJson($this->url($campaign->id.'/report'), $this->auth)->assertOk()->json('data.metrics');
        $this->assertSame(1, $report['unsubscribed']);
        $this->assertSame(2, $report['sent']);
    }

    public function test_open_and_click_tracking_refuse_unknown_urls(): void
    {
        Queue::fake();
        Mail::fake();
        $this->subscriber('mona@example.test');
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $this->runQueuedBatches();
        $recipient = $this->recipients($campaign)->first();

        $sent = null;
        Mail::assertSent(StoreEmailCampaignMail::class, function (StoreEmailCampaignMail $mail) use (&$sent) {
            $sent = $mail;

            return true;
        });
        $html = $sent->render();
        $this->assertStringContainsString('/api/v1/email/open/'.$recipient->token, $html);
        $this->assertStringNotContainsString('href="https://shop.test/collections/sale"', $html);
        $this->assertStringContainsString(e(EmailCampaignContent::clickUrl($recipient->token, 'https://shop.test/collections/sale')), $html);
        $this->assertStringContainsString(e(EmailCampaignContent::clickUrl($recipient->token, 'https://shop.test/sale?x=1&y=2')), $html);

        // Open pixel.
        $this->get('/api/v1/email/open/'.$recipient->token)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertNotNull($recipient->fresh()->opened_at);
        $this->get('/api/v1/email/open/unknownToken')->assertOk();

        // Click on a link of the content redirects and is recorded.
        $target = 'https://shop.test/sale?x=1&y=2';
        $this->get(EmailCampaignContent::clickUrl($recipient->token, $target))->assertRedirect($target);
        $this->assertSame(1, $recipient->fresh()->clicks_count);
        $this->assertNotNull($recipient->fresh()->clicked_at);

        // Unknown URL — even with a valid signature for it — is refused: no open redirect.
        $this->get(EmailCampaignContent::clickUrl($recipient->token, 'https://evil.test/phish'))->assertNotFound();
        // Tampered signature.
        $this->get('/api/v1/email/click/'.$recipient->token.'?'.http_build_query(['u' => 'https://shop.test/collections/sale', 's' => str_repeat('0', 32)]))->assertNotFound();
        $this->get('/api/v1/email/click/'.$recipient->token.'?u=https://evil.test')->assertNotFound();
        $this->assertSame(1, $recipient->fresh()->clicks_count);

        $report = $this->getJson($this->url($campaign->id.'/report'), $this->auth)->assertOk()->json('data.metrics');
        $this->assertEquals(['recipients' => 1, 'sent' => 1, 'opened' => 1, 'clicked' => 1, 'clicks' => 1, 'unsubscribed' => 0, 'failed' => 0, 'open_rate' => 100, 'click_rate' => 100],
            array_intersect_key($report, array_flip(['recipients', 'sent', 'opened', 'clicked', 'clicks', 'unsubscribed', 'failed', 'open_rate', 'click_rate'])));
        $this->getJson($this->url(), $this->auth)->assertOk()->assertJsonPath('data.0.open_rate', 100);
    }

    public function test_cancel_stops_sending_and_failures_are_reported(): void
    {
        Queue::fake();
        Mail::fake();
        $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $this->postJson($this->url($campaign->id.'/cancel'), [], $this->auth)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->runQueuedBatches();
        Mail::assertNothingSent();
        $this->postJson($this->url($campaign->id.'/cancel'), [], $this->auth)->assertUnprocessable();

        // An address that unsubscribed after the snapshot is skipped at send time.
        $c2 = $this->campaign(['name' => 'Two']);
        $this->startNow($c2);
        NewsletterSubscriber::withoutGlobalScopes()->where('email', 'b@example.test')->update(['is_active' => false]);
        $this->runQueuedBatches();
        $this->assertSame('skipped', $this->recipients($c2)->where('email', 'b@example.test')->value('status'));
        $this->assertSame('sent', $this->recipients($c2)->where('email', 'a@example.test')->value('status'));

        // A permanent SMTP rejection is recorded as a bounce and suppresses the address.
        $c3 = $this->campaign(['name' => 'Three']);
        $this->startNow($c3);
        $recipient = $this->recipients($c3)->first();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('550 mailbox unavailable', 550));
        app()->call([new SendStoreEmailCampaignBatch($c3->id, [$recipient->id]), 'handle']);
        $this->assertSame('bounced', $recipient->fresh()->status);
        $this->assertStringNotContainsString('@', (string) $recipient->fresh()->error);
        $this->assertSame(1, $c3->fresh()->failed_count);
    }

    public function test_store_isolation(): void
    {
        $campaign = $this->campaign();
        $this->getJson($this->url($campaign->id), $this->otherAuth)->assertNotFound();
        $this->putJson($this->url($campaign->id), ['name' => 'Hijack'], $this->otherAuth)->assertNotFound();
        $this->postJson($this->url($campaign->id.'/test'), ['email' => 'x@y.test'], $this->otherAuth)->assertNotFound();
        $this->postJson($this->url($campaign->id.'/schedule'), ['scheduled_at' => null], $this->otherAuth)->assertNotFound();
        $this->getJson($this->url($campaign->id.'/report'), $this->otherAuth)->assertNotFound();
        $this->getJson($this->url(), $this->otherAuth)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url($campaign->id))->assertUnauthorized();
        $this->assertSame('Summer', $campaign->fresh()->name);
    }

    public function test_sent_campaigns_cannot_be_deleted_or_edited(): void
    {
        Queue::fake();
        Mail::fake();
        $this->subscriber('a@example.test');
        $campaign = $this->campaign();
        $this->startNow($campaign);
        $this->runQueuedBatches();
        $this->artisan('email-campaigns:dispatch')->assertSuccessful();
        $this->assertSame('sent', $campaign->fresh()->status);
        $this->deleteJson($this->url($campaign->id), [], $this->auth)->assertUnprocessable();
        $this->putJson($this->url($campaign->id), ['subject' => 'x'], $this->auth)->assertUnprocessable();
        $this->postJson($this->url($campaign->id.'/schedule'), ['scheduled_at' => null], $this->auth)->assertUnprocessable();
    }
}

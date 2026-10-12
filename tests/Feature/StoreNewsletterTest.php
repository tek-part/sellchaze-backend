<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use App\Models\PhoneNewsletterSubscriber;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StoreNewsletterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $public = 'http://newsletter.sellchase.com/api/v1/storefront/newsletter';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Newsletter', 'slug' => 'newsletter', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'newsletter.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    public function test_phone_opt_in_is_canonical_idempotent_without_overwriting_identity_or_sending_messages(): void
    {
        $this->postJson($this->public, ['name' => 'Original', 'phone' => '01001234567'])->assertCreated()->assertExactJson(['message' => 'Subscribed.']);
        $this->postJson($this->public, ['name' => 'Replacement', 'phone' => '+20 100 123 4567'])->assertCreated();
        $this->postJson($this->public, ['name' => 'Replacement', 'phone' => '٠١٠٠١٢٣٤٥٦٧'])->assertCreated();
        $this->assertDatabaseCount('phone_newsletter_subscribers', 1);
        $this->assertDatabaseHas('phone_newsletter_subscribers', ['name' => 'Original', 'phone_normalized' => '+201001234567', 'store_id' => $this->store->id]);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_email_opt_in_remains_compatible_and_phone_channel_has_required_fields(): void
    {
        $this->postJson($this->public, ['email' => 'Reader@Example.test'])->assertCreated();
        $this->postJson($this->public, ['email' => 'reader@example.test'])->assertCreated();
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'reader@example.test', 'store_id' => $this->store->id]);
        foreach ([[], ['phone' => '01001234567'], ['name' => 'Reader', 'phone' => '123'], ['name' => 'Reader', 'phone' => '01001234567', 'email' => 'reader@example.test']] as $payload) {
            $this->postJson($this->public, $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('phone_newsletter_subscribers', 0);
    }

    public function test_store_country_controls_phone_normalization_and_foreign_payload_store_id_is_ignored(): void
    {
        $this->store->update(['order_limits' => ['phone_country' => 'GB']]);
        $this->postJson($this->public, ['name' => 'Reader', 'phone' => '07123456789', 'store_id' => 999])->assertCreated();
        $this->assertDatabaseHas('phone_newsletter_subscribers', ['store_id' => $this->store->id, 'phone_normalized' => '+447123456789']);
    }

    public function test_merchant_listing_unifies_channels_but_never_leaks_another_store(): void
    {
        $this->postJson($this->public, ['name' => 'Reader', 'phone' => '01001234567'])->assertCreated();
        $this->postJson($this->public, ['email' => 'reader@example.test'])->assertCreated();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-newsletter', 'currency' => 'EGP', 'status' => 'active']);
        NewsletterSubscriber::withoutGlobalScopes()->create(['store_id' => $other->id, 'email' => 'secret@example.test']);
        PhoneNewsletterSubscriber::withoutGlobalScopes()->create(['store_id' => $other->id, 'name' => 'Secret', 'phone' => '01001234568', 'phone_normalized' => '+201001234568']);
        $response = $this->getJson('/api/v1/my-store/newsletter', $this->auth)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertStringNotContainsString('secret@example.test', $response->getContent());
        $this->getJson('/api/v1/stores/'.$other->id.'/newsletter', $this->auth)->assertForbidden();
        $this->getJson('/api/v1/my-store/newsletter')->assertUnauthorized();
        $this->getJson('http://newsletter.sellchase.com/api/v1/storefront/newsletter')->assertNotFound();
    }

    public function test_filters_are_literal_validated_and_paginated_across_channels(): void
    {
        $this->postJson($this->public, ['name' => 'Reader%_!', 'phone' => '01001234567'])->assertCreated();
        $this->postJson($this->public, ['email' => 'reader@example.test'])->assertCreated();
        $this->getJson('/api/v1/my-store/newsletter?channel=phone&search=%25_!', $this->auth)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Reader%_!');
        $this->getJson('/api/v1/my-store/newsletter?per_page=1&page=2', $this->auth)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/my-store/newsletter?channel=email', $this->auth)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'reader@example.test');
        $this->getJson('/api/v1/my-store/newsletter?channel=sms', $this->auth)->assertUnprocessable();
        $this->getJson('/api/v1/my-store/newsletter?per_page=101', $this->auth)->assertUnprocessable();
    }

    public function test_phone_migration_refuses_to_discard_saved_subscribers(): void
    {
        $this->postJson($this->public, ['name' => 'Reader', 'phone' => '01001234567'])->assertCreated();
        $migration = require database_path('migrations/2026_10_12_010000_create_phone_newsletter_subscribers.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve populated contacts');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('preserve', $error->getMessage());
        }
        $this->assertSame(1, DB::table('phone_newsletter_subscribers')->count());
    }
}

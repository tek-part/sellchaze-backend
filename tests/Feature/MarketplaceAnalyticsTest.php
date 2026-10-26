<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $this->owner = $this->merchant();
        $this->viewer = $this->merchant();
    }

    private function merchant(): User
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');

        return $user;
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user), 'Accept' => 'application/json'];
    }

    private function publish(User $owner, bool $contactsPublic = true): array
    {
        $this->putJson('/api/v1/me/service-marketplace/provider', ['display_name' => 'Analytics provider', 'contacts_public' => $contactsPublic, 'published' => true,
            'contacts' => ['email' => null, 'phone' => null, 'whatsapp' => '+201001234567', 'telegram' => null]], $this->auth($owner))->assertOk();

        return $this->postJson('/api/v1/me/service-marketplace/services', ['request_key' => (string) Str::uuid(), 'title' => 'Landing page build', 'description' => 'Details',
            'category' => 'landing-pages', 'price_minor' => 50000, 'currency' => 'EGP', 'images' => [], 'published' => true], $this->auth($owner))->assertCreated()->json('data');
    }

    private function report(User $user, string $query)
    {
        return $this->getJson('/api/v1/me/service-marketplace/analytics?'.$query, $this->auth($user));
    }

    public function test_views_and_contact_clicks_count_once_per_visitor_per_day_and_never_count_the_provider(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $service = $this->publish($this->owner);
        $provider = $service['provider']['id'];
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk();
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk();
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->owner))->assertOk();
        $this->getJson('/api/v1/service-marketplace/providers/'.$provider, $this->auth($this->viewer))->assertOk();
        $this->postJson('/api/v1/service-marketplace/providers/'.$provider.'/contact-clicks', ['channel' => 'whatsapp', 'service_id' => $service['id']], $this->auth($this->viewer))->assertStatus(202);
        $this->postJson('/api/v1/service-marketplace/providers/'.$provider.'/contact-clicks', ['channel' => 'whatsapp', 'service_id' => $service['id']], $this->auth($this->viewer))->assertStatus(202);
        // The same channel opened from the profile page is a different target and counts on its own.
        $this->postJson('/api/v1/service-marketplace/providers/'.$provider.'/contact-clicks', ['channel' => 'whatsapp'], $this->auth($this->viewer))->assertStatus(202);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
        $second = $this->merchant();
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk();
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($second))->assertOk();

        $this->assertFalse(Schema::hasColumn('marketplace_engagements', 'user_id'));
        $this->assertNotContains((string) $this->viewer->id, DB::table('marketplace_engagements')->pluck('viewer_hash')->all());
        $this->report($this->owner, 'from=2026-10-20&to=2026-10-21')->assertOk()
            ->assertJsonPath('data.summary', ['service_views' => 3, 'profile_views' => 1, 'contact_clicks' => 2, 'unique_visitors' => 2])
            ->assertJsonPath('data.contact_channels.whatsapp', 2)->assertJsonPath('data.daily.0.service_views', 1)->assertJsonPath('data.daily.1.service_views', 2)
            ->assertJsonPath('data.services.0.title', 'Landing page build')->assertJsonPath('data.services.0.views', 3)->assertJsonPath('data.services.0.contact_clicks', 1);
        $this->report($this->owner, 'from=2026-10-21&to=2026-10-21')->assertOk()->assertJsonPath('data.summary.service_views', 2)->assertJsonCount(1, 'data.daily');
    }

    public function test_ranges_follow_the_requested_timezone_and_are_validated(): void
    {
        // 22:30 UTC on 20 Oct is 21 Oct in Cairo.
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(22, 30));
        $service = $this->publish($this->owner);
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk();
        $this->report($this->owner, 'from=2026-10-21&to=2026-10-21&timezone=Africa/Cairo')->assertOk()->assertJsonPath('data.summary.service_views', 1);
        $this->report($this->owner, 'from=2026-10-21&to=2026-10-21&timezone=UTC')->assertOk()->assertJsonPath('data.summary.service_views', 0);
        $this->report($this->owner, 'from=2026-10-21&to=2026-10-20')->assertUnprocessable();
        $this->report($this->owner, 'from=2025-01-01&to=2026-10-20')->assertUnprocessable();
        $this->report($this->owner, 'from=2026-10-20&to=2026-10-20&timezone=Mars/Base')->assertUnprocessable();
    }

    public function test_reports_are_private_to_their_provider_and_contact_clicks_need_published_channels(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $service = $this->publish($this->owner);
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk();
        // The viewer has no provider profile: their report is empty, never another provider's numbers.
        $this->report($this->viewer, 'from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('data', null);
        $rival = $this->merchant();
        $rivalService = $this->publish($rival, false);
        $this->report($rival, 'from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('data.summary.service_views', 0)->assertJsonCount(0, 'data.services');
        $this->postJson('/api/v1/service-marketplace/providers/'.$rivalService['provider']['id'].'/contact-clicks', ['channel' => 'whatsapp'], $this->auth($this->viewer))->assertNotFound();
        $this->postJson('/api/v1/service-marketplace/providers/'.$service['provider']['id'].'/contact-clicks', ['channel' => 'email'], $this->auth($this->viewer))->assertUnprocessable();
        $this->postJson('/api/v1/service-marketplace/providers/'.$service['provider']['id'].'/contact-clicks', ['channel' => 'whatsapp', 'service_id' => $rivalService['id']], $this->auth($this->viewer))->assertNotFound();
        $this->postJson('/api/v1/service-marketplace/providers/'.$service['provider']['id'].'/contact-clicks', ['channel' => 'whatsapp'])->assertUnauthorized();
        $this->getJson('/api/v1/me/service-marketplace/analytics?from=2026-10-20&to=2026-10-20')->assertUnauthorized();
        $customer = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->report($customer, 'from=2026-10-20&to=2026-10-20')->assertForbidden();
        $this->assertSame(0, DB::table('marketplace_engagements')->where('kind', 'contact_click')->count());

        $migration = require database_path('migrations/2026_10_30_040002_create_marketplace_engagements.php');
        try {
            $migration->down();
            $this->fail('Recorded engagement must survive rollback');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve', $error->getMessage());
        }
        $this->assertTrue(Schema::hasTable('marketplace_engagements'));
    }

    public function test_contact_clicks_count_per_service_and_recording_never_breaks_pages_or_ignores_privacy_signals(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $first = $this->publish($this->owner);
        $second = $this->postJson('/api/v1/me/service-marketplace/services', ['request_key' => (string) Str::uuid(), 'title' => 'Store setup', 'description' => 'Details',
            'category' => 'store-setup', 'price_minor' => 1000, 'currency' => 'EGP', 'images' => [], 'published' => true], $this->auth($this->owner))->assertCreated()->json('data');
        $provider = $first['provider']['id'];
        foreach ([$first['id'], $second['id']] as $service) {
            $this->postJson('/api/v1/service-marketplace/providers/'.$provider.'/contact-clicks', ['channel' => 'whatsapp', 'service_id' => $service], $this->auth($this->viewer))->assertStatus(202);
        }
        $this->report($this->owner, 'from=2026-10-20&to=2026-10-20')->assertOk()->assertJsonPath('data.summary.contact_clicks', 2)
            ->assertJsonPath('data.services.0.contact_clicks', 1)->assertJsonPath('data.services.1.contact_clicks', 1);

        $other = $this->merchant();
        $this->getJson('/api/v1/service-marketplace/services/'.$first['id'], $this->auth($other) + ['DNT' => '1'])->assertOk();
        $this->getJson('/api/v1/service-marketplace/providers/'.$provider, $this->auth($other) + ['Sec-GPC' => '1'])->assertOk();
        $this->assertSame(0, DB::table('marketplace_engagements')->whereIn('kind', ['service_view', 'profile_view'])->count());

        // A broken analytics table is reported but never turns the public pages into errors.
        Schema::drop('marketplace_engagements');
        $this->getJson('/api/v1/service-marketplace/services/'.$first['id'], $this->auth($other))->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->getJson('/api/v1/service-marketplace/providers/'.$provider, $this->auth($other))->assertOk();
    }
}

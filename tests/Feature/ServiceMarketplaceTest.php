<?php

namespace Tests\Feature;

use App\Models\MarketplaceService;
use App\Models\MarketplaceServiceProvider;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceMarketplaceTest extends TestCase
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
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->viewer = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->viewer->assignRole('Merchant');
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($user), 'Accept' => 'application/json'];
    }

    private function providerBody(array $change = []): array
    {
        return $change + ['display_name' => 'Local service provider', 'bio' => 'Named local marketplace review', 'country' => 'Egypt',
            'contacts_public' => false, 'published' => true, 'contacts' => ['email' => 'provider@example.test', 'phone' => '+201001234567', 'whatsapp' => '+201001234567', 'telegram' => 'local_fixture']];
    }

    private function publish(array $change = []): array
    {
        if (! MarketplaceServiceProvider::where('user_id', $this->owner->id)->exists()) {
            $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(), $this->auth($this->owner))->assertOk();
        }
        $body = $change + ['request_key' => (string) Str::uuid(), 'title' => 'Named local design service', 'description' => 'Design service details', 'category' => 'design', 'price_minor' => 55000, 'currency' => 'EGP', 'images' => [], 'published' => true];

        return $this->postJson('/api/v1/me/service-marketplace/services', $body, $this->auth($this->owner))->assertCreated()->json('data');
    }

    public function test_reading_an_empty_marketplace_does_not_publish_profiles_or_expose_existing_user_contacts(): void
    {
        $this->getJson('/api/v1/service-marketplace', $this->auth($this->viewer))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(10, 'categories')->assertJsonMissing(['email' => $this->owner->email]);
        $this->getJson('/api/v1/me/service-marketplace', $this->auth($this->owner))->assertOk()->assertJsonPath('provider', null);
        $this->assertDatabaseCount('marketplace_service_providers', 0);
        $this->getJson('/api/v1/service-marketplace')->assertUnauthorized();
    }

    public function test_contacts_require_explicit_publisher_opt_in_and_private_profiles_never_list(): void
    {
        $service = $this->publish();
        $id = $service['provider']['id'];
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertOk()->assertJsonPath('data.provider.contacts', null)->assertJsonMissing(['email' => 'provider@example.test']);
        $this->getJson('/api/v1/me/service-marketplace', $this->auth($this->owner))->assertOk()->assertJsonPath('provider.contacts.email', 'provider@example.test');
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 1, 'contacts_public' => true]), $this->auth($this->owner))->assertOk();
        $this->getJson('/api/v1/service-marketplace/providers/'.$id, $this->auth($this->viewer))->assertOk()->assertJsonPath('data.contacts.email', 'provider@example.test');
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 2, 'contacts_public' => false, 'published' => false]), $this->auth($this->owner))->assertOk();
        $this->getJson('/api/v1/service-marketplace', $this->auth($this->viewer))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertNotFound();
        $this->getJson('/api/v1/service-marketplace/providers/'.$id, $this->auth($this->viewer))->assertNotFound();
    }

    public function test_publishing_is_owned_versioned_and_replayed_without_duplicate_services(): void
    {
        $key = (string) Str::uuid();
        $service = $this->publish(['request_key' => $key]);
        $this->publish(['request_key' => $key]);
        $this->assertDatabaseCount('marketplace_services', 1);
        $body = ['version' => 1, 'title' => 'Updated local service', 'description' => 'Updated details', 'category' => 'marketing', 'price_minor' => 60000, 'currency' => 'EGP', 'published' => true, 'images' => []];
        $this->putJson('/api/v1/me/service-marketplace/services/'.$service['id'], $body, $this->auth($this->viewer))->assertNotFound();
        $this->putJson('/api/v1/me/service-marketplace/services/'.$service['id'], $body, $this->auth($this->owner))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/me/service-marketplace/services/'.$service['id'], $body, $this->auth($this->owner))->assertConflict();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 9]), $this->auth($this->owner))->assertConflict();
        $employee = User::factory()->create(['pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(), $this->auth($employee))->assertForbidden();
        $this->assertDatabaseHas('marketplace_services', ['id' => $service['id'], 'title' => 'Updated local service', 'version' => 2]);
    }

    public function test_rating_retries_and_edits_keep_one_vote_and_accurate_aggregates(): void
    {
        $service = $this->publish();
        $url = '/api/v1/service-marketplace/services/'.$service['id'].'/rating';
        $this->putJson($url, ['rating' => 5], $this->auth($this->owner))->assertUnprocessable();
        $this->putJson($url, ['rating' => 5], $this->auth($this->viewer))->assertOk()->assertJsonPath('data.rating', '5.00')->assertJsonPath('data.ratings_count', 1);
        $this->putJson($url, ['rating' => 5], $this->auth($this->viewer))->assertOk()->assertJsonPath('data.ratings_count', 1);
        $this->putJson($url, ['rating' => 3], $this->auth($this->viewer))->assertOk()->assertJsonPath('data.rating', '3.00')->assertJsonPath('my_rating', 3);
        $other = User::factory()->create(['pending_approval' => false]);
        $this->putJson($url, ['rating' => 4], $this->auth($other))->assertOk()->assertJsonPath('data.rating', '3.50')->assertJsonPath('data.ratings_count', 2);
        $this->assertDatabaseCount('marketplace_service_ratings', 2);
        $this->assertDatabaseHas('marketplace_services', ['id' => $service['id'], 'ratings_sum' => 7]);
    }

    public function test_marketplace_filters_stable_pages_and_only_compares_prices_in_the_same_currency(): void
    {
        $a = $this->publish(['title' => 'A local service', 'price_minor' => 10000]);
        $b = $this->publish(['title' => 'B local service', 'price_minor' => 20000]);
        $this->publish(['title' => 'USD service', 'currency' => 'USD', 'price_minor' => 200]);
        $this->publish(['title' => 'Private draft service', 'published' => false]);
        $this->putJson('/api/v1/service-marketplace/services/'.$b['id'].'/rating', ['rating' => 4], $this->auth($this->viewer))->assertOk();
        $this->getJson('/api/v1/service-marketplace?sort=rating&per_page=1', $this->auth($this->viewer))->assertOk()->assertJsonPath('data.0.id', $b['id'])->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/service-marketplace?sort=price_asc', $this->auth($this->viewer))->assertOk()->assertJsonPath('data.0.id', $a['id'])->assertJsonPath('meta.total', 2)->assertJsonPath('meta.currency_filter', 'EGP');
        $this->getJson('/api/v1/service-marketplace?sort=price_desc&currency=USD', $this->auth($this->viewer))->assertOk()->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/service-marketplace?category=video', $this->auth($this->viewer))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/service-marketplace?sort=ratings_count;DROP%20TABLE', $this->auth($this->viewer))->assertUnprocessable();
    }

    public function test_image_upload_is_owned_and_only_published_service_images_are_public(): void
    {
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(), $this->auth($this->owner))->assertOk();
        $image = $this->post('/api/v1/me/service-marketplace/images', ['image' => UploadedFile::fake()->image('named-local-service.png')], $this->auth($this->owner))->assertCreated()->json('path');
        $provider = MarketplaceServiceProvider::where('user_id', $this->owner->id)->firstOrFail();
        $media = '/api/v1/service-marketplace/media/'.$provider->id.'/'.basename($image);
        $this->get($media)->assertNotFound();
        $service = $this->publish(['images' => [$image]]);
        $this->assertSame($media, $service['images'][0]);
        $this->get($media)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->publish(['published' => false]);
        $this->deleteJson('/api/v1/me/service-marketplace/services/'.$service['id'], ['version' => 1], $this->auth($this->owner))->assertOk();
        $this->get($media)->assertNotFound();
        $this->post('/api/v1/me/service-marketplace/images', ['image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], $this->auth($this->owner))->assertUnprocessable();
        Storage::disk('local')->assertExists($image);
    }

    public function test_archiving_and_deactivated_providers_keep_history_but_remove_public_visibility(): void
    {
        $service = $this->publish();
        $this->putJson('/api/v1/service-marketplace/services/'.$service['id'].'/rating', ['rating' => 5], $this->auth($this->viewer))->assertOk();
        $this->owner->update(['is_active' => false]);
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertNotFound();
        $this->owner->update(['is_active' => true]);
        $this->deleteJson('/api/v1/me/service-marketplace/services/'.$service['id'], ['version' => 1], $this->auth($this->owner))->assertOk();
        $this->getJson('/api/v1/service-marketplace/services/'.$service['id'], $this->auth($this->viewer))->assertNotFound();
        $this->assertSoftDeleted('marketplace_services', ['id' => $service['id']]);
        $this->assertDatabaseCount('marketplace_service_ratings', 1);
        $migration = require database_path('migrations/2026_10_12_120000_create_service_marketplace.php');
        try {
            $migration->down();
            $this->fail('Populated marketplace cannot reverse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }
        $this->assertSame(1, (int) DB::table('marketplace_service_ratings')->count());
        $this->assertTrue(MarketplaceService::withTrashed()->whereKey($service['id'])->exists());
    }

    public function test_portfolio_images_are_owned_and_only_published_profiles_expose_them(): void
    {
        $this->publish();
        $image = $this->post('/api/v1/me/service-marketplace/images', ['image' => UploadedFile::fake()->image('portfolio.png')], $this->auth($this->owner))->assertCreated()->json('path');
        $provider = MarketplaceServiceProvider::where('user_id', $this->owner->id)->firstOrFail();
        $work = ['id' => (string) Str::uuid(), 'title' => 'Named local work', 'description' => 'Synthetic portfolio only', 'url' => 'https://example.test/work', 'images' => [$image]];
        $body = $this->providerBody(['version' => 1, 'headline' => 'Designer', 'skills' => ['Design', 'Development'], 'portfolio' => [$work]]);
        $this->putJson('/api/v1/me/service-marketplace/provider', array_replace($body, ['portfolio' => [array_replace($work, ['url' => 'javascript:alert(1)'])]]), $this->auth($this->owner))->assertUnprocessable();
        $this->putJson('/api/v1/me/service-marketplace/provider', $body, $this->auth($this->owner))->assertOk();
        $this->getJson('/api/v1/service-marketplace/providers/'.$provider->id, $this->auth($this->viewer))->assertOk()->assertJsonPath('data.skills.0', 'Design')->assertJsonPath('data.portfolio.0.image_paths', [])->assertJsonPath('data.headline', 'Designer');
        $public = '/api/v1/service-marketplace/media/'.$provider->id.'/'.basename($image);
        $this->get($public)->assertOk();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['portfolio' => [$work]]), $this->auth($this->viewer))->assertUnprocessable();
        $this->putJson('/api/v1/me/service-marketplace/provider', array_replace($body, ['version' => 2, 'published' => false]), $this->auth($this->owner))->assertOk();
        $this->get($public)->assertNotFound();
        Storage::disk('local')->assertExists($image);
        $textOnly = array_replace($body, ['version' => 3, 'portfolio' => [array_replace($work, ['images' => []])]]);
        $this->putJson('/api/v1/me/service-marketplace/provider', $textOnly, $this->auth($this->owner))->assertOk()->assertJsonPath('data.portfolio.0.images', []);
    }

    public function test_private_image_preview_and_avatar_publication_follow_provider_ownership_and_visibility(): void
    {
        $this->publish();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(), $this->auth($this->viewer))->assertOk();
        $image = $this->post('/api/v1/me/service-marketplace/images', ['image' => UploadedFile::fake()->image('avatar.png')], $this->auth($this->owner))->assertCreated()->json('path');
        $file = basename($image);
        $preview = '/api/v1/me/service-marketplace/media/'.$file;
        $this->get($preview, $this->auth($this->viewer))->assertNotFound();
        $previewResponse = $this->get($preview, $this->auth($this->owner))->assertOk();
        $this->assertStringContainsString('no-store', $previewResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $previewResponse->headers->get('Cache-Control'));
        $provider = MarketplaceServiceProvider::where('user_id', $this->owner->id)->firstOrFail();
        $public = '/api/v1/service-marketplace/media/'.$provider->id.'/'.$file;
        $this->get($public)->assertNotFound();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 1, 'avatar' => $image]), $this->auth($this->viewer))->assertUnprocessable();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 1, 'avatar' => $image]), $this->auth($this->owner))->assertOk();
        $this->get($public)->assertOk();
        $this->putJson('/api/v1/me/service-marketplace/provider', $this->providerBody(['version' => 2, 'published' => false]), $this->auth($this->owner))->assertOk();
        $this->get($public)->assertNotFound();
        $this->get($preview, $this->auth($this->owner))->assertOk();
    }
}

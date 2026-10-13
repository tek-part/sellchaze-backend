<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\Commerce\MerchantGeneralSettings;
use App\Services\Commerce\StoreSocialLinks;
use App\Services\JwtTokenService;
use App\Services\Storefront\StoreSeoService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorePresentationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($owner));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->store = $owner->fresh()->store;
    }

    private function seo(array $changes = []): array
    {
        return array_replace(['revision' => app(MerchantGeneralSettings::class)->revision($this->store->fresh()), 'site_title' => 'Review store', 'description' => 'A merchant description'], $changes);
    }

    private function social(array $changes = []): array
    {
        return array_replace(app(StoreSocialLinks::class)->configuration($this->store->fresh()), $changes);
    }

    public function test_reads_are_private_noop_and_seo_only_updates_identity_fields(): void
    {
        $this->store->update(['currency' => 'EGP', 'font_family' => 'Legacy custom font', 'shipping_enabled' => false, 'shipping_flat_rate' => '70.00', 'theme_settings' => ['kept' => true], 'order_limits' => ['minimum_order_amount' => '45.00']]);
        $before = $this->store->fresh()->getRawOriginal();
        $this->getJson('/api/v1/my-store/seo-settings')->assertOk()->assertJsonMissingPath('data.bot_protection_credentials');
        $this->getJson('/api/v1/my-store/social-links')->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->putJson('/api/v1/my-store/seo-settings', $this->seo(['site_title' => 'متجر جديد', 'description' => 'وصف المتجر', 'currency' => 'XYZ', 'shipping_flat_rate' => '1000', 'disable_bot_protection' => true]))->assertOk();
        $saved = $this->store->fresh();
        foreach (['currency', 'font_family', 'shipping_enabled', 'shipping_flat_rate', 'theme_settings', 'order_limits'] as $field) {
            $this->assertSame($before[$field], $saved->getRawOriginal($field));
        }
        $metadata = app(StoreSeoService::class)->forStore($saved);
        $this->assertSame('متجر جديد', $metadata['title']);
        $this->assertSame('وصف المتجر', $metadata['description']);
        $this->assertSame('متجر جديد', $metadata['og']['og:title']);
    }

    public function test_seo_rejects_stale_identity_or_description_before_staging_images(): void
    {
        $stale = $this->seo(['logo' => UploadedFile::fake()->image('logo.png')]);
        $this->store->update(['description' => 'Edited elsewhere']);
        $this->postJson('/api/v1/my-store/seo-settings', ['_method' => 'PUT', ...$stale])->assertConflict();
        $this->assertSame('Edited elsewhere', $this->store->fresh()->description);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->putJson('/api/v1/my-store/seo-settings', $this->seo(['description' => str_repeat('x', 5001)]))->assertUnprocessable();
    }

    public function test_seo_media_failure_is_atomic_and_valid_favicon_becomes_public_metadata(): void
    {
        $path = 'stores/'.$this->store->id.'/identity/old.png';
        Storage::disk('public')->put($path, 'old');
        $this->store->update(['favicon' => $path]);
        $before = $this->store->fresh()->getRawOriginal();
        DB::unprepared("CREATE TRIGGER fail_seo_save BEFORE UPDATE ON stores WHEN NEW.site_title = 'FAIL_SEO' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->postJson('/api/v1/my-store/seo-settings', ['_method' => 'PUT', ...$this->seo(['site_title' => 'FAIL_SEO', 'favicon' => UploadedFile::fake()->image('new.png', 32, 32)])])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_seo_save');
        }
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->assertSame([$path], Storage::disk('public')->allFiles());
        $this->postJson('/api/v1/my-store/seo-settings', ['_method' => 'PUT', ...$this->seo(['favicon' => UploadedFile::fake()->image('icon.png', 32, 32)])])->assertOk();
        $saved = $this->store->fresh();
        Storage::disk('public')->assertExists($saved->favicon);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringContainsString('/storage/stores/'.$saved->id.'/identity/', app(StoreSeoService::class)->forStore($saved)['favicon_url']);
    }

    public function test_social_links_publish_only_when_enabled_and_keep_all_seven_networks(): void
    {
        $links = array_fill_keys(array_keys(StoreSocialLinks::LABELS), '');
        $links['instagram'] = 'https://www.instagram.com/review-store/';
        $links['youtube'] = 'https://www.youtube.com/@review-store';
        $payload = $this->social(['links' => $links]);
        $this->putJson('/api/v1/my-store/social-links', $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame([], app(StoreSocialLinks::class)->publicLinks($this->store->fresh()));
        $this->putJson('/api/v1/my-store/social-links', $this->social(['enabled' => true]))->assertOk();
        $this->assertSame([['label' => 'Instagram', 'url' => $links['instagram']], ['label' => 'YouTube', 'url' => $links['youtube']]], app(StoreSocialLinks::class)->publicLinks($this->store->fresh()));
        $this->assertArrayNotHasKey('social_links_configuration', $this->store->fresh()->toArray());
        $host = $this->store->primaryDomain()->first()->host;
        $this->store->update(['status' => 'active']);
        $this->getJson("http://{$host}/api/v1/storefront")->assertOk()->assertJsonPath('store.identity.social.0.url', $links['instagram']);
        $this->putJson('/api/v1/my-store/social-links', $this->social(['enabled' => false]))->assertOk();
        $this->getJson("http://{$host}/api/v1/storefront")->assertOk()->assertJsonPath('store.identity.social', []);
        $this->assertCount(7, $this->store->fresh()->social_links_configuration['links']);
    }

    public function test_social_links_reject_unsafe_urls_unknown_keys_and_stale_versions(): void
    {
        foreach (['javascript:alert(1)', 'https://user:secret@example.test/path', 'data:text/html,test', 'https://example.test/a b', ['nested']] as $url) {
            $data = $this->social();
            $data['links']['facebook'] = $url;
            $this->putJson('/api/v1/my-store/social-links', $data)->assertUnprocessable()->assertJsonValidationErrors('links.facebook');
        }
        $data = $this->social();
        $data['links']['unknown'] = 'https://example.test/';
        $this->putJson('/api/v1/my-store/social-links', $data)->assertUnprocessable()->assertJsonValidationErrors('links');
        $stale = $this->social();
        $this->putJson('/api/v1/my-store/social-links', $this->social(['enabled' => true]))->assertOk();
        $this->putJson('/api/v1/my-store/social-links', $stale)->assertConflict();
        $this->assertTrue($this->store->fresh()->social_links_configuration['enabled']);
    }

    public function test_foreign_merchant_cannot_read_or_edit_settings_and_populated_rollback_refuses_loss(): void
    {
        $this->store->update(['social_links_configuration' => ['version' => 1, 'enabled' => false, 'links' => []]]);
        $migration = require database_path('migrations/2026_10_13_030000_add_social_links_configuration_to_stores.php');
        try {
            $migration->down();
            $this->fail('Saved links must prevent destructive rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Saved social links must be preserved.', $error->getMessage());
        }
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        foreach (['seo-settings', 'social-links'] as $endpoint) {
            $this->getJson('/api/v1/stores/'.$this->store->id.'/'.$endpoint)->assertNotFound();
            $this->putJson('/api/v1/stores/'.$this->store->id.'/'.$endpoint, [])->assertNotFound();
        }
    }
}

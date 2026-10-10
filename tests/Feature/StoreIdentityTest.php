<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\SpaShell;
use App\Services\Storefront\StoreSeoService;
use Database\Seeders\CurrencyRatesSeeder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class, CurrencyRatesSeeder::class]);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->store = $this->owner->fresh()->store;
    }

    public function test_identity_is_saved_and_published_without_changing_theme_settings(): void
    {
        $this->store->update(['status' => 'active', 'theme_settings' => ['kept' => 'value']]);
        $host = $this->store->primaryDomain()->first()->host;
        $this->getJson("http://{$host}/api/v1/storefront")->assertOk();
        $this->putJson('/api/v1/my-store', ['name' => 'Fresh store', 'site_title' => 'متجر جديد', 'header_mode' => 'custom',
            'header_text' => 'توصيل إلى كل المحافظات', 'primary_color' => '#4bde1c', 'font_family' => 'Readex Pro'])
            ->assertOk()->assertJsonPath('data.identity.site_title', 'متجر جديد')
            ->assertJsonPath('data.identity.font_family', 'Readex Pro');
        $this->getJson("http://{$host}/api/v1/storefront")->assertOk()
            ->assertJsonPath('store.identity.primary_color', '#4bde1c')
            ->assertJsonPath('store.identity.header_text', 'توصيل إلى كل المحافظات')
            ->assertJsonPath('seo.title', 'متجر جديد')
            ->assertJsonPath('homepage.hero.title', 'Fresh store');
        $this->assertSame(['kept' => 'value'], $this->store->fresh()->theme_settings);
        $this->putJson('/api/v1/my-store', ['email' => 'owner@example.com'])->assertOk()->assertJsonPath('data.identity.site_title', 'متجر جديد');
    }

    public function test_nulls_restore_defaults_and_empty_custom_header_remains_deliberately_hidden(): void
    {
        $this->putJson('/api/v1/my-store', ['header_mode' => 'custom', 'header_text' => '', 'font_family' => 'Almarai', 'primary_color' => '#123456'])->assertOk()
            ->assertJsonPath('data.identity.header_mode', 'custom')->assertJsonPath('data.identity.header_text', null);
        $this->putJson('/api/v1/my-store', ['header_mode' => 'theme', 'header_text' => null, 'site_title' => null, 'font_family' => null, 'primary_color' => null])->assertOk()
            ->assertJsonPath('data.identity.font_family', null)->assertJsonPath('data.identity.primary_color', null);
        $this->assertSame($this->store->name, app(StoreSeoService::class)->forStore($this->store->fresh())['title']);
    }

    public function test_untrusted_style_values_and_oversized_text_are_rejected_without_partial_save(): void
    {
        $this->putJson('/api/v1/my-store', ['site_title' => str_repeat('x', 256), 'header_text' => str_repeat('x', 501),
            'header_mode' => 'invalid', 'primary_color' => 'url(https://example.com)', 'font_family' => "Cairo';color:red"])
            ->assertUnprocessable()->assertJsonValidationErrors(['site_title', 'header_text', 'header_mode', 'primary_color', 'font_family']);
        $this->assertNull($this->store->fresh()->primary_color);
        $this->putJson('/api/v1/my-store', ['font_family' => 'No Such Sellchaze Font'])->assertUnprocessable();
        $this->putJson('/api/v1/my-store', ['font_family' => 'system'])->assertOk();
    }

    public function test_favicon_upload_is_scoped_and_removal_detaches_without_deleting_cached_assets(): void
    {
        Storage::fake('public');
        $this->postJson('/api/v1/my-store', ['_method' => 'PUT', 'favicon' => UploadedFile::fake()->image('icon.png', 32, 32)])
            ->assertOk()->assertJsonPath('data.identity.favicon_url', fn ($url) => is_string($url) && str_contains($url, "/stores/{$this->store->id}/icons/"));
        $path = $this->store->fresh()->favicon;
        Storage::disk('public')->assertExists($path);
        // Raw path strings can never point the identity at another tenant's upload.
        $this->putJson('/api/v1/my-store', ['favicon' => 'stores/999/icons/icon.png'])->assertUnprocessable();
        $this->putJson('/api/v1/my-store', ['remove_favicon' => true])->assertOk()->assertJsonPath('data.identity.favicon_url', null);
        Storage::disk('public')->assertExists($path);
    }

    public function test_favicon_rejects_scripts_external_urls_wrong_dimensions_and_large_files(): void
    {
        foreach ([UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('icon.png', 16, 32), UploadedFile::fake()->image('icon.png', 513, 513),
            UploadedFile::fake()->image('icon.png', 8, 8), UploadedFile::fake()->image('icon.png', 32, 32)->size(513), 'https://example.com/icon.png'] as $file) {
            $this->postJson('/api/v1/my-store', ['_method' => 'PUT', 'favicon' => $file])->assertUnprocessable()->assertJsonValidationErrors('favicon');
        }
        $this->assertNull($this->store->fresh()->favicon);
    }

    public function test_another_owner_cannot_update_identity(): void
    {
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->putJson("/api/v1/stores/{$this->store->id}", ['site_title' => 'Stolen title'])->assertForbidden();
        $this->getJson('/api/v1/my-store')->assertOk()->assertJsonPath('data.identity.site_title', null);
        $this->assertNull($this->store->fresh()->site_title);
    }

    public function test_shell_replaces_the_build_icon_and_escapes_literal_title_replacement_characters(): void
    {
        $path = sys_get_temp_dir().'/store-identity-'.uniqid().'.html';
        file_put_contents($path, '<html><head><title>Old</title><link rel="icon" href="/icon.png"><link rel="shortcut icon" href="/old.ico"><link rel="stylesheet" href="/assets/app.css"></head><body><div id="storefront-root"></div></body></html>');
        config()->set('sellchase.storefront.spa_shell', $path);
        try {
            $this->store->update(['site_title' => '$1 <script>bad</script>', 'favicon' => 'stores/1/icons/review.png']);
            $html = app(SpaShell::class)->render(['store' => ['identity' => $this->store->identity()], 'seo' => app(StoreSeoService::class)->forStore($this->store)]);
            $this->assertStringContainsString('<title>$1 &lt;script&gt;bad&lt;/script&gt;</title>', $html);
            $this->assertSame(1, substr_count($html, 'rel="icon"'));
            $this->assertStringNotContainsString('old.ico', $html);
            $this->assertStringNotContainsString('href="/icon.png"', $html);
            $this->assertStringContainsString('/assets/app.css', $html);
            $this->assertStringNotContainsString('<script>bad', $html);
        } finally {
            unlink($path);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ThemeSeeder::class); // registers default (1.0.0, 1.1.0) + aurora
    }

    private function auth(): self
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);

        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    public function test_marketplace_lists_published_themes_and_categories(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes')->assertOk();
        $keys = array_column($res->json('data'), 'key');
        $this->assertContains('default', $keys);
        $this->assertContains('aurora', $keys);
        $this->assertContains('modern', $res->json('categories'));
        $this->assertContains('general', $res->json('categories'));
    }

    public function test_featured_filter_returns_featured_themes(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes?filter=featured')->assertOk();
        $keys = array_column($res->json('data'), 'key');
        $this->assertContains('aurora', $keys);       // aurora is featured
        $this->assertNotContains('default', $keys);   // default is not featured
    }

    public function test_category_filter(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes?category=modern')->assertOk();
        $this->assertSame(['aurora'], array_column($res->json('data'), 'key'));
    }

    public function test_theme_detail_exposes_versions_and_compatibility(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes/default')->assertOk();
        $versions = array_column($res->json('versions'), 'version');
        $this->assertContains('1.0.0', $versions);
        $this->assertContains('1.1.0', $versions);
        $this->assertArrayHasKey('min_platform_version', $res->json('versions')[0]);
    }

    public function test_unpublished_theme_is_not_visible(): void
    {
        Theme::where('key', 'aurora')->update(['status' => 'draft']);
        $this->auth()->getJson('/api/v1/marketplace/themes/aurora')->assertNotFound();
    }
}

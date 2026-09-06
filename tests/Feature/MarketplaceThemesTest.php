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

    /** Every first-party storefront theme, as shipped in resources/themes/storefront. */
    private const KEYS = ['bazaar', 'fresh', 'naseem', 'sahra', 'techno'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ThemeSeeder::class); // registers the five first-party storefront themes
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
        sort($keys);
        $this->assertSame(self::KEYS, $keys);
        $this->assertContains('general', $res->json('categories'));
        $this->assertContains('luxury', $res->json('categories'));
        $this->assertContains('electronics', $res->json('categories'));
    }

    public function test_featured_filter_returns_featured_themes(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes?filter=featured')->assertOk();
        $keys = array_column($res->json('data'), 'key');
        $this->assertContains('naseem', $keys);      // featured
        $this->assertContains('sahra', $keys);       // featured
        $this->assertNotContains('techno', $keys);   // not featured
        $this->assertNotContains('fresh', $keys);    // not featured
    }

    public function test_category_filter(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes?category=luxury')->assertOk();
        $this->assertSame(['sahra'], array_column($res->json('data'), 'key'));
    }

    public function test_theme_detail_exposes_versions_and_compatibility(): void
    {
        $res = $this->auth()->getJson('/api/v1/marketplace/themes/naseem')->assertOk();
        $this->assertSame('naseem', $res->json('theme.key'));
        $versions = array_column($res->json('versions'), 'version');
        $this->assertSame(['1.0.0'], $versions);
        $this->assertSame('1.0.0', $res->json('versions.0.min_platform_version'));
        $this->assertContains('rtl', $res->json('versions.0.supported_features'));
    }

    public function test_unpublished_theme_is_not_visible(): void
    {
        Theme::where('key', 'bazaar')->update(['status' => 'draft']);
        $this->auth()->getJson('/api/v1/marketplace/themes/bazaar')->assertNotFound();
        $this->assertNotContains('bazaar', array_column($this->auth()->getJson('/api/v1/marketplace/themes')->json('data'), 'key'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\ThemeCompatibility;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private ThemeCompatibility $compat;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sellchase.platform.version' => '1.0.0']);
        config(['sellchase.platform.features' => ['sections', 'seo', 'ssr', 'settings']]);
        $this->compat = new ThemeCompatibility;
    }

    private function version(array $attrs): ThemeVersion
    {
        $theme = Theme::create(['key' => 'x'.uniqid(), 'name' => 'X', 'status' => 'published']);

        return ThemeVersion::create(array_merge([
            'theme_id' => $theme->id, 'version' => '1.0.0',
            'settings_schema' => [], 'sections_schema' => [], 'templates' => [],
        ], $attrs));
    }

    public function test_min_platform_incompatibility(): void
    {
        $v = $this->version(['min_platform_version' => '2.0.0']);
        $this->assertFalse($this->compat->isCompatible($v));
        $this->assertNotEmpty($this->compat->errors($v));
    }

    public function test_max_platform_incompatibility(): void
    {
        $v = $this->version(['max_platform_version' => '0.9.0']);
        $this->assertFalse($this->compat->isCompatible($v));
    }

    public function test_unsupported_feature_incompatibility(): void
    {
        $v = $this->version(['supported_features' => ['time-travel']]);
        $errors = $this->compat->errors($v);
        $this->assertStringContainsString('unsupported feature: time-travel', implode(' ', $errors));
    }

    public function test_compatible_version_has_no_errors(): void
    {
        $v = $this->version(['min_platform_version' => '1.0.0', 'supported_features' => ['seo']]);
        $this->assertTrue($this->compat->isCompatible($v));
        $this->assertSame([], $this->compat->errors($v));
    }

    public function test_install_rejects_incompatible_version_via_api(): void
    {
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => 'S', 'slug' => 's', 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 's.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);

        $theme = Theme::create(['key' => 'future', 'name' => 'Future', 'status' => 'published']);
        ThemeVersion::create(['theme_id' => $theme->id, 'version' => '1.0.0', 'settings_schema' => [], 'sections_schema' => [], 'templates' => [], 'min_platform_version' => '9.9.9']);

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user))
            ->postJson("/api/v1/stores/{$store->id}/themes/install", ['theme_id' => $theme->id])
            ->assertStatus(422);
    }

    public function test_assert_compatible_throws_and_service_blocks_install(): void
    {
        $v = $this->version(['min_platform_version' => '5.0.0']);
        $this->expectException(\RuntimeException::class);
        $this->compat->assertCompatible($v);
    }
}

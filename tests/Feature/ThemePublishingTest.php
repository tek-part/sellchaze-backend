<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeStatusChange;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Themes\ThemePublishingService;
use App\Services\Themes\ThemeRegistry;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ThemePublishingTest extends TestCase
{
    use RefreshDatabase;

    private ThemePublishingService $publishing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishing = app(ThemePublishingService::class);
    }

    private function draftTheme(): Theme
    {
        return app(ThemeRegistry::class)->register([
            'key' => 'brandx', 'name' => 'BrandX', 'version' => '1.0.0',
            'settings_schema' => [], 'sections_schema' => ['hero' => ['settings' => []], 'product-grid' => ['settings' => []], 'category-header' => ['settings' => []], 'product-details' => ['settings' => []]],
            'templates' => [
                'home' => ['sections' => [['type' => 'hero']]],
                'product' => ['sections' => [['type' => 'product-details']]],
                'category' => ['sections' => [['type' => 'category-header']]],
            ],
        ], 'draft');
    }

    public function test_full_workflow_transitions(): void
    {
        $theme = $this->draftTheme();
        $this->assertSame('review', $this->publishing->transition($theme, 'review')->status);
        $this->assertSame('approved', $this->publishing->transition($theme, 'approved')->status);
        $this->assertSame('published', $this->publishing->transition($theme, 'published')->status);
        $this->assertSame('deprecated', $this->publishing->transition($theme, 'deprecated')->status);

        $this->assertSame(4, ThemeStatusChange::where('theme_id', $theme->id)->count()); // audit trail
    }

    public function test_direct_publish_bypass_is_rejected(): void
    {
        $theme = $this->draftTheme();
        $this->expectException(RuntimeException::class);
        $this->publishing->transition($theme, 'published'); // draft -> published not allowed
    }

    public function test_admin_can_transition_but_non_admin_cannot(): void
    {
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $theme = $this->draftTheme();

        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $merchant = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $merchant->assignRole('Merchant');

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($merchant))
            ->postJson("/api/v1/admin/themes/{$theme->id}/transition", ['to' => 'review'])
            ->assertForbidden();

        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin))
            ->postJson("/api/v1/admin/themes/{$theme->id}/transition", ['to' => 'review'])
            ->assertOk()->assertJsonPath('data.status', 'review');

        // invalid transition -> 422
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin))
            ->postJson("/api/v1/admin/themes/{$theme->id}/transition", ['to' => 'published'])
            ->assertStatus(422);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Storefront\UiBlocks;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreUiBlocksTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $settings = '/api/v1/my-store/ui-blocks';

    private string $public = 'http://blocks.sellchase.com/api/v1/storefront/ui-blocks';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Blocks', 'slug' => 'blocks', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'blocks.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function body(array $changes = []): array
    {
        $blocks = [];
        foreach (UiBlocks::SLOTS as $slot) {
            $blocks[$slot] = ['editor' => 'rich', 'content' => ['ar' => '<p>عربي '.$slot.'</p>', 'en' => '<p>English '.$slot.'</p>']];
        }

        return array_replace(['enabled' => true, 'version' => 1, 'blocks' => $blocks], $changes);
    }

    public function test_defaults_are_empty_and_disabled_drafts_never_reach_public_responses(): void
    {
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
        $this->putJson($this->settings, $this->body(['enabled' => false]), $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $response = $this->getJson($this->public)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.enabled', false)->assertJsonMissingPath('data.version');
        foreach (UiBlocks::SLOTS as $slot) {
            $response->assertJsonPath('data.blocks.'.$slot, '');
            $this->getJson($this->settings, $this->auth)->assertJsonPath('data.blocks.'.$slot.'.content.en', '<p>English '.$slot.'</p>');
        }
    }

    public function test_all_eight_slots_are_localized_and_html_editor_mode_survives_reload(): void
    {
        $body = $this->body();
        $body['blocks']['after_footer']['editor'] = 'html';
        $body['blocks']['before_header']['content']['ar'] = '';
        $this->putJson($this->settings, $body, $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($this->settings, $this->auth)->assertJsonPath('data.blocks.after_footer.editor', 'html');
        $english = $this->getJson($this->public.'?lang=en')->assertOk();
        $arabic = $this->getJson($this->public.'?lang=ar')->assertOk();
        foreach (UiBlocks::SLOTS as $slot) {
            $english->assertJsonPath('data.blocks.'.$slot, '<p>English '.$slot.'</p>');
            $arabic->assertJsonPath('data.blocks.'.$slot, $slot === 'before_header' ? '<p>English '.$slot.'</p>' : '<p>عربي '.$slot.'</p>');
        }
        $english->assertJsonMissingPath('data.blocks.after_footer.editor')->assertJsonMissingPath('data.blocks.after_footer.content');
    }

    public function test_stale_edits_cannot_overwrite_a_disable_and_reactivation_preserves_content(): void
    {
        $this->putJson($this->settings, $this->body(), $this->auth)->assertOk();
        $this->putJson($this->settings, $this->body(['version' => 2, 'enabled' => false]), $this->auth)->assertOk();
        $stale = $this->body(['version' => 2]);
        $stale['blocks']['before_header']['content']['en'] = 'Stale body';
        $this->putJson($this->settings, $stale, $this->auth)->assertStatus(409);
        $this->getJson($this->public.'?lang=en')->assertJsonPath('data.enabled', false)->assertJsonPath('data.blocks.before_header', '');
        $saved = $this->getJson($this->settings, $this->auth)->assertJsonPath('data.version', 3)->assertJsonPath('data.blocks.before_header.content.en', '<p>English before_header</p>')->json('data');
        $saved['enabled'] = true;
        $this->putJson($this->settings, $saved, $this->auth)->assertOk()->assertJsonPath('data.version', 4);
        $this->getJson($this->public.'?lang=en')->assertJsonPath('data.blocks.before_header', '<p>English before_header</p>');
    }

    public function test_unknown_slots_modes_missing_languages_and_oversize_bodies_fail_atomically(): void
    {
        $body = $this->body();
        $body['blocks']['unknown'] = $body['blocks']['before_header'];
        $this->putJson($this->settings, $body, $this->auth)->assertUnprocessable();
        $body = $this->body();
        $body['blocks']['before_header']['editor'] = 'script';
        $this->putJson($this->settings, $body, $this->auth)->assertUnprocessable();
        $body = $this->body();
        unset($body['blocks']['before_header']['content']['en']);
        $this->putJson($this->settings, $body, $this->auth)->assertUnprocessable();
        $body = $this->body();
        $body['blocks']['after_checkout']['content']['en'] = str_repeat('x', 20001);
        $this->putJson($this->settings, $body, $this->auth)->assertUnprocessable();
        $body = $this->body();
        unset($body['blocks']['before_footer']);
        $this->putJson($this->settings, $body, $this->auth)->assertUnprocessable();
        $this->getJson($this->settings, $this->auth)->assertJsonPath('data.version', 1)->assertJsonPath('data.enabled', false);
    }

    public function test_rich_content_is_cleaned_in_both_modes_and_again_at_public_read(): void
    {
        $html = '<h2 style="text-align:center;color:#065f46;position:fixed">Offer</h2><p onclick="bad()"><strong>Bold</strong><script>alert(1)</script><iframe src="https://bad.test"></iframe><a href="javascript:bad()">Unsafe</a><img src="/safe.webp" onerror="bad()"><video src="/safe.mp4" autoplay></video></p>';
        $body = $this->body();
        foreach (['before_header' => 'rich', 'before_footer' => 'html'] as $slot => $editor) {
            $body['blocks'][$slot] = ['editor' => $editor, 'content' => ['ar' => '', 'en' => $html]];
        }
        $this->putJson($this->settings, $body, $this->auth)->assertOk();
        foreach (['before_header', 'before_footer'] as $slot) {
            $clean = $this->getJson($this->public.'?lang=ar')->json('data.blocks.'.$slot);
            foreach (['text-align:center', 'color:#065f46', '<strong>Bold</strong>', '/safe.webp', '/safe.mp4', 'controls'] as $safe) {
                $this->assertStringContainsString($safe, $clean);
            }
            foreach (['onclick', '<script', 'alert(1)', '<iframe', 'javascript:', 'onerror', 'position:', 'autoplay'] as $unsafe) {
                $this->assertStringNotContainsString($unsafe, $clean);
            }
        }
        $config = $this->store->fresh()->ui_blocks_configuration;
        $config['blocks']['before_header']['content']['en'] = $html;
        $this->store->update(['ui_blocks_configuration' => $config]);
        $this->assertStringNotContainsString('<script', $this->getJson($this->public.'?lang=en')->json('data.blocks.before_header'));
    }

    public function test_tenant_scope_and_employee_permissions_guard_read_and_write(): void
    {
        $foreignOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $foreignOwner->assignRole('Merchant');
        $foreign = Store::create(['owner_user_id' => $foreignOwner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other', 'currency' => 'EGP', 'status' => 'active']);
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($foreignOwner)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/ui-blocks', $headers)->assertForbidden();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/ui-blocks', $this->body(), $headers)->assertForbidden();
        $this->putJson('/api/v1/my-store/ui-blocks', $this->body(), $headers)->assertOk();
        $this->getJson($this->public)->assertJsonPath('data.enabled', false);
        $this->assertTrue($foreign->fresh()->ui_blocks_configuration['enabled']);
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, $this->body(), $headers)->assertForbidden();
        $employee->givePermissionTo('store.pages.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->settings, $headers)->assertOk();
        $this->putJson($this->settings, $this->body(), $headers)->assertOk();
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($admin)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/ui-blocks', $headers)->assertOk();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/ui-blocks', $this->body(['version' => 2]), $headers)->assertOk();
    }

    public function test_blade_hooks_wrap_chrome_and_gallery_and_cache_refreshes_after_updates(): void
    {
        config(['sellchase.storefront.spa_shell' => '', 'sellchase.storefront.ssr_url' => '']);
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Bag', 'name_en' => 'Bag', 'name_ar' => 'حقيبة', 'slug' => 'bag', 'sku' => 'BLOCK-BAG', 'price' => 100, 'stock' => 10, 'is_active' => true]);
        $this->putJson($this->settings, $this->body(), $this->auth)->assertOk();
        $html = $this->get('http://blocks.sellchase.com/products/bag?lang=en')->assertOk()->getContent();
        foreach (['before_header', 'after_header', 'before_product_gallery', 'after_product_gallery', 'before_footer', 'after_footer'] as $slot) {
            $this->assertStringContainsString('data-ui-block="'.$slot.'"', $html);
        }
        $this->assertTrue(strpos($html, 'data-ui-block="before_header"') < strpos($html, '<header>'));
        $this->assertTrue(strpos($html, '</header>') < strpos($html, 'data-ui-block="after_header"'));
        $this->assertTrue(strpos($html, 'data-ui-block="before_footer"') < strpos($html, '<footer'));
        $this->assertTrue(strpos($html, '</footer>') < strpos($html, 'data-ui-block="after_footer"'));
        $body = $this->body(['version' => 2]);
        $body['blocks']['before_header']['content']['en'] = '<p>Current content</p>';
        $this->putJson($this->settings, $body, $this->auth)->assertOk();
        $this->get('http://blocks.sellchase.com/products/bag?lang=en')->assertOk()->assertSee('Current content')->assertDontSee('English before_header');
        $this->putJson($this->settings, $this->body(['version' => 3, 'enabled' => false]), $this->auth)->assertOk();
        $this->get('http://blocks.sellchase.com/products/bag?lang=en')->assertOk()->assertDontSee('data-ui-block=', false);
    }
}

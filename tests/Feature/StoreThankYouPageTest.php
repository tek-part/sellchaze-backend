<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\ProductDescription;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreThankYouPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $settings = '/api/v1/my-store/thank-you';

    private string $public = 'http://thanks.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Thanks', 'slug' => 'thanks', 'currency' => 'EGP', 'status' => 'active', 'default_locale' => 'ar', 'supported_locales' => ['ar', 'en']]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'thanks.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function body(array $changes = []): array
    {
        return array_replace(['enabled' => true, 'content' => ['ar' => '<h2>شكرًا لك</h2>', 'en' => '<p>Thank you</p>'], 'show_home_button' => true, 'category_id' => null, 'version' => 1], $changes);
    }

    private function category(array $changes = []): Category
    {
        return Category::create(array_replace(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name_en' => 'Bags', 'name_ar' => 'حقائب', 'slug' => 'bags', 'is_active' => true], $changes));
    }

    public function test_default_is_disabled_and_public_configuration_has_no_private_order_or_draft_content(): void
    {
        $this->getJson($this->settings, $this->auth)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.version', 1);
        $this->putJson($this->settings, $this->body(['enabled' => false]))->assertUnauthorized();
        $this->putJson($this->settings, $this->body(['enabled' => false]), $this->auth)->assertOk();
        $json = $this->getJson($this->public.'/thank-you?number=ANY')->assertOk()->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.content_html', '')->assertJsonPath('data.products', [])->json();
        $this->assertArrayNotHasKey('receipt', $json);
        $this->assertArrayNotHasKey('payment', $json);
        $this->assertArrayNotHasKey('content', $json['data']);
        $this->postJson($this->public.'/checkout/receipt', ['token' => 'invalid'])->assertNotFound();
        $this->assertDatabaseCount('store_orders', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_rich_content_is_sanitized_and_stale_edits_cannot_replace_saved_settings(): void
    {
        $dirty = '<h2 onclick="bad()">Welcome 🎁</h2><script>bad()</script><a href="javascript:bad()">bad</a><img src="https://example.test/p.png" onerror="bad()"><video src="/video.mp4" autoplay></video><iframe src="https://example.test"></iframe>';
        $response = $this->putJson($this->settings, $this->body(['content' => ['ar' => $dirty, 'en' => null], 'show_home_button' => false]), $this->auth)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.show_home_button', false);
        $html = $response->json('data.content.ar');
        foreach (['onclick', 'onerror', 'javascript:', '<script', '<iframe', 'autoplay'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        foreach (['Welcome 🎁', '<h2>', 'https://example.test/p.png', 'controls', '/video.mp4'] as $safe) {
            $this->assertStringContainsString($safe, $html);
        }
        $this->putJson($this->settings, $this->body(['enabled' => false]), $this->auth)->assertConflict();
        $this->getJson($this->settings, $this->auth)->assertJsonPath('data.enabled', true)->assertJsonPath('data.version', 2);
        $this->getJson($this->public.'/thank-you?lang=en')->assertJsonPath('data.content_html', $html);
    }

    public function test_invalid_configuration_is_atomic_and_preserves_both_language_drafts(): void
    {
        foreach ([['version' => 0], ['version' => null], ['enabled' => 'bad'], ['content' => ['ar' => 'a']], ['content' => ['ar' => 'a', 'en' => 'e', 'fr' => 'f']], ['content' => ['ar' => str_repeat('x', 20001), 'en' => 'e']], ['category_id' => -1], ['show_home_button' => null]] as $change) {
            $this->putJson($this->settings, $this->body($change), $this->auth)->assertUnprocessable();
        }
        $this->assertNull($this->store->fresh()->thank_you_configuration);
        $this->putJson($this->settings, $this->body(), $this->auth)->assertOk();
        $this->putJson($this->settings, $this->body(['enabled' => false, 'version' => 2]), $this->auth)->assertOk()->assertJsonPath('data.content.en', '<p>Thank you</p>');
    }

    public function test_only_safe_text_colors_and_alignment_survive_saved_html_and_live_reads(): void
    {
        $html = '<h2 style="text-align:center;position:fixed;z-index:999">Hello</h2><p style="color:rgb(1, 2, 255);background-color:#abc;background-image:url(https://example.test);width:99999px" onclick="bad()">Color</p><span style="color:expression(bad());background-color:var(--secret);text-align:start">Safe</span>';
        $response = $this->putJson($this->settings, $this->body(['content' => ['ar' => $html, 'en' => '']]), $this->auth)->assertOk();
        $clean = $response->json('data.content.ar');
        foreach (['position', 'z-index', 'url(', 'width', 'onclick', 'expression', 'var('] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $clean);
        }
        foreach (['text-align:center', 'color:#0102ff', 'background-color:#aabbcc', 'text-align:start'] as $safe) {
            $this->assertStringContainsString($safe, $clean);
        }
        $this->getJson($this->public.'/thank-you?lang=ar')->assertOk()->assertJsonPath('data.content_html', $clean);
        $this->assertStringNotContainsString('style', ProductDescription::clean($html));
    }

    public function test_category_must_be_published_in_this_store_and_options_do_not_leak_other_catalogs(): void
    {
        $valid = $this->category();
        $hidden = $this->category(['slug' => 'hidden', 'is_active' => false]);
        $blank = $this->category(['slug' => null]);
        $foreign = $this->category(['store_id' => null, 'slug' => 'global']);
        // Creation hooks supply missing slug/store values; simulate real legacy/B2B rows explicitly.
        DB::table('categories')->where('id', $blank->id)->update(['slug' => null]);
        DB::table('categories')->where('id', $foreign->id)->update(['store_id' => null]);
        $this->getJson($this->settings, $this->auth)->assertJsonCount(1, 'categories')->assertJsonPath('categories.0.id', $valid->id);
        foreach ([$hidden->id, $blank->id, $foreign->id, 999999] as $id) {
            $this->putJson($this->settings, $this->body(['category_id' => $id]), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        $this->assertNull($this->store->fresh()->thank_you_configuration);
        $this->putJson($this->settings, $this->body(['category_id' => $valid->id]), $this->auth)->assertOk();
    }

    public function test_public_language_category_products_visibility_and_removed_category_recovery(): void
    {
        $category = $this->category();
        for ($i = 0; $i < 10; $i++) {
            Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Bag '.$i, 'slug' => 'bag-'.$i, 'price' => 100, 'category_id' => $category->id, 'is_active' => true]);
        }
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Hidden', 'slug' => 'hidden', 'price' => 100, 'category_id' => $category->id, 'is_active' => false]);
        $global = Product::create(['store_id' => null, 'user_id' => $this->store->owner_user_id, 'name' => 'Global', 'slug' => 'global', 'price' => 100, 'category_id' => $category->id, 'is_active' => true]);
        DB::table('products')->where('id', $global->id)->update(['store_id' => null]);
        $this->putJson($this->settings, $this->body(['category_id' => $category->id]), $this->auth)->assertOk();
        $response = $this->getJson($this->public.'/thank-you?lang=en')->assertOk()->assertJsonPath('data.content_html', '<p>Thank you</p>')->assertJsonPath('data.category.name', 'Bags')->assertJsonCount(8, 'data.products');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        foreach ($response->json('data.products') as $product) {
            $this->assertStringStartsWith('bag-', $product['slug']);
        }
        $this->getJson($this->public.'/thank-you?lang=ar')->assertJsonPath('data.content_html', '<h2>شكرًا لك</h2>')->assertJsonPath('data.category.name', 'حقائب');
        $category->update(['is_active' => false]);
        $this->getJson($this->public.'/thank-you')->assertJsonPath('data.category', null)->assertJsonPath('data.products', []);
        $category->delete();
        $this->getJson($this->public.'/thank-you')->assertJsonPath('data.products', []);
        $this->putJson($this->settings, $this->body(['version' => 2]), $this->auth)->assertOk();
        $this->getJson($this->public.'/thank-you')->assertJsonPath('data.category', null);
    }

    public function test_settings_permissions_isolate_owners_employees_and_admin_routes(): void
    {
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->getJson('/api/v1/stores/'.$this->store->id.'/thank-you', $headers)->assertNotFound();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/thank-you', $this->body(), $headers)->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->settings, $headers)->assertForbidden();
        $this->putJson($this->settings, $this->body(), $headers)->assertForbidden();
        $employee->givePermissionTo('store.settings.manage');
        $this->travel(31)->seconds();
        $this->putJson($this->settings, $this->body(), $headers)->assertOk();
        $this->assertSame(2, $this->store->fresh()->thank_you_configuration['version']);
    }
}

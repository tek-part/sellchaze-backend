<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDesignAsset;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreDesignAssetsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $base = '/api/v1/my-store/design-assets';

    private string $host = 'http://files.sellchase.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Files', 'slug' => 'files', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'files.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        app(CurrentStore::class)->set($this->store);
    }

    private function upload(string $name = 'theme.css', string $body = 'body { color: #123456; }'): array
    {
        return $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent($name, $body)], $this->auth + ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function settings(array $ids, int $version = 1, bool $enabled = true): array
    {
        return ['version' => $version, 'enabled' => $enabled, 'assets' => $ids];
    }

    public function test_uploaded_code_is_immutable_exact_and_public_only_on_its_store_host(): void
    {
        $body = "@media (max-width: 500px) { h1 { color: red; } }\n/* عربي */";
        $css = $this->upload('Theme.CSS', $body);
        $this->assertSame('css', $css['kind']);
        $this->assertSame(strlen($body), $css['size_bytes']);
        $this->assertSame(hash('sha256', $body), $css['checksum_sha256']);
        $this->get($this->host.$css['public_path'])->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertContent($body);
        $jsBody = "document.body.dataset.themeReview = '</script> عربي';";
        $js = $this->upload('theme.js', $jsBody);
        $this->get($this->host.$js['public_path'])->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')->assertContent($jsBody);
        $this->get('http://localhost'.$css['public_path'])->assertNotFound();
        $this->get('http://unknown.test'.$js['public_path'])->assertNotFound();
        $this->get($this->host.str_replace('.css', '.js', $css['public_path']))->assertNotFound();
        $this->get($this->host.'/api/v1/storefront/design-assets/../../.env')->assertNotFound();
        $this->getJson($this->host.'/api/v1/storefront/design-assets')->assertJsonPath('data.enabled', false)->assertJsonPath('data.assets', []);
        $this->assertSame(2, StoreDesignAsset::count());
    }

    public function test_deduplication_preserves_urls_and_restores_without_reactivating(): void
    {
        $css = $this->upload();
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('another.css', 'body { color: #123456; }')], $this->auth + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('deduplicated', true)->assertJsonPath('data.id', $css['id'])->assertJsonPath('data.name', 'theme.css');
        $this->putJson($this->base.'/configuration', $this->settings([$css['id']]), $this->auth)->assertOk();
        $this->deleteJson($this->base.'/'.$css['id'], [], $this->auth)->assertOk();
        $this->get($this->host.$css['public_path'])->assertNotFound();
        $this->getJson($this->base.'?archived=1', $this->auth)->assertJsonPath('data.0.archived', true)->assertJsonPath('configuration.assets', []);
        $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent('restored.css', 'body { color: #123456; }')], $this->auth + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $css['id']);
        $this->get($this->host.$css['public_path'])->assertOk();
        $this->getJson($this->host.'/api/v1/storefront/design-assets')->assertJsonPath('data.assets', []);
        $this->assertSame(1, StoreDesignAsset::withTrashed()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('store-design-assets'));
    }

    public function test_public_manifest_order_version_disable_and_stale_edit_are_atomic(): void
    {
        $css = $this->upload();
        $js = $this->upload('behavior.js', 'window.designExample = 1;');
        $this->putJson($this->base.'/configuration', $this->settings([$js['id'], $css['id']]), $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($this->host.'/api/v1/storefront/design-assets')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.assets.0.kind', 'js')->assertJsonPath('data.assets.1.url', $css['public_path'])->assertJsonMissingPath('data.version')->assertJsonMissingPath('data.assets.0.name');
        $this->putJson($this->base.'/configuration', $this->settings([], 1), $this->auth)->assertStatus(409);
        $this->putJson($this->base.'/configuration', $this->settings([$js['id'], $css['id']], 2, false), $this->auth)->assertOk()->assertJsonPath('data.assets', [$js['id'], $css['id']]);
        $this->getJson($this->host.'/api/v1/storefront/design-assets')->assertJsonPath('data.enabled', false)->assertJsonPath('data.assets', []);
        $this->putJson($this->base.'/configuration', $this->settings([$css['id'], $js['id']], 3), $this->auth)->assertOk();
        $this->getJson($this->host.'/api/v1/storefront/design-assets')->assertJsonPath('data.assets.0.kind', 'css');
        $this->deleteJson($this->base.'/'.$css['id'], [], $this->auth)->assertOk();
        $this->getJson($this->base, $this->auth)->assertJsonPath('configuration.version', 5)->assertJsonPath('configuration.assets', [$js['id']]);
        $this->postJson($this->base.'/'.$css['id'].'/restore', [], $this->auth)->assertOk();
        $this->getJson($this->base, $this->auth)->assertJsonPath('configuration.assets', [$js['id']]);
    }

    public function test_file_validation_and_selection_never_partially_change_state(): void
    {
        foreach ([['x.php', '<?php echo 1;'], ['x.html', '<script>x</script>'], ['x.css', ' '], ['x.js', "bad\0file"], ['x.css', "\xff\xfe"], ['x.css', str_repeat('x', 2 * 1024 * 1024 + 1)]] as [$name, $body]) {
            $this->post($this->base, ['file' => UploadedFile::fake()->createWithContent($name, $body)], $this->auth + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame(0, StoreDesignAsset::count());
        $this->assertCount(0, Storage::disk('local')->allFiles('store-design-assets'));
        $css = $this->upload();
        foreach ([[$css['id'], $css['id']], [999], array_fill(0, 41, 1)] as $ids) {
            $this->putJson($this->base.'/configuration', $this->settings($ids), $this->auth)->assertUnprocessable();
        }
        $this->getJson($this->base, $this->auth)->assertJsonPath('configuration.version', 1)->assertJsonPath('configuration.assets', []);
        $this->deleteJson($this->base.'/'.$css['id'], [], $this->auth)->assertOk();
        $this->putJson($this->base.'/configuration', $this->settings([$css['id']], 2), $this->auth)->assertUnprocessable();
        Storage::disk('local')->delete(StoreDesignAsset::withTrashed()->findOrFail($css['id'])->path);
        $this->postJson($this->base.'/'.$css['id'].'/restore', [], $this->auth)->assertStatus(409);
    }

    public function test_tenant_permissions_cover_library_restore_configuration_and_public_bytes(): void
    {
        $css = $this->upload();
        $foreign = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-files', 'status' => 'active']);
        $this->getJson('/api/v1/stores/'.$foreign->id.'/design-assets', $this->auth)->assertForbidden();
        $foreignAsset = StoreDesignAsset::create(['store_id' => $foreign->id, 'public_id' => (string) Str::uuid(), 'name' => 'other.css', 'kind' => 'css', 'path' => 'other.css', 'size_bytes' => 1, 'checksum_sha256' => hash('sha256', 'x')]);
        Storage::disk('local')->put('other.css', 'x');
        $this->deleteJson($this->base.'/'.$foreignAsset->id, [], $this->auth)->assertNotFound();
        $this->postJson($this->base.'/'.$foreignAsset->id.'/restore', [], $this->auth)->assertNotFound();
        $this->putJson($this->base.'/configuration', $this->settings([$foreignAsset->id]), $this->auth)->assertUnprocessable();
        $this->get($this->host.$foreignAsset->publicPath())->assertNotFound();
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee)];
        $this->getJson($this->base, $headers)->assertForbidden();
        $this->putJson($this->base.'/configuration', $this->settings([$css['id']]), $headers)->assertForbidden();
        $employee->givePermissionTo('store.pages.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->base, $headers)->assertForbidden();
        $employee->givePermissionTo('store.themes.manage');
        $this->travel(31)->seconds();
        $this->getJson($this->base, $headers)->assertOk();
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $headers = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($admin)];
        $this->getJson('/api/v1/stores/'.$foreign->id.'/design-assets', $headers)->assertOk()->assertJsonPath('data.0.id', $foreignAsset->id);
    }

    public function test_blade_fallback_loads_external_tags_and_cache_updates_on_disable(): void
    {
        config(['sellchase.storefront.spa_shell' => '', 'sellchase.storefront.ssr_url' => '']);
        Product::create(['store_id' => $this->store->id, 'user_id' => $this->store->owner_user_id, 'name' => 'Bag', 'slug' => 'bag', 'sku' => 'DESIGN-BAG', 'price' => 100, 'stock' => 10, 'is_active' => true]);
        $css = $this->upload();
        $js = $this->upload('behavior.js', 'window.example = 1;');
        $this->putJson($this->base.'/configuration', $this->settings([$css['id'], $js['id']]), $this->auth)->assertOk();
        $html = $this->get($this->host.'/products/bag')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$css['public_path'].'" data-store-design-asset=', $html);
        $this->assertStringContainsString('defer src="'.$js['public_path'].'" data-store-design-asset=', $html);
        $this->assertStringNotContainsString('window.example', $html);
        $this->putJson($this->base.'/configuration', $this->settings([$css['id'], $js['id']], 2, false), $this->auth)->assertOk();
        $this->get($this->host.'/products/bag')->assertOk()->assertDontSee('data-store-design-asset=', false);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StoreHeadCodeController;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Commerce\StoreHeadCode;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StoreHeadCodeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private string $url = '/api/v1/my-store/head-code';

    private string $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class, StorePermissionsSeeder::class]);
        Http::preventStrayRequests();
        config(['sellchase.storefront.base_domain' => 'sellchaze.com']);
        Mail::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->store = $this->owner->fresh()->store;
        $this->store->update(['status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'head-code.sellchaze.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->shell = tempnam(sys_get_temp_dir(), 'head-code-shell-');
        file_put_contents($this->shell, '<html><head><title>Store</title></head><body><div id="storefront-root"></div></body></html>');
        config(['sellchase.storefront.spa_shell' => $this->shell, 'sellchase.storefront.force_https' => false]);
    }

    protected function tearDown(): void
    {
        if (isset($this->shell) && is_file($this->shell)) {
            unlink($this->shell);
        }
        parent::tearDown();
    }

    private function code(): string
    {
        return "\n  <meta name=\"site-verification\" content=\"review\">\n<style>html{--merchant-review:1}</style>\n<script>window.review='$1';</script>\n  ";
    }

    private function save(string $html, int $version = 1): void
    {
        $this->putJson($this->url, ['version' => $version, 'html' => $html])->assertOk()->assertJsonPath('data.html', $html)->assertJsonPath('data.version', $version + 1);
    }

    public function test_private_read_is_noop_and_save_preserves_source_whitespace_and_other_settings(): void
    {
        $before = $this->store->fresh()->getRawOriginal();
        $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.html', '')->assertJsonPath('data.version', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->store->fresh()->getRawOriginal());
        $this->save($this->code());
        $after = $this->store->fresh()->getRawOriginal();
        unset($before['head_code_configuration'], $before['updated_at'], $after['head_code_configuration'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertArrayNotHasKey('head_code_configuration', $this->store->fresh()->toArray());
        $this->getJson($this->url)->assertJsonPath('data.html', $this->code());
    }

    public function test_stale_save_and_invalid_or_oversized_code_are_rejected(): void
    {
        $this->save($this->code());
        $this->putJson($this->url, ['version' => 1, 'html' => 'stale'])->assertConflict();
        foreach ([['version' => 2], ['version' => 2, 'html' => ['bad']], ['version' => 2, 'html' => str_repeat('a', 65536)]] as $body) {
            $this->putJson($this->url, $body)->assertUnprocessable();
        }
        $this->assertSame($this->code(), app(StoreHeadCode::class)->configuration($this->store->fresh())['html']);
    }

    public function test_real_public_html_and_bootstrap_publish_code_once_in_head_and_clear_on_next_request(): void
    {
        $this->save($this->code());
        $url = 'http://head-code.sellchaze.com';
        $response = $this->get($url.'/')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString($this->code(), $html);
        $this->assertSame(1, substr_count($html, 'name="sellchaze-head-code"'));
        $this->assertLessThan(stripos($html, '</head>'), strpos($html, 'site-verification'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($url.'/api/v1/storefront')->assertOk()->assertJsonPath('head_code.html', $this->code())->assertJsonPath('head_code.host', 'head-code.sellchaze.com');
        $firstEtag = $response->headers->get('ETag');
        $this->save('', 2);
        $response = $this->get($url.'/')->assertOk();
        $this->assertStringNotContainsString('site-verification', $response->getContent());
        $this->assertNotSame($firstEtag, $response->headers->get('ETag'));
        $this->getJson($url.'/api/v1/storefront')->assertJsonPath('head_code', null);
    }

    public function test_isolated_tenant_preview_and_customizer_show_published_head_code(): void
    {
        $this->save($this->code());
        foreach (['preview=1', 'customize=1', '__preview=invalid', 'preview=0'] as $query) {
            $response = $this->get('http://head-code.sellchaze.com/?'.$query)->assertOk();
            $this->assertStringContainsString($this->code(), $response->getContent());
            $this->getJson('http://head-code.sellchaze.com/api/v1/storefront?'.$query)->assertJsonPath('head_code.html', $this->code());
        }
    }

    public function test_control_plane_and_reserved_hosts_are_excluded_even_for_legacy_attached_domains(): void
    {
        $this->save($this->code());
        $service = app(StoreHeadCode::class);
        config(['app.url' => 'https://api.example.test', 'sellchase.frontend_url' => 'https://dashboard.example.test', 'sellchase.storefront.spa_origin' => 'https://assets.example.test']);
        foreach (['sellchaze.com', 'api.example.test', 'dashboard.example.test', 'assets.example.test', 'admin.sellchaze.com', 'localhost', '127.0.0.1'] as $host) {
            $this->assertNull($service->publication($this->store->fresh(), Request::create('https://'.$host)));
            $this->assertNull($service->publication($this->store->fresh(), Request::create('https://'.$host.'/?preview=1')));
        }
        $this->assertNotNull($service->publication($this->store->fresh(), Request::create('https://head-code.sellchaze.com')));
        $this->store->update(['status' => 'suspended']);
        $this->assertNull($service->publication($this->store->fresh(), Request::create('https://head-code.sellchaze.com')));
    }

    public function test_owner_cannot_edit_foreign_store_and_another_owner_does_not_read_this_code(): void
    {
        $this->save($this->code());
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson('/api/v1/my-store')->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.html', '');
        $this->getJson('/api/v1/stores/'.$this->store->id.'/head-code')->assertNotFound();
        $this->putJson('/api/v1/stores/'.$this->store->id.'/head-code', ['version' => 2, 'html' => 'foreign'])->assertNotFound();
        $this->assertSame($this->code(), app(StoreHeadCode::class)->configuration($this->store->fresh())['html']);
    }

    public function test_global_store_admin_can_manage_code_through_the_scoped_admin_route(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $url = '/api/v1/stores/'.$this->store->id.'/head-code';
        $this->getJson($url)->assertOk()->assertJsonPath('data.html', '');
        $this->putJson($url, ['version' => 1, 'html' => $this->code()])->assertOk()->assertJsonPath('data.html', $this->code());
    }

    public function test_general_settings_permission_alone_does_not_authorize_executable_code(): void
    {
        $staff = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $staff->givePermissionTo('store.settings.manage');
        $request = Request::create($this->url, 'PUT', ['version' => 1, 'html' => $this->code()]);
        $request->setUserResolver(fn () => $staff);
        $request->attributes->set('store', $this->store);
        try {
            app(StoreHeadCodeController::class)->update($request);
            $this->fail('A general settings grant must not allow arbitrary store scripts.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertNull($this->store->fresh()->head_code_configuration);
    }
}

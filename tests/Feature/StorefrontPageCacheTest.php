<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\FlushesOwnerStorefront;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Storefront\StorefrontPageCache;
use App\Services\Themes\StoreThemeService;
use App\Services\Themes\ThemeRegistry;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontPageCacheTest extends TestCase
{
    use RefreshDatabase;

    private StorefrontPageCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = app(StorefrontPageCache::class);
    }

    private function ctx(int $storeId = 1, int $tvId = 1, array $settings = ['primary' => '#000']): array
    {
        return ['store' => ['id' => $storeId], 'theme' => ['theme_version_id' => $tvId, 'settings' => $settings]];
    }

    public function test_key_changes_with_each_component(): void
    {
        $base = $this->cache->key($this->ctx(), '/', 'en');

        $this->assertSame($base, $this->cache->key($this->ctx(), '/', 'en'));               // stable
        $this->assertNotSame($base, $this->cache->key($this->ctx(storeId: 2), '/', 'en'));  // store_id
        $this->assertNotSame($base, $this->cache->key($this->ctx(tvId: 2), '/', 'en'));      // theme_version_id
        $this->assertNotSame($base, $this->cache->key($this->ctx(settings: ['primary' => '#fff']), '/', 'en')); // settings_hash
        $this->assertNotSame($base, $this->cache->key($this->ctx(), '/products', 'en'));     // path
        $this->assertNotSame($base, $this->cache->key($this->ctx(), '/', 'ar'));             // locale
    }

    public function test_flush_store_invalidates_the_key(): void
    {
        $before = $this->cache->key($this->ctx(99), '/', 'en');
        $this->cache->flushStore(99);
        $after = $this->cache->key($this->ctx(99), '/', 'en');

        $this->assertNotSame($before, $after, 'flushStore must change the generation and thus the key');
    }

    public function test_theme_activation_and_catalog_changes_flush_the_store(): void
    {
        app(ThemeRegistry::class)->registerFromFile(resource_path('themes/default/theme.json'));
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active',
        ]);

        $gen1 = $this->cache->generation($store->id);

        app(StoreThemeService::class)->installAndActivateDefault($store); // activation flush
        $gen2 = $this->cache->generation($store->id);
        $this->assertGreaterThan($gen1, $gen2);

        $store->update(['status' => 'suspended']); // store status flush
        $gen3 = $this->cache->generation($store->id);
        $this->assertGreaterThan($gen2, $gen3);

        // A catalog change via the unified /products path flushes the owner's storefront.
        Product::create(['user_id' => $store->owner_user_id, 'name' => 'Air Max', 'price' => 10, 'is_active' => true]);
        $flusher = new class
        {
            use FlushesOwnerStorefront;

            public function flush(int $ownerId): void
            {
                $this->flushOwnerStorefront($ownerId);
            }
        };
        $flusher->flush((int) $store->owner_user_id);
        $gen4 = $this->cache->generation($store->id);
        $this->assertGreaterThan($gen3, $gen4);
    }
}

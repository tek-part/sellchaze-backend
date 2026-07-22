<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\StoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSlugUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $slug): Store
    {
        $user = User::factory()->create();

        return Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => 'Test',
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    public function test_duplicate_slug_gets_a_numeric_suffix(): void
    {
        $this->makeStore('nike');
        $service = new StoreService;

        $this->assertSame('nike-2', $service->uniqueSlug('nike'));
    }

    public function test_ignore_id_lets_a_store_keep_its_own_slug(): void
    {
        $store = $this->makeStore('nike');
        $service = new StoreService;

        $this->assertSame('nike', $service->uniqueSlug('nike', $store->id));
    }

    public function test_reserved_slug_is_hardened_even_when_available(): void
    {
        $service = new StoreService;

        $this->assertSame('admin-store', $service->uniqueSlug('admin'));
    }
}

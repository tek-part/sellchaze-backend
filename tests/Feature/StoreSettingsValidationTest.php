<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\CurrencyRatesSeeder;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StorePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UpdateStoreRequest hardening for the owner settings form: status is not
 * writable by owners (publish/unpublish only), currencies are normalised to
 * uppercase and must exist in currency_rates, and the store-scoped currency
 * list endpoint exposes the available codes.
 */
class StoreSettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->seed(StorePermissionsSeeder::class);
        $this->seed(CurrencyRatesSeeder::class);
    }

    private function owner(string $role = 'Supplier'): User
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole($role);

        return $user;
    }

    private function asUser(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function provisionedStore(User $user): Store
    {
        $this->asUser($user)->getJson('/api/v1/my-store')->assertOk();

        return $user->fresh()->store;
    }

    public function test_owner_status_input_is_ignored(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $this->assertSame('draft', $store->status);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['status' => 'active', 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Renamed');
        $this->assertSame('draft', $store->fresh()->status);
    }

    public function test_admin_can_still_set_status_directly(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);
        $admin = $this->owner('Admin');

        $this->asUser($admin)->putJson("/api/v1/stores/{$store->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $owner = $this->owner();
        $this->provisionedStore($owner);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['currency' => 'XXX'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);

        $this->asUser($owner)->putJson('/api/v1/my-store', ['supported_currencies' => ['USD', 'ZZZ']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supported_currencies.1']);
    }

    public function test_lowercase_currencies_are_uppercased_before_validation(): void
    {
        $owner = $this->owner();
        $store = $this->provisionedStore($owner);

        $this->asUser($owner)->putJson('/api/v1/my-store', [
            'currency' => 'aed',
            'supported_currencies' => ['aed', 'usd'],
        ])->assertOk()
            ->assertJsonPath('data.currency', 'AED');

        $fresh = $store->fresh();
        $this->assertSame('AED', $fresh->currency);
        $this->assertSame(['AED', 'USD'], array_values((array) $fresh->supported_currencies));
    }

    public function test_store_scoped_currency_codes_endpoint(): void
    {
        $owner = $this->owner('Merchant');
        $this->provisionedStore($owner);

        $response = $this->asUser($owner)->getJson('/api/v1/my-store/currencies')->assertOk();
        $codes = $response->json('data');

        $this->assertContains('USD', $codes);
        $this->assertContains('AED', $codes);
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes, 'codes are sorted');
        $this->assertSame($codes, array_map('strtoupper', $codes), 'codes are uppercase');
    }
}

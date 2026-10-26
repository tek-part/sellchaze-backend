<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuthSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Theme;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\Stores\StoreManagers;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreManagersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private string $base = '/api/v1/my-store/managers';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Notification::fake();
        Mail::fake();
        Storage::fake('public');
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Manager fixture', 'slug' => 'manager-fixture', 'status' => 'active', 'currency' => 'USD', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        $this->actingOwner();
    }

    private function actingOwner(): void
    {
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    private function createManager(array $permissions = [], array $extra = []): array
    {
        return $this->postJson($this->base, array_merge([
            'name' => 'Catalog manager', 'email' => 'manager@example.test', 'password' => 'PrivateFixture123!', 'permissions' => $permissions,
        ], $extra))->assertCreated()->json('data');
    }

    private function login(array $manager): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $manager['email'], 'password' => 'PrivateFixture123!'])->assertOk()->json('access_token');
        $this->withToken($token);

        return $token;
    }

    public function test_create_has_only_explicit_store_grants_and_never_returns_credentials(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $manager = User::findOrFail($row['id']);
        $this->assertSame((int) $this->owner->id, (int) $manager->parent_user_id);
        $this->assertSame([StoreManagers::ROLE], $manager->getRoleNames()->all());
        $this->assertSame(['store.products.manage', 'store.view'], $row['permissions']);
        $this->assertFalse($manager->can('products-list'));
        $this->assertFalse($manager->can('store.settings.manage'));
        $this->assertTrue(Hash::check('PrivateFixture123!', $manager->password));
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);
        $response = $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(11, 'permissions');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $audit = ActivityLog::where('action', 'store.manager.created')->firstOrFail();
        $this->assertSame($this->store->id, $audit->properties['store_id']);
        $this->assertStringNotContainsString('PrivateFixture123!', $audit->toJson());
        $this->assertStringNotContainsString($manager->password, $audit->toJson());
    }

    public function test_creation_cannot_reuse_or_modify_an_existing_account_or_add_privileged_grants(): void
    {
        $before = User::count();
        foreach ([['permissions' => ['stores-edit']], ['roles' => ['Admin']], ['parent_user_id' => 999], ['store_id' => 999], ['password' => 'short'], ['email' => $this->owner->email]] as $extra) {
            $this->postJson($this->base, array_merge(['name' => 'Invalid', 'email' => 'invalid@example.test', 'password' => 'PrivateFixture123!', 'permissions' => []], $extra))->assertUnprocessable();
        }
        $this->assertSame($before, User::count());
        $this->assertSame(['Merchant'], $this->owner->fresh()->getRoleNames()->all());
    }

    public function test_manager_can_manage_only_the_selected_store_catalog_and_read_its_category_choices(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $this->login($row);
        $this->getJson('/api/v1/my-store')->assertOk()->assertJsonPath('data.id', $this->store->id);
        $this->getJson('/api/v1/my-store/catalog/categories')->assertOk();
        $this->getJson('/api/v1/my-store/catalog/products')->assertOk();
        $product = $this->postJson('/api/v1/my-store/catalog/products', ['name' => 'Owned product', 'slug' => 'owned-product', 'price' => 10])->assertCreated()->json('data');
        $this->putJson('/api/v1/my-store/catalog/products/'.$product['id'], ['name' => 'Updated product'])->assertOk()->assertJsonPath('data.name', 'Updated product');
        $this->postJson('/api/v1/my-store/catalog/categories', ['name' => 'Forbidden category', 'slug' => 'forbidden'])->assertForbidden();
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/my-store/orders')->assertForbidden();
        $this->getJson('/api/v1/my-store/managers')->assertForbidden();
        $this->postJson('/api/v1/employees', ['name' => 'Escalation', 'email' => 'child@example.test', 'password' => 'PrivateFixture123!'])->assertForbidden();
    }

    public function test_category_grant_does_not_require_a_global_b2b_category_permission(): void
    {
        $row = $this->createManager(['store.categories.manage']);
        $this->login($row);
        $this->postJson('/api/v1/my-store/catalog/categories', ['name' => 'Owned category', 'slug' => 'owned-category'])->assertCreated();
        $this->getJson('/api/v1/categories')->assertForbidden();
        $this->getJson('/api/v1/my-store/catalog/products')->assertForbidden();
    }

    public static function protectedStoreEndpoints(): array
    {
        return array_map(fn ($path) => [$path], [
            'orders', 'coupons', 'catalog/products', 'catalog/categories', 'analytics/overview', 'analytics/dashboard',
            'themes', 'pages', 'simple-pages', 'menus', 'media', 'payments', 'domains', 'shopping-preferences',
            'bot-protection', 'readiness', 'funnels', 'managers',
        ]);
    }

    #[DataProvider('protectedStoreEndpoints')]
    public function test_view_only_account_cannot_bypass_permissions_through_direct_store_urls(string $path): void
    {
        $row = $this->createManager();
        $this->login($row);
        $this->getJson('/api/v1/my-store/'.$path)->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/'.$path)->assertForbidden();
        $this->putJson('/api/v1/my-store', ['name' => 'Not allowed'])->assertForbidden();
        $this->assertSame('Manager fixture', $this->store->fresh()->name);
    }

    public function test_permission_change_revokes_existing_access_and_refresh_sessions_and_rejects_stale_edits(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $token = $this->login($row);
        $this->getJson('/api/v1/my-store/catalog/products')->assertOk();
        $this->actingOwner();
        $next = $this->putJson($this->base.'/'.$row['id'], array_merge($row, ['is_active' => true, 'permissions' => ['store.orders.manage']]))->assertOk()->json('data');
        $this->assertNotSame($row['version'], $next['version']);
        $this->assertDatabaseMissing('auth_sessions', ['user_id' => $row['id'], 'revoked_at' => null]);
        $this->withToken($token)->getJson('/api/v1/my-store/catalog/products')->assertUnauthorized();
        $this->actingOwner();
        $this->putJson($this->base.'/'.$row['id'], array_merge($row, ['is_active' => true]))->assertConflict();
        $this->assertSame(['store.orders.manage', 'store.view'], StoreManagers::serialize(User::findOrFail($row['id']))['permissions']);
        $this->login($row);
        $this->getJson('/api/v1/my-store/catalog/products')->assertForbidden();
        $this->getJson('/api/v1/my-store/orders')->assertOk();
    }

    public function test_remove_and_restore_preserve_history_and_leave_restored_access_disabled(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $token = $this->login($row);
        $session = AuthSession::where('user_id', $row['id'])->firstOrFail();
        $this->actingOwner();
        $removed = $this->deleteJson($this->base.'/'.$row['id'], ['version' => $row['version']])->assertOk()->json('data');
        $this->assertSoftDeleted('users', ['id' => $row['id']]);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $row['id'], 'action' => 'store.manager.removed']);
        $this->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->base.'?status=removed')->assertOk()->assertJsonCount(1, 'data');
        $restored = $this->postJson($this->base.'/'.$row['id'].'/restore', ['version' => $removed['version']])->assertOk()->json('data');
        $this->assertFalse($restored['is_active']);
        $this->assertSame($row['permissions'], $restored['permissions']);
        $this->withToken($token)->getJson('/api/v1/my-store')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $row['email'], 'password' => 'PrivateFixture123!'])->assertUnprocessable();
        $this->actingOwner();
        $this->putJson($this->base.'/'.$row['id'], array_merge($restored, ['is_active' => true]))->assertOk();
        $this->login($row);
        $this->getJson('/api/v1/my-store')->assertOk();
    }

    public function test_foreign_owner_and_other_roles_cannot_manage_accounts_even_with_settings_permission(): void
    {
        $row = $this->createManager();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $otherStore = Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-manager-fixture']);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($other));
        $this->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($this->base.'/'.$row['id'], array_merge($row, ['is_active' => false]))->assertNotFound();
        $this->deleteJson($this->base.'/'.$row['id'], ['version' => $row['version']])->assertNotFound();
        $this->actingOwner();
        $manager = $this->createManager(['store.settings.manage'], ['email' => 'settings-manager@example.test']);
        $this->login($manager);
        $this->getJson('/api/v1/my-store/managers')->assertForbidden();
        $this->getJson('/api/v1/stores/'.$otherStore->id)->assertForbidden();
        $this->getJson('/api/v1/stores/'.$otherStore->id.'/catalog/products')->assertForbidden();
        $this->postJson('/api/v1/employees', ['name' => 'No delegation', 'email' => 'delegate@example.test', 'password' => 'PrivateFixture123!'])->assertForbidden();
    }

    public function test_parent_deactivation_blocks_both_implicit_and_explicit_store_routes(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $this->login($row);
        $this->owner->update(['is_active' => false]);
        $this->getJson('/api/v1/my-store/catalog/products')->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/catalog/products')->assertForbidden();
    }

    public function test_dedicated_manager_tokens_require_a_revocable_session(): void
    {
        $row = $this->createManager(['store.products.manage']);
        $token = JwtTokenService::fromConfig()->issueAccessToken(User::findOrFail($row['id']));
        $this->withToken($token)->getJson('/api/v1/my-store')->assertUnauthorized();
    }

    public function test_page_manager_can_choose_funnel_products_without_catalog_editing_access(): void
    {
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Product choice', 'slug' => 'product-choice', 'price' => 15]);
        $row = $this->createManager(['store.pages.manage']);
        $this->login($row);
        $this->getJson('/api/v1/my-store/funnels/products?search=Product')->assertOk()->assertJsonPath('data.0.id', $product->id)->assertJsonMissingPath('data.0.price');
        $this->getJson('/api/v1/my-store/catalog/products')->assertForbidden();
        $this->postJson('/api/v1/my-store/funnels', ['title' => 'Native choice', 'slug' => 'native-choice', 'locale' => 'en', 'product_id' => $product->id, 'template_key' => 'glasses-en'])->assertCreated();
    }

    public function test_name_only_edit_keeps_sessions_but_password_change_revokes_them(): void
    {
        $row = $this->createManager();
        $token = $this->login($row);
        $this->actingOwner();
        $next = $this->putJson($this->base.'/'.$row['id'], array_merge($row, ['name' => 'New display name', 'is_active' => true]))->assertOk()->json('data');
        $this->withToken($token)->getJson('/api/v1/my-store')->assertOk();
        $this->actingOwner();
        $this->putJson($this->base.'/'.$row['id'], array_merge($next, ['password' => 'ReplacementFixture456!']))->assertOk();
        $this->withToken($token)->getJson('/api/v1/my-store')->assertUnauthorized();
        $this->assertTrue(Hash::check('ReplacementFixture456!', User::findOrFail($row['id'])->password));
    }

    public function test_owner_can_filter_accounts_but_cannot_modify_legacy_or_privileged_children(): void
    {
        $row = $this->createManager();
        $legacy = User::factory()->create(['parent_user_id' => $this->owner->id, 'is_active' => true]);
        $legacy->assignRole('Employee');
        $this->getJson($this->base.'?search=manager%40example.test')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base.'?status=inactive')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($this->base.'/'.$legacy->id, ['version' => $row['version']])->assertNotFound();
        $this->putJson('/api/v1/employees/'.$row['id'], ['is_active' => false])->assertNotFound();
        User::findOrFail($row['id'])->assignRole('Admin');
        $this->deleteJson($this->base.'/'.$row['id'], ['version' => $row['version']])->assertNotFound();
        $this->assertTrue($legacy->fresh()->is_active);
    }

    public function test_legacy_owner_employee_management_stays_separate_and_operational(): void
    {
        $manager = $this->createManager();
        $employee = $this->postJson('/api/v1/employees', ['name' => 'Legacy employee', 'email' => 'legacy@example.test', 'password' => 'PrivateFixture123!'])->assertCreated()->json('data');
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $employee['id']);
        $this->putJson('/api/v1/employees/'.$employee['id'], ['name' => 'Renamed legacy employee'])->assertOk()->assertJsonPath('data.name', 'Renamed legacy employee');
        $this->deleteJson('/api/v1/employees/'.$employee['id'])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $employee['id']]);
        $this->assertDatabaseHas('users', ['id' => $manager['id'], 'deleted_at' => null]);
    }

    public function test_theme_editors_cannot_start_owner_payment_sessions_on_either_tenant_prefix(): void
    {
        $theme = Theme::create(['key' => 'manager-paid-fixture', 'name' => 'Paid fixture', 'status' => 'published', 'is_marketplace' => true, 'price' => 100, 'currency' => 'USD']);
        $row = $this->createManager(['store.themes.manage']);
        $this->actingAs(User::findOrFail($row['id']), 'sanctum');
        foreach (['/api/my-store', '/api/stores/'.$this->store->id] as $prefix) {
            $this->postJson($prefix.'/themes/'.$theme->id.'/purchase')->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_privileged_direct_grants_exclude_an_account_from_owner_account_management(): void
    {
        $row = $this->createManager();
        User::findOrFail($row['id'])->givePermissionTo('users-edit');
        $this->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($this->base.'/'.$row['id'], ['version' => $row['version']])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $row['id'], 'deleted_at' => null]);
    }

    public function test_role_level_grants_cannot_be_changed_through_owner_account_management(): void
    {
        $row = $this->createManager();
        Role::findByName(StoreManagers::ROLE, 'web')->givePermissionTo('users-edit');
        $this->getJson($this->base)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($this->base.'/'.$row['id'], ['version' => $row['version']])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $row['id'], 'deleted_at' => null]);
        $this->postJson($this->base, ['name' => 'No inherited access', 'email' => 'no-inherited@example.test', 'password' => 'PrivateFixture123!', 'permissions' => []])->assertConflict();
        $this->assertDatabaseMissing('users', ['email' => 'no-inherited@example.test']);
    }
}

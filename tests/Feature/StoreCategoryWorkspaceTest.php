<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StoreCategoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private array $auth;

    private string $base = '/api/v1/my-store/catalog/categories';

    private string $public = 'http://category-review.sellchase.com/api/v1/storefront';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('public');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Categories', 'slug' => 'category-review', 'currency' => 'EGP', 'default_locale' => 'ar', 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'category-review.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
    }

    private function category(string $slug, array $extra = []): array
    {
        return $this->postJson($this->base, ['name' => $slug, 'slug' => $slug, 'is_active' => true] + $extra, $this->auth)->assertCreated()->json('data');
    }

    public function test_category_edit_preserves_product_assignment_and_renders_safe_rich_content_and_seo(): void
    {
        $row = $this->category('furniture');
        $this->putJson($this->base.'/'.$row['id'], ['name' => 'New furniture', 'description' => '<h2>About</h2><script>alert(1)</script><a href="javascript:alert(1)">x</a>', 'seo_title' => 'SEO title', 'seo_description' => 'SEO summary', 'show_in_header' => false, 'is_hidden' => true, 'edit_version' => $row['edit_version']], $this->auth)->assertOk()->assertJsonPath('data.name', 'New furniture')->assertJsonPath('data.show_in_header', false)->assertJsonPath('data.is_hidden', true)->assertJsonPath('data.seo_title', 'SEO title');
        $public = $this->getJson($this->public.'/categories/furniture')->assertOk()->assertJsonPath('data.name', 'New furniture')->assertJsonMissingPath('data.edit_version');
        $this->assertStringContainsString('<h2>About</h2>', $public->json('data.description'));
        $this->assertStringNotContainsString('script', $public->json('data.description'));
        $this->getJson($this->public.'/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($this->base.'/'.$row['id'], ['name' => 'Updated again', 'description' => '<h2>Replacement</h2>'], $this->auth)->assertOk()->assertJsonPath('data.description', '<h2>Replacement</h2>');
        $this->getJson($this->public.'/categories/furniture')->assertOk()->assertJsonPath('data.description', '<h2>Replacement</h2>');
    }

    public function test_parent_category_contains_descendant_products_and_visible_children_only(): void
    {
        $parent = $this->category('parent');
        $child = $this->category('child', ['parent_id' => $parent['id']]);
        $hidden = $this->category('hidden', ['parent_id' => $parent['id'], 'is_hidden' => true]);
        foreach ([$parent, $child, $hidden] as $row) {
            Product::create(['store_id' => $this->store->id, 'name' => $row['name'], 'slug' => $row['slug'], 'category_id' => $row['id'], 'is_active' => true, 'price' => 2]);
        }
        $this->getJson($this->public.'/categories/parent')->assertOk()->assertJsonCount(1, 'children')->assertJsonPath('children.0.id', $child['id'])->assertJsonPath('products.meta.total', 3);
        $this->getJson($this->base, $this->auth)->assertOk()->assertJsonPath('data.0.children_count', 2);
        $this->putJson($this->base.'/'.$child['id'], ['parent_id' => null], $this->auth)->assertOk()->assertJsonPath('data.parent_id', null);
        $this->getJson($this->public.'/categories/parent')->assertOk()->assertJsonPath('products.meta.total', 2);
    }

    public function test_parent_cycles_foreign_parents_and_stale_versions_are_rejected(): void
    {
        $one = $this->category('one');
        $two = $this->category('two', ['parent_id' => $one['id']]);
        $this->putJson($this->base.'/'.$one['id'], ['parent_id' => $two['id']], $this->auth)->assertUnprocessable();
        $this->putJson($this->base.'/'.$one['id'], ['parent_id' => $one['id']], $this->auth)->assertUnprocessable();
        $this->putJson($this->base.'/'.$one['id'], ['name' => 'Changed'], $this->auth)->assertOk();
        $this->putJson($this->base.'/'.$one['id'], ['name' => 'Overwritten', 'edit_version' => $one['edit_version']], $this->auth)->assertUnprocessable();
        app(CurrentStore::class)->forget();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-category', 'status' => 'active']);
        $foreign = Category::create(['store_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign']);
        $this->putJson($this->base.'/'.$one['id'], ['parent_id' => $foreign->id], $this->auth)->assertUnprocessable();
        $this->getJson($this->base.'/'.$foreign->id, $this->auth)->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $one['id'], 'name' => 'Changed', 'parent_id' => null]);
    }

    public function test_atomic_bulk_deletion_preserves_products_and_children_and_rejects_stale_selection(): void
    {
        $one = $this->category('one');
        $two = $this->category('two');
        $child = $this->category('child', ['parent_id' => $one['id']]);
        $product = Product::create(['store_id' => $this->store->id, 'name' => 'Keep', 'slug' => 'keep', 'category_id' => $one['id'], 'price' => 0]);
        $targets = array_map(fn ($row) => ['id' => $row['id'], 'version' => $row['edit_version']], [$one, $two]);
        $this->putJson($this->base.'/'.$two['id'], ['name' => 'Changed'], $this->auth)->assertOk();
        $this->postJson($this->base.'/bulk-delete', ['categories' => $targets], $this->auth)->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $one['id']]);
        $this->postJson($this->base.'/bulk-delete', ['categories' => [$targets[0]]], $this->auth)->assertOk()->assertJsonPath('meta.deleted', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => null, 'price' => 0]);
        $this->assertDatabaseHas('categories', ['id' => $child['id'], 'parent_id' => null]);
    }

    public function test_header_sort_visibility_images_and_delete_permissions_are_real(): void
    {
        $one = $this->category('zulu', ['show_in_header' => false]);
        $two = $this->category('alpha');
        $this->getJson($this->base.'?sort_by=name&sort_direction=asc', $this->auth)->assertOk()->assertJsonPath('data.0.id', $two['id']);
        $this->getJson($this->base.'?sort_by=image', $this->auth)->assertUnprocessable();
        $row = $this->post($this->base.'/'.$one['id'], ['_method' => 'PUT', 'image' => UploadedFile::fake()->image('photo.png')], $this->auth + ['Accept' => 'application/json'])->assertOk()->json('data');
        Storage::disk('public')->assertExists($row['image']);
        $this->putJson($this->base.'/'.$one['id'], ['remove_image' => true], $this->auth)->assertOk()->assertJsonPath('data.image', null);
        Role::findByName('Merchant', 'web')->revokePermissionTo('categories-delete');
        $this->deleteJson($this->base.'/'.$one['id'], [], $this->auth)->assertForbidden();
        $this->postJson($this->base.'/bulk-delete', ['categories' => [['id' => $two['id'], 'version' => $two['edit_version']]]], $this->auth)->assertForbidden();
    }
}

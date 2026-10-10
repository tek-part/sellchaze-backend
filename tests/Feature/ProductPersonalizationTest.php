<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Product;
use App\Models\ProductPersonalizationUpload;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Commerce\StorefrontOrderBridge;
use App\Services\Commerce\StoreOrderService;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private array $auth;

    private string $base = 'http://personal.sellchase.com/api/v1/storefront';

    private array $textField = ['key' => 'engraving', 'type' => 'text', 'label' => 'Engraving', 'labels' => ['ar' => 'الاسم', 'en' => 'Name'], 'required' => true, 'max_length' => 20];

    private array $imageField = ['key' => 'photo', 'type' => 'image', 'label' => 'Photo', 'required' => false, 'max_length' => 100];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Queue::fake([BridgeStorefrontOrderJob::class]);
        Storage::fake('local');
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Personal', 'slug' => 'personal', 'currency' => 'EGP', 'default_locale' => 'en', 'supported_locales' => ['ar', 'en'], 'status' => 'active']);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'personal.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 100, 'is_active' => true, 'track_inventory' => true, 'stock_quantity' => 3, 'personalization_fields' => [$this->textField, $this->imageField]]);
    }

    private function line(array $custom = ['engraving' => 'Alice'], int $quantity = 1): array
    {
        return ['product_id' => $this->product->id, 'quantity' => $quantity, 'personalization' => $custom];
    }

    private function order(array $lines): array
    {
        return ['customer_name' => 'Local buyer', 'customer_email' => 'buyer@example.test', 'items' => $lines];
    }

    private function image(): array
    {
        return $this->post($this->base.'/products/'.$this->product->id.'/personalization-image', ['field_key' => 'photo', 'file' => UploadedFile::fake()->image('customer.png', 32, 32)], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_merchant_schema_round_trips_and_public_fields_are_localized(): void
    {
        $path = '/api/v1/my-store/catalog/products/'.$this->product->id;
        $this->putJson($path, ['personalization_fields' => [$this->imageField, $this->textField]], $this->auth)->assertOk()->assertJsonPath('data.personalization_fields.0.key', 'photo');
        $this->getJson($this->base.'/products/mug?lang=ar')->assertOk()->assertJsonPath('data.has_personalization', true)->assertJsonPath('data.personalization_fields.1.label', 'الاسم')->assertJsonMissingPath('data.personalization_fields.1.labels');
        foreach ([[$this->textField, $this->textField], [array_merge($this->textField, ['key' => 'BAD'])], [array_merge($this->textField, ['type' => 'html'])], [array_merge($this->textField, ['max_length' => 1001])]] as $fields) {
            $this->putJson($path, ['name' => 'Do not save', 'personalization_fields' => $fields], $this->auth)->assertUnprocessable();
        }
        $this->putJson($path, ['price' => 120], $this->auth)->assertOk()->assertJsonCount(2, 'data.personalization_fields');
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'name' => 'Mug']);
    }

    public function test_required_long_unknown_and_control_character_text_rejects_without_cart_or_orders(): void
    {
        foreach ([[], ['engraving' => ' '], ['engraving' => str_repeat('a', 21)], ['engraving' => "bad\x00text"], ['engraving' => 'Alice', 'foreign' => 'value']] as $custom) {
            $lines = [$this->line($custom)];
            $this->postJson($this->base.'/checkout/quote', ['items' => $lines])->assertUnprocessable();
            $this->postJson($this->base.'/checkout', $this->order($lines))->assertUnprocessable();
        }
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_different_cart_customizations_share_stock_and_only_equal_normalized_values_merge(): void
    {
        $input = ['store_product_id' => $this->product->id, 'quantity' => 1, 'personalization' => ['engraving' => 'Alice']];
        $token = $this->postJson($this->base.'/cart/items', $input)->assertCreated()->json('data.token');
        $headers = ['X-Cart-Token' => $token];
        $this->postJson($this->base.'/cart/items', array_merge($input, ['personalization' => ['engraving' => 'Bob']]), $headers)->assertCreated()->assertJsonCount(2, 'data.items');
        $cart = $this->postJson($this->base.'/cart/items', array_merge($input, ['personalization' => ['engraving' => ' Alice ', 'photo' => '']]), $headers)->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.quantity', 2);
        $this->postJson($this->base.'/cart/items', array_merge($input, ['personalization' => ['engraving' => 'Carol']]), $headers)->assertUnprocessable();
        $this->patchJson($this->base.'/cart/items/'.$cart->json('data.items.1.id'), ['quantity' => 2], $headers)->assertUnprocessable();
        $this->getJson($this->base.'/cart', $headers)->assertOk()->assertJsonPath('data.subtotal', '300.00');
    }

    public function test_aggregate_variant_stock_rejects_quote_and_checkout_atomically(): void
    {
        $variant = ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $this->product->id, 'name' => 'Blue', 'is_active' => true, 'price_override' => 125, 'track_inventory' => true, 'stock_quantity' => 2]);
        $lines = [array_merge($this->line(['engraving' => 'Alice'], 2), ['variant_id' => $variant->id]), array_merge($this->line(['engraving' => 'Bob']), ['variant_id' => $variant->id])];
        $this->postJson($this->base.'/checkout/quote', ['items' => $lines])->assertUnprocessable();
        $this->postJson($this->base.'/checkout', $this->order($lines))->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('store_product_variants', ['id' => $variant->id, 'reserved_quantity' => 0]);
    }

    public function test_image_is_private_signed_scoped_and_cannot_be_forged_or_reused_for_another_product(): void
    {
        $image = $this->image();
        $upload = ProductPersonalizationUpload::firstOrFail();
        Storage::disk('local')->assertExists($upload->path);
        $this->assertNotSame($image['token'], $upload->token_hash);
        $this->get($image['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(strtok($image['url'], '?'))->assertForbidden();
        $this->get(str_replace('/'.$this->store->id.'/', '/9999/', $image['url']))->assertForbidden();
        $this->postJson($this->base.'/checkout/quote', ['items' => [$this->line(['engraving' => 'Alice', 'photo' => str_repeat('a', 64)])]])->assertUnprocessable();
        $other = $this->product->replicate()->fill(['slug' => 'other']);
        $other->save();
        $foreign = array_merge($this->line(['engraving' => 'Alice', 'photo' => $image['token']]), ['product_id' => $other->id]);
        $this->postJson($this->base.'/checkout/quote', ['items' => [$foreign]])->assertUnprocessable();
        $otherStore = $this->store->replicate()->fill(['slug' => 'other-store']);
        $otherStore->save();
        $upload->update(['store_id' => $otherStore->id]);
        $this->postJson($this->base.'/checkout/quote', ['items' => [$this->line(['engraving' => 'Alice', 'photo' => $image['token']])]])->assertUnprocessable();
    }

    public function test_upload_rejects_non_image_oversize_dimensions_wrong_field_and_inactive_product(): void
    {
        $path = $this->base.'/products/'.$this->product->id.'/personalization-image';
        foreach ([UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->image('big.png')->size(10241), UploadedFile::fake()->image('wide.png', 4097, 1)] as $file) {
            $this->post($path, ['field_key' => 'photo', 'file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post($path, ['field_key' => 'engraving', 'file' => UploadedFile::fake()->image('image.png')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->product->update(['is_active' => false]);
        $this->post($path, ['field_key' => 'photo', 'file' => UploadedFile::fake()->image('image.png')], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertDatabaseCount('product_personalization_uploads', 0);
    }

    public function test_order_and_bridge_keep_immutable_snapshots_and_idempotent_replay_retains_images(): void
    {
        $image = $this->image();
        $custom = ['engraving' => ' Alice ', 'photo' => $image['token']];
        $payload = $this->order([$this->line($custom)]);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $quote = $this->postJson($this->base.'/checkout/quote', ['items' => $payload['items']])->assertOk()->assertJsonPath('data.items.0.personalization.0.value', 'Alice')->json('data.totals');
        $this->assertNull(ProductPersonalizationUpload::firstOrFail()->claimed_at);
        $first = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertJsonPath('data.items.0.personalization.1.filename', 'customer.png')->assertJsonMissingPath('data.items.0.personalization.1.token');
        foreach ($quote as $key => $value) {
            $first->assertJsonPath('data.'.$key, $value);
        }
        $this->assertNotNull(ProductPersonalizationUpload::firstOrFail()->claimed_at);
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::with('items')->findOrFail($first->json('data.id'));
        $bridge = app(StorefrontOrderBridge::class)->bridge($order, $this->store);
        $this->assertSame('Alice', $bridge->storefront_items[0]['personalization'][0]['value']);
        $this->product->update(['personalization_fields' => [], 'is_active' => false, 'price' => 900]);
        $this->travel(8)->days();
        $this->artisan('personalization:prune')->assertSuccessful();
        $replay = $this->postJson($this->base.'/checkout', $payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.items.0.personalization.0.value', 'Alice');
        $this->get($replay->json('data.items.0.personalization.1.url'))->assertOk();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 1]);
    }

    public function test_unused_expired_images_are_removed_and_quote_rejects_them(): void
    {
        $image = $this->image();
        $upload = ProductPersonalizationUpload::firstOrFail();
        $this->travel(8)->days();
        $this->get($image['url'])->assertForbidden();
        $this->postJson($this->base.'/checkout/quote', ['items' => [$this->line(['engraving' => 'Alice', 'photo' => $image['token']])]])->assertUnprocessable();
        $this->artisan('personalization:prune')->assertSuccessful();
        Storage::disk('local')->assertMissing($upload->path);
        $this->assertDatabaseCount('product_personalization_uploads', 0);
    }

    public function test_failed_later_line_rolls_back_upload_claim_and_reservations(): void
    {
        $image = $this->image();
        $this->postJson($this->base.'/checkout', $this->order([$this->line(['engraving' => 'Alice', 'photo' => $image['token']]), $this->line(['engraving' => 'Bob'], 3)]))->assertUnprocessable();
        $this->assertNull(ProductPersonalizationUpload::firstOrFail()->claimed_at);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 0]);
    }

    public function test_claim_is_rolled_back_when_a_later_existing_cart_price_changed(): void
    {
        $image = $this->image();
        $other = $this->product->replicate()->fill(['slug' => 'later-product', 'personalization_fields' => []]);
        $other->save();
        $token = $this->postJson($this->base.'/cart/items', ['store_product_id' => $this->product->id, 'quantity' => 1, 'personalization' => ['engraving' => 'Alice', 'photo' => $image['token']]])->assertCreated()->json('data.token');
        $this->postJson($this->base.'/cart/items', ['store_product_id' => $other->id, 'quantity' => 1], ['X-Cart-Token' => $token])->assertCreated();
        $other->update(['price' => 200]);
        $this->postJson($this->base.'/checkout', ['customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test'], ['X-Cart-Token' => $token])->assertUnprocessable();
        $this->assertNull(ProductPersonalizationUpload::firstOrFail()->claimed_at);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertDatabaseCount('store_inventory_movements', 0);
    }

    public function test_multiple_personalized_order_lines_reserve_shared_stock_and_survive_product_deletion(): void
    {
        $image = $this->image();
        $first = $this->line(['engraving' => 'Alice', 'photo' => $image['token']], 2);
        $second = $this->line(['engraving' => 'Bob']);
        $response = $this->postJson($this->base.'/checkout', $this->order([$first, $second]))->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.subtotal', '300.00');
        $this->assertDatabaseHas('products', ['id' => $this->product->id, 'reserved_quantity' => 3]);
        app(CurrentStore::class)->set($this->store);
        app(StoreOrderService::class)->transition(StoreOrder::findOrFail($response->json('data.id')), 'cancelled', $this->store->owner_user_id);
        $this->deleteJson('/api/v1/my-store/catalog/products/'.$this->product->id, [], $this->auth)->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $this->product->id]);
        $detail = $this->getJson('/api/v1/my-store/orders/'.$response->json('data.id'), $this->auth)->assertOk()->assertJsonPath('data.items.0.personalization.0.value', 'Alice')->assertJsonPath('data.items.1.personalization.0.value', 'Bob');
        $this->get($detail->json('data.items.0.personalization.1.url'))->assertOk();
    }

    public function test_guest_cart_merge_retains_personalization_and_merges_only_matching_values(): void
    {
        $auth = $this->postJson($this->base.'/auth/register', ['name' => 'Buyer', 'email' => 'merge@example.test', 'password' => 'password123'])->assertCreated()->json('token');
        $input = ['store_product_id' => $this->product->id, 'quantity' => 1, 'personalization' => ['engraving' => 'Alice']];
        $this->postJson($this->base.'/cart/items', $input, ['Authorization' => 'Bearer '.$auth])->assertCreated();
        $guest = $this->postJson($this->base.'/cart/items', $input)->assertCreated()->json('data.token');
        $this->postJson($this->base.'/cart/items', array_merge($input, ['personalization' => ['engraving' => 'Bob']]), ['X-Cart-Token' => $guest])->assertCreated();
        $this->getJson($this->base.'/cart', ['Authorization' => 'Bearer '.$auth, 'X-Cart-Token' => $guest])->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.items.0.personalization.engraving', 'Alice')->assertJsonPath('data.items.1.personalization.engraving', 'Bob');
    }
}

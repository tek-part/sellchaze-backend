<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreBundlesAppTest extends TestCase
{
    use MerchantAppFixtures, RefreshDatabase;

    private Product $strap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
        $this->strap = $this->makeProduct('Strap', 50);
    }

    private function bundle(array $changes = []): array
    {
        return array_replace(['title' => ['ar' => 'اشترِهما معًا', 'en' => 'Buy them together'], 'product_ids' => [$this->product->id, $this->strap->id], 'discount_percent' => 20, 'active' => true], $changes);
    }

    private function enable(array $bundles, bool $enabled = true): array
    {
        return $this->saveSettings('bundles', ['enabled' => $enabled, 'bundles' => $bundles])->assertOk()->json('data');
    }

    private function quote(array $items, array $changes = []): array
    {
        return $this->postJson($this->storefront.'/checkout/quote', ['items' => $items] + $changes)->assertOk()->json('data');
    }

    public function test_settings_validate_bundles_and_require_coupon_permission(): void
    {
        $this->assertSame(['version' => 1, 'enabled' => false, 'bundles' => []], $this->settings('bundles'));
        $otherOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-bundles', 'currency' => 'EGP', 'status' => 'active']);
        $foreign = $this->makeProduct('Foreign', 10, $other);
        $variant = $this->makeProduct('Shirt', 80);
        ProductVariant::create(['store_id' => $this->store->id, 'store_product_id' => $variant->id, 'name' => 'Red', 'is_active' => true]);
        $engraved = $this->makeProduct('Engraved pen', 30, attributes: ['personalization_fields' => [['key' => 'name', 'type' => 'text', 'label' => ['ar' => 'الاسم', 'en' => 'Name'], 'required' => true, 'max_length' => 20]]]);
        foreach ([[$this->bundle(['product_ids' => [$this->product->id]])], [$this->bundle(['product_ids' => [$this->product->id, $this->product->id]])], [$this->bundle(['product_ids' => [$this->product->id, $foreign->id]])],
            [$this->bundle(['product_ids' => [$this->product->id, $variant->id]])], [$this->bundle(['product_ids' => [$this->product->id, $engraved->id]])], [$this->bundle(['discount_percent' => 95])], [$this->bundle(['active' => false])]] as $bundles) {
            $this->putJson('/api/v1/my-store/apps/bundles/settings', ['version' => 1, 'enabled' => true, 'bundles' => $bundles], $this->auth)->assertUnprocessable();
        }
        $saved = $this->enable([$this->bundle()]);
        $this->assertSame(2, $saved['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $saved['bundles'][0]['id']);
        $this->getJson('/api/v1/my-store/apps/bundles/settings', $this->employee(['store.view', 'store.settings.manage']))->assertForbidden();
        $this->getJson('/api/v1/my-store/apps/bundles/settings', $this->employee(['store.view', 'store.settings.manage', 'store.coupons.manage']))->assertOk();
        $this->getJson($this->storefront.'/context')->assertJsonPath('store.bundles_enabled', true);
    }

    public function test_storefront_lists_bundles_for_a_product_only_while_enabled_and_all_products_are_active(): void
    {
        $this->getJson($this->storefront.'/bundles?product_id='.$this->product->id)->assertOk()->assertJsonPath('data', []);
        $this->enable([$this->bundle()]);
        $this->getJson($this->storefront.'/bundles?product_id='.$this->strap->id)->assertOk()
            ->assertJsonPath('data.0.discount_percent', 20)->assertJsonPath('data.0.title', 'Buy them together')
            ->assertJsonPath('data.0.products.0.name', 'Leather bag')->assertJsonPath('data.0.products.1.price', '50.00')->assertJsonPath('currency', 'EGP');
        $this->getJson($this->storefront.'/bundles')->assertUnprocessable();
        $this->strap->update(['is_active' => false]);
        $this->getJson($this->storefront.'/bundles?product_id='.$this->product->id)->assertJsonPath('data', []);
    }

    public function test_bundle_discount_applies_at_quote_and_placement_when_every_product_is_present(): void
    {
        $this->enable([$this->bundle(), $this->bundle(['title' => ['ar' => 'غير مفعل', 'en' => 'Inactive'], 'discount_percent' => 90, 'active' => false])]);
        $both = [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->strap->id, 'quantity' => 1]];
        $quote = $this->quote($both);
        $this->assertSame('30.00', $quote['totals']['discount_total']);
        $this->assertEquals(['type' => 'bundle', 'method' => 'automatic', 'title' => 'Buy them together', 'amount' => '30.00'], array_intersect_key($quote['discounts'][0], array_flip(['type', 'method', 'title', 'amount'])));
        $this->assertSame([], $this->quote([['product_id' => $this->product->id, 'quantity' => 2]])['discounts']);
        $this->assertSame('60.00', $this->quote([['product_id' => $this->product->id, 'quantity' => 2], ['product_id' => $this->strap->id, 'quantity' => 2]])['totals']['discount_total']);
        $this->assertSame('30.00', $this->quote([['product_id' => $this->product->id, 'quantity' => 3], ['product_id' => $this->strap->id, 'quantity' => 1]])['totals']['discount_total']);

        $id = $this->checkout(['items' => $both])->assertCreated()->json('data.id');
        $order = $this->getJson('/api/v1/my-store/orders/'.$id, $this->auth)->assertOk()->json('data');
        $this->assertSame('30.00', $order['discount_total']);
        $this->assertSame('120.00', $order['grand_total']);
        $this->assertDatabaseCount('coupon_usages', 0);
    }

    public function test_entered_codes_and_larger_automatic_discounts_win_and_disabled_bundles_do_nothing(): void
    {
        $this->enable([$this->bundle()]);
        $both = [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->strap->id, 'quantity' => 1]];
        Coupon::create(['store_id' => $this->store->id, 'code' => 'TEN', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
        $quote = $this->quote($both, ['coupon_code' => 'TEN']);
        $this->assertSame('10.00', $quote['totals']['discount_total']);
        $this->assertSame('TEN', $quote['discounts'][0]['code']);

        $automatic = Coupon::create(['store_id' => $this->store->id, 'method' => 'automatic', 'title' => 'Half off', 'type' => 'percentage', 'value' => 50, 'is_active' => true]);
        $this->assertSame('Half off', $this->quote($both)['discounts'][0]['title']);
        $automatic->update(['is_active' => false]);

        $this->enable([$this->bundle()], false);
        $this->assertSame([], $this->quote($both)['discounts']);
        $this->getJson($this->storefront.'/bundles?product_id='.$this->product->id)->assertJsonPath('data', []);
    }
}

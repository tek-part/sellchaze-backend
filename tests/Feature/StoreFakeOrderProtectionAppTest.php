<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreFakeOrderProtectionAppTest extends TestCase
{
    use MerchantAppFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    private function protect(array $rules, array $changes = []): void
    {
        $this->saveSettings('fake-orders', array_replace(['enabled' => true, 'rules' => $rules], $changes))->assertOk();
    }

    private function merchantOrder(int $id): array
    {
        return $this->getJson('/api/v1/my-store/orders/'.$id, $this->auth)->assertOk()->json('data');
    }

    public function test_settings_validate_rules_and_expose_classifications(): void
    {
        $response = $this->getJson('/api/v1/my-store/apps/fake-orders/settings', $this->auth)->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.rules.repeat_phone.hours', 24)->assertJsonPath('meta.classifications', ['high_risk', 'moderate_risk', 'spam']);
        $base = $response->json('data');
        foreach ([['flag_classification' => 'fraud'], ['rules' => ['blocked_words' => ['enabled' => true, 'words' => []]]], ['rules' => ['open_cod' => ['action' => 'delete']]],
            ['rules' => ['repeat_phone' => ['hours' => 0]]], ['rules' => ['high_quantity' => ['max_units' => 0]]], ['rules' => ['blocked_words' => ['words' => ['x']]]]] as $change) {
            $this->putJson('/api/v1/my-store/apps/fake-orders/settings', array_replace_recursive($base, $change), $this->auth)->assertUnprocessable();
        }
        $this->putJson('/api/v1/my-store/apps/fake-orders/settings', array_replace_recursive($base, ['enabled' => true, 'rules' => ['blocked_words' => ['enabled' => true, 'words' => [' Test ', 'test', 'Fake']]]]), $this->auth)
            ->assertOk()->assertJsonPath('data.rules.blocked_words.words', ['test', 'fake'])->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/my-store/apps/fake-orders/settings', $this->employee(['store.view', 'store.orders.manage']))->assertForbidden();
    }

    public function test_disabled_protection_has_no_effect(): void
    {
        $this->protect(['blocked_words' => ['enabled' => true, 'action' => 'block', 'words' => ['mona']], 'high_quantity' => ['enabled' => true, 'action' => 'block', 'max_units' => 1]], ['enabled' => false]);
        $id = $this->checkout(['items' => [['product_id' => $this->product->id, 'quantity' => 3]]])->assertCreated()->json('data.id');
        $order = $this->merchantOrder($id);
        $this->assertSame([], $order['protection']);
        $this->assertNull($order['review']['classification']);
        $this->assertDatabaseCount('store_order_reviews', 0);
    }

    public function test_repeated_phone_orders_are_flagged_with_the_rule_and_store_isolation(): void
    {
        $this->protect(['repeat_phone' => ['enabled' => true, 'action' => 'flag', 'hours' => 6, 'max_orders' => 1]], ['flag_classification' => 'moderate_risk']);
        $otherOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-protect', 'currency' => 'EGP', 'status' => 'active']);
        $this->order([], $other, $this->makeProduct('Other', 10, $other));
        $this->order(['placed_at' => now()->subHours(7)]);

        $first = $this->checkout()->assertCreated()->json('data.id');
        $this->assertSame([], $this->merchantOrder($first)['protection']);
        $second = $this->checkout()->assertCreated()->json('data.id');
        $order = $this->merchantOrder($second);
        $this->assertSame([['rule' => 'repeat_phone', 'action' => 'flag', 'detail' => ['orders' => 1, 'hours' => 6]]], $order['protection']);
        $this->assertSame('moderate_risk', $order['review']['classification']);
        $this->assertTrue($order['review']['automatic']);
        $this->assertNull($order['review']['actor_id']);
        $this->assertDatabaseHas('store_order_status_changes', ['store_order_id' => $second, 'source' => 'order_protection', 'actor_id' => null]);
        $filtered = $this->getJson('/api/v1/my-store/orders?classification=moderate_risk', $this->auth)->assertOk()->json('data');
        $this->assertSame([$second], array_column($filtered, 'id'));

        // The merchant can still clear the automatic classification with the existing review flow.
        $this->postJson('/api/v1/my-store/orders/'.$second.'/review', ['classification' => null, 'version' => 1], $this->auth)->assertOk();
        $this->assertNull($this->merchantOrder($second)['review']['classification']);
        $this->assertArrayNotHasKey('protection_signals', $this->getJson($this->storefront.'/context')->json());
    }

    public function test_block_rules_refuse_checkout_politely_without_creating_an_order(): void
    {
        $this->protect(['blocked_words' => ['enabled' => true, 'action' => 'block', 'words' => ['fake street']], 'high_quantity' => ['enabled' => true, 'action' => 'block', 'max_units' => 2]],
            ['message' => ['ar' => '', 'en' => 'Please call us to complete this order.']]);
        $this->checkout(['shipping_address' => ['line1' => '1 FAKE Street', 'city' => 'Cairo', 'country' => 'EG']])->assertUnprocessable()
            ->assertJsonPath('errors', ['order_protection' => ['Please call us to complete this order.']]);
        $this->checkout(['items' => [['product_id' => $this->product->id, 'quantity' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('order_protection');
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame(0, (int) $this->product->fresh()->reserved_quantity);
        $this->checkout(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]])->assertCreated();
    }

    public function test_open_cash_on_delivery_orders_and_high_quantity_can_flag_together(): void
    {
        $this->protect(['open_cod' => ['enabled' => true, 'action' => 'flag', 'max_open' => 1], 'high_quantity' => ['enabled' => true, 'action' => 'flag', 'max_units' => 1]]);
        $this->order(['status' => 'delivered']);
        $clean = $this->checkout()->assertCreated()->json('data.id');
        $this->assertSame([], $this->merchantOrder($clean)['protection']);
        StoreOrder::query()->whereKey($clean)->update(['status' => 'confirmed']);
        $id = $this->checkout(['items' => [['product_id' => $this->product->id, 'quantity' => 2]]])->assertCreated()->json('data.id');
        $order = $this->merchantOrder($id);
        $this->assertSame(['open_cod', 'high_quantity'], array_column($order['protection'], 'rule'));
        $this->assertSame('high_risk', $order['review']['classification']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreGiftCard;
use App\Models\StoreGiftCardTransaction;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StorePaymentTransaction;
use App\Models\User;
use App\Services\Commerce\GiftCards;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gift cards: a tender applied after discounts, delivery and tax. Codes are shown once and stored
 * as a keyed digest; balances move only through locked, logged transactions.
 */
class StoreGiftCardsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://alpha.sellchase.com/api/v1/storefront';

    private User $owner;

    private User $otherOwner;

    private Store $store;

    private Store $other;

    private Product $tee;   // 100.00 + 30.00 delivery = 130.00

    private Product $cap;   // 20.00 + 30.00 delivery = 50.00

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        [$this->owner, $this->store] = $this->makeStore('alpha');
        [$this->otherOwner, $this->other] = $this->makeStore('beta');
        $this->tee = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '100.00', 'is_active' => true]);
        $this->cap = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Cap', 'slug' => 'cap', 'price' => '20.00', 'is_active' => true]);
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => 30]);
    }

    /** @return array{0: User, 1: Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return [$user, $store];
    }

    private function as(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/stores/{$this->store->id}/gift-cards{$path}";
    }

    /** @return array{0: StoreGiftCard, 1: string} */
    private function card(string $value, ?Store $store = null): array
    {
        $issued = app(GiftCards::class)->issue($store ?? $this->store, ['initial_value' => $value], $this->owner->id);

        return [$issued['card'], $issued['code']];
    }

    /** @param list<string> $cards */
    private function quote(Product $product, array $cards): TestResponse
    {
        return $this->postJson(self::HOST.'/checkout/quote', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'gift_cards' => $cards]);
    }

    /** @param list<string> $cards */
    private function place(Product $product, array $cards, ?string $method = null): TestResponse
    {
        return $this->postJson(self::HOST.'/checkout', array_filter([
            'cart_mode' => 'direct', 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'gift_cards' => $cards,
            'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+201000000001', 'payment_method' => $method,
            'shipping_address' => ['line1' => '1 Street', 'city' => 'Cairo', 'country' => 'EG'],
        ], fn ($value) => $value !== null));
    }

    private function balance(StoreGiftCard $card): string
    {
        return (string) StoreGiftCard::forStore($card->store_id)->whereKey($card->id)->value('balance');
    }

    public function test_issue_returns_the_code_once_and_stores_only_a_keyed_digest(): void
    {
        $response = $this->as($this->owner)->postJson($this->url(), ['initial_value' => 75, 'customer_name' => 'Mona', 'customer_email' => 'mona@example.test', 'note' => 'Birthday'])
            ->assertCreated()->assertJsonPath('data.balance', '75.00')->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.state', 'active');
        $code = (string) $response->json('code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}$/', $code);
        $this->assertSame(substr(str_replace('-', '', $code), -4), $response->json('data.last4'));
        $this->assertArrayNotHasKey('code_digest', $response->json('data'));

        $card = StoreGiftCard::forStore($this->store)->sole();
        $this->assertSame(app(GiftCards::class)->digest($this->store->id, $code), $card->code_digest);
        $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($card->getAttributes()));
        $this->assertDatabaseHas('store_gift_card_transactions', ['gift_card_id' => $card->id, 'reason' => 'issued', 'amount' => '75.00', 'actor_id' => $this->owner->id]);

        // Never again: not in the list, not in the detail.
        $list = $this->as($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 1);
        $show = $this->as($this->owner)->getJson($this->url('/'.$card->id))->assertOk()->assertJsonPath('transactions.0.reason', 'issued');
        foreach ([$list, $show] as $later) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), $later->getContent());
            $this->assertStringNotContainsString($code, $later->getContent());
        }

        $this->as($this->owner)->postJson($this->url(), ['initial_value' => 0])->assertStatus(422)->assertJsonValidationErrors('initial_value');
        $this->as($this->owner)->postJson($this->url(), ['initial_value' => 10, 'expires_at' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('expires_at');
    }

    public function test_check_returns_a_masked_balance_and_one_generic_error(): void
    {
        [$card, $code] = $this->card('40.00');
        $this->postJson(self::HOST.'/checkout/gift-card/check', ['code' => strtolower(str_replace('-', ' ', $code))])->assertOk()
            ->assertJsonPath('data.last4', $card->last4)->assertJsonPath('data.balance', '40.00')->assertJsonMissingPath('data.code');

        [, $foreign] = $this->card('40.00', $this->other);
        [$disabled, $disabledCode] = $this->card('40.00');
        $disabled->update(['status' => 'disabled']);
        [$expired, $expiredCode] = $this->card('40.00');
        $expired->update(['expires_at' => now()->subMinute()]);
        foreach (['NOPE-NOPE-NOPE-NOPE', $foreign, $disabledCode, $expiredCode] as $bad) {
            $this->postJson(self::HOST.'/checkout/gift-card/check', ['code' => $bad])->assertStatus(422)->assertJsonPath('errors.code.0', GiftCards::INVALID);
        }
    }

    public function test_check_is_rate_limited_hard(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::HOST.'/checkout/gift-card/check', ['code' => 'AAAA-BBBB-CCCC-DDD'.$i])->assertStatus(422);
        }
        $this->postJson(self::HOST.'/checkout/gift-card/check', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertStatus(429);
    }

    public function test_quote_applies_cards_in_order_for_partial_and_full_coverage(): void
    {
        [$small, $smallCode] = $this->card('50.00');
        [$big, $bigCode] = $this->card('100.00');

        $this->quote($this->tee, [$smallCode])->assertOk()
            ->assertJsonPath('data.totals.grand_total', '130.00')
            ->assertJsonPath('data.totals.gift_card_total', '50.00')
            ->assertJsonPath('data.amount_due', '80.00')
            ->assertJsonPath('data.gift_cards.0.last4', $small->last4)
            ->assertJsonPath('data.gift_cards.0.applied', '50.00')
            ->assertJsonPath('data.gift_cards.0.balance_after', '0.00');

        $this->quote($this->tee, [strtolower($smallCode), str_replace('-', '', $bigCode)])->assertOk()
            ->assertJsonPath('data.totals.grand_total', '130.00')
            ->assertJsonPath('data.amount_due', '0.00')
            ->assertJsonPath('data.gift_cards.1.last4', $big->last4)
            ->assertJsonPath('data.gift_cards.1.applied', '80.00')
            ->assertJsonPath('data.gift_cards.1.balance_after', '20.00');

        // Quoting never consumes a balance.
        $this->assertSame('50.00', $this->balance($small));
        $this->quote($this->tee, ['A', 'B', 'C', 'D'])->assertStatus(422)->assertJsonValidationErrors('gift_cards');
    }

    public function test_full_coverage_order_is_paid_by_gift_card_without_a_payment_method(): void
    {
        [$small, $smallCode] = $this->card('50.00');
        [$big, $bigCode] = $this->card('100.00');

        $response = $this->place($this->tee, [$smallCode, $bigCode])->assertCreated()
            ->assertJsonPath('data.payment_method', 'gift_card')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.grand_total', '130.00')
            ->assertJsonPath('data.gift_card_total', '130.00')
            ->assertJsonPath('data.amount_due', '0.00')
            ->assertJsonPath('data.gift_cards.0.last4', $small->last4)
            ->assertJsonPath('payment.status', 'paid');
        $order = StoreOrder::forStore($this->store)->findOrFail($response->json('data.id'));

        $this->assertSame('0.00', $this->balance($small));
        $this->assertSame('20.00', $this->balance($big));
        $this->assertDatabaseHas('store_gift_card_transactions', ['gift_card_id' => $small->id, 'store_order_id' => $order->id, 'reason' => 'redeemed', 'amount' => '-50.00', 'balance_after' => '0.00']);
        $this->assertDatabaseHas('store_gift_card_transactions', ['gift_card_id' => $big->id, 'store_order_id' => $order->id, 'reason' => 'redeemed', 'amount' => '-80.00', 'balance_after' => '20.00']);
        $this->assertSame(0, StorePaymentTransaction::query()->where('store_order_id', $order->id)->count());
        $this->as($this->owner)->getJson("/api/v1/stores/{$this->store->id}/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.gift_card_total', '130.00')->assertJsonPath('data.amount_due', '0.00');
    }

    public function test_partial_coverage_charges_the_rest_through_the_chosen_method(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => []]);
        [$card, $code] = $this->card('50.00');

        $response = $this->place($this->tee, [$code], 'bank_transfer')->assertCreated()
            ->assertJsonPath('data.payment_method', 'bank_transfer')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.grand_total', '130.00')
            ->assertJsonPath('data.amount_due', '80.00');
        $this->assertSame('80.00', (string) StorePaymentTransaction::query()->where('store_order_id', $response->json('data.id'))->value('amount'));
        $this->assertSame('0.00', $this->balance($card));

        // Without a method, a remaining balance cannot be "paid" by the gift card sentinel.
        [, $other] = $this->card('10.00');
        $this->place($this->tee, [$other], 'gift_card')->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_a_payment_method_adjustment_applies_only_to_what_that_method_collects(): void
    {
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'bank_transfer', 'enabled' => true, 'credentials' => [],
            'presentation' => ['price_adjustment' => ['enabled' => true, 'type' => 'percentage', 'direction' => 'add', 'value' => '10']]]);
        [, $code] = $this->card('50.00');
        $quote = $this->postJson(self::HOST.'/checkout/quote', ['items' => [['product_id' => $this->tee->id, 'quantity' => 1]], 'gift_cards' => [$code], 'payment_method' => 'bank_transfer'])->assertOk()
            ->assertJsonPath('data.totals.payment_adjustment_total', '8.00')
            ->assertJsonPath('data.totals.grand_total', '138.00')
            ->assertJsonPath('data.amount_due', '88.00');

        $response = $this->postJson(self::HOST.'/checkout', ['cart_mode' => 'direct', 'items' => [['product_id' => $this->tee->id, 'quantity' => 1]], 'gift_cards' => [$code],
            'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test', 'customer_phone' => '+201000000001', 'payment_method' => 'bank_transfer',
            'payment_adjustment_token' => $quote->json('data.payment_adjustment_token'), 'shipping_address' => ['line1' => '1 Street', 'city' => 'Cairo', 'country' => 'EG']])
            ->assertCreated()->assertJsonPath('data.grand_total', '138.00')->assertJsonPath('data.amount_due', '88.00');
        $this->assertSame('88.00', (string) StorePaymentTransaction::query()->where('store_order_id', $response->json('data.id'))->value('amount'));

        // Fully covered: no method collects anything, so no adjustment and no token.
        [, $big] = $this->card('200.00');
        $this->postJson(self::HOST.'/checkout/quote', ['items' => [['product_id' => $this->tee->id, 'quantity' => 1]], 'gift_cards' => [$big], 'payment_method' => 'bank_transfer'])->assertOk()
            ->assertJsonPath('data.totals.payment_adjustment_total', '0.00')->assertJsonPath('data.totals.grand_total', '130.00')
            ->assertJsonPath('data.amount_due', '0.00')->assertJsonPath('data.payment_adjustment_token', null);
    }

    public function test_placement_rechecks_balances_so_a_stale_quote_cannot_overspend(): void
    {
        [$card, $code] = $this->card('60.00');
        // Both shoppers were quoted the full 60.00 before either order was placed.
        $this->quote($this->cap, [$code])->assertOk()->assertJsonPath('data.gift_cards.0.applied', '50.00');
        $this->quote($this->tee, [$code])->assertOk()->assertJsonPath('data.gift_cards.0.applied', '60.00');

        $this->place($this->cap, [$code])->assertCreated()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.gift_card_total', '50.00');
        // Second order: only the remaining 10.00 is applied; the rest is cash on delivery.
        $this->place($this->tee, [$code])->assertCreated()
            ->assertJsonPath('data.gift_card_total', '10.00')->assertJsonPath('data.amount_due', '120.00')
            ->assertJsonPath('data.payment_method', 'cod')->assertJsonPath('data.payment_status', 'pending');
        $this->assertSame('0.00', $this->balance($card));
        // A third order finds an empty card and is refused without touching anything.
        $this->place($this->cap, [$code])->assertStatus(422)->assertJsonPath('errors', ['gift_cards.0' => [GiftCards::INVALID]]);
        $this->assertEqualsWithDelta(-60.0, (float) StoreGiftCardTransaction::forStore($this->store)->where('reason', 'redeemed')->sum('amount'), 0.001);
        $this->assertSame(2, StoreOrder::forStore($this->store)->count());
    }

    public function test_expired_disabled_foreign_and_other_currency_cards_are_rejected(): void
    {
        [$expired, $expiredCode] = $this->card('100.00');
        $expired->update(['expires_at' => now()->subMinute()]);
        [$disabled, $disabledCode] = $this->card('100.00');
        $disabled->update(['status' => 'disabled']);
        [, $foreignCode] = $this->card('100.00', $this->other);
        [$egp, $egpCode] = $this->card('100.00');
        $egp->forceFill(['currency' => 'EGP'])->save();

        foreach ([$expiredCode, $disabledCode, $foreignCode, $egpCode] as $code) {
            $this->quote($this->tee, [$code])->assertStatus(422)->assertJsonPath('errors', ['gift_cards.0' => [GiftCards::INVALID]]);
            $this->place($this->tee, [$code])->assertStatus(422)->assertJsonPath('errors', ['gift_cards.0' => [GiftCards::INVALID]]);
        }
        $this->assertSame(0, StoreOrder::forStore($this->store)->count());
        $this->assertSame(0, StoreGiftCardTransaction::query()->withoutGlobalScopes()->where('reason', 'redeemed')->count());
    }

    public function test_cancellation_restores_each_card_once(): void
    {
        [$small, $smallCode] = $this->card('50.00');
        [$big, $bigCode] = $this->card('100.00');
        $id = $this->place($this->tee, [$smallCode, $bigCode])->assertCreated()->json('data.id');

        $this->as($this->owner)->patchJson("/api/v1/my-store/orders/{$id}/status", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('50.00', $this->balance($small));
        $this->assertSame('100.00', $this->balance($big));

        // Idempotent: a second restore (retry, refund path) adds nothing.
        $order = StoreOrder::forStore($this->store)->findOrFail($id);
        app(GiftCards::class)->restore($order, $this->owner->id);
        $this->as($this->owner)->patchJson("/api/v1/my-store/orders/{$id}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('50.00', $this->balance($small));
        $this->assertSame('100.00', $this->balance($big));
        $this->assertSame(2, StoreGiftCardTransaction::forStore($this->store)->where('store_order_id', $id)->where('reason', 'restored')->count());
        $this->assertDatabaseHas('store_gift_card_transactions', ['gift_card_id' => $big->id, 'reason' => 'restored', 'amount' => '80.00', 'balance_after' => '100.00', 'actor_id' => $this->owner->id]);
    }

    public function test_merchant_adjusts_disables_filters_and_searches(): void
    {
        $issued = $this->as($this->owner)->postJson($this->url(), ['initial_value' => 100, 'customer_name' => 'Mona Ali', 'customer_email' => 'mona@example.test'])->assertCreated();
        $id = $issued->json('data.id');
        $last4 = $issued->json('data.last4');
        $this->card('20.00');

        $this->as($this->owner)->postJson($this->url("/{$id}/adjust"), ['amount' => '-30.50', 'reason' => 'Damaged item credit removed'])->assertOk()->assertJsonPath('data.balance', '69.50');
        $this->assertDatabaseHas('store_gift_card_transactions', ['gift_card_id' => $id, 'reason' => 'adjusted', 'amount' => '-30.50', 'balance_after' => '69.50', 'note' => 'Damaged item credit removed', 'actor_id' => $this->owner->id]);
        $this->as($this->owner)->postJson($this->url("/{$id}/adjust"), ['amount' => '-100', 'reason' => 'Too much'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->as($this->owner)->postJson($this->url("/{$id}/adjust"), ['amount' => '5'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->owner)->postJson($this->url("/{$id}/adjust"), ['amount' => '0', 'reason' => 'Nothing'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->as($this->owner)->getJson($this->url("/{$id}"))->assertOk()->assertJsonPath('transactions.0.reason', 'adjusted')->assertJsonPath('transactions.0.actor_name', $this->owner->name);

        $this->as($this->owner)->putJson($this->url("/{$id}"), ['status' => 'disabled', 'note' => 'Lost', 'balance' => '999'])->assertOk()
            ->assertJsonPath('data.state', 'disabled')->assertJsonPath('data.balance', '69.50')->assertJsonPath('data.note', 'Lost');
        $this->as($this->owner)->getJson($this->url().'?status=disabled')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->as($this->owner)->getJson($this->url().'?status=active')->assertOk()->assertJsonPath('meta.total', 1);
        $this->as($this->owner)->getJson($this->url().'?search=mona')->assertOk()->assertJsonPath('meta.total', 1);
        $this->as($this->owner)->getJson($this->url().'?search='.strtolower($last4))->assertOk()->assertJsonPath('data.0.last4', $last4);
        $this->as($this->owner)->getJson($this->url().'?sort_by=balance&sort_direction=asc')->assertOk()->assertJsonPath('data.0.balance', '20.00');
        $this->as($this->owner)->putJson($this->url("/{$id}"), ['status' => 'active'])->assertOk()->assertJsonPath('data.state', 'active');
    }

    public function test_store_isolation(): void
    {
        [$card] = $this->card('100.00');
        [$foreign] = $this->card('25.00', $this->other);

        $this->as($this->otherOwner)->getJson($this->url())->assertForbidden();
        $this->as($this->otherOwner)->getJson('/api/v1/my-store/gift-cards')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $foreign->id);
        $this->as($this->otherOwner)->getJson("/api/v1/my-store/gift-cards/{$card->id}")->assertNotFound();
        $this->as($this->otherOwner)->postJson("/api/v1/my-store/gift-cards/{$card->id}/adjust", ['amount' => '-100', 'reason' => 'x'])->assertNotFound();
        $this->as($this->otherOwner)->putJson("/api/v1/my-store/gift-cards/{$card->id}", ['status' => 'disabled'])->assertNotFound();
        $this->assertSame('100.00', $this->balance($card));
        $this->assertSame('active', StoreGiftCard::forStore($this->store)->whereKey($card->id)->value('status'));
    }
}

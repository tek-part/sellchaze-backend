<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6E — merchant order management: listing/filtering, details, the status
 * workflow + timeline, internal-note visibility, ownership, and regression that
 * the customer-side order flow still works.
 */
class MerchantOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private Store $alpha;

    private Store $beta;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        [$this->ownerA, $this->alpha] = $this->makeStore('alpha');
        [, $this->beta] = $this->makeStore('beta');
        $this->tee = Product::create([
            'user_id' => $this->alpha->owner_user_id, 'name' => 'Tee', 'slug' => 'tee', 'price' => '50.00', 'is_active' => true,
        ]);
    }

    /** @return array{0:User,1:Store} */
    private function makeStore(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create([
            'owner_user_id' => $user->id, 'owner_type' => 'merchant',
            'name' => ucfirst($slug), 'slug' => $slug, 'currency' => 'USD', 'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return [$user, $store];
    }

    private function owner(User $user): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($user));
    }

    /** @return array{id:int, number:string, auth:array<string,string>} */
    private function placeOrder(string $email = 'c@example.com'): array
    {
        $host = "http://{$this->alpha->slug}.sellchase.com";
        $token = $this->postJson($host.'/api/v1/storefront/auth/register', [
            'name' => 'C', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson($host.'/api/v1/storefront/cart/items', ['store_product_id' => $this->tee->id, 'quantity' => 1], $auth)->assertCreated();
        $order = $this->postJson($host.'/api/v1/storefront/checkout', [
            'customer_name' => 'C', 'customer_email' => $email,
        ], $auth)->assertCreated();

        return ['id' => $order->json('data.id'), 'number' => $order->json('data.order_number'), 'auth' => $auth];
    }

    private function ordersUrl(?int $id = null, string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->alpha->id}/orders".($id ? "/{$id}" : '').$suffix;
    }

    // --------------------------------------------------------------- listing

    public function test_owner_can_list_filter_and_search_orders(): void
    {
        $o = $this->placeOrder();

        $this->owner($this->ownerA)->getJson($this->ordersUrl())->assertOk()->assertJsonPath('meta.total', 1);
        $this->owner($this->ownerA)->getJson($this->ordersUrl().'?status=pending')->assertOk()->assertJsonPath('meta.total', 1);
        $this->owner($this->ownerA)->getJson($this->ordersUrl().'?status=shipped')->assertOk()->assertJsonPath('meta.total', 0);
        $this->owner($this->ownerA)->getJson($this->ordersUrl().'?search='.$o['number'])->assertOk()->assertJsonPath('meta.total', 1);

        // foreign owner cannot list store A's orders
        $this->owner($this->beta->owner)->getJson($this->ordersUrl())->assertStatus(403);
    }

    public function test_owner_can_view_order_details(): void
    {
        $o = $this->placeOrder();

        $this->owner($this->ownerA)->getJson($this->ordersUrl($o['id']))
            ->assertOk()
            ->assertJsonPath('data.order_number', $o['number'])
            ->assertJsonPath('data.grand_total', '50.00')
            ->assertJsonCount(1, 'data.items');

        $this->owner($this->beta->owner)->getJson($this->ordersUrl($o['id']))->assertStatus(403);
    }

    // ------------------------------------------------------- status workflow

    public function test_valid_transitions_are_recorded_in_the_timeline(): void
    {
        $o = $this->placeOrder();

        $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => 'confirmed', 'note' => 'called customer'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $detail = $this->owner($this->ownerA)->getJson($this->ordersUrl($o['id']))->assertOk();
        $detail->assertJsonPath('data.timeline.0.from_status', 'pending')
            ->assertJsonPath('data.timeline.0.to_status', 'confirmed')
            ->assertJsonPath('data.timeline.0.notes', 'called customer')
            ->assertJsonPath('data.timeline.0.actor', $this->ownerA->name);

        // continue the happy path
        foreach (['processing', 'shipped', 'delivered'] as $next) {
            $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => $next])
                ->assertOk()->assertJsonPath('data.status', $next);
        }
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $o = $this->placeOrder();

        // illegal jump pending -> shipped
        $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => 'shipped'])->assertStatus(422);

        // walk to delivered, then delivered -> pending is illegal
        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $s) {
            $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => $s])->assertOk();
        }
        $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => 'pending'])->assertStatus(422);
    }

    public function test_status_update_authorization(): void
    {
        $o = $this->placeOrder();
        $this->owner($this->beta->owner)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => 'confirmed'])->assertStatus(403);
    }

    // --------------------------------------------------- internal-note privacy

    public function test_internal_notes_are_never_exposed_to_the_storefront(): void
    {
        $o = $this->placeOrder('priv@example.com');

        $this->owner($this->ownerA)->patchJson($this->ordersUrl($o['id'], '/status'), ['status' => 'confirmed', 'note' => 'SECRET-MEMO'])->assertOk();

        // merchant sees it
        $this->owner($this->ownerA)->getJson($this->ordersUrl($o['id']))->assertOk()->assertSee('SECRET-MEMO');

        // customer storefront view must NOT contain it
        $host = "http://{$this->alpha->slug}.sellchase.com";
        $this->getJson($host."/api/v1/storefront/orders/{$o['number']}", $o['auth'])
            ->assertOk()
            ->assertDontSee('SECRET-MEMO');
    }

    public function test_merchant_can_add_a_standalone_internal_note(): void
    {
        $o = $this->placeOrder('note@example.com');

        $this->owner($this->ownerA)->postJson($this->ordersUrl($o['id'], '/note'), ['note' => 'STANDALONE-NOTE'])->assertOk();

        // recorded on the timeline at the current status, actor = merchant
        $this->owner($this->ownerA)->getJson($this->ordersUrl($o['id']))->assertOk()
            ->assertJsonPath('data.timeline.0.notes', 'STANDALONE-NOTE')
            ->assertJsonPath('data.timeline.0.to_status', 'pending')
            ->assertJsonPath('data.timeline.0.actor', $this->ownerA->name);

        // never exposed to the storefront customer view
        $host = "http://{$this->alpha->slug}.sellchase.com";
        $this->getJson($host."/api/v1/storefront/orders/{$o['number']}", $o['auth'])->assertOk()->assertDontSee('STANDALONE-NOTE');
    }

    // ------------------------------------------------------------ regression

    public function test_customer_self_service_cancel_still_works_and_is_audited(): void
    {
        $o = $this->placeOrder('reg@example.com');
        $host = "http://{$this->alpha->slug}.sellchase.com";

        $this->postJson($host."/api/v1/storefront/orders/{$o['number']}/cancel", [], $o['auth'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // merchant timeline records the cancel with a null (customer-initiated) actor
        $this->owner($this->ownerA)->getJson($this->ordersUrl($o['id']))
            ->assertOk()
            ->assertJsonPath('data.timeline.0.to_status', 'cancelled')
            ->assertJsonPath('data.timeline.0.actor', null);
    }
}

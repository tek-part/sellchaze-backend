<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Scopes\ProductScope;
use App\Models\Store;
use App\Models\StoreTheme;
use Database\Seeders\ThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriticalBusinessJourneyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{token:string, organization_id:int, store_id:int} */
    private function registerCompany(string $kind, string $email, string $company, string $store): array
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => $company.' Owner',
            'email' => $email,
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'registration_role' => $kind,
            'company_name' => $company,
            'store_name' => $store,
        ])->assertCreated()->assertJsonPath('pending_approval', false);

        return [
            'token' => $response->json('access_token'),
            'organization_id' => (int) $response->json('onboarding.organization_id'),
            'store_id' => (int) $response->json('onboarding.store_id'),
        ];
    }

    public function test_registration_theme_storefront_order_and_b2b_procurement_work_as_one_journey(): void
    {
        $this->seed(ThemeSeeder::class);
        $merchant = $this->registerCompany('Merchant', 'merchant-journey@example.com', 'Nile Retail', 'Nile Market');
        $supplier = $this->registerCompany('Supplier', 'supplier-journey@example.com', 'Delta Factory', 'Delta Supply');

        $store = Store::query()->findOrFail($merchant['store_id']);
        $themeId = (int) StoreTheme::query()->where('store_id', $store->id)->where('status', 'active')->valueOrFail('theme_id');
        $themePath = "/api/v2/organizations/{$merchant['organization_id']}/stores/{$store->id}/themes/settings";
        $this->withToken($merchant['token'])->putJson($themePath, [
            'theme_id' => $themeId,
            'settings' => ['primary' => '#0A7A5A', 'products_per_row' => 4],
            'source' => 'autosave',
        ])->assertOk();
        $this->assertDatabaseHas('store_theme_revisions', ['store_id' => $store->id, 'source' => 'autosave']);
        $this->withToken($merchant['token'])
            ->putJson("/api/v2/organizations/{$merchant['organization_id']}/stores/{$store->id}/themes/custom-css", [
                'theme_id' => $themeId,
                'custom_css' => '.store-announcement { color: #0A7A5A; }',
            ])->assertOk()->assertJsonPath('data.custom_css', '#storefront-root .store-announcement {color: #0A7A5A;}');

        $merchantOwnerId = (int) Organization::query()->findOrFail($merchant['organization_id'])
            ->memberships()->where('role', 'owner')->valueOrFail('user_id');
        $product = Product::query()->withoutGlobalScope(ProductScope::class)->create([
            'store_id' => $store->id,
            'user_id' => $merchantOwnerId,
            'name' => 'Journey Product',
            'slug' => 'journey-product',
            'price' => 250,
            'is_active' => true,
            'is_featured' => true,
        ]);

        $this->withToken($merchant['token'])
            ->postJson("/api/v2/organizations/{$merchant['organization_id']}/stores/{$store->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $host = 'http://'.$store->slug.'.'.config('sellchase.storefront.base_domain');
        $this->getJson($host.'/api/v1/storefront')
            ->assertOk()->assertJsonPath('store.id', $store->id)
            ->assertJsonFragment(['name' => 'Journey Product']);
        $order = $this->withHeader('Authorization', '')
            ->postJson($host.'/api/v1/storefront/checkout', [
                'customer_name' => 'Public Buyer',
                'customer_email' => 'public-buyer@example.com',
                'shipping_address' => ['name' => 'Public Buyer', 'line1' => '10 Market Street', 'city' => 'Cairo'],
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.grand_total', '500.00');
        $this->assertNotEmpty($order->json('data.order_number'));

        $connection = $this->withToken($merchant['token'])->withHeader('Idempotency-Key', 'critical-journey-connection')
            ->postJson("/api/v2/organizations/{$merchant['organization_id']}/connections", [
                'target_organization_id' => $supplier['organization_id'],
                'message' => 'We want to source branded packaging from your factory.',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'outgoing');
        $connectionId = $connection->json('data.id');

        $this->withToken($supplier['token'])->withHeader('Idempotency-Key', 'critical-journey-connection-accept')
            ->postJson("/api/v2/organizations/{$supplier['organization_id']}/connections/{$connectionId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.direction', 'incoming');

        $rfq = $this->withToken($merchant['token'])->withHeader('Idempotency-Key', 'critical-journey-rfq')
            ->postJson('/api/v2/procurement/requests', [
                'organization_id' => $merchant['organization_id'],
                'target_supplier_organization_id' => $supplier['organization_id'],
                'title' => 'Supply 1,000 branded boxes',
                'quantity' => 1000,
                'unit' => 'box',
                'status' => 'published',
            ])->assertCreated();
        $requestId = $rfq->json('data.id');

        $quote = $this->withToken($supplier['token'])->withHeader('Idempotency-Key', 'critical-journey-quote')
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes", [
                'supplier_organization_id' => $supplier['organization_id'],
                'amount' => 18000,
                'lead_time_days' => 7,
            ])->assertCreated();
        $quoteId = $quote->json('data.id');

        $this->withToken($merchant['token'])
            ->postJson("/api/v2/procurement/requests/{$requestId}/quotes/{$quoteId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.procurement_request.order.status', 'confirmed');
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'StorePublished']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ConnectionAccepted']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ProcurementOrderCreated']);
    }
}

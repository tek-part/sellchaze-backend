<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Storefront\VisitorCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreCountryRestrictionAppTest extends TestCase
{
    use MerchantAppFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    private function restrict(string $mode, array $countries, array $changes = []): void
    {
        $this->saveSettings('country-restriction', array_replace(['enabled' => true, 'mode' => $mode, 'countries' => $countries], $changes))->assertOk();
    }

    private function quote(array $changes = [], array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->postJson($this->storefront.'/checkout/quote', array_replace(['items' => [['product_id' => $this->product->id, 'quantity' => 1]]], $changes));
    }

    private function visitorCountry(?string $country): void
    {
        $this->app->instance(VisitorCurrency::class, new class($country) extends VisitorCurrency
        {
            public function __construct(private readonly ?string $fixed) {}

            public function country(?string $ip): ?string
            {
                return $ip === '203.0.113.9' ? $this->fixed : null;
            }
        });
    }

    public function test_settings_validate_countries_and_report_capabilities(): void
    {
        $response = $this->getJson('/api/v1/my-store/apps/country-restriction/settings', $this->auth)->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.mode', 'allow')->assertJsonPath('data.countries', []);
        $this->assertContains('EG', $response->json('meta.countries'));
        $this->assertIsBool($response->json('meta.ip_lookup_available'));
        $base = $response->json('data');
        foreach ([['enabled' => true, 'countries' => []], ['countries' => ['XX']], ['countries' => ['EG', 'EG']], ['mode' => 'maybe'], ['message' => ['en' => str_repeat('a', 301)]]] as $change) {
            $this->putJson('/api/v1/my-store/apps/country-restriction/settings', array_replace($base, $change), $this->auth)->assertUnprocessable();
        }
        $this->putJson('/api/v1/my-store/apps/country-restriction/settings', array_replace($base, ['enabled' => true, 'countries' => ['SA', 'EG']]), $this->auth)
            ->assertOk()->assertJsonPath('data.countries', ['EG', 'SA'])->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/my-store/apps/country-restriction/settings', $base, $this->employee(['store.view']))->assertForbidden();
    }

    public function test_disabled_restriction_has_no_effect(): void
    {
        $this->saveSettings('country-restriction', ['enabled' => false, 'mode' => 'allow', 'countries' => ['EG']])->assertOk();
        $this->checkout(['shipping_address' => ['line1' => 'Main road 1', 'city' => 'Austin', 'country' => 'US']])->assertCreated();
        $this->quote(['shipping_country' => 'US'])->assertOk();
        $this->getJson($this->storefront.'/checkout/fields')->assertOk()->assertJsonPath('country_restriction', null);
    }

    public function test_allow_list_is_enforced_at_quote_and_placement_with_a_clear_message(): void
    {
        $this->restrict('allow', ['EG', 'SA']);
        $fields = $this->getJson($this->storefront.'/checkout/fields')->assertOk()
            ->assertJsonPath('country_restriction.mode', 'allow')->assertJsonPath('country_restriction.countries', ['EG', 'SA']);
        $country = collect($fields->json('data'))->firstWhere('key', 'country');
        $this->assertTrue($country['enabled'] && $country['required'] && $country['country_restriction_required']);

        $this->quote()->assertOk();
        $this->quote(['shipping_country' => 'EG'])->assertOk();
        $this->quote(['shipping_country' => 'us'])->assertUnprocessable()->assertJsonValidationErrors('shipping_address.country');

        $rejected = $this->checkout(['shipping_address' => ['line1' => 'Main road 1', 'city' => 'Austin', 'country' => 'US']])->assertUnprocessable()
            ->assertJsonValidationErrors('shipping_address.country');
        $this->assertStringContainsString('does not currently accept orders for this country', $rejected->json('errors')['shipping_address.country'][0]);
        $this->checkout(['shipping_address' => ['line1' => 'Main road 1', 'city' => 'Austin']])->assertUnprocessable()->assertJsonValidationErrors('shipping_address.country');
        $this->assertDatabaseCount('store_orders', 0);
        $this->checkout()->assertCreated();
        $this->assertDatabaseCount('store_orders', 1);

        $this->store->update(['supported_locales' => ['ar', 'en']]);
        $arabic = $this->withHeader('Accept-Language', 'ar')->postJson($this->storefront.'/checkout/quote', ['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'shipping_country' => 'US']);
        $this->assertStringContainsString('عذرًا', $arabic->assertUnprocessable()->json('errors')['shipping_address.country'][0]);
    }

    public function test_block_list_custom_message_and_store_isolation(): void
    {
        $this->restrict('block', ['US'], ['message' => ['ar' => 'لا نشحن إلى هذه الدولة.', 'en' => 'We do not ship to this country yet.']]);
        $this->checkout(['shipping_address' => ['line1' => 'Main road 1', 'city' => 'Austin', 'country' => 'US']])->assertUnprocessable()
            ->assertJsonPath('errors', ['shipping_address.country' => ['We do not ship to this country yet.']]);
        $this->checkout(['shipping_address' => ['line1' => 'Main road 1', 'city' => 'Riyadh', 'country' => 'SA']])->assertCreated();

        $otherOwner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other = Store::create(['owner_user_id' => $otherOwner->id, 'owner_type' => 'merchant', 'name' => 'Open', 'slug' => 'open-store', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'open.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $product = $this->makeProduct('Open product', 20, $other);
        $this->postJson('http://open.sellchase.com/api/v1/storefront/checkout', $this->checkoutPayload(['items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address' => ['line1' => 'Main road 1', 'city' => 'Austin', 'country' => 'US']]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    public function test_delivery_regions_in_refused_countries_are_hidden_and_rejected(): void
    {
        $egypt = (string) Str::uuid();
        $saudi = (string) Str::uuid();
        $this->store->update(['shipping_enabled' => true, 'shipping_flat_rate' => '10.00', 'shipping_configuration' => ['regions_enabled' => true, 'auto_select_region' => false, 'options' => [], 'regions' => [
            ['id' => $egypt, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'], 'country' => 'EG', 'rate' => '10.00', 'enabled' => true, 'position' => 0],
            ['id' => $saudi, 'name' => ['ar' => 'الرياض', 'en' => 'Riyadh'], 'country' => 'SA', 'rate' => '30.00', 'enabled' => true, 'position' => 1],
        ]]]);
        $this->restrict('allow', ['EG']);
        $regions = $this->getJson($this->storefront.'/checkout/fields')->assertOk()->json('shipping.regions');
        $this->assertSame([$egypt], array_column($regions, 'id'));
        $this->quote(['shipping_region_id' => $saudi])->assertUnprocessable()->assertJsonValidationErrors('shipping_address.country');
        $this->quote(['shipping_region_id' => $egypt])->assertOk();
        $this->checkout(['shipping_region_id' => $saudi, 'shipping_address' => ['line1' => 'Road 1']])->assertUnprocessable()->assertJsonValidationErrors('shipping_address.country');
        $this->checkout(['shipping_region_id' => $egypt, 'shipping_address' => ['line1' => 'Road 1']])->assertCreated();
    }

    public function test_optional_visitor_ip_check_only_refuses_a_known_country(): void
    {
        $this->restrict('allow', ['EG'], ['check_ip' => true]);
        $this->visitorCountry('US');
        $this->quote([], ['REMOTE_ADDR' => '203.0.113.9'])->assertUnprocessable()->assertJsonValidationErrors('country_restriction');
        $this->checkout([], ['REMOTE_ADDR' => '203.0.113.9'])->assertUnprocessable()->assertJsonValidationErrors('country_restriction');
        $this->checkout([], ['REMOTE_ADDR' => '198.51.100.7'])->assertCreated();
        $this->visitorCountry('EG');
        $this->checkout([], ['REMOTE_ADDR' => '203.0.113.9'])->assertCreated();

        $this->saveSettings('country-restriction', ['check_ip' => false])->assertOk();
        $this->visitorCountry('US');
        $this->checkout([], ['REMOTE_ADDR' => '203.0.113.9'])->assertCreated();
    }
}

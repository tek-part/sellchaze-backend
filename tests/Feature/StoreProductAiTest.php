<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StoreProductAiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $this->store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'AI store', 'slug' => 'ai-store', 'status' => 'active', 'currency' => 'USD', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar']]);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $owner->id, 'name' => 'Original bag', 'slug' => 'original-bag', 'price' => 20, 'is_active' => false]);
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($owner));
        config(['services.product_ai.api_key' => 'fake-product-ai-key', 'services.product_ai.model' => 'configured-product-model']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function fakeResponse(array $payload, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response($payload, $status)]);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['locale' => 'ar', 'parts' => ['name', 'description', 'features', 'faqs', 'reviews'], 'counts' => ['features' => 3, 'faqs' => 3, 'reviews' => 3], 'language' => 'Arabic', 'dialect' => 'Egyptian', 'country' => 'Egypt', 'description' => 'A washable cotton shopping bag with reinforced handles.', 'review_source' => 'The handles were comfortable. The cotton fabric was easy to wash.'], $changes);
    }

    private function copy(array $changes = []): array
    {
        return array_replace(['name' => 'Everyday bag', 'description' => ['Washable cotton.'], 'features' => ['Reinforced handles.'], 'faqs' => [['question' => 'Is it washable?', 'answer' => 'Yes.']], 'reviews' => ['Feedback mentions comfortable handles.']], $changes);
    }

    private function response(array $copy): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($copy)]]]]];
    }

    public function test_generation_returns_only_a_transient_localized_copy_and_keeps_product_and_reviews_unchanged(): void
    {
        $this->fakeResponse($this->response($this->copy()));
        $before = $this->product->fresh()->getRawOriginal();
        $this->getJson('/api/v1/my-store/catalog/products/ai-draft')->assertOk()->assertJsonPath('available', true)->assertDontSee('fake-product-ai-key');
        foreach (['/ai-draft', '/'.$this->product->id.'/ai-draft'] as $suffix) {
            $this->postJson('/api/v1/my-store/catalog/products'.$suffix, $this->input())->assertOk()->assertJsonPath('locale', 'ar')->assertJsonPath('data.name', 'Everyday bag')
                ->assertJsonPath('review_provenance', 'ai_summary_of_supplied_feedback')->assertJsonMissingPath('data.price');
        }
        Http::assertSent(function ($request) {
            $brief = json_decode($request['input'][1]['content'], true);

            return $request['store'] === false && $request['max_output_tokens'] === 6000
                && $request['model'] === 'configured-product-model' && $request['text']['format']['strict'] === true
                && $brief['locale'] === 'ar' && $brief['country'] === 'Egypt' && $brief['dialect'] === 'Egyptian'
                && $brief['counts']['features'] === 3 && count($brief['parts']) === 5;
        });
        $this->assertSame($before, $this->product->fresh()->getRawOriginal());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('store_funnels', 0);
        $this->assertDatabaseCount('store_pages', 0);
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_unselected_parts_and_zero_counts_are_enforced(): void
    {
        $input = $this->input(['parts' => ['features'], 'counts' => ['features' => 0, 'faqs' => 0, 'reviews' => 0], 'review_source' => '']);
        $empty = ['name' => null, 'description' => [], 'features' => [], 'faqs' => [], 'reviews' => []];
        $this->fakeResponse($this->response($empty));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $input)->assertOk()->assertJsonPath('data', $empty);
        $this->fakeResponse($this->response($this->copy()));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $input)->assertStatus(502);
    }

    public function test_no_review_source_means_no_generated_reviews_or_verification_metadata(): void
    {
        $this->fakeResponse($this->response($this->copy()));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input(['review_source' => '']))->assertStatus(502);
        $this->fakeResponse($this->response($this->copy(['reviews' => [['text' => 'Great!', 'verified_purchase' => true, 'rating' => 5]]])));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(502);
        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_validation_rejects_unsupported_locale_parts_counts_and_large_or_missing_briefs_without_provider_calls(): void
    {
        Http::fake();
        foreach ([['locale' => 'fr'], ['parts' => []], ['parts' => ['price']], ['parts' => ['name', 'name']], ['counts' => ['features' => 11, 'faqs' => 3, 'reviews' => 3]], ['counts' => ['features' => -1, 'faqs' => 3, 'reviews' => 3]], ['description' => ''], ['description' => str_repeat('x', 6001)], ['review_source' => str_repeat('x', 4001)]] as $changes) {
            $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input($changes))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_missing_configuration_disables_generation_without_spending_quota(): void
    {
        config(['services.product_ai.api_key' => '', 'services.product_ai.model' => '']);
        Http::fake();
        $this->getJson('/api/v1/my-store/catalog/products/ai-draft')->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, RateLimiter::attempts('product-ai:'.$this->store->id));
    }

    public function test_foreign_store_and_foreign_product_are_rejected_before_provider_calls(): void
    {
        Http::fake();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $other->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-ai', 'status' => 'active', 'currency' => 'USD']);
        $foreign = Product::withoutGlobalScopes()->create(['store_id' => $store->id, 'user_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'price' => 10]);
        $this->postJson('/api/v1/my-store/catalog/products/'.$foreign->id.'/ai-draft', $this->input())->assertNotFound();
        $this->postJson('/api/v1/stores/'.$store->id.'/catalog/products/ai-draft', $this->input())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_action_permissions_are_enforced_independently_of_store_access(): void
    {
        Http::fake();
        $this->store->owner->syncRoles([]);
        $this->store->owner->givePermissionTo('store.view');
        $this->getJson('/api/v1/my-store/catalog/products/ai-draft')->assertForbidden();
        $this->postJson('/api/v1/my-store/catalog/products/'.$this->product->id.'/ai-draft', $this->input())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_employee_and_admin_share_the_owners_budget_and_exact_action_grants(): void
    {
        config(['services.product_ai.per_day' => 1]);
        $this->fakeResponse($this->response($this->copy()));
        $employee = User::factory()->create(['parent_user_id' => $this->store->owner_user_id, 'is_active' => true, 'pending_approval' => false]);
        $employee->assignRole('Employee');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($employee));
        $this->postJson('/api/v1/my-store/catalog/products/'.$this->product->id.'/ai-draft', $this->input())->assertForbidden();
        $employee->givePermissionTo('products-edit');
        Cache::flush();
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertForbidden();
        $this->postJson('/api/v1/my-store/catalog/products/'.$this->product->id.'/ai-draft', $this->input())->assertOk();
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->store->owner));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(429);
        $admin = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $admin->assignRole('Admin');
        $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($admin));
        $this->postJson('/api/v1/stores/'.$this->store->id.'/catalog/products/ai-draft', $this->input())->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_store_lock_and_shared_create_edit_quota_count_failures_without_automatic_provider_retries(): void
    {
        config(['services.product_ai.per_day' => 1]);
        $this->fakeResponse(['secret' => 'provider-body-must-stay-private'], 500);
        $key = 'product-ai:'.$this->store->id;
        $lock = Cache::lock($key.':lock', 90);
        $this->assertTrue($lock->get());
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(429);
        Http::assertNothingSent();
        $lock->release();
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(502)->assertDontSee('provider-body-must-stay-private');
        $this->postJson('/api/v1/my-store/catalog/products/'.$this->product->id.'/ai-draft', $this->input())->assertStatus(429)->assertHeader('Retry-After');
        Http::assertSentCount(1);
    }

    public function test_refusal_partial_malformed_and_oversized_outputs_do_not_mutate_the_catalog(): void
    {
        $payloads = [
            ['status' => 'incomplete', 'output' => []],
            ['status' => 'completed', 'output' => 'bad'],
            ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'not-json']]]]],
            $this->response($this->copy(['features' => array_fill(0, 11, 'Too many')])),
            $this->response($this->copy(['name' => str_repeat('x', 256)])),
            $this->response($this->copy(['faqs' => [['question' => 'Q', 'answer' => 'A', 'url' => 'https://bad.test']]])),
        ];
        foreach ($payloads as $payload) {
            $this->fakeResponse($payload);
            $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(502);
        }
        $this->fakeResponse(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'private provider detail']]]]]);
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertUnprocessable()->assertDontSee('private provider detail');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('private connection error'));
        $this->postJson('/api/v1/my-store/catalog/products/ai-draft', $this->input())->assertStatus(504)->assertDontSee('private connection error');
        $this->assertSame('Original bag', $this->product->fresh()->name);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('store_pages', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreContentPage;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\StoreContent\ContentPageSchema;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E1: content pages keep the `{en:{}, ar:{}}` shape, validated per locale/field. */
class ContentPageValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->store = Store::create([
            'owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Nike', 'slug' => 'nike',
            'currency' => 'USD', 'status' => 'active', 'default_locale' => 'en', 'supported_locales' => ['en', 'ar'],
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'nike.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
    }

    private function api(): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($this->owner));
    }

    public function test_schema_flags_translatable_fields(): void
    {
        $fields = collect(ContentPageSchema::fields('about'))->keyBy('key');
        $this->assertTrue($fields['heading']['translatable']);
        $this->assertTrue($fields['story']['translatable']);         // lines
        $this->assertFalse($fields['hero_image']['translatable']);   // image
        $this->assertFalse($fields['values']['translatable']);       // repeater itself
        $this->assertTrue(collect($fields['values']['item'])->keyBy('key')['title']['translatable']);

        $contact = collect(ContentPageSchema::fields('contact'))->keyBy('key');
        $this->assertFalse($contact['show_form']['translatable']);
        $this->assertFalse($contact['map_embed']['translatable']);

        $this->api()->getJson('/api/v1/my-store/content')
            ->assertOk()
            ->assertJsonPath('locales.default', 'en')
            ->assertJsonPath('locales.supported', ['en', 'ar']);
    }

    public function test_valid_localized_payload_is_stored_verbatim_and_served_per_locale(): void
    {
        $payload = [
            'en' => ['heading' => 'About us', 'story' => ['Line one', 'Line two'], 'values' => [['title' => 'Quality', 'body' => 'Always']], 'hero_image' => 'https://cdn/x.jpg'],
            'ar' => ['heading' => 'من نحن', 'story' => "سطر ١\nسطر ٢", 'values' => []],
        ];
        $this->api()->putJson('/api/v1/my-store/content/about', ['data' => $payload, 'is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.data.en.heading', 'About us')
            ->assertJsonPath('data.data.ar.heading', 'من نحن')
            ->assertJsonPath('data.data.ar.story', ['سطر ١', 'سطر ٢']);

        $row = StoreContentPage::query()->where('store_id', $this->store->id)->where('key', 'about')->first();
        $this->assertSame('Quality', $row->data['en']['values'][0]['title']);
        $this->assertSame('https://cdn/x.jpg', $row->data['en']['hero_image']);

        $this->getJson('http://nike.sellchase.com/api/v1/storefront/content/about?lang=ar')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.ar.heading', 'من نحن');
    }

    public function test_unsupported_locale_and_wrong_types_are_rejected(): void
    {
        $this->api()->putJson('/api/v1/my-store/content/about', ['data' => ['fr' => ['heading' => 'Bonjour']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data.fr']);

        $this->api()->putJson('/api/v1/my-store/content/contact', ['data' => [
            'en' => ['show_form' => 'maybe', 'departments' => 'not-a-list', 'heading' => ['weird' => ['nested']]],
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data.en.show_form', 'data.en.departments', 'data.en.heading']);

        $this->api()->putJson('/api/v1/my-store/content/faq', ['data' => [
            'en' => ['items' => [['question' => 'Q', 'answer' => 'A'], 'oops']],
        ]])->assertStatus(422)->assertJsonValidationErrors(['data.en.items.1']);
    }

    public function test_unknown_fields_are_dropped_and_toggles_coerced(): void
    {
        $this->api()->putJson('/api/v1/my-store/content/contact', ['data' => [
            'en' => ['heading' => 'Contact', 'show_form' => '1', 'not_a_field' => 'x'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.data.en.show_form', true)
            ->assertJsonMissingPath('data.data.en.not_a_field');
    }
}

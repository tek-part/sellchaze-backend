<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use App\Models\PhoneNewsletterSubscriber;
use App\Models\Store;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerProfile;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreCustomersV2Test extends TestCase
{
    use RefreshDatabase;

    private const MONA = '+201001112222';

    private Store $store;

    private User $owner;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        [$this->owner, $this->auth, $this->store] = $this->merchant('alpha');
    }

    /** @return array{0: User, 1: array<string, string>, 2: Store} */
    private function merchant(string $slug): array
    {
        $owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $owner->assignRole('Merchant');
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($owner)];
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => ucfirst($slug), 'slug' => 'cv2-'.$slug, 'currency' => 'EGP', 'default_locale' => 'en', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'cv2-'.$slug.'.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);

        return [$owner, $auth, $store];
    }

    private function order(array $attributes, ?Store $store = null): StoreOrder
    {
        return StoreOrder::withoutGlobalScopes()->create(array_merge(['store_id' => ($store ?? $this->store)->id, 'order_number' => 'CV-'.Str::random(8), 'status' => 'delivered',
            'payment_status' => 'paid', 'payment_method' => 'cod', 'currency' => 'EGP', 'subtotal' => 100, 'grand_total' => 100], $attributes));
    }

    private function seedCustomers(): void
    {
        $this->order(['customer_name' => 'Mona', 'customer_phone' => '+20 100 111 2222', 'customer_phone_normalized' => self::MONA, 'customer_email' => 'mona@example.test',
            'grand_total' => 300, 'placed_at' => now()->subDays(40), 'shipping_address' => ['line1' => '1 Nile St', 'city' => 'Giza']]);
        $this->order(['customer_name' => 'Mona A.', 'customer_phone' => '01001112222', 'customer_phone_normalized' => self::MONA,
            'grand_total' => 200, 'placed_at' => now()->subDays(2), 'shipping_address' => ['line1' => '9 Tahrir Sq', 'city' => 'Cairo']]);
        $this->order(['customer_name' => 'Mona', 'customer_phone_normalized' => self::MONA, 'status' => 'cancelled', 'grand_total' => 999, 'placed_at' => now()->subDay()]);
        $this->order(['customer_name' => 'Omar', 'customer_email' => 'Omar@Example.test', 'grand_total' => 50, 'placed_at' => now()->subDays(100)]);
        $this->order(['customer_name' => 'Nobody', 'grand_total' => 10, 'placed_at' => now()->subDay()]);
    }

    private function url(string $path, array $query = []): string
    {
        return '/api/v1/my-store/customers/'.$path.($query ? '?'.http_build_query($query) : '');
    }

    public function test_profile_aggregates_a_guest_key(): void
    {
        $this->seedCustomers();

        $response = $this->getJson($this->url('profile', ['key' => self::MONA]), $this->auth)->assertOk();
        $data = $response->json('data');
        $this->assertSame('phone', $data['key_type']);
        $this->assertSame(2, $data['stats']['orders_count']);
        $this->assertEquals(500, $data['stats']['total_spent']);
        $this->assertEquals(250, $data['stats']['average_order_value']);
        $this->assertSame(1, $data['stats']['cancelled_count']);
        $this->assertNotNull($data['stats']['first_order_at']);
        $this->assertTrue($data['stats']['first_order_at'] < $data['stats']['last_order_at']);
        $this->assertSame('mona@example.test', $data['contact']['email']);
        $this->assertNull($data['account']);
        // Latest address first, de-duplicated.
        $this->assertSame(['9 Tahrir Sq, Cairo', '1 Nile St, Giza'], array_column($data['addresses'], 'formatted'));
        // All orders (cancelled included) newest first, paginated.
        $this->assertSame(3, $data['orders']['meta']['total']);
        $this->assertSame('cancelled', $data['orders']['data'][0]['status']);
        $this->assertSame(['id', 'order_number', 'status', 'payment_status', 'payment_method', 'currency', 'grand_total', 'placed_at'], array_keys($data['orders']['data'][0]));
        $this->assertSame(['tags' => [], 'note' => null, 'name' => null, 'email' => null, 'phone' => null, 'updated_at' => null], $data['profile']);
        $this->assertFalse($data['marketing']['accepts_marketing']);

        $paged = $this->getJson($this->url('profile', ['key' => self::MONA, 'per_page' => 1, 'page' => 2]), $this->auth)->assertOk();
        $this->assertSame(3, $paged->json('data.orders.meta.last_page'));
        $this->assertCount(1, $paged->json('data.orders.data'));

        // E-mail keys are case-insensitive; contact-less orders have their own key.
        $this->getJson($this->url('profile', ['key' => 'OMAR@example.test']), $this->auth)->assertOk()->assertJsonPath('data.stats.orders_count', 1);
        $nobody = StoreOrder::withoutGlobalScopes()->where('customer_name', 'Nobody')->value('id');
        $this->getJson($this->url('profile', ['key' => 'order:'.$nobody]), $this->auth)->assertOk()->assertJsonPath('data.contact.name', 'Nobody');
        // Mona's orders carry a phone, so they are not reachable by an order: key.
        $monaOrder = StoreOrder::withoutGlobalScopes()->where('customer_phone_normalized', self::MONA)->value('id');
        $this->getJson($this->url('profile', ['key' => 'order:'.$monaOrder]), $this->auth)->assertNotFound();
    }

    public function test_profile_of_a_registered_key_links_the_account_even_without_orders(): void
    {
        $account = StoreCustomer::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Sara', 'email' => 'Sara@Example.test', 'phone' => '01223334444', 'password' => bcrypt('secret-123'), 'is_active' => true]);

        $index = $this->getJson($this->url(''), $this->auth)->assertOk();
        $this->assertSame('+201223334444', $index->json('data.0.customer_key'));
        $this->getJson($this->url((string) $account->id), $this->auth)->assertOk()->assertJsonPath('data.customer_key', '+201223334444');

        $empty = $this->getJson($this->url('profile', ['key' => '+201223334444']), $this->auth)->assertOk()->json('data');
        $this->assertSame($account->id, $empty['account']['id']);
        $this->assertSame(0, $empty['stats']['orders_count']);
        $this->assertSame('Sara', $empty['contact']['name']);

        $this->order(['store_customer_id' => $account->id, 'customer_name' => 'Sara', 'customer_phone' => '01223334444', 'customer_phone_normalized' => '+201223334444', 'grand_total' => 120]);
        $this->order(['store_customer_id' => $account->id, 'customer_name' => 'Sara', 'customer_phone' => '01223334444', 'customer_phone_normalized' => '+201223334444', 'grand_total' => 80]);
        $data = $this->getJson($this->url('profile', ['key' => '+201223334444']), $this->auth)->assertOk()->json('data');
        $this->assertSame($account->id, $data['account']['id']);
        $this->assertSame(2, $data['stats']['orders_count']);
        $this->assertEquals(100, $data['stats']['average_order_value']);
        $row = collect($this->getJson($this->url('directory'), $this->auth)->json('data'))->firstWhere('key', '+201223334444');
        $this->assertSame($account->id, $row['account_id']);
    }

    public function test_upsert_tags_note_contact_and_consent(): void
    {
        $this->seedCustomers();

        $saved = $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['VIP', ' wholesale  buyer ', 'vip'], 'note' => '  Prefers evening delivery. ', 'accepts_marketing' => true, 'name' => 'Mona Ali'], $this->auth)
            ->assertOk()->assertJsonPath('data.profile.tags', ['VIP', 'wholesale buyer'])->assertJsonPath('data.profile.note', 'Prefers evening delivery.')
            ->assertJsonPath('data.contact.name', 'Mona Ali')->assertJsonPath('data.marketing.accepts_marketing', true)->json('data.marketing');
        $this->assertTrue($saved['sms']['subscribed']);
        $this->assertTrue($saved['email']['subscribed']);
        $this->assertSame('merchant', $saved['source']);
        $this->assertNotNull($saved['updated_at']);

        // Consent lives in the newsletter lists (the storefront opt-in and the Newsletter page use the same rows).
        $sms = PhoneNewsletterSubscriber::withoutGlobalScopes()->where('store_id', $this->store->id)->where('phone_normalized', self::MONA)->firstOrFail();
        $this->assertTrue($sms->is_active);
        $this->assertSame('Mona Ali', $sms->name);
        $this->assertTrue(NewsletterSubscriber::withoutGlobalScopes()->where('store_id', $this->store->id)->where('email', 'mona@example.test')->value('is_active'));
        $profile = StoreCustomerProfile::withoutGlobalScopes()->where('store_id', $this->store->id)->where('customer_key', self::MONA)->firstOrFail();
        $this->assertSame($this->owner->id, $profile->marketing_updated_by);

        // Partial update: only the sent fields change; unsubscribe deactivates both channels.
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'accepts_marketing' => false], $this->auth)->assertOk()
            ->assertJsonPath('data.profile.tags', ['VIP', 'wholesale buyer'])->assertJsonPath('data.profile.note', 'Prefers evening delivery.')
            ->assertJsonPath('data.marketing.accepts_marketing', false);
        $this->assertFalse($sms->fresh()->is_active);
        $this->assertFalse(NewsletterSubscriber::withoutGlobalScopes()->where('email', 'mona@example.test')->value('is_active'));

        // Clearing the note and an override; a contact-less customer can't be subscribed until they have an e-mail.
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'note' => '', 'name' => null, 'tags' => []], $this->auth)->assertOk()
            ->assertJsonPath('data.profile.note', null)->assertJsonPath('data.contact.name', 'Mona A.')->assertJsonPath('data.profile.tags', []);
        $nobody = 'order:'.StoreOrder::withoutGlobalScopes()->where('customer_name', 'Nobody')->value('id');
        $this->putJson($this->url('profile'), ['key' => $nobody, 'accepts_marketing' => true], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('accepts_marketing');
        $this->putJson($this->url('profile'), ['key' => $nobody, 'accepts_marketing' => true, 'email' => 'nobody@example.test'], $this->auth)->assertOk()
            ->assertJsonPath('data.marketing.email.subscribed', true)->assertJsonPath('data.contact.email', 'nobody@example.test');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'phone' => 'not a phone'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'email' => 'nope'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_tag_normalization_and_limits(): void
    {
        $this->seedCustomers();
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['Wholesale']], $this->auth)->assertOk();

        // Comma lists split, whitespace collapses, duplicates drop case-insensitively, the store's spelling is reused.
        $this->putJson($this->url('profile'), ['key' => 'omar@example.test', 'tags' => ['wholesale, Cairo', "  late\t payer ", '', 'CAIRO', 'عميل مميز']], $this->auth)
            ->assertOk()->assertJsonPath('data.profile.tags', ['Wholesale', 'Cairo', 'late payer', 'عميل مميز']);

        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => [str_repeat('x', 41)]], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('tags.0');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => array_map(fn ($i) => 'tag'.$i, range(1, 21))], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('tags');
        // 20 entries that split into 21 tags are rejected after normalization too.
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => array_merge(['a, b'], array_map(fn ($i) => 'tag'.$i, range(1, 19)))], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors('tags');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => array_map(fn ($i) => 'tag'.$i, range(1, 20))], $this->auth)->assertOk()->assertJsonCount(20, 'data.profile.tags');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'note' => str_repeat('n', 5001)], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => 'vip'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('tags');
    }

    public function test_unknown_or_malformed_keys_are_rejected(): void
    {
        $this->seedCustomers();
        $this->putJson($this->url('profile'), ['key' => '+201009999999', 'tags' => ['junk']], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->putJson($this->url('profile'), ['key' => 'ghost@example.test', 'note' => 'x'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->putJson($this->url('profile'), ['key' => 'DROP TABLE', 'note' => 'x'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->putJson($this->url('profile'), ['tags' => ['x']], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->getJson($this->url('profile', ['key' => '+201009999999']), $this->auth)->assertNotFound();
        $this->getJson($this->url('profile', ['key' => 'order:999999']), $this->auth)->assertNotFound();
        $this->getJson($this->url('profile'), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->assertSame(0, StoreCustomerProfile::withoutGlobalScopes()->count());
    }

    public function test_store_isolation(): void
    {
        $this->seedCustomers();
        [, $otherAuth, $other] = $this->merchant('beta');
        $this->order(['customer_name' => 'Hidden', 'customer_phone_normalized' => '+201005556666', 'grand_total' => 70], $other);
        $this->putJson($this->url('profile'), ['key' => '+201005556666', 'tags' => ['secret']], $otherAuth)->assertOk();
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['VIP']], $this->auth)->assertOk();
        // Same key in the other store: a separate profile, invisible here.
        $this->order(['customer_name' => 'Mona elsewhere', 'customer_phone_normalized' => self::MONA, 'grand_total' => 10], $other);
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['Other-store'], 'note' => 'theirs'], $otherAuth)->assertOk();

        $this->getJson($this->url('profile', ['key' => '+201005556666']), $this->auth)->assertNotFound();
        $this->putJson($this->url('profile'), ['key' => '+201005556666', 'note' => 'mine'], $this->auth)->assertUnprocessable()->assertJsonValidationErrors('key');
        $mona = $this->getJson($this->url('profile', ['key' => self::MONA]), $this->auth)->assertOk();
        $mona->assertJsonPath('data.profile.tags', ['VIP'])->assertJsonPath('data.profile.note', null)->assertJsonPath('data.stats.orders_count', 2);
        $this->assertSame([['tag' => 'VIP', 'count' => 1]], $this->getJson($this->url('tags'), $this->auth)->json('data'));
        $this->assertSame([], $this->getJson($this->url('directory', ['tag' => 'secret']), $this->auth)->json('data'));
        $this->assertSame(['Other-store', 'secret'], array_column($this->getJson($this->url('tags'), $otherAuth)->json('data'), 'tag'));

        // The explicit /stores/{store} form refuses another merchant's store.
        $this->getJson('/api/v1/stores/'.$other->id.'/customers/tags', $this->auth)->assertForbidden();
        $this->putJson('/api/v1/stores/'.$other->id.'/customers/profile', ['key' => self::MONA, 'note' => 'x'], $this->auth)->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->store->id.'/customers/profile?key='.urlencode(self::MONA), $this->auth)->assertOk();
    }

    public function test_directory_tag_and_marketing_filters_and_row_fields(): void
    {
        $this->seedCustomers();
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['VIP', 'Cairo']], $this->auth)->assertOk();
        $this->putJson($this->url('profile'), ['key' => 'omar@example.test', 'tags' => ['Wholesale'], 'name' => 'Omar Override'], $this->auth)->assertOk();
        // Omar opted in from the storefront (e-mail list), Mona never did.
        NewsletterSubscriber::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'email' => 'omar@example.test', 'is_active' => true]);
        // Inactive phone subscriber does not count.
        PhoneNewsletterSubscriber::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'name' => 'Mona', 'phone' => '0100', 'phone_normalized' => self::MONA, 'is_active' => false]);

        $all = collect($this->getJson($this->url('directory'), $this->auth)->assertOk()->json('data'))->keyBy('key');
        $this->assertCount(3, $all);
        $this->assertSame(['VIP', 'Cairo'], $all[self::MONA]['tags']);
        $this->assertFalse($all[self::MONA]['accepts_marketing']);
        $this->assertTrue($all['omar@example.test']['accepts_marketing']);
        $this->assertSame('Omar Override', $all['omar@example.test']['name']);
        $nobody = $all->first(fn ($row) => str_starts_with($row['key'], 'order:'));
        $this->assertSame([], $nobody['tags']);

        $keys = fn (array $query) => array_column($this->getJson($this->url('directory', $query), $this->auth)->assertOk()->json('data'), 'key');
        $this->assertSame([self::MONA], $keys(['tag' => 'vip']));
        $this->assertEqualsCanonicalizing([self::MONA, 'omar@example.test'], $keys(['tag' => 'vip,wholesale']));
        $this->assertEqualsCanonicalizing([self::MONA, 'omar@example.test'], $keys(['tag' => ['VIP', 'Wholesale']]));
        $this->assertSame([], $keys(['tag' => 'missing']));
        $this->assertSame(['omar@example.test'], $keys(['marketing' => 'subscribed']));
        $this->assertCount(2, $keys(['marketing' => 'not_subscribed']));
        $this->assertSame([self::MONA], $keys(['marketing' => 'not_subscribed', 'tag' => 'cairo', 'orders_min' => 2]));
        $this->assertSame(['omar@example.test'], $keys(['search' => 'override']));
        // Sorting by name uses the override: "Omar Override" > "Nobody" > "Mona A.".
        $byName = $keys(['sort_by' => 'name', 'sort_direction' => 'desc']);
        $this->assertSame('omar@example.test', $byName[0]);
        $this->assertSame(self::MONA, $byName[2]);
        $this->getJson($this->url('directory', ['marketing' => 'maybe']), $this->auth)->assertUnprocessable()->assertJsonValidationErrors('marketing');

        // Merchant consent is reflected through the same join.
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'accepts_marketing' => true], $this->auth)->assertOk();
        $this->assertEqualsCanonicalizing([self::MONA, 'omar@example.test'], $keys(['marketing' => 'subscribed']));

        $this->putJson($this->url('profile'), ['key' => self::MONA, 'name' => '=SUM(1+1)'], $this->auth)->assertOk();
        $csv = $this->get($this->url('directory/export', ['tag' => 'vip']), $this->auth)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('"Accepts marketing"', $lines[0]);
        // Formula-looking cells are neutralized for spreadsheets.
        $this->assertStringStartsWith("'=SUM(1+1),01001112222,mona@example.test,guest,2,500.00,250.00,", $lines[1]);
        $this->assertStringContainsString('"VIP, Cairo"', $lines[1]);
    }

    public function test_tags_endpoint_counts_and_searches(): void
    {
        $this->seedCustomers();
        $this->putJson($this->url('profile'), ['key' => self::MONA, 'tags' => ['VIP', 'Cairo']], $this->auth)->assertOk();
        $this->putJson($this->url('profile'), ['key' => 'omar@example.test', 'tags' => ['vip']], $this->auth)->assertOk();

        $this->assertSame([['tag' => 'VIP', 'count' => 2], ['tag' => 'Cairo', 'count' => 1]], $this->getJson($this->url('tags'), $this->auth)->assertOk()->json('data'));
        $this->assertSame([['tag' => 'Cairo', 'count' => 1]], $this->getJson($this->url('tags', ['search' => 'cai']), $this->auth)->json('data'));
    }
}

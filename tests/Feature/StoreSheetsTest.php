<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\StoreSheetConnection;
use App\Models\StoreSheetDelivery;
use App\Models\User;
use App\Services\Commerce\StoreSheets;
use App\Services\Commerce\StoreSheetsDispatch;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreSheetsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $owner;

    private array $auth;

    private array $credential;

    private const SPREADSHEET = 'LOCAL_REVIEW_SPREADSHEET_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner), 'Accept' => 'application/json'];
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Local Sheets', 'slug' => 'local-sheets', 'status' => 'active', 'currency' => 'EGP']);
        $this->product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->owner->id, 'name' => 'Local Sheets product', 'slug' => 'local-sheets-product', 'price' => 250, 'is_active' => true]);
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => __DIR__.'/../Fixtures/openssl-test.cnf'];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        $this->credential = ['type' => 'service_account', 'client_email' => 'fixture@local-preview.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'http://127.0.0.1/forbidden'];
    }

    private function createConnection(array $changes = []): array
    {
        $body = $changes + ['request_key' => (string) Str::uuid(), 'spreadsheet_id' => self::SPREADSHEET, 'enabled' => true, 'all_products' => true, 'product_ids' => []];
        $body['service_account'] = UploadedFile::fake()->createWithContent('service-account.json', json_encode($this->credential, JSON_THROW_ON_ERROR));

        return $this->post('/api/v1/my-store/sheets', $body, $this->auth)->assertCreated()->json('data');
    }

    private function order(?int $product = null): StoreOrder
    {
        app(CurrentStore::class)->set($this->store);
        $order = StoreOrder::create(['store_id' => $this->store->id, 'order_number' => 'LOCAL-'.Str::uuid(), 'status' => 'pending', 'currency' => 'EGP', 'customer_name' => '=IMPORTXML("https://example.test")', 'customer_phone' => '+201001234567', 'customer_email' => 'buyer@example.test', 'subtotal' => 250, 'shipping_total' => 25, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 275, 'payment_method' => 'cod', 'payment_status' => 'pending', 'placed_at' => now()]);
        $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product ?? $this->product->id, 'name' => 'Local snapshot product', 'unit_price' => 250, 'quantity' => 1, 'line_total' => 250]);

        return $order;
    }

    private function mockGoogle(StoreSheetConnection $connection, array &$cells, bool &$created, bool $uncertainWrite = false): void
    {
        Http::fake(function ($request) use ($connection, &$cells, &$created, $uncertainWrite) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'LOCAL-FAKE-TOKEN']);
            }
            if (str_contains($request->url(), ':batchUpdate')) {
                $created = true;
                $cells[1] = StoreSheets::HEADERS;

                return Http::response(['replies' => []]);
            }
            if (! str_contains($request->url(), '/values/')) {
                return Http::response(['sheets' => $created ? [['properties' => ['sheetId' => $connection->sheet_id, 'title' => $connection->sheet_title, 'gridProperties' => ['rowCount' => 1000]], 'developerMetadata' => [['metadataKey' => 'sellchaze_connection', 'metadataValue' => $connection->id]]]] : []]);
            }
            $range = rawurldecode(explode('?', explode('/values/', $request->url())[1])[0]);
            preg_match('/!A([0-9]+):Q[0-9]+$/', $range, $matches);
            $row = (int) $matches[1];
            if ($request->method() === 'PUT') {
                $this->assertStringContainsString('valueInputOption=RAW', $request->url());
                $cells[$row] = $request['values'][0];
                if ($uncertainWrite) {
                    return Http::response(['error' => 'Synthetic network ambiguity'], 503);
                }

                return Http::response(['updatedRange' => $range, 'updatedRows' => 1, 'updatedColumns' => 17]);
            }

            return Http::response(['values' => isset($cells[$row]) ? [$cells[$row]] : []]);
        });
    }

    public function test_empty_read_does_not_connect_or_send_and_credentials_are_encrypted_and_never_returned(): void
    {
        $this->getJson('/api/v1/my-store/sheets', $this->auth)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('store_sheet_connections', 0);
        Http::assertNothingSent();
        $created = $this->createConnection();
        $row = StoreSheetConnection::forStore($this->store)->findOrFail($created['id']);
        $this->assertArrayNotHasKey('credentials', $created);
        $this->assertArrayNotHasKey('credentials', $row->toArray());
        $this->assertStringNotContainsString('PRIVATE KEY', DB::table('store_sheet_connections')->value('credentials'));
        $this->assertArrayNotHasKey('token_uri', $row->credentials);
        Http::assertNothingSent();
    }

    public function test_apps_registry_is_real_scoped_metadata_without_secret_or_subscription_side_effects(): void
    {
        $this->getJson('/api/v1/my-store/apps', $this->auth)->assertOk()->assertJsonCount(31, 'data')->assertJsonCount(9, 'categories');
        $before = $this->getJson('/api/v1/my-store/apps', $this->auth)->json('data');
        $this->assertFalse(collect($before)->firstWhere('key', 'sheets')['configured']);
        $this->assertCount(18, array_filter($before, fn ($app) => $app['included']));
        $this->createConnection();
        $response = $this->getJson('/api/v1/my-store/apps', $this->auth)->assertOk();
        $this->assertTrue(collect($response->json('data'))->firstWhere('key', 'sheets')['configured']);
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $this->assertStringNotContainsString('fixture@', $response->getContent());
        $this->assertDatabaseCount('platform_wallet_entries', 0);
        Http::assertNothingSent();
    }

    public function test_sheet_export_requires_order_permission_and_rejects_cross_store_products_and_configuration_injection(): void
    {
        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $employee->assignRole('Employee');
        $employee->givePermissionTo(['store.view', 'store.settings.manage']);
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee), 'Accept' => 'application/json'];
        $this->getJson('/api/v1/my-store/sheets', $auth)->assertForbidden();
        $response = $this->getJson('/api/v1/my-store/apps', $auth)->assertOk();
        $this->assertFalse(collect($response->json('data'))->firstWhere('key', 'sheets')['can_manage']);
        $other = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Other store', 'slug' => 'other-sheet-store', 'is_primary' => false]);
        $product = Product::create(['store_id' => $other->id, 'name' => 'Other store product', 'slug' => 'other-store-product', 'price' => 10]);
        $body = ['request_key' => (string) Str::uuid(), 'spreadsheet_id' => self::SPREADSHEET, 'enabled' => false, 'all_products' => false, 'product_ids' => [$product->id], 'service_account' => UploadedFile::fake()->createWithContent('key.json', json_encode($this->credential))];
        $this->post('/api/v1/my-store/sheets', $body, $this->auth)->assertUnprocessable();
        $this->post('/api/v1/my-store/sheets', array_replace($body, ['configuration' => '{"product_ids":[],"store_id":999}']), $this->auth)->assertUnprocessable();
        $this->assertDatabaseCount('store_sheet_connections', 0);
        Http::assertNothingSent();
    }

    public function test_create_retries_are_idempotent_and_updates_are_owned_versioned_and_never_retarget_history(): void
    {
        $key = (string) Str::uuid();
        $created = $this->createConnection(['request_key' => $key]);
        $this->assertSame(1, $created['version']);
        $this->assertSame($created['id'], $this->createConnection(['request_key' => $key])['id']);
        $this->assertDatabaseCount('store_sheet_connections', 1);
        $body = ['version' => 1, 'spreadsheet_id' => self::SPREADSHEET, 'enabled' => false, 'all_products' => true, 'product_ids' => []];
        $this->putJson('/api/v1/my-store/sheets/'.$created['id'], $body, $this->auth)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame($created['id'], $this->createConnection(['request_key' => $key])['id']);
        $this->putJson('/api/v1/my-store/sheets/'.$created['id'], $body, $this->auth)->assertConflict();
        $this->putJson('/api/v1/my-store/sheets/'.$created['id'], array_replace($body, ['version' => 2, 'spreadsheet_id' => 'ANOTHER_SPREADSHEET_1234567890']), $this->auth)->assertConflict();
        $other = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $other->assignRole('Merchant');
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($other)];
        $this->putJson('/api/v1/my-store/sheets/'.$created['id'], $body, $auth)->assertNotFound();
        $this->getJson('/api/v1/my-store/sheets')->assertUnauthorized();
    }

    public function test_recording_uses_new_matching_products_only_and_freezes_one_encrypted_row_per_order(): void
    {
        $old = $this->order();
        $this->travel(1)->seconds();
        $created = $this->createConnection(['all_products' => false, 'product_ids' => [$this->product->id]]);
        app(StoreSheets::class)->record($old);
        $this->assertDatabaseCount('store_sheet_deliveries', 0);
        $order = $this->order();
        app(StoreSheets::class)->record($order);
        app(StoreSheets::class)->record($order);
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $this->assertSame(2, (int) $delivery->row_number);
        $this->assertStringNotContainsString('buyer@example.test', DB::table('store_sheet_deliveries')->value('payload'));
        $other = Product::create(['store_id' => $this->store->id, 'name' => 'Unselected', 'slug' => 'unselected', 'price' => 10, 'is_active' => true]);
        app(StoreSheets::class)->record($this->order($other->id));
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
        $this->assertSame(3, (int) StoreSheetConnection::forStore($this->store)->findOrFail($created['id'])->next_row);
        Http::assertNothingSent();
    }

    public function test_checkout_commit_and_idempotent_replay_record_one_delivery_without_outbound_calls(): void
    {
        $this->createConnection();
        StoreDomain::create(['store_id' => $this->store->id, 'host' => 'sheets.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        StorePaymentGateway::create(['store_id' => $this->store->id, 'gateway' => 'cod', 'enabled' => true, 'test_mode' => true, 'credentials' => []]);
        $body = ['customer_name' => 'Local Sheets buyer', 'customer_email' => 'local-buyer@example.test', 'customer_phone' => '01001234567', 'shipping_address' => ['line1' => 'Local preview address', 'city' => 'Cairo', 'country' => 'EG'], 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payment_method' => 'cod'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('http://sheets.sellchase.com/api/v1/storefront/checkout', $body, $headers)->assertCreated();
        $this->postJson('http://sheets.sellchase.com/api/v1/storefront/checkout', $body, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
        $this->assertDatabaseCount('store_orders', 1);
        Http::assertNothingSent();
    }

    public function test_success_and_ambiguous_retry_use_the_same_raw_row_without_duplicate_append_or_formula_execution(): void
    {
        $created = $this->createConnection();
        $connection = StoreSheetConnection::forStore($this->store)->findOrFail($created['id']);
        $order = $this->order();
        app(StoreSheets::class)->record($order);
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $cells = [];
        $sheetCreated = false;
        $this->mockGoogle($connection, $cells, $sheetCreated, true);
        $this->assertSame('retry', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertCount(2, $cells);
        $this->assertSame($order->customer_name, $cells[2][2]);
        $this->travel(31)->seconds();
        $this->assertSame('delivered', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertCount(2, $cells);
        $writes = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT');
        $this->assertCount(1, $writes);
        $this->assertSame('skipped', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), ':append') || str_starts_with($request->url(), 'http:'));
    }

    public function test_conflicting_user_rows_are_not_overwritten_and_disabled_connections_never_send(): void
    {
        $created = $this->createConnection();
        $connection = StoreSheetConnection::forStore($this->store)->findOrFail($created['id']);
        app(StoreSheets::class)->record($this->order());
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $cells = [1 => StoreSheets::HEADERS, 2 => ['Existing user data']];
        $sheetCreated = true;
        $this->mockGoogle($connection, $cells, $sheetCreated);
        $this->assertSame('failed', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertSame('sheet_row_conflict', $delivery->fresh()->error_code);
        $this->assertSame(['Existing user data'], $cells[2]);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        $connection->update(['enabled' => false]);
        $another = $this->order();
        app(StoreSheets::class)->record($another);
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
    }

    public function test_manual_retry_reconciles_the_reserved_row_and_completed_deliveries_cannot_be_requeued(): void
    {
        $created = $this->createConnection();
        $connection = StoreSheetConnection::forStore($this->store)->findOrFail($created['id']);
        app(StoreSheets::class)->record($this->order());
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $cells = [1 => StoreSheets::HEADERS, 2 => ['Existing user data']];
        $sheetCreated = true;
        $this->mockGoogle($connection, $cells, $sheetCreated);
        $this->assertSame('failed', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        // The spreadsheet owner removes the conflicting row before retrying.
        unset($cells[2]);
        $this->postJson('/api/v1/my-store/sheets/deliveries/'.$delivery->id.'/retry', [], $this->auth)->assertOk();
        $this->assertSame('delivered', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertSame(2, (int) $delivery->fresh()->row_number);
        $this->assertSame(3, (int) $connection->fresh()->next_row);
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
        $this->postJson('/api/v1/my-store/sheets/deliveries/'.$delivery->id.'/retry', [], $this->auth)->assertConflict();
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'));
    }

    public function test_an_active_lease_prevents_parallel_calls_and_revocation_during_oauth_prevents_sheet_writes(): void
    {
        $created = $this->createConnection();
        $connection = StoreSheetConnection::forStore($this->store)->findOrFail($created['id']);
        app(StoreSheets::class)->record($this->order());
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $delivery->update(['claimed_until' => now()->addMinute(), 'lease' => (string) Str::uuid()]);
        $this->assertSame('skipped', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        Http::assertNothingSent();
        $delivery->update(['claimed_until' => null, 'lease' => null]);
        Http::fake(function ($request) use ($connection) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                $public = openssl_pkey_get_details(openssl_pkey_get_private($this->credential['private_key']))['key'];
                $claims = JWT::decode($request['assertion'], new Key($public, 'RS256'));
                $this->assertSame('https://oauth2.googleapis.com/token', $claims->aud);
                $this->assertSame('https://www.googleapis.com/auth/spreadsheets', $claims->scope);
                $this->assertFalse(property_exists($claims, 'sub'));
                $connection->update(['enabled' => false, 'version' => $connection->version + 1]);

                return Http::response(['access_token' => 'LOCAL-FAKE-TOKEN']);
            }

            return Http::response(['sheets' => []]);
        });
        $this->assertSame('cancelled', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertSame('connection_changed', $delivery->fresh()->error_code);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT' || str_contains($request->url(), ':batchUpdate'));
    }

    public function test_wallet_debt_blocks_export_before_provider_calls_and_archiving_preserves_history(): void
    {
        $created = $this->createConnection();
        config(['platform_wallet.fees_enabled' => true]);
        app(PlatformWallet::class)->activateOrderFees();
        for ($index = 0; $index < 5; $index++) {
            $order = $this->order();
            app(PlatformWallet::class)->orderFee($order);
        }
        app(StoreSheets::class)->record($order);
        $delivery = StoreSheetDelivery::forStore($this->store)->firstOrFail();
        $this->assertSame(-200, (int) DB::table('platform_wallet_accounts')->value('balance_milli'));
        $this->assertSame('skipped', app(StoreSheetsDispatch::class)->deliver($delivery->id));
        $this->assertSame('blocked', $delivery->fresh()->status);
        Http::assertNothingSent();
        $this->deleteJson('/api/v1/my-store/sheets/'.$created['id'], ['version' => 1], $this->auth)->assertOk();
        $this->assertSoftDeleted('store_sheet_connections', ['id' => $created['id']]);
        $this->assertDatabaseCount('store_sheet_deliveries', 1);
        $migration = require database_path('migrations/2026_10_12_130000_create_store_sheet_connections.php');
        try {
            $migration->down();
            $this->fail('Populated sheet history cannot reverse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }
    }
}

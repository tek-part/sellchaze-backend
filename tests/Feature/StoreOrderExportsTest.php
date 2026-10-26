<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderExport;
use App\Models\User;
use App\Notifications\StoreOrderExportReady;
use App\Services\Commerce\StoreOrderExports;
use App\Services\JwtTokenService;
use App\Services\PlatformWallet;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreOrderExportsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Store $store;

    private Store $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('local');
        [$this->actor, $this->store] = $this->workspace('exports');
        [, $this->other] = $this->workspace('foreign');
    }

    /** @return array{User,Store} */
    private function workspace(string $slug): array
    {
        $user = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $user->assignRole('Merchant');
        $store = Store::create(['owner_user_id' => $user->id, 'owner_type' => 'merchant', 'name' => $slug, 'slug' => $slug, 'currency' => 'USD', 'status' => 'active']);

        return [$user, $store];
    }

    /** @param array<string,mixed> $attributes */
    private function order(array $attributes = []): StoreOrder
    {
        return StoreOrder::create(array_merge(['store_id' => $this->store->id, 'order_number' => 'EXPORT-'.Str::random(8), 'customer_name' => 'Review customer',
            'customer_email' => 'review@example.test', 'customer_phone' => '+201000000000', 'shipping_address' => ['line1' => 'Review street', 'city' => 'Cairo', 'country' => 'Egypt'],
            'status' => 'pending', 'payment_status' => 'pending', 'currency' => 'USD', 'subtotal' => 100, 'grand_total' => 100,
            'placed_at' => now(), 'marketing_attribution' => ['source' => 'review-source', 'campaign' => 'review-campaign'], 'coupon_code' => 'REVIEW10'], $attributes));
    }

    private function authenticate(?User $actor = null): self
    {
        return $this->withToken(JwtTokenService::fromConfig()->issueAccessToken($actor ?? $this->actor));
    }

    private function endpoint(string $tail = ''): string
    {
        return '/api/v1/stores/'.$this->store->id.'/orders'.$tail;
    }

    /** @return array<string,string> */
    private function requestData(): array
    {
        return ['date_from' => now()->subDay()->format('Y-m-d'), 'date_to' => now()->format('Y-m-d'), 'request_key' => (string) Str::uuid()];
    }

    public function test_all_observed_filters_use_real_store_order_snapshots(): void
    {
        $order = $this->order();
        $this->order(['store_id' => $this->other->id, 'customer_name' => 'Foreign customer']);
        $product = Product::create(['store_id' => $this->store->id, 'user_id' => $this->actor->id, 'name' => 'Export review product', 'slug' => 'export-review', 'price' => 100]);
        $order->items()->create(['store_id' => $this->store->id, 'store_product_id' => $product->id, 'name' => 'Product snapshot', 'unit_price' => 100, 'quantity' => 1, 'line_total' => 100]);
        foreach (['order_id' => $order->id, 'customer' => 'Review customer', 'customer_phone' => '1000000000', 'address' => 'Cairo',
            'source' => 'review-source', 'campaign' => 'review-campaign', 'coupon' => 'REVIEW10', 'product_id' => $product->id,
            'date_from' => now()->format('Y-m-d'), 'date_to' => now()->format('Y-m-d')] as $key => $value) {
            $this->authenticate()->getJson($this->endpoint().'?'.http_build_query([$key => $value]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $order->id);
        }
        $this->authenticate()->getJson($this->endpoint().'?address=line1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->authenticate()->getJson($this->endpoint().'?source=foreign')->assertOk()->assertJsonPath('meta.total', 0);
        $this->authenticate()->getJson($this->endpoint().'?sort=customer_name&dir=asc')->assertOk()->assertJsonPath('counts.pending', 1);
        $this->authenticate()->getJson($this->endpoint().'?sort=unsafe')->assertUnprocessable();
        $this->authenticate()->getJson($this->endpoint().'?date_from=2026-11-01&date_to=2026-10-01')->assertUnprocessable();
    }

    public function test_export_is_queued_encrypted_and_notifies_only_the_requester_once(): void
    {
        $this->order(['customer_name' => '=HYPERLINK("https://example.test")']);
        $this->order(['store_id' => $this->other->id, 'customer_name' => 'Foreign customer']);
        $request = $this->requestData();
        $response = $this->authenticate()->postJson($this->endpoint('/exports'), $request)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $id = $response->json('data.id');
        $this->authenticate()->postJson($this->endpoint('/exports'), $request)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->authenticate()->postJson($this->endpoint('/exports'), $this->requestData())->assertStatus(429)->assertHeader('Retry-After');
        $row = StoreOrderExport::findOrFail($id);
        $this->assertStringNotContainsString('date_from', $row->getRawOriginal('filters'));
        $this->authenticate()->getJson($this->endpoint('/exports/'.$id.'/download'))->assertStatus(409);
        $this->assertSame(1, app(StoreOrderExports::class)->process());
        $this->assertSame(0, app(StoreOrderExports::class)->process());
        $row->refresh();
        $this->assertSame('ready', $row->status);
        $this->assertSame(1, $row->row_count);
        $this->assertStringNotContainsString('HYPERLINK', Storage::disk('local')->get($row->artifact_path));
        $download = $this->authenticate()->get($this->endpoint('/exports/'.$id.'/download'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=HYPERLINK", $download->getContent());
        $header = str_getcsv(strtok(ltrim($download->getContent(), "\xEF\xBB\xBF"), "\n"), ',', '"', '');
        $this->assertSame(['Payment method adjustment', 'Gift cards', 'Amount due'], array_slice($header, -3));
        $this->assertStringNotContainsString('Foreign customer', $download->getContent());
        $this->assertSame(1, $this->actor->notifications()->where('type', StoreOrderExportReady::class)->count());
        $this->assertSame(0, $this->other->owner->notifications()->count());
        $this->assertSame('/store/orders?export='.$id, $this->actor->notifications()->firstOrFail()->data['url']);
    }

    public function test_foreign_actor_changed_owner_and_expired_files_cannot_be_downloaded(): void
    {
        $this->order();
        $id = $this->authenticate()->postJson($this->endpoint('/exports'), $this->requestData())->assertAccepted()->json('data.id');
        app(StoreOrderExports::class)->process();
        $this->authenticate($this->other->owner)->getJson($this->endpoint('/exports/'.$id))->assertForbidden();
        $row = StoreOrderExport::findOrFail($id);
        $path = $row->artifact_path;
        $row->update(['expires_at' => now()->subSecond()]);
        $this->authenticate()->getJson($this->endpoint('/exports/'.$id.'/download'))->assertStatus(409);
        app(StoreOrderExports::class)->process();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($row->fresh()->filters);
        $this->assertSame('expired', $row->fresh()->status);
    }

    public function test_archive_and_wallet_debt_cannot_be_bypassed_by_an_old_export(): void
    {
        $wallet = app(PlatformWallet::class);
        config(['platform_wallet.fees_enabled' => true]);
        $wallet->activateOrderFees();
        $first = $this->order();
        $wallet->orderFee($first);
        $id = $this->authenticate()->postJson($this->endpoint('/exports'), $this->requestData())->assertAccepted()->json('data.id');
        app(StoreOrderExports::class)->process();
        for ($i = 0; $i < 4; $i++) {
            $wallet->orderFee($this->order());
        }
        $this->authenticate()->getJson($this->endpoint())->assertOk()->assertJsonPath('meta.total', 0)->assertJsonMissingPath('counts.pending');
        $this->authenticate()->getJson($this->endpoint('/exports/'.$id.'/download'))->assertStatus(409);
        config(['platform_wallet.fees_enabled' => false]);
        $first->delete();
        $this->authenticate()->getJson($this->endpoint('/exports/'.$id.'/download'))->assertStatus(409);
    }

    public function test_owner_change_revokes_queued_jobs_and_worker_restores_tenant_context(): void
    {
        $this->order();
        $id = $this->authenticate()->postJson($this->endpoint('/exports'), $this->requestData())->assertAccepted()->json('data.id');
        $this->store->update(['owner_user_id' => $this->other->owner_user_id]);
        $tenant = app(CurrentStore::class);
        $previous = $tenant->get();
        app(StoreOrderExports::class)->process();
        $this->assertSame($previous, $tenant->get());
        $this->assertSame('access_revoked', StoreOrderExport::findOrFail($id)->error_code);
        $this->assertSame(0, $this->actor->notifications()->count());
    }

    public function test_invalid_ranges_and_reused_payloads_are_rejected(): void
    {
        $request = $this->requestData();
        $this->authenticate()->postJson($this->endpoint('/exports'), array_merge($request, ['date_to' => '2020-01-01']))->assertUnprocessable();
        $this->authenticate()->postJson($this->endpoint('/exports'), $request)->assertAccepted();
        $this->authenticate()->postJson($this->endpoint('/exports'), array_merge($request, ['source' => 'changed']))->assertStatus(409);
        $this->assertDatabaseCount('store_order_exports', 1);
    }

    public function test_bulk_status_changes_are_atomic_scoped_and_replay_without_duplicate_events(): void
    {
        $one = $this->order();
        $two = $this->order(['status' => 'shipped']);
        $payload = ['status' => 'confirmed', 'orders' => [['id' => $one->id, 'status' => 'pending'], ['id' => $two->id, 'status' => 'shipped']]];
        $this->authenticate()->postJson($this->endpoint('/bulk-status'), $payload)->assertUnprocessable();
        $this->assertSame('pending', $one->fresh()->status);
        $this->assertDatabaseCount('store_order_status_changes', 0);
        $payload['orders'] = [['id' => $one->id, 'status' => 'pending']];
        $this->authenticate()->postJson($this->endpoint('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 1);
        $this->authenticate()->postJson($this->endpoint('/bulk-status'), $payload)->assertOk()->assertJsonPath('updated', 0);
        $this->assertDatabaseCount('store_order_status_changes', 1);
        $foreign = $this->order(['store_id' => $this->other->id]);
        $payload['orders'] = [['id' => $foreign->id, 'status' => 'pending']];
        $this->authenticate()->postJson($this->endpoint('/bulk-status'), $payload)->assertNotFound();
        $payload['status'] = 'processing';
        $payload['orders'] = [['id' => $one->id, 'status' => 'pending']];
        $this->authenticate()->postJson($this->endpoint('/bulk-status'), $payload)->assertStatus(409);
        $this->assertDatabaseCount('store_order_status_changes', 1);
    }

    public function test_printing_and_product_choices_do_not_escape_the_visible_order_set(): void
    {
        $one = $this->order();
        $foreign = $this->order(['store_id' => $this->other->id]);
        $this->authenticate()->getJson($this->endpoint('/print-data').'?'.http_build_query(['ids' => [$one->id]]))->assertOk()->assertJsonPath('data.0.id', $one->id);
        $this->authenticate()->getJson($this->endpoint('/print-data').'?'.http_build_query(['ids' => [$one->id, $foreign->id]]))->assertNotFound();
        $this->authenticate()->getJson($this->endpoint('/filter-products?search=missing'))->assertOk()->assertJsonCount(0, 'data');
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessStorePhoneBlockImport;
use App\Models\Store;
use App\Models\StorePhoneBlock;
use App\Models\StorePhoneBlockImport;
use App\Models\User;
use App\Services\Commerce\PhoneBlockImports;
use App\Services\JwtTokenService;
use App\Support\Tenancy\CurrentStore;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** G3 (TAR-036) #19: bulk blocked-phone import (streamed, normalized, deduplicated, chunked, resumable) and export. */
class PhoneBlockImportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private array $auth;

    private string $endpoint = '/api/v1/my-store/blocked-phone-numbers';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionTableSeeder::class, RolesTableSeeder::class]);
        Storage::fake('local');
        $this->owner = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $this->owner->assignRole('Merchant');
        $this->auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($this->owner), 'Accept' => 'application/json'];
        $this->store = Store::create(['owner_user_id' => $this->owner->id, 'owner_type' => 'merchant', 'name' => 'Blocking', 'slug' => 'phone-import', 'currency' => 'EGP', 'status' => 'active']);
    }

    private function csv(string $content, string $name = 'phones.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(UploadedFile $file, ?string $endpoint = null): array
    {
        return $this->post(($endpoint ?? $this->endpoint).'/imports', ['file' => $file, 'request_key' => (string) Str::uuid()], $this->auth)->assertStatus(202)->json('data');
    }

    private function existing(string $phone, bool $active, string $scope = 'checkout'): void
    {
        StorePhoneBlock::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'scope' => $scope, 'phone' => $phone, 'phone_normalized' => $phone,
            'phone_country' => 'EG', 'active' => $active, 'version' => 1]);
    }

    public function test_csv_import_normalizes_with_store_country_dedupes_skips_existing_and_audits_each_new_block(): void
    {
        Bus::fake([ProcessStorePhoneBlockImport::class]);
        $this->existing('+201001112222', true);
        $this->existing('+201003334444', false);
        $lines = ["\u{FEFF}Name;Phone;Note", 'A;01001234567;fraud', 'B;+20 100 123 4567;same number', 'C;00201005556666;', 'D;not-a-phone;', 'E;01001112222;already',
            'F;01003334444;was unblocked', 'G;٠١٠٠٧٧٧٨٨٨٨;arabic digits', ';;'];
        $import = $this->upload($this->csv(implode("\n", $lines)));
        $this->assertSame('queued', $import['status']);
        Bus::assertDispatchedAfterResponse(ProcessStorePhoneBlockImport::class, fn ($job) => $job->importId === $import['id']);
        $this->assertCount(1, Storage::disk('local')->allFiles('store-phone-imports'));
        $stored = Storage::disk('local')->get(Storage::disk('local')->allFiles('store-phone-imports')[0]);
        $this->assertStringNotContainsString('01001234567', $stored);

        $this->artisan('store-phone-blocks:import')->assertSuccessful();
        $done = $this->getJson($this->endpoint.'/imports/'.$import['id'], $this->auth)->assertOk()->json('data');
        $this->assertSame(['completed', 7, 7, 3, 2, 1, 1], [$done['status'], $done['total_rows'], $done['processed_rows'], $done['imported'], $done['duplicates'], $done['invalid'], $done['skipped_inactive']]);
        $this->assertSame([['row' => 5, 'value' => 'not-a-phone']], $done['invalid_samples']);
        $this->assertEqualsCanonicalizing(['+201001234567', '+201005556666', '+201007778888'],
            StorePhoneBlock::withoutGlobalScopes()->where('store_id', $this->store->id)->where('created_at', '>=', now()->subMinute())->whereNotIn('phone_normalized', ['+201001112222', '+201003334444'])->pluck('phone_normalized')->all());
        $this->assertSame('fraud', StorePhoneBlock::withoutGlobalScopes()->where('phone_normalized', '+201001234567')->value('note'));
        $this->assertFalse((bool) StorePhoneBlock::withoutGlobalScopes()->where('phone_normalized', '+201003334444')->value('active'));
        $this->assertDatabaseCount('store_phone_block_events', 3);
        $this->assertDatabaseHas('store_phone_block_events', ['actor_id' => $this->owner->id, 'action' => 'blocked']);
        $this->assertSame([], Storage::disk('local')->allFiles('store-phone-imports'));
        // Checkout enforcement uses the imported entries.
        $this->getJson($this->endpoint.'?q=01005556666', $this->auth)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_large_headerless_import_is_chunked_resumable_and_exportable(): void
    {
        Bus::fake([ProcessStorePhoneBlockImport::class]);
        $rows = [];
        for ($i = 0; $i < 20000; $i++) {
            $rows[] = '010'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT);
        }
        $rows[] = $rows[0];
        $import = $this->upload($this->csv(implode("\r\n", $rows)));
        $row = StorePhoneBlockImport::query()->findOrFail($import['id']);
        app(PhoneBlockImports::class)->run($row->id);
        $done = $this->getJson($this->endpoint.'/imports/'.$import['id'], $this->auth)->assertOk()->json('data');
        $this->assertSame(['completed', 20001, 20000, 1, 0], [$done['status'], $done['processed_rows'], $done['imported'], $done['duplicates'], $done['invalid']]);
        $this->assertSame(20000, StorePhoneBlock::withoutGlobalScopes()->where('store_id', $this->store->id)->count());
        $this->assertDatabaseCount('store_phone_block_events', 20000);

        // Resume: a second import whose lease expired after 1000 rows continues from row 1001.
        $more = [];
        for ($i = 0; $i < 1500; $i++) {
            $more[] = '011'.str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT);
        }
        $second = $this->upload($this->csv("phone\n".implode("\n", $more)));
        StorePhoneBlockImport::query()->whereKey($second['id'])->update(['status' => 'processing', 'lease_until' => now()->subMinute(), 'processed_rows' => 1000, 'total_rows' => 1500, 'imported' => 1000]);
        app(PhoneBlockImports::class)->process();
        $resumed = StorePhoneBlockImport::query()->findOrFail($second['id']);
        $this->assertSame(['completed', 1500, 1500], [$resumed->status, $resumed->processed_rows, $resumed->imported]);
        $this->assertSame(500, StorePhoneBlock::withoutGlobalScopes()->where('phone_normalized', 'like', '+2011%')->count());

        $csv = $this->get($this->endpoint.'/export?status=active', $this->auth)->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(20501, $lines);
        $this->assertStringStartsWith("\u{FEFF}phone,phone_normalized,status", $lines[0]);
    }

    public function test_xlsx_import_otp_list_is_separate_and_only_one_import_runs_per_list(): void
    {
        Bus::fake([ProcessStorePhoneBlockImport::class]);
        $path = tempnam(sys_get_temp_dir(), 'phones-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['رقم الهاتف', 'ملاحظة']));
        $writer->addRow(Row::fromValues([1001234567, 'numeric cell without leading zero']));
        $writer->addRow(Row::fromValues(['+966501234567', 'international']));
        $writer->close();
        $otp = $this->upload(new UploadedFile($path, 'phones.xlsx', null, null, true), '/api/v1/my-store/blocked-phones');
        $this->artisan('store-phone-blocks:import')->assertSuccessful();
        $this->getJson('/api/v1/my-store/blocked-phones/imports/'.$otp['id'], $this->auth)->assertOk()->assertJsonPath('data.imported', 2)->assertJsonPath('data.status', 'completed');
        $this->assertSame(2, StorePhoneBlock::withoutGlobalScopes()->where('scope', 'otp')->count());
        $this->assertSame(0, StorePhoneBlock::withoutGlobalScopes()->where('scope', 'checkout')->count());
        $this->getJson($this->endpoint.'/imports/'.$otp['id'], $this->auth)->assertNotFound();

        StorePhoneBlockImport::query()->create(['id' => (string) Str::uuid(), 'store_id' => $this->store->id, 'user_id' => $this->owner->id, 'request_key' => (string) Str::uuid(),
            'scope' => 'checkout', 'status' => 'processing', 'file_name' => 'x.csv', 'format' => 'csv', 'lease_until' => now()->addMinutes(5)]);
        $this->post($this->endpoint.'/imports', ['file' => $this->csv('01001234567'), 'request_key' => (string) Str::uuid()], $this->auth)->assertStatus(409);
        $this->post($this->endpoint.'/imports', ['file' => UploadedFile::fake()->create('phones.pdf', 10), 'request_key' => (string) Str::uuid()], $this->auth)->assertJsonValidationErrors('file');
    }

    public function test_imports_are_tenant_isolated_and_require_order_management_access(): void
    {
        $import = $this->upload($this->csv('01001234567'));
        app(CurrentStore::class)->forget();
        $intruder = User::factory()->create(['is_active' => true, 'pending_approval' => false]);
        $intruder->assignRole('Merchant');
        Store::create(['owner_user_id' => $intruder->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-phones', 'currency' => 'EGP', 'status' => 'active']);
        $auth = ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($intruder), 'Accept' => 'application/json'];
        // Without a fake bus the upload is processed right after the response.
        $this->assertSame('completed', StorePhoneBlockImport::query()->findOrFail($import['id'])->status);
        $this->getJson($this->endpoint.'/imports/'.$import['id'], $auth)->assertNotFound();
        $this->getJson($this->endpoint.'/imports', $auth)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('', trim(str_replace(["\u{FEFF}", 'phone,phone_normalized,status,note,created_at,updated_at'], '', $this->get($this->endpoint.'/export', $auth)->assertOk()->streamedContent())));

        $employee = User::factory()->create(['is_active' => true, 'pending_approval' => false, 'parent_user_id' => $this->owner->id]);
        $this->post($this->endpoint.'/imports', ['file' => $this->csv('01001234567'), 'request_key' => (string) Str::uuid()],
            ['Authorization' => 'Bearer '.JwtTokenService::fromConfig()->issueAccessToken($employee), 'Accept' => 'application/json'])->assertForbidden();
    }
}

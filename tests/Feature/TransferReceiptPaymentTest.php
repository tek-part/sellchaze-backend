<?php

namespace Tests\Feature;

use App\Jobs\BridgeStorefrontOrderJob;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreOrder;
use App\Models\StorePaymentGateway;
use App\Models\User;
use App\Services\Commerce\ManualBankPayment;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\StorePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferReceiptPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake([BridgeStorefrontOrderJob::class]);
        $owner = User::factory()->create();
        $store = Store::create(['owner_user_id' => $owner->id, 'owner_type' => 'merchant', 'name' => 'Proof', 'slug' => 'proof', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $store->id, 'host' => 'proof.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $setting = StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'transfer_receipt', 'enabled' => true, 'credentials' => ['account_name' => 'Synthetic', 'iban' => 'LOCAL-ONLY']]);
        $order = StoreOrder::withoutGlobalScopes()->create(['store_id' => $store->id, 'order_number' => 'PROOF-1', 'status' => 'pending', 'currency' => 'EGP',
            'customer_name' => 'Synthetic Buyer', 'payment_method' => 'transfer_receipt', 'payment_status' => 'pending', 'subtotal' => 100, 'grand_total' => 100]);
        app(StorePaymentService::class)->start($store, $order, $setting);

        return [$store, $order, $owner, app(OrderReceipt::class)->make($order)['token']];
    }

    public function test_upload_remains_pending_and_private_until_merchant_confirmation(): void
    {
        [$store, $order, $owner, $token] = $this->fixtures();
        $response = $this->post('http://proof.sellchase.com/api/v1/storefront/checkout/receipt/upload',
            ['token' => $token, 'file' => UploadedFile::fake()->image('receipt.png'), 'reference' => 'LOCAL-REF'], ['Accept' => 'application/json'])->assertCreated();
        $row = DB::table('store_payment_proofs')->where('id', $response->json('data.id'))->first();
        Storage::disk('local')->assertExists($row->path);
        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertNothingSent();
        app(ManualBankPayment::class)->confirm($order, 'BANK-VERIFIED', $owner->id, 'Internal review note');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('accepted', DB::table('store_payment_proofs')->where('id', $row->id)->value('status'));
        $receipt = $this->postJson('http://proof.sellchase.com/api/v1/storefront/checkout/receipt', ['token' => $token])->assertOk();
        $this->assertStringNotContainsString('Internal review note', $receipt->getContent());
        $this->assertStringNotContainsString('payment-proofs/', $receipt->getContent());
        $this->post('http://proof.sellchase.com/api/v1/storefront/checkout/receipt/upload', ['token' => $token, 'file' => UploadedFile::fake()->image('again.png')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_forged_token_and_wrong_store_cannot_upload_a_receipt(): void
    {
        [, $order, , $token] = $this->fixtures();
        $this->post('http://proof.sellchase.com/api/v1/storefront/checkout/receipt/upload', ['token' => 'forged', 'file' => UploadedFile::fake()->image('proof.png')], ['Accept' => 'application/json'])->assertNotFound();
        $other = Store::create(['owner_user_id' => User::factory()->create()->id, 'owner_type' => 'merchant', 'name' => 'Other', 'slug' => 'other-proof', 'currency' => 'EGP', 'status' => 'active']);
        StoreDomain::create(['store_id' => $other->id, 'host' => 'other-proof.sellchase.com', 'type' => 'subdomain', 'is_primary' => true]);
        $this->post('http://other-proof.sellchase.com/api/v1/storefront/checkout/receipt/upload', ['token' => $token, 'file' => UploadedFile::fake()->image('proof.png')], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertDatabaseCount('store_payment_proofs', 0);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_confirmation_without_a_receipt_and_non_receipt_payment_methods_are_rejected(): void
    {
        [, $order, $owner, $token] = $this->fixtures();
        try {
            app(ManualBankPayment::class)->confirm($order, 'NOT-REVIEWED', $owner->id);
            $this->fail('Payment without a receipt was confirmed.');
        } catch (ValidationException) {
            $this->assertSame('pending', $order->fresh()->payment_status);
        }
        $order->update(['payment_method' => 'cod']);
        $this->post('http://proof.sellchase.com/api/v1/storefront/checkout/receipt/upload', ['token' => $token, 'file' => UploadedFile::fake()->image('proof.png')], ['Accept' => 'application/json'])->assertUnprocessable();
        Storage::disk('local')->assertDirectoryEmpty('payment-proofs');
    }
}

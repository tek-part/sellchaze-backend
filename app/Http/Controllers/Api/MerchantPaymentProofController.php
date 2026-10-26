<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\PlatformWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MerchantPaymentProofController extends Controller
{
    private function order(Store $store, int $id): StoreOrder
    {
        $order = StoreOrder::forStore($store)->whereKey($id)->firstOrFail();
        app(PlatformWallet::class)->assertOrderVisible($order);

        return $order;
    }

    public function index(Request $request, Store $store, int $order)
    {
        $this->order($store, $order);

        return response()->json(['data' => DB::table('store_payment_proofs')->where('store_id', $store->id)->where('store_order_id', $order)
            ->orderByDesc('created_at')->get(['id', 'mime', 'size_bytes', 'reference', 'status', 'review_note', 'reviewed_at', 'created_at'])])->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, Store $store, int $order, string $proof)
    {
        $this->order($store, $order);
        $row = DB::table('store_payment_proofs')->where('store_id', $store->id)->where('store_order_id', $order)->where('id', $proof)->first();
        abort_unless($row, 404);
        abort_unless(str_starts_with($row->path, 'payment-proofs/'.$store->id.'/'.$order.'/'), 404);

        return Storage::disk('local')->download($row->path, 'transfer-receipt.'.(['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$row->mime] ?? 'bin'),
            ['Content-Type' => $row->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }

    public function reject(Request $request, Store $store, int $order, string $proof)
    {
        $this->order($store, $order);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $store, $order, $proof, $data) {
            $locked = StoreOrder::forStore($store)->whereKey($order)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->payment_status, ['paid', 'deposit_paid'], true), 422, 'The transfer has already been confirmed.');
            $changed = DB::table('store_payment_proofs')->where('store_id', $store->id)->where('store_order_id', $order)->where('id', $proof)->where('status', 'pending')
                ->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'review_note' => $data['note'], 'reviewed_at' => now(), 'updated_at' => now()]);
            abort_unless($changed, 422, 'This receipt is no longer awaiting review.');
        });

        return $this->index($request, $store, $order);
    }
}

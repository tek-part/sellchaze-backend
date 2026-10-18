<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Models\StoreOrder;
use App\Services\Commerce\OrderReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PaymentProofController extends Controller
{
    use ResolvesStorefront;

    public function __invoke(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:1000'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'], 'reference' => ['nullable', 'string', 'max:200']]);
        $store = $this->currentStore($request);
        $id = app(OrderReceipt::class)->verify($data['token'], $store->id);
        abort_unless($id, 404, 'Invalid private receipt.');
        $path = null;
        try {
            return DB::transaction(function () use ($request, $store, $id, $data, &$path) {
                $order = StoreOrder::forStore($store)->whereKey($id)->lockForUpdate()->firstOrFail();
                abort_unless($order->payment_method === 'transfer_receipt' && $order->payment_status !== 'paid'
                    && ! in_array($order->status, ['cancelled', ...StoreOrder::RETURN_STATUSES], true), 422, 'This order cannot accept a transfer receipt.');
                abort_if(DB::table('store_payment_proofs')->where('store_id', $store->id)->where('store_order_id', $order->id)->count() >= 3, 422, 'The receipt upload limit for this order has been reached. Contact the store.');
                $file = $request->file('file');
                $proof = (string) Str::uuid();
                $path = $file->storeAs('payment-proofs/'.$store->id.'/'.$order->id, $proof.'.'.$file->extension(), 'local');
                DB::table('store_payment_proofs')->insert(['id' => $proof, 'store_id' => $store->id, 'store_order_id' => $order->id, 'path' => $path,
                    'mime' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'reference' => $data['reference'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

                return response()->json(['data' => ['id' => $proof, 'status' => 'pending']], 201)->header('Cache-Control', 'private, no-store');
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}

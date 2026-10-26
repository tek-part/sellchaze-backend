<?php

namespace App\Services\Commerce;

use App\Models\StoreOrder;
use App\Models\StoreSheetConnection;
use App\Models\StoreSheetDelivery;
use Illuminate\Support\Facades\DB;

class StoreSheets
{
    /** Sheets connected before gift cards keep this 17-column layout (A:Q); rows written there stop at Reference. */
    public const LEGACY_HEADERS = ['Order number', 'Placed at', 'Customer', 'Phone', 'Email', 'Address', 'Products', 'Quantity', 'Subtotal', 'Shipping', 'Discount', 'Tax', 'Total', 'Currency', 'Payment method', 'Status', 'Reference'];

    /** New sheets add what gift cards paid and the amount still due (A:S). */
    public const HEADERS = [...self::LEGACY_HEADERS, 'Gift cards', 'Amount due'];

    public function record(StoreOrder $order): void
    {
        $order->loadMissing(['items' => fn ($q) => $q->withoutGlobalScopes()->where('store_id', $order->store_id)]);
        $ids = $order->items->pluck('store_product_id')->filter()->map(fn ($id) => (int) $id)->all();
        DB::transaction(function () use ($order, $ids) {
            $connections = StoreSheetConnection::forStore((int) $order->store_id)->where('enabled', true)->orderBy('id')->lockForUpdate()->get();
            foreach ($connections as $connection) {
                if ($order->created_at->lessThan($connection->starts_at) || (! $connection->all_products && ! array_intersect($ids, $connection->product_ids))
                    || StoreSheetDelivery::forStore((int) $order->store_id)->where('connection_id', $connection->id)->where('store_order_id', $order->id)->exists()) {
                    continue;
                }
                $reference = hash('sha256', $connection->id.':'.$order->id);
                $address = json_encode($order->shipping_address ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $values = [(string) $order->order_number, $order->placed_at?->toIso8601String() ?? '', (string) $order->customer_name,
                    (string) $order->customer_phone, (string) $order->customer_email, $address,
                    $order->items->map(fn ($item) => $item->name.' × '.$item->quantity)->implode('; '), (string) $order->items->sum('quantity'),
                    $order->subtotal, $order->shipping_total, $order->discount_total, $order->tax_total, $order->grand_total, (string) $order->currency, (string) $order->payment_method, (string) $order->status, $reference,
                    (string) ($order->gift_card_total ?? '0.00'), $order->amountDue()];
                StoreSheetDelivery::create(['store_id' => $order->store_id, 'connection_id' => $connection->id, 'store_order_id' => $order->id,
                    'row_number' => $connection->next_row, 'payload' => ['values' => $values, 'reference' => $reference], 'status' => 'pending']);
                $connection->increment('next_row');
            }
        });
    }

    public function present(StoreSheetConnection $row): array
    {
        $latest = StoreSheetDelivery::forStore((int) $row->store_id)->where('connection_id', $row->id)->latest('id')->first();

        return ['id' => $row->id, 'spreadsheet_id' => $row->spreadsheet_id, 'service_email' => $row->service_email,
            'enabled' => $row->enabled, 'all_products' => $row->all_products, 'product_ids' => $row->product_ids,
            'version' => $row->version, 'starts_at' => $row->starts_at, 'sheet_title' => $row->sheet_title,
            'credentials_configured' => true, 'last_delivery' => $latest ? ['id' => $latest->id, 'status' => $latest->status, 'error_code' => $latest->error_code, 'attempts' => $latest->attempts, 'delivered_at' => $latest->delivered_at] : null];
    }
}

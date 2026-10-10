<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Commerce\DigitalDeliverySettings;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendDigitalWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $messageId) {}

    public function handle(StoreDigitalWhatsappClient $client, DigitalDeliverySettings $settings): void
    {
        // Claim is committed before external I/O. An abandoned sending claim is uncertain,
        // not permission for a second send. Outbox/queue replay therefore cannot resend it.
        $claim = DB::transaction(function () use ($settings): ?array {
            $message = OutboxMessage::query()->whereKey($this->messageId)->lockForUpdate()->first();
            if (! $message || ! $message->published_at || $message->event_type !== DigitalDeliverySettings::CHANNELS['whatsapp'] || isset($message->metadata['transport_state'])) {
                return null;
            }
            $storeId = (int) $message->payload['store_id'];
            $store = Store::find($storeId);
            $order = StoreOrder::forStore($storeId)->whereKey($message->payload['store_order_id'])->lockForUpdate()->first();
            $item = StoreOrderItem::withoutGlobalScopes()->where('store_id', $storeId)->where('store_order_id', $order?->id)->whereKey($message->payload['store_order_item_id'])->first();
            $active = $store && $item ? $settings->effective($store, (int) $item->store_product_id) : null;
            $phone = StoreDigitalWhatsappClient::phone((string) ($message->payload['recipient'] ?? ''));
            $text = $store && $order && $item ? DigitalDeliverySettings::render($message->metadata['settings']['whatsapp_body'], DigitalDeliverySettings::variables($store, $order, $item)) : '';
            $eligible = $store && $order && $item && $order->payment_status === 'paid' && $order->status !== 'cancelled'
                && $active['enabled'] && $active['whatsapp_enabled'] && ! empty($store->digital_delivery_credentials['verified_at']);
            $state = ! $eligible ? 'skipped' : (! $phone || ! $text || mb_strlen($text) > 4096 ? 'invalid' : 'sending');
            $message->update(['metadata' => array_merge($message->metadata ?? [], ['transport_state' => $state, 'transport_claimed_at' => now()->toIso8601String()])]);

            return $state === 'sending' ? ['credentials' => $store->digital_delivery_credentials, 'phone' => $phone, 'text' => $text] : null;
        });
        if (! $claim) {
            return;
        }
        $result = $client->send($claim['credentials'], $claim['phone'], $claim['text']);
        DB::transaction(function () use ($result) {
            $message = OutboxMessage::query()->whereKey($this->messageId)->lockForUpdate()->firstOrFail();
            $message->update(['metadata' => array_merge($message->metadata ?? [], ['transport_state' => $result['state'], 'provider_reference' => $result['reference'] ?? null, 'transport_finished_at' => now()->toIso8601String()])]);
        });
    }
}

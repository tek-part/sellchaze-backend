<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendStoreNotificationWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $messageId) {}

    public function handle(StoreDigitalWhatsappClient $client, StoreNotificationSettings $notifications): void
    {
        // Claim is committed before external I/O. An abandoned sending claim is uncertain, not
        // permission for a second send, so outbox/queue replay can never message a customer twice.
        $claim = DB::transaction(function () use ($notifications): ?array {
            $message = OutboxMessage::query()->whereKey($this->messageId)->lockForUpdate()->first();
            if (! $message || ! $message->published_at || $message->event_type !== StoreNotificationSettings::MESSAGE_TYPES['whatsapp']
                || in_array($message->metadata['transport_state'] ?? null, ['sending', 'accepted', 'rejected', 'unknown', 'skipped', 'invalid'], true)) {
                return null;
            }
            $storeId = (int) $message->payload['store_id'];
            $store = Store::find($storeId);
            $order = StoreOrder::forStore($storeId)->whereKey($message->payload['store_order_id'])->with('items')->first();
            $event = (string) $message->payload['event'];
            $phone = StoreDigitalWhatsappClient::phone((string) ($message->payload['recipient'] ?? ''));
            $settings = $store ? $notifications->configured($store) : null;
            $eligible = $store && $order && $notifications->whatsappReady($store)
                && (! empty($message->metadata['resend']) || ! empty($settings['events'][$event]['whatsapp_enabled']))
                && $phone === StoreDigitalWhatsappClient::phone((string) $order->customer_phone);
            $text = $eligible ? StoreNotificationSettings::render((string) $settings['events'][$event]['whatsapp_body'], $notifications->variables($store, $order)) : '';
            $state = ! $eligible ? 'skipped' : ($text === '' || mb_strlen($text) > 4096 ? 'invalid' : 'sending');
            $message->update(['metadata' => array_merge($message->metadata ?? [], ['transport_state' => $state, 'transport_claimed_at' => now()->toIso8601String()])]);

            return $state === 'sending' ? ['credentials' => $store->digital_delivery_credentials, 'phone' => $phone, 'text' => $text] : null;
        });
        if (! $claim) {
            return;
        }
        $result = $client->send($claim['credentials'], $claim['phone'], $claim['text']);
        DB::transaction(function () use ($result) {
            $message = OutboxMessage::query()->whereKey($this->messageId)->lockForUpdate()->firstOrFail();
            $message->update(['metadata' => array_merge($message->metadata ?? [], ['transport_state' => $result['state'], 'provider_reference' => $result['reference'] ?? null,
                'sent_at' => $result['state'] === 'accepted' ? now()->toIso8601String() : null, 'transport_finished_at' => now()->toIso8601String()])]);
        });
    }
}

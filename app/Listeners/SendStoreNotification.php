<?php

namespace App\Listeners;

use App\Events\DomainEventPublished;
use App\Jobs\SendStoreNotificationWhatsapp;
use App\Mail\StoreNotificationMail;
use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Notifications\StoreNotificationSettings;
use Illuminate\Support\Facades\Mail;

/** Delivers queued order notifications; settings are re-read at send time so a disabled event stops. */
class SendStoreNotification
{
    public function handle(DomainEventPublished $event): void
    {
        $channel = array_search($event->message->event_type, StoreNotificationSettings::MESSAGE_TYPES, true);
        if ($channel === false) {
            return;
        }
        $payload = $event->message->payload;
        $storeId = (int) ($payload['store_id'] ?? 0);
        $store = Store::find($storeId);
        $order = StoreOrder::forStore($storeId)->whereKey($payload['store_order_id'] ?? 0)->with('items')->first();
        $key = (string) ($payload['event'] ?? '');
        $definition = StoreNotificationSettings::events()[$key] ?? null;
        $settings = $store ? app(StoreNotificationSettings::class)->configured($store) : null;
        $enabled = $settings && $definition && (! empty($event->message->metadata['resend']) || ! empty($settings['events'][$key][$channel.'_enabled']));
        if (! $order || ! $enabled) {
            self::outcome($event->message, 'skipped');

            return;
        }
        if ($channel === 'whatsapp') {
            SendStoreNotificationWhatsapp::dispatch($event->message->id)->afterCommit();
            self::outcome($event->message, 'queued');

            return;
        }
        $config = $settings['events'][$key];
        try {
            Mail::to($payload['recipient'])->send(new StoreNotificationMail($store, $order, $key,
                ['subject' => $config['email_subject'], 'body' => $config['email_body']], $event->message->id));
        } catch (\Throwable) {
            // Provider errors can echo addresses; the outbox keeps only this generic reason and retries.
            throw new \RuntimeException('Store notification email could not be sent; retry is pending.');
        }
        self::outcome($event->message, 'sent', ['sent_at' => now()->toIso8601String()]);
    }

    public static function outcome(OutboxMessage $message, string $state, array $extra = []): void
    {
        $message->update(['metadata' => array_merge($message->metadata ?? [], ['transport_state' => $state], $extra)]);
    }
}

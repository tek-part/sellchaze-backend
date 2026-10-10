<?php

namespace App\Services\Commerce;

use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Outbox\OutboxRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DigitalDeliveryTracking
{
    /** @return Builder<OutboxMessage> */
    private function messages(StoreOrder $order): Builder
    {
        return OutboxMessage::query()->where('aggregate_type', 'store_order')->where('aggregate_id', (string) $order->id)
            ->where('payload->store_id', $order->store_id)->where('payload->store_order_id', $order->id)
            ->whereIn('event_type', array_values(DigitalDeliverySettings::CHANNELS));
    }

    public function status(StoreOrder $order): array
    {
        $store = Store::findOrFail($order->store_id);
        if ($store->digital_delivery_configuration === null) {
            return [];
        }
        $messages = $this->messages($order)->orderByDesc('created_at')->orderByDesc('id')->get();
        // The paid parent may not have fanned out yet. A manual item send in
        // that interval would race its automatic child and send the same item twice.
        $parentPending = OutboxMessage::query()->where('aggregate_type', 'store_order')->where('aggregate_id', (string) $order->id)
            ->where('payload->store_id', $order->store_id)->where('payload->store_order_id', $order->id)
            ->where('event_type', 'StorefrontDigitalPaid')->whereNull('published_at')->whereNull('failed_at')->exists();
        $rows = [];
        foreach ($order->items()->withoutGlobalScopes()->where('store_id', $order->store_id)->whereNotNull('digital_delivery')->get() as $item) {
            $settings = app(DigitalDeliverySettings::class)->effective($store, (int) $item->store_product_id);
            foreach (DigitalDeliverySettings::CHANNELS as $channel => $type) {
                $message = $messages->first(fn ($row) => $row->event_type === $type && (int) $row->payload['store_order_item_id'] === $item->id);
                $state = ! $message ? 'not_scheduled' : (! $message->published_at ? ($message->failed_at ? 'failed' : ($message->attempts > 0 ? 'retrying' : 'queued')) : ($channel === 'email' ? ($message->metadata['mail_outcome'] ?? 'processed') : ($message->metadata['transport_state'] ?? 'queued')));
                if (! $message && $parentPending && $settings['enabled'] && $settings[$channel.'_enabled']) {
                    $state = 'queued';
                }
                if ($state === 'sending' && CarbonImmutable::parse($message->metadata['transport_claimed_at'])->addMinutes(2)->isPast()) {
                    $state = 'unknown';
                }
                $cooldown = ! empty($message?->metadata['mail_sent_at']) ? CarbonImmutable::parse($message->metadata['mail_sent_at'])->addSeconds(60) : null;
                $eligible = $settings['enabled'] && $settings[$channel.'_enabled'] && $order->payment_status === 'paid' && $order->status !== 'cancelled';
                $rows[] = ['item_id' => $item->id, 'product_name' => $item->name, 'channel' => $channel, 'message_id' => $message?->id,
                    'recipient' => $channel === 'email' ? $order->customer_email : $order->customer_phone,
                    'state' => $state, 'attempts' => (int) ($message?->attempts ?? 0),
                    'provider_reference' => $channel === 'whatsapp' ? ($message?->metadata['provider_reference'] ?? null) : null,
                    'sent_at' => $message?->metadata['mail_sent_at'] ?? null,
                    'can_resend' => $channel === 'email' && $eligible && filled($order->customer_email) && in_array($state, ['not_scheduled', 'failed', 'sent', 'skipped'], true) && (! $cooldown || $cooldown->isPast())];
            }
        }

        return $rows;
    }

    /** Audited per-item email resend with immutable recipient and UUID replay. No WhatsApp resend. */
    public function resendEmail(StoreOrder $order, int $itemId, ?string $expectedId, string $key, int $actorId): void
    {
        DB::transaction(function () use ($order, $itemId, $expectedId, $key, $actorId) {
            if ($expectedId) {
                $this->messages($order)->whereKey($expectedId)->where('event_type', DigitalDeliverySettings::CHANNELS['email'])->where('payload->store_order_item_id', $itemId)->lockForUpdate()->firstOrFail();
            }
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $current->items()->withoutGlobalScopes()->where('store_id', $current->store_id)->whereKey($itemId)->whereNotNull('digital_delivery')->firstOrFail();
            $hash = hash('sha256', $key);
            $existing = $this->messages($current)->where('metadata->request_hash', $hash)->first();
            if ($existing) {
                if ((int) $existing->payload['store_order_item_id'] !== $itemId) {
                    throw ValidationException::withMessages(['request_key' => 'This request belongs to another product delivery.']);
                }

                return;
            }
            $latest = $this->messages($current)->where('event_type', DigitalDeliverySettings::CHANNELS['email'])->where('payload->store_order_item_id', $itemId)->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($latest?->id !== $expectedId) {
                throw ValidationException::withMessages(['message_id' => 'Delivery changed. Refresh before sending again.']);
            }
            $status = collect($this->status($current))->first(fn ($row) => $row['item_id'] === $itemId && $row['channel'] === 'email');
            if (! $status || ! $status['can_resend']) {
                throw ValidationException::withMessages(['email' => 'This product email cannot be sent now.']);
            }
            $store = Store::findOrFail($current->store_id);
            app(OutboxRecorder::class)->record(DigitalDeliverySettings::CHANNELS['email'], 'store_order', $current->id,
                ['store_id' => $store->id, 'store_order_id' => $current->id, 'store_order_item_id' => $item->id, 'recipient' => $current->customer_email],
                ['settings' => DigitalDeliverySettings::snapshot(app(DigitalDeliverySettings::class)->effective($store, (int) $item->store_product_id)), 'request_hash' => $hash, 'source_message_id' => $expectedId, 'actor_id' => $actorId]);
            $current->statusChanges()->create(['store_id' => $current->store_id, 'from_status' => $current->status, 'to_status' => $current->status, 'actor_id' => $actorId, 'source' => 'email_resend', 'notes' => 'Digital product item '.$item->id.' email queued by merchant.']);
        });
    }
}

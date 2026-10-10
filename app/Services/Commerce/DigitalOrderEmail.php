<?php

namespace App\Services\Commerce;

use App\Models\OutboxMessage;
use App\Models\StoreOrder;
use App\Services\Outbox\OutboxRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DigitalOrderEmail
{
    public const TYPES = ['receipt' => 'StorefrontDigitalReceipt', 'delivery' => 'StorefrontDigitalPaid'];

    /** @return Builder<OutboxMessage> */
    private function messages(StoreOrder $order): Builder
    {
        return OutboxMessage::query()->where('aggregate_type', 'store_order')->where('aggregate_id', (string) $order->id)
            ->where('payload->store_id', (int) $order->store_id)->where('payload->store_order_id', (int) $order->id)->whereIn('event_type', array_values(self::TYPES));
    }

    private function eligible(StoreOrder $order, string $kind): bool
    {
        return $order->status !== 'cancelled' && filled($order->customer_email)
            && ($kind === 'delivery' ? $order->payment_status === 'paid' : $order->payment_status !== 'paid')
            && $order->items()->withoutGlobalScopes()->where('store_id', $order->store_id)->whereNotNull('digital_delivery')->exists();
    }

    public function status(StoreOrder $order): array
    {
        $rows = $this->messages($order)->orderByDesc('created_at')->orderByDesc('id')->get()->unique('event_type')->keyBy('event_type');
        $data = [];
        foreach (self::TYPES as $kind => $type) {
            $message = $rows->get($type);
            $state = $message === null ? 'not_scheduled' : ($message->published_at ? ($message->metadata['mail_outcome'] ?? 'processed') : ($message->failed_at ? 'failed' : ($message->attempts > 0 ? 'retrying' : 'queued')));
            $cooldown = $message?->published_at ? CarbonImmutable::parse($message->published_at)->addSeconds(60) : null;
            $data[] = ['kind' => $kind, 'id' => $message?->id, 'state' => $state, 'attempts' => (int) ($message?->attempts ?? 0),
                'sent_at' => $message?->metadata['mail_sent_at'] ?? null, 'next_attempt_at' => in_array($state, ['queued', 'retrying'], true) ? $message?->available_at : null,
                'error' => $state === 'failed' ? 'Email could not be sent after automatic retries.' : null,
                'resend_after' => $cooldown && $cooldown->isFuture() ? $cooldown : null,
                'can_send' => $this->eligible($order, $kind) && in_array($state, ['not_scheduled', 'failed', 'sent', 'processed'], true) && (! $cooldown || ! $cooldown->isFuture())];
        }

        return $data;
    }

    /** A manual send creates a separate audited event; exhausted attempts remain historical. */
    public function request(StoreOrder $order, string $kind, ?string $expectedId, string $requestKey, int $actorId): void
    {
        DB::transaction(function () use ($order, $kind, $expectedId, $requestKey, $actorId) {
            // Publishers lock event then order. Follow the same order to avoid a
            // resend racing SMTP publication and inverting those locks.
            if ($expectedId !== null) {
                $this->messages($order)->whereKey($expectedId)->lockForUpdate()->firstOrFail();
            }
            $current = StoreOrder::forStore($order->store_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $requestKey);
            $existing = $this->messages($current)->where('metadata->request_hash', $hash)->first();
            if ($existing !== null) {
                if ($existing->event_type !== self::TYPES[$kind]) {
                    throw ValidationException::withMessages(['request_key' => 'This send request belongs to a different email.']);
                }

                return;
            }
            $latest = $this->messages($current)->where('event_type', self::TYPES[$kind])->orderByDesc('created_at')->orderByDesc('id')->first();
            if (($latest?->id) !== $expectedId) {
                throw ValidationException::withMessages(['message_id' => 'Email state changed. Refresh it before sending again.']);
            }
            $status = collect($this->status($current))->firstWhere('kind', $kind);
            if (! $status['can_send']) {
                throw ValidationException::withMessages(['email' => 'This email cannot be sent now. It may be queued, recently sent, cancelled or awaiting payment.']);
            }
            app(OutboxRecorder::class)->record(self::TYPES[$kind], 'store_order', $current->id, ['store_id' => $current->store_id, 'store_order_id' => $current->id],
                ['request_hash' => $hash, 'actor_id' => $actorId, 'source_message_id' => $latest?->id]);
            $current->statusChanges()->create(['store_id' => $current->store_id, 'from_status' => $current->status, 'to_status' => $current->status,
                'actor_id' => $actorId, 'source' => 'email_resend', 'notes' => $kind === 'receipt' ? 'Private receipt email queued by merchant.' : 'Digital delivery email queued by merchant.']);
        });
    }
}

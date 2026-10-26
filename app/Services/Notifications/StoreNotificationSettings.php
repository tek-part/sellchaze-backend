<?php

namespace App\Services\Notifications;

use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreShipment;
use App\Services\Commerce\OrderReceipt;
use App\Services\Commerce\StoreDigitalWhatsappClient;
use App\Services\Outbox\OutboxRecorder;
use App\Services\Storefront\StorefrontUrlGenerator;
use Illuminate\Support\Collection;

/**
 * Shopify-style order notifications: one editable template per event and channel, delivered through
 * the outbox (email via the platform mailer, WhatsApp via the store's own verified Wawp connection).
 *
 * Templates are plain text with `{variable}` tokens. Emails wrap the text in the branded layout with
 * the order summary, so merchants edit words, never HTML.
 */
class StoreNotificationSettings
{
    public const VARIABLES = ['customer_name', 'order_number', 'order_total', 'store_name', 'order_url', 'items',
        'payment_method', 'shipping_address', 'tracking_number', 'carrier'];

    /** Outbox event type per channel. */
    public const MESSAGE_TYPES = ['email' => 'StoreNotificationEmail', 'whatsapp' => 'StoreNotificationWhatsapp'];

    /** Order status → customer event. */
    public const STATUS_EVENTS = ['confirmed' => 'order_confirmed', 'shipped' => 'shipping_confirmation', 'delivered' => 'order_delivered',
        'cancelled' => 'order_cancelled', 'refunded' => 'order_refunded'];

    /** Payment methods whose orders are real before any payment arrives. */
    public const OFFLINE_METHODS = ['cod', 'bank_transfer', 'transfer_receipt'];

    public const MAX_STAFF_RECIPIENTS = 5;

    /**
     * @return array<string, array{audience: string, group: string, channels: list<string>, email: bool,
     *     label: array{ar: string, en: string}, description: array{ar: string, en: string}}>
     */
    public static function events(): array
    {
        return [
            'order_confirmation' => ['audience' => 'customer', 'group' => 'orders', 'channels' => ['email', 'whatsapp'], 'email' => true,
                'label' => ['ar' => 'تأكيد استلام الطلب', 'en' => 'Order confirmation'],
                'description' => ['ar' => 'تُرسل للعميل فور إتمام الطلب (أو بعد الدفع الإلكتروني).', 'en' => 'Sent to the customer when they place an order (or once an online payment succeeds).']],
            'order_confirmed' => ['audience' => 'customer', 'group' => 'orders', 'channels' => ['email', 'whatsapp'], 'email' => false,
                'label' => ['ar' => 'تأكيد الطلب من المتجر', 'en' => 'Order confirmed'],
                'description' => ['ar' => 'تُرسل عندما تغيّر حالة الطلب إلى «مؤكد»، مفيدة لطلبات الدفع عند الاستلام.', 'en' => 'Sent when you mark the order as confirmed — useful for cash-on-delivery orders.']],
            'order_cancelled' => ['audience' => 'customer', 'group' => 'orders', 'channels' => ['email', 'whatsapp'], 'email' => true,
                'label' => ['ar' => 'إلغاء الطلب', 'en' => 'Order cancelled'],
                'description' => ['ar' => 'تُرسل عند إلغاء الطلب.', 'en' => 'Sent when the order is cancelled.']],
            'order_refunded' => ['audience' => 'customer', 'group' => 'orders', 'channels' => ['email', 'whatsapp'], 'email' => true,
                'label' => ['ar' => 'استرداد المبلغ', 'en' => 'Order refunded'],
                'description' => ['ar' => 'تُرسل عندما تصبح حالة الطلب «تم الاسترداد».', 'en' => 'Sent when the order is marked as refunded.']],
            'shipping_confirmation' => ['audience' => 'customer', 'group' => 'shipping', 'channels' => ['email', 'whatsapp'], 'email' => true,
                'label' => ['ar' => 'تم شحن الطلب', 'en' => 'Shipping confirmation'],
                'description' => ['ar' => 'تُرسل عند شحن الطلب، مع رقم التتبع إن وُجد.', 'en' => 'Sent when the order ships, with the tracking number when there is one.']],
            'order_delivered' => ['audience' => 'customer', 'group' => 'shipping', 'channels' => ['email', 'whatsapp'], 'email' => true,
                'label' => ['ar' => 'تم توصيل الطلب', 'en' => 'Order delivered'],
                'description' => ['ar' => 'تُرسل عند توصيل الطلب.', 'en' => 'Sent when the order is delivered.']],
            'staff_new_order' => ['audience' => 'staff', 'group' => 'staff', 'channels' => ['email'], 'email' => true,
                'label' => ['ar' => 'طلب جديد (للفريق)', 'en' => 'New order (staff)'],
                'description' => ['ar' => 'تُرسل لك ولعناوين الفريق عند كل طلب جديد.', 'en' => 'Sent to you and your staff addresses for every new order.']],
        ];
    }

    /** @return array{email_subject: string, email_body: string, whatsapp_body?: string} */
    private static function template(string $event, bool $ar): array
    {
        $t = [
            'order_confirmation' => $ar
                ? ['تم استلام طلبك {order_number}', "مرحبًا {customer_name}،\nشكرًا لطلبك من {store_name}. استلمنا طلبك رقم {order_number} وسنبدأ في تجهيزه.", "مرحبًا {customer_name} 👋\nاستلمنا طلبك رقم {order_number} من {store_name}.\nالإجمالي: {order_total}\nتفاصيل الطلب: {order_url}"]
                : ['Order {order_number} received', "Hi {customer_name},\nThank you for shopping with {store_name}. We've received order {order_number} and will start preparing it.", "Hi {customer_name} 👋\nWe've received your order {order_number} from {store_name}.\nTotal: {order_total}\nOrder details: {order_url}"],
            'order_confirmed' => $ar
                ? ['تم تأكيد طلبك {order_number}', "مرحبًا {customer_name}،\nتم تأكيد طلبك رقم {order_number} وجارٍ تجهيزه للشحن.", "مرحبًا {customer_name}\nتم تأكيد طلبك رقم {order_number} من {store_name} وجارٍ تجهيزه.\n{order_url}"]
                : ['Order {order_number} confirmed', "Hi {customer_name},\nYour order {order_number} is confirmed and being prepared for shipping.", "Hi {customer_name}\nYour order {order_number} from {store_name} is confirmed and being prepared.\n{order_url}"],
            'order_cancelled' => $ar
                ? ['تم إلغاء طلبك {order_number}', "مرحبًا {customer_name}،\nتم إلغاء طلبك رقم {order_number}. إذا كان لديك أي سؤال، يسعدنا تواصلك معنا.", "مرحبًا {customer_name}\nتم إلغاء طلبك رقم {order_number} من {store_name}. للاستفسار تواصل معنا."]
                : ['Order {order_number} cancelled', "Hi {customer_name},\nYour order {order_number} has been cancelled. If you have any questions, just reply to this email.", "Hi {customer_name}\nYour order {order_number} from {store_name} has been cancelled. Contact us with any questions."],
            'order_refunded' => $ar
                ? ['تم استرداد مبلغ طلبك {order_number}', "مرحبًا {customer_name}،\nتم استرداد مبلغ طلبك رقم {order_number}. قد يستغرق ظهوره في حسابك بضعة أيام حسب طريقة الدفع.", "مرحبًا {customer_name}\nتم استرداد مبلغ طلبك رقم {order_number} من {store_name}."]
                : ['Refund for order {order_number}', "Hi {customer_name},\nWe've refunded order {order_number}. Depending on your payment method it may take a few days to appear.", "Hi {customer_name}\nWe've refunded your order {order_number} from {store_name}."],
            'shipping_confirmation' => $ar
                ? ['طلبك {order_number} في الطريق إليك', "مرحبًا {customer_name}،\nخبر سعيد! تم شحن طلبك رقم {order_number}.", "مرحبًا {customer_name} 🚚\nتم شحن طلبك رقم {order_number} من {store_name}.\nشركة الشحن: {carrier}\nرقم التتبع: {tracking_number}\n{order_url}"]
                : ['Your order {order_number} is on the way', "Hi {customer_name},\nGood news! Order {order_number} has shipped.", "Hi {customer_name} 🚚\nYour order {order_number} from {store_name} has shipped.\nCarrier: {carrier}\nTracking number: {tracking_number}\n{order_url}"],
            'order_delivered' => $ar
                ? ['تم توصيل طلبك {order_number}', "مرحبًا {customer_name}،\nتم توصيل طلبك رقم {order_number}. نتمنى أن ينال إعجابك!", "مرحبًا {customer_name}\nتم توصيل طلبك رقم {order_number} من {store_name}. شكرًا لثقتك بنا 🌟"]
                : ['Order {order_number} delivered', "Hi {customer_name},\nYour order {order_number} has been delivered. We hope you love it!", "Hi {customer_name}\nYour order {order_number} from {store_name} has been delivered. Thank you for shopping with us 🌟"],
            'staff_new_order' => $ar
                ? ['طلب جديد {order_number} — {order_total}', "طلب جديد في {store_name} من {customer_name}.\nالدفع: {payment_method}"]
                : ['New order {order_number} — {order_total}', "New order on {store_name} from {customer_name}.\nPayment: {payment_method}"],
        ][$event];

        return array_filter(['email_subject' => $t[0], 'email_body' => $t[1], 'whatsapp_body' => $t[2] ?? null], fn ($value) => $value !== null);
    }

    public function defaults(Store $store): array
    {
        $ar = $store->default_locale === 'ar';
        $events = [];
        foreach (self::events() as $key => $event) {
            $events[$key] = array_merge(['email_enabled' => $event['email']], self::template($key, $ar),
                in_array('whatsapp', $event['channels'], true) ? ['whatsapp_enabled' => false] : []);
        }

        return ['sender_name' => '', 'reply_to' => null, 'staff_recipients' => [], 'events' => $events];
    }

    public function configured(Store $store): array
    {
        $defaults = $this->defaults($store);
        $saved = is_array($store->notification_settings) ? $store->notification_settings : [];
        $settings = array_replace($defaults, array_intersect_key($saved, array_flip(['sender_name', 'reply_to', 'staff_recipients'])));
        foreach ($defaults['events'] as $key => $event) {
            $settings['events'][$key] = array_replace($event, array_intersect_key($saved['events'][$key] ?? [], $event));
        }

        return $settings;
    }

    public function whatsappReady(Store $store): bool
    {
        $credentials = $store->digital_delivery_credentials ?? [];

        return ! empty($credentials['verified_at']) && ! empty($credentials['access_token']) && ! empty($credentials['instance_id']);
    }

    public static function render(string $template, array $variables): string
    {
        $values = [];
        foreach (self::VARIABLES as $key) {
            $values['{'.$key.'}'] = (string) ($variables[$key] ?? '');
        }

        // A line whose variables are all empty (e.g. "Tracking number: {tracking_number}" before the
        // shipment has one) is dropped rather than sent half-filled.
        $lines = [];
        foreach (preg_split('/\R/u', $template) as $line) {
            preg_match_all('/\{([a-z_]+)\}/', $line, $tokens);
            $used = array_intersect($tokens[1], self::VARIABLES);
            if ($used !== [] && collect($used)->every(fn ($key) => trim((string) ($variables[$key] ?? '')) === '')) {
                continue;
            }
            $lines[] = strtr($line, $values);
        }

        return trim(implode("\n", $lines));
    }

    public static function money(float|string|null $amount, ?string $currency): string
    {
        return number_format((float) $amount, 2).' '.strtoupper((string) $currency);
    }

    public function shipment(StoreOrder $order): ?StoreShipment
    {
        return $order->exists ? StoreShipment::query()->where('store_id', $order->store_id)->where('store_order_id', $order->id)->first() : null;
    }

    public function orderUrl(Store $store, StoreOrder $order): string
    {
        $token = $order->exists ? app(OrderReceipt::class)->make($order)['token'] : 'preview';

        return (string) app(StorefrontUrlGenerator::class)->publicUrl($store, '/order/success?number='.rawurlencode((string) $order->order_number).'#receipt='.rawurlencode($token));
    }

    public function dashboardUrl(StoreOrder $order): string
    {
        return rtrim((string) config('sellchase.frontend_url'), '/').'/store/orders/'.$order->id;
    }

    public static function address(?array $address): string
    {
        if (! $address) {
            return '';
        }
        $parts = [];
        foreach (['line', 'line1', 'line2', 'address', 'national_address', 'city', 'state', 'postal_code', 'country'] as $key) {
            if (isset($address[$key]) && is_scalar($address[$key]) && trim((string) $address[$key]) !== '') {
                $parts[] = trim((string) $address[$key]);
            }
        }

        return implode('، ', array_unique($parts));
    }

    /** @return array<string, string> */
    public function variables(Store $store, StoreOrder $order, bool $staff = false): array
    {
        $shipment = $this->shipment($order);
        /** @var Collection<int, StoreOrderItem> $items */
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        return [
            'customer_name' => trim((string) ($order->customer_name ?: ($store->default_locale === 'ar' ? 'عميلنا العزيز' : 'there'))),
            'order_number' => (string) $order->order_number,
            'order_total' => self::money($order->grand_total, $order->currency),
            'store_name' => (string) $store->name,
            'order_url' => $staff ? $this->dashboardUrl($order) : $this->orderUrl($store, $order),
            'items' => $items->map(fn ($item) => '• '.$item->name.($item->variant_name ? ' ('.$item->variant_name.')' : '').' × '.$item->quantity)->implode("\n"),
            'payment_method' => self::paymentLabel((string) $order->payment_method, $store->default_locale === 'ar'),
            'shipping_address' => self::address($order->shipping_address),
            'tracking_number' => (string) ($shipment?->tracking_number ?? ''),
            'carrier' => $shipment ? ucfirst((string) $shipment->carrier) : '',
        ];
    }

    public static function paymentLabel(string $method, bool $ar): string
    {
        return match ($method) {
            'cod' => $ar ? 'الدفع عند الاستلام' : 'Cash on delivery',
            'bank_transfer', 'transfer_receipt' => $ar ? 'تحويل بنكي' : 'Bank transfer',
            '' => '',
            default => ucwords(str_replace(['_', '-'], ' ', $method)),
        };
    }

    public function trackingUrl(?StoreShipment $shipment): ?string
    {
        if (! $shipment?->tracking_number) {
            return null;
        }

        return match ($shipment->carrier) {
            'bosta' => 'https://bosta.co/tracking-shipments?shipment-number='.rawurlencode($shipment->tracking_number),
            default => null,
        };
    }

    /** @return list<string> */
    public function staffRecipients(Store $store, array $settings): array
    {
        $owner = $store->owner?->email;
        $all = array_merge($owner ? [$owner] : [], $settings['staff_recipients'] ?? []);

        return array_values(array_unique(array_filter(array_map(fn ($email) => strtolower(trim((string) $email)), $all),
            fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /** True when the order is real for the customer: offline payment, or an online payment that succeeded. */
    public static function confirmable(StoreOrder $order): bool
    {
        return $order->payment_status === 'paid' || in_array($order->payment_method, self::OFFLINE_METHODS, true) || (float) $order->amountDue() <= 0;
    }

    /**
     * Queue the enabled channels of one event for an order, inside the caller's transaction.
     * Each (order, event, channel, recipient) is sent at most once unless $force (merchant resend).
     *
     * @param  list<string>|null  $channels
     * @return int number of messages queued
     */
    public function schedule(StoreOrder $order, string $event, ?array $channels = null, bool $force = false): int
    {
        $definition = self::events()[$event] ?? null;
        $store = $order->store;
        if (! $definition || ! $store) {
            return 0;
        }
        $settings = $this->configured($store);
        $config = $settings['events'][$event];
        $queued = 0;
        foreach ($definition['channels'] as $channel) {
            if (($channels !== null && ! in_array($channel, $channels, true)) || (! $force && empty($config[$channel.'_enabled']))) {
                continue;
            }
            $recipients = match (true) {
                $definition['audience'] === 'staff' => $this->staffRecipients($store, $settings),
                $channel === 'email' => filter_var($order->customer_email, FILTER_VALIDATE_EMAIL) ? [strtolower((string) $order->customer_email)] : [],
                default => $this->whatsappReady($store) && ($phone = StoreDigitalWhatsappClient::phone((string) $order->customer_phone)) ? [$phone] : [],
            };
            foreach ($recipients as $recipient) {
                $type = self::MESSAGE_TYPES[$channel];
                $exists = ! $force && OutboxMessage::query()->where('event_type', $type)->where('aggregate_type', 'store_order')
                    ->where('aggregate_id', (string) $order->id)->where('payload->event', $event)->where('payload->recipient', $recipient)->exists();
                if ($exists) {
                    continue;
                }
                app(OutboxRecorder::class)->record($type, 'store_order', $order->id,
                    ['store_id' => $order->store_id, 'store_order_id' => $order->id, 'event' => $event, 'channel' => $channel, 'recipient' => $recipient],
                    ['resend' => $force]);
                $queued++;
            }
        }

        return $queued;
    }

    /** Order placed: customer confirmation (once it is real) and the staff alert. */
    public function orderPlaced(StoreOrder $order): void
    {
        $this->schedule($order, 'staff_new_order');
        if (self::confirmable($order) && ! self::hasDigitalItems($order)) {
            $this->schedule($order, 'order_confirmation');
        }
    }

    /** An online payment succeeded after the order was created. */
    public function orderPaid(StoreOrder $order): void
    {
        if (! self::hasDigitalItems($order)) {
            $this->schedule($order, 'order_confirmation');
        }
    }

    /** Digital orders already get their receipt/delivery email, which is their confirmation. */
    public static function hasDigitalItems(StoreOrder $order): bool
    {
        return $order->items()->withoutGlobalScopes()->where('store_id', $order->store_id)->whereNotNull('digital_delivery')->exists();
    }

    public function statusChanged(StoreOrder $order, string $to): void
    {
        if (isset(self::STATUS_EVENTS[$to])) {
            $this->schedule($order, self::STATUS_EVENTS[$to]);
        }
    }
}

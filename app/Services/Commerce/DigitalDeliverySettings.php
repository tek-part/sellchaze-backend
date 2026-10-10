<?php

namespace App\Services\Commerce;

use App\Models\OutboxMessage;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\Outbox\OutboxRecorder;

class DigitalDeliverySettings
{
    public const VARIABLES = ['customer_name', 'code_or_link', 'store_name', 'product_name'];

    public const CHANNELS = ['email' => 'StorefrontDigitalItemEmail', 'whatsapp' => 'StorefrontDigitalItemWhatsapp'];

    public function defaults(Store $store): array
    {
        $ar = $store->default_locale === 'ar';

        return ['enabled' => true, 'email_enabled' => true, 'sender_name' => '',
            'email_subject' => $ar ? 'منتجك من {store_name}: {product_name}' : 'Your product from {store_name}: {product_name}',
            'email_body' => $ar ? "مرحبًا {customer_name}\nمنتجك: {product_name}\n{code_or_link}\nشكرًا لاختيارك {store_name}" : "Hello {customer_name}\nYour product: {product_name}\n{code_or_link}\nThank you for choosing {store_name}",
            'whatsapp_enabled' => false,
            'whatsapp_body' => $ar ? "مرحبًا {customer_name}\nمنتجك من {store_name}: {product_name}\n{code_or_link}" : "Hello {customer_name}\nYour product from {store_name}: {product_name}\n{code_or_link}",
            'overrides' => []];
    }

    public function configured(Store $store): array
    {
        return array_replace($this->defaults($store), $store->digital_delivery_configuration ?? []);
    }

    public function effective(Store $store, int $productId): array
    {
        $settings = $this->configured($store);
        $override = collect($settings['overrides'])->firstWhere('product_id', $productId);

        return array_replace($settings, $override ?? []);
    }

    public static function render(string $template, array $variables): string
    {
        $values = [];
        foreach (self::VARIABLES as $key) {
            $values['{'.$key.'}'] = (string) ($variables[$key] ?? '');
        }

        return strtr($template, $values);
    }

    public static function snapshot(array $settings): array
    {
        return array_intersect_key($settings, array_flip(['enabled', 'email_enabled', 'sender_name', 'email_subject', 'email_body', 'whatsapp_enabled', 'whatsapp_body']));
    }

    public static function variables(Store $store, StoreOrder $order, StoreOrderItem $item): array
    {
        $delivery = DigitalProducts::present($item, $order);

        return ['customer_name' => $order->customer_name ?? '', 'store_name' => $store->name,
            'product_name' => $item->name, 'code_or_link' => $delivery && $delivery['status'] === 'ready' ? implode("\n", $delivery['values']) : ''];
    }

    /** Publisher holds the parent lock: retries cannot fan out a second copy of existing children. */
    public function schedule(Store $store, StoreOrder $order, OutboxMessage $parent): void
    {
        foreach ($order->items as $item) {
            if (! $item->digital_delivery) {
                continue;
            }
            $settings = $this->effective($store, (int) $item->store_product_id);
            foreach (self::CHANNELS as $channel => $type) {
                if (! $settings['enabled'] || ! $settings[$channel.'_enabled']) {
                    continue;
                }
                $exists = OutboxMessage::query()->where('event_type', $type)->where('metadata->parent_message_id', $parent->id)
                    ->where('payload->store_order_item_id', $item->id)->exists();
                if ($exists) {
                    continue;
                }
                app(OutboxRecorder::class)->record($type, 'store_order', $order->id,
                    ['store_id' => $store->id, 'store_order_id' => $order->id, 'store_order_item_id' => $item->id,
                        'recipient' => $channel === 'email' ? $order->customer_email : $order->customer_phone],
                    ['parent_message_id' => $parent->id, 'settings' => self::snapshot($settings)]);
            }
        }
        $parent->update(['metadata' => array_merge($parent->metadata ?? [], ['mail_outcome' => 'scheduled'])]);
    }
}

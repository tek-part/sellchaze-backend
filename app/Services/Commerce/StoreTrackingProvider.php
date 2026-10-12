<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Http;

/** Fixed official endpoints; raw access tokens, user data and provider errors are never logged. */
class StoreTrackingProvider
{
    public const META_VERSION = 'v26.0';

    public const NAMES = ['facebook' => ['page_view' => 'PageView', 'view_item' => 'ViewContent', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'Purchase'],
        'tiktok' => ['page_view' => 'Pageview', 'view_item' => 'ViewContent', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'Purchase'],
        'snapchat' => ['page_view' => 'PAGE_VIEW', 'view_item' => 'VIEW_CONTENT', 'add_to_cart' => 'ADD_CART', 'begin_checkout' => 'START_CHECKOUT', 'purchase' => 'PURCHASE']];

    public function body(string $provider, array $event, int $time, string $pixel): array
    {
        $user = $event['user_data'];
        $custom = ['currency' => $event['currency'], 'value' => (float) ($event['gross_value'] ?? $event['value']), 'content_type' => $event['content_type'] ?? 'product',
            'content_ids' => array_column($event['items'], 'item_id'), 'contents' => array_map(fn ($item) => ['id' => $item['item_id'], 'quantity' => $item['quantity'], 'item_price' => (float) $item['price']], $event['items'])];
        if (isset($event['transaction_id'])) {
            $custom['order_id'] = $event['transaction_id'];
        }
        if ($provider === 'tiktok') {
            $mapped = [];
            foreach (['em' => 'email', 'ph' => 'phone', 'client_ip_address' => 'ip', 'client_user_agent' => 'user_agent'] as $from => $to) {
                if (isset($user[$from])) {
                    $mapped[$to] = $user[$from];
                }
            }
            $properties = ['currency' => $event['currency'], 'value' => $custom['value'], 'content_type' => $custom['content_type'],
                'contents' => array_map(fn ($item) => ['content_id' => $item['item_id'], 'content_name' => $item['item_name'], 'quantity' => $item['quantity'], 'price' => (float) $item['price']], $event['items'])];

            return ['event_source' => 'web', 'event_source_id' => $pixel, 'data' => [['event' => self::NAMES[$provider][$event['event']],
                'event_time' => $time, 'event_id' => $event['event_id'], 'user' => $mapped, 'page' => ['url' => $event['page_location']], 'properties' => $properties]]];
        }
        foreach (['em', 'ph'] as $key) {
            if (isset($user[$key])) {
                $user[$key] = [$user[$key]];
            }
        }
        if ($provider === 'snapchat' && ($event['content_type'] ?? '') === 'product_group') {
            $custom['item_category'] = $event['items'][0]['item_id'] ?? '';
        }

        return ['data' => [['event_name' => self::NAMES[$provider][$event['event']], 'event_id' => $event['event_id'], 'event_time' => $time,
            'event_source_url' => $event['page_location'], 'action_source' => $provider === 'facebook' ? 'website' : 'WEB', 'user_data' => $user, 'custom_data' => $custom]]];
    }

    public function send(string $provider, string $pixel, string $token, array $event, int $time): array
    {
        try {
            $request = Http::asJson()->acceptJson()->timeout(8)->connectTimeout(3)->withOptions(['allow_redirects' => false]);
            $body = $this->body($provider, $event, $time, $pixel);
            $response = match ($provider) {
                'facebook' => $request->post('https://graph.facebook.com/'.self::META_VERSION.'/'.$pixel.'/events', $body + ['access_token' => $token]),
                'tiktok' => $request->withHeaders(['Access-Token' => $token])->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body),
                'snapchat' => $request->post('https://tr.snapchat.com/v3/'.$pixel.'/events?access_token='.rawurlencode($token), $body),
                default => throw new \InvalidArgumentException('Unknown tracking provider.'),
            };
            $valid = $response->successful() && match ($provider) {
                'facebook' => (int) $response->json('events_received', 0) >= 1,
                'tiktok' => $response->json('code') === 0,
                'snapchat' => $response->json('status') === 'VALID',
            };
            if ($valid) {
                return ['status' => 'delivered', 'error_code' => null];
            }
            $retry = $response->status() >= 500 || in_array($response->status(), [408, 429], true) || $response->json('error.is_transient') === true;

            return ['status' => $retry ? 'retry' : 'failed', 'error_code' => $response->successful() ? 'provider_rejected' : 'http_'.$response->status()];
        } catch (\Throwable) {
            // Retries use exactly the same event ID and receipt time, including ambiguous network failures.
            return ['status' => 'retry', 'error_code' => 'network_unavailable'];
        }
    }
}

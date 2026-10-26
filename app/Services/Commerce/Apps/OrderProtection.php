<?php

namespace App\Services\Commerce\Apps;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Services\Commerce\MerchantOrderReviews;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fake-order protection. Each rule either flags the order for the merchant (the existing review
 * classification, recorded without a human actor) or refuses checkout with a polite message.
 * The rules that fired are stored on the order so the merchant can see why.
 */
class OrderProtection implements MerchantAppSettings
{
    public const RULES = ['repeat_phone', 'open_cod', 'blocked_words', 'high_quantity'];

    /** Orders still waiting for delivery or payment collection. */
    public const OPEN_STATUSES = ['pending', 'confirmed', 'processing', 'waiting_for_pickup', 'shipped'];

    private const MESSAGE_AR = 'تعذّر إتمام هذا الطلب تلقائيًا. تواصل مع المتجر لإكمال طلبك.';

    private const MESSAGE_EN = 'We could not complete this order automatically. Please contact the store to finish your order.';

    public function column(): string
    {
        return 'order_protection_configuration';
    }

    public function configured(Store $store): array
    {
        $defaults = ['version' => 1, 'enabled' => false, 'flag_classification' => 'high_risk', 'message' => ['ar' => '', 'en' => ''],
            'rules' => [
                'repeat_phone' => ['enabled' => false, 'action' => 'flag', 'hours' => 24, 'max_orders' => 2],
                'open_cod' => ['enabled' => false, 'action' => 'flag', 'max_open' => 2],
                'blocked_words' => ['enabled' => false, 'action' => 'flag', 'words' => []],
                'high_quantity' => ['enabled' => false, 'action' => 'flag', 'max_units' => 10],
            ]];
        $saved = $store->order_protection_configuration ?? [];
        $config = array_replace($defaults, $saved);
        foreach (self::RULES as $rule) {
            $config['rules'][$rule] = array_replace($defaults['rules'][$rule], $saved['rules'][$rule] ?? []);
        }

        return $config;
    }

    public function rules(Store $store): array
    {
        $rules = ['enabled' => ['required', 'boolean'], 'flag_classification' => ['required', Rule::in(MerchantOrderReviews::CLASSIFICATIONS)],
            'rules' => ['required', 'array:'.implode(',', self::RULES)],
            'rules.repeat_phone.hours' => ['required', 'integer', 'min:1', 'max:720'],
            'rules.repeat_phone.max_orders' => ['required', 'integer', 'min:1', 'max:100'],
            'rules.open_cod.max_open' => ['required', 'integer', 'min:1', 'max:100'],
            'rules.blocked_words.words' => ['present', 'array', 'max:100'],
            'rules.blocked_words.words.*' => ['required', 'string', 'min:2', 'max:60'],
            'rules.high_quantity.max_units' => ['required', 'integer', 'min:1', 'max:10000']] + LocalizedText::rules('message', 300);
        foreach (self::RULES as $rule) {
            $rules['rules.'.$rule] = ['required', 'array'];
            $rules['rules.'.$rule.'.enabled'] = ['required', 'boolean'];
            $rules['rules.'.$rule.'.action'] = ['required', Rule::in(['flag', 'block'])];
        }

        return $rules;
    }

    public function normalize(Store $store, array $data): array
    {
        $rules = [];
        foreach (self::RULES as $rule) {
            $row = $data['rules'][$rule];
            $rules[$rule] = ['enabled' => (bool) $row['enabled'], 'action' => $row['action']];
        }
        $rules['repeat_phone'] += ['hours' => (int) $data['rules']['repeat_phone']['hours'], 'max_orders' => (int) $data['rules']['repeat_phone']['max_orders']];
        $rules['open_cod'] += ['max_open' => (int) $data['rules']['open_cod']['max_open']];
        $words = array_values(array_unique(array_filter(array_map(fn ($word) => mb_strtolower(trim((string) $word)), $data['rules']['blocked_words']['words']), fn ($word) => mb_strlen($word) >= 2)));
        $rules['blocked_words'] += ['words' => $words];
        $rules['high_quantity'] += ['max_units' => (int) $data['rules']['high_quantity']['max_units']];
        if ($rules['blocked_words']['enabled'] && $words === []) {
            throw ValidationException::withMessages(['rules.blocked_words.words' => 'Add at least one word before enabling this rule.']);
        }

        return ['enabled' => (bool) $data['enabled'], 'flag_classification' => $data['flag_classification'],
            'message' => LocalizedText::normalize($data['message'] ?? []), 'rules' => $rules];
    }

    public function enabled(Store $store): bool
    {
        $config = $this->configured($store);

        return $config['enabled'] && collect($config['rules'])->contains(fn (array $rule) => $rule['enabled']);
    }

    public function meta(Store $store): array
    {
        return ['classifications' => MerchantOrderReviews::CLASSIFICATIONS];
    }

    /**
     * Caller holds the store row lock, so phone counts cannot race a concurrent checkout.
     *
     * @param  array{phone:?string,payment_method:string,name:?string,address:?array,units:int}  $order
     * @return list<array{rule:string,action:string,detail:array<string,mixed>}>
     */
    public function evaluate(Store $store, array $order): array
    {
        if (! $this->enabled($store)) {
            return [];
        }
        $rules = $this->configured($store)['rules'];
        $fired = [];
        $fire = function (string $rule, array $detail) use (&$fired, $rules) {
            $fired[] = ['rule' => $rule, 'action' => $rules[$rule]['action'], 'detail' => $detail];
        };
        $phone = $order['phone'];
        $orders = fn () => StoreOrder::withoutGlobalScopes()->where('store_id', $store->id)->whereNull('deleted_at')->where('customer_phone_normalized', $phone);
        if ($rules['repeat_phone']['enabled'] && $phone !== null) {
            $count = $orders()->where('placed_at', '>', now()->subHours($rules['repeat_phone']['hours']))->count();
            if ($count >= $rules['repeat_phone']['max_orders']) {
                $fire('repeat_phone', ['orders' => $count, 'hours' => $rules['repeat_phone']['hours']]);
            }
        }
        if ($rules['open_cod']['enabled'] && $phone !== null && $order['payment_method'] === 'cod') {
            $count = $orders()->where('payment_method', 'cod')->whereIn('status', self::OPEN_STATUSES)->count();
            if ($count >= $rules['open_cod']['max_open']) {
                $fire('open_cod', ['open_orders' => $count]);
            }
        }
        if ($rules['blocked_words']['enabled']) {
            $address = $order['address'] ?? [];
            $text = mb_strtolower(implode(' ', array_filter([$order['name'] ?? '', ...array_map(fn ($key) => is_string($address[$key] ?? null) ? $address[$key] : '',
                ['name', 'line1', 'line2', 'city', 'state', 'national_address'])])));
            foreach ($rules['blocked_words']['words'] as $word) {
                if ($word !== '' && mb_strpos($text, $word) !== false) {
                    $fire('blocked_words', ['word' => $word]);
                    break;
                }
            }
        }
        if ($rules['high_quantity']['enabled'] && $order['units'] > $rules['high_quantity']['max_units']) {
            $fire('high_quantity', ['units' => $order['units'], 'max_units' => $rules['high_quantity']['max_units']]);
        }

        return $fired;
    }

    /** @param list<array{rule:string,action:string,detail:array<string,mixed>}> $fired */
    public function assertNotBlocked(Store $store, array $fired): void
    {
        if (collect($fired)->contains(fn (array $row) => $row['action'] === 'block')) {
            throw ValidationException::withMessages(['order_protection' => LocalizedText::pick($this->configured($store)['message'], self::MESSAGE_AR, self::MESSAGE_EN)]);
        }
    }

    /**
     * Store which rules fired and classify the new order for review. Runs inside the placement transaction.
     *
     * @param  list<array{rule:string,action:string,detail:array<string,mixed>}>  $fired
     */
    public function record(StoreOrder $order, Store $store, array $fired): void
    {
        if ($fired === []) {
            return;
        }
        $order->forceFill(['protection_signals' => $fired])->save();
        DB::table('store_order_reviews')->insertOrIgnore(['store_id' => $order->store_id, 'store_order_id' => $order->id,
            'classification' => $this->configured($store)['flag_classification'], 'version' => 1, 'actor_id' => null,
            'created_at' => now(), 'updated_at' => now()]);
        $order->statusChanges()->create(['store_id' => $order->store_id, 'from_status' => $order->status, 'to_status' => $order->status,
            'actor_id' => null, 'source' => 'order_protection', 'notes' => null]);
    }
}

<?php

namespace App\Services\Commerce\Apps;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Support\Localization\LocaleContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Sales notifications built only from the store's own recent storefront orders.
 * A notification never carries a phone, email, address line or full name: at most the
 * first letter of the first name and a short city label. Counts of "people viewing" are
 * not offered because the platform does not record per-product live sessions.
 */
class SocialProof implements MerchantAppSettings
{
    public const PAGES = ['home', 'product', 'collection', 'cart'];

    public function column(): string
    {
        return 'social_proof_configuration';
    }

    public function configured(Store $store): array
    {
        $saved = $store->social_proof_configuration ?? [];
        $defaults = ['version' => 1, 'enabled' => false, 'lookback_days' => 7, 'max_events' => 10,
            'first_delay_seconds' => 8, 'interval_seconds' => 25, 'display_seconds' => 6, 'position' => 'bottom-start',
            'pages' => ['home' => true, 'product' => true, 'collection' => true, 'cart' => false],
            'show_initial' => true, 'show_city' => true, 'show_time' => true];

        return array_replace($defaults, $saved, ['pages' => array_replace($defaults['pages'], $saved['pages'] ?? [])]);
    }

    public function rules(Store $store): array
    {
        $rules = ['enabled' => ['required', 'boolean'],
            'lookback_days' => ['required', 'integer', 'min:1', 'max:90'],
            'max_events' => ['required', 'integer', 'min:1', 'max:20'],
            'first_delay_seconds' => ['required', 'integer', 'min:0', 'max:120'],
            'interval_seconds' => ['required', 'integer', 'min:8', 'max:600'],
            'display_seconds' => ['required', 'integer', 'min:3', 'max:20'],
            'position' => ['required', Rule::in(['bottom-start', 'bottom-end'])],
            'pages' => ['required', 'array:'.implode(',', self::PAGES)],
            'show_initial' => ['required', 'boolean'], 'show_city' => ['required', 'boolean'], 'show_time' => ['required', 'boolean']];
        foreach (self::PAGES as $page) {
            $rules['pages.'.$page] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function normalize(Store $store, array $data): array
    {
        $config = ['enabled' => (bool) $data['enabled'], 'position' => (string) $data['position']];
        foreach (['lookback_days', 'max_events', 'first_delay_seconds', 'interval_seconds', 'display_seconds'] as $key) {
            $config[$key] = (int) $data[$key];
        }
        foreach (['show_initial', 'show_city', 'show_time'] as $key) {
            $config[$key] = (bool) $data[$key];
        }
        $config['pages'] = array_map(fn ($value) => (bool) $value, array_intersect_key($data['pages'], array_flip(self::PAGES)));

        return $config;
    }

    public function enabled(Store $store): bool
    {
        return (bool) $this->configured($store)['enabled'];
    }

    public function meta(Store $store): array
    {
        return ['viewers_available' => false];
    }

    /** Public feed for the storefront widget; null while the app is disabled. */
    public function publicFeed(Store $store): ?array
    {
        $config = $this->configured($store);
        if (! $config['enabled']) {
            return null;
        }
        $locale = app(LocaleContext::class)->current();
        $key = 'storefront:social-proof:'.$store->id.':'.$config['version'].':'.$locale;
        // Short shared cache: public traffic must not query orders on every page view.
        $rows = Cache::remember($key, 60, fn () => $this->recent($store, $config));
        $events = [];
        foreach ($rows as $row) {
            $minutes = max(1, (int) floor(abs(now()->getTimestamp() - $row['placed_at']) / 60));
            if ($minutes > $config['lookback_days'] * 1440) {
                continue;
            }
            $events[] = ['initial' => $config['show_initial'] ? $row['initial'] : null, 'city' => $config['show_city'] ? $row['city'] : null,
                'minutes_ago' => $config['show_time'] ? $minutes : null, 'product' => $row['product']];
        }

        return array_intersect_key($config, array_flip(['version', 'first_delay_seconds', 'interval_seconds', 'display_seconds', 'position', 'pages'])) + ['events' => $events];
    }

    /** @return list<array{placed_at:int,initial:?string,city:?string,product:array<string,mixed>}> */
    private function recent(Store $store, array $config): array
    {
        $orders = StoreOrder::withoutGlobalScopes()->where('store_id', $store->id)->whereNull('deleted_at')
            ->whereNull('manual_request_key')
            ->whereNotIn('status', ['cancelled', ...StoreOrder::RETURN_STATUSES])
            ->where('placed_at', '>=', now()->subDays($config['lookback_days']))
            ->orderByDesc('placed_at')->orderByDesc('id')->limit($config['max_events'])
            ->with(['items' => fn ($query) => $query->orderBy('id')])->get();
        $productIds = $orders->flatMap(fn (StoreOrder $order) => $order->items->pluck('store_product_id'))->unique()->values()->all();
        $products = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->where('catalog_hidden', false)
            ->whereIn('id', $productIds)->get()->keyBy('id');
        $rows = [];
        foreach ($orders as $order) {
            $item = $order->items->first(fn ($line) => $products->has($line->store_product_id));
            if (! $item) {
                continue;
            }
            $product = $products->get($item->store_product_id);
            $rows[] = ['placed_at' => $order->placed_at->getTimestamp(), 'initial' => self::initial($order->customer_name),
                'city' => self::city($order->shipping_address['city'] ?? null),
                'product' => ['id' => $product->id, 'name' => $product->translated('name'), 'slug' => $product->slug, 'image_url' => $product->imageUrl()]];
        }

        return $rows;
    }

    /** First letter of the first name only. */
    public static function initial(?string $name): ?string
    {
        $first = preg_split('/\s+/u', trim((string) $name))[0] ?? '';
        $letter = mb_substr($first, 0, 1);

        return preg_match('/^\p{L}$/u', $letter) ? mb_strtoupper($letter) : null;
    }

    /** A short place name, never something that looks like a street address or contact detail. */
    public static function city(mixed $value): ?string
    {
        $city = trim((string) preg_replace('/\s+/u', ' ', is_string($value) ? $value : ''));
        if ($city === '' || mb_strlen($city) > 40 || preg_match('/[\p{N}@#\/\\\\,;:+]/u', $city) || count(explode(' ', $city)) > 4) {
            return null;
        }

        return $city;
    }
}

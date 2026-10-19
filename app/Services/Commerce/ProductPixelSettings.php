<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

/** Product browser identifiers extend relevant events; server conversion credentials stay store managed. */
final class ProductPixelSettings
{
    public static function rules(): array
    {
        return [
            'product_preferences.browser_pixels' => ['sometimes', 'nullable', 'array', 'list', 'max:30'],
            'product_preferences.browser_pixels.*' => ['required', 'array:provider,id'],
            'product_preferences.browser_pixels.*.provider' => ['required', 'string', 'in:facebook,tiktok,snapchat'],
            'product_preferences.browser_pixels.*.id' => ['required', 'string', 'max:64'],
        ];
    }

    public function normalize(array $rows): array
    {
        if (! array_is_list($rows)) {
            throw ValidationException::withMessages(['product_preferences.browser_pixels' => 'Provide a list of pixel rows.']);
        }
        $clean = [];
        $seen = [];
        $counts = [];
        $settings = app(StoreTrackingSettings::class);
        foreach ($rows as $index => $row) {
            $provider = $row['provider'] ?? '';
            if (! in_array($provider, StoreTrackingSettings::PROVIDERS, true)) {
                throw ValidationException::withMessages(["product_preferences.browser_pixels.$index.provider" => 'Choose Facebook, TikTok or Snapchat.']);
            }
            $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
            if (! $settings->validId($provider, $id)) {
                throw ValidationException::withMessages(["product_preferences.browser_pixels.$index.id" => 'Enter a valid pixel identifier, not code or a URL.']);
            }
            $id = $provider === 'snapchat' ? strtolower($id) : $id;
            if (isset($seen[$provider.':'.$id])) {
                continue;
            }
            $counts[$provider] = ($counts[$provider] ?? 0) + 1;
            if ($counts[$provider] > 10) {
                throw ValidationException::withMessages(['product_preferences.browser_pixels' => 'Use at most ten identifiers per network.']);
            }
            $seen[$provider.':'.$id] = true;
            $clean[] = ['provider' => $provider, 'id' => $id];
        }

        return $clean;
    }

    public function snapshot(Store $store, array $productIds, ?string $pagePath = null): array
    {
        $query = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true);
        if ($productIds) {
            $query->whereIn('id', array_unique($productIds));
        } elseif (is_string($pagePath) && preg_match('~^/products/([^/?#]+)/?$~D', $pagePath, $match)) {
            $query->where('slug', rawurldecode($match[1]));
        } else {
            return [];
        }
        $captured = [];
        foreach ($query->get(['id', 'product_preferences']) as $product) {
            $pixels = $this->configuration($product);
            if (array_filter($pixels)) {
                $captured[$product->id] = $pixels;
            }
        }

        return $captured ? ['product_pixels' => $captured] : [];
    }

    /** Removed IDs stop replays; newly added IDs do not inherit historical purchases. */
    public function forEvent(Store $store, array $payload, array $configuration): array
    {
        $captured = $payload['product_pixels'] ?? [];
        if (! is_array($captured) || ! $captured) {
            return $configuration;
        }
        $products = Product::withoutGlobalScopes()->where('store_id', $store->id)->where('is_active', true)->whereIn('id', array_keys($captured))->get(['id', 'product_preferences']);
        foreach ($products as $product) {
            $current = $this->configuration($product);
            foreach (StoreTrackingSettings::PROVIDERS as $provider) {
                $previous = $captured[$product->id][$provider] ?? [];
                $configuration[$provider] = array_values(array_unique(array_merge($configuration[$provider], array_intersect($current[$provider], is_array($previous) ? $previous : []))));
            }
        }

        return $configuration;
    }

    private function configuration(Product $product): array
    {
        $result = array_fill_keys(StoreTrackingSettings::PROVIDERS, []);
        $rows = $product->product_preferences['browser_pixels'] ?? [];
        if (! is_array($rows)) {
            return $result;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $provider = $row['provider'] ?? null;
            $id = $row['id'] ?? null;
            if (in_array($provider, StoreTrackingSettings::PROVIDERS, true) && is_string($id) && app(StoreTrackingSettings::class)->validId($provider, $id)) {
                $result[$provider][] = $id;
            }
        }

        return $result;
    }
}

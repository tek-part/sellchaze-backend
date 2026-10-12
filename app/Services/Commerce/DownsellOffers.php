<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;

class DownsellOffers
{
    public function configured(Store $store): array
    {
        return $store->downsell_configuration ?? [
            'version' => 1, 'enabled' => false, 'coupon_id' => null,
            'trigger' => 'both', 'delay_seconds' => 30, 'minimum_visit_seconds' => 5,
            'frequency_hours' => 24, 'cart_required' => false,
            'all_products' => true, 'product_ids' => [],
            'title' => ['ar' => 'هناك عرض خاص لك', 'en' => 'A special offer for you'],
            'body' => ['ar' => 'استخدم هذا الكوبون عند إتمام الطلب للحصول على الخصم.', 'en' => 'Use this coupon at checkout to receive your discount.'],
            'button' => ['ar' => 'أحصل على الخصم الآن', 'en' => 'Get the discount now'],
            'background_color' => '#F1FBF2', 'text_color' => '#090C50',
            'accent_color' => '#0ABB45', 'button_color' => '#090C50',
            'button_text_color' => '#FFFFFF', 'border_color' => '#090C50',
        ];
    }

    public function activeCoupon(Store $store, ?int $id): ?Coupon
    {
        if (! $id) {
            return null;
        }
        $coupon = Coupon::forStore($store)->whereKey($id)->first();
        if (! $coupon || ! $coupon->is_active || $coupon->starts_at?->isFuture() || $coupon->expires_at?->isPast()
            || ($coupon->max_uses !== null && $coupon->usages()->count() >= $coupon->max_uses)) {
            return null;
        }

        return $coupon;
    }

    public function enabled(Store $store): bool
    {
        $config = $this->configured($store);

        return $config['enabled'] && $this->activeCoupon($store, $config['coupon_id']) !== null;
    }

    public function publicOffer(Store $store, array $productIds = [], ?string $slug = null): ?array
    {
        $config = $this->configured($store);
        if (! $config['enabled'] || ! ($coupon = $this->activeCoupon($store, $config['coupon_id']))) {
            return null;
        }
        if (! $config['all_products']) {
            $visible = Product::forStore($store)->where('is_active', true)->whereIn('id', $config['product_ids']);
            $matches = $visible->where(fn ($query) => $query->whereIn('id', $productIds)->when($slug, fn ($query) => $query->orWhere('slug', $slug)))->exists();
            if (! $matches) {
                return null;
            }
        }

        // Presentation never reserves uses or changes the coupon/order engine.
        return array_diff_key($config, ['enabled' => true, 'coupon_id' => true, 'product_ids' => true, 'all_products' => true]) + [
            'coupon' => ['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value,
                'minimum_order_amount' => $coupon->minimum_order_amount, 'currency' => $store->currency ?: 'USD'],
        ];
    }
}

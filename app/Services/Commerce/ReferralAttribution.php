<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Validation\Rule;

/** Visitor-supplied marketer code and source product, not proof of commission entitlement. */
class ReferralAttribution
{
    public const CODE_RULES = ['string', 'max:100', 'regex:/^[\p{L}\p{N}_.-]+$/u'];

    public static function rules(Store $store): array
    {
        return [
            'referral_attribution' => ['nullable', 'array:code,product_id'],
            'referral_attribution.code' => ['required_with:referral_attribution', ...self::CODE_RULES],
            'referral_attribution.product_id' => ['required_with:referral_attribution', 'integer', 'min:1',
                Rule::exists('products', 'id')->where('store_id', $store->id)->where('is_active', true)],
        ];
    }

    public function snapshot(array $data): array
    {
        return ['code' => $data['code'], 'product_id' => (int) $data['product_id']];
    }
}

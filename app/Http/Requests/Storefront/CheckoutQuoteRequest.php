<?php

namespace App\Http\Requests\Storefront;

use App\Services\Commerce\GiftCards;
use App\Services\Commerce\ProductPersonalization;
use App\Services\Commerce\QuantityOffers;
use App\Services\Commerce\StoreShipping;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return StoreShipping::SELECTION_RULES + ProductPersonalization::inputRules('items.*.personalization') + QuantityOffers::inputRules('items.*.') + [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:80'],
        ] + GiftCards::inputRules();
    }
}

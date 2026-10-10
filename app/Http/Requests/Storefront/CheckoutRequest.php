<?php

namespace App\Http\Requests\Storefront;

use App\Services\Commerce\CheckoutBasket;
use App\Services\Commerce\CheckoutFields;
use App\Services\Commerce\ProductPersonalization;
use App\Services\Commerce\StoreShipping;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = app(CurrentStore::class)->get();
        abort_unless($store, 404);

        $basket = app(CheckoutBasket::class)->forRequest($store, $this);

        return app(CheckoutFields::class)->rules($store, is_string($this->input('payment_method')) ? $this->input('payment_method') : null, $basket['requires_shipping'], $basket['has_digital']) + StoreShipping::SELECTION_RULES + ProductPersonalization::inputRules('items.*.personalization') + [
            'payment_method' => ['nullable', 'string', 'max:80'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'cart_mode' => ['sometimes', 'in:cart,direct'],
            // The storefront cart is client-side; the order's line items are submitted here and
            // synced into the cart before placement (see CheckoutController@store).
            'items' => ['required_if:cart_mode,direct', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:999'],
        ];
    }
}

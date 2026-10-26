<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Concerns\ResolvesStorefront;
use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutLeads;
use App\Services\Commerce\QuantityOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutLeadController extends Controller
{
    use ResolvesStorefront;

    public function store(Request $request, CheckoutLeads $leads): JsonResponse
    {
        $data = $request->validate(QuantityOffers::inputRules('items.*.') + [
            'lead_token' => ['required', 'uuid'], 'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'clear' => ['sometimes', 'boolean'], 'source' => ['required', 'in:storefront,funnel'],
            'source_path' => ['nullable', 'string', 'max:512', 'regex:~^/(?:checkout|(?:funnels|pages|products|p)/[a-z0-9-]+)?$~i'],
            'customer_name' => ['nullable', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'shipping_region_id' => ['nullable', 'string', 'max:80'], 'shipping_option_id' => ['nullable', 'string', 'max:80'], 'shipping_rate_id' => ['nullable', 'string', 'max:80'], 'coupon_code' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['nullable', 'array:name,line1,city,country,postal_code,phone_alt,national_address'],
            'shipping_address.name' => ['nullable', 'string', 'max:255'], 'shipping_address.line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'], 'shipping_address.country' => ['nullable', 'string', 'max:100'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:32'], 'shipping_address.phone_alt' => ['nullable', 'string', 'max:50'],
            'shipping_address.national_address' => ['nullable', 'string', 'max:255'],
            'items' => ['required_unless:clear,true', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'], 'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $leads->capture($this->currentStore($request), $data);

        return response()->json(['message' => 'Saved.'], 202, ['Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\DownsellOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreDownsellController extends Controller
{
    public function __construct(private readonly DownsellOffers $offers) {}

    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('stores-edit')
            || ($user->can('store.settings.manage') && $user->can('store.coupons.manage'))), 403);

        return $store;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->offers->configured($this->store($request))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $rules = ['version' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'],
            'coupon_id' => ['nullable', 'integer', Rule::exists('coupons', 'id')->where('store_id', $store->id)],
            'trigger' => ['required', Rule::in(['timer', 'exit', 'both'])],
            'delay_seconds' => ['required', 'integer', 'min:1', 'max:600'],
            'minimum_visit_seconds' => ['required', 'integer', 'min:0', 'max:300'],
            'frequency_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'cart_required' => ['required', 'boolean'], 'all_products' => ['required', 'boolean'],
            'product_ids' => ['present', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('store_id', $store->id)],
        ];
        foreach (['title' => 160, 'body' => 600, 'button' => 80] as $field => $maximum) {
            $rules[$field] = ['required', 'array:ar,en'];
            foreach (['ar', 'en'] as $locale) {
                $rules[$field.'.'.$locale] = ['required', 'string', 'max:'.$maximum];
            }
        }
        foreach (['background_color', 'text_color', 'accent_color', 'button_color', 'button_text_color', 'border_color'] as $field) {
            $rules[$field] = ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'];
        }
        $data = $request->validate($rules);
        $data['enabled'] = (bool) $data['enabled'];
        $data['cart_required'] = (bool) $data['cart_required'];
        $data['all_products'] = (bool) $data['all_products'];
        $data['coupon_id'] = isset($data['coupon_id']) ? (int) $data['coupon_id'] : null;
        foreach (['delay_seconds', 'minimum_visit_seconds', 'frequency_hours'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $data['product_ids'] = $data['all_products'] ? [] : array_map('intval', $data['product_ids']);
        if (! $data['all_products'] && ! $data['product_ids']) {
            throw ValidationException::withMessages(['product_ids' => 'Select a product or enable all products.']);
        }
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = $this->offers->configured($locked);
            abort_unless((int) $data['version'] === (int) $current['version'], 409, 'The popup changed. Reload before saving.');
            if ($data['enabled'] && ! $this->offers->activeCoupon($locked, $data['coupon_id'])) {
                throw ValidationException::withMessages(['coupon_id' => 'Choose an active coupon before enabling the offer.']);
            }
            $locked->update(['downsell_configuration' => array_replace($data, ['version' => $current['version'] + 1])]);
        });
        $request->attributes->set('store', $store->fresh());

        return $this->index($request);
    }
}

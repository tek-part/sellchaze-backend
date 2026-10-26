<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Merchant discount create/update. Code discounts need a code unique per store; automatic
 * discounts need a title. Percentages are capped at 100; free shipping carries no value; buy X
 * get Y describes both sides in `bxgy`. Product/category targets must belong to the store.
 */
class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership enforced by the store.scope middleware
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key !== null || ! is_array($data)) {
            return $data;
        }
        $data['method'] ??= $this->effectiveMethod();
        // An update that leaves out the scope keeps the stored one; an empty scope still means all.
        if ($this->route('coupon') === null || array_key_exists('applies_to', $data)) {
            $data['applies_to'] ??= 'all';
        }
        if (! in_array($data['type'], ['fixed', 'percentage'], true)) {
            $data['value'] = 0;
        }
        if ($data['type'] !== 'buy_x_get_y') {
            $data['bxgy'] = null;
        }
        if (($data['applies_to'] ?? null) === 'all') {
            $data['target_ids'] = null;
        }
        if ($data['method'] === 'automatic') {
            $data['code'] = null;
        }

        return $data;
    }

    /** An update that leaves out the method keeps the stored one. */
    private function effectiveMethod(): string
    {
        if ($this->filled('method')) {
            return (string) $this->input('method');
        }
        $couponId = $this->route('coupon');
        $store = $this->route('store');
        if ($couponId !== null && $store instanceof Store) {
            $stored = Coupon::query()->withoutGlobalScopes()->where('store_id', $store->id)->whereKey($couponId)->value('method');
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
        }

        return 'code';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $store = $this->route('store');
        $storeId = $store instanceof Store ? $store->id : 0;
        $couponId = $this->route('coupon');

        $valueMax = $this->input('type') === 'percentage' ? 100 : 99999999;
        $automatic = $this->effectiveMethod() === 'automatic';
        $valued = in_array($this->input('type'), ['fixed', 'percentage'], true);
        $products = Rule::exists('products', 'id')->where('store_id', $storeId);
        $categories = Rule::exists('categories', 'id')->where('store_id', $storeId);
        $targetRule = fn (string $scopeField) => $this->input($scopeField) === 'categories' ? $categories : $products;

        return [
            'method' => ['nullable', Rule::in(Coupon::METHODS)],
            'code' => [
                $automatic ? 'nullable' : 'required', 'string', 'max:50',
                Rule::unique('coupons', 'code')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($couponId),
            ],
            'title' => [$automatic ? 'required' : 'nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in(Coupon::TYPES)],
            'value' => [$valued ? 'required' : 'nullable', 'numeric', 'min:0.01', "max:{$valueMax}"],
            'applies_to' => ['nullable', Rule::in(Coupon::SCOPES)],
            'target_ids' => [Rule::requiredIf(in_array($this->input('applies_to'), ['products', 'categories'], true)), 'nullable', 'array', 'max:500'],
            'target_ids.*' => ['integer', $targetRule('applies_to')],
            'minimum_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'bxgy' => [Rule::requiredIf($this->input('type') === 'buy_x_get_y'), 'nullable', 'array:buy_quantity,buy_applies_to,buy_ids,get_quantity,get_applies_to,get_ids,get_percent,max_uses_per_order'],
            'bxgy.buy_quantity' => ['required_with:bxgy', 'integer', 'min:1', 'max:99'],
            'bxgy.buy_applies_to' => ['nullable', Rule::in(Coupon::SCOPES)],
            'bxgy.buy_ids' => [Rule::requiredIf(in_array($this->input('bxgy.buy_applies_to'), ['products', 'categories'], true)), 'nullable', 'array', 'max:500'],
            'bxgy.buy_ids.*' => ['integer', $targetRule('bxgy.buy_applies_to')],
            'bxgy.get_quantity' => ['required_with:bxgy', 'integer', 'min:1', 'max:99'],
            'bxgy.get_applies_to' => ['nullable', Rule::in(Coupon::SCOPES)],
            'bxgy.get_ids' => [Rule::requiredIf(in_array($this->input('bxgy.get_applies_to'), ['products', 'categories'], true)), 'nullable', 'array', 'max:500'],
            'bxgy.get_ids.*' => ['integer', $targetRule('bxgy.get_applies_to')],
            'bxgy.get_percent' => ['required_with:bxgy', 'integer', 'min:1', 'max:100'],
            'bxgy.max_uses_per_order' => ['nullable', 'integer', 'min:1', 'max:99'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

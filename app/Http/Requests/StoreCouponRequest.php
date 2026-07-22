<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 6D: merchant coupon create/update. Code is unique per store; a
 * percentage value is capped at 100.
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
    public function rules(): array
    {
        $store = $this->route('store');
        $storeId = $store instanceof Store ? $store->id : 0;
        $couponId = $this->route('coupon');

        $valueMax = $this->input('type') === 'percentage' ? 100 : 99999999;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('coupons', 'code')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($couponId),
            ],
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => ['required', 'numeric', 'min:0.01', "max:{$valueMax}"],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

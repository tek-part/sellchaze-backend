<?php

namespace App\Http\Requests;

use App\Models\StoreOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6E: merchant order status update. The status must be a known status;
 * whether the transition itself is legal is enforced by StoreOrderService
 * (fail-closed). `note` is an optional internal note recorded on the change.
 */
class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership enforced by the store.scope middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(StoreOrder::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

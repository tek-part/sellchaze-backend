<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Services\StoreService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a Store. Authorization is enforced by StorePolicy
 * in the controller, so authorize() returns true here.
 */
class StoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::notIn(StoreService::RESERVED_SLUGS), 'unique:stores,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', Rule::in(Store::STATUSES)],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}

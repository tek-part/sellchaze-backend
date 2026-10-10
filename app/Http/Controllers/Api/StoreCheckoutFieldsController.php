<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CheckoutFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreCheckoutFieldsController extends Controller
{
    private function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $user = $request->user();
        abort_unless($user && ((int) $store->owner_user_id === (int) $user->id || $user->can('store.settings.manage') || $user->can('stores-edit')), 403);

        return $store;
    }

    public function index(Request $request, CheckoutFields $fields): JsonResponse
    {
        return response()->json(['data' => $fields->configured($this->store($request)), 'presets' => ['cod' => $fields->defaults()]]);
    }

    public function update(Request $request, CheckoutFields $fields): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'fields' => ['required', 'array', 'size:'.count(CheckoutFields::PATHS)],
            'fields.*' => ['required', 'array:key,label,hint,enabled,required,position'],
            'fields.*.key' => ['required', 'string', 'distinct', Rule::in(array_keys(CheckoutFields::PATHS))],
            'fields.*.label' => ['required', 'array:ar,en'],
            'fields.*.label.ar' => ['required', 'string', 'max:100'],
            'fields.*.label.en' => ['required', 'string', 'max:100'],
            'fields.*.hint' => ['required', 'array:ar,en'],
            'fields.*.hint.ar' => ['nullable', 'string', 'max:250'],
            'fields.*.hint.en' => ['nullable', 'string', 'max:250'],
            'fields.*.enabled' => ['required', 'boolean'],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.position' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
        $contactRequired = false;
        foreach ($data['fields'] as $index => &$field) {
            if (! $field['enabled'] && $field['required']) {
                throw ValidationException::withMessages(["fields.$index.required" => 'A hidden field cannot be required.']);
            }
            $contactRequired = $contactRequired || (in_array($field['key'], ['phone', 'email'], true) && $field['enabled'] && $field['required']);
            $field['hint'] = ['ar' => $field['hint']['ar'] ?? '', 'en' => $field['hint']['en'] ?? ''];
        }
        unset($field);
        if (! $contactRequired) {
            throw ValidationException::withMessages(['fields' => 'Keep a phone number or email address visible and required so you can contact the buyer.']);
        }
        $store->update(['checkout_fields' => collect($data['fields'])->sortBy('position')->values()->all()]);

        return $this->index($request, $fields);
    }
}

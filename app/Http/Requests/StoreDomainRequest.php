<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connect a custom domain to a store.
 *
 * Only shape/length are asserted here. Hostname semantics (syntax, platform-owned
 * hosts, production dev-hosts, cross-store ownership) live in
 * StoreDomainService::assertValidHost() so the exact same rules apply to every
 * caller — HTTP, console and jobs alike — rather than only to this endpoint.
 */
class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised via the store policy in the controller
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253'],
        ];
    }

    public function messages(): array
    {
        return [
            'host.required' => __('A domain is required.'),
        ];
    }
}

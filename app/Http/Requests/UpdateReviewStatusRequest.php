<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 6H: merchant review moderation. Merchants may approve, reject, or hide a
 * review; only `approved` reviews are shown publicly and counted in analytics.
 */
class UpdateReviewStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership enforced by store.scope
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected', 'hidden'])],
        ];
    }
}

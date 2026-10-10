<?php

namespace App\Http\Requests;

class BulkVariantDeleteRequest extends BulkVariantSelectionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['confirm' => ['required', 'accepted']]);
    }
}

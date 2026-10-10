<?php

namespace App\Http\Requests;

class BulkVariantInventoryRequest extends BulkVariantSelectionRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'mode' => ['required', 'in:set,increase,decrease'],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000000'],
            'tracking' => ['required', 'in:keep,on,off'],
        ]);
    }
}

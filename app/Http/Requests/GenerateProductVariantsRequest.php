<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateProductVariantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'axes' => ['required', 'array', 'min:1', 'max:5'],
            'axes.*' => ['required', 'array:name,values'],
            'axes.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case', 'not_regex:/^\d+$/u'],
            'axes.*.values' => ['required', 'array', 'min:1', 'max:50'],
            'axes.*.values.*' => ['required', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $count = 1;
            foreach ($this->input('axes') as $index => $axis) {
                $values = array_map(fn (string $value) => mb_strtolower(trim($value)), $axis['values']);
                if (count(array_unique($values)) !== count($values)) {
                    $validator->errors()->add("axes.$index.values", 'Values within each property must be distinct.');
                }
                $count *= count($values);
            }
            if ($count > 200) {
                $validator->errors()->add('axes', 'Generate at most 200 combinations per request.');
            }
        }];
    }
}

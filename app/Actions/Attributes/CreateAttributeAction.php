<?php

namespace App\Actions\Attributes;

use App\Models\Attribute;
use App\Models\AttributeValues;

class CreateAttributeAction
{
    /**
     * Create an attribute with optional values from validated data.
     *
     * @param  array<string, mixed>  $data  Must include 'name', optionally 'type' and 'attribute_values'
     * @return Attribute
     */
    public function __invoke(array $data): Attribute
    {
        $attribute = Attribute::create([
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
        ]);

        if (! empty($data['attribute_values'])) {
            foreach ($data['attribute_values'] as $input) {
                AttributeValues::create([
                    'value' => $input['value'] ?? $input,
                    'attribute_id' => $attribute->id,
                ]);
            }
        }

        return $attribute->load('attribute_values');
    }
}

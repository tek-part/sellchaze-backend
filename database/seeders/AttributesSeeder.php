<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValues;
use Illuminate\Database\Seeder;

class AttributesSeeder extends Seeder
{
    public function run(): void
    {
        $attributesData = [
            'Color' => ['Black', 'Brown', 'Blonde', 'Auburn', 'Highlighted', 'Ombre'],
            'Length' => ['10 inches', '12 inches', '14 inches', '16 inches', '18 inches', '20 inches', '22 inches', '24 inches'],
            'Density' => ['130%', '150%', '180%', '200%', '250%'],
            'Texture' => ['Straight', 'Body Wave', 'Loose Wave', 'Curly', 'Deep Wave', 'Water Wave'],
            'Size' => ['Petite', 'Small', 'Medium', 'Large'],
            'Type' => ['Lace Front', 'Full Lace', 'HD Lace', 'Transparent Lace'],
        ];

        foreach ($attributesData as $attrName => $values) {
            $attr = Attribute::firstOrCreate(
                ['name' => $attrName],
                ['type' => 'select']
            );

            foreach ($values as $val) {
                if (!AttributeValues::where('attribute_id', $attr->id)->where('value', $val)->exists()) {
                    $av = new AttributeValues;
                    $av->attribute_id = $attr->id;
                    $av->value = $val;
                    $av->save();
                }
            }
        }

        $this->command->info('Attributes and values seeded.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehousesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'code' => 'EG',
                'name' => 'مصر / Egypt',
                'name_en' => 'Egypt',
                'name_ar' => 'مصر',
                'is_active' => true,
                'is_default' => true,
                'position' => 1,
            ],
            [
                'code' => 'AE',
                'name' => 'الإمارات',
                'name_en' => 'United Arab Emirates',
                'name_ar' => 'الإمارات',
                'is_active' => true,
                'is_default' => false,
                'position' => 2,
            ],
            [
                'code' => 'SA',
                'name' => 'السعودية',
                'name_en' => 'Saudi Arabia',
                'name_ar' => 'السعودية',
                'is_active' => true,
                'is_default' => false,
                'position' => 3,
            ],
            [
                'code' => 'KW',
                'name' => 'الكويت',
                'name_en' => 'Kuwait',
                'name_ar' => 'الكويت',
                'is_active' => true,
                'is_default' => false,
                'position' => 4,
            ],
        ];

        foreach ($rows as $row) {
            Warehouse::query()->updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }
}

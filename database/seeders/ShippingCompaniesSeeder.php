<?php

namespace Database\Seeders;

use App\Models\ShippingCompany;
use Illuminate\Database\Seeder;

class ShippingCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            [
                'name' => 'Aramex',
                'code' => 'ARAMEX',
                'website' => 'https://www.aramex.com',
                'tracking_url_template' => 'https://www.aramex.com/track/shipments?ShipmentNumber={tracking_number}',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'FedEx',
                'code' => 'FEDEX',
                'website' => 'https://www.fedex.com',
                'tracking_url_template' => 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}',
                'sort_order' => 20,
                'is_active' => true,
            ],
            [
                'name' => 'DHL',
                'code' => 'DHL',
                'website' => 'https://www.dhl.com',
                'tracking_url_template' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}',
                'sort_order' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Local Delivery UAE',
                'code' => 'LOCAL-UAE',
                'sort_order' => 40,
                'is_active' => true,
            ],
            [
                'name' => 'Local Delivery Kuwait',
                'code' => 'LOCAL-KWT',
                'sort_order' => 50,
                'is_active' => true,
            ],
        ];

        foreach ($companies as $company) {
            ShippingCompany::query()->updateOrCreate(
                ['code' => $company['code']],
                $company,
            );
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            UsersSeeder::class,
            WarehousesSeeder::class,
            AttributesSeeder::class,
            InvitationsSeeder::class,
            TransactionsSeeder::class,
            ShippingCompaniesSeeder::class,
            CurrencyRatesSeeder::class,
            EmailSettingsSeeder::class,
            GoogleSettingsSeeder::class,
            EmailTemplatesSeeder::class,
            SectorsSeeder::class,
            PlansSeeder::class,
        ]);
    }
}

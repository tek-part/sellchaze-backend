<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaysSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'Cash on delivery', 'slug' => 'cod'],
            ['name' => 'Bank transfer', 'slug' => 'bank_transfer'],
            ['name' => 'Stripe', 'slug' => 'stripe'],
            ['name' => 'PayPal', 'slug' => 'paypal'],
            ['name' => 'Tabby', 'slug' => 'tabby'],
            ['name' => 'Tamara', 'slug' => 'tamara'],
            ['name' => 'Paymob', 'slug' => 'paymob'],
            ['name' => 'Fawry', 'slug' => 'fawry'],
            ['name' => 'Fawaterak', 'slug' => 'fawaterak'],
            ['name' => 'Tap Payments', 'slug' => 'tap'],
            ['name' => 'PayTabs', 'slug' => 'paytabs'],
            ['name' => 'HyperPay', 'slug' => 'hyperpay'],
        ];

        foreach ($providers as $provider) {
            PaymentGateway::query()->updateOrCreate(['slug' => $provider['slug']], $provider);
        }
    }
}

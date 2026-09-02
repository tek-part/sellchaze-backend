<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaysSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'Cash on delivery', 'slug' => 'cod', 'icon_class' => 'fas fa-money-bill-wave'],
            ['name' => 'Bank transfer', 'slug' => 'bank_transfer', 'icon_class' => 'fas fa-building-columns'],
            ['name' => 'Stripe', 'slug' => 'stripe', 'icon_class' => 'fab fa-stripe'],
            ['name' => 'PayPal', 'slug' => 'paypal', 'icon_class' => 'fab fa-paypal'],
            ['name' => 'Tabby', 'slug' => 'tabby', 'icon_class' => 'fas fa-wallet'],
            ['name' => 'Tamara', 'slug' => 'tamara', 'icon_class' => 'fas fa-wallet'],
            ['name' => 'Paymob', 'slug' => 'paymob', 'icon_class' => 'fas fa-credit-card'],
            ['name' => 'Fawry', 'slug' => 'fawry', 'icon_class' => 'fas fa-receipt'],
            ['name' => 'Fawaterak', 'slug' => 'fawaterak', 'icon_class' => 'fas fa-file-invoice-dollar'],
            ['name' => 'Tap Payments', 'slug' => 'tap', 'icon_class' => 'fas fa-credit-card'],
            ['name' => 'PayTabs', 'slug' => 'paytabs', 'icon_class' => 'fas fa-credit-card'],
            ['name' => 'HyperPay', 'slug' => 'hyperpay', 'icon_class' => 'fas fa-credit-card'],
        ];

        foreach ($providers as $provider) {
            PaymentGateway::query()->updateOrCreate(['slug' => $provider['slug']], $provider + ['is_active' => true]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\OrderQuotations;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionsSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = User::where('email', 'supplier@demo.com')->first();
        $customer = User::where('email', 'customer@demo.com')->first();

        if (!$supplier || !$customer) {
            $this->command->warn('Customer or Supplier not found.');
            return;
        }

        $acceptedQuotations = OrderQuotations::where('supplier_user_id', $supplier->id)
            ->where('customer_user_id', $customer->id)
            ->where('status', 'accepted')
            ->get();

        $count = Transaction::where('supplier_user_id', $supplier->id)->where('customer_user_id', $customer->id)->count();
        if ($count > 0) {
            $this->command->info('Transactions already exist, skipping.');
            return;
        }

        if ($acceptedQuotations->isEmpty()) {
            $orderIds = serialize([]);
            $amount = 250;
        } else {
            $orderIds = serialize($acceptedQuotations->pluck('order_id')->toArray());
            $amount = $acceptedQuotations->sum('price');
        }

        Transaction::create([
            'supplier_user_id' => $supplier->id,
            'customer_user_id' => $customer->id,
            'orders' => $orderIds,
            'amount' => $amount,
            'transfer_method' => 'bank_transfer',
            'notes' => 'Demo transaction - balance settlement.',
        ]);

        $this->command->info('Transactions seeded.');
    }
}

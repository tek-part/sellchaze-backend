<?php

namespace Database\Seeders;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvitationsSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = User::where('email', 'supplier@demo.com')->first();
        $merchant = User::where('email', 'customer@demo.com')->first();

        if (! $supplier || ! $merchant) {
            $this->command->warn('Merchant or Supplier not found.');

            return;
        }

        Invitation::updateOrCreate(
            [
                'email' => $merchant->email,
                'sender_user_id' => $supplier->id,
            ],
            [
                'invitation_token' => substr(md5($merchant->email.config('app.key')), 0, 32),
                'invite_code' => null,
                'invited_role' => 'merchant',
                'status' => 'accepted',
                'permissions' => null,
                'registered_at' => now(),
                'receiver_user_id' => $merchant->id,
                'expires_at' => null,
            ]
        );

        $this->command->info('Invitations seeded.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->call(RolesTableSeeder::class);

        // Use updateOrCreate so password and is_active stay in sync when re-seeding
        // (firstOrCreate never updates an existing row — common cause of "credentials do not match").
        $admin = User::updateOrCreate(
            ['email' => 'wigpleasure@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('12345678'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['Admin']);
        // Permissions come from the Admin role only (avoids drifting from role seed).
        $admin->syncPermissions([]);

        $this->createProfile($admin, 'wigpleasure_admin');

        $merchant = User::updateOrCreate(
            ['email' => 'customer@demo.com'],
            [
                'name' => 'Demo Merchant',
                'password' => Hash::make('12345678'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $merchant->syncRoles(['Merchant']);
        $merchant->syncPermissions([]);

        $this->createProfile($merchant, 'demo_customer');

        $supplier = User::updateOrCreate(
            ['email' => 'supplier@demo.com'],
            [
                'name' => 'Demo Supplier',
                'password' => Hash::make('12345678'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $supplier->syncRoles(['Supplier']);
        $supplier->syncPermissions([]);

        $this->command->info('Users seeded: admin (wigpleasure@gmail.com), merchant (customer@demo.com), supplier (supplier@demo.com). Password: 12345678');
    }

    private function createProfile(User $user, string $username): void
    {
        if (Profile::where('user_id', $user->id)->exists()) {
            return;
        }
        $base = $username;
        $i = 0;
        while (Profile::where('username', $username)->exists()) {
            $username = $base.'_'.++$i;
        }
        Profile::create([
            'user_id' => $user->id,
            'username' => $username,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

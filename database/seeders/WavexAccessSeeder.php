<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class WavexAccessSeeder extends Seeder
{
    public function run(): void
    {
        $perm = Permission::firstOrCreate(['name' => 'wavex-access']);
        $role = Role::query()->where('name', 'Admin')->first();
        if ($role) {
            $role->givePermissionTo($perm);
        }
    }
}

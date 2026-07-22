<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\ConsoleOutput;
use App\Models\User;
use Auth;

class AdminPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /*$output = new ConsoleOutput();

        for($i=0; $i<50000; $i++)
        {
             $output->writeln('Converting '.$i.' of 50000');
        }*/

        $permissions = Permission::get();

        if(count($permissions) > 0) {
            $permission = Permission::pluck('id')->toArray();
            $user       = User::find('1');
            
            $user->syncPermissions($permissions);

            $this->command->info('Permissions successfully assigned to super admin.');
        }
        else {
            $this->command->error('There are no permissions to assign to super admin.');
        }
    }
}

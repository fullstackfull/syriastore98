<?php

namespace Workdo\FrequentlyProduct\database\seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\FrequentlyProduct\app\Models\FrequentlyProductUtility;

class RoleTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        $super_admin = User::where('type', 'super admin')->first();

        FrequentlyProductUtility::GivePermissionToRoles();
    }
}

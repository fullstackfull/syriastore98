<?php

namespace Workdo\Duo2FA\Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\Duo2FA\app\Models\Duo2FAUtility;

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
        Duo2FAUtility::GivePermissionToRoles();
    }
}

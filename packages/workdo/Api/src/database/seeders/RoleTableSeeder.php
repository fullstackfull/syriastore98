<?php

namespace Workdo\Api\Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\Api\app\Models\ApiUtility;


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

        ApiUtility::GivePermissionToRoles();
    }
}

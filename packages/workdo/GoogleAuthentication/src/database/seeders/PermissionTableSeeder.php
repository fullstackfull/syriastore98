<?php

namespace Workdo\GoogleAuthentication\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use App\Models\Role;
use App\Models\Permission;

class PermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        Artisan::call('cache:clear');

        $permission  = [
            'Manage Google Authentication',
            'Enable Google Authentication',
            'Disable Google Authentication',
        ];
        $roleQuery = Role::query();
        $permissionQuery = Permission::query();


        $admin_role = (clone $roleQuery)->where('name','admin')->first();
        $super_admin_role = (clone $roleQuery)->where('name','super admin')->first();
        foreach ($permission as $key => $value)
        {
            $data = (clone $permissionQuery)->where('name',$value)->where('module','GoogleAuthentication')->first();

            if (!$data) {
                $data =  (clone $permissionQuery)->create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => 'GoogleAuthentication',
                        'created_by' => 0,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
                if(!$admin_role->hasPermission($value)){
                    $admin_role->givePermission($data);
                }

                if(!$super_admin_role->hasPermission($value)){
                    $super_admin_role->givePermission($data);
                }
            } else {
                if(!$admin_role->hasPermission($value)){
                    $admin_role->givePermission($data);
                }

                if(!$super_admin_role->hasPermission($value)){
                    $super_admin_role->givePermission($data);
                }
            }
        }
    }
}

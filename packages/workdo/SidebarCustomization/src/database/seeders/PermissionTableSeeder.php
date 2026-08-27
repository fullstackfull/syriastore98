<?php

namespace Workdo\SidebarCustomization\Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

class PermissionTableSeeder extends Seeder
{
     public function run()
    {
        Model::unguard();
        Artisan::call('cache:clear');
        $module = 'SidebarCustomization';

        $superadmin_permissions  = [   
            'Manage Sidebar Menus',
            'Create Sidebar Menus',
            'Edit Sidebar Menus',
            'Delete Sidebar Menus',
        ];

        $company_permissions  = [   
            'Manage Sidebar Menus',
            'Create Sidebar Menus',
            'Edit Sidebar Menus',
            'Delete Sidebar Menus',
        ];

        $superAdmin = User::where('type', 'super admin')->first();
        $superadmin_role = Role::where('name','super admin')->first();

        foreach ($superadmin_permissions as $key => $value)
        {
            $check = Permission::where('name',$value)->where('module',$module)->exists();
            if($check == false)
            {
                $permission = Permission::create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => $module,
                        'created_by' => $superAdmin->id,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
                if(!$superadmin_role->hasPermission($value))
                {
                    $superadmin_role->givePermission($permission);
                }
            }
        }

        $companyRole = Role::where('name', 'admin')->first();
        foreach ($company_permissions as $key => $value) {
            $permission = Permission::where('name', $value)->first();
            if (empty($permission)) {
                $permission = Permission::create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => $module,
                        'created_by' => 0,
                        "created_at" => date('Y-m-d H:i:s'),
                        "updated_at" => date('Y-m-d H:i:s')
                    ]
                );
            }
            if (!$companyRole->hasPermission($value)) {
                $companyRole->givePermission($permission);
            }
        }
    }
}

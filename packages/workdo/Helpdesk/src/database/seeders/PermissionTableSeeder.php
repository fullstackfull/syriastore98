<?php

namespace Workdo\Helpdesk\Database\Seeders;

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
        $module = 'Helpdesk';

        $superadminPermissions  = [
            'Manage Helpdesk',
            'Manage Helpdesk Ticket',
            'Create Helpdesk Ticket',
            'Edit Helpdesk Ticket',
            'Show Helpdesk Ticket',
            'Reply Helpdesk Ticket',
            'Delete Helpdesk Ticket',
            'Manage SystemSetup',
            'Manage Helpdesk Category',
            'Create Helpdesk Category',
            'Edit Helpdesk Category',
            'Delete Helpdesk Category',
            'Manage Helpdesk Status',
            'Create Helpdesk Status',
            'Edit Helpdesk Status',
            'Delete Helpdesk Status',
            'Manage Helpdesk New Ticket',
            'Manage Helpdesk Ticket Reply',
        ];

        $companyPermissiom  = [
            'Manage Helpdesk',
            'Manage Helpdesk Ticket',
            'Show Helpdesk Ticket',
            'Reply Helpdesk Ticket',
            'Create Helpdesk Ticket',
            'Edit Helpdesk Ticket',
            'Delete Helpdesk Ticket',
        ];
        $superAdmin = User::where('type', 'super admin')->first();
        $superadminRole = Role::where('name', 'super admin')->first();

        foreach ($superadminPermissions as $key => $value) {
            $check = Permission::where('name', $value)->where('module', $module)->exists();
            if ($check == false) {
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
                if (!$superadminRole->hasPermission($value)) {
                    $superadminRole->givePermission($permission);
                }
            }
        }

        $companyRole = Role::where('name', 'admin')->first();
        foreach ($companyPermissiom as $key => $value) {
            $permission = Permission::where('name', $value)->first();
            if (empty($permission)) {
                $permission = Permission::create(
                    [
                        'name' => $value,
                        'guard_name' => 'web',
                        'module' => $$module,
                        'created_by' => $superAdmin->id,
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

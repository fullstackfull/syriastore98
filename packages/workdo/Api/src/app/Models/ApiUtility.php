<?php

namespace Workdo\Api\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\Api\Database\factories\ApiUtilityFactory;

class ApiUtility extends Model
{
    use HasFactory, Cachable;

    public static function GivePermissionToRoles($role_id = null, $rolename = null)
    {
        $client_permissions = [
            'Manage Api',

        ];

        if ($role_id == Null) {
            // client
            $roles_c = Role::where('name', 'admin')->get();
            foreach ($roles_c as $role) {
                foreach ($client_permissions as $permission_c) {
                    $permission = Permission::where('name', $permission_c)->first();
                    if (!$role->hasPermission($permission_c)) {
                        $role->givePermission($permission);
                    }
                }
            }

        } else {
            if ($rolename == 'admin') {
                $roles_c = Role::where('name', 'admin')->where('id', $role_id)->first();
                foreach ($client_permissions as $permission_c) {
                    $permission = Permission::where('name', $permission_c)->first();
                    if (!$roles_c->hasPermission($permission_c)) {
                        $roles_c->givePermission($permission);
                    }
                }
            }
        }

    }
}

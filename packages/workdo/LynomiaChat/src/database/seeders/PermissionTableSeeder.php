<?php

namespace Workdo\LynomiaChat\Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * The merchant-side permission this module gates on.
 *
 * ONE PERMISSION IN PHASE 1, because there is one merchant surface. More arrive with the
 * phases that add surfaces; seeding permissions for screens that do not exist would put
 * names in the role editor that grant nothing.
 *
 * NOTE ON THE `admin` ROLE. Role::where('name','admin') is a single GLOBAL row shared by
 * every merchant owner on the platform (App\Models\Utility::addNewData re-grants it on every
 * login, with no store_id filter). Granting here therefore grants to all merchant owners at
 * once. That is the existing platform behaviour and this seeder follows it rather than
 * inventing a different model -- but it is why the permission is NOT the tenancy control.
 * EnsureStoreOwnsLink is.
 */
class PermissionTableSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        $module = 'LynomiaChat';
        $permissions = ['Manage Lynomia Chat'];

        $companyRole = Role::where('name', 'admin')->first();

        foreach ($permissions as $value) {
            $exists = Permission::where('name', $value)->where('module', $module)->exists();

            if ($exists) {
                continue;
            }

            $permission = Permission::create([
                'name' => $value,
                'guard_name' => 'web',
                'module' => $module,
                'created_by' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($companyRole && ! $companyRole->hasPermission($value)) {
                $companyRole->givePermission($permission);
            }
        }

        Model::reguard();
    }
}

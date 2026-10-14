<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * The merchant-side permission this module gates on.
 *
 * THREE PERMISSIONS, BECAUSE READING AND ACTING ARE DIFFERENT DECISIONS. Phase 1 had one
 * surface and so one permission. The inbox makes the distinction real: a merchant may want a
 * junior staff member to SEE what customers are asking without being able to answer them in
 * the store's name, and may want only a lead to move a conversation between colleagues. A
 * single permission would force all three on anyone allowed to look.
 *
 *   Manage Lynomia Chat   open the module and read conversations
 *   Reply Lynomia Chat    send a reply or an internal note
 *   Assign Lynomia Chat   assign a conversation and change its status
 *
 * These are enforced on the ROUTES, not in the interface. A hidden button is not an
 * authorization control -- the staff member types the path.
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
        $permissions = ['Manage Lynomia Chat', 'Reply Lynomia Chat', 'Assign Lynomia Chat'];

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

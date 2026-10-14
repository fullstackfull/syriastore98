<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * The merchant-side permission this module gates on.
 *
 * FIVE PERMISSIONS, BECAUSE READING AND ACTING ARE DIFFERENT DECISIONS. Phase 1 had one
 * surface and so one permission. The inbox makes the distinction real: a merchant may want a
 * junior staff member to SEE what customers are asking without being able to answer them in
 * the store's name, and may want only a lead to move a conversation between colleagues. A
 * single permission would force all three on anyone allowed to look.
 *
 *   Manage Lynomia Chat        open the module and read conversations
 *   Reply Lynomia Chat         send a reply or an internal note
 *   Assign Lynomia Chat        assign a conversation and change its status
 *   Manage Lynomia Automation  open the Automation Studio and change what it sends
 *   Manage Lynomia Consent     record what a shopper agreed to receive
 *
 * The fourth is Phase 4's, and it is a separate decision from the first three rather than a
 * finer slice of them: replying is one message, written by a person who read what was asked,
 * while publishing a flow is every future customer being answered automatically with nobody
 * reading anything. A merchant can reasonably want staff who do the first and not the second.
 *
 * The fifth is heavier than the fourth and separate for that reason: editing an automation
 * changes what the store would send, while recording a consent decision changes whether a named
 * person may be messaged at all. A merchant can reasonably let staff build automations without
 * letting them mark a customer as having agreed to marketing.
 *
 * It narrows, it never widens. Lynomia authorizes flow management as a workspace administrator
 * and only the store owner holds that identity, so granting this to staff lets them reach the
 * Studio and be refused there -- which is why the Studio says who to ask instead of showing a
 * bare 403.
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
        $permissions = ['Manage Lynomia Chat', 'Reply Lynomia Chat', 'Assign Lynomia Chat',
            'Manage Lynomia Automation', 'Manage Lynomia Consent'];

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

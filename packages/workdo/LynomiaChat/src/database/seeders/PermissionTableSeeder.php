<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController;

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
 *   Manage Lynomia Audiences   define who a campaign may be sent to
 *   Manage Lynomia Templates   write and submit the message text Meta approves
 *   Manage Lynomia Campaigns   send those words to those people
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
 * The sixth is Phase 5B's. An audience decides WHO gets messaged, which is narrower than
 * building an automation and different from reading the inbox: a merchant can reasonably let
 * a marketer define segments without letting them publish a flow, and can reasonably let
 * support read conversations without letting them build the list a campaign sends to.
 *
 * The seventh is Phase 5C's and is the heaviest of the marketing three. A template is the
 * text Meta approves and every campaign and automation then sends, so editing one changes
 * what thousands of customers read -- and submitting one spends a review cycle that cannot
 * be taken back.
 *
 * The eighth is the heaviest of the marketing four. An audience says who could be reached
 * and a template says what the words are; a campaign is the act of SENDING those words to
 * those people, and it cannot be taken back once the first message leaves.
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
        // ONE LIST, shared with the Super Admin page that reports how widely each is granted.
        // A permission added here and forgotten there is a control nobody can see the reach of.
        $permissions = IntegrationController::MODULE_PERMISSIONS;

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

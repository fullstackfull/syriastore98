<?php

/**
 * GIVE THE DEV FIXTURE'S PLATFORM OPERATOR THE ROLE PRODUCTION GIVES IT.
 * =================================================================================================
 *
 * GAP-179 / P06-06. A dev-fixture-only grant, in the same family as
 * `docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh`, and for the same reason:
 * without it a live harness measures nothing and reports success.
 *
 * WHY IT IS NEEDED, MEASURED RATHER THAN ASSUMED. `database/seed-p2dev.php` creates
 * `operator@example.test` with `type = 'super admin'` and NO Laratrust role -- the fixture's two
 * roles (`p2dev-owner`, `p2dev-staff`) both belong to the merchant (`created_by = 2`). CLAUDE.md
 * records that there is no super-admin bypass: `isAbleTo()` alone REFUSES the platform operator.
 * So on the untouched fixture, every operator screen whose controller still carries a bare
 * `isAbleTo()` answers `redirect()->back()`.
 *
 * AND `back()` IS THE TRAP, not a visible refusal. Measured on this fixture before this script
 * existed, driving nine screens in sequence as the operator:
 *
 *     /plan-coupon        -> 200, final URL /platform/site-content   (the PREVIOUS screen)
 *     /plan-coupon/create -> 200, final URL /platform/site-content
 *     /modules/list       -> 200, final URL /super-admin/lynomia-integration
 *     /plan               -> 200, final URL /super-admin/lynomia-integration
 *     /plan/create        -> 200, final URL /super-admin/lynomia-integration
 *     /plan-request       -> 200, final URL /super-admin/lynomia-integration
 *
 * Every one of those is HTTP 200 with a fully rendered page, so a harness that checks the status
 * code photographs the previous screen and reports a pass. That is the failure mode
 * `serve-dev.sh` records for the merchant ("eleven distinct images from seventeen, every row
 * reporting ok") reproduced on the operator.
 *
 * WHAT IS GRANTED IS COPIED FROM PRODUCTION, NOT CHOSEN HERE. `Utility::addNewData()` creates a
 * `super admin` role and grants it exactly the 29 permissions in `$superadminNewPermission`
 * (app/Models/Utility.php:3384-3413). This script reproduces that list verbatim. It weakens no
 * gate and invents no permission: a name absent from `Utility::addNewData()` would be a permanent
 * 403 for everyone, which is the 'Edit Order' defect CLAUDE.md records.
 *
 * REFUSES OUTSIDE local/testing, like `seed-p2dev.php`, because granting a role is a privilege
 * change and a claim that holds only because nobody ran it elsewhere is the kind this project has
 * been bitten by.
 *
 * Laratrust, not spatie: `addRole()` / `givePermission()`, never `assignRole()` -- and NOT
 * `attachRole()` either, which is spatie-shaped and does not exist on this trait (it threw
 * BadMethodCallException on the first run of this script). `StoreController:387` is the in-product
 * precedent.
 *
 * usage:
 *   APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
 *     php tests/Support/bin/grant-dev-operator-role.php
 */

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "grant-dev-operator-role.php is a development fixture tool and refuses to run "
        . "outside local/testing. It grants a role to a super-admin account.\n");
    exit(2);
}

/* Verbatim from app/Models/Utility.php $superadminNewPermission. Do not edit this list to make a
   screen reachable -- if a screen needs a permission production does not grant the operator, that
   is a finding about the screen, not about this fixture. */
$productionOperatorPermissions = [
    'Manage Dashboard',
    'Manage Store Setting',
    'Manage User',
    'Create User',
    'Edit User',
    'Delete User',
    'Reset Password',
    'Manage Coupon',
    'Create Coupon',
    'Edit Coupon',
    'Delete Coupon',
    'Show Coupon',
    'Manage Plan',
    'Create Plan',
    'Edit Plan',
    'Manage Plan Request',
    'Manage Email Template',
    'Manage CMS',
    'Manage Country',
    'Manage Setting',
    'Manage Language',
    'Create Language',
    'Manage Module',
    'Module Add',
    'Module remove',
    'Manage currency',
    'Create currency',
    'Edit currency',
    'Delete Language',
];

$operator = User::where('email', 'operator@example.test')->first();

if (! $operator) {
    fwrite(STDERR, "error: no user operator@example.test -- run database/seed-p2dev.php first\n");
    exit(3);
}

if (strtolower(trim((string) $operator->type)) !== 'super admin') {
    fwrite(STDERR, "error: operator@example.test has type '{$operator->type}', expected 'super admin'\n");
    exit(4);
}

$role = Role::where('name', 'super admin')->first();
$created = false;

if (! $role) {
    $role = Role::create([
        'name' => 'super admin',
        'display_name' => 'super admin',
        'description' => 'Platform operator (dev fixture; mirrors Utility::addNewData())',
        'created_by' => $operator->id,
    ]);
    $created = true;
}

$granted = 0;
$missing = [];

foreach ($productionOperatorPermissions as $name) {
    $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

    if (! $permission) {
        /* Production's own block creates the row when it is absent, so this mirrors it rather
           than inventing a name. Recorded in the summary either way. */
        $permission = Permission::create(['name' => $name, 'guard_name' => 'web']);
        $missing[] = $name;
    }

    if (! $role->hasPermission($name)) {
        $role->givePermission($permission);
        $granted++;
    }
}

$attached = false;

if (! $operator->hasRole('super admin')) {
    $operator->addRole($role);
    $attached = true;
}

/* `User` IS Cachable and Laratrust memoises a user's permission set, so without this the server
   keeps refusing the operator with the role already in the database.
   `Role` AND `Permission` ARE NOT CACHABLE -- they are two of the sixteen exceptions CLAUDE.md
   lists, and calling flushCache() on either throws BadMethodCallException. It did, on the second
   run of this script, which is the documented fact confirming itself. */
(new User)->flushCache();
Cache::flush();

$operator = User::where('email', 'operator@example.test')->first();

echo 'role=' . ($created ? 'created' : 'existing')
    . " id={$role->id}"
    . ' permissions_granted=' . $granted
    . ' permission_rows_created=' . count($missing)
    . ' role_attached=' . ($attached ? 'yes' : 'already')
    . ' operator_isAbleTo(Manage Plan)=' . var_export($operator->isAbleTo('Manage Plan'), true)
    . "\n";

if ($missing) {
    echo 'permission rows created (absent from this fixture): ' . implode(', ', $missing) . "\n";
}

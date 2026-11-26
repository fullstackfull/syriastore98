<?php

/**
 * GIVE THE DEV FIXTURE'S MERCHANT WHAT PRODUCTION GIVES A LYNOMIA-ENABLED TENANT.
 * =================================================================================================
 *
 * GAP-179 / P06-06, the LynomiaChat MERCHANT surfaces. A dev-fixture-only grant, in the same
 * family as `grant-dev-operator-role.php` and `docs/development/phase-2.5/tools/
 * grant-dev-harness-permissions.sh`, and for the same reason: without it a live harness measures
 * nothing and reports success.
 *
 * FOUR THINGS ARE NEEDED AND THE FOURTH IS THE ONE NOBODY EXPECTS.
 *
 *  1. THE PERMISSIONS. `/lynomia/automation*` carries
 *     `Laratrust\Middleware\Permission:Manage Lynomia Automation`, and the Consent TAB on
 *     `/lynomia/automation/commerce` renders only when the person also holds `Manage Lynomia
 *     Consent` (`AutomationController::commerce()` passes it as `canManageConsent`). A harness
 *     that grants only the first measures a page with ONE tab and cannot see the second label.
 *
 *  2. A LINK ROW. `EnsureStoreOwnsLink:existing` refuses a store with no `lynomia_store_links`
 *     row.
 *
 *  3. THE PLAN'S MODULE LIST, which `PlanModuleCheck` resolves.
 *
 *  4. AN `add_on_managers` ROW -- AND THIS IS THE PREMISE THAT IS EASY TO GET WRONG IN BOTH
 *     DIRECTIONS. `CLAUDE.md` says no WorkDo module loads without one. For LynomiaChat the first
 *     half of that is FALSE and the second half is TRUE, which is a sharper statement than either:
 *
 *       LOADS WITHOUT IT.  `PackageServiceProvider::register()` lists LynomiaChat in
 *                          `$staticAddons` beside LandingPage, so its provider, routes, views and
 *                          migrations exist with the table empty. That is why
 *                          `Route::has('lynomia.admin.index')` is true in the test suite.
 *
 *       UNREACHABLE WITHOUT IT. `ActivatedModule()` intersects the owner's activations with
 *                          `Module::getByStatus(1)`, and `App\Classes\Module::getByStatus()` is
 *                          `AddOnManager::where('is_enable', $status)->pluck('module')`
 *                          (`app/Classes/Module.php:117-120`). With the table empty that
 *                          intersection is EMPTY, so the merchant's entitlement resolves to
 *                          nothing and every module page answers a dashboard redirect.
 *
 *     MEASURED on this fixture before this script existed: all four combinations of
 *     `/lynomia/automation/commerce` answered HTTP 200 having landed on `/dashboard`.
 *
 * AND `redirect()->route('dashboard')` IS THE TRAP, not a visible refusal: it is HTTP 200 with a
 * fully rendered page, so a status-code harness photographs the dashboard and reports a pass.
 * `measure-lynomia-merchant-live.cjs` compares the final URL for exactly this reason.
 *
 * REFUSES OUTSIDE local/testing, like `seed-p2dev.php` and `grant-dev-operator-role.php`: granting
 * a permission is a privilege change, and a claim that holds only because nobody ran it elsewhere
 * is the kind this project has been bitten by.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/p2dev.sqlite" CACHE_STORE=file \
 *   SESSION_DRIVER=file APP_ENV=local \
 *   php artisan tinker --execute="require 'tests/Support/bin/grant-dev-lynomia-merchant.php';"
 */

use App\Models\AddOnManager;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Support\Facades\DB;

if (! app()->environment(['local', 'testing'])) {
    throw new \RuntimeException(
        'grant-dev-lynomia-merchant.php is a development fixture and refuses to run outside '
        . 'local/testing. It grants module permissions to a known account.'
    );
}

$merchant = User::where('email', 'merchant@example.test')->first();

if (! $merchant) {
    throw new \RuntimeException('fixture is missing merchant@example.test -- run database/seed-p2dev.php first');
}

/*
 * The two permissions the routes and the view really read, named rather than guessed:
 * `Manage Lynomia Automation` from the route group's Laratrust middleware, `Manage Lynomia
 * Consent` from AutomationController::commerce()'s `canManageConsent`.
 *
 * forceFill for `module`, for the reason `InteractsWithLynomia` records: App\Models\Permission
 * sets `public $guarded = []` but extends Laratrust's Permission, whose non-empty
 * `protected $fillable` wins in isFillable(), so `module` is silently dropped by create().
 */
$role = Role::where('created_by', $merchant->creatorId())->orderBy('id')->first();

if (! $role) {
    throw new \RuntimeException('the fixture merchant holds no role -- run database/seed-p2dev.php first');
}

$granted = [];
/*
 * `Manage Lynomia Chat` IS NOT OPTIONAL AND WAS FOUND BY BEING REFUSED, not by reading the route
 * line. The `/automation/commerce` declaration carries `Permission:Manage Lynomia Automation`, so
 * granting that looked like the whole grant -- and the page still answered 403 "User does not have
 * any of the necessary access rights" in all four combinations. `gatherRouteMiddleware()` on the
 * named route prints the real stack, and an OUTER group adds
 * `Laratrust\Middleware\Permission:Manage Lynomia Chat` above it. A route's own middleware list is
 * not its middleware stack.
 */
foreach (['Manage Lynomia Chat', 'Manage Lynomia Automation', 'Manage Lynomia Consent'] as $name) {
    $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'guard_name' => 'web']);

    if ($permission->module !== 'LynomiaChat') {
        $permission->forceFill(['module' => 'LynomiaChat'])->save();
    }

    /*
     * IDEMPOTENT, AND THE PIVOT IS CHECKED DIRECTLY RATHER THAN THROUGH LARATRUST.
     * `givePermission()` inserts unconditionally -- a second run of this script died with
     * `UNIQUE constraint failed: permission_role.permission_id, permission_role.role_id`, which
     * makes a re-runnable fixture script un-re-runnable. And `$role->hasPermission()` is not the
     * way to avoid it: Laratrust MEMOISES a role's permission set on first read, so a probe taken
     * before the attach caches a stale set for the rest of the process -- the same trap
     * `InteractsWithLynomia` records for `hasRole()`.
     */
    $attached = DB::table('permission_role')
        ->where('permission_id', $permission->id)
        ->where('role_id', $role->id)
        ->exists();

    if (! $attached) {
        $role->givePermission($permission);
    }

    $granted[] = $name . ($attached ? ' (already held)' : ' (granted)');
}
$role->flushCache();

userActiveModule::updateOrCreate(['user_id' => $merchant->id, 'module' => 'LynomiaChat'], []);

$plan = Plan::find($merchant->plan_id);
$planModules = 'no plan';
if ($plan) {
    $modules = array_values(array_filter(array_map('trim', explode(',', (string) $plan->modules))));
    if (! in_array('LynomiaChat', $modules, true)) {
        $modules[] = 'LynomiaChat';
        $plan->modules = implode(',', $modules);
        $plan->save();
    }
    $planModules = $plan->modules;
}

AddOnManager::updateOrCreate(['module' => 'LynomiaChat'], [
    'name' => 'Lynomia Chat',
    'monthly_price' => 0,
    'yearly_price' => 0,
    'is_enable' => 1,
    'package_name' => 'lynomia-chat',
]);

/*
 * A link row for the merchant's own store. `EnsureStoreOwnsLink:existing` only requires one to
 * exist, so the state is left as whatever is already there rather than forced to `active`: a
 * harness that silently promoted a pending link would measure a page the fixture does not have.
 */
$storeId = (int) $merchant->current_store;
$links = DB::table('lynomia_store_links')->where('store_id', $storeId)->count();

if ($links === 0) {
    DB::table('lynomia_store_links')->insert([
        'store_id' => $storeId,
        'owner_user_id' => $merchant->id,
        'lynomia_account_id' => 'acct-dev-' . $storeId,
        'lynomia_account_name' => 'Dev Workspace',
        'state' => 'pending_verification',
        'link_mode' => 'provisioned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $links = 1;
}

$merchant->flushCache();

echo 'merchant=' . $merchant->id
    . ' store=' . $storeId
    . ' permissions=' . implode('|', $granted)
    . ' plan_modules=' . $planModules
    . ' add_on_managers=' . AddOnManager::where('is_enable', 1)->pluck('module')->implode('|')
    . ' links=' . $links
    . "\n";
echo 'ActivatedModule(' . $merchant->id . ')=' . implode('|', ActivatedModule($merchant->id)) . "\n";
echo "NOW run: php artisan cache:clear (with the four variables PREFIXED, never exported)\n";

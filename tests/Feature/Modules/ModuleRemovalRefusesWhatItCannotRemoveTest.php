<?php

namespace Tests\Feature\Modules;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `POST /remove-modules/{module}` ACCEPTED ANY STRING AND THEN DELETED PERMISSIONS BY IT.
 *
 * Phase 06 `CTRL-002` is "إطلاق تدريجي وإيقاف آمن" -- gradual rollout and SAFE SHUTDOWN -- and
 * `CTRL-004` is "عدم حذف بيانات ميزة بمجرد إيقاف إتاحتها", do not delete a feature's data merely
 * because its availability is switched off. This handler is the shutdown door, and it had no
 * validation at all.
 *
 * THE GUARD THAT WAS NOT A GUARD. `ModuleController::remove()` read
 * `$module = Module::find($moduleName); if($module) { ... }`. Measured in `app/Classes/Module.php`:
 * `find()`'s final `else` branch assigns `$this->name = $name` and returns `$this` for ANY string,
 * with no `module.json` and no `add_on_managers` row -- so `if($module)` was true for every input
 * the router could deliver. Verified by execution against the booted container.
 *
 * WHAT FOLLOWED THE GUARD, in order, with no transaction:
 *   :199  Permission::where('module', $moduleName)->delete()
 *   :200  AddOnManager::where('name', $moduleName)->delete()
 *   :202  $this->deleteDirectory('packages/workdo/' . $moduleName)
 *
 * SO TWO DISTINCT LOSSES WERE REACHABLE, and this file pins the first because it is the one that is
 * deployment-independent:
 *
 * 1. `Base` IS NOT A MODULE -- IT IS THE CORE PERMISSION SET. `packages/workdo/Base` does not exist;
 *    `Permission.module = 'Base'` is what `helper.php:1751` reads to render the whole
 *    role-permission UI. And `permission_role.permission_id` carries `onDelete('cascade')`
 *    (`2023_12_08_100022_laratrust_setup_tables.php:70-71`), so deleting those rows ALSO strips
 *    those grants from every role of every tenant. One request, platform-wide authorization loss.
 *
 * 2. The directory delete took a RELATIVE path built from the raw route segment, with no
 *    `basename()` and no `base_path()` -- while `Module::json()` in the same class DOES use
 *    `base_path()`. Whether a traversal resolves therefore depends on the server's working
 *    directory, and `public/index.php` performs no `chdir`. That half is deployment-dependent and
 *    is pinned by `RemovableModuleTest` against disposable fixtures rather than asserted here.
 *
 * SEVERITY, STATED HONESTLY. The route is gated by `isAbleTo('Module remove')`, which
 * `Utility.php`'s `$superadminNewPermission` seeds only to `super admin`, and
 * `RolePolicy::isGrantableBy` refuses every `config/superadminaccess.php` name -- so a merchant
 * cannot grant it to themselves. This is an operator-reachable destructive door, NOT a privilege
 * escalation. It is worth fixing because an operator typo, a stale bookmark or a crawled form
 * should not be able to delete the platform's authorization model.
 *
 * THE FIX IS NOT A NEW ENGINE. `Module::has()` already exists and is grounded in
 * `File::directories(base_path('packages/workdo'))`; measured, it answers false for `Base`, `..`,
 * `%2e%2e`, `../..` and `''`, and true for a real module. This codebase also already fixed the
 * identical shape once, at `AddonController::destroy` (recorded there as SEC-F5), using
 * `InstallAddonRequest::safeDirectoryName()`. Both are reused.
 */
class ModuleRemovalRefusesWhatItCannotRemoveTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The core permission set plus one tenant role holding it, which is what the cascade destroys.
     *
     * @return array{0: Permission, 1: Role}
     */
    private function coreGrant(): array
    {
        $permission = $this->permissionForModule('Manage Order', 'Base');

        $role = Role::create([
            'name' => 'tenant-manager',
            'display_name' => 'Tenant Manager',
            'description' => 'a merchant role that holds a core permission',
            'created_by' => 1,
        ]);

        DB::table('permission_role')->insert([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
        ]);

        return [$permission, $role];
    }

    private function grantsFor(int $permissionId): int
    {
        return DB::table('permission_role')->where('permission_id', $permissionId)->count();
    }

    /**
     * A permission really belonging to `$module`, which MASS ASSIGNMENT CANNOT CREATE.
     *
     * Measured: `App\Models\Permission` sets `$guarded = []`, but it extends Laratrust's
     * `Permission`, which declares its own `$fillable` (`vendor/santigarcor/laratrust/src/Models/Permission.php:25`)
     * -- and Laravel honours a non-empty `$fillable` regardless of `$guarded`. So `module` is
     * silently DROPPED from a `create()` array and the column default applies:
     * `permissions.module` is `->default('Base')`
     * (`2023_12_08_100022_laratrust_setup_tables.php:36`).
     *
     * CORRECTED AFTER REVIEW: an earlier draft cited line 23 and called the column `nullable()`.
     * Line 23 is `roles.module`, which IS nullable; `permissions.module` at line 36 is NOT NULL.
     * The half this test depends on -- the `'Base'` default -- was right either way, which is
     * precisely why the wrong citation survived being written twice.
     *
     * That cost a vacuous test. `Permission::create([... 'module' => 'NotAModule'])` stored
     * `module = 'Base'`, so the row this file meant to protect was never in the module it claimed,
     * `where('module','NotAModule')->delete()` matched nothing, and the assertion passed while the
     * handler was still wide open. Dumped the raw row to find it.
     *
     * IT ALSO AMPLIFIES THE DEFECT RATHER THAN SOFTENING IT: every permission ever created without
     * an explicitly assigned module is a `Base` row, so `POST /remove-modules/Base` reaches far
     * more than the seeded core set.
     */
    private function permissionForModule(string $name, string $module): Permission
    {
        $permission = Permission::create([
            'name' => $name,
            'display_name' => $name,
            'description' => 'fixture',
        ]);

        // Direct assignment, not mass assignment: $fillable governs only the latter.
        $permission->module = $module;
        $permission->save();

        $this->assertSame($module, $permission->fresh()->getAttribute('module'),
            'premise: the fixture row must really belong to ' . $module);

        return $permission;
    }

    /**
     * AN OPERATOR WHO CAN ACTUALLY REACH THE HANDLER, which `makePlatformAdmin()` alone cannot.
     *
     * THIS IS WHY THE FIRST VERSION OF THIS FILE PASSED AGAINST THE UNFIXED CODE AND PROVED
     * NOTHING. `remove()` gates on `auth()->user()->isAbleTo('Module remove')`, and CLAUDE.md
     * records the fact that makes that trap: Laratrust's `permissions_as_gates` is false and THERE
     * IS NO SUPER-ADMIN BYPASS, so `isAbleTo()` alone REFUSES the platform operator. Measured here:
     * a `type = 'super admin'` user with no Laratrust role gets `isAbleTo('Module remove') = false`
     * and the handler answers 'Permission denied.' — so every destructive line was unreachable and
     * my assertions were satisfied by the gate rather than by any fix.
     *
     * Production reaches it because `Utility.php`'s `$superadminNewPermission` grants
     * 'Module remove' to the seeded `super admin` ROLE (`$superadminRole->givePermission($op)`).
     * This mirrors that with the same Laratrust API, and with the role attached the probe measured
     * `isAbleTo = true`, 'Module delete successfully!', and the core permission gone.
     */
    private function operatorWhoMayRemoveModules(): \App\Models\User
    {
        $operator = $this->makePlatformAdmin();

        $this->permissionForModule('Module remove', 'ModuleAdmin');

        $role = Role::create([
            'name' => 'super admin',
            'display_name' => 'Super Admin',
            'description' => 'the seeded operator role',
            'created_by' => $operator->id,
        ]);

        $role->givePermission('Module remove');
        $operator->addRole($role);
        $operator->refresh();

        $this->assertTrue(
            $operator->isAbleTo('Module remove'),
            'premise: this operator must really hold the permission, or the test proves only that '
            . 'the gate refused it.'
        );

        return $operator;
    }

    /**
     * THE DEFECT. `Base` is not a module, so removing it must change nothing.
     */
    #[Test]
    public function test_removing_the_core_permission_set_as_if_it_were_a_module_is_refused(): void
    {
        [$permission, $role] = $this->coreGrant();

        $this->assertSame(1, $this->grantsFor($permission->id), 'premise: the role holds the grant');

        $operator = $this->operatorWhoMayRemoveModules();

        $this->actingAs($operator)->post('/remove-modules/Base');

        $this->assertNotNull(
            Permission::withoutGlobalScopes()->find($permission->id),
            'POST /remove-modules/Base deleted a core permission row. `Base` is not a module -- '
            . 'packages/workdo/Base does not exist -- it is the module value helper.php:1751 reads '
            . 'to render the entire role-permission UI.'
        );

        $this->assertSame(
            1,
            $this->grantsFor($permission->id),
            "The FK cascade on permission_role stripped a tenant role's grant. One request removed "
            . 'authorization from every role on the platform that held a core permission.'
        );

        $this->assertNotNull(Role::withoutGlobalScopes()->find($role->id), 'the role itself must survive');
    }

    /**
     * An identifier that names no module must change nothing either -- the same guard, a different
     * input, so a fix that special-cases the string "Base" does not pass.
     */
    #[Test]
    public function test_removing_an_unknown_identifier_is_refused_and_deletes_nothing(): void
    {
        $permission = $this->permissionForModule('Manage Thing', 'NotAModule');

        $operator = $this->operatorWhoMayRemoveModules();

        $this->actingAs($operator)->post('/remove-modules/NotAModule');

        $this->assertNotNull(
            Permission::withoutGlobalScopes()->find($permission->id),
            'An identifier naming no installed module still reached the mass delete.'
        );
    }

    /**
     * WHY THE TRAVERSAL AND ACCEPT CASES ARE **NOT** DRIVEN THROUGH THE ROUTE HERE.
     *
     * I wrote both as route tests first and stopped before running them, because against the
     * UNFIXED handler each one destroys the checkout:
     *
     *   POST /remove-modules/..   -> deleteDirectory('packages/workdo/..'). That relative path
     *                                resolves from the test runner's working directory, which is
     *                                the repository root, so `is_dir` is TRUE -- the target is
     *                                `packages/`. The recursion would delete all 70 vendored
     *                                modules and then rmdir `packages/` itself.
     *   POST /remove-modules/Api  -> deleteDirectory('packages/workdo/Api') on a REAL installed
     *                                module, which CI's `guard-no-workdo-changes` would then
     *                                report as a modified vendor tree.
     *
     * The two refusal cases above are safe to drive unfixed precisely because `packages/workdo/Base`
     * and `packages/workdo/NotAModule` do not exist, so `deleteDirectory` returns false at its
     * `is_dir` check and only the database half executes -- which is the half they assert.
     *
     * Traversal rejection, symlink rejection, canonical confinement and the accept path are proved
     * in `tests/Unit/Modules/RemovableModuleTest.php` against DISPOSABLE fixture directories, which
     * is the only honest way to test a recursive delete.
     */
    #[Test]
    public function test_the_resolver_accepts_a_real_module_so_the_fix_is_not_refuse_everything(): void
    {
        $this->assertDirectoryExists(base_path('packages/workdo/Api'),
            'premise: Api is a really installed module in this checkout');

        /*
         * The CONTROL against an over-broad fix, asserted at the resolver rather than the route,
         * because driving the route for an accepted module deletes that module.
         */
        $module = \App\Support\Modules\RemovableModule::resolve('Api');

        $this->assertSame('Api', $module->name);
        $this->assertSame(
            realpath(base_path('packages/workdo/Api')),
            $module->directory,
            'An accepted module must resolve to its own canonical directory.'
        );
    }
}

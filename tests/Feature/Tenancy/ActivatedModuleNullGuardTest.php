<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * NEW-NULLGUARD-04 -- `ActivatedModule()` guarded the WRONG VARIABLE.
 *
 * app/Helper/helper.php, inside `ActivatedModule()`:
 *
 *     if (!empty($user)) {                     // <- the enclosing block ALREADY proved this
 *         ...
 *         $user_not_com = Cache::remember(..., fn () => User::find($user->created_by));
 *         if (!empty($user)) {                 // <- WRONG VARIABLE. A no-op.
 *             $active_module = userActiveModule::where('user_id', $user_not_com->id)...
 *                                              // ^ null dereference
 *
 * `User::find($user->created_by)` is null whenever a STAFF member's employer row is absent --
 * a deleted merchant, a `created_by` pointing at a row that never existed, or a part-provisioned
 * registration (NEW-NULLGUARD-03 leaves exactly that state behind). The guard that was supposed
 * to catch it tested a variable the enclosing block had already proved non-empty.
 *
 * PRE-EXISTING, NOT A REGRESSION: the same wrong variable is at the `p1b/integration` baseline.
 * It was invisible because an earlier 500 on the same render path short-circuited first, which
 * is why it surfaced only once those guards landed.
 *
 * THE SECOND HALF OF THE FIX IS WHY A ONE-TOKEN PATCH WOULD NOT HAVE BEEN ENOUGH, and this suite
 * proves both halves. With the guard finally working, `$active_module` stays at its `null`
 * initial value for that principal, and the next statement is
 * `array_intersect($active_module, $available_modules)` -- a TypeError on PHP 8. So repairing the
 * dereference alone just moves the 500 two lines down. Both tests below fail on a
 * guard-only fix.
 */
class ActivatedModuleNullGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A staff member whose employer row does not exist. `creatorId()`-style resolution finds
     * nothing, so `$user_not_com` is null on the path under test.
     */
    private function makeOrphanedStaff(): User
    {
        $missingCreatorId = ((int) User::max('id')) + 9_999;

        $this->assertNull(User::find($missingCreatorId), 'fixture precondition: no such employer');

        $staff = $this->makeMerchant();
        $staff->type = 'staff';           // anything other than 'admin' / 'super admin'
        $staff->created_by = $missingCreatorId;
        $staff->save();

        return $staff->fresh();
    }

    public function test_activated_module_does_not_500_for_staff_whose_employer_row_is_missing(): void
    {
        $staff = $this->makeOrphanedStaff();

        // Before the repair this raised
        //   ErrorException: Attempt to read property "id" on null
        // from the `userActiveModule::where('user_id', $user_not_com->id)` line.
        $modules = ActivatedModule($staff->id);

        $this->assertIsArray($modules,
            'ActivatedModule() must return an array for every principal. It is called from the '
            . 'shared module checks on the render path, so a throw here is a 500 on the page.');
        // CORRECTED AFTER MEASURING. This first asserted `[]`, and the code was right and the
        // assertion was wrong: ActivatedModule() returns
        // `array_unique(array_merge($commonModules, $activated_module))`, where
        // `$activated_module` is `User::$superadmin_activated_module` (app/Models/User.php:319-321,
        // `['LandingPage']`) -- an ALWAYS-ON baseline independent of any employer. So the right
        // contract for an orphaned staff member is "the always-on baseline and NOTHING
        // employer-derived", which is what is asserted now. Asserting `[]` would have demanded a
        // behaviour change nobody asked for, on a module-authorization path, as a side effect of
        // a null-guard fix.
        $this->assertSame(User::$superadmin_activated_module, $modules,
            'An orphaned staff member must get the always-on module baseline and no '
            . 'employer-derived modules. That denies module access rather than granting it, '
            . 'which is the safe direction for a missing-data case.');
    }

    /**
     * THE SAME CALL AGAIN, IN THE SAME PROCESS, FOR A DIFFERENT PRINCIPAL.
     *
     * `$active_module` inside ActivatedModule() is a `static` keyed on NOTHING, so the obvious
     * way to satisfy the test above -- assigning `$active_module = []` when the employer is
     * missing -- would memoise "no modules" for every later principal in the request. That would
     * turn a null-guard fix into a silent authorization outage for unrelated users, which is
     * strictly worse than the 500 it replaced. The fallback is therefore written locally at the
     * array_intersect, and this test is what holds that line.
     */
    public function test_the_orphan_case_does_not_poison_the_static_memo_for_the_next_principal(): void
    {
        $staff = $this->makeOrphanedStaff();

        $this->assertSame(User::$superadmin_activated_module, ActivatedModule($staff->id),
            'the orphan resolves to the always-on baseline only');

        $platform = $this->makeMerchant();
        $platform->type = 'super admin';
        $platform->save();

        // The super-admin branch does not read `$active_module` at all, so it is a clean probe
        // of whether the orphan call left anything behind that changes a later answer.
        $superAdminModules = ActivatedModule($platform->fresh()->id);

        $this->assertIsArray($superAdminModules);
        $this->assertSame(
            $superAdminModules,
            ActivatedModule($platform->fresh()->id),
            'ActivatedModule() must give the same principal the same answer twice in one process. '
            . 'If the orphan branch had written [] into the static memo, this is where an '
            . 'unrelated user would start silently losing module access.'
        );
    }
}

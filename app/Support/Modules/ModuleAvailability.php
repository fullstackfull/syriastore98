<?php

namespace App\Support\Modules;

use App\Models\AddOnManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * IS THIS APPLICATION SWITCHED OFF? One answer, for the one flag that decides it.
 *
 * MEASURED DEFECT this exists to close: `PackageServiceProvider.php:47` reads
 * `AddOnManager::pluck('module')` with NO `is_enable` predicate and feeds it to `loadAddons()`, which
 * registers each module's PSR-4 prefix and service provider. So disabling an application removed
 * nothing -- its code stayed loaded, its providers stayed registered and its routes stayed mounted.
 * The only thing that consulted the flag was the per-call `module_is_active()` helper, wherever a
 * developer remembered to call it.
 *
 * It is a DIVERGENCE INSIDE THIS CODEBASE rather than a stated design: `app/Classes/Module.php:114`
 * (`AddOnManager::where('is_enable', 1)->pluck('module')`) and `:141` both filter on the same flag.
 *
 * WHAT IT IS NOT. `add_on_managers.is_enable` is a SINGLE GLOBAL ROW PER MODULE -- `ModuleController`
 * records that at its own `:60`. This answers "has the operator switched this application off for the
 * whole platform", not "is this merchant entitled to it": per-merchant entitlement is
 * `user_active_modules`, a different table and a different question.
 *
 * AN ABSENT ROW IS NOT A DISABLED APPLICATION, and that is the most important line in this class.
 * It means "not managed through the add-on manager". Reading it as "disabled" would be the same
 * mistake the account-suspension predicate nearly shipped -- and here it has a concrete victim:
 * `PackageServiceProvider:49-51` force-adds `LandingPage` to the loaded set whether or not it has a
 * row, and `LandingPage` owns 275 routes, MORE THAN ANY OTHER MODULE, including the public landing
 * pages. A guard that refused on a missing row would take those down.
 */
final class ModuleAvailability
{
    /**
     * True only when a row EXISTS for `$module` and says it is switched off.
     *
     * Fails OPEN by construction, in three distinct ways, each for a stated reason:
     *
     *  - no `add_on_managers` table (a fresh checkout before `migrate`): there is nothing to read, so
     *    nothing is disabled. `PackageServiceProvider:44` guards the same way for the same reason.
     *  - no row for the module: see the docblock above.
     *  - any throwable while reading: a guard that cannot reach its own flag must not refuse the
     *    whole application surface. This is a BOUNDED availability control, not a secret, and the
     *    thing it protects against is an operator-switched-off module still answering -- not an
     *    attacker. Breaking every module route because a cache driver hiccuped would be the worse
     *    failure.
     */
    public static function isDisabled(?string $module): bool
    {
        if ($module === null || $module === '') {
            return false;
        }

        try {
            if (! Schema::hasTable('add_on_managers')) {
                return false;
            }

            $flag = AddOnManager::where('module', $module)->value('is_enable');

            /*
             * `null` MEANS "NO ROW", AND HERE IT CAN MEAN NOTHING ELSE. Measured against the booted
             * schema: `add_on_managers.is_enable` is `$table->boolean('is_enable')->default(0)` --
             * `tinyint(1)`, NOT NULL, default 0. So a stored row always carries 0 or 1 and `value()`
             * returns null only when the row is absent. That is exactly the distinction this guard
             * needs, and it is why the check is `!== null` rather than a truthiness test.
             *
             * WORTH KNOWING, THOUGH IT CHANGES NOTHING HERE: the column's default is **0**, so a row
             * inserted without an explicit flag is DISABLED. A freshly installed module is switched
             * off until somebody enables it, which is the install screen's own flow.
             *
             * THE COMPARISON IS LOOSE, AND A MUTATION PROVED THE SUITE CANNOT TELL. Making it `=== 0`
             * survived every test, because on SQLite the driver returns an INTEGER whichever way the
             * value was written -- measured for all four of `0`, `'0'`, `1`, `'1'`. An earlier draft
             * of this comment claimed the codebase writes the flag as both an int and a string; that
             * was not verified and is not true of what comes back out.
             *
             * It stays loose for a reason the suite cannot reach: production is MySQL, and PDO's
             * MySQL driver can return a `tinyint(1)` as the STRING `"0"` depending on how prepared
             * statements are emulated. `=== 0` would then read a disabled module as enabled -- a
             * silent failure of the control, in the permissive direction. CLAUDE.md records that
             * SQLite's type affinity hiding a real MySQL defect is how P01-SEC-13 survived, so the
             * strict form is not adopted merely because the suite cannot distinguish it.
             */
            return $flag !== null && $flag == 0;
        } catch (\Throwable $failure) {
            /*
             * FAIL OPEN, BUT NEVER SILENTLY. Found by independent review: this swallowed the
             * throwable with no log at all, while its sibling in `PackageServiceProvider` logs
             * `critical` for the same class of failure -- and this project's own P1-PLT-04 lesson
             * was that a broken install did not fail, it DISAPPEARED. An availability control that
             * stops controlling is exactly that shape, so it now says so.
             *
             * The direction is still open, deliberately: a database that cannot be read must not
             * make every module's routes refuse.
             */
            Log::warning('ModuleAvailability could not read the enable flag; treating the module as available.', [
                'module' => $module,
                'exception' => $failure->getMessage(),
            ]);

            return false;
        }
    }
}

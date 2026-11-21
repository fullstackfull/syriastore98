<?php

namespace App\Services;

use App\Models\User;

/**
 * WHO MAY READ A REPORT. One predicate, so a link cannot disagree with the gate behind it.
 * =================================================================================================
 *
 * `App\Http\Middleware\ReportPermission` has enforced this since P1-SEC-09, and its ordering is the
 * ordering this codebase requires everywhere: the platform OPERATOR first (Laratrust has no
 * super-admin bypass, so `isAbleTo()` alone refuses the one principal who reads every store), then
 * the tenant OWNER (`creatorId() === id`, because a merchant whose seeded `admin` role predates a
 * permission name would otherwise be locked out of their own reports), then the named permission.
 *
 * WHY IT MOVED OUT OF THE MIDDLEWARE. MKT-001 — "an independent marketing section, linked to
 * analytics" — needs a VIEW to ask the same question the middleware will answer, so the marketing
 * screens can offer a link to the analytics centre without offering it to someone who would be
 * refused on arrival. A copy of the three checks in a Blade would be a second authorization rule
 * that drifts from the first, which this project has already paid for: `OtpIntegrationController`
 * records, in its own docblock, that not delegating to `StoreSettingsAccess::permits()` was a
 * mistake. So the predicate is extracted verbatim and the middleware now calls it.
 *
 * THIS IS A REFACTOR, NOT A RELAXATION. The three checks, their order and their exact comparisons
 * are unchanged — `strtolower(trim(...)) === 'super admin'`, `(int) creatorId() === (int) id`, then
 * `isAbleTo($permission)`. The middleware keeps its `ACTIONS` map, its unmapped-action default of
 * `Manage Reports` and its refusal shape (redirect, or 403 for a JSON/ajax caller, NEVER a 401,
 * because the shipped Flutter app force-logs-out on any 401). `ReportAuthorizationTest` is the gate
 * on all of that and was run against this change.
 *
 * A NULL USER IS REFUSED, which is the only thing this class decides that the middleware did not
 * state as its own line: the middleware's `if ($user !== null)` fell through to the refusal, and
 * returning false here is the same answer written as one.
 */
final class ReportAccess
{
    /** The permission the reports group and the analytics centre are both held to. */
    public const REPORTS = 'Manage Reports';

    /**
     * May this principal read a report held to `$permission`?
     *
     * @param  string  $permission  a Laratrust permission NAME. It must be one that
     *                              `Utility::addNewData()` really seeds: a name absent from there
     *                              cannot exist as a row, so `isAbleTo()` would answer false for
     *                              every staff member while the operator and the owner still pass,
     *                              which looks like a working gate and is not one.
     */
    public static function permits(?User $user, string $permission = self::REPORTS): bool
    {
        if ($user === null) {
            return false;
        }

        if (strtolower(trim((string) $user->type)) === 'super admin') {
            return true;
        }

        if ((int) $user->creatorId() === (int) $user->id) {
            return true;
        }

        return $user->isAbleTo($permission);
    }
}

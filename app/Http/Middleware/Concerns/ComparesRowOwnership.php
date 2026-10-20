<?php

namespace App\Http\Middleware\Concerns;

use App\Models\Permission;
use App\Policies\OrderPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ownership predicate shared by the `web`-group guards over vendored module routes -- five of
 * them now: ModuleOrderMutationGuard, ModuleResourceOwnershipGuard, LotteryDrawOwnershipGuard,
 * LotteryRefundOwnershipGuard and LotterySettingProductOwnershipGuard. (This line said "the two"
 * until the count was checked.)
 *
 * WHY THIS EXISTS AT ALL. `ModuleOrderMutationGuard`'s own docblock argues that "a guard whose
 * mechanism changes per table is the drift this guard exists to prevent" -- and then
 * P1-INV-17 needed a SECOND guard, because the resource routes it covers differ in two ways that
 * cannot be folded into the first: their id comes from the route parameter and must NOT be read
 * from the body (the handlers take `$id` as an argument), and one uri carries three methods with
 * different permissions. Two guards, each honest about what its own routes do, is right. Two
 * COPIES of the ownership predicate is not: the first guard shipped with a false "permission is
 * not seeded" premise and a `resolveId()` that fell through to the route segment, and both were
 * found by review rather than by a test. A copy would have inherited whichever version was
 * current on the day it was written.
 *
 * So the predicate lives here once. Every change to it changes both guards, and both guards'
 * suites run against it.
 *
 * WHAT IS NOT HERE. Id resolution is deliberately each guard's own, because "which request key
 * does the handler read" is the one question that genuinely differs per route, and it is the
 * question a wrong answer to turns a guard into a thing that authorises one row while the handler
 * mutates another.
 */
trait ComparesRowOwnership
{
    /**
     * The platform operator, checked FIRST wherever this trait is used.
     *
     * Not an aesthetic choice: on a database built from source no role holds most permissions, so
     * `isAbleTo()` ahead of this would lock the operator out entirely. `OrderPolicy::allow`
     * records the same ordering and the reason it was corrected there.
     *
     * Normalised, which `OrderPolicy::isPlatformAdmin` is not -- it compares the raw string. The
     * normalised form is what CLAUDE.md prescribes and is the stricter-input reading; no row with
     * a differently-cased `type` is written anywhere in the application, so the two agree on every
     * real principal.
     */
    protected function isPlatformOperator(?User $user): bool
    {
        return $user !== null
            && strtolower(trim((string) $user->type)) === OrderPolicy::PLATFORM_ADMIN;
    }

    /**
     * Is this permission one we may actually require?
     *
     * ONLY WHEN A `permissions` ROW OF THAT NAME EXISTS, and that condition is a lockout guard
     * rather than a loophole. A permission with no row can be held by no role, so a hard gate on a
     * missing name is a PERMANENT 403 for every principal including the operator -- the lockout
     * `App\Http\Middleware\FinancePermission`'s docblock records having been caused once already
     * by six unseeded finance names. A missing row is a real state here: each vendored module
     * seeds its own permissions from its own `PermissionTableSeeder`, which the enable path runs
     * via `ModuleController`'s `Artisan::call('package:seed')`, so an install whose module was
     * enabled before that seeder existed has the routes and not the permissions.
     *
     * It cannot be turned into a bypass. Deleting a `permissions` row needs platform access the
     * attacker would already have to hold, and with the row gone `storeMatches()` still refuses
     * every other tenant's row. The degraded state is "authenticated and same-store", never
     * "open", and both guards' suites assert that in both directions.
     */
    protected function permissionIsEnforceable(?string $permission): bool
    {
        if ($permission === null) {
            return false;
        }

        try {
            return Permission::where('name', $permission)->exists();
        } catch (\Throwable $e) {
            // Fail TOWARDS the ownership limb, not past it: this method's answer only decides
            // whether the PERMISSION is checked. Ownership is enforced regardless.
            return false;
        }
    }

    /**
     * Fetch the row's `store_id`, or a sentinel saying why it could not be compared.
     *
     * @return array{0: string, 1: int|null} ['ok'|'unreadable table'|'no row', store_id|null]
     */
    protected function readRowStore(string $table, int $id): array
    {
        /*
         * FAIL CLOSED ON A BROKEN READ, NEVER OPEN. A module's table exists whenever its routes
         * do -- the same module registers both -- but "in practice" is not a control, and this
         * branch was found for real: module migrations do not run until the module is enabled, so
         * the auction tables are absent from a test schema and from any install whose migration
         * step failed. A missing table must DENY rather than raise a QueryException into the
         * exception handler, which would then reach the unguarded handler by whatever route that
         * handler takes.
         */
        try {
            $row = DB::table($table)->where('id', $id)->first(['store_id']);
        } catch (\Throwable $e) {
            return ['unreadable table', null];
        }

        if ($row === null) {
            return ['no row', null];
        }

        return ['ok', $row->store_id === null ? null : (int) $row->store_id];
    }

    /**
     * Does this row belong to the caller's tenant?
     *
     * PASS THE USER ID EXPLICITLY. `getCurrentStore()` with no argument reads `auth()->user()` on
     * the MUTABLE DEFAULT guard (app/Helper/helper.php:392). If the default has been pointed at
     * `customers`, a no-argument call resolves a Customer, returns null, and this fails closed for
     * a merchant who is in fact entitled -- or worse, resolves the `User` whose id happens to
     * equal the customer's. Passing the id makes the lookup independent of the default guard and
     * resolves the SAME principal the authorization was taken from.
     *
     * Fails closed on a missing store on EITHER side. A row with a NULL `store_id` is invisible
     * to every `where('store_id', ...)` predicate in the application and must not become
     * reachable here.
     */
    protected function storeMatches(User $user, ?int $rowStore): bool
    {
        $currentStore = getCurrentStore($user->id);

        if (empty($currentStore) || empty($rowStore)) {
            return false;
        }

        return (int) $rowStore === (int) $currentStore;
    }

    /**
     * Would the handler's own `whereIn` touch any row outside the caller's store?
     *
     * A SET PREDICATE, because not every vendored handler names one row. P1-INV-18's
     * `LotteryResultController::store()` takes a comma-separated list of ticket CODES and resolves
     * them with `PurchaseTicket::whereIn('lottery_code', $codes)` -- no id, no route parameter, and
     * no store predicate. The guards' single-row shape cannot express that, so this does.
     *
     * =======================================================================================
     *  THE PREDICATE ASKS ABOUT THE ROWS, NOT ABOUT THE VALUES. THE FIRST VERSION ASKED THE
     *  WRONG QUESTION AND WAS BYPASSABLE ON MySQL.
     * =======================================================================================
     *
     * It originally ran `whereIn($column, $values)->where('store_id', $currentStore)->pluck()` and
     * compared that against the requested values with `array_diff` -- i.e. it asked *"does every
     * value I was given resolve to a row I own?"*. That is not the same question as *"is every row
     * the handler will touch mine?"*, and MySQL's default collation separates them. MEASURED on
     * MySQL 8.0.46, database collation `utf8mb4_0900_ai_ci`:
     *
     *   rows: (1, 'ABC-1', store 1)   (2, 'abc-1', store 2)
     *   guard   WHERE lottery_code IN ('ABC-1') AND store_id = 1  -> row 1 only   => ALLOW
     *   handler WHERE lottery_code IN ('ABC-1')                   -> rows 1 AND 2 => draws store 2's
     *
     * So a caller owning `ABC-1` could draw a victim's `abc-1`: the guard verified the value and
     * the handler matched a wider set. `ai_ci` is accent- and case-insensitive, so the same holds
     * for accent variants. **SQLite's BINARY default hides it completely** -- the same engine-hiding
     * class as the `'5abc'` coercion that `ModuleOrderMutationGuard` shipped with, and the reason
     * the proof is tests/Support/Security/bin/verify-lottery-code-collation-on-mysql.php rather
     * than a testcase.
     *
     * The predicate is now the engine's own question: run the handler's `whereIn` WITHOUT a store
     * filter and refuse if any row it matches is outside the caller's store. Whatever the
     * collation does, the guard and the handler see the same row set.
     *
     * THE VALUE CHECK IS KEPT AS A SECOND, STRICTER LIMB, and it only ever denies more: every
     * requested value must also be accounted for by a row the caller owns. That closes a separate
     * defect for free -- these handlers dereference the first result of their own lookup with no
     * null guard, so an unmatched value is a 500 rather than a refusal -- and where collation makes
     * PHP's case-sensitive `array_diff` disagree with the database, it disagrees in the direction of
     * refusing.
     *
     * ALL, NOT ANY, AND THE WHOLE CALL IS REFUSED RATHER THAN THE LIST FILTERED. Filtering would be
     * worse than refusing: the handler re-reads the raw input itself, so a guard that silently
     * narrowed the list would authorise a subset while the handler acted on the whole of it.
     *
     * Returns false on an empty set: nothing to authorise means nothing to allow.
     */
    protected function noMatchedRowOutsideCallerStore(
        string $table,
        string $column,
        array $values,
        User $user
    ): bool {
        return $this->noMatchedRowOutsideStore($table, $column, $values, getCurrentStore($user->id));
    }

    /**
     * The same predicate against an EXPLICIT store id.
     *
     * The wrapper above resolves the store from a `User`, which is right for a merchant-facing
     * guard. The storefront has neither: its caller is a `Customer` on the `customers` guard and
     * the relevant store is the one the URL slug resolves to. `LotteryRefundOwnershipGuard` needs
     * exactly this predicate against that store.
     *
     * It is extracted rather than copied because copying is the specific failure this trait exists
     * to prevent: `ModuleOrderMutationGuard` and `ModuleResourceOwnershipGuard` each shipped a
     * separately-written version of this comparison, and two of the defects in them were found by
     * review rather than by test. One predicate, one place, one set of removal checks.
     */
    protected function noMatchedRowOutsideStore(
        string $table,
        string $column,
        array $values,
        mixed $currentStore
    ): bool {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $values),
            static fn ($v) => $v !== ''
        )));

        if ($values === []) {
            return false;
        }

        // A bounded list, so the guard itself cannot be made the expensive part of the request.
        // The handler's own per-item `sleep(1)` makes a long list costly regardless; this caps
        // what the guard will even consider.
        if (count($values) > 500) {
            return false;
        }

        if (empty($currentStore)) {
            return false;
        }

        try {
            // LIMB 1, the collation-safe core: the handler's own query, and nothing it matches may
            // lie outside the caller's store. A NULL store_id counts as outside -- such a row is
            // invisible to every store_id predicate in the application and must not become
            // reachable here.
            $reachesForeignRow = DB::table($table)
                ->whereIn($column, $values)
                ->where(function ($query) use ($currentStore) {
                    $query->whereNull('store_id')
                        ->orWhere('store_id', '!=', $currentStore);
                })
                ->exists();

            if ($reachesForeignRow) {
                return false;
            }

            // LIMB 2, stricter and deliberately case-sensitive: every requested value must be
            // accounted for by a row the caller owns.
            $owned = DB::table($table)
                ->whereIn($column, $values)
                ->where('store_id', $currentStore)
                ->pluck($column)
                ->map(static fn ($v) => trim((string) $v))
                ->all();
        } catch (\Throwable $e) {
            // Fail closed, for the reason readRowStore() records: a module's table exists when its
            // routes do, but "in practice" is not a control.
            return false;
        }

        return array_diff($values, $owned) === [];
    }

    /**
     * A row id from a value, or null when it is not usable as one.
     *
     * STRICT, AND `is_numeric` IS NOT GOOD ENOUGH -- measured, not argued. `is_numeric` accepts
     * `'5.9'`, `' 5'` and `'5e0'`, and rejects `'5abc'`, while MySQL's implicit coercion in a
     * `WHERE id = ?` comparison accepts `'5abc'` and `'5e0'` (both resolving to row 5) and
     * rejects `'5.9'`. A guard and its handler disagreeing about which row a value denotes is
     * exactly the bug that makes a guard worthless: `ModuleOrderMutationGuard` shipped with it,
     * and `'5abc'` authorised the caller's own order while the handler mutated another store's.
     * Proven on MySQL 8.0.46 by
     * tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php.
     *
     * `filter_var` admits exactly the values that denote the row the guard checked.
     */
    protected function usableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $id = filter_var((string) $value, FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }
}

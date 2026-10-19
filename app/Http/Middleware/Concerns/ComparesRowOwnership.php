<?php

namespace App\Http\Middleware\Concerns;

use App\Models\Permission;
use App\Policies\OrderPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ownership predicate shared by the two `web`-group guards over vendored module routes.
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

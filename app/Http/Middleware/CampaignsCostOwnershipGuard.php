<?php

namespace App\Http\Middleware;

use App\Policies\UserPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-86 — EVERY `cost_setting` ROW A REQUEST NAMES MUST BELONG TO THE CALLER.
 * =================================================================================================
 *
 * `Workdo\Campaigns\...\CampaignsController::campaignsCost()` (vendored, not edited) has two
 * unscoped operations over caller-supplied ids:
 *
 *     CostSetting::whereIn('id', $deletedIds)->delete();        // no ownership predicate
 *
 *     $costSetting['created_by'] = $creatorId;                  // <- assigned BEFORE the update
 *     $existing = CostSetting::find($costSetting['id']);
 *     if ($existing) { $existing->update($costSetting); }       // no ownership predicate
 *
 * The second is a TRANSFER, not an edit: `created_by` is set from the caller first, so an unscoped
 * update hands the row to whoever asked.
 *
 * WHY A BESPOKE GUARD AND NOT A MANIFEST RULE THAT ALREADY EXISTS. `store-row:<table>:<param>`
 * reads a ROUTE parameter and compares `store_id`; both halves are wrong here, because the ids are
 * in the BODY (`deleted_ids`, `store_group[*][id]`) and `cost_setting` HAS NO `store_id` -- it is
 * `min`, `max`, `price`, `created_by`, `timestamps`. And `operator` -- the rule the sibling
 * `campaigns-system-setup` carries, which is what first came to mind -- would be wrong in the
 * other direction: these are per-principal pricing tiers keyed on `created_by` alone, so an
 * operator-only rule would take every merchant's own tiers away from them.
 *
 * `merchantTenantRoot()` AND NOT `creatorId()`: for a merchant owner and for their staff the two
 * agree exactly, which is what makes this a drop-in for the handler's own notion of ownership; for
 * a principal rooted at the OPERATOR -- staff the operator created, or a merchant who changed
 * their own `type` -- `creatorId()` is the operator's id and this is null, so the request is
 * refused rather than answered against the operator's tiers. Same fail-closed choice as
 * `tenant-store:` and the same reasoning as P1-SEC-83.
 *
 * ===========================================================================================
 *  THE TWO ID SOURCES ARE READ DIFFERENTLY, BECAUSE THE HANDLER READS THEM DIFFERENTLY
 * ===========================================================================================
 *
 * Mirroring is only safe when it is exact -- the lesson of this guard's sibling, where a first
 * version asked `input()` while the handler asked `__get`/`all()`, and one multipart part got
 * past the entire check. Both reads here are `$request->input(...)`, so this class uses `input()`
 * too and a file part cannot make the two disagree in either direction.
 *
 * `deleted_ids` is `explode(',', ...)` then `filter_var($id, FILTER_VALIDATE_INT) !== false`, and
 * the handler DROPS what fails. So this collects exactly what the handler keeps and ignores the
 * rest: an unparseable entry there never reaches a query, and refusing the request over one would
 * break a legitimate list with a trailing comma, which the UI really does send.
 *
 * `store_group[*][id]` is passed to `find()` RAW. `config/database.php:55` sets `'strict' => false`,
 * so on production MySQL `find('7abc')` compares a string to an integer column and matches row 7.
 * This guard does NOT try to reproduce the engine's coercion -- it REFUSES anything it cannot
 * parse canonically, which is the fix the sibling guard needed after review found that exact
 * bypass, and which was visible only on MySQL. A missing or falsy id is a CREATE and is allowed:
 * the handler's own test is `isset($costSetting['id']) && $costSetting['id']`.
 *
 * ALL OR NOTHING. One unowned, unparseable or non-existent id refuses the WHOLE request rather
 * than the offending row, because the handler is not transactional: it would have applied the
 * legitimate rows before reaching the bad one, and a partial write is the state this exists to
 * prevent. A row that does not exist is not a row the caller owns.
 */
final class CampaignsCostOwnershipGuard
{
    /** Read by the route census, so a module update that renames the route is visible. */
    public const TARGETS = ['campaigns/campaigns-cost'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! in_array($route->uri(), self::TARGETS, true)) {
            return $next($request);
        }

        // EXPLICIT GUARD NAME, never auth()->user(): CustomAuth logs a token's owner into whichever
        // of the three guards matches its class, so the default guard can be `customers`.
        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request);
        }

        if (UserPolicy::isOperator($user)) {
            return $next($request);
        }

        $tenant = UserPolicy::merchantTenantRoot($user);

        if ($tenant === null) {
            return $this->deny($request);
        }

        $ids = $this->namedIds($request);

        if ($ids === null) {
            return $this->deny($request);
        }

        if ($ids === []) {
            return $next($request);
        }

        $owned = DB::table('cost_setting')
            ->whereIn('id', $ids)
            ->where('created_by', $tenant)
            ->count();

        return $owned === count($ids) ? $next($request) : $this->deny($request);
    }

    /**
     * Every `cost_setting` id this request will make the handler touch.
     *
     * @return list<int>|null  null means "refuse": a shape this class will not vouch for.
     */
    private function namedIds(Request $request): ?array
    {
        $ids = [];

        // ---- the delete list: mirror the handler's own filter, including what it drops ---------
        $deleted = $request->input('deleted_ids');

        if ($deleted !== null && ! is_string($deleted) && ! is_int($deleted)) {
            return null;   // the handler's `explode()` would throw on this; refuse rather than 500
        }

        foreach (explode(',', (string) ($deleted ?? '')) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_INT) !== false) {
                $ids[] = (int) $candidate;
            }
        }

        // ---- the update list: an id that is present and not canonical refuses the request ------
        $group = $request->input('store_group');

        if ($group === null || $group === '' || $group === []) {
            return array_values(array_unique($ids));
        }

        if (! is_array($group)) {
            return null;   // the handler's `foreach` would throw; refuse rather than 500
        }

        foreach ($group as $row) {
            if (! is_array($row)) {
                return null;
            }

            // The handler's own test for "this is an update and not a create".
            if (! isset($row['id']) || ! $row['id']) {
                continue;
            }

            $id = $this->canonicalId($row['id']);

            if ($id === null) {
                return null;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /** A positive integer written plainly, or null. Deliberately stricter than the database. */
    private function canonicalId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private function deny(Request $request): Response
    {
        $message = __('Permission denied');

        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message, 'message' => $message], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}

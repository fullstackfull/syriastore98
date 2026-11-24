<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use App\Models\User;
use App\Policies\UserPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-69 (c) — THE CAMPAIGN A MERCHANT CREATES MAY ONLY NAME THEIR OWN STORE AND PRODUCTS.
 * =================================================================================================
 *
 * `Workdo\Campaigns\...\CampaignsController::paymentSuccess` is the gateway-return handler, and it
 * is where a campaign is actually written — `store()` only starts a payment. It reads every field
 * of the row from a blob the CALLER supplies:
 *
 *     $requestData = json_decode(urldecode($request->input('request_data')), true);
 *     if (empty($requestData)) { $requestData = Session::get('campaigns_request_data'); }
 *     ...
 *     $campaignsData->store      = $requestData['store'];
 *     $campaignsData->product    = $requestData['product'];
 *     $campaignsData->created_by = \Auth::user()->creatorId();
 *
 * No ownership test on either. MEASURED through the real route with the module loaded
 * (`CampaignIntentOwnershipTest`, four cases red before this guard): an entitled merchant created a
 * campaign naming ANOTHER TENANT's store and product; naming a foreign product inside their own
 * store worked too; so did naming it in a comma-separated list, and so did naming it in a form
 * `filter_var` rejects but a non-strict MySQL column stores (see `intentIsOwned()`, which records
 * that the list half of that sentence was engine-dependent and the non-canonical half is the live
 * one); and a principal rooted at the operator filed one against the OPERATOR's store, where
 * `created_by = creatorId()` then recorded it as the operator's own. `settingmenuController::campaginSorting` renders approved
 * campaigns on the platform's PUBLIC landing page, so the row is not private.
 *
 * ===========================================================================================
 *  WHAT THIS GUARD DECIDES, AND WHAT IT DELIBERATELY DOES NOT
 * ===========================================================================================
 *
 * IT DECIDES WHOSE STORE AND WHOSE PRODUCTS a campaign may name. That is tenancy, and it needs no
 * policy that does not already exist in this codebase.
 *
 * IT DOES NOT DECIDE THE PRICE OR WHETHER THE PLACEMENT WAS PAID FOR. `total_cost`, `total_days`
 * and `payment_type` come from the same caller-supplied blob, and the handler verifies a payment
 * for Khalti alone. What the platform must charge for a placement, and which gateway returns must
 * be verified before a campaign is written, are the platform's own commercial rules and a
 * per-gateway change in vendored code. Those are reported to the owner and are OUTSIDE this guard
 * by choice, not by oversight. A guard that invented a price would be worse than one that does not.
 *
 * ===========================================================================================
 *  WHY A DEDICATED GUARD RATHER THAN A MANIFEST RULE
 * ===========================================================================================
 *
 * `ModuleAdministrativeRouteGuard` acts on `ModuleRouteManifest::ADMINISTRATIVE`, and these six
 * keys are in `ANONYMOUS_BY_DESIGN` as `payment-callback`, the category for a gateway return.
 *
 * BUT THIS GUARD DOES REFUSE AN ANONYMOUS REQUEST, so that category's usual promise does not hold
 * for these keys and the first version of this paragraph was wrong to imply it did (review,
 * 2026-11-22). It costs nothing: the server-to-server callback urls that point here already
 * dereferenced `Auth::user()->id` on null and answered 500, so no gateway ever completed a write
 * without a session. The classification stays `guarded:` rather than moving to ADMINISTRATIVE
 * because the census forbids a key in both tables and because "any signed-in user" is not the rule
 * this route wants. So the classification becomes
 * `guarded:CampaignIntentOwnershipGuard`, the category the census already understands: it asserts
 * the route really carries the named middleware AND that the middleware has a rule for that exact
 * route, which it reads from `TARGETS` below. A module update that renames the route turns the
 * classification stale and fails the census rather than silently unguarding the write.
 *
 * THE INTENT IS DECODED EXACTLY AS THE HANDLER DECODES IT, fallback included. A guard that
 * authorised one payload while the handler wrote another would be worthless — that is the defect
 * `ComparesRowOwnership` records having shipped once already, and the reason id resolution is
 * written per route rather than shared.
 *
 * EVERY ID IN THE PRODUCT LIST IS CHECKED, in the two limbs that trait documents: nothing the
 * handler's own `explode` would touch may lie outside the caller's store, and every id asked for
 * must be accounted for by a row they own. `campaigns.product` is a comma-separated list —
 * `viewCampaigns()` explodes it — so a check on one id would pass a smuggled second.
 */
final class CampaignIntentOwnershipGuard
{
    use ComparesRowOwnership;

    /**
     * The vendored route uris this guard acts on. READ BY THE CENSUS
     * (`RouteCensusGateTest::guardActsOn`), so it is the one place the route is named.
     */
    public const TARGETS = ['campaigns/campaigns-card-payment'];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! in_array($route->uri(), self::TARGETS, true)) {
            return $next($request);
        }

        /*
         * The handler's own first branch: a cancelled return writes nothing and only redirects.
         *
         * READ EXACTLY AS THE HANDLER READS IT -- CORRECTED AFTER INDEPENDENT REVIEW, WHICH FOUND A
         * BYPASS IN THE FIRST VERSION. That version asked `$request->input('return_type')`. The
         * handler asks `$request->return_type`, which is `Request::__get` -> `all()`, and `all()` is
         * `array_replace_recursive($this->input(), $this->allFiles())`: an UPLOADED FILE named
         * `return_type` REPLACES the input of the same name, while `input()` never sees files at
         * all. So one multipart POST carrying `return_type=cancel` as a field AND a file part of the
         * same name read as "cancel" to the guard -- which skipped its entire ownership check -- and
         * as an UploadedFile to the handler, which took the else branch and WROTE. That bypassed
         * every other control in this class.
         *
         * `Arr::get($request->all(), ...)` is the handler's expression, and `==` is its comparison,
         * loose included: mirroring is only safe if it is exact. The two now cannot disagree in
         * either direction -- a JSON `{"return_type": true}` reads as "cancel" for both, and a
         * file-only part reads as non-cancel for both.
         */
        if (Arr::get($request->all(), 'return_type') == 'cancel') {
            return $next($request);
        }

        // EXPLICIT GUARD NAME, never auth()->user(): CustomAuth logs a token's owner into whichever
        // of the three guards matches its class, so the default guard can be `customers`, and a
        // Customer has no creatorId().
        $user = auth('web')->user();

        if ($user === null) {
            /*
             * No session, no write -- and the handler agrees, by accident: it dereferences
             * `Auth::user()->id` two lines before `save()`, so an anonymous call answers 500 today.
             * Refusing is the same outcome reached deliberately, and it is what holds if that
             * dereference ever moves.
             */
            return $this->deny($request);
        }

        if ($this->isPlatformOperator($user)) {
            return $next($request);
        }

        return $this->intentIsOwned($user, $this->intent($request))
            ? $next($request)
            : $this->deny($request);
    }

    /**
     * The intent the HANDLER will read, decoded the same way, fallback included.
     *
     * @return array<string, mixed>
     */
    private function intent(Request $request): array
    {
        $decoded = json_decode(urldecode((string) $request->input('request_data')), true);

        if (! empty($decoded) && is_array($decoded)) {
            return $decoded;
        }

        // `empty()`, as the handler has it: an unreadable blob falls back to the session copy the
        // module's payment service stored when the caller started the payment.
        if ($request->hasSession()) {
            $session = $request->session()->get('campaigns_request_data');

            if (is_array($session)) {
                return $session;
            }

            if (is_object($session)) {
                return (array) $session;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $intent */
    private function intentIsOwned(User $user, array $intent): bool
    {
        $tenant = UserPolicy::merchantTenantRoot($user);
        $storeId = $this->usableId($intent['store'] ?? null);

        /*
         * A principal with no MERCHANT tenant root has no store of their own, so there is nothing
         * they may name: `creatorId()` would be the operator's id and the row would be filed as the
         * operator's. Fails closed, like every other sink in the P1-SEC-66/67 family.
         *
         * MEASURED REDUNDANT, AND KEPT, AND THE MEASUREMENT IS STATED HONESTLY BECAUSE THREE
         * MUTATIONS OF IT SURVIVE. Removing this early return leaves every case in the suite
         * passing; so does loosening `!==` to `!=` below; so does doing both. The reason is that
         * two further limbs refuse a tenant-less caller anyway -- `(int) $store->created_by` is
         * never identical to null, and limb 2 below requires every product id to be matched by a
         * row whose `created_by` equals the tenant, which `where('created_by', null)` matches for
         * no row. A fixture that defeated all three at once would need a store AND a product
         * carrying `created_by = 0`, which nothing in this application writes.
         *
         * So this branch is defence in depth, not the operative check, and it is kept for being
         * the one place the rule is stated in one line. An earlier version of this comment claimed
         * the redundancy "rests entirely on `!==`"; that was wrong, and the mutation run is what
         * showed it.
         */
        if ($tenant === null || $storeId === null) {
            return false;
        }

        /*
         * ONE CANONICAL ID AND NOTHING ELSE -- CORRECTED AFTER INDEPENDENT REVIEW, AND THE FIRST
         * VERSION WAS BYPASSABLE ON PRODUCTION MySQL WHILE PASSING EVERY TEST.
         *
         * That version split `product` on commas, ran each element through `usableId()`, and
         * silently DROPPED the elements it could not parse -- checking only what was left. Three
         * measured facts turn that into an authorisation bypass:
         *
         *   1. `campaigns.product` is an `integer` column
         *      (`...Campaigns/src/database/migrations/2024_07_23_065837_create_campaigns_table.php:19`),
         *      not a string, although `viewCampaigns()` explodes it as though it were a list.
         *   2. `config/database.php:55` sets `'strict' => false` on the mysql connection, so MySQL
         *      TRUNCATES a non-numeric value instead of refusing it: `'06,5'` is stored as 6.
         *   3. `usableId()` is `filter_var(FILTER_VALIDATE_INT)`, which REJECTS `'06'`, `'6abc'`,
         *      `'6.0'` and `'6e0'` -- verified in this container.
         *
         * So `product = '06,5'` was authorised on the strength of the caller's own id 5, and stored
         * as 6: ANOTHER TENANT'S PRODUCT. The campaign then advertises that product on the caller's
         * list and on the operator's. A sibling shape, `'0abc,5'`, stores 0, which matches no row and
         * made the vendored list cell's unguarded `->products->name` answer 500 for the whole list.
         *
         * This is the exact defect `ComparesRowOwnership`'s own docblock records having shipped once
         * before, in `ModuleOrderMutationGuard`, with `'5abc'`. Writing it a second time is why the
         * rule is now the strict one: the value must be a single canonical id, so that what the guard
         * authorises and what the database stores cannot be two different rows.
         *
         * REFUSING A COMMA LIST IS NOT A REGRESSION. The column is an integer, so a list has never
         * been storable: on MySQL it collapses to the first element, and the module's own create form
         * offers a single-select. An existing row that holds a list was not written through this
         * route, and reading one is handled separately (the list cells are null-safe).
         *
         * IT ALSO CORRECTS A CLAIM THIS CLASS MADE. The docblock said hiding a foreign id in the
         * comma-separated list "worked too". That was measured on SQLite, which keeps `'06,5'` as
         * TEXT; on MySQL the same request stores 6. The claim was engine-dependent and said so
         * nowhere, which is the kind of overclaim this project has already paid for twice.
         */
        $productId = $this->usableId(trim((string) ($intent['product'] ?? '')));

        if ($productId === null) {
            return false;
        }

        try {
            $store = DB::table('stores')->where('id', $storeId)->first(['created_by']);

            if ($store === null || (int) $store->created_by !== $tenant) {
                return false;
            }

            /*
             * ONE QUESTION, ASKED OF THE ROW THAT WILL BE STORED: is there a product with this id,
             * created by this tenant, in this store? An id that matches no row is refused by the
             * same predicate -- which matters beyond ownership, because the vendored list cell does
             * `$campaigns->products->name` with no null guard, so a campaign naming a phantom
             * product answers 500 for the whole list, the operator's included.
             *
             * The two-limb set predicate this replaced was written for a LIST of ids. With a single
             * canonical id the set question and the row question are the same question, and one
             * query that cannot be read two ways is better than two that can.
             */
            return DB::table('products')
                ->where('id', $productId)
                ->where('created_by', $tenant)
                ->where('store_id', $storeId)
                ->exists();
        } catch (\Throwable $e) {
            // Fail closed on an unreadable table, for the reason ComparesRowOwnership::readRowStore
            // records: a module's tables exist when its routes do, but "in practice" is not a control.
            return false;
        }
    }

    /** A refusal is a redirect or a 403, NEVER a 401: the Flutter admin app forces a logout on any 401. */
    private function deny(Request $request): Response
    {
        $message = __('Permission denied');

        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message, 'message' => $message], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}

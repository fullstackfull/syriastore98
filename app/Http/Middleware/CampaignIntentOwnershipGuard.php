<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use App\Models\User;
use App\Policies\UserPolicy;
use Closure;
use Illuminate\Http\Request;
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
 * store worked too; so did hiding a foreign id in the comma-separated product LIST; and a principal
 * rooted at the operator filed one against the OPERATOR's store, where `created_by = creatorId()`
 * then recorded it as the operator's own. `settingmenuController::campaginSorting` renders approved
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
 * keys are in `ANONYMOUS_BY_DESIGN` as `payment-callback`: a gateway return must stay reachable in
 * the shape the gateway sends it. The census forbids a key in both tables, and it is right to —
 * "requires a signed-in user" is not the rule this route wants. So the classification becomes
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

    /** A product list longer than this is refused outright rather than queried. */
    private const MAX_PRODUCTS = 200;

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! in_array($route->uri(), self::TARGETS, true)) {
            return $next($request);
        }

        // The handler's own first branch: a cancelled return writes nothing and only redirects.
        if ($request->input('return_type') === 'cancel') {
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

        $products = array_values(array_unique(array_filter(
            array_map(fn ($value) => $this->usableId($value), explode(',', (string) ($intent['product'] ?? ''))),
            static fn ($id) => $id !== null
        )));

        if ($products === [] || count($products) > self::MAX_PRODUCTS) {
            return false;
        }

        try {
            $store = DB::table('stores')->where('id', $storeId)->first(['created_by']);

            if ($store === null || (int) $store->created_by !== $tenant) {
                return false;
            }

            // LIMB 1: nothing the handler's own list would touch may lie outside this tenant's
            // rows in this store. A NULL on either column counts as outside.
            $foreign = DB::table('products')
                ->whereIn('id', $products)
                ->where(function ($query) use ($tenant, $storeId) {
                    $query->whereNull('created_by')
                        ->orWhere('created_by', '!=', $tenant)
                        ->orWhereNull('store_id')
                        ->orWhere('store_id', '!=', $storeId);
                })
                ->exists();

            if ($foreign) {
                return false;
            }

            // LIMB 2, stricter: every id asked for must be accounted for by a row they own, so an
            // id that matches NO row cannot be written either.
            $owned = DB::table('products')
                ->whereIn('id', $products)
                ->where('created_by', $tenant)
                ->where('store_id', $storeId)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            // Fail closed on an unreadable table, for the reason ComparesRowOwnership::readRowStore
            // records: a module's tables exist when its routes do, but "in practice" is not a control.
            return false;
        }

        return array_diff($products, $owned) === [];
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

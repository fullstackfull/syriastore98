<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-INV-14. SIX ANONYMOUS ADMIN MUTATIONS IN TWO VENDORED MODULES, ONE OF THEM A MONEY WRITE.
 * =============================================================================================
 *
 * `OrderDocumentAccessGuard` is this guard's sibling and its precedent: same vehicle, same
 * conventions, different verb. That one closed anonymous order-document READS. This one closes
 * anonymous order-and-settings WRITES, found while tracing the callers of
 * `Order::product_return` for P1-INV-02 and then widened by a census of both modules' route
 * files rather than by following the first route found.
 *
 * THE CENSUS, because "two routes" was the first answer and it was wrong. Every POST in each
 * module's route file was listed with the middleware group enclosing it. The admin-shaped
 * mutations declared in a `['web']`-only group -- no `auth`, no `PlanModuleCheck` -- are:
 *
 *   LotteryPrizes/src/routes/web.php   (group at :32)
 *     :56  lottery-order-status-change/{id}  -> App\Models\Order::order_status_change
 *     :59  lottery-order-return              -> App\Models\Order::product_return
 *   AuctionProduct/src/routes/web.php  (group at :24)
 *     :28  update-auction-status             -> AuctionProductSetting::findOrFail
 *     :31  auction-order-assign              -> AuctionProductOrder::find
 *     :32  auction-order-status-change/{id}  -> AuctionProductOrder::order_status_change
 *     :33  auction-order-return              -> AuctionProductOrder::product_return
 *
 * The storefront POSTs in the same groups (`{storeSlug}/product-bid`, the ticket-payment
 * callbacks, `{storeSlug}/lottery-form-data`) are deliberately NOT here: those are shopper-facing
 * and public by design. Only the admin surfaces are guarded.
 *
 * THE TWO LOTTERY ROUTES ARE THE WORST, because they import `App\Models\Order` -- the HOST model.
 * `lottery-order-status-change` reaches the same `cancel` limb P1-ORD-04 hardened, which writes
 * `payment_status = 'Unpaid'` and so triggers `OrderObserver::updated` ->
 * `OrderFinancialService::syncFromOrder`. An anonymous caller could cancel any store's order and
 * walk back its finance record. `lottery-order-return` reaches `Order::product_return`, whose
 * host controller P1-INV-02 hardened; its store predicate narrows the target but cannot supply
 * the authentication this route never had.
 *
 * WHY A `web`-GROUP MIDDLEWARE AND NOT A ROUTE RE-DECLARATION. Module route files are required
 * from `$this->app->booted()`, i.e. AFTER routes/web.php, and `RouteCollection::addToCollections`
 * keys on method+domain+uri -- so a host re-declaration LOSES the slot to the module's. That is
 * measured and written up in docs/security/P01-SEC-2-ROUTE-CACHE-VERIFICATION.md, which also
 * records why a group-appended middleware survives `route:cache`: the cache stores declared
 * middleware NAMES unexpanded and the group is resolved per request from bootstrap/app.php.
 * Editing the module is not an option either -- `packages/workdo/` is vendored and
 * guard-no-workdo-changes.sh enforces it.
 *
 * ===========================================================================================
 *  THE ID IS READ FROM THE SAME REQUEST KEY THE HANDLER READS. THIS IS THE LOAD-BEARING DETAIL.
 * ===========================================================================================
 *
 * A guard that authorises one row while the handler mutates another is not a guard. P1-ORD-04
 * shipped exactly that bug for one commit -- it pinned the id for the authorization and left two
 * later sinks reading the posted value -- so the keys below are taken from each handler's own
 * source line, not from the route signature:
 *
 *   order_status_change  reads `$request->id`        (BOTH modules; the route also declares {id},
 *                                                     and `Request::__get` reads all() FIRST, so
 *                                                     the BODY wins -- the guard must agree)
 *   order_return         reads `$request->order_id`
 *   order_assign         reads `$request->order_id`
 *   updateStatus         reads `$request->input('auctionId')`
 *
 * ===========================================================================================
 *  THE PERMISSION LIMB IS ENFORCED ONLY WHEN THE PERMISSION ROW EXISTS. THAT IS A LOCKOUT
 *  GUARD, NOT A LOOPHOLE -- AND THE FIRST VERSION OF THIS GUARD GOT THE REASONING WRONG.
 * ===========================================================================================
 *
 * WHAT THIS DOCBLOCK USED TO SAY, AND WHY IT WAS FALSE. It claimed the four auction routes could
 * carry no permission limb because `Manage Auction Product Order` and `Manage Auction Product`
 * are "not seeded", `Utility::addNewData()` being "the only mechanism that creates Permission
 * rows". The adversarial review of this guard refuted that, and the refutation was MEASURED:
 * `packages/workdo/AuctionProduct/src/database/seeders/PermissionTableSeeder.php:19-51` creates
 * all ten auction permissions and grants every one to the global `admin` role, and the module
 * ENABLE path runs it -- `ModuleController` calls `Artisan::call('package:seed ' . $name)` at
 * :131, :151, :162 and :182, and `PackageSeed::getSeederClass()` resolves exactly that seeder.
 * Executed against a throwaway database: 0 rows before, 2 rows after, and the merchant `admin`
 * role holds both. (The seeder's namespace casing differs from the directory's, which looks fatal
 * and is not: PHP class names are case-insensitive, so either spelling resolves once the file is
 * included.) `addNewData()` is the only HOST mechanism; it is not the only one.
 *
 * So the limb is available, and its absence was leaving a real within-tenant gap: any
 * authenticated principal whose `current_store` matched the row -- including staff on a
 * hand-built role that deliberately LACKS `Manage Auction Product Order` -- could cancel, confirm
 * and return auction orders, reassign the driver and toggle auction settings.
 *
 * WHY EXISTENCE-CONDITIONAL AND NOT A HARD GATE. A permission with no row can be held by no role,
 * so a hard gate on a name whose row is missing is a PERMANENT 403 for every principal including
 * the platform operator -- the lockout `App\Http\Middleware\FinancePermission`'s docblock
 * records having been caused once already by six unseeded finance names. And a missing row is a
 * real state here, not a hypothetical: an install whose module was enabled before the seeder
 * existed, or whose seeding failed, has the routes and not the permissions. So the row is checked
 * first, for all six targets uniformly rather than through a per-entry exception.
 *
 * THE EXISTENCE CHECK CANNOT BE TURNED INTO A BYPASS. Deleting a `permissions` row needs platform
 * access the attacker would have to already hold, and with the row gone the OWNERSHIP limb below
 * still refuses every other tenant's row -- which is this guard's reason for existing. The
 * degraded state is "authenticated and same-store", never "open".
 *
 * `Manage Order` IS seeded by the host (`app/Models/Utility.php:2755`, granted to the merchant
 * `admin` role at :2918) and is what the two LOTTERY routes require, because those two write the
 * host `orders` table and this guard is deliberately equivalent to `OrderPolicy` for them. The
 * module also seeds `Manage Lottery Order`, which governs its own listing screen rather than the
 * host table, so it is NOT required here -- requiring it would break that equivalence.
 *
 * Whether a merchant's staff SHOULD hold a money-adjacent auction power is still a privilege
 * decision for the owner; what this guard settles is that the question is asked at all.
 *
 * ===========================================================================================
 *  WHAT THIS GUARD CANNOT CLOSE, STATED SO IT IS NOT MISTAKEN FOR CLOSED.
 * ===========================================================================================
 *
 * It authorises the row the request NAMES. It cannot authorise a second row that the vendored
 * handler then picks out of the first one's data, and `AuctionProductOrder::product_return`
 * (packages/workdo/AuctionProduct/src/app/Models/AuctionProductOrder.php:285) does exactly that:
 * it restocks `Product::find($product_id)` / `ProductVariant::where('product_id', ...)` with NO
 * store predicate, and `Product` carries no effective global scope -- it is absent from
 * config/tenancy.php's `scoped_models`, so `StoreScope::isActiveFor()` short-circuits for it.
 * That method has three further defects, all in code this repository may not edit: no replay
 * guard (a second call recomputes `final_price` from the already-reduced total), a shadowed
 * `$product` that makes the non-variant restock `+= null` (there is no `products.qty` column),
 * and no null guard on the `find()`.
 *
 * The LOTTERY routes are not affected by that: they call the HOST `App\Models\Order::product_return`,
 * which P1-INV-02 hardened with both a store anchor and a replay guard. The auction equivalents
 * are filed separately, because the vehicle for them is a host-side control over `Product` writes
 * and not this middleware. Closing an anonymous mutation is this guard's job and is done; the
 * residual needs an authenticated, entitled merchant whose own order already references another
 * tenant's product id.
 *
 * FOR THE HOST-ORDER ROUTES THIS PREDICATE IS DELIBERATELY EQUIVALENT TO `OrderPolicy::allow`:
 * platform operator first, then the seeded permission, then same-store. It is implemented once
 * here rather than split between a policy call for `orders` and an inline check for the two
 * vendored tables -- those models have no policies, and a guard whose mechanism changes per table
 * is the drift this guard exists to prevent. `ModuleOrderMutationGuardTest` asserts the
 * equivalence for the host-order case, so the two cannot diverge silently.
 */
class ModuleOrderMutationGuard
{
    /*
     * THE OWNERSHIP PREDICATE IS SHARED WITH ModuleResourceOwnershipGuard, not copied. The trait
     * holds the operator check, the existence-conditional permission check, the fail-closed row
     * read, the store comparison and strict id parsing. This guard shipped with a false
     * "permission is not seeded" premise AND a resolveId() that fell through to the route
     * segment, and BOTH were found by review rather than by a test -- a second copy would have
     * inherited whichever version was current the day it was written. Id RESOLUTION stays here,
     * because "which request key does the handler read" is the one question that genuinely
     * differs per route, and the two guards answer it differently.
     */
    use ComparesRowOwnership;

    /**
     * uri => [table, request keys in precedence order, required permission or null].
     *
     * Keyed by uri because that is what the route table gives us and what the module declares;
     * keying by action would miss a second uri on the same handler, which is the mistake
     * OrderDocumentAccessGuard's `order/{order}` entry records.
     */
    private const TARGETS = [
        // --- LotteryPrizes: these two drive the HOST Order model ---
        'lottery-order-status-change/{id}' => [
            'table' => 'orders',
            'keys' => ['id'],
            'permission' => 'Manage Order',
        ],
        'lottery-order-return' => [
            'table' => 'orders',
            'keys' => ['order_id'],
            'permission' => 'Manage Order',
        ],

        // --- AuctionProduct: its own models and tables, both of which carry store_id
        //     (verified in their module migrations). The permission names are the ones the
        //     module's OWN authenticated controllers check -- AuctionOrdersController:34 gates
        //     index() on 'Manage Auction Product Order' -- and all ten are created by the
        //     module's PermissionTableSeeder, which the enable path runs. See the docblock for
        //     why the limb is conditional on the row existing.
        //     AuctionProductSettingController checks NOTHING anywhere, so 'Edit Auction Setting'
        //     is this guard's choice from that seeder's list rather than a name the module
        //     already uses. ---
        'auction-order-status-change/{id}' => [
            'table' => 'auction_product_orders',
            'keys' => ['id'],
            'permission' => 'Manage Auction Product Order',
        ],
        'auction-order-return' => [
            'table' => 'auction_product_orders',
            'keys' => ['order_id'],
            'permission' => 'Manage Auction Product Order',
        ],
        'auction-order-assign' => [
            'table' => 'auction_product_orders',
            'keys' => ['order_id'],
            'permission' => 'Manage Auction Product Order',
        ],
        'update-auction-status' => [
            'table' => 'auction_product_settings',
            'keys' => ['auctionId'],
            'permission' => 'Edit Auction Setting',
        ],

        // --- P1-SEC-04, found by the module-route census and EXECUTED (AnonymousAdministrativeModuleRoutesTest): two more routes that
        //     change another tenant's row by a bare id from an unauthenticated `['web']` group. Both tables carry store_id. ---
        // PartialPayments: payment_status_change() sets `order_partial_payments.payment_status` to any string for the row named by `id`
        // (the shopper's "pay balance" button and the amount due are driven by that status). 'Edit Partial Payment' is seeded by the
        // module's PermissionTableSeeder and granted to the admin role; the permission limb is existence-conditional, see the trait.
        'payment-status-change/{id}' => [
            'table' => 'order_partial_payments',
            'keys' => ['id'],
            'permission' => 'Edit Partial Payment',
        ],
        // PDFVouchers: status() accepts or rejects the coupon request named by `id` (an accept mails a PDF of the store's live coupons).
        // The module seeds no permission of its own, so the row's store is the whole check.
        'coupencode/Status/store' => [
            'table' => 'coupon_requests',
            'keys' => ['id'],
            'permission' => null,
        ],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENT, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $target = self::TARGETS[$route->uri()] ?? null;

        if ($target === null) {
            return $next($request);
        }

        /*
         * EXPLICIT GUARD NAME, NEVER auth()->user(). The default guard is mutable -- CustomAuth
         * logs a token's owner into whichever of the three guards matches its tokenable class,
         * and actingAs($customer, 'customers') sets it in tests -- so auth()->user() can return
         * an App\Models\Customer, and isAbleTo() below would then be a fatal on a model that has
         * no such method. The sibling guard records the same trap.
         *
         * Anonymous is refused HERE and not deferred: these six routes declare no `auth` at all,
         * so deferring on a null principal would be a straight anonymous pass-through and this
         * guard a no-op.
         */
        $user = auth('web')->user();

        if ($user === null) {
            return $this->deny($request, 'anonymous');
        }

        // The platform operator, first -- as OrderPolicy::allow does, and for the same reason:
        // on a database built from source no role holds 'Manage Order', so checking the
        // permission first would lock the operator out of the support workflow.
        if ($this->isPlatformOperator($user)) {
            return $next($request);
        }

        if ($this->permissionIsEnforceable($target['permission'])
            && ! $user->isAbleTo($target['permission'])) {
            return $this->deny($request, 'permission');
        }

        $rowId = $this->resolveId($request, $route, $target['keys']);

        if ($rowId === null) {
            return $this->deny($request, 'no id');
        }

        /*
         * THE ROW READ AND THE STORE COMPARISON LIVE IN
         * App\Http\Middleware\Concerns\ComparesRowOwnership, shared with
         * ModuleResourceOwnershipGuard. Four things that used to be inline here are documented
         * there instead, and all four are load-bearing: the read FAILS CLOSED on an unreadable
         * table (module migrations do not run until the module is enabled, so a missing table is
         * a real state and must deny rather than raise past this guard); a null row and a null
         * store_id are both treated as "no store", so a row invisible to every store_id
         * predicate in the application does not become mutable here; and getCurrentStore() is
         * called WITH the user id, because with no argument it reads the mutable default guard.
         */
        [$status, $rowStore] = $this->readRowStore($target['table'], $rowId);

        if ($status !== 'ok') {
            // 'unreadable table' or 'no row'. The response body is identical either way -- see
            // deny() -- so the guard is not an existence oracle over another tenant's id space.
            return $this->deny($request, $status);
        }

        if (! $this->storeMatches($user, $rowStore)) {
            return $this->deny($request, 'other store');
        }

        return $next($request);
    }

    /**
     * The row id this request denotes, from the first key the handler would read.
     *
     * The route parameter is a FALLBACK ONLY, and only when the key is ABSENT from the input. The
     * handlers read the body first because `Request::__get` reads `all()` before the route
     * parameters, so the body is what they act on when both are present -- and a guard that
     * preferred the route parameter would authorise the wrong row on exactly the request that
     * matters.
     *
     * =======================================================================================
     *  A KEY THAT IS PRESENT DECIDES, EVEN WHEN IT IS UNUSABLE. FALLING THROUGH WAS A HOLE.
     * =======================================================================================
     *
     * The first version of this method required `is_numeric()` in BOTH loops, so a body value
     * that was present but not numeric -- `id=5abc` -- was skipped by loop 1 and loop 2 then
     * authorised the ROUTE SEGMENT. That is a live cross-tenant write, found by the adversarial
     * review of this guard and MEASURED on MySQL (see
     * tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php):
     *
     *   POST /lottery-order-status-change/<own order>   body: id=5abc&delivered=cancel
     *     guard  : input('id') = '5abc', not numeric -> route param 42 -> authorises OWN order
     *     handler: $request->id = '5abc' -> Order::find('5abc')
     *     MySQL  : `WHERE id = '5abc'` coerces the string to 5 (warning 1292, non-fatal on a
     *              SELECT) and returns ANOTHER STORE'S ORDER 5, which is then cancelled.
     *
     * SQLITE HIDES IT COMPLETELY. Under INTEGER affinity `'5abc'` is not converted, so the row
     * does not match and the attack silently no-ops -- the same engine-hiding that let P01-SEC-13
     * survive. No test in this suite could have found it, which is why the proof is a MySQL
     * script and not a testcase.
     *
     * So: presence in the input is what hands a key the decision, and `filter_var` decides
     * whether it is usable. `is_numeric` was also too loose in the other direction -- it accepts
     * `'5.9'` and `' 5'`, which this guard and MySQL normalise differently -- so the strict
     * integer filter closes that divergence too. `''` is treated as absent: MySQL coerces it to 0
     * and 0 matches no row.
     */
    private function resolveId(Request $request, $route, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if ($value === null || $value === '') {
                continue;
            }

            // PRESENT. From here the answer is this key's, or it is nothing: the trait's
            // usableId() returns null for anything that does not denote the row the guard would
            // then compare -- which is what `'5abc'` did not.
            return $this->usableId($value);
        }

        foreach ($keys as $key) {
            $id = $this->usableId($route->parameter($key));

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Refuse, in the shape the vendored handlers answer in.
     *
     * All six targets are AJAX POSTs whose handlers return JSON, so a JSON 403 is what their
     * callers can read. `$reason` is logged, never returned: the response must not say whether
     * the row exists, belongs elsewhere, or was never named.
     */
    private function deny(Request $request, string $reason): Response
    {
        $actorId = optional(auth('web')->user())->id;

        Log::warning('module order mutation refused', [
            'reason' => $reason,
            'uri' => $request->route() ? $request->route()->uri() : null,
            'user_id' => $actorId,
            'current_store' => $actorId === null ? null : getCurrentStore($actorId),
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => 'error', 'message' => __('Forbidden.')], 403);
        }

        abort(403);
    }
}

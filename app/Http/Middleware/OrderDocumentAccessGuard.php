<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P01-SEC-2. ORDER DOCUMENTS -- ORDER DETAILS, RECEIPTS, REFUND SCREENS, SHIPPING LABELS, PRINT
 * LABELS AND THE INVOICE PREVIEW -- WERE READABLE ACROSS TENANTS. SEVENTEEN URIS ARE GUARDED.
 *
 * NINE ARE MODULE-DECLARED AND ANONYMOUS; THE REST ARE AUTHENTICATED BUT NOT TENANT-SCOPED, AND
 * THOSE ARE LIVE WITH NO MODULE ENABLED AT ALL.
 *
 * WHICH NINE DEPENDS ON MODULE LOAD ORDER, so the set is named rather than counted loosely: with
 * AutomaticOrderPrinting, AuctionProduct and LotteryPrizes all enabled, the uris resolving with
 * no Illuminate\Auth\Middleware\Authenticate are AutomaticOrderPrinting's own three
 * (`shippinglabel/{id?}/...`, `printlabel/{id?}/...`, `invoices/preview/...`), AuctionProduct's
 * three (`auction/shippinglabel/pdf/{id}`, `auction-order-view/{id}`,
 * `auction-order-receipt/{id}`) and LotteryPrizes' three (`lottery/shippinglabel/pdf/{id}`,
 * `lottery-order-view/{id}`, `lottery-order-receipt/{id}`). `shippinglabel/pdf/{id}` is the
 * TENTH and it is the conditional one: whether it keeps `Authenticate` depends on which
 * declaration wins the method+uri slot, which depends on load order -- see the measurement
 * below. Counting it as unconditionally anonymous would be wrong, and counting it as
 * unconditionally authenticated would be worse.
 *
 * TWO INDEPENDENT LAYERS. The brief's "authentication is necessary but not sufficient" is
 * precisely the second one:
 *
 *   LAYER 1, ANONYMOUS. Nine uris are declared in a `['web']`-only group in a module route file,
 *       so the resolved stack contains no `Authenticate` at all. Reachable by anyone the moment
 *       the owning module is enabled.
 *   LAYER 2, CROSS-TENANT. EVERY target handler, the authenticated host ones included, decrypts
 *       the id and loads the order with NO store predicate -- and four of them check no
 *       permission either. `Order` carries neither the tenancy trait nor an entry in
 *       config/tenancy.php's `scoped_models` allowlist, so no global scope supplies the missing
 *       predicate. The TARGETS table records the measurement per handler.
 *
 * MEASURED, NOT INFERRED. tests/Feature/Security/OrderDocumentRouteResolutionTest dumps the
 * resolved route table with and without the AutomaticOrderPrinting addon enabled through the
 * production loader. With it enabled:
 *
 *   GET shippinglabel/pdf/{id}                      App\...\OrderController@shippinglabel
 *       middleware WITHOUT the addon ... Authenticate, SubstituteBindings, SetLocate, XSS, EmailIsVerified
 *       middleware WITH    the addon ... SubstituteBindings, SetLocate            <-- Authenticate GONE
 *   GET shippinglabel/{id?}/{template?}/{color?}    Workdo\AutomaticOrderPrinting\...@shippinglabel
 *   GET printlabel/{id?}/{template?}/{color?}       Workdo\AutomaticOrderPrinting\...@printinglabel
 *   GET invoices/preview/{template}/{color}         Workdo\AutomaticOrderPrinting\...@invoice_preview
 *
 * THE FIRST ROW IS THE SUBTLE ONE AND IS WHY READING THE DIFF WAS NOT ENOUGH.
 * packages/workdo/AutomaticOrderPrinting/src/routes/web.php:31 re-declares the SAME uri with the
 * SAME controller action as the authenticated host declaration at routes/web.php:1178 (inside the
 * ['auth','xss','setlocate','verified'] group at routes/web.php:400), but inside a ['web']-only
 * group at :24. RouteCollection::addToCollections keys on method+domain+uri, so the later
 * declaration replaces the earlier one -- keeping the action, losing `Authenticate`. Nothing about
 * the action name changes, so a reviewer comparing controllers sees no difference at all.
 *
 * The addon is NOT in PackageServiceProvider's $staticAddons, so none of this exists until an
 * `add_on_managers` row does -- which is why the pre-existing route-boundary sweep could never
 * have caught it: in CI and in the whole test suite the module is simply never loaded.
 *
 * WHY THE HANDLERS ARE NOT FIXED IN PLACE. Nine of the seventeen live under packages/workdo/,
 * which .github/scripts/guard-no-workdo-changes.sh refuses to let a diff modify (LynomiaChat is
 * the one allowlisted directory), so for those there is no choice. The other eight are host code
 * (OrderController, AccountProfileController, OrderRefundController) and ARE editable; they are
 * guarded from here anyway so that ONE rule covers every document surface and cannot drift
 * between a host copy of the check and a module copy of it. Crypt is NOT an authorization control here:
 * APP_KEY is published in this repository's git history and unrotated, so an encrypted id is an
 * ENCODING anyone holding a clone can mint.
 *
 * WHY A MIDDLEWARE APPENDED TO THE 'web' GROUP, following App\Http\Middleware\BulkDeleteTenantGuard.
 * Re-declaring the routes in routes/web.php cannot work: the addon's route file is required from
 * `$this->app->booted()` (app/Providers/PackageServiceProvider.php:35), i.e. AFTER routes/web.php,
 * so the addon would win the slot again. Route-level middleware cannot be attached to a vendored
 * route file. A group-appended middleware survives `route:cache` because the cache stores
 * middleware NAMES unexpanded and the 'web' group is resolved per request from bootstrap/app.php,
 * which every web request re-executes. VERIFIED BY EXECUTION, not by reading Laravel: a real
 * bootstrap/cache/routes-v7.php was built with the addon enabled, and a SEPARATE process booted
 * from that file and reported `routesAreCached() === true` with all four AutomaticOrderPrinting
 * uris present and every one of them carrying this guard (the mechanism proven is a property of
 * the `web` group, not of those four routes). The cache file itself names neither this guard nor
 * BulkDeleteTenantGuard -- only the group name `web` -- which is the mechanism. Transcript:
 * docs/security/P01-SEC-2-ROUTE-CACHE-VERIFICATION.md.
 *
 * THE RULE. One rule, applied uniformly to every target, deliberately NOT delegated to
 * App\Policies\OrderPolicy: a policy needs a model instance, and some targets' rows belong to a
 * module model that is absent whenever that module is not loaded. The rule mirrors
 * OrderPolicy::allow() -- operator first, then the permission, then the tenant predicate -- and
 * tests/Feature/Security/OrderDocumentAccessTest pins the two in agreement.
 *
 *   platform operator (users.type === 'super admin')  -> allow. MANDATORY and not a shortcut:
 *       Laratrust has no super-admin bypass and $superadminNewPermission (app/Models/Utility.php)
 *       contains no order permission at all, so a bare isAbleTo() would 403 the operator for ever.
 *   merchant / staff on the `web` guard (resolved as auth('web'), never the mutable default)
 *       -> isAbleTo('Show Order') AND the order's store_id equals
 *       getCurrentStore(). 'Show Order' is seeded (app/Models/Utility.php:2756) and granted by the
 *       same function to the global role literally named `admin` -- the merchant/store-owner role,
 *       matched at :2899, listed in $companyNewPermission at :2919, granted by the loop at
 *       :3112. The OTHER seeded role, `super admin`
 *       (database/seeders/PermissionTableSeeder.php:58), does NOT hold it -- $superadminNewPermission
 *       contains no order permission at all -- which is exactly why the operator branch above is
 *       mandatory rather than a convenience. 'Show Order' is already the permission guarding the
 *       admin order screen that
 *       emits these links (app/Http/Controllers/OrderController.php:501) -- so the gate is exactly
 *       as tight as the UI and creates no dead link. No new permission name is introduced: an
 *       unseeded one can never be granted and would lock everyone out permanently.
 *   signed-in shopper on the `customers` guard -> the order's customer_id equals their id, is
 *       non-zero, and the order's store matches theirs.
 *   everyone else, including every anonymous caller -> 403.
 *
 * FAIL CLOSED, AND INDISTINGUISHABLY. A failed decrypt, an id for an order that does not exist,
 * an order in another tenant and a caller without the permission all produce the same bare 403
 * with no body, so the response cannot be used to probe which order ids exist in which store. The
 * guard runs BEFORE the controller, and every target handler is a pure read-and-render that
 * returns a View -- none streams, echoes or writes a file -- so a denial means the document was
 * never produced, not merely not delivered.
 *
 * NOT 401, DELIBERATELY. repo B's lib/network/network.dart maps any 401 onto a forced logout, and
 * these uris are reachable from a browser session that may hold a shopper principal. A 403 is also
 * what App\Http\Middleware\EnsureCustomerAuthenticated and the admin API's storeAccessDenied()
 * already answer for an authorization failure.
 *
 * DISCLOSED BEHAVIOUR CHANGE, recorded so it is not discovered in production: the document button
 * on the ANONYMOUS storefront order page stops working for a caller who is not signed in. That
 * page is served by routes/customer.php:165, outside any auth group, and is reached from the
 * emailed {order_url}. ONLY ONE BRANCH of its @if ever offers a link, and the measurement matters
 * because the other reading is tempting and wrong: when module_is_active('AutomaticOrderPrinting')
 * is FALSE, resources/views/front_end/pages/order-detail.blade.php:30 links `shippinglabel.pdf`,
 * and that is the link that stops working. When it is TRUE, :27-28 renders
 * @stack('invoice-button') -- and NOTHING EVER FILLS THAT STACK ON THIS VIEW. The only pusher is
 * packages/workdo/AutomaticOrderPrinting/src/app/Providers/ViewComposer.php:41, registered at :28
 * for the views `order.view` and `front_end.sections.order_detail`, neither of which is
 * `front_end.pages.order-detail`. So with the addon active the storefront page today shows no
 * document button at all, and this guard changes nothing there. A signed-in shopper keeps it. A
 * GUEST order cannot be self-served at all, because orders.customer_id defaults to 0. Preserving
 * that flow safely needs a scoped expiring document token; no signed-URL machinery exists in this
 * codebase (only routes/auth.php:45 uses `signed`, for email verification) and inventing one is
 * outside this package. It is an owner decision, recorded in the sprint report.
 *
 * SECOND DISCLOSED BEHAVIOUR CHANGE, and this one is a permission bypass closing rather than a
 * loss: a staff role holding 'Manage Order' but NOT 'Show Order' can today still read an order
 * through `orders/order_view/{id}`, because OrderController::show() checks no permission at all
 * while resources/views/order/action.blade.php renders its Show button unconditionally. The order
 * LIST those buttons sit in needs only 'Manage Order' (OrderController::index():483). After this
 * change that role gets a 403 from the Show button and from the receipt, which is what not
 * holding 'Show Order' is supposed to mean. No seeded role is affected: $companyNewPermission
 * (app/Models/Utility.php:2918-2919) grants both together, and only a hand-built custom role can
 * hold one without the other.
 */
class OrderDocumentAccessGuard
{
    public const PLATFORM_ADMIN = 'super admin';

    /** The permission that already guards the admin screen emitting these links. */
    public const PERMISSION = 'Show Order';

    /**
     * The guarded routes, keyed by the route uri exactly as the resolved route table reports it.
     *
     * Matching is on verb + uri, not on the route NAME and not on the controller class.
     *   - not the name, because all but one are vendored module-owned strings;
     *   - not the controller class, because App\Http\Controllers\OrderController@shippinglabel is
     *     shared with the properly gated host declaration and that controller holds many other
     *     methods, so a class-prefix match would arm this guard on unrelated routes.
     *
     * `table` is queried directly rather than through a model: the auction row belongs to a module
     * class that does not exist when that module is not loaded, and a raw query also cannot pick up
     * a global scope that may or may not be enabled.
     *
     * NOTE on the second entry: `shippinglabel/{id?}/{template?}/{color?}` ALSO matches the URL
     * /shippinglabel/pdf/7, so guarding only `shippinglabel/pdf/{id}` would leave a live path to
     * the same document.
     */
    private const TARGETS = [
        // ---------------------------------------------------------------------------------
        // ANONYMOUS WHEN THE MODULE IS ENABLED. Each sits in a `['web']`-only group, so the
        // resolved stack contains no `Authenticate` at all. Measured in
        // tests/Feature/Security/OrderDocumentRouteResolutionTest.
        // ---------------------------------------------------------------------------------
        // Re-declares routes/web.php:1178's uri AND action, displacing the authenticated host
        // declaration. The one that no diff review could have caught.
        'shippinglabel/pdf/{id}' => ['table' => 'orders', 'param' => 'id'],
        'shippinglabel/{id?}/{template?}/{color?}' => ['table' => 'orders', 'param' => 'id'],
        'printlabel/{id?}/{template?}/{color?}' => ['table' => 'orders', 'param' => 'id'],
        'lottery/shippinglabel/pdf/{id}' => ['table' => 'orders', 'param' => 'id'],
        'auction/shippinglabel/pdf/{id}' => ['table' => 'auction_product_orders', 'param' => 'id'],
        // Order DETAIL and RECEIPT documents, the first two nouns in this package's objective.
        // Same shape as the labels above: declared in their module's `['web']`-only group
        // (AuctionProduct/src/routes/web.php:24, LotteryPrizes/src/routes/web.php:32), so
        // anonymous, and their handlers load the row by decrypted id with no store predicate.
        // Both receipt handlers additionally check NOTHING -- not auth, not a permission.
        'auction-order-view/{id}' => ['table' => 'auction_product_orders', 'param' => 'id'],
        'auction-order-receipt/{id}' => ['table' => 'auction_product_orders', 'param' => 'id'],
        'lottery-order-view/{id}' => ['table' => 'orders', 'param' => 'id'],
        'lottery-order-receipt/{id}' => ['table' => 'orders', 'param' => 'id'],

        // ---------------------------------------------------------------------------------
        // AUTHENTICATED BUT NOT TENANT-SCOPED -- live with no module enabled at all, and
        // therefore the half of this defect that is reachable on the server as it stands.
        // All three are in the host's `['auth','xss','setlocate','verified']` group, so
        // `Authenticate` IS present (measured), and all three then decrypt the id and load the
        // order with no store predicate:
        //   OrderController::order_view():499      checks isAbleTo('Show Order'), no store check
        //   OrderController::show():490            checks NOTHING -- no permission, no store
        //   OrderController::order_receipt():630   checks NOTHING -- no permission, no store
        // So any signed-in user of any tenant could read any other merchant's order detail or
        // receipt. The brief's "authentication is necessary but not sufficient", exactly.
        // Guarded here rather than by editing the controller so that one rule covers every
        // document surface and cannot drift between them.
        'order-view/{id}' => ['table' => 'orders', 'param' => 'id'],
        'orders/order_view/{id}' => ['table' => 'orders', 'param' => 'id'],
        'order-receipt/{id}' => ['table' => 'orders', 'param' => 'id'],

        // ---------------------------------------------------------------------------------
        // SHOPPER-FACING ORDER DOCUMENTS. Both are in routes/customer.php's
        // EnsureCustomerAuthenticated group, so a signed-in shopper is required -- and that is
        // ALL that was required: neither handler compares the order to the caller or to the
        // store. Any shopper of any tenant could read any order's full detail, which is the
        // brief's "authentication is necessary but not sufficient" on the storefront side.
        //   AccountProfileController::customerorder():819  Crypt::decrypt then
        //       Order::order_detail($id); no customer_id, no store_id.
        //   AccountProfileController::order_refund():901   with ?refund=true, takes the route
        //       parameter VERBATIM -- a raw, enumerable integer -- into Order::order_detail().
        //       Its else-branch loads OrderRefund, which IS covered by a global scope
        //       (config/tenancy.php), so only the refund=true branch is exposed. This is the
        //       route that forced resolveOrderId() to accept the raw form.
        // Found by an adversarial audit of this guard AFTER its first commit, not by the census
        // -- neither uri contains any of the words the uri census scans for, which is why that
        // census is no longer the only net. See
        // OrderDocumentRouteResolutionTest::test_every_route_reaching_an_order_document_handler_is_guarded.
        '{storeSlug?}/customerorder/{id}' => ['table' => 'orders', 'param' => 'id'],
        '{storeSlug?}/order-refund/{id}' => ['table' => 'orders', 'param' => 'id'],

        // THE REFUND REQUEST SCREEN, which is an order document by any reading:
        // OrderRefundController::show() does Crypt::decrypt then Order::order_detail($id) and
        // renders the order with its line items. It is authenticated (Authenticate is in the
        // measured stack) and it DOES call getCurrentStore() -- but only to look up the store's
        // OrderRefundSetting rows at :41-42. The order itself is never compared to the caller's
        // store, and no permission is checked. Registered by
        // `Route::resource('refund-request', OrderRefundController::class)` at
        // routes/web.php:1274, so the parameter is Laravel's snake_case of the resource name.
        'refund-request/{refund_request}' => ['table' => 'orders', 'param' => 'refund_request'],

        // A SECOND URI ON AN ALREADY-GUARDED HANDLER. Route::resource('order') at
        // routes/web.php:1051 registers `order/{order}` -> OrderController@show, the same action
        // as the guarded `orders/order_view/{id}`, but with a different parameter NAME. Guarding
        // by uri means a second uri on a guarded handler is NOT guarded by implication, so it is
        // listed explicitly. Note `param` is 'order', not 'id'.
        'order/{order}' => ['table' => 'orders', 'param' => 'order'],

        // ---------------------------------------------------------------------------------
        // NO ORDER ID AT ALL. invoice_preview builds `new Order()` and renders a template
        // chooser, so it leaks no order data. Authentication only -- this entry is hardening,
        // not a vulnerability fix, and gating it on an order permission would break the
        // settings iframe
        // (packages/workdo/AutomaticOrderPrinting/src/resources/views/index.blade.php).
        'invoices/preview/{template}/{color}' => ['table' => null, 'param' => null],
    ];

    /** The route whose `{id}` is a refund id unless `refund` is truthy (P1-ORD-12). */
    private const REFUND_VIEW_URI = '{storeSlug?}/order-refund/{id}';

    private function usableOrderId(mixed $value): ?int
    {
        $id = is_scalar($value) && ! is_bool($value) ? filter_var((string) $value, FILTER_VALIDATE_INT) : false;

        return ($id === false || $id <= 0) ? null : $id;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // FIRST STATEMENT, so this guard is inert on every other route in the application.
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $target = self::TARGETS[$route->uri()] ?? null;

        if ($target === null || ! in_array('GET', $route->methods(), true)) {
            return $next($request);
        }

        // Anonymous is refused here and not deferred. The three existing host guards open with
        // `if (! $user) return $next($request)` because every route they sit on declares `auth`.
        // NINE OF THE SEVENTEEN TARGETS DECLARE NO SUCH THING -- their measured stack contains
        // no Authenticate at all -- so deferring on a null principal would be a straight
        // anonymous pass-through and the fix a no-op. On the eight host targets this branch is
        // largely unreachable: Authenticate (admin) or EnsureCustomerAuthenticated (storefront)
        // precedes this guard in their stack and has already refused an anonymous caller.
        // EXPLICIT GUARD NAMES, NEVER auth()->user(). The DEFAULT guard is mutable: Laravel's
        // actingAs($customer, 'customers') sets it, and App\Http\Middleware\CustomAuth logs a
        // token's owner into whichever of the three guards matches its tokenable class. So
        // auth()->user() can legitimately return an App\Models\Customer here, and the merchant
        // limb below would then call isAbleTo() on a model that has no such method -- a fatal,
        // and on a guard-evaluation path that is the worst place to have one. Caught by
        // tests/Feature/Security/OrderDocumentAccessTest's shopper cases.
        $user = auth('web')->user();
        $customer = auth('customers')->user();

        if ($user === null && $customer === null) {
            return $this->deny($request);
        }

        // invoice_preview: authenticated merchant surface, no order to own.
        if ($target['table'] === null) {
            return $user === null ? $this->deny($request) : $next($request);
        }

        $orderId = $this->resolveOrderId($request, $route, (string) $target['param']);

        if ($orderId === null) {
            return $this->deny($request);
        }

        // P1-ORD-12. `{id}` on `order-refund/{id}` is an ORDER id only on the `refund=true` branch (the form that starts a refund). On the
        // other branch (the shopper's "eye" button on their refund list) it is a REFUND id, and asking "does order #<refundId> belong to me?"
        // refused a shopper their own refund request unless the two numbers happened to coincide - and answered the attacker's question for
        // them. The branch condition is the handler's own (AccountProfileController::order_refund), copied, not reinterpreted. A refund
        // that does not exist is refused like one that is not yours.
        if ($route->uri() === self::REFUND_VIEW_URI && ! (isset($request->refund) && $request->refund == true)) {
            // a refund with no order (or no refund) leaves null here, and the row read below then finds nothing and denies
            $orderId = $this->usableOrderId(DB::table('order_refunds')->where('id', $orderId)->value('order_id'));
        }

        // FAIL CLOSED ON A BROKEN READ, NEVER OPEN. In practice a module's table exists
        // whenever its routes do -- the same module registers both -- but "in practice" is not a
        // control. A missing table, a lost connection or a renamed column must deny, not raise a
        // QueryException: the handler behind this guard would otherwise be reached by whatever
        // the exception handler decides to do, and a 500 page is an unhelpful way to learn that
        // authorization stopped running. Measured: auction_product_orders is absent from the
        // test schema (module migrations do not run until the module is enabled), which is how
        // this branch was found.
        try {
            $row = DB::table($target['table'])
                ->where('id', $orderId)
                ->first(['store_id', 'customer_id']);
        } catch (\Throwable $e) {
            return $this->deny($request);
        }

        if ($row === null) {
            return $this->deny($request);
        }

        return $this->authorises($user, $customer, $row)
            ? $next($request)
            : $this->deny($request);
    }

    /**
     * The order id a route parameter denotes, or null if it denotes none.
     *
     * ENCRYPTED OR RAW, BECAUSE THE HANDLERS ARE BOTH. Most targets pass `Crypt::encrypt($id)`,
     * but AccountProfileController::order_refund():901 takes the route parameter VERBATIM into
     * `Order::order_detail($id)` -- a plain, enumerable integer -- so a decrypt-only resolver
     * would deny that route's LEGITIMATE callers while the defect it was added for stayed open.
     *
     * ACCEPTING THE RAW FORM WEAKENS NOTHING, and that is the whole reason it is allowed: the
     * encryption is not a control here. APP_KEY is published in this repository's git history and
     * unrotated, so anyone holding a clone can mint the encrypted form of any integer -- the
     * class docblock's central point. Authorization is the comparison in authorises(); this
     * method only decides WHICH row to compare. A caller who supplies a raw id they do not own
     * gets the same 403 as one who supplies its encrypted form, which is asserted both ways in
     * tests/Feature/Security/OrderDocumentAccessTest.
     *
     * Still fails closed on everything that is not a positive integer in one of those two forms.
     */
    private function resolveOrderId(Request $request, $route, string $param): ?int
    {
        $raw = $route->parameter($param);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decrypt($raw);
        } catch (\Throwable $e) {
            // Not an encrypted payload. It may still be a raw id -- see the docblock.
            $decrypted = $raw;
        }

        if (! is_scalar($decrypted)) {
            return null;
        }

        $id = filter_var((string) $decrypted, FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }

    /** @param object{store_id: mixed, customer_id: mixed} $row */
    private function authorises(?object $user, ?object $customer, object $row): bool
    {
        $storeId = $row->store_id === null ? null : (int) $row->store_id;

        // EITHER IDENTITY MAY ENTITLE THE CALLER, and this is a correction rather than a
        // widening. The merchant limb used to `return false` on its own failure, so a browser
        // holding BOTH a live admin session and a shopper session -- entirely possible, since
        // config/session.php sets the cookie path to '/' and the storefront sits inside the same
        // `web` group -- was refused its OWN order document: the web limb ran first, failed, and
        // never let the shopper limb speak. A false DENIAL, reported by the P01-SEC-2 audit.
        //
        // Falling through is not a bypass: the shopper limb below is not weaker, it is a
        // different entitlement, and it still demands orders.customer_id equal a non-zero
        // customer id AND the stores match. A principal who genuinely holds two identities should
        // be admitted if EITHER of them owns the document; being admitted by neither is the only
        // correct refusal.
        if ($user !== null) {
            if ($user->type === self::PLATFORM_ADMIN) {
                return true;
            }

            if (! $user->isAbleTo(self::PERMISSION)) {
                return $this->shopperOwns($customer, $row);
            }

            // PASS THE USER ID EXPLICITLY. getCurrentStore() with no argument reads
            // auth()->user() on the MUTABLE DEFAULT guard (app/Helper/helper.php:392), which is
            // the same trap as above: if the default has been pointed at `customers`, it would
            // resolve a Customer and return null, and this branch would then fail closed for a
            // merchant who is in fact entitled. Passing the id makes the lookup independent of
            // whatever the default guard happens to be.
            $currentStore = getCurrentStore($user->id);

            // Fail closed on a missing store on either side. An order with a NULL store_id is
            // invisible to every `where('store_id', ...)` predicate in the application and must
            // not become visible here.
            if (empty($currentStore) || $storeId === null) {
                return $this->shopperOwns($customer, $row);
            }

            return $storeId === (int) $currentStore
                ? true
                : $this->shopperOwns($customer, $row);
        }

        return $this->shopperOwns($customer, $row);
    }

    /**
     * Whether the signed-in shopper, if there is one, placed this order in their own store.
     *
     * Extracted so the merchant limb can fall through to it rather than returning its own
     * verdict. customer_id defaults to 0 on a guest order, so 0 can never match: a guest order
     * is not self-serviceable by anyone.
     *
     * @param object{store_id: mixed, customer_id: mixed} $row
     */
    private function shopperOwns(?object $customer, object $row): bool
    {
        if ($customer === null) {
            return false;
        }

        $storeId = $row->store_id === null ? null : (int) $row->store_id;
        $customerId = $row->customer_id === null ? 0 : (int) $row->customer_id;

        if ($customerId <= 0 || (int) $customer->id !== $customerId) {
            return false;
        }

        return $storeId !== null && (int) ($customer->store_id ?? 0) === $storeId;
    }

    /**
     * One response for every refusal, so the status cannot be used to tell "no such order" from
     * "another merchant's order" from "you lack the permission".
     */
    private function deny(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Forbidden.')], 403);
        }

        abort(403);
    }
}

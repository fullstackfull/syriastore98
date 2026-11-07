<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\FlashSale;
use App\Models\Store;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\DataTables\CustomerDataTable;
use App\Support\Customers\CustomerListFilter;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
/**
 * CLAUDE B FINDING / P01B-SEC-2. THE ADD-CUSTOMER ACTION HAD NO PERMISSION CHECK AT ALL.
 *
 * Claude B reported the check as "removed for testing and still absent", and that is what the
 * code showed: store() went straight from the method signature into $request->validate(), while
 * its siblings in this same controller all gate -- index() and destroy() on 'Manage Customer',
 * show() on 'Show Customer'. Any authenticated user could create a customer.
 *
 * A HIDDEN BUTTON IS NOT A FIX, and the brief says so explicitly, so the gate is here on the
 * server action. The Blade visibility is a separate, secondary concern and is not what this
 * closes.
 *
 * GATED ON 'Manage Customer', NOT ON 'Create Customer'. That name does not exist: the only
 * customer rows Utility::addNewData() creates are 'Manage Customer', 'Show Customer' and
 * 'Status Customer' -- verified by counting both quote styles, and recorded already in
 * destroy()'s docblock below. Gating on an invented name would 403 every legitimate user on a
 * database built from source, which is the exact failure that locked out the finance surface.
 *
 * THE STORE FALLBACK IS ALSO CLOSED. The row was created with
 * `'store_id' => auth()->user()->current_store ?? 1`, so a user with no current_store silently
 * created the customer in STORE 1 -- another tenant's store. It now resolves through
 * getCurrentStore(), which returns 1 only for the platform operator (app/Helper/helper.php) and
 * null for a merchant with no store, and a null store is refused rather than defaulted.
 */
public function store(Request $request)
{
    $user = auth()->user();

    // Operator first: isAbleTo() has no super-admin bypass in this codebase, so testing the
    // permission alone would refuse the platform operator. Same order as destroy() below.
    $isOperator = $user && strtolower(trim((string) $user->type)) === 'super admin';

    if (!$user || (!$isOperator && !$user->isAbleTo('Manage Customer'))) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /*
     * ISSUE #84. THE OPERATOR MUST HAVE NAMED A STORE, and the guard below cannot ask that.
     *
     * `getCurrentStore()` returns the integer literal `1` for a `super admin` whose
     * `current_store` is empty -- `app/Helper/helper.php:774-775`, "Default store for super
     * admin". `empty(1)` is FALSE, so the `empty($store)` check immediately below passes and the
     * handler proceeds. The operator then creates a customer in WHOEVER OWNS STORE 1, with a
     * password the operator chose and `email_verified_at` already set (see the create call below)
     * -- an account that can sign in to that merchant's storefront at once. The merchant is not
     * told, and the response never names the store.
     *
     * This is not an escalation: the operator may legitimately act in any store. The defect is
     * that NO STORE WAS CHOSEN, and a silent default is the wrong answer to "which merchant did
     * you mean" when the answer decides who owns a new credential.
     *
     * NARROW ON PURPOSE -- the fix is here, not in the helper. That literal serves a large number
     * of call sites and changing it is a platform-wide behaviour change needing its own decision
     * and its own evidence. This handler asks the smaller question (may I CREATE an account in a
     * store the caller has not named) and answers it locally.
     *
     * THE MERCHANT PATH IS UNTOUCHED, and that is asserted: a merchant's `current_store` is set,
     * so the helper's first branch returns it and this limb is never reached. A merchant who has
     * no `current_store` but owns a store still falls through to the helper's own
     * `Store::where('created_by', ...)` lookup, exactly as before.
     *
     * `!empty()` matches the helper's own test, so all three spellings it treats as unset --
     * null, 0 and '' -- are refused here rather than one of them slipping through to the literal.
     */
    if ($isOperator && empty($user->current_store)) {
        return redirect()->back()->with('error', __('Select a store before adding a customer.'));
    }

    $store = getCurrentStore();

    if (empty($store)) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /*
     * P2.5-PKG-E (E-3, corrected). VALIDATION RUNS **OUTSIDE** THE try, and that placement is the
     * whole fix rather than a tidy-up.
     *
     * The first attempt simply deleted the `catch (ValidationException $e)` block and left
     * `validate()` inside the try. **ValidationException EXTENDS Exception**, so the generic
     * `catch (\Exception $e)` below swallowed it and replaced the per-field errors with the
     * generic "could not be created" flash -- every field message gone, and the merchant told
     * nothing about what was wrong. Caught by
     * CustomerCreateFormTest::test_a_live_duplicate_in_the_same_store_is_still_refused and three
     * siblings, which is why that test asserts the refusal rather than only the repair.
     *
     * Outside the try, a validation failure propagates to Laravel's own handler, which redirects
     * back with the errors and the old input -- exactly what `<x-ss.error-summary>` and the
     * `@error` blocks render. One report, in the merchant's own language.
     */
    $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            /*
             * P2.5-PKG-E (E-1). `whereNull('deleted_at')` -- AN ARCHIVED E-MAIL STOPS BEING
             * RESERVED, and that closes a dead end a merchant reaches in two clicks.
             *
             * `destroy()` is `$customer->delete()` and `Customer` uses `SoftDeletes`, so archiving
             * sets `deleted_at` and the merchant is told "Customer archived successfully."
             * Laravel's `unique` rule queries the table with NO `deleted_at` predicate, so it
             * counted the archived row: the address was reserved for ever and the create form
             * answered "The email has already been taken" for a person the merchant can no longer
             * see anywhere in their own interface.
             *
             * AND THERE IS NO WAY BACK. No restore route, no archived-customer view, and no
             * `withTrashed()` anywhere in this controller -- so the merchant had archived someone,
             * wanted them back, and the product offered neither re-create nor restore.
             *
             * A LIVE DUPLICATE IS STILL REFUSED. The rule is narrowed to archived rows, not
             * removed, and a test asserts that half explicitly -- removing the rule rather than
             * narrowing it is the obvious wrong fix here.
             *
             * AND IT IS NOW PER STORE. **OWNER DECISION, 2026-11-07**, approved in principle:
             * the same e-mail MAY exist in different stores; two ACTIVE customers with the same
             * e-mail in ONE store must NOT be allowed; ARCHIVED customers MAY re-register safely.
             * That is one rule -- (email, store_id) unique over LIVE rows -- and the two
             * predicates below are the whole of it.
             *
             * THE PREDICATE COMES FROM THE PRINCIPAL, NOT FROM INPUT. `$store` is
             * `getCurrentStore()`, resolved above and refused when empty. A `store_id` in the
             * request body is ignored, and a test asserts that, because a tenant predicate built
             * from caller-supplied input is not a tenant check -- it has produced four separate
             * defects in this codebase.
             *
             * WHAT THE OWNER'S CONDITIONS REQUIRED FIRST, both completed before this line changed:
             * the tenant-authorization review (three rounds, which found three faults in my own
             * fixes), and store-scoping the two first-party lookups that resolved a customer by
             * e-mail with no store predicate -- `PaymentController:2070` on guest checkout, where
             * an anonymous storefront request resolves no tenant so StoreScope adds nothing, and
             * `HomeController:1218` on order status. Both now carry `store_id`, asserted by
             * `UnscopedCustomerEmailLookupTest`.
             *
             * THE TWO ADMIN-APP PATHS WERE CHANGED IN THE SAME SLICE, and that is the point rather
             * than a side effect: `Api\DashboardController::addCustomer()` and
             * `::updateCustomerInfo()` were per-store but archived-INSENSITIVE, so the web form
             * and the shipped Flutter app gave OPPOSITE answers on both axes of one operation.
             * CLAUDE.md's second standing principle is Flutter parity, and
             * `CustomerEmailUniquenessPolicyTest` now asserts the two surfaces agree instead of
             * assuming it.
             *
             * WHAT IS **NOT** CHANGED, because it is a storefront AUTHENTICATION path and the
             * owner's instruction in the same message is "No silent payment behavior or
             * authentication policy changes": `Api\AuthController:76`, whose global
             * `unique:customers,email` is live on the routed public `POST api/{slug}/register`;
             * the soft-delete-blind registration existence check in
             * `Customer\Auth\CustomerLoginController`; and `AccountProfileController:127`/`:183`,
             * which are per-store but archived-insensitive and therefore STRICTER than the policy,
             * i.e. failing in the safe direction. Each is recorded in
             * `docs/security/P25-CUSTOMER-EMAIL-UNIQUENESS-PROPOSAL.md` for the owner.
             *
             * NO DATABASE CONSTRAINT BACKS THIS. There is no unique index on `customers.email`
             * anywhere, and the migration is NOT authorised, so this is check-then-act and two
             * concurrent creates can both succeed. Measured, not glossed:
             * `verify-customer-email-uniqueness-on-mysql.php` section D, on MariaDB 11.4.9 --
             * production's own engine version.
             */
            'email' => [
                'required', 'email', 'max:255',
                \Illuminate\Validation\Rule::unique('customers', 'email')
                    ->where('store_id', $store)
                    ->whereNull('deleted_at'),
            ],
            'mobile' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
        'status' => 'required|in:0,1',
    ]);

    try {
        $customer = Customer::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'password' => \Hash::make($validated['password']),
            'type' => 'customer',
            'status' => $validated['status'],
            'store_id' => $store,
            'regiester_date' => now()->toDateString(),
            'last_active' => now()->toDateString(),
            'created_by' => auth()->id(),
            'email_verified_at' => now(),
        ]);

        return redirect()->route('customer.index')
            ->with('success', __('Customer created successfully.'));

    /*
     * P2.5-PKG-E (E-3). THE ValidationException CATCH IS GONE, because it reported the same
     * failure a second time and in English only.
     *
     * `$request->validate()` throws, and Laravel's own handler already redirects back with the
     * errors and the old input -- which is what `<x-ss.error-summary>` and the `@error` blocks on
     * the form render. The catch redid that redirect and added
     * `->with('error', 'Validation failed: ' . implode(', ', $errorMessages))`: an untranslated
     * English sentence listing every message, on top of the per-field messages. A merchant with
     * one bad field was told twice, once in a language they may not read.
     *
     * Letting it propagate also keeps `withInput()` consistent with the rest of the application
     * instead of this handler's own copy.
     */
    } catch (\Exception $e) {
        /*
         * P2.5-PKG-E (E-2). THE RAW EXCEPTION MESSAGE IS NO LONGER PUT ON THE MERCHANT'S SCREEN.
         *
         * This was `->with('error', 'Error: ' . $e->getMessage())`. A database exception's message
         * carries SQL, table and column names and sometimes values, so an internal detail reached
         * a merchant-facing page -- and `'Error: '` was untranslated, so an Arabic merchant read an
         * English prefix in front of it.
         *
         * NOTHING IS LOST FOR DIAGNOSIS: the full message, file and line are still logged, and the
         * exception class is added, which the previous log line omitted.
         */
        \Log::error('Customer Creation Error:', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return redirect()->back()
            ->with('error', __('The customer could not be created. Please try again.'))
            ->withInput($request->except(['password', 'password_confirmation']));
    }
}

public function create()
    {
        $user = auth()->user();
        $isOperator = $user && strtolower(trim((string) $user->type)) === 'super admin';

        if (!$user || (!$isOperator && !$user->isAbleTo('Manage Customer'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('customer.create');
    }

/**
 * R4-04 cross-tenant IDOR exemplar.
 *
 * This had NO checks at all -- no permission, no tenant scope -- while its two
 * siblings in this same controller both check: index() gates on
 * 'Manage Customer' and show() on 'Show Customer'. The parameter is route-model
 * bound, so Laravel resolved ANY customer id in the table and deleted it. Any
 * authenticated user could delete any other store's customer by id.
 *
 * Gated on 'Manage Customer', the permission index() already uses, and NOT on a
 * 'Delete Customer' permission. That name does not exist: the only
 * customer-related rows Utility::addNewData() creates are 'Manage Customer',
 * 'Show Customer' and 'Status Customer' (verified by counting both quote styles
 * in Utility.php -- there is no 'Delete Customer', 'Create Customer' or
 * 'Edit Customer'). Gating on an invented name would make this 403 for
 * everybody on a database built from source, which is exactly the defect that
 * locked out the finance surface.
 */
public function destroy(Customer $customer)
{
    $user = auth()->user();

    if (!$user) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    // Operator path first. This ORDER is a fix: isAbleTo() has no super-admin
    // bypass, so the previous `!$user->isAbleTo('Manage Customer')` ahead of
    // the exemption below denied the platform operator outright. Same shape as
    // AuthServiceProvider's `finance` gate (:80-83).
    if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN
        && !$user->isAbleTo('Manage Customer')) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    // Ownership. A super admin is exempt, matching OrderPolicy::allow and the
    // finance gate, so the operator is not locked out.
    if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
        $currentStore = getCurrentStore();

        if (
            empty($currentStore)
            || empty($customer->store_id)
            || (int) $customer->store_id !== (int) $currentStore
        ) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    $customer->delete();

    return redirect()->route('customer.index')
                     ->with('success', __('Customer archived successfully.'));}

    public function index(CustomerDataTable $dataTable, Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Customer')) {
            $customer_field = Customer::customer_field();
            return $dataTable->with('request', $request)->render('customer.index', compact('customer_field'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * NEW-customer-show-cross-tenant.
     *
     * The 'Show Customer' permission was checked; OWNERSHIP was not. Every
     * query below keys on the customer id alone, so a merchant holding a
     * permission every merchant holds could read another store's customer and
     * that customer's orders by changing the id in the URL.
     *
     * The ownership limb is added ahead of the queries, matching destroy().
     */
    public function show($id)
    {
        $actor = auth()->user();
        // Operator path first -- see customerStatus() above for why.
        $mayShow = $actor
            && ($actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN
                || $actor->isAbleTo('Show Customer'));

        if ($mayShow) {
            $user = $actor;
            $customer = Customer::find($id);

            if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    !$customer
                    || empty($currentStore)
                    || empty($customer->store_id)
                    || (int) $customer->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Customer not found.'));
                }
            }

            /*
             * F169 -- THE ORDERS ARE SCOPED TO THE CUSTOMER'S OWN STORE.
             *
             * The guest branch below joins on an EMAIL ADDRESS, and an email address carries no
             * tenancy. `OrderBillingDetail::where('email', ...)` had no store predicate and
             * cannot be given one on its own row: that table has no `store_id` column in any
             * migration. The model is also absent from `config/tenancy.php`'s `scoped_models`
             * and registers no `StoreScope`, so the global scope short-circuits in
             * `isActiveFor()` and adds no predicate for ANY principal -- the `Product` defect
             * from the project skill, not the `null_tenant => 'open'` one. `Order` is likewise
             * unscoped, by design.
             *
             * So a shopper who had bought as a guest from two tenants using one email address
             * had the OTHER tenant's orders listed here: reference, date, value, payment type
             * and status, on a page a merchant reaches with `Show Customer`. The ownership check
             * above does not constrain it -- it decides which CUSTOMER ROW may be opened, and
             * the branch then pivots on that customer's email. MEASURED before this predicate:
             * the foreign store's guest order appeared in the rendered table.
             *
             * `order_billing_details.email` is a plain nullable string with no unique constraint
             * and is taken from checkout input, so one address in two stores is the ordinary
             * case for any shopper who buys from two tenants -- not a contrived collision.
             *
             * THE PREDICATE IS THE CUSTOMER'S STORE, NOT `getCurrentStore()`, and that is the
             * substance of the fix rather than a style choice. The helper returns the hardcoded
             * literal `1` for a `super admin` with an empty `current_store`
             * (helper.php:636-637), and the operator deliberately SKIPS the customer store check
             * above -- so a `getCurrentStore()` predicate would render a store-7 customer
             * alongside the claim that they have no orders. That is a fabrication, not a
             * refusal, and it is the same trap F157 recorded. The customer row has already been
             * resolved and, for a merchant, already store-checked; its own `store_id` is the
             * authority on whose orders these are.
             *
             * An empty `store_id` matches NOTHING rather than everything: `where('store_id',
             * null)` becomes `whereNull` in Laravel, which would match every store-less order,
             * so it is refused explicitly.
             */
            $customerStoreId = (int) ($customer->store_id ?? 0);

            if ($customerStoreId === 0) {
                return redirect()->back()->with('error', __('Customer not found.'));
            }

            $users_id = Order::where('customer_id', $id)->where('store_id', $customerStoreId)->get();
            if ($users_id->count() == 0) {
                $customers = Customer::find($id);
                $orders_detail = OrderBillingDetail::where('email', $customers->email)->get()->toArray();
                $order_id = [];
                foreach ($orders_detail as $orders) {
                    $order_id[] = $orders['order_id'];
                }
                $orders = Order::whereIn('id', $order_id)
                    ->where('customer_id', 0)
                    ->where('store_id', $customerStoreId)
                    ->get();
            } else {
                $orders = Order::where('customer_id', $id)->where('store_id', $customerStoreId)->get();
            }
            return view('customer.view', compact('orders'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * NEW-customer-status-unauthorized.
     *
     * This had NO permission check, NO ownership check and NO null guard:
     *   $customer = Customer::find($request->customer_id);
     *   $customer->status = $request->status;
     *   $customer->save();
     * The route carries ['web','auth','xss','setlocate','verified'] (measured
     * from the real router, routes/web.php:950), so authentication was the only
     * control. Any logged-in web user -- including a staff member holding no
     * permissions at all -- could enable or DISABLE any customer account on the
     * platform, in any store, by sending one id. Disabling a customer locks a
     * shopper out of the store they bought from. The bare find() was also a
     * null dereference on an unmatched id.
     *
     * The predicate is copied from destroy() in this same controller rather
     * than invented, so there is one shape for customer ownership here:
     * 'Manage Customer' (a real seeded row -- app/Models/Utility.php:2821, NOT
     * an invented name), a platform-operator exemption so the operator is not
     * locked out, and customers.store_id compared against getCurrentStore().
     */
    public function customerStatus(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        // OPERATOR PATH FIRST, then the permission. The order is the fix, not a
        // style choice.
        //
        // Laratrust's isAbleTo() resolves purely through the role/permission
        // pivot tables and has NO super-admin bypass. makePlatformAdmin-shaped
        // operators hold no role, so checking the permission BEFORE the
        // operator exemption denies the platform operator outright -- which is
        // exactly the lockout the finance permissions suffered when all six
        // names resolved to "denied" for everybody. The seed-independent
        // operator path is the shape AuthServiceProvider's `finance` gate
        // (:80-83) and OrderPolicy::allow() already use.
        //
        // This also corrects destroy() below, which had the checks in the
        // other order and locked the operator out of customer deletion. Caught
        // by test_the_platform_operator_is_not_locked_out, which failed with
        // "The platform operator was locked out of customer administration."
        $isOperator = $user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;

        if (!$isOperator && !$user->isAbleTo('Manage Customer')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $customer = Customer::find($request->customer_id);

        if (!$customer) {
            return response()->json(['error' => __('Customer not found.')], 404);
        }

        if (!$isOperator) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($customer->store_id)
                || (int) $customer->store_id !== (int) $currentStore
            ) {
                // 404, not 403: a customer outside the caller's store is
                // indistinguishable from one that does not exist, so the
                // response does not confirm that an id is in use.
                return response()->json(['error' => __('Customer not found.')], 404);
            }
        }

        $customer->status = $request->status;
        $customer->save();

        return response()->json(['success' => __('Status change successfully.')]);
    }

    /**
     * NEW-customer-timeline-unauthorized.
     *
     * No permission check and no ownership check. Any logged-in web user could
     * read ANY customer's activity log, total spend and profile row in any
     * store by walking the id in the URL -- a cross-tenant disclosure of
     * shopper behaviour and lifetime value.
     *
     * 'Show Customer' is the permission this controller's own show() already
     * uses for the same data class, and it is a real seeded row
     * (app/Models/Utility.php:2822).
     */
    public function customerTimeline($ids)
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // Operator path first -- see customerStatus() above for why.
        $isOperator = $user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;

        if (!$isOperator && !$user->isAbleTo('Show Customer')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $customer = Customer::find($ids);

        if (!$customer) {
            return redirect()->back()->with('error', __('Customer not found.'));
        }

        if (!$isOperator) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($customer->store_id)
                || (int) $customer->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Customer not found.'));
            }
        }

        /*
         * P2.5-PKG-C (F-C-09). SCOPED TO THE CUSTOMER'S OWN STORE, which these three queries were
         * not. The ownership check above decides WHICH CUSTOMER may be opened; it says nothing
         * about whose activity is then listed, and `ActivityLog` is absent from `scoped_models`
         * even though it HAS a `store_id` column.
         *
         * WHAT THE FEED LEAKED IS NOT MERELY "ACTIVITY": `ActivityLog::order_entry()` writes
         * `remark` as JSON carrying `order_id`, `order_date`, `product`, `price` and
         * `payment_type`, so a merchant reading one of their OWN customers' timelines saw another
         * store's order references, products and PRICES. Measured in
         * CustomerTimelineTenancyTest.
         *
         * THE CUSTOMER'S OWN STORE, not `getCurrentStore()`, and that is deliberate for the reason
         * F-C-07 records: `getCurrentStore()` returns the literal 1 for a super admin with an
         * empty `current_store`, and this route explicitly admits the operator to ANY store's
         * customer -- so a `getCurrentStore()` predicate would have shown store 1's activity on a
         * store-7 customer's page. A fabrication is worse than a refusal.
         *
         * Fails closed: a customer with no store matches no activity rather than all of it.
         */
        $timelineStoreId = (int) ($customer->store_id ?? 0);

        $activityLog = $timelineStoreId > 0
            ? ActivityLog::where('store_id', $timelineStoreId)
                ->where('customer_id', $ids)
                ->groupBy('customer_id')
                ->first()
            : null;

        $logs = $timelineStoreId > 0
            ? ActivityLog::where('store_id', $timelineStoreId)->where('customer_id', $ids)->get()
            : collect();

        $totalSpend = ActivityLog::totalspend($ids, $timelineStoreId > 0 ? $timelineStoreId : null);

        $customer_data = Customer::find($ids);


        return view('customer.timeline', compact('logs', 'activityLog', 'totalSpend', 'customer_data'));
    }


    public function CustomFilter(Request $request)
    {
        $id = $request->customer_field;
        $data = [];
        if ($id == 'Name' || $id == 'Email') {
            $data['condition'] = Customer::$fields_status;
            $data['message']  = __("Customer name and email search filed");
        } else if ($id == 'Last active') {
            $data['condition'] = Customer::$fields_status1;
            $data['message']  = __("Customer last active search filed");
        } else if ($id == 'Total Spend' || $id == 'No. of Orders' || $id == "AOV") {
            $data['condition'] = Customer::$fields_status2;
            $data['message']  = __("Customer last active search filed");
        } else {
            $data['condition'] = [];
            $data['message']  = __("Customer condition search filed");
        }

        if ($id == 'Name') {
            $data['field_type'] = 'text';
        } else if ($id == 'Last active') {
            $data['field_type'] = 'date';
        } else if ($id == 'Email') {
            $data['field_type'] = 'email';
        } else {
            $data['field_type'] = 'number';
        }
        return response()->json($data);
    }

    public function CustomFilterData(Request $request)
    {
        /*
         * P2.5-PKG-C (F-C-06). THIS HANDLER CHECKED NOTHING -- no permission, no policy, no
         * operator limb -- while returning every customer in the caller's store with first and
         * last name, EMAIL, registration date, last-active date, order count and total spend.
         *
         * Reachable by any authenticated `web` principal: its route sits in the
         * `['auth','xss','setlocate','verified']` group, so a newly added staff user holding ZERO
         * customer permissions could issue the request and read the lot. Raised by the
         * tenant-authz review of the F-C-03 fix, and correctly NOT counted against that fix:
         * the gap was already reachable with any query parameter at all (any value misses all
         * five filter branches and falls through to `$query->get()`), so guarding the absent-key
         * case conferred no new capability. It is still a live gap, so it is closed here.
         *
         * `Manage Customer`, NOT A NEW PERMISSION NAME. Both siblings that render this data
         * already require exactly that -- `index()` and `customer_grid_view()`.
         *
         * THE SENTENCE THAT USED TO FOLLOW IS NOW FALSE AND IS CORRECTED RATHER THAN LEFT. It said
         * "this fragment is requested over ajax from `customer/grid.blade.php:265`, a page the
         * refused principal cannot open". **P2.5-R-07 DELETED THAT AJAX CALL.** Measured on the
         * current tree, `customer.filter.data` appears only in this route's declaration
         * (`routes/web.php:1193`), in comments, and in three test files -- no view, no script and
         * no module calls it. The grid was its last caller. Raised by the tenant-authz review of
         * that change, which correctly noted that a gate justified by a caller that no longer
         * exists is a gate the next reader may think is unnecessary.
         *
         * THE GATE ABOVE STILL HOLDS AND IS DELIBERATELY KEPT. This endpoint still renders the
         * whole store's customer roster -- names, EMAIL, registration date, last active, order
         * count and total spend -- so the permission is what stands between that roster and any
         * authenticated `web` principal. Retaining or retiring the route is a separate decision
         * with its own risk; it is recorded in the ledger, not folded into this slice.
         *
         * OPERATOR FIRST, which the two siblings omit. Laratrust has no super-admin bypass and the
         * platform operator holds no merchant role, so a bare `isAbleTo()` refuses the one
         * principal who legitimately reads across stores -- CLAUDE.md records this ordering as a
         * repeated source of defects. Through `UserPolicy::isOperator()` rather than a fourth
         * spelling of the comparison, as P1-SEC-11 criterion 3 asks.
         *
         * This changes WHO reaches the endpoint, so it is deliberately a refusal and not a
         * narrowing of the data: the tenant predicate below is unchanged.
         */
        $user = auth()->user();

        if ($user === null
            || (! \App\Policies\UserPolicy::isOperator($user) && ! $user->isAbleTo('Manage Customer'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $activitylog = ActivityLog::groupBy('customer_id')->where('store_id', getCurrentStore())->get();

        $requestData = $request->all();

        $query = Customer::where('store_id', getCurrentStore());
        /*
         * P2.5-PKG-C (F-C-03). READ ONCE, GUARDED, AND USED EVERYWHERE BELOW.
         *
         * MEASURED: `GET /customer-filter-data` with no query string answered HTTP 500,
         * `Undefined array key "customer_field"`. The route is called over ajax from
         * `customer/grid.blade.php:265` and that caller always sends the key, so a bare request is
         * what reaches it -- but an undefined-key fatal is never the right answer to a missing
         * parameter, and ONE of the six reads in this method already carried `?? null` with a
         * comment about a previous round of exactly this defect. The other five did not.
         *
         * Absent now means "no filter", which is what the unfiltered list already shows.
         *
         * Six reads, one variable, deliberately: guarding only the first would have left the next
         * five to throw, which is what the first attempt at this fix did -- the probe still
         * answered 500 and named the second site.
         */
        $customerField = $requestData['customer_field'] ?? null;

        /*
         * P2.5-R-06 -- THE SECOND COPY OF THE FILTER IS GONE, and that is the repair.
         *
         * WHAT WAS HERE: ~105 lines reimplementing CustomerDataTable::query()'s five filters, with
         * all three numeric faults present identically -- the AOV `'Equal' && $AOV =
         * $text_field_float` assignment, the duplicated `'Less Than'` branch testing `>`, and no
         * `'More Than'` branch for `No. of Orders` or `Total Spend`.
         *
         * AND IT HAD DRIFTED FURTHER THAN THAT. The three numeric branches DISCARDED the $query
         * built above and started again from a fresh `Customer::where('store_id',
         * getCurrentStore())`, through throwaway variables named `$queryyy`, `$queryss` and
         * `$queryes`. That was harmless only because `customer_field` is a single select, so a
         * numeric field and a text field can never both be in force -- an invariant nothing
         * asserted and the next filter option would have broken.
         *
         * The shared implementation narrows the query it is given and never rebuilds it, so that
         * accident cannot recur. The tenant predicate on $query above is untouched and remains the
         * only thing establishing tenancy here.
         */
        $query = CustomerListFilter::apply(
            $query,
            $customerField,
            $requestData['selected_name'] ?? null,
            $requestData['text_field'] ?? null
        );

        $customers = $query->get();

        $filter = view('customer.filter', compact('customers', 'activitylog'))->render();

        return response()->json($filter);
    }

    /**
     * P2.5-R-07 -- THE GRID'S FILTER NOW EXISTS SERVER-SIDE, which it never has.
     *
     * WHAT THIS METHOD WAS: a bare `Customer::where('store_id', ...)->paginate(12)` that read
     * none of `field_name`, `selected_name` or `text_field`. The screen's Apply control DID fire a
     * request -- measured -- but to `customer.filter.data`, whose answer is the LIST's `<tr>`
     * fragment, written into a container id this view does not contain. So the grid's filter had
     * never worked by any path: not through the browser, and not through the server either.
     *
     * ONE SEMANTICS FOR BOTH SCREENS. The filter is `CustomerListFilter::apply()`, the same call
     * `CustomerDataTable::query()` makes, so "Less Than" cannot mean one thing on the list and
     * another on the grid. Before P2.5-R-06 it meant three different wrong things.
     *
     * THE TENANT PREDICATE IS UNCHANGED and still comes from `getCurrentStore()`, never from the
     * request. `CustomerListFilter` takes an already-scoped Builder, has no store parameter and
     * reads no request, so it can only narrow what it is given.
     *
     * THE PERMISSION IS UNCHANGED -- `Manage Customer`, as before. This method is reachable
     * exactly as it was; the change is what it does with the query string, not who may call it.
     * (`index()` and this method both omit an operator limb, which `CustomFilterData()` carries.
     * That asymmetry is recorded as an open finding rather than widened here: adding an operator
     * path to a grid whose `getCurrentStore()` can resolve to a literal store 1 is a separate
     * question with a tenancy answer, and it is the kind of change that belongs in its own
     * reviewed slice.)
     *
     * `withQueryString()` IS THE OTHER HALF OF THE REPAIR. Without it, page 2 of a filtered grid
     * drops the filter and silently shows the unfiltered remainder -- which looks like a working
     * filter that lost its mind, and is the commonest way a GET filter half-ships.
     */
    public function customer_grid_view(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Customer')) {
            $query = Customer::where('store_id', getCurrentStore());

            $query = CustomerListFilter::apply(
                $query,
                $request->query('field_name'),
                $request->query('selected_name'),
                $request->query('text_field')
            );

            $customers = $query->paginate(12)->withQueryString();
            $customer_field = Customer::customer_field();
            return view('customer.grid', compact('customer_field','customers'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
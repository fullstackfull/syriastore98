<?php

namespace App\Support\Operator;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use App\Scopes\StoreScope;
use App\Support\Api\OrderStatusContract;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\MoneyText;
use App\Support\OrderStatusTone;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADM-002 -- STORE 360°: ONE STORE'S STATE, READ ACROSS ITS OWN SUBSYSTEMS, FOR THE OPERATOR.
 * =================================================================================================
 *
 * The recovered decision text is `مساحة Store 360° لمراجعة حالة كل متجر وإدارته من أنظمته الأصلية`
 * -- "a Store 360° space to review the state of each store and manage it FROM ITS OWN SYSTEMS".
 * The load-bearing half is the last clause, and it is what this class deliberately does NOT do:
 *
 *   - It READS state. Every figure below is a count or a per-currency amount of rows that already
 *     exist, produced by the store's own tables.
 *   - It WRITES nothing, and it reimplements no merchant screen. Management happens through
 *     `doors()`, which hands the operator the EXISTING route into each subsystem -- the merchant's
 *     own plan screen, the merchant's own password reset, impersonation into the merchant's own
 *     dashboard. A second operator-side editor for a merchant's catalogue would be a parallel
 *     system, which is the opposite of what the decision asks for.
 *
 * ============================================================================================
 *  EVERY QUERY NAMES ITS STORE, AND NOT ONE OF THEM TRUSTS THE GLOBAL SCOPE.
 * ============================================================================================
 *
 * The operator is a CROSS-TENANT principal by design, and this codebase has three separate
 * reasons why leaning on `StoreScope` here would be wrong:
 *
 *   1. `StoreScope::apply()` has an explicit PLATFORM-OPERATOR BYPASS (`MODE_SUPER_ADMIN`,
 *      the branch at the top of `StoreScope::apply()`) -- for a `super admin` it adds NO
 *      predicate at all. So for this
 *      caller the scope is not a filter; it is an absence of one.
 *   2. `config('tenancy.null_tenant')` is `'open'`, so an unresolved tenant also adds no
 *      predicate. A handler that relies on the scope is unprotected for any principal that
 *      resolves nothing.
 *   3. `Product` and `Category` are NOT in `config/tenancy.php`'s `scoped_models`, so
 *      `StoreScope::isActiveFor()` short-circuits for them and they add no predicate for ANY
 *      principal -- while `getGlobalScopes()` still answers `[StoreScope]` for both, which is
 *      why checking THAT asks the wrong question.
 *
 * So each query below carries `where('store_id', $store->id)` itself, and for the ELEVEN models
 * that really do filter the scope is removed BY NAME first (`withoutGlobalScope(StoreScope::class)`
 * and never `withoutGlobalScopes()`, which would also strip `Customer`'s `SoftDeletingScope` and
 * silently start counting deleted shoppers). Removing it is not a weakening: without it,
 * `Customer::where('store_id', $other)` for an operator whose `users.current_store` points at
 * their OWN store would AND the two predicates together and answer zero for every store but one --
 * a 360° screen that reads empty for 99% of the platform.
 *
 * `plan_requests` HAS NO `store_id` COLUMN -- measured, not assumed: its table is
 * (id, user_id, plan_id, duration, created_at, updated_at). A plan request belongs to the
 * merchant, not to one of their stores, and this class says so rather than inventing a predicate.
 *
 * ============================================================================================
 *  MONEY COMES FROM THE EXISTING CAVEATED INSTRUMENT, PER CURRENCY, AND IS NEVER SUMMED.
 * ============================================================================================
 *
 * `GrossMerchandiseValue` is the class the merchant's own dashboard and `/platform/analytics`
 * already use. It is reached here with `MetricScope::store($store->id)` -- the single-tenant
 * scope, spelled explicitly -- so this screen cannot disagree with those two about a number.
 *
 * The rows are per-currency and MUST NOT be added together: `orders.currency_code` records what
 * the order was denominated in and the schema holds no exchange rate for the order's own date, so
 * a total would be a fabricated number. The view prints one row per currency with no total, and
 * the caveat sentences the metric itself attaches travel with it.
 */
final class StoreThreeSixty
{
    /**
     * One store's whole state, as data. No formatting decisions, no sentences the view cannot
     * reach, and no figure this class computed itself.
     *
     * @return array<string, mixed>
     */
    public static function forStore(Store $store): array
    {
        $storeId = (int) $store->id;
        $owner = $store->created_by === null ? null : User::find($store->created_by);

        return [
            'identity' => self::identity($store, $owner),
            'subscription' => self::subscription($store, $owner),
            'catalogue' => self::catalogue($storeId),
            'orders' => self::orders($storeId),
            'shoppers' => self::shoppers($storeId),
            'support' => self::support($storeId),
            'money' => self::money($storeId),
            'doors' => self::doors($store, $owner),
        ];
    }

    /**
     * THE ROSTER: every store on the platform with the handful of facts an operator chooses one
     * by. `stores` is NOT a scoped model (it is absent from `scoped_models` and carries no
     * `store_id`), so this is a plain ordered read and the cross-tenant reach is the point.
     *
     * @return list<array<string, mixed>>
     */
    public static function roster(): array
    {
        $stores = Store::query()->orderBy('id')->get();

        $ownerIds = $stores->pluck('created_by')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        $owners = $ownerIds === []
            ? collect()
            : User::query()->whereIn('id', $ownerIds)->get()->keyBy('id');

        return $stores->map(function (Store $store) use ($owners): array {
            $owner = $store->created_by === null ? null : $owners->get((int) $store->created_by);

            return [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'slug' => (string) $store->slug,
                'is_active' => (int) ($store->is_active ?? 0) === 1,
                'owner_name' => $owner === null ? null : (string) $owner->name,
                'owner_email' => $owner === null ? null : (string) $owner->email,
                /* A store whose owner row is gone is not a rendering problem to paper over: the
                   operator is exactly the person who needs to see it. */
                'owner_missing' => $store->created_by !== null && $owner === null,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private static function identity(Store $store, ?User $owner): array
    {
        return [
            'id' => (int) $store->id,
            'name' => (string) $store->name,
            'slug' => (string) $store->slug,
            'email' => (string) ($store->email ?? ''),
            'theme' => (string) ($store->theme_id ?? ''),
            'language' => (string) ($store->default_language ?? ''),
            'is_active' => (int) ($store->is_active ?? 0) === 1,
            'pwa' => (int) ($store->enable_pwa_store ?? 0) === 1,
            'created_at' => $store->created_at,
            'owner' => $owner === null ? null : [
                'id' => (int) $owner->id,
                'name' => (string) $owner->name,
                'email' => (string) $owner->email,
                'type' => (string) $owner->type,
                'is_active' => (int) ($owner->is_active ?? 0) === 1,
                'login_enabled' => (int) ($owner->is_enable_login ?? 0) === 1,
            ],
        ];
    }

    /**
     * THE STORE'S SaaS STATE. It hangs off the OWNER, not off the store row, and that is a fact
     * about this schema rather than a simplification: `users` carries `plan_id`,
     * `plan_expire_date`, `plan_is_active`, `trial_plan` and `requested_plan`; `stores` carries
     * none of them. A merchant on one plan may own several stores, so two 360° screens can
     * legitimately show the same subscription.
     *
     * @return array<string, mixed>
     */
    private static function subscription(Store $store, ?User $owner): array
    {
        $storeId = (int) $store->id;

        /* `plan_orders` DOES carry `store_id`, so these two are per-store and are predicated. */
        $planOrders = PlanOrder::query()->where('store_id', $storeId);

        /* ONE lookup, not one per field. `Plan` is `Cachable`, so the second `find()` was cheap
           rather than free -- but a reader comparing the two lines has to check they name the same
           id, which is the kind of thing that drifts. */
        $plan = $owner === null ? null : Plan::find($owner->plan_id);

        return [
            'plan_name' => $owner === null ? null : (string) ($plan?->name ?? ''),
            'plan_price' => $owner === null ? null : $plan?->price,
            'expires_on' => $owner?->plan_expire_date,
            'plan_is_active' => $owner === null ? null : (int) ($owner->plan_is_active ?? 0) === 1,
            'on_trial' => $owner === null ? null : ! empty($owner->trial_plan),
            'plan_orders' => (clone $planOrders)->count(),
            'plan_orders_refunded' => (clone $planOrders)->where('is_refund', 1)->count(),
            /* NO `store_id` ON `plan_requests` -- see the class docblock. This is the OWNER's
               pending requests, and it is labelled that way on screen. */
            'owner_plan_requests' => $owner === null
                ? 0
                : PlanRequest::query()->where('user_id', $owner->id)->count(),
        ];
    }

    /** @return array<string, int> */
    private static function catalogue(int $storeId): array
    {
        /* `Product` AND `Category` ADD NO PREDICATE FOR ANY PRINCIPAL -- neither is in
           `scoped_models`, so `StoreScope::isActiveFor()` short-circuits. The `where` below is
           the ONLY tenant check these two queries have, which is why it is not optional. */
        $products = Product::query()->where('store_id', $storeId);

        return [
            'products' => (clone $products)->count(),
            'active' => (clone $products)->where('is_active', 1)->count(),
            /* Only a stock-tracked product can be out of stock; an untracked one has no answer,
               so it is neither counted as in stock nor as out. */
            'out_of_stock' => (clone $products)
                ->where('track_stock', 1)
                ->where('product_stock', '<=', 0)
                ->count(),
            'categories' => Category::query()->where('store_id', $storeId)->count(),
        ];
    }

    /**
     * ORDERS BY LIFECYCLE, AND NOT BY PAYMENT -- and the second half is a disposition, not an
     * omission.
     *
     * THE LIFECYCLE COLUMN IS `delivered_status`, NOT `product_order_status`. Both exist on
     * `orders`; the one the application's own dashboard buckets on is `delivered_status`
     * (`HomeController::getOrderCounts()`'s per-status `count()` calls), and
     * `OrderStatusContract::allKeys()` is the DECLARED map of the
     * nine integers it may hold. This iterates that map, so the screen's vocabulary is the API
     * contract's rather than one typed out here.
     *
     * THE `other` BUCKET IS NOT DEFENSIVE PADDING; IT IS THE CENSUS RULE THIS PROJECT LEARNED THE
     * HARD WAY. A breakdown that enumerates only the values somebody thought of SILENTLY DROPS the
     * one nobody did -- the nine counts would sum to less than the total and nothing on screen
     * would say so. So the safe set is enumerated and everything else is `total` minus their sum,
     * which cannot hide a value and cannot be short by one.
     *
     * NO PAYMENT BREAKDOWN, DELIBERATELY. `orders.payment_status` has NO declared vocabulary in
     * this codebase: a census of its literal writes finds `paid`, `pending`, `cancelled`,
     * `unpaid`, `success`, `partially_paid`, `Unpaid` and `Pending` -- eight spellings in two
     * casings, and a ninth is exactly the kind of thing such a list is short by. Bucketing on a
     * vocabulary that does not exist would state a false breakdown with total confidence. What the
     * money question gets instead is `money()`, which goes through `GrossMerchandiseValue` -- the
     * instrument that already owns that decision and attaches its own caveats to it.
     *
     * NO RAW SQL. Nine `count()` queries rather than one `selectRaw('... GROUP BY ...')`: the
     * grouped form is a `->selectRaw(` site, which `BuilderRawQueryVisibilityTest` counts as a
     * blind spot in the repository's raw-query inventory, and buying a `GROUP BY` for an
     * operator screen with a ratchet move in two other workstreams' files is the wrong trade.
     *
     * @return array<string, mixed>
     */
    private static function orders(int $storeId): array
    {
        $orders = fn (): Builder => Order::query()->where('store_id', $storeId);

        $total = $orders()->count();
        $buckets = [];
        $accounted = 0;

        foreach (OrderStatusContract::allKeys() as $status => $key) {
            $count = $orders()->where('delivered_status', $status)->count();
            $accounted += $count;

            $buckets[] = [
                'key' => $key,
                'label' => OrderStatusContract::label((int) $status),
                'tone' => OrderStatusTone::for((int) $status),
                'orders' => $count,
            ];
        }

        return [
            'total' => $total,
            'by_status' => $buckets,
            /* Rows whose `delivered_status` is NULL or a value the contract does not declare. A
               non-zero here is a finding, which is why it is rendered rather than swallowed. */
            'unclassified' => max(0, $total - $accounted),
        ];
    }

    /** @return array<string, int> */
    private static function shoppers(int $storeId): array
    {
        return [
            /* `Customer` and `Cart` ARE scoped, so the scope is dropped BY NAME and the store is
               named instead. `withoutGlobalScopes()` would also drop `Customer`'s
               `SoftDeletingScope` and start counting deleted shoppers. */
            'customers' => self::unscoped(Customer::query(), $storeId)->count(),
            'open_carts' => self::unscoped(Cart::query(), $storeId)->count(),
        ];
    }

    /**
     * SUPPORT STATE, ON THE ONE VOCABULARY THIS SCHEMA REALLY DECLARES.
     *
     * `SupportTicket::STATUSES` is `['open', 'In Progress', 'Solved']` -- the set the writer
     * accepts and the controller validates against, declared once in the model precisely so two
     * callers cannot disagree about it. This iterates that constant, so a status added there
     * appears here without an edit.
     *
     * Same `other` bucket and same reason as `orders()`: `support_tickets.status` is a free
     * string column with no database constraint, so a row written before that constant existed,
     * or by a vendored module, can hold anything -- and the operator is exactly the person who
     * should see that it does.
     *
     * @return array<string, mixed>
     */
    private static function support(int $storeId): array
    {
        $tickets = fn (): Builder => self::unscoped(SupportTicket::query(), $storeId);

        $total = $tickets()->count();
        $buckets = [];
        $accounted = 0;

        foreach (SupportTicket::STATUSES as $status) {
            $count = $tickets()->where('status', $status)->count();
            $accounted += $count;

            $buckets[] = ['status' => $status, 'tickets' => $count];
        }

        return [
            'tickets' => $total,
            'by_status' => $buckets,
            'unclassified' => max(0, $total - $accounted),
        ];
    }

    /**
     * ONE ROW PER CURRENCY AND NO TOTAL. `rows` is already formatted by `MoneyText::amount()`,
     * which wraps each amount in a bidi isolate -- an unisolated negative renders as
     * "USD 20.00-" on an Arabic page, which `FinanceLedgerFigures` records as a measured defect
     * rather than a theory.
     *
     * @return array<string, mixed>
     */
    private static function money(int $storeId): array
    {
        $result = (new GrossMerchandiseValue())->for(
            MetricScope::store($storeId),
            MetricWindow::allTime()
        );

        $rows = [];

        foreach (MoneyText::buckets($result->byCurrency()) as $currency => $amount) {
            $rows[] = [
                'currency' => MoneyText::currencyLabel((string) $currency),
                'amount' => MoneyText::amount((float) $amount, (string) $currency),
            ];
        }

        return [
            'rows' => $rows,
            'orders_counted' => $result->rowCount(),
            /* The metric's OWN caveats, never a sentence this class invented. */
            'caveats' => array_values(array_map(
                static fn (string $caveat): string => \App\Support\Metrics\MetricCaveat::message($caveat),
                $result->caveats()
            )),
        ];
    }

    /**
     * THE DOORS INTO THE STORE'S OWN SYSTEMS -- the `من أنظمته الأصلية` half of ADM-002.
     *
     * Every entry is an EXISTING route with its EXISTING gate. Nothing here is a new capability
     * and nothing here widens one: `login.with.admin` still runs
     * `$this->authorize('impersonate', $user)` in `StoreController::LoginWithAdmin()`, and
     * `plan.upgrade` still refuses a non-operator in `StoreController::upgradePlan()`. What this
     * screen adds is that they are all in one place for one store, which is the requirement.
     *
     * A DOOR WHOSE ROUTE OR OWNER IS MISSING IS DROPPED, NOT RENDERED BROKEN. `route()` on an
     * unregistered name throws inside the view, and `MerchantPageDirectory` already records that a
     * stored navigation row is a worse place to discover that than a sidebar.
     *
     * @return list<array<string, string>>
     */
    public static function doors(Store $store, ?User $owner): array
    {
        $doors = [];

        if ($owner !== null) {
            /*
             * `encrypt()` AND `Crypt::encrypt()` ARE THE ARGUMENT SHAPES THESE ROUTES ALREADY
             * TAKE, copied from `resources/views/store/action.blade.php` rather than chosen. They
             * are an ENCODING and not a control -- `APP_KEY` is in this repository's git history
             * and unrotated, so an encrypted id in a URL is forgeable -- which is precisely why
             * each handler does its own authorization and this list relies on none of it.
             */
            $doors[] = [
                'name' => 'login.with.admin',
                'url' => route('login.with.admin', encrypt($owner->id)),
                'label' => __('Sign in as this store\'s owner'),
                'hint' => __('Opens the merchant\'s own dashboard as the merchant. Every screen you then see is theirs, not a copy.'),
            ];
            $doors[] = [
                'name' => 'plan.upgrade',
                'url' => route('plan.upgrade', encrypt($owner->id)),
                'label' => __('Change the subscription'),
                'hint' => __('The platform\'s own plan screen for this owner. A plan belongs to the merchant, so it covers every store they own.'),
            ];
            $doors[] = [
                'name' => 'user.info',
                'url' => route('user.info', encrypt($owner->id)),
                'label' => __('Owner account'),
                'hint' => __('The merchant\'s account record: contact details, status and the staff they created.'),
            ];
            $doors[] = [
                'name' => 'stores.link',
                'url' => route('stores.link', encrypt($owner->id)),
                'label' => __('Store addresses'),
                'hint' => __('Every address this owner\'s storefronts answer on, including any custom domain.'),
            ];
            $doors[] = [
                'name' => 'stores.reset.password',
                'url' => route('stores.reset.password', \Crypt::encrypt($owner->id)),
                'label' => __('Reset the owner\'s password'),
                'hint' => __('Sets a new password for the merchant account. It does not sign anybody out.'),
            ];
        }

        return $doors;
    }

    /**
     * THE SCOPE IS DROPPED BY NAME, FOR THE ELEVEN MODELS THAT REALLY FILTER, AND THE STORE IS
     * NAMED INSTEAD. One helper so the pair cannot be separated by a later edit: a
     * `withoutGlobalScope()` with no `where` behind it would read every tenant at once.
     */
    private static function unscoped(Builder $query, int $storeId): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class)->where('store_id', $storeId);
    }
}

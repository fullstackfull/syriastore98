<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use App\Models\Utility;
use App\Models\{Customer, Country, Order, PlanOrder, Plan, PlanCoupon, PlanRequest, Store, Setting, User, OrderBillingDetail, PixelFields, Page, Cart, City, DeliveryAddress, State};
use App\Models\Faq;
use App\Models\Category;
use App\Models\BlogCategory;
use App\Models\Blog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\{FlashSale, ProductQuestion, Testimonial, TaxOption};
use Qirolab\Theme\Theme;
use App\Http\Controllers\Api\ApiController;
use Shetabit\Visitor\VisitorFacade as Visitor;
use App\Facades\ModuleFacade as Module;
use App\Models\AddOnManager;
use App\Models\ProductBrand;
use App\Http\Controllers\OfertemagController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;

class HomeController extends Controller
{
    public function __construct(Request $request)
    {

        if (!file_exists(storage_path() . '/installed')) {
            header('location:install');
            exit;
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function Landing()
    {
        if (auth()->user()) {
            return redirect('dashboard');
        }
        $uri = url()->full();
        $segments = explode('/', str_replace('' . url('') . '', '', $uri));
        $segments = $segments[1] ?? null;
        $local = parse_url(config('app.url'))['host'];
        // Get the request host
        $remote = request()->getHost();
        // Get the remote domain
        // remove WWW
        $remote = str_replace('www.', '', $remote);
        // P1-SEC-29: only a row whose own store has the matching flag on counts (App\Services\HostClaims).
        $subdomain = \App\Services\HostClaims::row('subdomain', $remote);
        $domain = \App\Services\HostClaims::row('domains', $remote);

        $enable_subdomain = '';
        $enable_domain = '';

        if ($subdomain || $domain) {
            if ($subdomain) {
                $enable_subdomain = Setting::where('name', 'enable_subdomain')->where('value', 'on')->where('store_id', $subdomain->store_id)->first();

            }

            if ($domain) {
                $enable_domain = Setting::where('name', 'enable_domain')->where('value', 'on')->where('store_id', $domain->store_id)->first();
            }
        }
        if ($enable_domain || $enable_subdomain) {

            if ($subdomain) {
                $enable_subdomain = Setting::where('name', 'enable_subdomain')->where('value', 'on')->where('store_id', $subdomain->store_id)->first();
                if ($enable_subdomain) {
                    $admin = User::find($enable_subdomain->created_by);

                    if ($enable_subdomain->value == 'on' && $enable_subdomain->store_id == $admin->current_store) {
                        $store = Store::find($admin->current_store);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } elseif ($enable_subdomain->value == 'on' && $enable_subdomain->store_id != $admin->current_store) {
                        $store = Store::find($enable_subdomain->store_id);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } else {
                        return $this->storeSlug($segments);
                    }
                }
            }

            if ($domain) {
                $enable_domain = Setting::where('name', 'enable_domain')->where('value', 'on')->where('store_id', $domain->store_id)->first();

                if ($enable_domain) {
                    $admin = User::find($enable_domain->created_by);

                    if ($enable_domain->value == 'on' && $enable_domain->store_id == $admin->current_store) {
                        $store = Store::find($admin->current_store);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } elseif ($enable_domain->value == 'on' && $enable_domain->store_id != $admin->current_store) {
                        $store = Store::find($enable_domain->store_id);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } else {
                        return $this->storeSlug($segments);
                    }
                }
            }
        } else {
            $settings = getSuperAdminAllSetting();
            if (isset($settings['display_landing']) && $settings['display_landing'] == 'on') {
                $this->ensureLandingPageInstalled();

                return view('landing-page::layouts.landingpage');
            } else {
                return redirect('login');
            }
        }
    }

    /**
     * F215 — INSTALL THE LANDING MODULE ONCE, NOT ON EVERY ANONYMOUS REQUEST.
     * =============================================================================================
     *
     * `Landing()` used to call `package:migrate LandingPage` and then `package:seed LandingPage`
     * unconditionally, on every request that reached the landing branch. That branch is the ROOT
     * ROUTE — `routes/web.php:393`, `Route::any('/')` with `->middleware('setlocate')` and nothing
     * else: no `auth`, no `throttle`, and `any` so a bare GET reaches it with no CSRF token. And
     * `display_landing => 'on'` is the seeded default (`database/seeders/DefaultSetting.php:28`),
     * so this was the shipped configuration rather than an unusual one.
     *
     * WHY THAT WAS A PAYMENT DEFECT RATHER THAN A PERFORMANCE ONE.
     * `LandingPageDatabaseSeeder:19` calls `PermissionTableSeeder`, whose **first** statement
     * (`:22`) is `Artisan::call('cache:clear')` — a `Cache::flush()` over the whole default store.
     * `GuardsGatewayReturn::claimGatewayReturn()` takes its gateway-return replay claim in that
     * store with **`Cache::add()`**, and `add()` on a missing key returns **true**, so an erased
     * claim is not a missing record the control notices: it is an UN-TAKEN claim, indistinguishable
     * from a first arrival (measured on MariaDB in F211). The claim carries a 48-hour TTL precisely
     * so a late replay cannot slip through.
     *
     * `PaymentController::getProductStatus()` — reached by `store.payment.status`, which
     * `app/Services/PaymentService.php` names **48 times** as a return, cancel or callback URL
     * across the gateway catalogue — takes that claim at `PaymentController.php:2923` and makes no
     * signature or verification call of its own, so the claim is that handler's only replay
     * control. `releaseGatewayReturnClaim()` has exactly one call site in all of `app/`
     * (`NMIPayController.php:1526`), so nothing else touches those keys either. A stranger hitting
     * the home page therefore cleared the replay claims for every gateway return in flight,
     * platform-wide, for free, as often as they liked.
     *
     * `packages/workdo/` must not be edited, so the seeder keeps its `cache:clear`; what changes is
     * that a request no longer invokes it.
     *
     * WHAT THE GUARD IS, AND THE TRADE IT MAKES — STATED RATHER THAN HIDDEN.
     * The calls are legitimate exactly once: a brand-new deployment whose first visitor needs the
     * module's tables and rows. So each is gated on its own precondition:
     *
     *   - `package:migrate` runs only while the module's primary table is absent.
     *   - `package:seed` runs only while that table holds no rows. `LandingPageDataTableSeeder:98`
     *     writes `LandingPageSetting` with `updateOrCreate`, so a populated table is exactly the
     *     evidence that the seed has already run.
     *
     * **THE TRADE:** a future module update that adds a table will no longer be migrated by an
     * anonymous page view. That is the correct expectation — migrations belong to a deployment step,
     * and relying on a stranger's request to run them is the defect, not the feature — but it IS a
     * behaviour change, and an operator upgrading the LandingPage module must run
     * `php artisan package:migrate LandingPage` as part of the deploy. Nothing here touches
     * production, and the fresh-install path is asserted by
     * `AnonymousLandingCacheFlushTest::test_a_fresh_install_is_still_seeded_on_the_first_visit`.
     *
     * The table name is NOT guessed: `LandingPageSetting`'s own table, created by
     * `2023_06_05_043450_create_landing_page_settings_table.php`.
     */
    private function ensureLandingPageInstalled(): void
    {
        $table = 'landing_page_settings';

        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            Artisan::call('package:migrate LandingPage');
        }

        /* Re-asked rather than assumed: if the migrate could not create it, there is nothing to
           seed and the view will report its own failure, which is more honest than seeding into a
           table that is not there. */
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return;
        }

        if (! DB::table($table)->exists()) {
            Artisan::call('package:seed LandingPage');
        }
    }

    public function index()
    {
        $user = auth()->user();
        Utility::userDefaultData($user->id);

        /*
         * ADM-001 / GAP-231 -- ONE DEFINITION OF THE OPERATOR, AND THIS LINE WAS THE SECOND ONE.
         *
         * This read `$user->type == 'super admin'` -- a plain PHP string comparison -- while
         * `handleRegularUser()` immediately below asks `UserPolicy::isOperator()`. Two definitions
         * on ONE request path, and `GAP-231` records that as a measured property of the dashboard
         * this requirement is about.
         *
         * THEY DISAGREE FOR `'super admin '`, AND THE DATABASE AGREES WITH THE HELPER.
         * `utf8mb4_unicode_ci` is a PAD SPACE collation, so a TRAILING space is ignored: that row
         * IS the operator to every SQL comparison in the product, to `PlatformOperatorOnly` and to
         * `User::isPlatformAdmin()`. It was a stranger only to this line -- which sent it to
         * `handleRegularUser()`, where `isOperator()` then answered TRUE, skipping the
         * storeless-merchant logout guard and every `if (! $isOperator)` figure block, and drew the
         * MERCHANT dashboard, empty, for the platform operator.
         *
         * `isOperator()` USES `rtrim`, NOT `trim`, DELIBERATELY: PAD SPACE does not fold a LEADING
         * space, so `' super admin'` must stay a non-operator -- the opposite failure and a worse
         * one. `OperatorDashboardOneOperatorDefinitionTest` drives all four spellings, including
         * that one, so a later `trim()` here fails a test instead of granting the platform
         * dashboard to a row the engine refuses.
         *
         * LATENT RATHER THAN LIVE, established and not assumed: the only writers of `users.type`
         * are the seeder's exact literal and `UserController`'s role-name path, which normalises
         * before refusing the reserved names. It needs a hand-edited row. Converted anyway -- the
         * cost is one call, and a command dashboard that cannot agree with itself about who is
         * reading it is not the `لوحة قيادة شاملة` the decision asks for.
         */
        if (\App\Policies\UserPolicy::isOperator($user)) {
            Utility::defaultEmail();
            $data = $this->handleSuperAdmin($user);

            return view('superadmin.dashboard', $data);
        } else {
            $data = $this->handleRegularUser($user);

            /*
             * GAP-013. `handleRegularUser()` answers a RESPONSE, not a data array, when the
             * merchant resolves no store -- see the guard at its `$store = getStoreById(...)`
             * line and `StorelessMerchantDashboardTest` for why the page cannot be rendered for
             * that principal at all. Handing a Response to `view()` would be a second fault on
             * the same path, so the branch is explicit rather than implied.
             */
            if ($data instanceof \Symfony\Component\HttpFoundation\Response) {
                return $data;
            }

            return view('dashboard', $data);
        }
    }

    private function handleSuperAdmin($user)
    {
        /*
         * PLAIN DATA, NOT ATTRIBUTES ON THE AUTHENTICATED MODEL.
         *
         * These three were written as `$user['total_user'] = ...` -- onto the very `User`
         * instance the session guard holds, as attributes `users` has no column for. That is
         * harmless until anything SAVES the user later in the request, and something did:
         * `getOrderChart()` signed out an operator with no resolvable store, `logout()` cycles the
         * remember token, and that save became `UPDATE users SET total_user = ...` -- HTTP 500 on
         * the operator's landing page.
         *
         * NOT A DISCOVERY OF THIS CHANGE. The 500 and its cause were already written down as an
         * open finding in `OperatorImplicitStoreFinancialGuardTest::
         * test_the_refusal_does_not_strand_the_operator`, with an instruction to turn its
         * assertion round when somebody repaired it. Repaired 2026-11-22, that assertion turned
         * round, and `OperatorDashboardWithoutAStoreTest` holds the repair.
         */
        $platformTotals = [
            'stores' => $user->countCompany(),
            'orders' => PlanOrder::total_orders(),
            'plans' => Plan::total_plan(),
        ];
        $chartData = $this->getOrderChart(['duration' => 'week']);
        $topAdmins = $user->createdAdmins()
            ->with('stores')
            ->withCount('stores')
            ->orderBy('stores_count', 'desc')
            ->limit(5)
            ->get();

        $plan_order = Plan::most_purchese_plan();
        /*
         * ONE QUERY FOR THE COUPON RANKING, NOT ONE PER COUPON -- AND NOT A CACHED AGGREGATE.
         *
         * This was `PlanCoupon::get()` and then `$coupon->used_coupon()` inside a `foreach`.
         * `used_coupon()` is `hasMany(PlanUserCoupon::class, ...)->count()` -- it returns an int,
         * not a relation, so it runs its own `select count(*)` and cannot be eager-loaded.
         * MEASURED at exactly 12 queries against `plan_user_coupons` for 12 coupons, on every load
         * of the operator's landing page, unbounded by the number of coupons the platform has.
         *
         * THE FIRST FIX FOR THAT WAS `PlanCoupon::withCount('planUserCoupons')`, AND IT WAS WRONG.
         * `PlanCoupon` is `Cachable`, so a `withCount` aggregate is cached under the model being
         * queried -- `plan_coupons`. A redemption writes `plan_user_coupons`, which invalidates
         * that model's cache and NOT this one, so the ranking kept naming the previous winner.
         * MEASURED on MySQL with the array store: after two further redemptions moved the lead,
         * `withCount` still answered the old coupon at its old count, while the `used_coupon()` it
         * replaced answered correctly. The old code was accidentally FRESH because its count was a
         * query on `PlanUserCoupon` itself.
         *
         * So the ranking is grouped on `PlanUserCoupon` -- THE TABLE THE REDEMPTIONS ARE WRITTEN
         * TO, which is the part that fixes it. A query on that model caches under
         * `plan_user_coupons`, and a redemption write invalidates exactly that, so the figure
         * follows the data for the same reason the original loop did.
         *
         * `toBase()` IS NOT WHAT MAKES IT FRESH -- measured, because a mutation that removed it
         * SURVIVED `PlanCouponRankingFreshnessTest`: the tag would be `plan_user_coupons` either
         * way. It is kept for three other reasons, the third found by independent review:
         *   - it avoids hydrating an Eloquent `PlanUserCoupon` out of a row holding only
         *     `coupon_id` and a count;
         *   - it is the shape `PlatformAnalyticsFigures::topStoresByPageViews()` already uses for
         *     a ranking on this screen;
         *   - `CacheKey::make()` composes table, model, columns, wheres, havings, with-models,
         *     orderBy, offset, limit and bindings, and has NO GROUP BY COMPONENT. Two aggregates
         *     over the same table with the same select list and ordering but different `groupBy`
         *     would therefore collide on one cache key. There is no second such query today, so
         *     this is latent -- but it is a reason to keep `toBase()` rather than treat it as
         *     removable.
         *
         * HOW BAD THE STALENESS WAS IN PRODUCTION, stated so nobody reads the measurement above as
         * a production data-integrity incident. `Caching::cache()` applies tags only for a
         * TaggableStore. `ArrayStore` is one and the suite uses it (`phpunit.xml`);
         * `DatabaseStore` is NOT, and production runs `CACHE_STORE=database`, where
         * `config/laravel-model-caching.php` records that every model save flushes the whole
         * store. So the stale window was UNBOUNDED IN THE SUITE and closed in production on the
         * next save of any `Cachable` model. Still wrong, still worth fixing; not an incident.
         *
         * `toBase()` drops Eloquent global scopes, which is safe here and was checked rather than
         * assumed: `PlanUserCoupon` has NO global scopes and is not in `config/tenancy.php`'s
         * `scoped_models`, as these SaaS tables are platform-level and this handler is reached only
         * by the operator. `Plan::most_purchese_plan()` does the same for the card beside this one,
         * so the two figures on this screen are now the same shape.
         *
         * `used_coupon()` IS UNTOUCHED. It is called from about 120 sites -- every payment
         * controller's exhaustion check -- and `PlanCouponRefusalPersistsNothingTest` and
         * `verify-plan-coupon-refusal-ordering-on-mysql.php` both pin its platform-wide meaning.
         *
         * BOTH SEMANTICS OF THE ORIGINAL ARE PRESERVED, and both are pinned by
         * `OperatorDashboardCardLabelsTest`:
         *   - A coupon nobody has redeemed never wins. The loop started `$maxValue = 0` and used
         *     `>`, so a zero count never set a name; a coupon with no rows is not in the GROUP BY
         *     at all, which is the same outcome by construction.
         *   - A tie goes to the lowest id. `>` never displaces an equal count and `get()` returns
         *     primary-key order, so the FIRST of a tie won; hence `orderBy('coupon_id')`.
         * `PlanCouponRankingFreshnessTest` pins the staleness this replaced.
         */
        $mostRedeemed = \App\Models\PlanUserCoupon::query()
            ->selectRaw('coupon_id, COUNT(*) as redemptions')
            ->groupBy('coupon_id')
            ->orderByDesc('redemptions')
            ->orderBy('coupon_id')
            ->limit(1)
            ->toBase()
            ->first();

        $couponName = $mostRedeemed === null
            ? ''
            : (string) (PlanCoupon::find((int) $mostRedeemed->coupon_id)?->name ?? '');

        /*
         * P05-14 / P05-19 (GAP-162, GAP-167). THE ONE DELIBERATELY CROSS-TENANT MONEY FIGURE ON
         * THIS SCREEN, AND IT NOW SAYS SO WHERE IT IS READ.
         *
         * `MetricScope::stores()` with every store id: the breadth is an argument at the call
         * site, as it is for every Phase 05 figure, instead of the absence of a predicate on a bare
         * `Order` query. Nothing here is an authorization boundary. This handler is reached only
         * for the platform operator -- `index()` dispatches on `users.type`, and
         * `OperatorDashboardTopStoresTest` holds a merchant out.
         *
         * WHAT THE OLD QUERY GOT WRONG is recorded on `PlatformAnalyticsFigures::
         * topStoresByOrderValue()`: it ranked stores across currencies by the raw number, and its
         * view printed every total in the signed-in operator's own store's currency.
         *
         * An empty platform is answered here because a scope with no store is refused by
         * construction: it would compile to "0 = 1" and read as stores that sold nothing.
         */
        $everyStoreId = \App\Models\Store::query()->orderBy('id')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $topStoresByCurrency = $everyStoreId === []
            ? []
            : \App\Support\Metrics\PlatformAnalyticsFigures::topStoresByOrderValue(
                \App\Support\Metrics\MetricScope::stores($everyStoreId)
            );

        /*
         * P05-19 (GAP-167). THE OTHER CROSS-TENANT RANKING ON THIS SCREEN, declared the same way.
         * This was three lines that pulled the store id of EVERY row in the visit log into PHP and
         * counted it there -- no predicate, no bound, on every load of this page. What it got
         * wrong is recorded on `PlatformAnalyticsFigures::topStoresByPageViews()`.
         */
        $popularStores = $everyStoreId === []
            ? []
            : \App\Support\Metrics\PlatformAnalyticsFigures::topStoresByPageViews(
                \App\Support\Metrics\MetricScope::stores($everyStoreId)
            );
        $plan_requests = PlanRequest::count();

        $data = compact('user', 'platformTotals', 'chartData', 'couponName', 'plan_order', 'plan_requests', 'topStoresByCurrency', 'popularStores', 'topAdmins');
        return $data;
    }

    private function handleRegularUser($user)
    {
        $todayStart = Carbon::today();
        $todayEnd = Carbon::now();
        $yesterdayStart = Carbon::yesterday();
        $yesterdayEnd = Carbon::yesterday()->endOfDay();
        $productQuery = Product::where('product_type', null)->where('store_id', getCurrentStore());
        $orderQuery = Order::where('store_id', getCurrentStore());

        $totalproduct = (clone $productQuery)->count();
        $today_product = (clone $productQuery)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $productPer = $this->calculatePercentageToday($today_product, $totalproduct);

        $totle_order = (clone $orderQuery)->count();
        $customerQuery = Customer::where('store_id', getCurrentStore());
        $totle_customers = (clone $customerQuery)->where('store_id', getCurrentStore())->count();
        $today_customers = (clone $customerQuery)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $customerPer = $this->calculatePercentageToday($today_customers, $totle_customers);

        $totle_cancel_order = (clone $orderQuery)->where('delivered_status', 2)->count();

        /*
         * F257 — OWNER DECISION: THE ACTIONABLE ORDER-VALUE FIGURE EXCLUDES CANCELLED ORDERS.
         *
         * WHAT THIS REPLACED, AND WHY IT EXCLUDED ALMOST NOTHING. The predicate was
         *
         *     where(delivered_status != 2 AND delivered_status != 3)
         *     OR  (return_status != 2)
         *
         * and the two limbs are joined by **OR**. A cancelled order has `delivered_status = 2`, so
         * it fails the first group — and `return_status` defaults to `0`, so `0 != 2` is TRUE and
         * the second limb admits it anyway. The only orders the filter could exclude were those
         * whose return has been APPROVED *and* which are also cancelled or returned by fulfilment
         * status. In practice the figure was every order's total, cancellations included, while
         * reading as though it excluded them.
         *
         * NOW: one AND-ed predicate, cancellations out.
         *
         * **RETURNS (status 3) ARE STILL INCLUDED, and that is a deliberate non-decision.** The
         * dead first group above shows its author intended to exclude them too, and there is a case
         * for it — a returned order's money came back just as a cancelled one's never arrived. But
         * the owner's F257 decision names CANCELLED orders and nothing else, and widening it here
         * would be inventing the next decision rather than implementing this one. Recorded as an
         * open question in the ledger instead.
         *
         * **NOTHING FINANCIAL CHANGES.** This is a displayed metric: no settlement, no ledger, no
         * transaction status, no merchant balance. The cancelled orders themselves are untouched and
         * still counted by `$totle_cancel_order` and by the status breakdown, so the money is not
         * hidden — it is attributed to the bucket that describes it.
         *
         * **PER-CUSTOMER LIFETIME SPEND IS A DIFFERENT REPORT AND IS NOT TOUCHED.**
         * `Api\DashboardController` computes `$totalSpend` per customer over every order including
         * cancellations. That answers "what has this customer spent with you", which is a different
         * question with a different right answer, and the owner's instruction is to preserve such a
         * report with an accurate label rather than silently change its meaning.
         */
        /*
         * P5-R7-C. THE SAME PREDICATE, BUT NO LONGER ADDING CURRENCIES TOGETHER.
         *
         * This was `->sum('final_price')` -- one ungrouped aggregate with no reference to
         * `orders.currency_code` -- and `overview.blade.php` printed it through
         * `currency_format_with_sym(..., getCurrentStore())`, which stamps the STORE's own
         * `defult_currancy_symbol` over whatever came back. A store that had taken 100 EUR and
         * 50 USD was shown 150 behind the euro sign, and the hint said the figure was "in this
         * store's currency" -- which for that store is false. Worse than the category and brand
         * cards ANA-TOP fixed: those were silent, this one asserted the untrue thing.
         *
         * `GrossMerchandiseValue(includeCancelled: false)` applies
         * `(delivered_status IS NULL OR delivered_status != 2)`, and `ORDER_STATUS_CANCEL` is 2 --
         * so the F257 exclusion is preserved exactly, and the NULL limb makes it strictly more
         * inclusive than the bare `!=` was. `MetricWindow::allTime()` adds no date predicate, so
         * the tile keeps its all-time meaning and drops no order for a null `order_date`.
         *
         * THE RESULT IS NOT TOTALLED HERE. `MetricResult::total()` throws
         * `MixedCurrencyException` when the rows are not single-currency, which would make this
         * dashboard a 500 on exactly the stores that have the defect. The view is handed the
         * result and asks it what it can honestly say.
         */
        $ssStoreId = (int) getCurrentStore();

        /* The store's OWN currency, NORMALISED. `defult_currancy` is merchant-written and owner
           decision D-N34 leaves existing rows alone, so the stored value can be a symbol or
           lower case rather than a code (FIN-SYM). Reading an unnormalised value would name a
           bucket that holds nothing -- printing 0.00 -- and then list the store's own currency
           back to the merchant as "another currency". */
        $storeCurrencyCode = \App\Support\Money\CurrencySymbol::code(
            \App\Support\Money\OrderCurrencyStamp::configuredCodeFor($ssStoreId)
        );

        /*
         * A MERCHANT WITH NO STORE REACHES THIS LINE. `getCurrentStore()` is null for that
         * principal, and `MetricScope::store()` REFUSES a non-positive id rather than widening
         * to every tenant -- so an unguarded call throws here, ABOVE `getOrderChart()`, whose
         * own defensive `auth()->logout()` is this codebase's documented answer for them
         * (`OperatorDashboardWithoutAStoreTest`). Guard the call; the view already has a branch
         * for a figure it cannot have.
         */
        $totalRevenueResult = $ssStoreId > 0
            ? (new GrossMerchandiseValue(false))->for(
                MetricScope::store($ssStoreId),
                MetricWindow::allTime()
            )
            : null;

        /*
         * FOLD THE UNRECORDED ROWS IN BEFORE READING ONE BUCKET. `orders.currency_code` is NOT
         * backfilled by its migration, so every order placed before the stamp landed sits in the
         * `unknown` bucket. `totalIn()` reads ONE bucket and never throws, so an unfolded read
         * would silently drop that history out of the headline -- and the view's appended
         * sentence would then name it to the merchant as "another currency: 1,250.00 unknown".
         * `FinanceLedgerFigures`, `MerchantAnalyticsFigures`, `MetricThreshold` and
         * `CustomerMoneyTiles` all fold first, for this reason. Folding also keeps `total()`
         * unreachable: the view reads the folded result, so its "other currencies" list is the
         * genuinely-other ones.
         */
        $total_revenues = null;

        if ($totalRevenueResult !== null) {
            // Returns `$this` unchanged when the code is empty or there is nothing unrecorded,
            // so "an assumption was made" stays distinguishable from "none was".
            $totalRevenueResult = $totalRevenueResult->assumingUnknownCurrencyIs($storeCurrencyCode);

            $total_revenues = $storeCurrencyCode === ''
                ? ($totalRevenueResult->isSingleCurrency() ? $totalRevenueResult->total() : null)
                : $totalRevenueResult->totalIn($storeCurrencyCode);
        }

        /*
         * GAP-152 / P05-04 — THE TILE IS A `CaveatedFigure`, COMPOSED HERE AND NOT IN THE VIEW.
         *
         * `dashboard/overview.blade.php` built this hint inside a thirty-line `@php` block from the
         * result above, consulting its `currencies()` and `totalIn()` and never its `caveats()`.
         * `GrossMerchandiseValue` attaches `MetricCaveat::FLOAT_PRECISION` unconditionally -- GMV is
         * the one money metric that cannot read `store_order_financials`' DECIMAL and must come off
         * `orders.final_price`, which is FLOAT -- so the busiest money figure in the product stated
         * no precision limit on any screen. The row named this omission rather than leaving it to be
         * rediscovered; this is the fix.
         *
         * The sentences are the view's own, moved byte for byte, owner-decision text included.
         */
        $ssRevenueFigure = \App\Support\Metrics\DashboardOrderValueFigure::make(
            $totalRevenueResult,
            $storeCurrencyCode,
            $total_revenues === null ? null : currency_format_with_sym($total_revenues, $ssStoreId),
            __('all time, gross, excluding cancelled'),
        );

        $topSellingProductIds = (clone $orderQuery)->pluck('product_id')
            ->flatMap(function ($productIds) {
                return explode(',', $productIds);
            })
            ->map(function ($productId) {
                return (int) $productId;
            })
            ->groupBy(function ($productId) {
                return $productId;
            })
            ->map(function ($group) {
                return $group->count();
            })
            ->sortDesc()
            ->take(5)
            ->keys();

        $topSellingProducts = (clone $productQuery)->whereIn('id', $topSellingProductIds)->get();
        $theme_name = APP_THEME() ? APP_THEME() : env('DATA_INSERT_APP_THEME');
        $out_of_stock_threshold = Utility::GetValueByName('out_of_stock_threshold', getCurrentStore());
        $latests = (clone $productQuery)->orderBy('created_at', 'Desc')->limit(3)->get();

        /* P2-02 / F222: the count behind the dashboard's "needs attention" row.
         *
         * THE COMMENT THAT USED TO BE HERE WAS WRONG, AND THE CODE WITH IT. It
         * said "The predicate is NOT a new rule. It is the SAME one the product
         * list already labels a row with ... one that does track stock is out
         * when its count is at or below the store's threshold." The first
         * sentence was false and the third WAS the divergence, written as if it
         * were the shared rule.
         *
         * The authoritative rule is `ProductDataTable::trackedStockState()`
         * (:305) and the list's own filter `applyStockFilter()` (:339), and
         * both agree that A TRACKED PRODUCT IS OUT ONLY AT `product_stock <= 0`.
         * The threshold is the LOW boundary, not the out boundary. So with the
         * threshold at 5, a product with stock 3 was labelled LOW on the list
         * and COUNTED AS OUT here: one question, two numbers, on two surfaces
         * the merchant reaches from each other. `ReportController` calls the
         * shared helpers at eleven sites for exactly this reason; this handler
         * called neither.
         *
         * Fixed by using the same boundary. It stays a SQL predicate rather
         * than a call to `trackedStockState()` because that method takes a
         * single row's values and this must be a count the database evaluates
         * -- the same reason `applyStockFilter()` is hand-written SQL beside
         * it, and `ProductStockIndicatorTest` keeps those two in step.
         *
         * ONE LIMB IS DELIBERATELY BROADER THAN THE LIST, and it is said here
         * rather than left to be rediscovered: the list's stock filter opens
         * with `where('track_stock', 1)`, so it never considers an untracked
         * product at all. This count DOES include an untracked product whose
         * `stock_status` string says `out_of_stock`, because such a product is
         * out of stock in the only sense available for it, and a dashboard that
         * ignored it would under-report. That is a difference in COVERAGE, not
         * in the rule applied to a tracked product.
         *
         * $productQuery already carries ->where('store_id', getCurrentStore()),
         * which is the tenant predicate and has to be explicit: Product is NOT
         * in config/tenancy.php's scoped_models, so StoreScope adds nothing to
         * it for any principal. getCurrentStore() derives from the
         * authenticated user, never from request input.
         */
        $out_of_stock_products = (clone $productQuery)
            ->where(function ($q) {
                $q->where('variant_product', '!=', 1)->orWhereNull('variant_product');
            })
            ->where(function ($q) {
                $q->where(function ($untracked) {
                    $untracked->where('track_stock', 0)->where('stock_status', 'out_of_stock');
                })->orWhere(function ($tracked) {
                    /* `<= 0`, matching ProductDataTable::applyStockFilter('out'). NOT the
                       threshold: that is the LOW boundary. See the note above. */
                    $tracked->where('track_stock', '!=', 0)
                        ->where('product_stock', '<=', 0);
                });
            })
            ->count();

        /* WS C — INVENTORY ALERTS, the master prompt's own phrase, and the half that was missing.
         *
         * `$out_of_stock_products` above answers "what has run out". It does NOT answer "what is
         * about to", which is the alert a merchant can still act on — and that was the gap: the
         * dashboard had no low-stock figure at all while the product list has had an
         * `?ss_stock=low` filter since P2-03-9.
         *
         * THE PREDICATE IS THE LIST'S OWN, not a second copy of it. `ProductDataTable` records
         * what happened the last time this expression existed twice: the badge and the filter
         * disagreed at the boundary. A count that disagrees with the list it links to is worse
         * than no count, because the merchant follows it.
         *
         * Variant parents are excluded on the same terms as the out-of-stock count — their stock
         * lives on the variant rows, so the parent's own `product_stock` is not a quantity.
         */
        $low_stock_products = (clone $productQuery)
            ->where(function ($q) {
                $q->where('variant_product', '!=', 1)->orWhereNull('variant_product');
            })
            ->where(function ($q) {
                \App\DataTables\ProductDataTable::scopeToLowStock($q);
            })
            ->count();

        $orderCountsToday = $this->getOrderCounts($orderQuery, $todayStart, $todayEnd);
        $orderCounts = $this->getOrderCounts($orderQuery);

        //$orderCountsYesterday = $this->getOrderCounts($orderQuery, $yesterdayStart, $yesterdayEnd);

        $totalOrderPer = $this->calculatePercentageToday($orderCountsToday['total'], $orderCounts['total']);
        $pendingOrderPer = $this->calculatePercentageToday($orderCountsToday['pending'], $orderCounts['pending']);
        $completeOrderPer = $this->calculatePercentageToday($orderCountsToday['complete'], $orderCounts['complete']);
        $deliveredOrderPer = $this->calculatePercentageToday($orderCountsToday['delivered'], $orderCounts['delivered']);
        $cancelOrderPer = $this->calculatePercentageToday($orderCountsToday['cancel'], $orderCounts['cancel']);
        $returnOrderPer = $this->calculatePercentageToday($orderCountsToday['return'], $orderCounts['return']);
        $shippedOrderPer = $this->calculatePercentageToday($orderCountsToday['shipped'], $orderCounts['shipped']);


        $pending_order = $orderCounts['pending'];
        $delivered_order = $orderCounts['delivered'];
        $cancel_order = $orderCounts['cancel'];
        $return_order = $orderCounts['return'];
        $confirmed_order = $orderCounts['complete'];
        $shipped_order = $orderCounts['shipped'];
        // P2-04: the three statuses that were counted by nothing, plus the
        // catch-all, so the dashboard's breakdown reconciles with its total.
        $picked_up_order = $orderCounts['picked_up'];
        $partially_paid_order = $orderCounts['partially_paid'];
        $pre_order_order = $orderCounts['pre_order'];
        $other_status_order = $orderCounts['other'];
        $new_orders = $orderQuery->orderBy('id', 'DESC')->limit(4)->get();
        $chartData = $this->getOrderChart(['duration' => 'week']);

        $store = getStoreById(getCurrentStore());

        /*
         * GAP-013 -- NO STORE, NO MERCHANT DASHBOARD.
         *
         * `$slug = $store->slug;` below was the LAST unguarded dereference of
         * `getStoreById(getCurrentStore())` in this file, and the gap's own two lines (:610 and
         * :612, both `route('landing_page', $store->slug)` inside `getThemeUrl()`) were only its
         * siblings. Fixing those on 2026-11-20 made the shared CHROME render; it did not make
         * this screen render, and `GET /dashboard` stayed an `ErrorException: Attempt to read
         * property "slug" on null` for any merchant or staff member whose store does not
         * resolve. `StoreFinanceController` had written that down twice (:806, :1639) while
         * routing its own refusals here.
         *
         * THE ANSWER IS THIS REDIRECT BECAUSE LINE 633 ALREADY CHOSE IT. `getOrderChart()`
         * answers a non-operator with no resolvable store by `auth()->logout()` plus
         * `redirect()->route('login')`, and this method was discarding that into `$chartData` and
         * carrying on -- so every principal who can arrive here with a null store has already
         * been signed out. Rendering was never an option anyway: with only the dereference
         * guarded, the request dies in `dashboard.blade.php` instead, on
         * `route('theme.pages', $theme_name)` with a null `$theme_name`.
         *
         * THE TEST IS ON THE STORE, NOT ON `$chartData` BEING A REDIRECT. Reading another
         * function's return type would make this screen's tenant safety a side effect of a chart
         * helper. `config/tenancy.php` sets `null_tenant => 'open'`, so an unresolved tenant adds
         * no predicate anywhere: a null store has to be refused where it is read.
         *
         * `! $store` AND NOTHING WEAKER. A falsy test on the slug or the name would refuse a
         * store that exists and works -- see the two premise cases in
         * `StorelessMerchantDashboardTest`, and the same correction forced by review on
         * `getThemeUrl()` (`StorelessChromeRendersTest`).
         *
         * THE SIGN-OUT IS REPEATED HERE RATHER THAN ASSUMED, and that is not belt-and-braces --
         * it is what stops this redirect becoming an infinite one. `/login` answers an
         * AUTHENTICATED visitor with a redirect to `/dashboard`, so a dashboard that redirects to
         * sign-in without ending the session is a two-page ring in a real browser.
         * `FinanceNullStoreGuardTest` exists for exactly that hazard ("A 302 chain is worse than
         * the 500 it replaces") and routes its own storeless refusals here. `logout()` is
         * idempotent, so doing it twice when `getOrderChart()` already did costs nothing;
         * depending on it having happened would make this screen's termination a side effect of a
         * chart helper. MEASURED: `GET /login` after this answers 200, not a redirect.
         */
        if (! $store) {
            if (auth()->check()) {
                auth()->logout();
            }

            return redirect()->route('login')->with('message', __('You have been logged out.'));
        }

        $slug = $store->slug;
        $storage_limit = 0;
        $users = User::find($user->id);
        $plan = null;
        if ($users) {
            $plan = Plan::find($users->plan_id);
            if ($plan && $plan->storage_limit > 0) {
                $storage_limit = ($user->storage_limit / $plan->storage_limit) * 100;
            }
        }


        $theme_url = $this->getThemeUrl($store);

        $data = compact(
            'totalproduct',
            'totle_order',
            'totle_customers',
            'latests',
            'new_orders',
            'chartData',
            'theme_url',
            'store',
            'storage_limit',
            'users',
            'plan',
            'topSellingProducts',
            'total_revenues',
            /* P5-R7-C: the view needs the result, not only the one-currency total, so it can say
               what it cannot state and list what it did not add. GAP-152 moved the COMPOSITION out
               of the view -- `ssRevenueFigure` is the carrier the tile renders -- and both are kept
               because other blocks on this screen still read the raw total. */
            'totalRevenueResult',
            'ssRevenueFigure',
            'storeCurrencyCode',
            'totle_cancel_order',
            'out_of_stock_threshold',
            'out_of_stock_products',
            'low_stock_products',
            'theme_name',
            'pending_order',
            'delivered_order',
            'cancel_order',
            'return_order',
            'confirmed_order',
            'totalOrderPer',
            'pendingOrderPer',
            'completeOrderPer',
            'deliveredOrderPer',
            'cancelOrderPer',
            'returnOrderPer',
            'customerPer',
            'productPer',
            'shippedOrderPer',
            'shipped_order',
            'picked_up_order',
            'partially_paid_order',
            'pre_order_order',
            'other_status_order'
        );
        return $data;
    }

    /**
     * The nine values `orders.delivered_status` really takes, by the name the
     * order list already shows for each one (OrderDataTable::dataTable()).
     *
     * The column's own migration comment says `0=>pending/1=>diliver/2=>cancel
     * /3=>return` and has been stale for a long time: 4, 5, 6, 7 and 8 are all
     * live in app/ and resources/.
     *
     * Two of these are NOT fulfilment states and are worth naming as such,
     * because they are the reason a "status" column cannot be read as one
     * thing: 7 is PARTIALLY PAID, which is a payment state, and 8 is PRE
     * ORDER, which is an order type. They sit in the same integer column as
     * "shipped", so an order can be exactly one of the nine and the set does
     * partition -- but what it partitions by is not a single concept.
     */
    public const ORDER_STATUS_PENDING        = 0;
    public const ORDER_STATUS_DELIVERED      = 1;
    public const ORDER_STATUS_CANCEL         = 2;
    public const ORDER_STATUS_RETURN         = 3;
    public const ORDER_STATUS_CONFIRMED      = 4;
    public const ORDER_STATUS_PICKED_UP      = 5;
    public const ORDER_STATUS_SHIPPED        = 6;
    public const ORDER_STATUS_PARTIALLY_PAID = 7;   // a PAYMENT state
    public const ORDER_STATUS_PRE_ORDER      = 8;   // an ORDER TYPE

    /**
     * Counts per status, plus an `other` bucket so the parts ALWAYS sum to the
     * total.
     *
     * This used to count six of the nine values -- 0, 1, 2, 3, 4 and 6 --
     * leaving picked-up (5), partially-paid (7) and pre-order (8) in no bucket
     * at all. A screen that shows those six beside a total is then quietly
     * wrong for any store that uses the other three, and a merchant has no way
     * to tell from the screen that anything is missing.
     *
     * `other` is a deliberate catch-all rather than a tenth count: if a value
     * outside 0-8 is ever written, it lands here and stays visible instead of
     * disappearing. P2-04 asserts total == sum(parts).
     */
    private function getOrderCounts($orderQuery, $start = null, $end = null)
    {
        $scoped = function () use ($orderQuery, $start, $end) {
            $q = clone $orderQuery;
            if (!empty($start) && !empty($end)) {
                $q->whereBetween('created_at', [$start, $end]);
            }
            return $q;
        };

        $counts = [
            'total'          => $scoped()->count(),
            'pending'        => $scoped()->where('delivered_status', self::ORDER_STATUS_PENDING)->count(),
            'delivered'      => $scoped()->where('delivered_status', self::ORDER_STATUS_DELIVERED)->count(),
            'cancel'         => $scoped()->where('delivered_status', self::ORDER_STATUS_CANCEL)->count(),
            'return'         => $scoped()->where('delivered_status', self::ORDER_STATUS_RETURN)->count(),
            'complete'       => $scoped()->where('delivered_status', self::ORDER_STATUS_CONFIRMED)->count(),
            'picked_up'      => $scoped()->where('delivered_status', self::ORDER_STATUS_PICKED_UP)->count(),
            'shipped'        => $scoped()->where('delivered_status', self::ORDER_STATUS_SHIPPED)->count(),
            'partially_paid' => $scoped()->where('delivered_status', self::ORDER_STATUS_PARTIALLY_PAID)->count(),
            'pre_order'      => $scoped()->where('delivered_status', self::ORDER_STATUS_PRE_ORDER)->count(),
        ];

        $accounted = $counts['pending'] + $counts['delivered'] + $counts['cancel']
            + $counts['return'] + $counts['complete'] + $counts['picked_up']
            + $counts['shipped'] + $counts['partially_paid'] + $counts['pre_order'];

        $counts['other'] = max(0, $counts['total'] - $accounted);

        return $counts;
    }

    private function calculatePercentageToday($todayCount, $allCount)
    {
        if ($allCount == 0) {
            return $todayCount > 0 ? 100 : 0;
        }
        $percentage = (($todayCount - $allCount) / $allCount) * 100;
        if ($percentage > 0) {
            return '+ ' . number_format($percentage, 2);
        } else {
            return number_format($percentage, 2);
        }

    }

    public static function getThemeUrl($store)
    {
        /*
         * NO STORE, NO STOREFRONT LINK.
         *
         * `partision/header.blade.php:12` hands this the result of
         * `getStoreById(getCurrentStore())`, which is NULL for any principal who resolves no store:
         * a merchant between stores, and the platform operator, for whom `getCurrentStore()`
         * returns the hardcoded literal 1 and `getStoreById(1)` finds nothing when store 1 does not
         * exist. The method then reached `route('landing_page', $store->slug)` and died, so THE
         * SHARED CHROME WAS A 500 ON EVERY MERCHANT SCREEN SUCH A PRINCIPAL OPENED --
         * `StoreFinanceController` measured and reported that twice (:806, :1639) and routed its
         * own refusals to `/dashboard` while recording that the dashboard was a 500 there too.
         *
         * THE CALL SITE ALREADY HANDLES NULL. Line 250 of that partial renders
         * `{{ $theme_url ?? '#' }}`, and `dashboard.blade.php:50` guards with `@isset`, so both
         * consumers expected this to be absent -- the fallbacks had simply never been reachable,
         * because the function threw before it could return. Returning null makes existing
         * defences live rather than adding new ones.
         *
         * RETURNING HERE, BEFORE THE SETTINGS READS, IS THE WHOLE REASON THIS LIMB IS SEPARATE.
         * Each read below falls back to `getCurrentStore()`, which for the operator is the literal
         * 1, so continuing with no store would read ANOTHER MERCHANT'S `domains` and `subdomain`
         * and could hand the operator a link to an arbitrary tenant's storefront labelled as their
         * own. A missing link is correct; a confident wrong one is a disclosure.
         */
        if (! $store) {
            return null;
        }

        $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id ?? getCurrentStore());
        $enable_domain = Utility::GetValueByName('enable_domain', $store->id ?? getCurrentStore());
        $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id ?? getCurrentStore());
        $domains = Utility::GetValueByName('domains', $store->id ?? getCurrentStore());
        $subdomain = Utility::GetValueByName('subdomain', $store->id ?? getCurrentStore());

        if ($enable_domain == 'on') {
            return 'https://' . $domains;
        }

        if ($enable_subdomain == 'on') {
            return 'https://' . $subdomain;
        }

        /*
         * THE SLUG IS CHECKED HERE AND NOT WITH THE NULL STORE, which is a correction found by
         * review of the first version of this fix. `stores.slug` is nullable, and a store with no
         * slug but `enable_domain = 'on'` returned a WORKING custom-domain link above without ever
         * touching the slug. Guarding it alongside `! $store` would have taken that link away --
         * a change well beyond the null-store defect, for a store that exists and is reachable.
         * The two branches below are the only ones that address the store BY SLUG, so this is
         * where "no slug" means "no link".
         *
         * The original `elseif ($enable_storelink) ... else ...` pair had byte-identical bodies,
         * so collapsing them changes nothing; `$enable_storelink` is still read above because the
         * read itself is unconditional in the original and this is not the change to alter that.
         */
        if (! isset($store->slug)) {
            return null;
        }

        return route('landing_page', $store->slug);
    }

    /**
     * A day label for a chart axis, in the language the page is being rendered in.
     *
     * P2.5-PKG-A-10. Both chart axes called `date('d-M', ...)`, which is locale-blind: the Arabic
     * dashboard drew `31-Oct` under its bars while its own caption read `الطلبات يومياً — آخر ٧ أيام`.
     *
     * ONE OF THE TWO SITES LOOKED LIKE IT HANDLED THIS AND DID NOT. `best_selling_brand_chart`
     * wrapped the call as `__(date('d-M', ...))`, which asks the translator for the key "31-Oct".
     * MEASURED: `resources/lang/ar.json` holds no `DD-Mon` key at all, and exactly one month name
     * ("May", which is there as an ordinary English word). `__()` returns a key it cannot resolve
     * unchanged, so that call has never translated anything — it was decoration that made the
     * defect look handled.
     *
     * Carbon is already a framework dependency and carries the month names for every locale, so
     * this needs no new translation keys and no per-month table to maintain.
     *
     * WESTERN DIGITS ON PURPOSE. `isoFormat('D MMM')` yields `31 أكتوبر`, not `٣١ أكتوبر`, which
     * matches what the rest of this dashboard already renders — the KPI figures measure as `62`,
     * not `٦٢`. Forcing Arabic-Indic numerals here would make the axis disagree with the numbers
     * directly above it.
     *
     * NOT AN API CHANGE. `getOrderChart` is called only from two web dashboard actions and appears
     * nowhere in `routes/api.php`, so no client contract reads these strings.
     */
    private function ssDayLabel(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)
            ->locale(app()->getLocale())
            ->isoFormat('D MMM');
    }

    public function getOrderChart($arrParam)
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login')->with('message', __('You have been logged out.'));
        }
        /*
         * THE OPERATOR IS NOT ASKED FOR A STORE THEIR BRANCH NEVER READS. Below, the operator's
         * chart is built from `PlanOrder` alone; only the merchant branch touches a store. This
         * check used to run for both, so an operator whose selected store had been deleted, or
         * was never set, was signed out of the platform by their own dashboard -- and
         * `handleSuperAdmin()` then rendered the view anyway with this redirect where the chart
         * data should be. A merchant or staff member with no resolvable store is still signed
         * out, exactly as before.
         */
        $isOperator = \App\Policies\UserPolicy::isOperator($user);

        if (! $isOperator && ! getStoreById($user->current_store)) {
            if (auth()->check()) {
                auth()->logout();
            }

            return redirect()->route('login')->with('message', __('You have been logged out.'));
        }
        
        $arrDuration = [];
        if ($arrParam['duration']) {
            if ($arrParam['duration'] == 'week') {
                $previous_week = strtotime('-1 week +1 day');

                for ($i = 0; $i < 7; $i++) {
                    $arrDuration[date('Y-m-d', $previous_week)] = $this->ssDayLabel($previous_week);
                    $previous_week = strtotime(date('Y-m-d', $previous_week) . ' +1 day');
                }
            }
        }
        $arrTask = [];
        $arrTask['label'] = [];
        $arrTask['data'] = [];
        $registerTotal = '';
        $newguestTotal = '';
        /* P2-02: the discriminator was `type == 'admin'`, and that was the wrong
         * question.
         *
         * A staff user's `type` is THEIR ROLE NAME -- UserController::store sets
         * `$user->type = $role_r->name` -- so "Manager", "Accountant", anything
         * the merchant named the role. It is never the literal 'admin', which
         * only the store owner carries. Every staff member therefore fell into
         * the else-branch below and had their dashboard chart built from
         * `PlanOrder`: the PLATFORM's SaaS subscription orders, with no
         * `store_id` predicate of any kind (PlanOrder is not in
         * config/tenancy.php's scoped_models and registers no StoreScope, so
         * nothing scopes it).
         *
         * LATENT, NOT LIVE -- and the distinction is the point. `$chartData` is
         * rendered by exactly two views, superadmin/dashboard.blade.php and
         * theme_analytic.blade.php; `resources/views/dashboard.blade.php` and
         * its overview partial never reference it (measured: 0 occurrences).
         * So for merchant staff these counts were computed server-side and
         * discarded, never rendered and never serialised. An earlier version of
         * this comment said an employee "was shown" the platform's subscription
         * volume. That was an overclaim, caught in review, and corrected here.
         *
         * It is still worth fixing now rather than later: PHASE-02-REUSE-INVENTORY
         * marks this chart "Reuse", i.e. Phase 02 intends to start rendering it,
         * which is precisely when latent would become live.
         *
         * The real question is operator-or-merchant: getOrderChart() is called
         * from handleSuperAdmin() -- where PlanOrder is genuinely the right
         * table -- and from handleRegularUser(), where the store's own Orders
         * are, for the owner and for staff alike.
         *
         * Asked through UserPolicy::isOperator() rather than a fourth
         * hand-written spelling of it: P1-SEC-11 criterion 3 asks for the
         * operator to be defined ONCE, and that helper normalises the way
         * MySQL's PAD SPACE does.
         */
        foreach ($arrDuration as $date => $label) {
            if (! $isOperator) {
                $data = Order::select(\DB::raw('count(*) as total'))                    
                    ->where('store_id', getCurrentStore())
                    ->whereDate('created_at', '=', $date)
                    ->first();

                $registerTotal = Customer::select(\DB::raw('count(*) as total'))                    
                    ->where('store_id', getCurrentStore())
                    ->where('regiester_date', '!=', null)
                    ->whereDate('regiester_date', '=', $date)
                    ->first();

                $newguestTotal = Customer::select(\DB::raw('count(*) as total'))
                    ->where('store_id', getCurrentStore())
                    ->where('regiester_date', '=', null)
                    ->whereDate('last_active', '=', $date)
                    ->first();
            } else {
                // The platform operator only. PlanOrder is deliberately
                // unscoped here -- platform-wide subscription volume is what
                // the Super Admin dashboard is for.
                $data = PlanOrder::select(\DB::raw('count(*) as total'))
                    ->whereDate('created_at', '=', $date)
                    ->first();
            }

            $arrTask['label'][] = $label;
            $arrTask['data'][] = $data ? $data->total : 0; // Check if $data is not null

            if (auth()->user()->isAbleTo('Manage Dashboard')) {
                $arrTask['registerTotal'][] = $registerTotal ? $registerTotal->total : 0; // Check if $registerTotal is not null
                $arrTask['newguestTotal'][] = $newguestTotal ? $newguestTotal->total : 0; // Check if $newguestTotal is not null
            }
        }

        return $arrTask;
    }

    public function landing_page($storeSlug)
    {
        $uri = url()->full();
        $segments = explode('/', str_replace(url(''), '', $uri));
        $segments = $segments[1] ?? null;

        $local = parse_url(config('app.url'))['host'];
        $remote = str_replace('www.', '', request()->getHost());

        // P1-SEC-29: only a row whose own store has the matching flag on counts (App\Services\HostClaims). This lookup was the sixth one
        // and the review found it still reading every row of that host with no store predicate, the LAST of them winning.
        $subdomainSetting = \App\Services\HostClaims::row('subdomain', $remote);
        $domainSetting = \App\Services\HostClaims::row('domains', $remote);

        $enable_subdomain = null;
        $enable_domain = null;

        if ($subdomainSetting) {
            $enable_subdomain = Setting::where('name', 'enable_subdomain')
                ->where('value', 'on')
                ->where('store_id', $subdomainSetting->store_id)
                ->first();
        }

        if ($domainSetting) {
            $enable_domain = Setting::where('name', 'enable_domain')
                ->where('value', 'on')
                ->where('store_id', $domainSetting->store_id)
                ->first();
        }

        $storeSlugToReturn = $segments;

        if ($enable_subdomain) {
            $admin = User::find($enable_subdomain->created_by);
            if ($enable_subdomain->value == 'on' && $enable_subdomain->store_id == $admin->current_store) {
                $store = Store::find($admin->current_store);
                if ($store) {
                    return $this->storeSlug($store->slug);
                }
            }
        }

        if ($enable_domain) {
            $admin = User::find($enable_domain->created_by);
            if ($enable_domain->value == 'on' && $enable_domain->store_id == $admin->current_store) {
                $store = Store::find($admin->current_store);
                if ($store) {
                    return $this->storeSlug($store->slug);
                }
            }
        }

        return $this->storeSlug($storeSlugToReturn);
    }


    /**
     * PHASE 03 M4-5 — MADE PUBLIC, AND NOTHING ELSE ABOUT IT CHANGED.
     *
     * This is the real storefront renderer: it resolves the store, loads the page's data and
     * returns `main_file`. The draft preview needs exactly this and nothing else, so it calls it
     * rather than growing a second renderer that would have to be kept in step with three themes --
     * and the first time the two diverged, the merchant would be previewing a page no shopper ever
     * sees, which is worse than having no preview at all.
     *
     * `landing_page()` cannot be reused for that: it re-derives the store from `url()->full()` and
     * the request's host settings rather than from its argument, so from any path other than a
     * storefront URL it resolves the wrong store or none. Measured -- calling it from
     * `/theme-draft/{id}/preview` answered 404.
     *
     * Widening the visibility adds no caller that could not already reach the same rendering
     * through `landing_page()`, and the preview's own authorization happens in middleware before
     * this runs.
     */
    public function storeSlug($storeSlug)
    {
        // PHASE 03 M0 -- an unknown store slug must FAIL CLOSED with a not-found response.
        //
        // This used to be `if (!empty($storeSlug))`, and `!empty('0')` is FALSE in PHP: a store
        // whose slug is `0` was unreachable and answered 403 "The Link is not active." An explicit
        // emptiness test asks the question that was meant.
        if ($storeSlug !== null && $storeSlug !== '') {
            $store = getStore($storeSlug);

            // THE DEFECT THE PHASE 03 BASELINE MIS-DESCRIBED. This arm was
            //
            //     return redirect()->back()->with('error', __("Store Not Found."));
            //
            // and `redirect()->back()` reads the `Referer`. The capture harness had navigated from
            // store A, so an unknown slug sent it BACK to store A and it photographed a real
            // storefront -- which the baseline recorded as "an unknown slug serves another
            // tenant's shop". It never did. With no `Referer` the same line redirects to `/`,
            // which lands a shopper on the MERCHANT login page.
            //
            // Either way it is a soft 404: wrong for a crawler, wrong for a typo'd link, and an
            // evidence-integrity hazard for every harness that trusts the URL it asked for. Seven
            // of this controller's nine sibling page handlers already `abort(404)` here; this one
            // is now consistent with them.
            if (!$store) {
                abort(404);
            }

            // A STORE THAT EXISTS BUT WHOSE THEME HAS NO `main_file` IS A DIFFERENT FAILURE and no
            // longer shares this one's answer. It is the empty-`stores.theme_id` path: `ActiveTheme`
            // skips `Theme::set()`, so no theme view root is prepended and the lookup misses. That
            // is a server-side misconfiguration, not a missing store, and answering 404 for it
            // would tell a merchant their shop does not exist. Kept as a distinct refusal so the
            // two causes stay separable.
            if (!view()->exists('main_file')) {
                abort(503, 'The store theme is not available.');
            }

            $testimonials = Testimonial::where('store_id', $store->id)->where('status', 1)->get();
            $brands = ProductBrand::where('store_id', $store->id)->where('status', 1)->get();
            $categories = Category::where('store_id', $store->id)->where('status', 1)->take(8)->get();
            $top_categories = Category::with('product_details')->where('store_id', $store->id)->where('status', 1)->take(3)->get();
            $bestseller_products = Product::where('store_id', $store->id)->where('status', 1)->take(10)->get();
            $blogs = Blog::where('store_id', $store->id)->take(10)->get();
            $productQuery = Product::where('store_id', $store->id)->where('product_type', null)->where('status', 1);

            $all_products = (clone $productQuery)->inRandomOrder()->limit(20)->get();
            return view('main_file', compact('testimonials', 'brands', 'categories', 'top_categories', 'bestseller_products', 'blogs', 'all_products'));
        } else {
            return abort('403', 'The Link is not active.');
        }

    }

    public function faqs_page(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }

        $faqs = Faq::where('store_id', $store->id)->get();

        return view('front_end.pages.faq', compact('faqs'));
    }

    public function about_page(Request $request, $storeSlug)
    {
        // PHASE 03 M0 (independent review). This handler never looked the store up, so for an
        // unknown slug its ONLY store check was the `abort(404)` in ViewServiceProvider's composer
        // -- which is a control one `return response()->json(...)` away from silently vanishing,
        // with no test noticing. The composer guard stays; this is the handler saying it too.
        if (! getStore($storeSlug)) {
            abort(404);
        }

        return view('front_end.pages.about');
    }

    public function blog_page(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
        
        $topNavItems = [];
       
        $BlogCategory = BlogCategory::where('store_id', $store_id)->get()->pluck('name', 'id');
        $BlogCategory->prepend('All', '0');

        $blogs = Blog::where('store_id', $store_id)->paginate(6);
       
        return view('front_end.pages.blog', compact('BlogCategory', 'store', 'blogs'));
    }

    public function article_page(Request $request, $storeSlug, $blogSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
       
        $blog = Blog::where('slug', $blogSlug)->where('store_id', $store_id)->first();
        $home_blogs = Blog::where('store_id', $store_id)->get();
        if (empty($blog)) {
            abort(404);
        }

        $datas = Blog::where('store_id', $store_id)->inRandomOrder()
            ->limit(3)
            ->get();

        $l_articles = Blog::where('store_id', $store_id)->inRandomOrder()
            ->limit(5)
            ->get();

        $BlogCategory = BlogCategory::where('store_id', $store_id)->get()->pluck('name', 'id');
        $BlogCategory->prepend('All Products', '0');
        $homeproducts = Product::where('product_type', null)->where('store_id', $store_id)->get();
       
        $blogs = Blog::where('store_id', $store_id)->where('category_id', $blog->category_id)->get();

        return view('front_end.pages.article', compact('blog', 'datas', 'l_articles', 'BlogCategory', 'homeproducts', 'blogs'));

    }

    public function product_page(Request $request, $storeSlug, $categorySlug = null)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }

        $store_id = $store->id;
        $slug = $store->slug;
        
        $category_ids = [];
        $brand_ids = $brand_select = [];
        if ($categorySlug) {
            $category_ids = Category::where(function ($query) use ($categorySlug) {
                $query->where('slug', 'Like', "%$categorySlug%")->orWhere('name', 'Like', "%$categorySlug%");
            })->where('store_id', $store_id)->pluck('id')->toArray();
            if (!$category_ids) {
                $brand_select = $brand_ids = ProductBrand::where('slug', 'like', "%$categorySlug%")->where('store_id', $store_id)->pluck('id')->toArray();
            }
        }
       
        $filter_product = $request->get('filter_product') ?? 'all';
        $MainCategoryList = Category::where('status', 1)->where('store_id', $store_id)->get();
        $brands = ProductBrand::where('status', 1)->where('store_id', $store_id)->get();
       
        $main_category = $request->main_category;
        $category_slug = $request->category_slug;
        $product_brand = $request->brands;
        
        if (!empty($product_brand)) {
            $brand_select = ProductBrand::where('id', $product_brand)->pluck('id')->toArray();
        }
        
        /* For Filter */
        $min_price = 0;
        $max_price = Product::where('variant_product', 0)->orderBy('price', 'DESC')->where('product_type', null)->where('store_id', $store_id)->first();
        $max_price = !empty($max_price->price) ? $max_price->price : '0';

        $product_tag = implode(',', $category_ids);
        $product_brand = implode(',', $brand_ids);
        $filter_tag = $MainCategoryList;

        $compact = ['slug', 'MainCategoryList', 'filter_tag', 'min_price', 'max_price', 'brands', 'brand_select', 'product_tag', 'product_brand', 'store', 'category_ids', 'filter_product'];

        return view('front_end.pages.product-list', compact($compact));
    }

    public function product_page_filter(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);  
        if (!$store) {
            $data['product_count'] = 0;
            $data['status'] = false;
            $data['html'] = '';
            return response()->json($data);
        }     
        $page = $request->page ?? 1;

        // Clean parameters
        $filter_value = $request->get('filter_product') !== 'undefined' ? $request->get('filter_product') : null;
        $product_tag = $request->product_tag !== 'undefined' ? $request->product_tag : null;
        $product_brand = $request->product_brand !== 'undefined' ? $request->product_brand : null;
        $min_price = $request->min_price !== 'undefined' ? $request->min_price : 0;
        $max_price = $request->max_price !== 'undefined' ? $request->max_price : null;

        if (!empty($product_tag)) {
            $product_tag = explode(',', $product_tag);
        }

        if (!empty($product_brand)) {
            $product_brand = explode(',', $product_brand);
        }

        $products_query = Product::where('product_type', null)
            ->where('store_id', $store->id)
            ->where('status', 1);


        if (!empty($product_tag)) {
            $products_query->whereIn('category_id', $product_tag);
        }

        if (!empty($product_brand)) {
            $products_query->whereIn('brand_id', $product_brand);
        }

        if (!empty($max_price)) {
            $products_query->where(function ($query) use ($min_price, $max_price) {
                $query->whereBetween('price', [$min_price, $max_price]);
            });
        }

        if (!empty($filter_value)) {
            switch ($filter_value) {
                case 'trending':
                    $products_query->where('trending', 1);
                    break;
                case 'title-ascending':
                    $products_query->orderBy('name', 'asc');
                    break;
                case 'title-descending':
                    $products_query->orderBy('name', 'desc');
                    break;
                case 'price-ascending':
                    $products_query->orderBy('price', 'asc');
                    break;
                case 'price-descending':
                    $products_query->orderBy('price', 'desc');
                    break;
                case 'created-ascending':
                    $products_query->orderBy('created_at', 'asc');
                    break;
                case 'created-descending':
                    $products_query->orderBy('created_at', 'desc');
                    break;
            }
        }
        $product_count = $products_query->count();
        $products = $products_query->paginate(12);

       
        $currentDateTime = now()->format('Y-m-d H:i:s A');

        $tax_option = TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')
            ->toArray();

        $data['product_count'] = $product_count;
        $data['status'] = true;
        $data['html'] = view('front_end.pages.product_list_filter', compact(
            'tax_option',
            'storeSlug',
            'products',
            'currentDateTime',
            'product_count'
        ))->render();
        return response()->json($data);
    }

    public function product_detail(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
        
        $storeId = $store->id;
        $product = Product::where('slug', $product_slug)->where('store_id', $store_id)->first();
        
        if (!$product) {
            return redirect()->back()->with('error', __('Product not found.'));
        }
        $id = $product->id;
       
        
        $Stocks = ProductVariant::where('product_id', $id)->first();
        if ($Stocks) {
            $minPrice = ProductVariant::where('product_id', $id)->min('price');
            $maxPrice = ProductVariant::where('product_id', $id)->max('price');

            $min_vprice = ProductVariant::where('product_id', $id)->min('variation_price');
            $max_vprice = ProductVariant::where('product_id', $id)->max('variation_price');

            $mi_price = !empty($minPrice) ? $minPrice : $min_vprice;
            $ma_price = !empty($maxPrice) ? $maxPrice : $max_vprice;
        } else {
            $mi_price = 0;
            $ma_price = 0;
        }    
        
       
        $question = ProductQuestion::where('product_id', $id)->where('store_id', $storeId)->get();

        $flashsales = FlashSale::where('store_id', $storeId)->orderBy('created_at', 'Desc')->get();

        $setting = getAdminAllSetting();
        $defaultTimeZone = isset($setting['defult_timezone']) ? $setting['defult_timezone'] : 'Asia/Kolkata';
        date_default_timezone_set($defaultTimeZone);
        $currentDateTime = date('Y-m-d H:i:s A');

        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select country', ' ');
        $response = Cart::cart_list_cookie($request->all(), $store->id);
        $response = json_decode(json_encode($response));
       
        if (module_is_active('PreOrder')) {
            $customer = auth('customers')->user() ?? null;
            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
            if (isset($customer) && isset($product) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on' && (($product->variant_product == 0 && $product->track_stock == 0 && $product->stock_status == 'out_of_stock') || ($product->variant_product == 0 && $product->product_stock <= 0) || ($product->variant_product == 1))) {
                $pre_order_available = \Workdo\PreOrder\app\Models\PreOrderSetting::productStockAvailable($slug, $product->id);
                $latestSales = [];
            } else {
                $pre_order_available = [];
                $latestSales = Product::productSalesTag($store->slug, $product->id);
            }
        } else {
            $pre_order_available = [];
            $latestSales = Product::productSalesTag($store->slug, $product->id);
        }
        $productQuery = Product::where('store_id', $store->id)->where('product_type', null)->where('status', 1);

        $products = (clone $productQuery)->inRandomOrder()->limit(20)->get();
        return view('front_end.pages.product-detail', compact('slug', 'product', 'question', 'mi_price', 'ma_price', 'flashsales', 'latestSales', 'pre_order_available', 'products'));

    }

    public function cart_page(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        
        return view('front_end.pages.cart', compact('store'));
       
    }

    public function checkout(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        $payments = Setting::paymentList($slug);
        return view('front_end.pages.checkout', compact('store', 'payments'));
    }

    public function addressForm(Request $request, $slug)
    {
        $type = $request->type;
        $countries = Country::orderBy('name', 'ASC')->get();
        // if (isset($data['settings']['store_'.$type.'_location']) && $data['settings']['store_'.$type.'_location'] == 'except_countries') {
        //     $countries = Country::whereNotIn('id', json_decode($data['settings']['store_except_'.$type.'_country']))->orderBy('name', 'ASC')->get();
        // } elseif (isset($data['settings']['store_'.$type.'_location']) && $data['settings']['store_'.$type.'_location'] == 'specific_countries') {
        //     $countries = Country::whereIn('id', json_decode($data['settings']['store_'.$type.'_country']))->orderBy('name', 'ASC')->get();
        // }

        if (auth('customers')->user()) {
            $customer_address = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('default_address', 1)->first();

            if (empty($customer_address)) {
                $customer_address = null;
                $state_option = [];
                $city_option = [];
            } else {
                $state_option = State::where('country_id', $customer_address->country_id)->orderBy('name', 'ASC')->get();
                $city_option = City::where('state_id', $customer_address->state_id)->where('country_id', $customer_address->country_id)->orderBy('name', 'ASC')->get();
            }
        } else {
            $customer_address = null;
            $state_option = [];
            $city_option = [];
        }
        $data['html'] = view('front_end.pages.address-field', compact('type', 'countries', 'customer_address', 'state_option', 'city_option'))->render();

        return Utility::success($data , __('Form get successfully'));
        // return success('success', __('Form get successfully'), $data);
    }

    public function order_track(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
         
        // P1-ORD-08. AN ORDER IS RETURNED ONLY TO WHOEVER KNOWS BOTH THE NUMBER AND THE EMAIL.
        //
        // The gate was `order_number || email` and the email was then compared to NOTHING, so the
        // number alone disclosed the customer's name, email and phone and minted the order-details
        // link -- MEASURED, P1-SEC-03 round 2. `product_order_id` is a bare timestamp
        // ('0' . date('YmdHis') for a guest), so that is 86,400 guesses per store-day. An email-only
        // POST also compiled `where('product_order_id', null)` to IS NULL and returned the first
        // order with no number.
        //
        // The form (front_end/pages/track-order.blade.php) marks BOTH fields `required`, so this
        // refuses nothing a shopper can legitimately send. The email is compared against the one
        // the order was PLACED with (order_billing_details -- `orders` has no email column),
        // case-insensitively and trimmed so a retyped capital letter does not lock a buyer out.
        // An order with no recorded email is never matched: two empty strings are equal, and
        // `hash_equals('', '')` is true. A wrong email and an unknown number take the same branch,
        // so neither is an oracle for the other.
        //
        // The posted values are already trimmed by the global TrimStrings middleware, so the trim() calls
        // on them are belt and braces -- they cannot be isolated by an HTTP test, and say so rather than
        // claim a control. The RECORDED email's trim() is not redundant: nothing trims a database value.
        $orderNumber = is_string($request->order_number) ? trim($request->order_number) : '';
        $postedEmail = is_string($request->email) ? mb_strtolower(trim($request->email)) : '';

        if ($orderNumber !== '' && $postedEmail !== '') {
            // EVERY order in this store that carries the number, not the first: `product_order_id` is
            // not unique (two guest orders in one second share it), and comparing the email to only
            // the lowest id told the second buyer their order did not exist. The email is what tells
            // them apart. Bounded, because the number is request input.
            $order = null;

            foreach (Order::where('product_order_id', $orderNumber)->where('store_id', $store->id)->orderBy('id')->limit(20)->get() as $candidate) {
                $recordedEmail = mb_strtolower(trim((string) OrderBillingDetail::where('order_id', $candidate->id)->value('email')));

                if ($recordedEmail !== '' && hash_equals($recordedEmail, $postedEmail)) {
                    $order = $candidate;
                    break;
                }
            }

            if (!isset($order)) {
               return view('front_end.pages.order-status', compact('slug', 'store'));
            } else {
                $order_detail = Order::order_detail($order->id);
            }
            if (!empty($order)) {
                /*
                 * OWNER SAFEGUARD 1 (2026-11-07). `->where('store_id', $store->id)`.
                 *
                 * The ORDER lookup above is already correctly store-scoped (`:1203`); this customer
                 * lookup was not, so with two live customers sharing an address across stores the
                 * order-status page could show ANOTHER store's customer alongside this store's
                 * order. Read-only -- the value is passed to the view -- which is why this is the
                 * milder of the two sites, but it is the same omission.
                 *
                 * `$store` is already resolved in this method. No request input is involved.
                 */
                $customer = Customer::where('email', $order->email)
                    ->where('store_id', $store->id)
                    ->first();
            }

            $storeUrl = getFrontThemeUrl($store);
            $url =  $storeUrl .'/'. $slug . '/order/' . encrypt($order->id ?? '');
            return view('front_end.pages.order-status', compact('order', 'url', 'order_detail', 'customer', 'slug', 'store'));
        } else {
            return view('front_end.pages.order-status', compact('slug', 'store'));
        }
    }

    public function contactUs(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
       
        return view('front_end.pages.contact', compact('store'));
    }
    
    public function contactUsSave(Request $request, $slug)
    {
        try{
            $store = getStore($slug);
            if (!$store) {
                abort(404);
            }
            
            $request->validate([
                'first_name' => 'required',
                'email'   => 'required|email',
                'last_name'   => 'required',
                'subject'   => 'required',
                'description' => 'nullable',
            ]);

            $contact = Contact::create([
                'first_name'   => $request->first_name,
                'last_name'   => $request->last_name,
                'subject'   => $request->subject,
                'email'   => $request->email,
                'contact'   => $request->contact,
                'description' => $request->description,
                'store_id' => $store->id ?? null,
            ]);
            $contact->save();

            return back()->with('success', __('Thanks! Your message has been sent.'));
        }
        catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function search_products(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['html_data'] = null;
            $return['message'] = __('Store not found.');

            return response()->json($return);
        }
       
        $search_pro = $request->product;

        /*
         * CUST-003. `where('status', 1)` — the typeahead must not offer a product the merchant has
         * unpublished, and then hand back a working link to it.
         *
         * `product_page_filter` in this same controller — the storefront's filter panel, the other
         * half of this requirement's own surface — is already
         * `Product::where('product_type', null)->where('store_id', …)->where('status', 1)`. So this
         * applies a rule the sibling door already applies rather than deciding a new one.
         *
         * STILL UNBOUNDED (`get()`, no `limit`), deliberately: a cap on a typeahead is a UX decision
         * about how many suggestions to show and is reported rather than taken here.
         */
        $products = Product::where('name', 'LIKE', '%' . $search_pro . '%')->where('store_id', $store->id)->where('status', 1)->get();
       
        // Check if any matching products were found
        if (!$products->isEmpty()) {
            // Create an array of product URLs
            $productData = [];

            // Populate the array with product names and URLs
            foreach ($products as $product) {
                $url = url($store->slug . '/product/' . $product->slug);

                $productData[] = [
                    'name' => $product->name,
                    'url' => $url,
                ];
            }

            return response()->json($productData);
        } else {
             $url = url($store->slug . '/error');
             $productData[] = [
                    'name' => $request->product,
                    'url' => $url,
                ];
            // Handle the case where no matching products were found
            return response()->json($productData);
        }
    }

    public function privacy_page(Request $request, $slug)
    {
        $store = getStore($slug);
        // PHASE 03 M0 -- was `redirect()->back()`, i.e. a 302 to the Referer or, with none, to the
        // merchant login. A public legal page for a store that does not exist is a 404.
        if (empty($store)) {
            abort(404);
        }

        return view('front_end.pages.privacy-policy', compact('slug'));
    }

    public function SoftwareDetails($slug)
    {
        $modules_all = Module::all();
        $modules = [];
        if (count($modules_all) > 0) {
            // Ensure that array_rand() returns an array
            $randomKeys = (count($modules_all) === 1)
                ? [array_rand($modules_all)]  // Wrap single key in an array
                : array_rand($modules_all, (count($modules_all) < 6) ? count($modules_all) : 6);  // Get 6 or fewer random keys

            // Proceed with array_intersect_key
            $modules = array_intersect_key(
                $modules_all, // the array with all keys
                array_flip($randomKeys) // flip the random keys array
            );
        }
        $plan = Plan::first();

        $addon = AddOnManager::where('name', $slug)->first();

        if (!empty($addon) && !empty($addon->module)) {
            $module = Module::find($addon->module);
            if (!empty($module)) {
                try {
                    if (module_is_active('LandingPage')) {
                        return view('landing-page::marketplace.index', compact('modules', 'module', 'plan'));
                    } else {
                        return view($module->package_name . '::marketplace.index', compact('modules', 'module', 'plan'));
                    }
                } catch (\Throwable $th) {

                }
            }
        }
        if (module_is_active('LandingPage')) {
            $layout = 'landing-page::layouts.marketplace';

        } else {
            $layout = 'marketplace.marketplace';
        }
        return view('marketplace.detail_not_found', compact('modules', 'layout'));

    }

    public function Software(Request $request)
    {
        // Get the query parameter from the request
        $query = $request->query('query');
        // Get all modules (assuming Module::getByStatus(1) returns all modules)
        $modules = Module::getByStatus(1);

        // Filter modules based on the query parameter
        if ($query) {
            $modules = array_filter($modules, function ($module) use ($query) {
                // You may need to adjust this condition based on your requirements
                return stripos($module->name, $query) !== false;
            });
        }
        // Rest of your code
        if (module_is_active('LandingPage')) {
            $layout = 'landing-page::layouts.marketplace';
        } else {
            $layout = 'marketplace.marketplace';
        }

        return view('marketplace.software', compact('modules', 'layout'));
    }

    public function Pricing()
    {
        $admin_settings = getAdminAllSetting();
        if (module_is_active('GoogleCaptcha') && (isset($admin_settings['google_recaptcha_is_on']) ? $admin_settings['google_recaptcha_is_on'] : 'off') == 'on') {
            config(['captcha.secret' => isset($admin_settings['google_recaptcha_secret']) ? $admin_settings['google_recaptcha_secret'] : '']);
            config(['captcha.sitekey' => isset($admin_settings['google_recaptcha_key']) ? $admin_settings['google_recaptcha_key'] : '']);
        }
        if (auth()->check()) {
            if (auth()->user()->type == 'admin') {
                return redirect('plans');
            } else {
                return redirect('dashboard');
            }
        } else {
            $plan = Plan::first();
            $modules = Module::getByStatus(1);

            if (module_is_active('LandingPage')) {
                $layout = 'landing-page::layouts.marketplace';

                return view('landing-page::layouts.pricing', compact('modules', 'plan', 'layout'));

            } else {
                $layout = 'marketplace.marketplace';
            }

            return view('marketplace.pricing', compact('modules', 'plan', 'layout'));
        }
    }

    /**
     * The dashboard's "Top Category / Top Brand — By Sales" bodies.
     *
     * WS C ADDED THE PERMISSION CHECK, and it is not belt-and-braces. The route
     * (`routes/web.php:399`, `top.brand.category.chart`) carries
     * `['auth','xss','setlocate','verified']` and NO permission middleware, so
     * every principal on the `web` guard in the tenant could POST it and read
     * the store's order revenue broken down by category and by brand — the same
     * money the dashboard's own overview region withholds from anyone without
     * `Manage Order`. The gate is operator-first, because `isAbleTo()` alone
     * refuses the platform operator; the one definition lives in
     * App\Support\MerchantDashboardAbilities.
     *
     * AND THE TWELVE QUERIES NOW NAME THE STORE'S ORDERS. They filtered
     * `categories.store_id` / `product_brands.store_id` only; `orders` carries
     * no global scope in this codebase (it is absent from
     * `config/tenancy.php`'s `scoped_models`), so the sum reached every
     * tenant's orders that happened to reference one of this store's products.
     * `handleRegularUser()` has always scoped its own order figures with
     * `Order::where('store_id', getCurrentStore())`, so the two "By Sales"
     * cards were the only order money on the page not filtered that way.
     *
     * The twelve near-identical query bodies are left as they are: only the
     * date predicate differs between them, and collapsing them is a change to
     * vendored shape that this slice does not need.
     *
     * TWO THINGS AN INDEPENDENT REVIEW OF THIS CHANGE INSISTED BE WRITTEN DOWN:
     *
     *  - `orders.store_id` IS NULLABLE (create_orders_table.php:43), so an order carrying a NULL
     *    discriminator now drops out of both cards' totals. That is a change to a reported
     *    FIGURE, not to isolation, and it narrows rather than widens. If `getCurrentStore()`
     *    itself returns null the predicate becomes `IS NULL` on both columns — tighter than the
     *    unconstrained join it replaces, which reached every tenant.
     *
     *  - DO NOT "COMPLETE THE SWEEP" ONTO `showCategory()`. The thirteenth query that joins
     *    `orders` in this file (around :2030) is a LEFT JOIN, and pasting this same
     *    `->where('orders.store_id', …)` onto its outer builder would silently convert it to an
     *    inner join and drop every category with no orders. For an outer join the predicate
     *    belongs in the ON clause. All twelve here are inner joins, where WHERE and ON are
     *    equivalent, which is why the outer builder is the right place for these.
     */
    public function top_brand_category_chart(Request $request)
    {
        if (! \App\Support\MerchantDashboardAbilities::mayManageOrders()) {
            /* No partial, no figures, and NOT an exception: the dashboard calls
               this on page load for both cards, and a 403 there would put an
               error in the console of a staff member who is simply not
               entitled to the number. The cards themselves are hidden by the
               same check in `dashboard.blade.php`, so a refusal here is only
               reached by a direct POST. */
            return response()->json([
                'html' => '',
                'tab_name' => '',
                'type' => '',
            ]);
        }

        $tab_name = $request->tabId;
        $type = $request->type;

        /*
         * F242 — THE TWELVE CROSS-JOIN QUERIES ARE GONE, replaced by one computation over the real
         * per-line values. The owner approved the fix; the reasoning is in
         * `App\Support\TopSellersByRevenue`, and the short version is that the old shape was wrong
         * in two independent ways:
         *
         *   1. The `numbers.n` 1..100 cross join fanned each order out NON-UNIFORMLY — products
         *      1..k-1 matched once and product k matched 101-k times — so the view's `/100` was
         *      exact only for single-product orders and under-reported every earlier category of a
         *      multi-product order a hundredfold.
         *   2. It summed `orders.final_price`, crediting each matched category the WHOLE order
         *      including shipping and tax, so a two-category order was counted twice over.
         *
         * The window is HALF-OPEN `[from, to)` so a midnight order belongs to one period, and the
         * tab names are unchanged so the dashboard's AJAX and its tab ids keep working.
         */
        $dimension = $type === 'category'
            ? \App\Support\TopSellersByRevenue::CATEGORY
            : \App\Support\TopSellersByRevenue::BRAND;

        $suffix = $type === 'category' ? '-category-order' : '-brand-order';

        [$from, $to] = match ($tab_name) {
            '#today' . $suffix => [now()->startOfDay(), now()->startOfDay()->addDay()],
            '#week' . $suffix => [now()->startOfWeek(), now()->startOfWeek()->addWeek()],
            '#month' . $suffix => [now()->startOfMonth(), now()->startOfMonth()->addMonth()],
            '#year' . $suffix => [now()->startOfYear(), now()->startOfYear()->addYear()],
            default => [null, null],
        };

        $top_sales = collect(\App\Support\TopSellersByRevenue::compute(
            $dimension,
            getCurrentStore(),
            $from?->toDateTimeString(),
            $to?->toDateTimeString()
        ));

        $html = '';
        /* `$type` goes to the view so its EMPTY STATE can name what is missing. The partial is
           shared by the Top Category and Top Brand cards and had no way to tell them apart, so
           both rendered the same bare "No Data Found" -- display-only; nothing is computed from
           it and the populated path is untouched. */
        /* ANA-TOP. THE STORE THE FIGURES WERE COMPUTED FOR GOES TO THE VIEW, rather than the view
           calling `getCurrentStore()` a second time. GAP-156 is the precedent and the reason: the
           customer timeline summed from one store and formatted with another's currency for
           months, because the sum and the symbol each resolved the store independently. One
           resolution, passed down. */
        $top_sales_store = getCurrentStore();

        $html = view('order.brand_category_chart', compact('tab_name', 'top_sales', 'type', 'top_sales_store'))->render();

        $return['html'] = $html;
        $return['tab_name'] = $tab_name;
        $return['type'] = $type;

        return response()->json($return);
    }

    public function best_selling_brand_chart(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('defult_currancy_symbol', $store_id->id);
        if (empty($currency)) {
            $currency = '$';
        }
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            $lastMonth = Carbon::now()->subMonth();
            $prevMonth = strtotime('-1 month');
            $start = strtotime(date('Y-m-01', $prevMonth));
            $end = strtotime(date('Y-m-t', $prevMonth));

            $customer = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', null)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            $lastDayofMonth = Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $lastday = date('j', strtotime($lastDayofMonth));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE)); // Extract day of the month

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE));
                $userTotalArray[$day][] = $order->order_date;

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            $lastDayofMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $startDate = now()->subDays(6);

            $TotalOrder = 0;
            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            $monthList = [];
            $previous_week = strtotime('-1 week +1 day');

            for ($i = 0; $i <= 7 - 1; $i++) {
                $date = date('Y-m-d', $previous_week);
                $previous_week = strtotime(date('Y-m-d', $previous_week) . ' +1 day');
                $monthList[] = $this->ssDayLabel(strtotime($date));

                $ordersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get();
                $TotalOrder += $ordersForDate->count();
                $totalPurchasedItemsForDate = 0;

                foreach ($ordersForDate as $order) {
                    $products = json_decode($order->product_json, true);

                    $totalProductQuantity = array_reduce($products, function ($carry, $product) {
                        return $carry + intval($product['qty']);
                    }, 0);
                    $totalPurchasedItemsForDate += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $PurchasedItemTotal[] = $totalPurchasedItemsForDate;

                $totalOrdersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');

                $NetSaleTotal[] = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;
            }
        } elseif ($request->chart_data == 'year') {

            $TotalOrder = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $order = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', date('Y'))
                ->get()->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $netSaleTotalArray[$order->month][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$order->month][] = (float) $order->final_price;
                $CouponTotalArray[$order->month][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$order->month][] = (float) $order->delivery_price;
                $OrderTotalArray[$order->month][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$order->month][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            for ($i = 1; $i <= 12; $i++) {

                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $monthlySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $average = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageGrossSales[] = $average;

                $monthlySales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $netsaleaverage = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageNetSales[] = $netsaleaverage;
            }
            $monthList = $month = $this->yearMonth();
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $form_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $start_date = date('Y-m-d', strtotime($date_range[0]));
                    $end_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {

                $form_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }
            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $monthLists = Order::whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $monthLists = $monthLists->get();

            foreach ($monthLists as $monthLists_date) {
                $data[] = date('y-n-j', strtotime($monthLists_date->order_date));
                $data_month[] = date('Y-m-d', strtotime($monthLists_date->order_date));
            }
            if (!empty($data) && is_array($data)) {
                $List = array_values(array_unique($data));
                $monthList_data = $List;
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            } else {
                $List = [];
                $monthList_data = [];
                $data_month[] = date('y-n-j');
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            }

            foreach ($orders as $order) {
                $day = date('y-n-j', strtotime($order->DATE));
                $userTotalArray[$day][] = date('y-n-j', strtotime($order->order_date));

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            if (!empty($data) && is_array($data)) {
                foreach ($monthList_data as $month) {
                    $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                    $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                    $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                    $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                    $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                    $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                    $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                    $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                    $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                    $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
                }
            } else {
                $month = date('y-n-j');
                $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
        }

        $html = '';
        $html = view('reports.order_chart_data', compact('TotalOrder', 'PurchasedProductItemTotal', 'TotalgrossSale', 'currency', 'TotalNetSale', 'TotalCouponAmount', 'TotalShippingCharge'))->render();

        $return['html'] = $html;

        $return['TotalOrderCount'] = $TotalOrderCount;
        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['AverageNetSales'] = $averageNetSales;
        $return['GrossSaleTotal'] = $GrossSaleTotal;
        $return['AverageGrossSales'] = $averageGrossSales;
        $return['PurchasedItemTotal'] = $PurchasedItemTotal;
        $return['ShippingTotal'] = $ShippingTotal;
        $return['CouponTotal'] = $CouponTotal;
        $return['monthList'] = $monthList;
        Session::put('order_line_chart_report', $return);

        return response()->json($return);
    }

    public function pageError(Request $request, $slug)
    {
        $store = getStore($slug);
        // PHASE 03 M0 -- was `redirect()->back()`. This route renders a store's OWN themed error
        // page; for a store that does not exist there is no theme to render it in, so the generic
        // not-found response is the correct answer rather than a bounce to the referrer.
        if (empty($store)) {
            abort(404);
        }

        return view('front_end.pages.error', compact('store'));
    }

    /**
     * THE PUBLIC WISHLIST PAGE. GAP-286.
     *
     * WHAT WAS HERE: a commented-out `auth('customers')` guard, a commented-out scoped query, and
     * live beneath them `Wishlist::with('ProductData')->get()` -- every wishlist row belonging to
     * every customer of every store, each with its product eager-loaded, on a route that carries no
     * `auth` (`routes/customer.php:123`). `Wishlist` is in no `scoped_models` entry and carries no
     * global scope, so nothing narrowed it.
     *
     * MEASURED, on two stores each holding one wishlist row: an anonymous request collected BOTH.
     *
     * THE DEAD QUERY IS REMOVED RATHER THAN THE GUARD RESTORED, and that is the part worth reading
     * before changing this again.
     *
     *   - NOTHING EVER READ `$wishlists`. `front_end/pages/wishlist.blade.php` is the only copy of
     *     that view -- no theme override exists in any of the four theme roots -- and it references
     *     the variable zero times. The page is a shell: its `.wishlist-table-section` div is empty
     *     and `public/assets/js/front-theme.js:1108` fills it by AJAX.
     *   - THAT AJAX PATH IS THE REAL ONE AND IT IS ALREADY CORRECT. `WishlistController` reads
     *     `where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)` and
     *     hands an anonymous caller an empty collection.
     *   - SO RESTORING THE COMMENTED-OUT GUARD WOULD REMOVE A WORKING PAGE. This route is public by
     *     design; an anonymous visitor is meant to see the shell and an empty list. A redirect would
     *     be a user-visible regression dressed as a security fix, and
     *     `PublicWishlistQueryScopeTest::test_control_an_anonymous_visitor_still_gets_the_page`
     *     fails if anyone tries it.
     *
     * WHAT THE DEFECT ACTUALLY WAS, stated precisely because GAP-286 was dispatched as a cross-tenant
     * DISCLOSURE: there was no disclosure -- the rows were loaded and discarded. The live problem
     * was the READ: an unbounded, unauthenticated, platform-wide query with an eager load, on a URL
     * anyone can request as often as they like, growing with every wishlist ever saved. Removing it
     * costs nothing a visitor can see, because nothing a visitor sees came from it.
     */
    public function wishlist(Request $request)
    {
        return view('front_end.pages.wishlist');
    }

    public function showCategory(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
       
        $categories = Category::where('status', 1)->where('store_id', $store->id)->get();
        $topCategories = Category::select('categories.id', 'categories.slug', 'categories.image_path', 'categories.name', DB::raw('COUNT(orders.id) as total_sales'))
        ->leftjoin('products', 'categories.id', '=', 'products.category_id')
        ->leftjoin('orders', 'products.id', '=', 'orders.product_id')
        ->where('categories.store_id', $store->id)
        ->where('categories.status', 1)
        ->groupBy('categories.id', 'categories.name')
        ->orderBy('total_sales', 'DESC')
        ->limit(2)
        ->get();
        return view('front_end.pages.collection-list', compact('store', 'categories', 'topCategories'));
    }

    public function orderTrack(Request $request, $slug)
    {
        $store = getStore($request->route('storeSlug'));
        if (!$store) {
            abort(404);
        }        
        return view('front_end.pages.track-order', compact('store'));
    }

    public function customPage(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        if ($store && $request->route('page_slug')) {
            /*
             * THE `store_id` PREDICATE, WITHOUT WHICH THIS SERVED ANY STORE'S PAGE UNDER ANY SLUG.
             *
             * `$store` is resolved from the route on the line above and was then never used as a
             * predicate, so `GET /store-b/page/<a-slug-belonging-to-store-a>` answered 200 with
             * store A's `page_content` inside store B's theme — anonymously, on an open storefront
             * route (`routes/customer.php:132`).
             *
             * NOTHING UNDERNEATH CONTAINED IT. `Page` is `use HasFactory, Cachable;` with no
             * `BelongsToStore` and no `StoreScope`, and it appears ZERO times in
             * `config/tenancy.php`'s `scoped_models` — the "no scope at all" variety rather than
             * the inert-scope variety `Product` and `Category` have, so there was no predicate for
             * any principal.
             *
             * ITS OWN SIBLING ALREADY HAD THE CLAUSE: `article_page` reads
             * `Blog::where('slug', $blogSlug)->where('store_id', $store_id)` at `:1235`. This was a
             * gap in one handler of a pair, not a platform design.
             *
             * A PAGE SLUG IS MERCHANT-CHOSEN FREE TEXT, so two stores both having `about-us` or
             * `terms` is the ordinary case, not an exotic one — and before this, which of them
             * `first()` returned was arbitrary. Found by the independent review of the GAP-117 SEO
             * change, which did not introduce the leak but was about to classify this route as
             * "a store's public content".
             */
            $page   = Page::where('page_slug', $request->route('page_slug'))
                ->where('store_id', $store->id)
                ->where('page_status', 1)->first();
            if ($page) {
                $slug = $store->slug;               
                return view('front_end.pages.page', compact('page', 'store'));
            } else {
                abort(404);
            }
        }
    }
}

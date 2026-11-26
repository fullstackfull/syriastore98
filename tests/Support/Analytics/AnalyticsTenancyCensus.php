<?php

namespace Tests\Support\Analytics;

/**
 * GAP-167 / P05-19 — EVERY ELOQUENT READ OF AN UNSCOPED MODEL IN THE ANALYTICS AND REPORT SURFACES,
 * AND WHERE ITS STORE PREDICATE'S VALUE COMES FROM.
 * =================================================================================================
 *
 * THE RULE THIS APPLIES, in the GAP-167 row's own words: "A census of every read of `Order`, `Visit`
 * and the other unscoped models in the analytics and report controllers, asserting a store predicate
 * DERIVED FROM THE PRINCIPAL on each: the raw-query inventory's rule applied to Eloquent, and to
 * where the predicate's value comes from rather than to whether a column name appears."
 *
 * SO THE QUESTION IS NOT "does the word `store_id` occur". `RawQueryTenancyInventoryTest` says that
 * of itself as its own first limit, and GAP-167 records what the limit cost: the operator
 * dashboard's visit-log read said `whereNotNull('store_id')` and `pluck('store_id')`, was counted as
 * tenant-aware, and filtered nothing. This census reads the predicate's VALUE EXPRESSION and asks
 * where that value came from.
 *
 * WHY ELOQUENT AT ALL, when there is a global scope. Because for these models there is not one:
 * `StoreScope::isActiveFor()` short-circuits for any model absent from `config/tenancy.php`'s
 * `scoped_models`, so `Order`, `Visit`, `Store`, `User`, `Product`, `Category`, `Wishlist`,
 * `Testimonial`, `ProductImage` and `DeliveryAddress` add NO predicate for any principal -- not a
 * merchant, not the operator, not an unresolved one (`null_tenant => 'open'` adds nothing either).
 * An Eloquent read of one of them is exactly as reachable as a `DB::table()` statement, and the
 * raw-query inventory does not look at it at all. That is the gap the row names.
 *
 * ============================================================================================
 *  THE THREE VERDICTS
 * ============================================================================================
 *
 *  principal — the statement carries a store predicate whose value is derived from the
 *              AUTHENTICATED PRINCIPAL: `getCurrentStore()`, `Auth::user()`, `creatorId()`,
 *              `OperatorStoreSelection::reportStoreId()`, `resolveOwnedStore()` (which
 *              ownership-checks a requested id against the caller's own tenant root), a
 *              `MetricScope` (whose predicate the `Metric` base applies), or a local variable this
 *              file binds from one of those and from nothing else.
 *
 *  request   — the predicate exists and its value comes from CALLER-SUPPLIED INPUT:
 *              `$request->store_id`, `request('store_id')`, a `$requests_data[...]` key, or a local
 *              variable bound from one. CLAUDE.md: "A tenant predicate built from caller-supplied
 *              input is not a tenant check -- it only compares the caller's own two values."
 *
 *  none      — the statement carries no store predicate at all.
 *
 * ============================================================================================
 *  THE FOUR LIMITS OF THE MEASUREMENT, STATED UP FRONT
 * ============================================================================================
 *
 * LIMIT 1. `principal` IS NOT A PROOF OF CORRECTNESS. It says the value came from the principal,
 * not that the predicate is the right one -- a read scoped to the caller's own store that should
 * have been scoped to an order's store is still wrong and counts as `principal` here. So the
 * non-principal columns are a LOWER BOUND on the exposure, never an upper one.
 *
 * AND `ownership_checked` IS WEAKER STILL, QUANTIFIED RATHER THAN ASSERTED. It means a gate token
 * is present SOMEWHERE IN THE ENCLOSING FUNCTION, not that this read goes through one. MEASURED by
 * a declared mutation (`tests/Support/bin/mutate-gap167.py`): deleting ONE
 * `reportSelectionWithinCurrentStore(ProductBrand::class, ...)` call from `ReportController` moves
 * NO number here, because `order_brand_reports` is a single ~555-line method holding FOUR of those
 * calls, one per `chart_data` arm -- three survive and still credit every site in the method,
 * including the ten in the arm that just lost its gate. Attributing a gate to an ARM needs
 * branch-aware scoping and is a slice of its own. An independent review predicted this survivor
 * before it was run; it is declared in that file rather than retired.
 *
 * LIMIT 2. THE STATEMENT IS A TEXT WINDOW: the matched line plus following lines to the first `;`,
 * capped at 40 -- AND, when the matched line assigns a builder to a variable, the following lines
 * that call a method on that same variable, with their continuations, up to a reassignment of it
 * from something other than itself. That widening is not a convenience: `ReportController` writes
 * `$orders = Order::selectRaw(...);` and adds `->where('store_id', ...)` in a LATER statement
 * twenty times, and without it twenty sites read as carrying no predicate while carrying a
 * principal-derived one.
 *
 * What is still outside the window, and still reads as `none`: a predicate applied to the builder
 * further away than that, in another method, or after a `;` that sits INSIDE a closure (which ends
 * the first loop early -- `StoreInventoryValuation`'s join is exactly that shape). Those are
 * dispositioned by hand in the test's own table rather than guessed at.
 *
 * AND THE WIDENING ITSELF HAS BEEN WRONG TWICE, both times found by the same mutation and both
 * times in the dangerous direction -- CREDITING a site with a predicate that belongs to a
 * different query. It took any line CONTAINING the variable, so a line that merely mentions it
 * (`$returning = $buyers === [] ? 0 : Order::query()`) handed over its own predicate; and the
 * first repair matched the variable followed by `->` or `=`, which `$buyers === []` satisfies.
 * It is now a method call on the variable, or an assignment to it at the start of a line.
 *
 * LIMIT 3. VARIABLE TRACING IS LEXICAL AND PER FILE. A variable is `principal` when every
 * assignment to it in the file is principal-derived, `request` when any assignment is
 * request-derived, and unknown otherwise. It follows two levels of indirection and no more. A
 * value threaded through a method ARGUMENT from another file is not followed; a method parameter is
 * therefore unknown, and its call sites are dispositioned by hand.
 *
 * LIMIT 4. COMMENTS ARE STRIPPED THROUGH PHP'S OWN TOKENIZER, so prose about a defect cannot
 * satisfy or break this census -- CLAUDE.md §3 records eight occasions it did. The stripper
 * preserves newlines, so every line number here is the line number in the real file.
 *
 * LIMIT 5. THE WATCH LIST IS A NAMED SET OF THIRTEEN MODELS, NOT EVERY UNSCOPED MODEL THESE FILES
 * READ -- and until an independent review asked, nothing recorded the difference.
 * `test_every_model_this_census_watches_really_adds_no_predicate` asserts the list contains nothing
 * SCOPED; nothing asserted it contains everything unscoped. The proof was `ProductBrand`:
 * `Category`'s identical twin, read 17 times in `ReportController` with 10 of them a bare `find()`,
 * carrying NO `BelongsToStore` and no `StoreScope` at all -- strictly MORE open than `Category`'s
 * inert scope -- and entirely uncounted, so removing a gate call that narrowed those ids moved no
 * number. It is now watched, which took the census from 312 sites to 337.
 *
 * The gap is now a measurement rather than a claim:
 * `test_the_watch_list_covers_every_unscoped_model_these_files_read` requires every Eloquent model
 * these files read that is absent from `scoped_models` to be either WATCHED or exempted BY NAME
 * WITH A REASON in `self::NOT_WATCHED`, in both directions -- a stale exemption fails it too, and
 * deleted the two models this author had guessed at. **FIVE OF THE TWENTY-FOUR EXEMPTIONS ARE
 * MONEY-BEARING AND ARE NAMED AS THE NEXT ONES TO WATCH** (`OrderCouponDetail`, `OrderTaxDetail`,
 * `OrderBillingDetail`, `Tax`, `Shipping`): each carries money or a money rule and adds no
 * predicate for any principal, exactly like `Order`. Watching them re-measures every total here and
 * needs a disposition per shape, so they are named rather than quietly absent.
 *
 * Neither limit is a reason not to measure. An exact, reproducible lower bound that moves when the
 * code moves is worth more than an estimate, and every limit is recorded so no reader can mistake
 * this for a clean bill of health.
 */
final class AnalyticsTenancyCensus
{
    /**
     * THE FILES, AND WHY EACH ONE IS IN. Named explicitly rather than globbed over `app/`, because
     * "the analytics and report surfaces" is a claim about which files do that job and a reader must
     * be able to check it. Four of the six controllers are the ones GAP-167's "WHAT REMAINS"
     * paragraph names by name; the two analytics controllers are the screens the metric layer feeds.
     *
     * THE WHOLE FILE IS READ, NEVER A METHOD LIST. The tenancy skill's fourth census failure is "an
     * exemption scoped to the methods somebody happened to look at" -- it said "update and destroy
     * are stubs", which was true, while the live defect was in `store()`. So `HomeController` and
     * `Api/DashboardController` are censused entire even though only part of each is analytics.
     *
     * @var list<string>
     */
    public const FILES = [
        // The report centre itself. GAP-167: "ReportController (most of it unreachable by the suite)".
        'app/Http/Controllers/ReportController.php',
        // GAP-167 names it: the storefront theme's own analytics screen.
        'app/Http/Controllers/ThemeAnalyticController.php',
        // GAP-167 names it: the Flutter admin app's dashboard and report endpoints. Censused whole.
        'app/Http/Controllers/Api/DashboardController.php',
        // GAP-167 names `HomeController::handleRegularUser()`; the file is censused whole.
        'app/Http/Controllers/HomeController.php',
        // The two screens the metric layer feeds.
        'app/Http/Controllers/MerchantAnalyticsController.php',
        'app/Http/Controllers/PlatformAnalyticsController.php',
    ];

    /**
     * The producer directories read in full: the metric layer and its two report siblings, plus the
     * scheduled-report runner, which reads the same figures with no HTTP request behind it at all.
     *
     * @var list<string>
     */
    public const DIRECTORIES = [
        'app/Support/Metrics',
        'app/Support/Analytics',
        'app/Support/Reports',
        'app/Services/Reports',
    ];

    /**
     * THE MODELS THAT ADD NO PREDICATE. Nine of them are CLAUDE.md's own measured table of models
     * with no `StoreScope` at all plus the two that carry the scope and are absent from
     * `scoped_models`; `Visit` is Shetabit's, carries `store_id` since the 2026-11-20 attribution
     * fix, and is in no allowlist either. `ClaudeMdScopedModelClaimTest` re-measures that table
     * against the booted container in both directions, so this list has a live cross-check.
     *
     * A model that is REALLY scoped (`Customer`, `Cart`, `SupportTicket`, the other eight) is
     * deliberately absent: the scope attaches a predicate for it, so an Eloquent read is not the
     * open door this census is about.
     *
     * @var list<string>
     */
    public const UNSCOPED_MODELS = [
        'Order', 'Visit', 'Store', 'User', 'Product', 'Category', 'ProductBrand',
        'Wishlist', 'Testimonial', 'ProductImage', 'DeliveryAddress', 'ProductVariant', 'Coupon',
    ];

    /**
     * MODELS THESE FILES READ THAT ARE UNSCOPED AND ARE DELIBERATELY NOT WATCHED, with the reason.
     *
     * THE LIST ABOVE IS A NAMED SET, NOT "EVERY UNSCOPED MODEL", and until an independent review
     * said so nothing recorded the difference -- `test_every_model_this_census_watches_really_adds_no_predicate`
     * asserts the list contains nothing SCOPED and nothing asserted it contains everything
     * unscoped. `ProductBrand` was the proof: `Category`'s identical twin, read 17 times in
     * `ReportController` with 10 of them a bare `find()`, carrying NO `BelongsToStore` and no
     * `StoreScope` at all -- strictly more open than `Category`'s inert scope -- and uncounted.
     * It is now watched, and `test_the_watch_list_covers_every_unscoped_model_these_files_read`
     * makes the completeness question a measurement rather than a claim.
     *
     * An entry here is an exemption with a stated reason, which a reader can disagree with. An
     * absence is a model nobody has looked at.
     *
     * @var array<string, string>
     */
    public const NOT_WATCHED = [
        /* Not a tenant-discriminated model: `countries`, `states` and `cities` are platform
           reference data with no `store_id` column, so there is no predicate to look for. */
        'Country' => 'platform reference data, no store_id column',
        'State' => 'platform reference data, no store_id column',
        'City' => 'platform reference data, no store_id column',
        'Plan' => 'the platform\'s own subscription catalogue; every tenant reads the same rows',
        'PlanOrder' => 'a tenant\'s subscription PAYMENT to the platform, scoped by order not store',
        'AddOnManager' => 'the platform module registry; one row set for every tenant',
        'Utility' => 'a helper model over `settings`, read through its own static API',
        /* THE TWO I GUESSED AND THE TEST DELETED. `Role` and `Permission` were in this list on
           the assumption that these controllers read them; the completeness test's SECOND
           direction said they do not, so the entries were dead text about models nobody here
           touches. An exemption outliving its subject is how a register becomes decoration. */
        'Setting' => 'read through `Setting::where(store_id, ...)` helpers whose predicate this census does see when it is written inline; the model itself carries no figure',

        /*
         * THE FIVE MONEY-BEARING ONES, AND THEY ARE THE NEXT ONES TO WATCH RATHER THAN SETTLED.
         *
         * Each is read in a censused file, carries money or a money rule, and is in no
         * `scoped_models` entry -- so its reads add no predicate for any principal, exactly like
         * `Order`. They are exempted HERE AND NOW only because watching them re-measures every
         * total in this census and needs a disposition per shape, which is a slice of its own; the
         * exemption names them so the next reader has a target rather than a feeling. An
         * independent review raised the completeness question that found them.
         */
        'OrderCouponDetail' => 'MONEY, NEXT TO WATCH: the coupon-discount source `CouponRedemption` sums; unscoped',
        'OrderTaxDetail' => 'MONEY, NEXT TO WATCH: per-order tax rows; unscoped',
        'OrderBillingDetail' => 'MONEY, NEXT TO WATCH: per-order billing rows; unscoped',
        'Tax' => 'MONEY RULE, NEXT TO WATCH: tax definitions a report applies; unscoped',
        'Shipping' => 'MONEY RULE, NEXT TO WATCH: shipping charges a report sums; unscoped',

        /* Coupon and plan rows: tenant-discriminated, read by the marketing and SaaS handlers of
           the wide controllers rather than by any analytics figure. */
        'UserCoupon' => 'a shopper\'s coupon claim, read by the marketing handlers, not by a figure',
        'PlanCoupon' => 'the PLATFORM\'s own subscription coupons; every tenant reads the same rows',
        'PlanUserCoupon' => 'a tenant\'s claim on a platform subscription coupon, scoped by plan order',
        'PlanRequest' => 'a tenant\'s request to the platform; platform-side by construction',
        'FlashSale' => 'a promotion definition; `FlashSaleCondition` is the scoped half and IS in scoped_models',

        /* Catalogue and CMS rows read by the storefront half of `HomeController`, carrying no
           figure this census is about. The storefront census owns them. */
        'Blog' => 'storefront CMS; the storefront census owns it',
        'BlogCategory' => 'storefront CMS; the storefront census owns it',
        'Page' => 'storefront CMS; the storefront census owns it',
        'Faq' => 'storefront CMS; the storefront census owns it',
        'Contact' => 'a storefront contact-form row; the storefront census owns it',
        'Newsletter' => 'a storefront newsletter signup; the storefront census owns it',
        'ProductLabel' => 'catalogue presentation; no figure',
        'ProductQuestion' => 'storefront Q&A; the storefront census owns it',
        'ShippingMethod' => 'a per-store shipping method definition; read by the checkout, not by a figure',
        'TaxOption' => 'a per-store tax option definition; read by the checkout, not by a figure',

        /* Platform and per-principal rows with no store figure. */
        'AppSetting' => 'platform-wide application settings; one row set for every tenant',
        'CurrencyRate' => 'per-store currency rows read by name through `CurrencySymbol`; carries a code, not a figure',
        'AnalyticsReportSchedule' => 'scoped by `forCurrentPrincipal()` on the model itself, which this census does not read as a `store_id` predicate',
        'SavedView' => 'scoped by `forCurrentPrincipal()` on the model itself, as above',
        'StoreNotification' => 'a per-store notification row; carries no figure',
        'DeliveryEarning' => 'a driver\'s earning row; Phase 04\'s subject, not an analytics figure',
    ];

    /** Expressions whose value can only have come from the authenticated principal. */
    private const PRINCIPAL_TOKENS = [
        'getCurrentStore(',
        'Auth::user(',
        'auth()->user(',
        'auth()->id(',
        'creatorId(',
        'OperatorStoreSelection::reportStoreId(',
        'OperatorStoreSelection::storeId(',
        'resolveOwnedStore(',
        /* THE GATE VOCABULARY IS INCOMPLETE BY CONSTRUCTION and an independent review said so:
           `Api/DashboardController` has at least three ownership helpers and this list names
           them one at a time. `resolveChangeableOrder(` is the third, found by that review at
           `:318`. A helper nobody has added classifies as `request`, which is the safe direction
           for this census to be wrong in -- but it IS being wrong, and the dispositions say so
           rather than asserting the absence of a gate nobody looked for. */
        'resolveChangeableOrder(',
        'MetricScope::store(',
        'MetricScope::stores(',
        'merchantTenantRoot(',
        '$this->scope',
    ];

    /** Expressions whose value came from the caller's own request. */
    private const REQUEST_TOKENS = [
        '$request->',
        'request()->',
        'request(',
        '$requests_data[',
        '$_GET',
        '$_POST',
        '$_REQUEST',
    ];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * Every site, with its verdict.
     *
     * @return list<array{file: string, line: int, model: string, call: string, verdict: string, predicate: ?string}>
     */
    public function sites(): array
    {
        $sites = [];

        foreach ($this->files() as $relative) {
            foreach ($this->sitesIn($relative) as $site) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * The sites of ONE file, so the pattern can be self-tested against a string of source.
     *
     * Added because the premise test could not see the defect that hid 34 reads: it required the
     * known shapes to be found SOMEWHERE in the tree, and the unqualified spelling satisfied it
     * while the fully-qualified one was invisible. A self-test needs a door into one file.
     *
     * @return list<array{file: string, line: int, model: string, call: string, verdict: string, predicate: ?string}>
     */
    public function sitesIn(string $relative): array
    {
        $sites = [];

        {
            $lines = explode("\n", $this->codeOnly($relative));
            $functions = $this->functionRanges($lines);
            /*
             * THE LOOKBEHIND NO LONGER EXCLUDES A BACKSLASH, AND THAT ONE CHARACTER HID 34 READS.
             *
             * Its class used to contain a literal backslash, so a FULLY-QUALIFIED reference --
             * `\App\Models\Order::where(...)` -- failed it and was invisible. Measured by an
             * independent review: 31 such sites in `Api/DashboardController.php`, the file this
             * class censuses "whole", 2 in `PlatformAnalyticsFigures.php` and 1 in
             * `HomeController.php`, several with no store predicate at all -- among them
             * `\App\Models\DeliveryAddress::where('customer_id', ...)` on a model carrying no
             * scope whatever.
             *
             * It is the tenancy skill's §7 census failure in a fourth dress, keyed on SPELLING
             * rather than on the handler, and this class's own docblock claimed to have avoided it.
             * The exclusions that remain do real work: `\w` stops `SomeOrder::`, `$` stops
             * `$Order::`, `>` stops `->Order::`.
             */
            $pattern = '/(?<![\w$>])(' . implode('|', self::UNSCOPED_MODELS) . ')::(\w+)\s*\(/';

            foreach ($lines as $index => $line) {
                if (! preg_match($pattern, $line, $match)) {
                    continue;
                }

                /*
                 * THE BINDINGS ARE THE ENCLOSING FUNCTION'S, NOT THE FILE'S, and that correction
                 * is the difference between a census and noise. File-wide tracing read
                 * `HomeController`'s storefront handlers as request-derived because `$store` is
                 * assigned in twenty methods of that 2,400-line controller and ONE of them reads
                 * a request key -- so a variable bound from `getStore($storeSlug)` in the method
                 * actually being measured inherited another method's taint. Measured: 15 sites
                 * reported as `request` that are the route's own store.
                 */
                [$from, $to] = $this->enclosing($functions, $index);
                $body = array_slice($lines, $from, $to - $from, true);

                $statement = $this->statementAt($lines, $index);
                [$verdict, $predicate] = $this->classify(
                    $statement,
                    $this->bindings($body),
                    $this->parameters($body),
                    implode("\n", $body)
                );

                $sites[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'model' => $match[1],
                    'call' => $match[2],
                    'verdict' => $verdict,
                    'predicate' => $predicate,
                ];
            }
        }

        return $sites;
    }

    /** @return list<string> relative paths, the named files then every PHP file of the directories */
    public function files(): array
    {
        $files = [];

        foreach (self::FILES as $relative) {
            if (is_file($this->root . '/' . $relative)) {
                $files[] = $relative;
            }
        }

        foreach (self::DIRECTORIES as $directory) {
            $found = glob($this->root . '/' . $directory . '/*.php') ?: [];
            sort($found);

            foreach ($found as $path) {
                $files[] = ltrim(str_replace($this->root, '', $path), '/');
            }
        }

        return $files;
    }

    /**
     * THE STORE PREDICATE'S VALUE EXPRESSION, and where that value came from.
     *
     * The predicate is recognised in the four shapes this codebase writes: `where('store_id', X)`,
     * `whereIn('store_id', X)`, `where('<table>.store_id', X)` and `where(['store_id' => X])`. A
     * `MetricScope` counts as the predicate itself, because `Metric` applies it in the expression
     * that names the table and `MetricScope` has a private constructor, no "every tenant" factory,
     * and throws on an empty list -- which is a stronger guarantee than a hand-written `where`.
     *
     * @param  array<string, string>  $bindings  variable name => principal|request
     * @return array{0: string, 1: ?string}
     */
    private function classify(string $statement, array $bindings, array $parameters = [], string $body = ''): array
    {
        if ($this->hasAny($statement, ['MetricScope::store(', 'MetricScope::stores(', '$this->scope'])) {
            return ['principal', 'MetricScope'];
        }

        $expressions = [];

        /* `->where` AND `::where`. The first version of this pattern required the instance arrow,
           and `ReportController` writes `Order::where('store_id', OperatorStoreSelection::reportStoreId())`
           -- a STATIC call -- 33 times. Measured: the census read 79 of that file's sites as
           carrying no predicate when most of them carry a principal-derived one. A census that
           cannot see the predicate it is looking for reads exactly like one that looked and found
           nothing, which is the failure mode the tenancy skill's §7 lists four times. */
        if (preg_match_all('/(?:->|::)where(?:In|Integer)?\s*\(\s*[\'"][\w.]*store_id[\'"]\s*,\s*([^;]{0,120}?)\s*[,)]/s', $statement, $matches)) {
            foreach ($matches[1] as $expression) {
                $expressions[] = $expression;
            }
        }

        if (preg_match_all('/[\'"][\w.]*store_id[\'"]\s*=>\s*([^,\]\)]{0,120})/s', $statement, $matches)) {
            foreach ($matches[1] as $expression) {
                $expressions[] = $expression;
            }
        }

        if ($expressions === []) {
            /*
             * TWO MECHANISMS THAT ARE THE HANDLER'S REAL TENANT CHECK AND ARE NOT A `store_id`
             * PREDICATE ON THIS STATEMENT. Each is named, each is asserted present in the enclosing
             * function, and each is a VERDICT with its own column rather than an exemption -- so a
             * reader counts them and can disagree.
             *
             *   ownership-checked: the handler resolved the store through `resolveOwnedStore()` or
             *     `reportSelectionWithinCurrentStore()` first. Both compare a requested id against
             *     the caller's own tenant root (`UserPolicy::merchantTenantRoot($user)`), which is
             *     the admin API's documented tenancy design -- `config/tenancy.php` records why the
             *     global scope is deliberately NOT applied to those models and names the ~100 sites.
             *     A read after that gate is reading a row the principal owns.
             *
             *   route-store: the handler resolved the store from the PATH, `getStore($slug)`. That is
             *     the storefront's own tenant and CLAUDE.md states it as the storefront rule --
             *     "a storefront handler takes its store from the route" -- because `getCurrentStore()`
             *     answers for a merchant's ADMIN store on a storefront request and P1-SEC-71 stored
             *     ticket edits in a folder no store's id is part of.
             *
             * NEITHER IS A CLEAN BILL OF HEALTH. `LIMIT 1` applies to both: the gate being present
             * in the function does not prove this particular read goes through it.
             */
            if ($this->hasAny($body, ['resolveOwnedStore(', 'reportSelectionWithinCurrentStore('])) {
                return ['ownership_checked', null];
            }

            if ($this->hasAny($body, ['getStore(', 'getStoreBySlug('])) {
                return ['route_store', null];
            }

            return ['none', null];
        }

        $verdicts = [];

        foreach ($expressions as $expression) {
            $verdicts[] = $this->source($expression, $bindings, $parameters);
        }

        /* A statement with TWO store predicates is as weak as its weakest: `request` outranks
           `principal`, because the caller-supplied one is the one an attacker controls, and
           `unknown` outranks `principal` because this census cannot vouch for it. */
        foreach (['request', 'unknown', 'parameter'] as $weaker) {
            if (in_array($weaker, $verdicts, true)) {
                return [$weaker === 'unknown' ? 'none' : $weaker, implode(' | ', $expressions)];
            }
        }

        return ['principal', implode(' | ', $expressions)];
    }

    /**
     * @param  array<string, string>  $bindings
     * @return string principal|request|unknown
     */
    private function source(string $expression, array $bindings, array $parameters = []): string
    {
        /*
         * THE PRINCIPAL IS TESTED FIRST, AND THE OLD ORDER MADE 44 DISPOSITIONS SAY SOMETHING FALSE.
         *
         * `REQUEST_TOKENS` used to be tested first, so
         * `where('store_id', $this->resolveOwnedStore($request->store_id)->id)` -- the admin API's
         * DOCUMENTED shape, gate the requested id then filter on the gated store -- classified as
         * `request`, and the disposition's reason read "with no `resolveOwnedStore()` in the
         * handler". That sentence pointed the next reader at about forty-five sites of remediation
         * work against a hole that is not there. Found by independent review, which verified the
         * gate at `DashboardController.php:1755`, `:1806`, `:1934`, `:2065`, `:4373` and `:4860`
         * and counted `resolveOwnedStore(` 144 times in that file.
         *
         * An expression carrying BOTH a request token and a gate token is GATED: the gate's whole
         * job is to take a caller-supplied id and refuse it unless the principal owns it. An
         * expression carrying only a request token is unchanged.
         */
        if ($this->hasAny($expression, self::PRINCIPAL_TOKENS)) {
            return 'principal';
        }

        if ($this->hasAny($expression, self::REQUEST_TOKENS)) {
            return 'request';
        }

        if (preg_match_all('/\$(\w+)/', $expression, $matches)) {
            $verdicts = [];

            foreach ($matches[1] as $variable) {
                $verdicts[] = $bindings['$' . $variable]
                    ?? (isset($parameters['$' . $variable]) ? 'parameter' : 'unknown');
            }

            if (in_array('request', $verdicts, true)) {
                return 'request';
            }

            if (in_array('parameter', $verdicts, true)) {
                return 'parameter';
            }

            if (in_array('unknown', $verdicts, true)) {
                return 'unknown';
            }

            return $verdicts === [] ? 'unknown' : 'principal';
        }

        /* A literal -- `where('store_id', 1)` -- is not the principal's store. */
        return 'unknown';
    }

    /**
     * EVERY ASSIGNMENT IN THE FILE, classified. Two passes, so a variable assigned from another
     * variable is followed one level; `LIMIT 3` records that it stops there.
     *
     * @param  array<int, string>  $lines
     * @return array<string, string>
     */
    private function bindings(array $lines): array
    {
        $bindings = [];
        $assignments = [];

        foreach ($lines as $line) {
            if (! preg_match('/(\$\w+)\s*=\s*([^=].{0,400})$/', $line, $match)) {
                continue;
            }

            $assignments[$match[1]][] = $match[2];
        }

        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($assignments as $variable => $expressions) {
                $verdicts = [];

                foreach ($expressions as $expression) {
                    /* PRINCIPAL FIRST, for the reason `source()` records: an assignment that
                       passes a request id THROUGH an ownership gate is gated, not request-sourced,
                       and testing the request token first called it the latter. */
                    if ($this->hasAny($expression, self::PRINCIPAL_TOKENS)) {
                        $verdicts[] = 'principal';

                        continue;
                    }

                    if ($this->hasAny($expression, self::REQUEST_TOKENS)) {
                        $verdicts[] = 'request';

                        continue;
                    }

                    $referenced = [];

                    if (preg_match_all('/\$(\w+)/', $expression, $found)) {
                        foreach ($found[1] as $name) {
                            if ('$' . $name === $variable) {
                                continue;
                            }

                            $referenced[] = $bindings['$' . $name] ?? 'unknown';
                        }
                    }

                    $verdicts[] = match (true) {
                        in_array('request', $referenced, true) => 'request',
                        $referenced !== [] && ! in_array('unknown', $referenced, true) => 'principal',
                        default => 'unknown',
                    };
                }

                /* ANY request-derived assignment makes the variable request-derived, and a single
                   unknown one is enough to withhold `principal`. A variable that is sometimes the
                   principal's store and sometimes the caller's is the caller's, for the purpose of
                   a question about what an attacker can reach. */
                $bindings[$variable] = match (true) {
                    in_array('request', $verdicts, true) => 'request',
                    in_array('unknown', $verdicts, true) => 'unknown',
                    default => 'principal',
                };
            }
        }

        return $bindings;
    }

    /**
     * EVERY NAME THIS FILE DECLARES AS A METHOD PARAMETER.
     *
     * A predicate whose value is a parameter is not a verdict this census can reach: the value
     * arrives from a CALLER, which `LIMIT 3` records it does not follow across files. Calling it
     * `principal` would be the census vouching for something it has not read, and calling it `none`
     * would be a false finding -- the first version of this census did exactly that and reported
     * all nine of `MerchantAnalyticsFigures`' reads as unscoped, although every one carries
     * `where('store_id', $storeId)` and that class's own docblock states the entry condition:
     * "`$storeId` reaches this method from `getCurrentStore()` only, and every query below still
     * carries it." So it gets its own verdict and a per-site disposition in the test's table.
     *
     * @param  array<int, string>  $lines
     * @return array<string, true>
     */
    private function parameters(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (! preg_match('/\b(?:function)\s+\w+\s*\(/', $line) && ! str_contains($line, 'function (')) {
                /* A signature can span lines; a continuation line of one looks like
                   `        int $storeId,` and is matched by the parameter pattern below. */
                if (! preg_match('/^\s*(?:\??[\w\\|]+\s+)?\$\w+(\s*=[^,]*)?,?\s*$/', $line)) {
                    continue;
                }
            }

            if (preg_match_all('/(?:\?|\b)(?:[\w\\|]+\s+)?(\$\w+)(?=\s*(?:,|\)|=))/', $line, $found)) {
                foreach ($found[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * The 0-based start line of every `function` declaration in the file, in order.
     *
     * @param  array<int, string>  $lines
     * @return list<int>
     */
    private function functionRanges(array $lines): array
    {
        $starts = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/(?:^|\s)function\s+&?\w+\s*\(/', $line)) {
                $starts[] = $index;
            }
        }

        return $starts;
    }

    /**
     * The function body a line sits in, as [from, to) over the 0-based line array. A line before
     * the first declaration -- a `use` statement, a property -- gets the whole file, which is
     * correct: it has no enclosing function.
     *
     * @param  list<int>  $starts
     * @return array{0: int, 1: int}
     */
    private function enclosing(array $starts, int $index): array
    {
        $from = 0;
        $to = PHP_INT_MAX;

        foreach ($starts as $position => $start) {
            if ($start <= $index) {
                $from = $start;
                $to = $starts[$position + 1] ?? PHP_INT_MAX;

                continue;
            }

            break;
        }

        return [$from, $to];
    }

    /** @param list<string> $needles */
    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole statement starting at $index: that line plus following lines up to the first `;`,
     * capped at 40 lines so a file without a semicolon cannot read to the end of itself.
     *
     * @param  array<int, string>  $lines
     */
    private function statementAt(array $lines, int $index): string
    {
        $statement = $lines[$index] ?? '';

        for ($i = $index + 1; $i < min($index + 40, count($lines)); $i++) {
            if (str_contains($statement, ';')) {
                break;
            }

            $statement .= "\n" . $lines[$i];
        }

        /*
         * THE BUILDER HELD IN A VARIABLE, AND ITS PREDICATE ADDED LATER. `ReportController` writes
         *
         *     $orders = Order::selectRaw('orders.*, MONTH(order_date) as month');
         *     $orders->whereDate(...)->where('store_id', OperatorStoreSelection::reportStoreId());
         *     $orders = $orders->get();
         *
         * twenty times. The predicate is a STATEMENT LATER, so a window that stops at the first `;`
         * reads the first line as carrying none -- a false finding, not a conservative one. The
         * window therefore also takes the following lines that call a method on the SAME variable,
         * up to the next assignment of it from something that is not itself (`= $orders->get()`
         * ends it) or forty lines, whichever comes first.
         *
         * THIS WIDENS WHAT COUNTS AS THE STATEMENT AND IS RECORDED AS SUCH. A predicate applied to
         * the builder further away than that, or in another method, is still outside and still
         * reads as `none`; `LIMIT 2` says so.
         */
        if (preg_match('/^\s*(\$\w+)\s*=\s*\w/', $lines[$index] ?? '', $assigned)) {
            $variable = $assigned[1];

            for ($i = $index + 1; $i < min($index + 40, count($lines)); $i++) {
                $line = $lines[$i];

                /*
                 * A METHOD CALL ON THE VARIABLE, NOT A MENTION OF IT. The first version took any
                 * line CONTAINING the name, and a MUTATION CAUGHT IT: deleting
                 * `->where('store_id', $storeId)` from `MerchantAnalyticsFigures::customers()`
                 * changed nothing this census reported, because the next-but-one line reads
                 * `$returning = $buyers === [] ? 0 : Order::query()` -- it MENTIONS `$buyers`, so
                 * its own `->where('store_id', $storeId)` continuation was appended to the first
                 * site's statement and credited to it. The mutation survived with a perfect score,
                 * which is the most dangerous result a harness can print. Fixed here rather than
                 * retired: only `$var->` and `$var = ` lines belong to the chain.
                 *
                 * THE FIRST REPAIR WAS ALSO WRONG, AND THE SAME MUTATION CAUGHT IT AGAIN. Matching
                 * `$var` followed by `->` OR `=` reads `$returning = $buyers === [] ? 0 : ...` as a
                 * chain line, because `===` begins with `=`. So the test is now: a method call on
                 * the variable ANYWHERE in the line, or an ASSIGNMENT to it at the start of the
                 * line -- `=` not followed by another `=`. Two wrong repairs of one harness is why
                 * the mutation is kept rather than the result trusted.
                 */
                $onTheVariable = preg_match('/' . preg_quote($variable, '/') . '\s*->/', $line) === 1
                    || preg_match('/^\s*' . preg_quote($variable, '/') . '\s*=[^=]/', $line) === 1;

                if (! $onTheVariable) {
                    /* AN UNRELATED LINE DOES NOT END THE CHAIN. It did in an earlier version, and
                       `ReportController` writes the builder, then a comment block (which the
                       stripper turns into blank lines), then two unrelated assignments, THEN
                       `$user->whereDate(...)->where('store_id', ...)`. Eleven sites read as
                       carrying no predicate when they carry a principal-derived one. */
                    continue;
                }

                /* A REASSIGNMENT FROM SOMETHING THAT IS NOT ITSELF ENDS IT, because that is a
                   different builder under the same name. `$user = $user->get();` references
                   itself and is the same chain; `$orders = Order::where(...)` is not. */
                if (preg_match('/^\s*' . preg_quote($variable, '/') . '\s*=\s*(.*)$/', $line, $reassigned)
                    && ! str_contains($reassigned[1], $variable)) {
                    break;
                }

                $statement .= "\n" . $line;

                /*
                 * AND ITS CONTINUATION LINES. `ReportController` writes the chained call across
                 * two lines:
                 *
                 *     $user->whereDate('order_date', '>=', $form_date)
                 *         ->whereDate(...)->where('store_id', OperatorStoreSelection::reportStoreId());
                 *
                 * The second line does NOT contain `$user`, so a scan that only takes lines naming
                 * the variable stops one line short of the predicate -- and the predicate is the
                 * whole question. Measured: six sites read as carrying none while carrying a
                 * principal-derived one two characters further on.
                 */
                for ($j = $i + 1; ! str_contains($line, ';') && $j < min($index + 40, count($lines)); $j++) {
                    $line = $lines[$j];
                    $statement .= "\n" . $line;
                    $i = $j;
                }
            }
        }

        return $statement;
    }

    /**
     * The file's CODE, through PHP's own tokenizer, with every comment replaced by exactly the
     * newlines it contained so line numbers survive.
     *
     * This is `Tests\Support\StripsSourceComments::phpCodeOnly()`'s algorithm. It is reimplemented
     * here rather than inherited because that trait is a TEST trait -- it calls
     * `assertFileExists()` and `assertNotSame()` -- and this class is a plain object a script can
     * run outside PHPUnit. The shared trait's own docblock gives the reason a pattern is not used:
     * measured on `app/Http/Middleware/XSS.php`, a lookbehind-guarded pattern ate seven code tokens,
     * and a stripper that eats code makes every absence assertion a free pass.
     */
    public function codeOnly(string $relative): string
    {
        $source = (string) file_get_contents($this->root . '/' . $relative);
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }
}

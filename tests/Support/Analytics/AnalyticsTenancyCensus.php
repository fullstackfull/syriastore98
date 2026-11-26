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
 * LIMIT 2. THE STATEMENT IS A TEXT WINDOW: the matched line plus following lines to the first `;`,
 * capped at 40. A predicate applied to the same builder in a LATER statement -- `$q = Order::query();
 * ... $q->where('store_id', ...)` -- is outside the window and reads as `none`. Those are
 * dispositioned by hand in the test's own table rather than guessed at.
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
        'Order', 'Visit', 'Store', 'User', 'Product', 'Category',
        'Wishlist', 'Testimonial', 'ProductImage', 'DeliveryAddress', 'ProductVariant', 'Coupon',
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
            $lines = explode("\n", $this->codeOnly($relative));
            $functions = $this->functionRanges($lines);
            $pattern = '/(?<![\w\\\\$>])(' . implode('|', self::UNSCOPED_MODELS) . ')::(\w+)\s*\(/';

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
        if ($this->hasAny($expression, self::REQUEST_TOKENS)) {
            return 'request';
        }

        if ($this->hasAny($expression, self::PRINCIPAL_TOKENS)) {
            return 'principal';
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
                    if ($this->hasAny($expression, self::REQUEST_TOKENS)) {
                        $verdicts[] = 'request';

                        continue;
                    }

                    if ($this->hasAny($expression, self::PRINCIPAL_TOKENS)) {
                        $verdicts[] = 'principal';

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

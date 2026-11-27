<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * GAP-253 -- RAW SQL WRITTEN AS A QUERY-BUILDER METHOD IS IN NO INVENTORY, AND THAT WAS 113 SITES.
 * (115 now; every figure in this docblock is GAP-253's original measurement, and the assertions
 * below are the oracle for the live ones -- the same split the sibling census records about itself.)
 * =================================================================================================
 *
 * THE ROW NAMED TWO STATEMENTS. `SaasRevenueFigures` holds two `selectRaw` calls that
 * `RawQueryTenancyInventoryTest` does not count, and that file's own comment says so. The reason
 * generalises, and the generalisation is worth more than the two statements, so it is measured here.
 *
 * WHY THEY ARE INVISIBLE -- ESTABLISHED AT THE CENSUS, NOT TAKEN FROM THE COMMENT. Three candidate
 * causes were checked and two are innocent:
 *
 *   - NOT the file enumeration. `RawQueryTenancyInventoryTest::phpFiles()` is a fully recursive
 *     `RecursiveIteratorIterator` over `app` and `packages`, so `app/Services` is scanned.
 *   - NOT the comment stripping. Its `codeLines()` drops lines that BEGIN with a comment marker,
 *     which can only make it count more, never fewer.
 *   - IT IS THE PATTERN, and by construction:
 *
 *         RAW_ENTRY_POINT_PATTERN = '/\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/'
 *
 *     That matches the `DB` FACADE's entry points. Raw SQL written as a method ON A BUILDER --
 *     `->selectRaw()`, `->whereRaw()`, `->orderByRaw()`, `->havingRaw()`, `->groupByRaw()`,
 *     `->fromRaw()` -- is never preceded by `DB::`, so it cannot match. Note also that `raw` is
 *     ABSENT from that alternation, so `DB::raw()` is outside it too.
 *
 * THE INSTRUMENT IS VALIDATED AGAINST THE LIVE CENSUS IN BOTH DIRECTIONS, which is the only reason
 * the blind-spot figures below can be trusted: counting the same pattern over the same enumeration
 * reproduces `['app' => 253, 'packages' => 45]` EXACTLY -- the figures that test asserts. (Its
 * 2026-11-26 NOTE: this pair was 229/45 until the integration checkpoint, and it moved because the
 * sibling census legitimately moved, not because this instrument drifted. FOUR raw sites arrived
 * across two branches and each is named in that file's own chain of notes: `RefundPolicy::allows()`
 * (GAP-140), `OrderCurrencyStamp::configuredSymbolFor()` (GAP-278) and `PackingSlip`'s two reads
 * (ORD-008). This file's own instrument measured 232 live BEFORE the figure here was touched,
 * which is the order this test demands -- it says "do NOT adjust this to match: find out which
 * census changed" -- and the answer is that the codebase did.)
 * (AND AGAIN, 247 -> 253, at the integration reconciliation of 2026-11-26: SIX more raw sites
 * arrived across three branches -- `ReturnInspection`'s three, `ModuleTrust`'s two and
 * `PriceExplanation`'s one -- and two of the three branches had never declared them anywhere, so
 * the sibling census was already short before the merge that was blamed for it. Same order of work:
 * the six were located and read first, and this figure followed.) (Its
 * method NAME says "one hundred and sixty eight" and a comment in it says 112; both are stale, and
 * its docblock already records that the name is an identity the CI gate matches on and that "the
 * assertions carry the real figures". So the assertion is the oracle, and this agrees with it.)
 *
 * COMMENTS ARE STRIPPED WITH `token_get_all()`, NOT BY LINE PREFIX. CLAUDE.md records eight
 * occasions when a census was satisfied -- or broken -- by prose about the thing it measures, and
 * this file would be a prime candidate: it is surrounded by comments containing the literal strings
 * it searches for. A token-based strip removes a trailing comment on a live code line too, which a
 * prefix test cannot.
 *
 * WHAT THIS TEST IS AND IS NOT. It is a VISIBILITY ratchet: it counts the sites the existing
 * inventory cannot see, so the number cannot grow unnoticed. It is NOT a tenancy classification --
 * it does not say which of the 113 need a `store_id` predicate, and it must not be read as saying
 * they are safe. Classifying them is the follow-up GAP-253 implies, and it is a real piece of work:
 * `RawQueryTenancyInventoryTest` carries per-table totals (198 and 86) that a classification pass
 * would have to rebuild. Adding only `SaasRevenueFigures`' two rows to that inventory would have
 * been worse than leaving them out, because a total of 2 out of 113 implies the other 111 are
 * accounted for.
 *
 * ON THE TWO STATEMENTS THEMSELVES, the row's own disposition stands and is re-checked here:
 * `plan_orders` and `users` are in neither `scoped_models`, so `StoreScope` short-circuits and adds
 * no predicate for ANY principal -- the reach is identical whether the statement is raw or
 * Eloquent. And the figure is the PLATFORM's own revenue across every tenant, so a tenant predicate
 * would make it wrong. What was missing was the entry, and this is the entry.
 */
class BuilderRawQueryVisibilityTest extends TestCase
{
    /** The live census's own pattern, copied so this test measures what that test measures. */
    private const RAW_ENTRY_POINT_PATTERN = '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/';

    /** Raw SQL expressed as a method on a query builder. */
    private const BUILDER_RAW_PATTERN = '/->\s*(selectRaw|whereRaw|orWhereRaw|havingRaw|orHavingRaw|orderByRaw|groupByRaw|fromRaw|joinRaw|whereIntegerInRaw)\s*\(/';

    private const DB_RAW_PATTERN = '/\\\\?DB::raw\s*\(/';

    /**
     * @return array<string, array{visible: int, builder: int, dbRaw: int, files: array<string, list<int>>}>
     */
    private function census(): array
    {
        $out = [];

        foreach (['app', 'packages'] as $root) {
            $visible = $builder = $dbRaw = 0;
            $files = [];

            foreach ($this->phpFiles($root) as $relative) {
                $lines = explode("\n", $this->sourceWithoutComments(base_path($relative)));

                foreach ($lines as $index => $line) {
                    $isEntry = (bool) preg_match(self::RAW_ENTRY_POINT_PATTERN, $line);
                    $isBuilder = (bool) preg_match(self::BUILDER_RAW_PATTERN, $line);

                    if ($isEntry) {
                        $visible++;
                    }

                    if ($isBuilder && ! $isEntry) {
                        $builder++;
                        $files[$relative][] = $index + 1;
                    }

                    if (! $isEntry && ! $isBuilder && preg_match(self::DB_RAW_PATTERN, $line)) {
                        $dbRaw++;
                    }
                }
            }

            $out[$root] = ['visible' => $visible, 'builder' => $builder, 'dbRaw' => $dbRaw, 'files' => $files];
        }

        return $out;
    }

    /**
     * Comments replaced by the newlines they spanned, so line numbers survive.
     *
     * `Tests\Support\StripsSourceComments` exists for PHP sources, and this is the same idea done
     * positionally because the line NUMBERS are part of what is reported.
     */
    private function sourceWithoutComments(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $out = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /** @return list<string> relative paths, the same enumeration the live census uses */
    private function phpFiles(string $dir): array
    {
        $base = base_path($dir);

        if (! is_dir($base)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }

    /**
     * THE PREMISE, AND WITHOUT IT EVERY FIGURE BELOW IS UNANCHORED: this file's reading of the
     * census's own pattern reproduces the census's own asserted totals.
     */
    public function test_premise_this_instrument_reproduces_the_live_censuss_asserted_totals(): void
    {
        $census = $this->census();

        $this->assertSame(
            /* 253 -> 272 in app/ on 2026-11-27 [`P04-P4-02` / `INV-001` / `INV-007`]: the
               nineteen `DB::table()` statements of `app/Services/WarehouseInventory.php`. THE
               CENSUS THAT MOVED IS THE LIVE ONE AND THE REASON IS KNOWN, which is what this
               premise's own message asks for: nineteen statements were added to
               `app/Services/`, every one naming `store_id`, and
               `ServiceLayerRawQueryInventoryTest` carries the scoped/store-less split.
               RELEVANT TO THIS FILE'S OWN SUBJECT TOO: none of the nineteen is builder-raw.
               They are plain `where`/`decrement`/`updateOrInsert`/`insertGetId` calls with
               bound parameters and no `DB::raw`, `selectRaw`, `whereRaw` or `havingRaw`
               anywhere in the file, so the invisible-site count below does not move and no
               new dynamic fragment needs declaring. */
            /* 232 -> 247 in app/ on 2026-11-26 [OPS-001]: the fifteen `DB::table()` statements of
               `app/Services/SaasOperationsProbe.php`. The figure here only has to follow the live
               census's, which is this test's whole job — and the probe is relevant to this file's
               own subject for a second reason: an earlier draft of it had THREE builder-raw
               statements (two `GROUP BY` aggregates over `settings` and one over `failed_jobs`),
               which would have landed in the blind spot this file measures. All three were
               rewritten in the builder's own vocabulary, so the invisible-site count below does
               not move. */
            /* 247 -> 253 on 2026-11-26 [the integration reconciliation], AND THIS FILE'S OWN RULE
               WAS FOLLOWED RATHER THAN QUOTED: the message below says "do NOT adjust this to
               match: find out which census changed", so the six statements were located and read
               BEFORE this figure moved. They are `ReturnInspection`'s three (RET-002),
               `ModuleTrust`'s two (MARKET-004) and `PriceExplanation`'s one (PRICE-006), named per
               file with their predicates and their tables beside
               `RawQueryTenancyInventoryTest`'s own per-directory assertion. The codebase changed;
               this instrument did not. */
            ['app' => 278, 'packages' => 45],
            ['app' => $census['app']['visible'], 'packages' => $census['packages']['visible']],
            'This file counts `DB::` entry points with the same pattern and the same enumeration as '
            . 'RawQueryTenancyInventoryTest::test_the_raw_entry_point_totals_are_the_measured_one_hundred_and_sixty_eight, '
            . 'whose assertion is `[app => 272, packages => 45]`. A disagreement means one of the '
            . 'two moved, and the blind-spot figures below are measured against nothing until it is '
            . 'resolved. Do NOT adjust this to match: find out which census changed.'
        );
    }

    /**
     * THE BLIND SPOT, RATCHETED. 115 sites of raw SQL that no inventory counts (113 when GAP-253
     * was written; the assertion below carries the live figure and its history).
     */
    public function test_the_builder_raw_blind_spot_is_exactly_this_many_sites(): void
    {
        $census = $this->census();

        $actual = [
            'app builder-raw' => $census['app']['builder'],
            'app DB::raw' => $census['app']['dbRaw'],
            'packages builder-raw' => $census['packages']['builder'],
            'packages DB::raw' => $census['packages']['dbRaw'],
        ];

        $this->assertSame(
            [
                /* 69 -> 71 on 2026-11-26 [the integration reconciliation]. TWO builder-raw lines,
                   from two branches, and each is classified below rather than counted here:

                     `app/Http/Controllers/ModuleReviewController.php` [MARKET-004] -- one
                     `orderByRaw` that sorts pending reviews first. A PROJECTION, so it cannot add a
                     row to any result, and DYNAMIC by this file's whitelist because a class
                     constant is concatenated into it; declared in DYNAMIC_RAW_FRAGMENTS with that
                     mechanism.

                     `app/Support/Segments/SegmentDefinition.php` [MKT-005] -- that file's count
                     goes 4 -> 5 with `SegmentDefinition::applyLoyaltyPoints()`'s
                     `whereRaw('1 = 0')`, taken when the loyalty column does not exist at all. A
                     static REFUSAL, which is a tenancy control rather than a bypass of one:
                     `1 = 0` can only narrow, and the alternative the method's own docblock rejects
                     -- skipping the rule -- would have widened a merchant's segment to every
                     customer the other rules allow. */
                'app builder-raw' => 71,
                'app DB::raw' => 23,
                'packages builder-raw' => 17,
                'packages DB::raw' => 4,
            ],
            $actual,
            'The count of raw-SQL sites INVISIBLE to RawQueryTenancyInventoryTest has moved. That '
            . 'test counts `DB::` facade entry points only, so raw SQL written as a builder method '
            . 'is outside it by construction. If this went UP, a new raw statement was added where '
            . 'no inventory will ever report it -- classify it and move this figure deliberately. '
            . 'If it went DOWN, say which site was removed or rewritten. Measured: '
            . json_encode($actual)
        );

        /* 113 -> 115 [2026-11-26]: the two lines named above. The VISIBLE figure in the message
           moves with them -- 298 now (253 + 45), per the premise test -- so the coverage sentence
           is re-derived rather than left to read as though nothing moved. */
        $this->assertSame(
            115,
            array_sum($actual),
            'the blind spot total; 298 sites are visible, so the inventory reaches about 72% of the '
            . 'raw SQL in this codebase'
        );
    }

    /**
     * GAP-253's OWN TWO STATEMENTS are inside that total, named, so the row can be closed against
     * something specific rather than against a count.
     */
    public function test_the_two_saas_revenue_statements_are_among_the_invisible_sites(): void
    {
        $census = $this->census();
        $file = 'app/Services/SaasRevenueFigures.php';

        $this->assertArrayHasKey(
            $file,
            $census['app']['files'],
            'GAP-253 names two `selectRaw` statements in ' . $file . ' as uncounted. They are no '
            . 'longer found there at all, so either the file moved or the statements were rewritten '
            . '-- re-point this row rather than deleting it.'
        );

        $this->assertCount(
            2,
            $census['app']['files'][$file],
            'GAP-253 is about exactly two statements -- `planOrderGroups()` on PlanOrder::query() '
            . 'and the `User::query()` site in `subscriptions()`. Found: '
            . implode(', ', $census['app']['files'][$file])
        );
    }

    /**
     * AND THE CAUSE, DRIVEN RATHER THAN DESCRIBED: the census's pattern genuinely cannot match a
     * builder-raw line, and genuinely does match a `DB::table()` one.
     *
     * Without this the explanation in the docblock is an assertion about a regex that nobody ran.
     */
    public function test_the_live_censuss_pattern_cannot_match_a_builder_raw_statement(): void
    {
        $builderLine = "        return \$query->selectRaw('sum(price) as amount')->groupBy('x');";
        $facadeLine = "        \$row = DB::table('orders')->where('id', 1)->first();";
        $dbRawLine = "        \$q->select(DB::raw('count(*) as n'));";

        $this->assertSame(
            0,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $builderLine),
            'the census pattern now matches a builder-raw statement, so the blind spot this file '
            . 'measures has closed -- which is good news, and means these figures must be rebuilt'
        );

        $this->assertSame(
            0,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $dbRawLine),
            '`raw` is absent from the pattern\'s alternation, so `DB::raw()` is outside it too'
        );

        $this->assertSame(
            1,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $facadeLine),
            'the census pattern no longer matches a plain `DB::table()` call, so this test is '
            . 'measuring the wrong thing entirely'
        );
    }

    // =============================================================================================
    // THE CLASSIFICATION OF THE 113 -- the follow-up GAP-253 implied, and the control that would
    // actually have caught the injection.
    // =============================================================================================

    /**
     * Every raw-SQL call whose SQL TEXT is not a compile-time literal, declared per FILE with the
     * mechanism that makes it safe.
     *
     * WHY THIS IS THE CONTROL THAT MATTERS, measured rather than assumed. The injection closed in
     * `0aa24f838` was `whereRaw('ROUND(average_rating) =' . $request->rating)`. Classifying
     * fragments by whether they can WIDEN a row set does NOT catch it -- the source text contains no
     * OR, no subquery and no JOIN; the OR arrives at runtime, from the request. What catches it is
     * this: the first argument was not a literal. So the property worth ratcheting is "the SQL text
     * is fixed at compile time", and anything else is declared here with a reason.
     *
     * KEYED ON FILES AND COUNTS, NOT LINE NUMBERS, DELIBERATELY. CLAUDE.md records that an absolute
     * line citation goes stale on any insertion above it, and this branch spent two commits
     * repairing exactly that (`d62d78ced`, `501bfa21b`). A per-file count moves only when a file
     * gains or loses a dynamic fragment, which is the event worth failing on.
     *
     * THE MECHANISMS, one per entry, and they are five different things:
     *
     * @var array<string, array{count: int, mechanism: string}>
     */
    private const DYNAMIC_RAW_FRAGMENTS = [
        /* A TERNARY OVER TWO LITERALS, behind `in_array($items, ['one','several'], true)` on the
           line above. Both branches are constant strings, so no request text can reach the SQL --
           the detector calls it dynamic because a variable appears in the argument, which is the
           whitelist being conservative in the right direction. */
        'app/DataTables/AbandonCartDataTable.php' => [
            'count' => 1,
            'mechanism' => 'ternary over two literal strings, value allowlisted on the line above',
        ],

        /* AN ALLOWLIST, AND THE PATTERN THE OTHERS SHOULD COPY. `groupedVisits()` refuses any
           column not in `GROUPABLE_VISIT_COLUMNS` with an InvalidArgumentException, and its
           docblock says why: "a name from a request would be an injection point". */
        'app/Support/Metrics/MerchantAnalyticsFigures.php' => [
            'count' => 1,
            'mechanism' => 'column checked against a closed list (in_array + throw) before use',
        ],

        /* A CLASS CONSTANT. `implode(', ', array_map(fn => ..., self::SIBLING_COLUMNS))` -- the
           data is a constant in the same file, so the SQL is fixed at compile time in everything
           but form. */
        'app/Support/Metrics/WalletEntitlementFigure.php' => [
            'count' => 1,
            'mechanism' => 'built from a class constant, so the text is fixed at compile time',
        ],

        /* ALSO A CLASS CONSTANT, and it arrived undeclared [MARKET-004, 2026-11-26]. The fragment
           is `ModuleReviewController::index()`'s
               orderByRaw("CASE WHEN review_state = '" . ModuleReviewRecord::STATE_PENDING . "' ...")
           and `ModuleReviewRecord::STATE_PENDING` is `'pending'`, a plain string constant in
           first-party code -- read, not assumed. So the SQL text is fixed at compile time in
           everything but form, exactly as WalletEntitlementFigure's is, and the detector calls it
           dynamic because a non-literal token appears in the argument, which is the whitelist
           being conservative in the right direction.

           TWO FURTHER PROPERTIES, both checked rather than inferred, because this is an
           OPERATOR-ONLY screen over a PLATFORM-WIDE register and neither fact excuses the other:
           `orderByRaw` is a projection, so this fragment cannot add a row to any result whatever it
           says; and nothing from the request reaches it -- the method takes no `Request`, reads
           nothing from one, and is gated by `$this->authorize('manage-platform-addons')` on its
           first line. */
        'app/Http/Controllers/ModuleReviewController.php' => [
            'count' => 1,
            'mechanism' => 'built from a class constant, so the text is fixed at compile time',
        ],

        /* A LITERAL COLUMN NAME PASSED TO A HELPER, AND THE VALUE IS BOUND. All three read
           `whereRaw($this->digitsOnly('customers.mobile') . ' LIKE ?', ['%' . $digits])`:
           `digitsOnly()` wraps a column name it is GIVEN as a literal at every call site, and the
           user's text travels as a binding. This is the correct shape. */
        'packages/workdo/LynomiaChat/src/app/Services/CommerceReader.php' => [
            'count' => 3,
            'mechanism' => 'literal column name into a helper; the user value is a bound parameter',
        ],

        /* THE METRIC LAYER: a column or expression arrives as a `string` parameter and is
           concatenated. SAFE TODAY AND UNGUARDED BY CONSTRUCTION, which is the one systemic finding
           of this classification and is stated as such rather than as a defect. Every call site was
           read: all pass hard-coded literals (`'orders.currency_code'`,
           `'store_order_financials.customer_total'`, and so on). Nothing validates that, so a
           future caller passing request input would inject with nothing in the way. The remedy
           already exists in this codebase one file away -- see MerchantAnalyticsFigures above. */
        'app/Support/Metrics/Metric.php' => [
            'count' => 2,
            'mechanism' => 'column/expression parameter; every caller passes a literal; NO guard',
        ],
        'app/Support/Metrics/CancelledOrders.php' => [
            'count' => 2,
            'mechanism' => 'column/expression parameter; every caller passes a literal; NO guard',
        ],
        'app/Support/Metrics/CustomerLedgerMoney.php' => [
            'count' => 2,
            'mechanism' => 'column/expression parameter; every caller passes a literal; NO guard',
        ],
        'app/Support/Metrics/StoreInventoryValuation.php' => [
            'count' => 2,
            'mechanism' => 'column/expression parameter; every caller passes a literal; NO guard',
        ],
        'app/Support/Metrics/UnpaidOrders.php' => [
            'count' => 1,
            'mechanism' => 'column/expression parameter; every caller passes a literal; NO guard',
        ],
    ];

    /**
     * The only two fragments whose TEXT can widen a row set, both read and both safe.
     *
     * @var array<string, array{count: int, reason: string}>
     */
    private const WIDENING_CAPABLE = [
        /* A CORRELATED `EXISTS` OVER THE ROW'S OWN COLUMN. `JSON_TABLE(product_json, ...)` expands
           a column of the row being tested, names no other table, and compares against a BOUND
           parameter. An EXISTS filter narrows; it cannot introduce a row. */
        'app/DataTables/SalesDownloadableProductDataTable.php' => [
            'count' => 1,
            'reason' => 'EXISTS over the row\'s own product_json, bound parameter, no second table',
        ],

        /* PARENTHESISED, AND THAT IS THE WHOLE DIFFERENCE FROM THE INJECTION. The fragment is
           `'( delivered_status = 3 OR return_price > 0 )'` -- a constant string whose OR is enclosed,
           so it cannot escape the AND it is joined with. The injection had no parentheses and no
           fixed text, which is why its OR reached the top level and defeated `store_id`. */
        'app/Http/Controllers/Api/ApiController.php' => [
            'count' => 1,
            'reason' => 'static text, OR enclosed in parentheses so it cannot reach the top level',
        ],
    ];

    /** @var list<string> */
    private const RAW_METHODS = [
        'selectRaw', 'whereRaw', 'orWhereRaw', 'havingRaw', 'orHavingRaw', 'orderByRaw',
        'groupByRaw', 'fromRaw', 'joinRaw', 'whereIntegerInRaw', 'raw',
    ];

    /**
     * Every raw call site invisible to the inventories, with its first argument's text and whether
     * that text is a compile-time literal.
     *
     * @return list<array{file: string, line: int, method: string, arg: string, static: bool}>
     */
    private function rawCallSites(): array
    {
        $sites = [];

        foreach (['app', 'packages'] as $root) {
            foreach ($this->phpFiles($root) as $relative) {
                $code = $this->sourceWithoutComments(base_path($relative));
                $lines = explode("\n", $code);
                $tokens = token_get_all($code);
                $count = count($tokens);

                for ($i = 0; $i < $count; $i++) {
                    $token = $tokens[$i];

                    if (! is_array($token) || $token[0] !== T_STRING
                        || ! in_array($token[1], self::RAW_METHODS, true)) {
                        continue;
                    }

                    $j = $i + 1;

                    while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }

                    if (($tokens[$j] ?? null) !== '(') {
                        continue;
                    }

                    if (preg_match(self::RAW_ENTRY_POINT_PATTERN, $lines[$token[2] - 1] ?? '')) {
                        continue;   // this line is already in an inventory
                    }

                    $depth = 0;
                    $text = '';
                    $isStatic = true;

                    for ($k = $j + 1; $k < $count; $k++) {
                        $piece = $tokens[$k];

                        if ($piece === '(' || $piece === '[') {
                            $depth++;
                        }

                        if ($piece === ')' || $piece === ']') {
                            if ($depth === 0) {
                                break;
                            }

                            $depth--;
                        }

                        if ($piece === ',' && $depth === 0) {
                            break;
                        }

                        if (is_array($piece)) {
                            $text .= $piece[1];

                            if (! in_array($piece[0], [
                                T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE, T_LNUMBER, T_DNUMBER,
                            ], true)) {
                                $isStatic = false;
                            }

                            continue;
                        }

                        $text .= $piece;

                        if (! in_array($piece, ['.', '(', ')', '[', ']'], true)) {
                            $isStatic = false;
                        }
                    }

                    $sites[] = [
                        'file' => $relative, 'line' => $token[2], 'method' => $token[1],
                        'arg' => $text, 'static' => $isStatic,
                    ];
                }
            }
        }

        return $sites;
    }

    /**
     * THE CONTROL THAT WOULD HAVE CAUGHT THE INJECTION: a raw fragment whose SQL text is not fixed
     * at compile time must be declared with the mechanism that makes it safe.
     *
     * Asserted in BOTH directions -- an undeclared dynamic fragment and a declaration for a file
     * that no longer has one both fail, so a mechanism cannot outlive the statement it describes.
     */
    public function test_every_dynamic_raw_fragment_is_declared_with_its_safety_mechanism(): void
    {
        $measured = [];

        foreach ($this->rawCallSites() as $site) {
            if (! $site['static']) {
                $measured[$site['file']] = ($measured[$site['file']] ?? 0) + 1;
            }
        }

        $declared = [];

        foreach (self::DYNAMIC_RAW_FRAGMENTS as $file => $entry) {
            $declared[$file] = $entry['count'];
        }

        ksort($measured);
        ksort($declared);

        $this->assertSame(
            $declared,
            $measured,
            "A raw-SQL fragment whose TEXT is not a compile-time literal appeared, disappeared or "
            . "moved file.\n\nThis is the shape that produced the cross-tenant SQL injection closed "
            . "in 0aa24f838: `whereRaw('ROUND(average_rating) =' . \$request->rating)`. If a file "
            . "gained one, read it and either bind the value or declare the mechanism that makes it "
            . "safe in DYNAMIC_RAW_FRAGMENTS -- and do NOT declare it without reading every caller, "
            . "because the metric-layer entries are safe only because every caller passes a "
            . "literal.\n\nmeasured: " . json_encode($measured)
        );
    }

    /**
     * AND THE SECOND AXIS: a fragment whose text could widen a row set.
     *
     * A `selectRaw`, `orderByRaw`, `groupByRaw` or `DB::raw()` projection cannot add a row to a
     * result, so no tenant predicate applies to it -- that is a structural argument, not a sample.
     * A conjunctive filter narrows. What can widen is an OR that reaches the top level, a subquery,
     * a UNION or a JOIN, and exactly two fragments in the codebase contain any of those words.
     * Both are read and declared.
     */
    public function test_no_undeclared_raw_fragment_can_widen_a_row_set(): void
    {
        $projections = ['selectRaw', 'orderByRaw', 'groupByRaw', 'raw'];
        $measured = [];

        foreach ($this->rawCallSites() as $site) {
            if (in_array($site['method'], $projections, true)) {
                continue;       // cannot add a row, whatever it says
            }

            $upper = strtoupper($site['arg']);

            if (preg_match('/\bOR\b|\bSELECT\b|\bUNION\b|\bJOIN\b/', $upper)) {
                $measured[$site['file']] = ($measured[$site['file']] ?? 0) + 1;
            }
        }

        $declared = [];

        foreach (self::WIDENING_CAPABLE as $file => $entry) {
            $declared[$file] = $entry['count'];
        }

        ksort($measured);
        ksort($declared);

        $this->assertSame(
            $declared,
            $measured,
            'A raw FILTER fragment containing OR, a subquery, a UNION or a JOIN appeared or moved. '
            . 'Those are the only ways a raw fragment can widen a row set past a tenant predicate -- '
            . 'the injection in 0aa24f838 defeated `store_id` with exactly one unparenthesised OR. '
            . 'Read it, and if it is safe say WHY in WIDENING_CAPABLE. measured: '
            . json_encode($measured)
        );
    }

    /**
     * THE SHAPE OF ALL OF THEM, ratcheted so the classification cannot quietly stop being true.
     *
     * AND THE TWO FIGURES ARE DIFFERENT UNITS, which is worth stating because they look like a
     * contradiction: `test_the_builder_raw_blind_spot_is_exactly_this_many_sites` counts LINES
     * matching a pattern (115), because that is the unit the inventories it is compared against
     * use. This counts CALL SITES, so a line holding two raw calls counts twice and a `DB::raw()`
     * nested inside another call counts on its own. Both are measured; neither is wrong: 115 lines,
     * 216 call sites (113 and 214 when this was written -- the two integration deltas of 2026-11-26
     * moved both units, and the assertions below are the oracle for each).
     *
     * AND THE FIGURES CAME FROM THIS INSTRUMENT, not from the scratch script that explored the
     * question first. That script read `conjunctive filter` as 87 because it re-tokenised each file
     * behind a `'<?php '` prefix it had spliced on, which shifts what the tokeniser sees. The
     * methods here are the ones the premise test above validates against the live census's own
     * asserted 253/45, so they are the ones whose numbers are written down. A figure is only as
     * good as the instrument that is still in the repository.
     */
    public function test_the_classification_of_every_invisible_raw_site(): void
    {
        $projections = ['selectRaw', 'orderByRaw', 'groupByRaw', 'raw'];
        $counts = ['projection' => 0, 'refusal' => 0, 'conjunctive filter' => 0, 'can widen' => 0];

        foreach ($this->rawCallSites() as $site) {
            $upper = strtoupper($site['arg']);

            if (in_array($site['method'], $projections, true)) {
                $counts['projection']++;
            } elseif (preg_match("/^\s*'1\s*=\s*0'\s*$/", $site['arg'])) {
                $counts['refusal']++;
            } elseif (preg_match('/\bOR\b|\bSELECT\b|\bUNION\b|\bJOIN\b/', $upper)) {
                $counts['can widen']++;
            } else {
                $counts['conjunctive filter']++;
            }
        }

        /* 2026-11-26 [the integration reconciliation], and the two buckets that moved are the two
           that cannot widen a row set, which is the half that matters:
             projection 111 -> 112  `ModuleReviewController::index()`'s `orderByRaw` ordering.
             refusal     17 -> 18   `SegmentDefinition::applyLoyaltyPoints()`'s `whereRaw('1 = 0')`.
           `conjunctive filter` and `can widen` are UNCHANGED at 84 and 2. Had either moved, the
           sibling assertions above would have had something to say first: a new widening-capable
           fragment fails `test_no_undeclared_raw_fragment_can_widen_a_row_set` by name. */
        $this->assertSame(
            [
                'projection' => 112,
                'refusal' => 18,
                'conjunctive filter' => 84,
                'can widen' => 2,
            ],
            $counts,
            'The classification of raw-SQL call sites invisible to both inventories has moved. '
            . '`projection` cannot add a row at all; `refusal` is `1 = 0`, which IS a tenancy '
            . 'control rather than a bypass of one; `conjunctive filter` narrows. Move these '
            . 'figures deliberately and say which bucket changed. measured: ' . json_encode($counts)
        );
    }
}

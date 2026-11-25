<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;

/**
 * P5-3f -- THE "TOP 5" CHARTS MUST ACTUALLY RANK AND ACTUALLY STOP AT FIVE.
 *
 * Owner recommendation 4 (2026-11-17): "Either implement a genuinely ranked Top 5 with a
 * documented metric and tests, or relabel the chart honestly. Do not display an unordered full
 * list as Top 5." This is the first branch, and it required no new product decision -- the view
 * ALREADY documented the metric and the controller did not honour it.
 *
 * WHY THIS TEST READS SOURCE RATHER THAN DRIVING THE HANDLER. `top_product()` cannot run under
 * this suite: its category and brand queries use `SUBSTRING_INDEX` and the rest of
 * `ReportController` uses `MONTH()` and `YEAR()`, none of which exist in SQLite, so a Feature test
 * answers HTTP 500. The BEHAVIOUR is proved on MariaDB by
 * `tests/Support/Security/bin/verify-top-five-reports-on-mysql.php` (14 checks), which seeds seven
 * items so a correct top five must exclude two, lists one product eight times in a single order,
 * and spells one payment method four ways.
 *
 * This test is the cheap half: it runs in CI on every commit and fails if the ranking, the limit,
 * the case-insensitive grouping or the de-duplication is removed. It cannot prove the figures are
 * right -- only that the four mechanisms are still there.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * CORRECTED 2026-11-25 (ANA-002 / GAP-221), AND THE PARAGRAPH ABOVE IS KEPT BECAUSE IT EXPLAINS
 * THE SHAPE OF THIS FILE RATHER THAN BECAUSE IT IS STILL TRUE.
 *
 * `top_product()` DOES run under this suite now: its two rankings go through
 * `App\Support\Reports\TopSellersByOrderCount`, two ordinary queries and PHP, so the route
 * answers 200 and the FIGURES are asserted behaviourally --
 * `Tests\Feature\Analytics\TopSalesReportSuiteReachabilityTest` (12 tests: the metric, the
 * ranking, the tie-break, the hundred-position ceiling and three store predicates) and
 * `TopSalesReportPeriodTest` (6).
 *
 * So the first test below no longer reads the handler for an `orderByDesc(...)->limit(5)` that has
 * moved -- a string census of a mechanism's CURRENT SPELLING is the thing `GAP-229` keeps
 * catching. It asserts the two durable properties instead: the unexecutable join is gone, and both
 * charts come from the one shared object rather than from two hand-written copies. The MariaDB
 * script remains the only evidence that this engine and MySQL's case-insensitive collation agree,
 * and it reports **19** checks, not the 14 this docblock claimed (measured 2026-11-25, 0 failed,
 * against the rewritten charts).
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 */
class TopFiveRankingTest extends TestCase
{
    /*
     * A CENSUS THAT READS COMMENTS CAN BE SATISFIED -- OR BROKEN -- BY PROSE ABOUT THE THING IT
     * MEASURES, and this file met that trap the moment the handler gained a comment explaining the
     * join it no longer uses: the `SUBSTRING_INDEX` assertion below failed against code that
     * contains no such call. CLAUDE.md names four instances on 2026-11-21 alone.
     */
    use \Tests\Support\StripsSourceComments;

    private const CONTROLLER = 'app/Http/Controllers/ReportController.php';

    private const VIEW = 'resources/views/reports/top_5_reports.blade.php';

    private function source(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, $relative . ' is gone; this test has lost its subject.');

        return (string) file_get_contents($path);
    }

    public function test_the_category_and_brand_charts_come_from_the_one_shared_ranking_object(): void
    {
        $source = $this->sourceWithoutComments(self::CONTROLLER);

        /*
         * THE RANKING, THE LIMIT AND THE TIE-BREAK ARE ASSERTED BEHAVIOURALLY NOW, by
         * `Tests\Feature\Analytics\TopSalesReportSuiteReachabilityTest`, which drives the real
         * route and reads the real figures. What this test holds is the two properties that
         * assertion CANNOT see -- both about how the figure is obtained rather than what it is.
         */
        $this->assertStringNotContainsString(
            'SUBSTRING_INDEX',
            $source,
            'The `SUBSTRING_INDEX` + generated-numbers cross join is back in ReportController. '
            . 'SQLite has no such function, so every Feature test of a handler using it answers '
            . 'HTTP 500 -- which is how this screen came to have no suite coverage of its '
            . 'ranking, its limit or its three store predicates at all (GAP-221). '
            . 'App\\Support\\Reports\\TopSellersByOrderCount computes the same metric in two '
            . 'ordinary queries.'
        );

        foreach (['CATEGORY', 'BRAND'] as $dimension) {
            $this->assertStringContainsString(
                "TopSellersByOrderCount::compute(\n            TopSellersByOrderCount::" . $dimension,
                $source,
                'The ' . $dimension . ' chart no longer comes from the shared ranking object. Two '
                . 'hand-written copies of one metric is how the category and brand charts drifted '
                . 'apart before: P5-3f had to repair the identical defect twice, in two places, '
                . 'for the same reason.'
            );
        }
    }

    public function test_payment_methods_are_grouped_case_insensitively(): void
    {
        /*
         * `orders.payment_type` is free text and the same method is stored under several
         * spellings -- cash on delivery alone has three in
         * PaymentVerificationService::COD_PAYMENT_TYPES, plus casing and whitespace variants. A
         * case-sensitive grouping makes 'cod' and 'COD' two bars competing for the same five
         * slots, which makes a top five WRONG rather than merely unordered.
         */
        $source = $this->source(self::CONTROLLER);

        $this->assertStringContainsString(
            "->groupBy(fn (\$order) => mb_strtolower(trim((string) \$order->payment_type)))",
            $source,
            'Payment methods are no longer grouped on a lower-cased, trimmed key, so one method '
            . 'can occupy several of the five slots.'
        );

        $this->assertStringNotContainsString(
            "->groupBy('payment_type')",
            $source,
            'The raw case-sensitive grouping is back.'
        );
    }

    public function test_a_product_listed_twice_in_one_order_is_counted_once(): void
    {
        /*
         * `orders.product_id` is a comma-joined list and TWO of its three writers build it with
         * no de-duplication: OrderController::store() and Api\DashboardController both
         * `implode(',', array_column($request->products, 'product_id'))`, while only
         * PaymentService applies array_unique. Counting occurrences therefore inflated
         * merchant-admin and Flutter orders relative to storefront ones, under a label that says
         * "Counts orders, not quantity or value".
         */
        $source = $this->source(self::CONTROLLER);

        $this->assertMatchesRegularExpression(
            '/->filter\(fn \(\$id\) => \$id > 0\)\s*\n\s*->unique\(\)/',
            $source,
            'The product-id list is no longer de-duplicated per order, so one order containing '
            . 'the same product twice counts twice towards a figure labelled "orders".'
        );
    }

    public function test_the_view_still_documents_the_metric_the_controller_implements(): void
    {
        /*
         * The reason this slice needed no owner decision: the card notes already said what the
         * charts measure. If a future change moves the controller's metric, these notes become
         * the lie instead -- so they are pinned together.
         */
        $view = $this->source(self::VIEW);

        foreach ([
            'the five are the top five',
            'appearing in the most orders',
            'number of distinct orders containing one of their products',
            'Spellings of the same method are counted together',
        ] as $claim) {
            $this->assertStringContainsString(
                $claim,
                $view,
                'The card notes no longer state "' . $claim . '", so the view and the controller '
                . 'have stopped describing the same metric.'
            );
        }
    }

    public function test_the_mysql_evidence_script_exists_and_is_registered(): void
    {
        // A behavioural claim with no runnable evidence is the thing this project keeps finding.
        $this->assertFileExists(
            base_path('tests/Support/Security/bin/verify-top-five-reports-on-mysql.php'),
            'The only executable evidence for these charts is gone.'
        );

        $this->assertStringContainsString(
            'verify-top-five-reports-on-mysql',
            $this->source('tests/Support/Security/bin/run-mysql-verifications.sh'),
            'The Top-5 script is not in the runner, so nobody runs it.'
        );
    }
}

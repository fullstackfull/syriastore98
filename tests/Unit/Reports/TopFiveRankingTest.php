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
 */
class TopFiveRankingTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/ReportController.php';

    private const VIEW = 'resources/views/reports/top_5_reports.blade.php';

    private function source(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, $relative . ' is gone; this test has lost its subject.');

        return (string) file_get_contents($path);
    }

    public function test_the_category_and_brand_charts_rank_and_limit_to_five(): void
    {
        $source = $this->source(self::CONTROLLER);

        foreach ([
            'categories.name' => 'Top Selling Category',
            'product_brands.name' => 'Top Selling Brand',
        ] as $tieBreak => $chart) {
            $this->assertStringContainsString(
                "->orderByDesc('total_sale')\n            ->orderBy('" . $tieBreak . "')\n            ->limit(5)",
                $source,
                sprintf(
                    'The %s query no longer ranks by total_sale, tie-breaks on %s and limits to '
                    . 'five. Without all three a store with forty of them gets forty bars in '
                    . 'engine order, under a page headed "Top Sales Report" -- and without the '
                    . 'tie-break two on the same count swap places between requests, so '
                    . 'whichever lands fifth appears and disappears.',
                    $chart,
                    $tieBreak
                )
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

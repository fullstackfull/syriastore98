<?php

namespace Tests\Unit\Metrics;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-156 / P05-08 — EVERY PER-CUSTOMER MONEY FIGURE IS DECLARED, AND EVERY SURFACE THAT PRINTS
 * ONE RESOLVES THE LEDGER FIGURES BESIDE IT.
 * =================================================================================================
 *
 * WHY A CENSUS AND NOT JUST THE BEHAVIOURAL TESTS. GAP-156's own failure mode is a MISSED SURFACE,
 * twice over. The defect was that four screens read `SUM(orders.final_price)` per customer with no
 * payment predicate; the first repair reached three of them and left the fourth — the second copy
 * of the customer list at `customer/filter.blade.php` — printing a bare, unlabelled money cell. A
 * behavioural test can only drive the surfaces whoever wrote it knew about, which is exactly how
 * the fourth survived. This file is the question "is there a fifth".
 *
 * THE PREDICATE CANNOT LIVE ON `orders`, SO "COVERED" DOES NOT MEAN "FILTERED". Owner Option A,
 * quoted in `Order::order_status_change()`, keeps `orders.payment_status` as the customer's
 * settlement record and forbids a cancellation from rewriting it. No predicate on `orders` can
 * therefore exclude a cancelled order. `PHASE-05-METRIC-DEFINITIONS.md` §15 is the answer the
 * product took: the order-value figure keeps its value and its honest label, and the two LEDGER
 * figures — collected, and net retained — stand beside it. So a surface is covered when it names
 * the resolver, not when it adds a `where`.
 *
 * KEYED ON THE CALL, NOT ON A WORD. CLAUDE.md §7 records four completeness claims that missed what
 * they covered because each was keyed on spelling — uri words, `Route::post` lines, a uri prefix,
 * and an exemption scoped to the methods somebody happened to look at. The two per-customer money
 * figures in this codebase are METHODS (`Customer::total_spend()` and `ActivityLog::totalspend()`),
 * so their call sites are what is counted. The third shape — a raw `SUM(final_price)` keyed on
 * `customer_id` — is counted separately, because the admin API builds its own query rather than
 * calling either method.
 *
 * COMMENT-STRIPPED, IN BOTH DIRECTIONS. CLAUDE.md records eight readings where a census was
 * satisfied, or broken, by prose about the thing it measures — and the paragraphs above this one
 * name every identifier below. `sourceWithoutComments()` uses PHP's own tokenizer for `.php` and
 * strips `{{-- --}}` for Blade.
 *
 * WHAT THIS DOES NOT COVER, said plainly rather than implied:
 *
 *  - STORE-LEVEL money. `ReportController` and `HomeController` sum `orders.final_price` over a
 *    whole store; those are GMV-shaped figures with their own rows (`P05-01`, `GAP-217`) and their
 *    own caveat layer (`App\Support\Reports\ReportFigureNote`). They are not about one customer.
 *  - `packages/workdo/`. Vendored, never edited, and no module loads in a test run without an
 *    `add_on_managers` row.
 *  - The Flutter admin app (`StoreSyria_app98`), a separate repository. What leaves this one is
 *    pinned by `CustomerMoneySurfacesTest` against the two admin endpoints.
 */
final class CustomerMoneyFigureCensusTest extends TestCase
{
    use StripsSourceComments;

    /**
     * The directories searched. `app/` is where the figures are computed; `resources/views/` is
     * where they are printed, and the missed fourth surface was a view.
     */
    private const ROOTS = ['app', 'resources/views'];

    /**
     * Files whose entire content is one Blade comment, so stripping leaves nothing at all.
     *
     * Named rather than pattern-skipped, and pinned by a test below: a silent skip rule is how a
     * census stops looking at part of its subject, which reads exactly like looking and finding
     * nothing.
     */
    private const COMMENT_ONLY_FILES = [
        'resources/views/front_end/hooks/cart_sidebar.blade.php',
        'resources/views/front_end/hooks/header_button.blade.php',
        'resources/views/front_end/hooks/header_nav_link.blade.php',
        'resources/views/front_end/hooks/product_rating.blade.php',
    ];

    /**
     * Every file that calls a per-customer money method, with what it does and what stands beside
     * it. A file that appears here and is NOT found by the scan fails just as loudly as one the
     * scan finds and this list does not — a census that can shrink silently is not one.
     *
     * @var array<string, string>
     */
    private const METHOD_SITES = [
        'app/Models/Customer.php' =>
            'declares total_spend(): the unpredicated per-customer sum itself',
        'app/Models/ActivityLog.php' =>
            'declares totalspend(): the same sum for the timeline, with its order count',
        'app/DataTables/CustomerDataTable.php' =>
            'the merchant customer list: column retitled Order Value, both ledger columns beside it',
        'app/Http/Controllers/CustomerController.php' =>
            'the timeline and the filter fragment: both resolve the ledger figures',
        'app/Support/Customers/CustomerListFilter.php' =>
            'the filter axis: Total Spend selects exactly what it did, two ledger fields added',
        'resources/views/customer/filter.blade.php' =>
            'the filter fragment: labelled cells, and both ledger figures printed',
    ];

    /**
     * Every file that aggregates `orders.final_price` for ONE customer through a query of its own
     * rather than through either method above.
     *
     * @var array<string, string>
     */
    private const RAW_SITES = [
        'app/Models/Customer.php' =>
            'total_spend() sums over ownStoreOrders(), which is itself customer-keyed',
        'app/Models/ActivityLog.php' =>
            'totalspend() groups the customer\'s orders in one raw select',
        'app/Http/Controllers/Api/DashboardController.php' =>
            'customerlist and /customer/orders: total_spend unchanged, ledger block added to both',
    ];

    /**
     * The surfaces that PRINT a per-customer money figure to somebody. Each must name the resolver,
     * because §15 is delivered by what stands beside the figure and not by a filter on it.
     *
     * `CustomerController` covers two surfaces (the timeline and the fragment) and is listed once;
     * `CustomerMoneySurfacesTest` drives all four separately.
     *
     * @var array<string, string>
     */
    private const PRINTING_SURFACES = [
        'app/DataTables/CustomerDataTable.php' => 'CustomerMoneyFigures::forCustomer',
        'app/Http/Controllers/CustomerController.php' => 'CustomerMoneyFigures::forCustomers',
        'app/Http/Controllers/Api/DashboardController.php' => 'CustomerMoneyFigures::forCustomer',
        'resources/views/customer/filter.blade.php' => '$customerMoney',
    ];

    /** Every `.php` file under the searched roots, relative to the repository root. */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (self::ROOTS as $dir) {
            $walker = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walker as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

                /*
                 * FOUR VIEWS ARE NOTHING BUT A BLADE COMMENT -- the `front_end/hooks/*` stubs --
                 * and `sourceWithoutComments()` deliberately FAILS on a file that strips to
                 * nothing, because a stripper that eats its subject turns every absence assertion
                 * into a free pass. They are skipped here, by the same test that would otherwise
                 * crash on them, rather than by widening the trait: a file with no code cannot
                 * hold a money figure, and loosening the trait's own guard would weaken every
                 * other census that uses it.
                 */
                if (in_array($relative, self::COMMENT_ONLY_FILES, true)) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_scan_can_actually_see_the_files_it_claims_to_search(): void
    {
        /*
         * THE REACHABILITY ASSERTION CLAUDE.md §7 ASKS FOR. Without it a wrong root, a renamed
         * directory or a stricter extension filter makes every census below pass by finding
         * nothing, which reads exactly like finding nothing wrong.
         */
        $files = $this->sourceFiles();

        $this->assertGreaterThan(500, count($files),
            'The scan found ' . count($files) . ' files under ' . implode(' and ', self::ROOTS)
            . ', which is too few for this repository: the roots or the filter are wrong.');

        foreach (array_keys(self::METHOD_SITES + self::RAW_SITES + self::PRINTING_SURFACES) as $declared) {
            $this->assertContains($declared, $files,
                $declared . ' is declared below but the scan never reached it.');
        }
    }

    public function test_every_call_site_of_a_per_customer_money_method_is_declared(): void
    {
        /*
         * The two methods, matched as CALLS — `->total_spend(`, `::totalspend(`, and the
         * declarations themselves. A bare word would also match the `total_spend` DATATABLE COLUMN
         * KEY and the admin API's `total_spend` JSON key, neither of which is a call, and the
         * census would then be about a string rather than about who reads the figure.
         */
        $found = [];

        foreach ($this->sourceFiles() as $relative) {
            $code = $this->sourceWithoutComments($relative);

            if (preg_match('/(?:->|::|function\s+)total_?spend\s*\(/i', $code) === 1) {
                $found[] = $relative;
            }
        }

        sort($found);

        $declared = array_keys(self::METHOD_SITES);
        sort($declared);

        $this->assertSame(
            $declared,
            $found,
            "The set of files calling a per-customer money method has changed.\n"
            . "If you have ADDED a surface, it must resolve the two ledger figures beside the\n"
            . "order-value one (PHASE-05-METRIC-DEFINITIONS.md §15) and then be declared here\n"
            . "with what stands beside it. If you have REMOVED one, delete its row.\n"
            . 'found: ' . implode(', ', $found),
        );
    }

    public function test_every_per_customer_raw_money_aggregate_is_declared(): void
    {
        /*
         * THE SHAPE THAT CALLS NEITHER METHOD. `Api\DashboardController` builds
         * `Order::where('customer_id', ...)->sum('final_price')` itself, so the method census above
         * cannot see it — and that endpoint is what the shipped Flutter app reads.
         *
         * WINDOWED, NOT WHOLE-FILE, and that is the load-bearing half: `ReportController` contains
         * both `sum('final_price')` and the word `customer` thousands of lines apart, and a
         * whole-file conjunction would pull every store-level report into a census about one
         * shopper. CLAUDE.md's rule for this is "scope the search to the block too".
         */
        $found = [];

        foreach ($this->sourceFiles() as $relative) {
            $lines = explode("\n", $this->sourceWithoutComments($relative));

            foreach ($lines as $index => $line) {
                if (preg_match('/(?:->sum\(\s*[\'"]final_price[\'"]\s*\)|SUM\(\s*(?:orders\.)?final_price)/i', $line) !== 1) {
                    continue;
                }

                $window = implode("\n", array_slice($lines, max(0, $index - 12), 14));

                if (preg_match('/customer_id|ownStoreOrders/i', $window) === 1) {
                    $found[$relative] = true;
                }
            }
        }

        $found = array_keys($found);
        sort($found);

        $declared = array_keys(self::RAW_SITES);
        sort($declared);

        $this->assertSame(
            $declared,
            $found,
            "The set of files summing orders.final_price for ONE customer has changed.\n"
            . "A new one must carry the §15 ledger figures beside the figure it prints.\n"
            . 'found: ' . implode(', ', $found),
        );
    }

    public function test_every_surface_that_prints_a_customer_money_figure_names_the_resolver(): void
    {
        /*
         * THE DIRECTION THAT CATCHES A SILENT REGRESSION. A surface can keep printing the
         * order-value figure long after somebody deletes the ledger cells beside it, and nothing
         * about the number itself would change — which is precisely the state the fourth surface
         * shipped in for three parts of this gap.
         *
         * `$customerMoney` for the Blade file: a view does not call the resolver, it reads what the
         * controller handed it, and the regex in `CustomerMoneySurfacesTest` pins the two cells
         * themselves.
         */
        foreach (self::PRINTING_SURFACES as $relative => $needle) {
            $code = $this->sourceWithoutComments($relative);

            $this->assertStringContainsString($needle, $code,
                $relative . ' prints a per-customer money figure but no longer names ' . $needle
                . '. The order-value figure is not money received (owner Option A makes that '
                . 'permanent), so the ledger figures beside it are what makes the surface honest.');
        }
    }

    public function test_the_two_ledger_figures_are_never_printed_under_one_name(): void
    {
        /*
         * THE OWNER'S OWN WORDING, as §15 quotes it: "These are different metrics. Never silently
         * label either one as the other." A surface that offers one cell for both is the failure.
         *
         * KEYED ON THE FILES THAT RENDER THE CELLS, not on the ones that resolve the figures --
         * `CustomerController` hands both to a view and names neither, so asserting the words
         * there would be asserting against the wrong file. These four are where a merchant or the
         * app reads them.
         */
        $renderers = [
            'app/DataTables/CustomerDataTable.php',
            'app/Http/Controllers/Api/DashboardController.php',
            'resources/views/customer/filter.blade.php',
            'resources/views/customer/timeline.blade.php',
        ];

        foreach ($renderers as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach (['collected', 'retained'] as $figure) {
                $this->assertStringContainsString($figure, $code,
                    $relative . ' renders no `' . $figure . '` figure, so it cannot be showing '
                    . 'both -- and one of the two printed alone is the mislabelling §15 forbids.');
            }
        }
    }

    public function test_the_skipped_files_really_are_nothing_but_a_comment(): void
    {
        /*
         * The skip list above is the one place this census stops looking, so it is measured rather
         * than trusted. A hook stub that acquires real code must come back into the scan.
         */
        $root = dirname(__DIR__, 3);

        foreach (self::COMMENT_ONLY_FILES as $relative) {
            $raw = (string) file_get_contents($root . '/' . $relative);
            $stripped = trim((string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw));

            $this->assertSame('', $stripped,
                $relative . ' now holds code and must be scanned rather than skipped.');
        }
    }
}

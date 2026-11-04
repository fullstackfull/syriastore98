<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * A report export that cannot produce a file must SAY so, and must not answer 500.
 * ================================================================================
 *
 * WHAT WAS MEASURED. All six CSV export handlers were `$.ajax` calls whose entire option set
 * was three keys -- `url`, `method`, `success` -- with no `error:`, no `complete:` and no
 * `statusCode:`. Each file's only `error:` belongs to the earlier CHART fetch, and the sole
 * handling of a bad payload was `console.error`. So the merchant clicked Export and nothing
 * whatsoever happened: no file, no message, and not even a spinner, because `#loader` is
 * `display:none` and nothing on the export path ever shows it.
 *
 * WHY IT WAS REACHABLE. Five of the six controller methods dereferenced a null session
 * unguarded -- `Session::get('order_line_chart_report')['monthList']` on a session that had
 * never fetched a chart, which is the ORDINARY state of a bookmarked or deep-linked report.
 * `null['monthList']` raises E_WARNING, Laravel's `HandleExceptions::handleError` turns that
 * into an `ErrorException`, and the route answers HTTP 500. The sixth,
 * `category_order_export`, used `?? ''` and answered 200 with a well-formed EMPTY payload --
 * whose `hasOwnProperty` guards therefore passed, whose loop ran zero times, and which
 * downloaded a header-only `exported_data.csv`. One defect with two faces.
 *
 * THE TRAP THIS TEST IS SHAPED AROUND. Guarding the controller is the obvious companion fix,
 * and the moment it lands every `hasOwnProperty` guard passes with `monthList === []`, the loop
 * runs zero times, and ALL SIX screens silently download the header-only CSV that the type
 * check was added to stop. One defect would become six. So the discriminator cannot be
 * `monthList`'s TYPE alone -- it must also be whether there is anything to export. Both guards
 * are asserted here, and `test_the_length_guard_is_what_stops_a_header_only_download` is the
 * control that proves the type check alone is not enough.
 *
 * AND `error:` CANNOT SEE A THROW INSIDE `success`. `sale_by_brand` and `sale_by_category`
 * dereference `data.NetSaleTotal[0].name`, and `NetSaleTotal: []` alongside a 7-entry
 * `monthList` is reachable, which is a `TypeError` at i=0 -- this finding's exact symptom,
 * invisible to an `error:` handler. Hence the `try`/`catch` and the extra length requirement on
 * those two screens.
 *
 * TWO SHAPES THIS REPAIR MUST NOT TAKE, both asserted below:
 *   - NOT a 403 and NOT a redirect carrying `session('error')`. `ReportAuthorizationTest`
 *     defines refusal as exactly those two shapes and asserts the six exports are NOT refused
 *     for the owner and the operator; a guard of that shape would flip 12 assertions.
 *   - NOT a global `$(document).ajaxError()`, which would toast on every failing admin AJAX --
 *     including the chart fetches that fail quietly on purpose -- and double-toast the existing
 *     `show_toastr` call sites.
 */
class ReportExportFailureFeedbackTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * The six exports: URI => the JSON keys the handler must always return.
     *
     * Every one is reached with a session that has NEVER fetched a chart, which is the state
     * the 500 was measured in.
     */
    private const EXPORTS = [
        '/report-export' => ['monthList', 'userTotal', 'registerTotal', 'guest', 'newguestTotal'],
        '/order-report-export' => [
            'monthList', 'TotalOrderCount', 'NetSaleTotal', 'AverageNetSales', 'GrossSaleTotal',
            'AverageGrossSales', 'PurchasedItemTotal', 'ShippingTotal', 'CouponTotal',
        ],
        '/order-barchart-report-export' => [
            'monthList', 'NetSaleTotal', 'AverageNetSales', 'GrossSaleTotal', 'AverageGrossSales',
            'ShippingTotal', 'CouponTotal',
        ],
        '/product-order-export' => ['monthList', 'NetSaleTotal', 'PurchasedItemTotal'],
        '/category-order-export' => ['monthList', 'NetSaleTotal'],
        '/brand-order-export' => ['monthList', 'NetSaleTotal'],
    ];

    /** view => every series that view's success body INDEXES, so every one it must guard. */
    private const SCREENS = [
        'resources/views/reports/index.blade.php' => [
            'monthList', 'userTotal', 'registerTotal', 'guest', 'newguestTotal',
        ],
        'resources/views/reports/sale_by_brand.blade.php' => ['monthList', 'NetSaleTotal'],
        'resources/views/reports/sale_by_category.blade.php' => ['monthList', 'NetSaleTotal'],
        'resources/views/reports/sale_by_product.blade.php' => [
            'monthList', 'NetSaleTotal', 'PurchasedItemTotal',
        ],
        'resources/views/reports/order_report.blade.php' => [
            'monthList', 'TotalOrderCount', 'NetSaleTotal', 'AverageNetSales', 'GrossSaleTotal',
            'AverageGrossSales', 'PurchasedItemTotal', 'ShippingTotal', 'CouponTotal',
        ],
        'resources/views/reports/bar_chart_order_report.blade.php' => [
            'monthList', 'NetSaleTotal', 'AverageNetSales', 'GrossSaleTotal', 'AverageGrossSales',
            'ShippingTotal', 'CouponTotal',
        ],
    ];

    /** The two screens whose loop dereferences `data.NetSaleTotal[0].name`. */
    private const INDEXES_FIRST_SERIES = [
        'resources/views/reports/sale_by_brand.blade.php',
        'resources/views/reports/sale_by_category.blade.php',
    ];

    /** The chart fetches, which fail quietly ON PURPOSE and must stay that way. */
    private const CHART_FETCH_GUARD = [
        'resources/views/reports/index.blade.php',
        'resources/views/reports/sale_by_brand.blade.php',
        'resources/views/reports/sale_by_category.blade.php',
        'resources/views/reports/sale_by_product.blade.php',
        'resources/views/reports/order_report.blade.php',
        'resources/views/reports/bar_chart_order_report.blade.php',
    ];

    /*
     * ---------------------------------------------------------------------------------------
     * THE SERVER HALF -- no merchant admin route should answer 500
     * ---------------------------------------------------------------------------------------
     */

    /**
     * ASSERTS THE STATUS CODE, deliberately, and not `refused()`.
     *
     * `ReportAuthorizationTest` passes 24/24 against the measured 500, because its `refused()`
     * recognises only 403 and redirect-with-session-error. It is not coverage for this defect
     * and must not be mistaken for it.
     */
    public function test_an_unprimed_session_gets_an_empty_payload_and_not_a_server_error(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (self::EXPORTS as $uri => $keys) {
            $response = $this->actingAs($merchant->fresh())->get($uri);

            $this->assertSame(200, $response->status(), sprintf(
                '%s answered %d for the store owner on a session that had never fetched a '
                . 'chart. The handler dereferences a null session, PHP raises E_WARNING, and '
                . 'HandleExceptions turns that into an ErrorException. A bookmarked or '
                . 'deep-linked report is exactly this state.',
                $uri,
                $response->status()
            ));

            $payload = $response->json();

            $this->assertIsArray($payload, $uri . ' did not answer JSON.');

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $payload, sprintf(
                    '%s stopped returning %s. The key set is the contract the six views read; '
                    . 'it must not move.',
                    $uri,
                    $key
                ));

                $this->assertSame([], $payload[$key], sprintf(
                    '%s returned %s as %s. An unprimed session must default every key to an '
                    . 'empty ARRAY: the views now test with Array.isArray, and the \'\' '
                    . 'sentinel the category handler used to return is not an array.',
                    $uri,
                    $key,
                    var_export($payload[$key], true)
                ));
            }
        }
    }

    /**
     * THE BIG NEGATIVE CONTROL: the server guard must not be a refusal.
     */
    public function test_the_guard_is_not_a_403_and_not_a_redirect_carrying_a_session_error(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        foreach ([$merchant, $operator] as $principal) {
            foreach (array_keys(self::EXPORTS) as $uri) {
                $response = $this->actingAs($principal->fresh())->get($uri);

                $this->assertNotSame(403, $response->status(), sprintf(
                    '%s now answers 403 for a principal that is allowed to use it. '
                    . 'ReportAuthorizationTest defines refusal as 403-or-redirect-with-error '
                    . 'and asserts these six are NOT refused for the owner and the operator, so '
                    . 'a guard of that shape flips 12 assertions across 2 tests.',
                    $uri
                ));

                $this->assertFalse(
                    $response->isRedirect() && session()->has('error'),
                    $uri . ' now redirects with session(\'error\'), which is the other refusal shape.'
                );
            }
        }
    }

    public function test_the_routes_stay_get_and_the_filename_is_unchanged(): void
    {
        $web = (string) file_get_contents(base_path('routes/web.php'));

        foreach ([
            "Route::get('report-export'",
            "Route::get('order-report-export'",
            "Route::get('order-barchart-report-export'",
            "Route::get('product-order-export'",
            "Route::get('category-order-export'",
            "Route::get('brand-order-export'",
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $web, sprintf(
                'The export route declaration "%s" moved. This repair changes a handler body '
                . 'and six view scripts; no route, method or middleware may move.',
                $declaration
            ));
        }

        foreach (array_keys(self::SCREENS) as $relative) {
            $this->assertStringContainsString(
                '"exported_data.csv"',
                $this->sourceWithoutComments($relative),
                $relative . ' changed the download filename.'
            );
        }
    }

    public function test_the_handlers_still_read_the_session_and_never_a_request_supplied_store(): void
    {
        /*
         * A tenant predicate built from caller-supplied input is NOT a tenant check. The
         * exports read their own session key; a `$request->store_id` appearing here would be a
         * cross-tenant hole wearing the shape of a bug fix.
         */
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/ReportController.php'));

        foreach ([
            "Session::get('return')",
            "Session::get('order_line_chart_report')",
            "Session::get('order_bar_chart_report')",
            "Session::get('product_order_report')",
            "Session::get('category_order_report')",
            "Session::get('brand_order_report')",
        ] as $read) {
            $this->assertStringContainsString($read, $controller, $read . ' is no longer read.');
        }

        foreach (array_keys(self::EXPORTS) as $uri) {
            $this->assertStringNotContainsString('$request->store_id', $controller, sprintf(
                'ReportController now reads a caller-supplied store_id; %s and its five '
                . 'siblings must derive the tenant from the authenticated principal.',
                $uri
            ));
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * THE CLIENT HALF -- a failure must be visible
     * ---------------------------------------------------------------------------------------
     */

    public function test_every_export_ajax_has_an_error_handler(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $block = $this->exportAjax($relative);

            $this->assertMatchesRegularExpression('/\berror:\s*function\s*\(/', $block, sprintf(
                "%s export \$.ajax still has no error: handler, so a failure is silent -- no "
                . "file, no message, and no spinner (#loader is display:none and nothing on "
                . "this path shows it).\nBlock:\n%s",
                $relative,
                $block
            ));

            $this->assertStringContainsString('show_toastr(', $block, sprintf(
                '%s does not reach the host notifier. show_toastr also mirrors into the shared '
                . 'ss-live-region assertively for a non-success type, so it is the accessible '
                . 'fix as well as the visual one.',
                $relative
            ));
        }
    }

    public function test_no_export_reports_a_bad_payload_to_the_console_only(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $this->assertStringNotContainsString(
                'console.error("Data is not in the expected format:"',
                $this->exportAjax($relative),
                sprintf(
                    '%s still answers a malformed payload with console.error, which no merchant '
                    . 'reads.',
                    $relative
                )
            );
        }
    }

    public function test_every_series_the_screen_indexes_is_type_guarded(): void
    {
        foreach (self::SCREENS as $relative => $series) {
            $block = $this->exportAjax($relative);

            foreach ($series as $key) {
                $this->assertStringContainsString(
                    'Array.isArray(data.' . $key . ')',
                    $block,
                    sprintf(
                        '%s indexes data.%s but does not check it is an array. '
                        . "Array.isArray('') is false, which is what separates the handler's "
                        . 'empty-string sentinel from a real empty array BY TYPE.',
                        $relative,
                        $key
                    )
                );
            }

            $this->assertStringNotContainsString('hasOwnProperty', $block, sprintf(
                '%s still guards with hasOwnProperty, which is true for the \'\' sentinel and '
                . 'for a null, so it admits a payload the loop cannot read.',
                $relative
            ));
        }
    }

    /**
     * THE CONTROL THE WHOLE AMENDMENT TURNS ON.
     *
     * Once the controller defaults every key to `[]`, `Array.isArray([])` is TRUE and the type
     * guard passes. Without a length branch all six screens then download a header-only CSV --
     * one defect becoming six. "No records found" is the right message for a genuinely empty
     * period too: a header row is not an export.
     */
    public function test_the_length_guard_is_what_stops_a_header_only_download(): void
    {
        $this->assertTrue([] === [], 'Premise placeholder for the type/length distinction below.');

        foreach (array_keys(self::SCREENS) as $relative) {
            $block = $this->exportAjax($relative);

            $this->assertMatchesRegularExpression(
                '/data\.monthList\.length\s*===\s*0/',
                $block,
                sprintf(
                    '%s has no empty-period branch. Array.isArray([]) is true, so once the '
                    . 'controller defaults monthList to [] the type guard passes, the loop runs '
                    . 'zero times, and the merchant silently receives a header-only CSV.',
                    $relative
                )
            );

            $this->assertStringContainsString(
                "__('No records found')",
                $block,
                $relative . ' does not tell the merchant the period held nothing to export.'
            );
        }
    }

    public function test_the_two_screens_that_index_the_first_series_require_it_to_be_non_empty(): void
    {
        foreach (self::INDEXES_FIRST_SERIES as $relative) {
            $block = $this->exportAjax($relative);

            $this->assertStringContainsString('data.NetSaleTotal[0]', $block, sprintf(
                'Premise: %s is supposed to dereference data.NetSaleTotal[0].name.',
                $relative
            ));

            $this->assertMatchesRegularExpression(
                '/data\.NetSaleTotal\.length\s*(===\s*0|<\s*1|>\s*0)/',
                $block,
                sprintf(
                    '%s dereferences data.NetSaleTotal[0].name without requiring the series to '
                    . 'be non-empty. NetSaleTotal: [] beside a filled monthList is reachable '
                    . '(ProductBrand::find() resolves nothing and the All-Brands fallback is '
                    . 'skipped), and that is a TypeError at i=0 -- which an error: handler '
                    . 'cannot see, because it is thrown inside success.',
                    $relative
                )
            );
        }
    }

    public function test_the_csv_build_is_wrapped_so_a_throw_inside_success_is_reported(): void
    {
        foreach (array_keys(self::SCREENS) as $relative) {
            $block = $this->exportAjax($relative);

            $this->assertMatchesRegularExpression('/\btry\s*\{/', $block, sprintf(
                '%s does not wrap its CSV build. jQuery\'s error: callback cannot see a throw '
                . 'inside success, so a TypeError there is still an entirely silent failure.',
                $relative
            ));

            $this->assertMatchesRegularExpression('/\}\s*catch\s*\(/', $block, $relative . ' has a try with no catch.');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * NEGATIVE CONTROLS
     * ---------------------------------------------------------------------------------------
     */

    public function test_the_chart_fetches_still_fail_quietly(): void
    {
        /*
         * They fail quietly on purpose: the chart refetches on every period switch, and a toast
         * there would be noise on an ordinary interaction.
         */
        foreach (self::CHART_FETCH_GUARD as $relative) {
            $code = $this->sourceWithoutComments($relative);
            $block = $this->exportAjax($relative);

            $chartOnly = str_replace($block, '', $code);

            $this->assertMatchesRegularExpression(
                '/\berror:\s*function\s*\(/',
                $chartOnly,
                $relative . ' lost the chart fetch\'s own error: handler.'
            );

            $this->assertStringNotContainsString('show_toastr(', $chartOnly, sprintf(
                '%s now toasts from the CHART fetch. That refetches on every period switch, so '
                . 'this adds noise to an ordinary interaction.',
                $relative
            ));
        }
    }

    public function test_the_shared_notifier_was_not_touched(): void
    {
        $custom = (string) file_get_contents(base_path('public/js/custom.js'));

        $this->assertStringContainsString('function show_toastr(', $custom);

        $this->assertStringNotContainsString('ajaxError', $custom, sprintf(
            'A global $(document).ajaxError() would toast on every failing admin AJAX -- the '
            . 'quiet chart fetches included -- and double-toast the existing show_toastr call '
            . 'sites that already handle their own errors.'
        ));
    }

    public function test_no_full_screen_loader_is_shown_on_the_export_path(): void
    {
        /*
         * `#loader` sits inside a full-screen .loader-wrapper. Showing it without a guaranteed
         * hide on EVERY path would leave the page covered after a failure -- strictly worse
         * than the silence this repair replaces.
         */
        foreach (array_keys(self::SCREENS) as $relative) {
            $block = $this->exportAjax($relative);

            $this->assertStringNotContainsString("$('#loader').fadeIn()", $block, sprintf(
                '%s now shows the full-screen loader on export. Without a guaranteed fadeOut on '
                . 'every path that leaves the page covered.',
                $relative
            ));

            $this->assertStringContainsString("$('#loader').fadeOut()", $block, sprintf(
                '%s stopped hiding the loader; keep the existing fadeOut on both branches.',
                $relative
            ));
        }
    }

    public function test_every_message_key_already_exists_in_both_locale_files(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);

        foreach (['Error', 'Something went wrong. Please try again.', 'No records found'] as $key) {
            $this->assertArrayHasKey($key, $en, 'en.json is missing ' . $key);
            $this->assertArrayHasKey($key, $ar, sprintf(
                'ar.json is missing "%s". __() falls back to the KEY, so an invented message '
                . 'renders readable English rather than an identifier -- but ArabicUiCoverageTest '
                . 'globs resources/views/reports/*.blade.php and fails on any key absent from '
                . 'ar.json, and its EXEMPT list is asserted empty.',
                $key
            ));
            $this->assertNotSame($key, $ar[$key], 'ar.json maps ' . $key . ' to the English string.');
        }
    }

    public function test_no_added_comment_can_satisfy_the_report_filter_bar_scan(): void
    {
        /*
         * ReportFilterBarTest strips only Blade comments before scanning these files, and its
         * own docblock records that this trap has already fired three times in this phase.
         */
        foreach (array_keys(self::SCREENS) as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach (['role="tablist"', 'nav nav-pills', 'addClass("active")'] as $literal) {
                $this->assertStringNotContainsString($literal, $code, sprintf(
                    '%s contains "%s". ReportFilterBarTest forbids it and strips only '
                    . '{{-- --}} comments, so a /* */ comment quoting it self-matches.',
                    $relative,
                    $literal
                ));
            }
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * HELPERS
     * ---------------------------------------------------------------------------------------
     */

    /** The export `$.ajax({ ... })` call only -- never the chart fetch above it. */
    private function exportAjax(string $relative): string
    {
        $code = $this->sourceWithoutComments($relative);

        $matched = preg_match(
            "/\\\$\\('\\.exportChartButton'\\)\\.click\\(function\\(\\) \\{(.*?)\n            \\}\\);/s",
            $code,
            $m
        );

        $this->assertSame(1, $matched, sprintf(
            '%s: could not isolate the .exportChartButton click handler, so this test cannot '
            . 'tell the export ajax from the chart fetch.',
            $relative
        ));

        return (string) $m[1];
    }
}

<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * P2.5 customer-orders-export-dead-cdn — the orders-export control fetched its library from a
 * domain that has not served JavaScript since 2019, and failed destructively when it did not.
 * =================================================================================================
 *
 * WHAT WAS WRONG, measured. `resources/views/customer/view.blade.php` pushed
 * `<script src="https://rawgit.com/unconditional/jquery-table2excel/master/src/jquery.table2excel.js">`
 * into `custom-script`, and nothing else defined the plugin: no layout loads it, and
 * `partision/footerlink.blade.php` loads ~30 scripts, none of them table2excel. RawGit was retired
 * in October 2019 — a HEAD of that URL is 404 and a followed GET returns the plain-text shutdown
 * notice, not JavaScript under any status. So `$.fn.table2excel` was undefined when the click
 * handler ran, and the handler's steps are in the wrong order:
 *
 *     $('.ignore').remove();                       // DESTRUCTIVE, runs first
 *     $("#pc-dt-export").table2excel({...});       // THROWS TypeError
 *     window.location.reload();                    // NEVER REACHED
 *
 * One click therefore deleted the Action column — the `th` and every row's `td`, including the
 * View link and the Delete form — and left the page in that state, because the recovery reload is
 * behind the throwing line. simple-datatables' `rows().render()` returns the live `tr`, so the
 * 6-cell rows outlive paging: the Action controls are gone from every page, not just the visible
 * one.
 *
 * WHY THE CONTROL IS REMOVED RATHER THAN REPAIRED (owner-visible, and the challenge's
 * recommendation). Pointing the tag at the vendored `public/js/jquery.table2excel.js` would be the
 * one-line fix, but `#pc-dt-export` at `customer/view.blade.php:28` is the ONLY place in this
 * repository where that selector resolves — `customer/index.blade.php` and `customer/grid.blade.php`
 * contain zero `<table>` (they render cards) and `woocommerce/customer.blade.php` targets
 * `#pc-dt-export` while its table is `#woocom-customer-table`. So the swap would be the FIRST
 * activation of this code path repo-wide, not a mirror of a proven-safe change, and what it would
 * then deliver is wrong: the table carries `class="dataTable"`, `public/js/custom.js` constructs a
 * `simpleDatatables.DataTable` on it and DISCARDS the handle, `perPage` is 10, and `paginate()`
 * empties the tbody down to the current page — so the export would silently write 10 of N orders
 * to a file named `Order_<n>.xlsx` with nothing to say it was truncated. An invisibly-wrong orders
 * export is worse than a visibly-broken button on a money surface, and a correct "export all
 * orders" needs a server-side route (Phase 04).
 *
 * The WooCommerce twin keeps its script tag, pointed at the vendored copy: hygiene only. That
 * handler is unreachable (no element on that page carries `.csv`, and its `@section('action-button')`
 * is empty) and it targets an id the page does not have; correcting the selector is a separate
 * change and is deliberately NOT bundled here.
 *
 * NOT TOUCHED, and asserted below as negative controls: the table's own markup. `PaymentTypeDisplayTest`
 * and `CustomerGuestOrderTenancyTest` both locate this table by the literal `Payment Type` header
 * and then read cells BY INDEX, so deleting or reordering a `th`/`td` breaks them as a payment-label
 * test and a tenancy test — failures that will not look related to an export change.
 */
class CustomerOrdersExportCdnTest extends TestCase
{
    use StripsSourceComments;

    private const ORDERS_VIEW = 'resources/views/customer/view.blade.php';

    private const WOOCOMMERCE_VIEW = 'resources/views/woocommerce/customer.blade.php';

    /**
     * Every host a `<script src="...">` in this source points at, excluding `asset()`/`url()`
     * helpers. A list, not a boolean, so the failure message names what it found.
     *
     * @return list<string>
     */
    private function externalScriptHosts(string $source): array
    {
        preg_match_all('~<script\b[^>]*\bsrc\s*=\s*"([^"]+)"~i', $source, $m);

        $hosts = [];
        foreach ($m[1] ?? [] as $src) {
            if (preg_match('~^(?:https?:)?//([^/"]+)~i', trim($src), $host)) {
                $hosts[] = strtolower($host[1]);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * PREMISE / NEGATIVE CONTROL — the detector can still see the defect.
     *
     * The assertions below are absence claims, and an absence claim passes for free if the
     * detector is broken. This drives the pre-repair tag through the same helper and requires it
     * to be reported, so a regex that matched nothing could not make this file green.
     */
    public function test_the_detector_still_reports_the_pre_repair_script_tag(): void
    {
        $preRepair = '<script src="https://rawgit.com/unconditional/jquery-table2excel/master/src/jquery.table2excel.js"></script>';

        $this->assertSame(['rawgit.com'], $this->externalScriptHosts($preRepair),
            'premise: the external-host detector no longer recognises the exact tag this finding '
            . 'removed, so every absence assertion in this file would pass for free.');

        $this->assertSame([], $this->externalScriptHosts(
            '<script src="{{ asset(\'js/jquery.table2excel.js\') }}"></script>'),
            'premise: the detector reports a locally-served asset as an external host.');
    }

    public function test_neither_customer_view_loads_javascript_from_an_external_host(): void
    {
        foreach ([self::ORDERS_VIEW, self::WOOCOMMERCE_VIEW] as $view) {
            $source = $this->sourceWithoutComments($view);

            $this->assertSame([], $this->externalScriptHosts($source),
                $view . ': loads executable JavaScript from an external host on an authenticated '
                . 'merchant page with no CSP. Serve it from this repository with asset().');

            $this->assertStringNotContainsString('rawgit', strtolower($source),
                $view . ': still references RawGit, retired in October 2019 (404 + shutdown notice).');
        }
    }

    public function test_the_orders_table_no_longer_carries_the_destructive_export_handler(): void
    {
        $source = $this->sourceWithoutComments(self::ORDERS_VIEW);

        $this->assertStringNotContainsString('table2excel', $source,
            self::ORDERS_VIEW . ': still calls table2excel. #pc-dt-export is the only selector of '
            . 'its kind that resolves in this repository and the table is paginated to 10 rows by '
            . 'simple-datatables, so the export would silently truncate the order list.');

        $this->assertDoesNotMatchRegularExpression("~\\\$\\(\\s*'\\.ignore'\\s*\\)\\s*\\.remove~", $source,
            self::ORDERS_VIEW . ": still removes \$('.ignore') from the live DOM. That strips the "
            . 'Action column — the View link and the Delete form — before the throwing line, and '
            . 'the recovery reload never runs.');

        $this->assertStringNotContainsString("'.csv'", $source,
            self::ORDERS_VIEW . ': still binds the .csv export trigger. The control is deferred to '
            . 'a server-side export route (Phase 04), so neither the trigger nor its handler '
            . 'should remain.');

        $this->assertStringNotContainsString('csv', $source,
            self::ORDERS_VIEW . ': still carries a `csv` class. Deferring the control means the '
            . 'anchor goes with the handler, not a dead button that looks live.');
    }

    /**
     * NEGATIVE CONTROL — the WooCommerce twin keeps its tag, pointed at the vendored copy.
     *
     * The repair there is hygiene, not deletion: asserting only "no rawgit" would also pass if
     * somebody deleted the script and the handler, which is a different change than this finding.
     */
    public function test_the_woocommerce_twin_loads_the_vendored_plugin_which_exists_on_disk(): void
    {
        $source = $this->sourceWithoutComments(self::WOOCOMMERCE_VIEW);

        $this->assertStringContainsString("asset('js/jquery.table2excel.js')", $source,
            self::WOOCOMMERCE_VIEW . ': no longer loads the vendored table2excel copy.');

        $this->assertFileExists(base_path('public/js/jquery.table2excel.js'),
            'the vendored plugin the WooCommerce view now points at is not in this repository, so '
            . 'the swap replaced a dead CDN with a dead local path.');

        // The selector mismatch on that page (`#pc-dt-export` vs its own `#woocom-customer-table`)
        // is a SEPARATE finding and must not be bundled into this one.
        $this->assertStringContainsString('#pc-dt-export', $source,
            self::WOOCOMMERCE_VIEW . ': the handler was changed. Correcting its selector is a '
            . 'separate change; this finding is the script source only.');
    }

    /**
     * NEGATIVE CONTROL — the table markup the two DOM tests read by index is byte-identical.
     *
     * `PaymentTypeDisplayTest` does array_search('Payment Type', $headers, true) and then reads
     * $cells[$index]; `CustomerGuestOrderTenancyTest` reads $cells[0] and maps the leading `#`
     * back through orders.product_order_id.
     */
    public function test_the_orders_table_structure_and_order_reference_are_untouched(): void
    {
        $source = $this->sourceWithoutComments(self::ORDERS_VIEW);

        $this->assertStringContainsString('id="pc-dt-export"', $source,
            self::ORDERS_VIEW . ': the table id moved. The repair is the script source, not the table.');

        $this->assertStringContainsString('<th class="ignore">{{ __(\'Action\') }}</th>', $source,
            self::ORDERS_VIEW . ': the Action header was deleted. Removing the export handler must '
            . 'not remove the column it used to strip — that shifts every cell index the payment '
            . 'and tenancy tests read.');

        $this->assertStringContainsString('class="text-center ignore"', $source,
            self::ORDERS_VIEW . ": the row's Action cell was deleted or renamed.");

        $this->assertSame(
            7,
            preg_match_all('~<th\b~', (string) strstr($source, '<thead')),
            self::ORDERS_VIEW . ': the header no longer has exactly 7 columns.'
        );

        $this->assertStringContainsString('#{{ $order->product_order_id }}', $source,
            self::ORDERS_VIEW . ': the order-reference anchor in cell 0 changed. '
            . 'CustomerGuestOrderTenancyTest maps that text back to orders.product_order_id.');
    }

    /**
     * NEGATIVE CONTROL — no new user-visible string was introduced.
     *
     * `ArabicUiCoverageTest` globs `resources/views/customer/*.blade.php` and asserts every `__()`
     * literal has a key in `resources/lang/ar.json`. Disclosing the truncation in the control's
     * tooltip ("Export this page") was the alternative to deferring it, and it needs an ar.json
     * key — which is the over-reach most likely to look right and break CI. This asserts the
     * chosen path added no literal at all.
     */
    public function test_the_repair_adds_no_untranslated_string(): void
    {
        $ar = json_decode(
            (string) file_get_contents(base_path('resources/lang/ar.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($ar);

        preg_match_all(
            '/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]/',
            $this->sourceWithoutComments(self::ORDERS_VIEW),
            $m
        );
        $this->assertNotEmpty($m[1], 'premise: no __() literal found in ' . self::ORDERS_VIEW);

        $missing = [];
        foreach ($m[1] as $raw) {
            $key = str_replace("\\'", "'", $raw);
            if ($key !== '' && ! str_starts_with($key, ':') && ! array_key_exists($key, $ar)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing,
            self::ORDERS_VIEW . ' introduces strings with no Arabic translation: '
            . implode(', ', $missing));
    }
}

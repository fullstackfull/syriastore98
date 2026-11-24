<?php

namespace Tests\Feature\Reports;

use App\Support\Analytics\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE DOWNLOADABLE-PRODUCT REPORT, AND WHY IT IS NOT THE SAME SHAPE.
 * =============================================================================================
 *
 * Last of GAP-221's screens that is both buildable and suite-reachable, and the only one of the
 * four that is not a server-rendered chart.
 *
 * `OrderDownlodableReport()` is FOUR LINES: it renders `SalesDownloadableProductDataTable` and
 * nothing else. There is no query in the handler, no `periodLabel`, and the rows arrive on a
 * SECOND request — yajra's AJAX draw — so a test that only drives the page proves nothing about
 * what the merchant actually sees. Both legs are driven here.
 *
 * THE GET FORM STILL WORKS, AND THAT IS NOT OBVIOUS. yajra sets the table's `ajax.url` to
 * `url()->full()` AS EVALUATED WHEN THE PAGE RENDERS — the fact `ProductController::index` and
 * the Marketing filter-memory work both turn on. So reloading the page with `?ss_period=...`
 * makes the table's own second request carry the same query string, and the two legs cannot
 * disagree. A client-side fill would have left the draw requesting the unfiltered url: the page
 * would look filtered and the rows would not be.
 *
 * IT WINDOWS `orders.order_date`, NOT THE `created_at` THE TABLE DISPLAYS, and that is a decision
 * rather than an oversight. Every other screen in this centre windows `order_date`, and the
 * merchant's own order list shows it under the title "Date" (`OrderDataTable:840`); a period
 * filter that silently meant a different thing on one screen of one centre is the defect this
 * workstream exists to remove. The two coincide in practice — measured: all twelve write sites
 * set `order_date` to `now()` in the request that inserts the row, and nothing edits it
 * afterwards — so this changes no row's membership today. That the TABLE still displays
 * `created_at` under "Timestamp" is recorded on GAP-221 as a lead, not silently changed here.
 */
class DownloadableProductReportPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/order-downloadable-reports';

    private const AJAX = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    private const FEBRUARY = [
        AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
        AnalyticsPeriod::KEY_FROM => '2026-02-01',
        AnalyticsPeriod::KEY_TO => '2026-02-28',
    ];

    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order Reports');   // ReportPermission::ORDERS, which is private
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    /** An order on $when whose invoice reference is recognisable in the drawn rows. */
    private function order($store, string $when, string $ref): void
    {
        DB::table('orders')->insert([
            'product_order_id' => $ref,
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
            'final_price' => 10.00,
            'order_date' => $when,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /** @return array<string, mixed> the minimum yajra needs to answer a draw. */
    private function drawQuery(): array
    {
        $query = ['draw' => 1, 'start' => 0, 'length' => 50];

        foreach (['order_id', 'product_name', 'customer', 'attachment', 'created_at'] as $i => $name) {
            $query["columns[{$i}][data]"] = $name;
            $query["columns[{$i}][name]"] = $name;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'desc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        return $query;
    }

    /** The drawn rows: the AJAX leg, which is where the merchant's rows really come from. */
    private function draw($merchant, array $period = []): string
    {
        $response = $this->actingAs($merchant->fresh())
            ->withHeaders(self::AJAX)
            ->get(self::URI . '?' . http_build_query($period + $this->drawQuery()));

        $response->assertOk();

        return (string) $response->getContent();
    }

    public function test_a_requested_period_narrows_the_drawn_rows(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->order($store, '2026-02-10 10:00:00', 'DL-INSIDE');
        $this->order($store, '2025-06-01 10:00:00', 'DL-BEFORE');
        $this->order($store, '2026-05-01 10:00:00', 'DL-AFTER');

        $rows = $this->draw($merchant, self::FEBRUARY);

        $this->assertStringContainsString('DL-INSIDE', $rows,
            'The order inside the requested period is not in the table.');

        $this->assertStringNotContainsString('DL-BEFORE', $rows,
            'An order placed before the requested period is still drawn, so the control narrows '
            . 'the page and not the rows.');

        $this->assertStringNotContainsString('DL-AFTER', $rows,
            'An order placed after the requested period is still drawn -- the window has a lower '
            . 'bound and no upper one.');
    }

    /**
     * THE TABLE'S OWN SECOND REQUEST MUST CARRY THE PERIOD.
     *
     * The rows are fetched by yajra from `ajax.url`, which is `url()->full()` as evaluated when
     * the page RENDERED. If the period did not reach that url the page would show a chosen period
     * in its control and an unfiltered table beneath it, and the test above — which drives the
     * draw directly — would still pass.
     *
     * SAID PLAINLY: THIS TEST PASSED BEFORE THE FILTER EXISTED, and it is kept anyway. Nothing in
     * this slice makes the url carry the period — `url()->full()` already did, which is exactly
     * why the GET-form pattern transfers to a yajra screen at all. So this is not evidence that
     * the filter works (D1–D4 are); it is a ratchet against the change that would silently break
     * it: a hand-written or route-derived `ajax.url` that drops the query string. Recorded here
     * rather than left to read as proof it is not.
     */
    public function test_the_tables_own_draw_url_carries_the_chosen_period(): void
    {
        [$merchant] = $this->merchantWhoMayRead();

        $page = (string) $this->actingAs($merchant->fresh())
            ->get(self::URI . '?' . http_build_query(self::FEBRUARY))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/"url"\s*:\s*"[^"]*ss_period[^"]*"/u',
            $page,
            'The table fetches its rows from a url that carries no period, so the control filters '
            . 'the page and the rows arrive unfiltered underneath it.'
        );
    }

    /**
     * THE UNASKED CASE MUST NOT MOVE. `AnalyticsPeriod::fromRequest([])` defaults to
     * `last_30_days`, so an unconditional resolve would hide every order older than a month from
     * a report that has always shown all of them.
     */
    public function test_with_no_period_requested_every_order_is_still_drawn(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->order($store, '2026-02-10 10:00:00', 'DL-RECENT');
        $this->order($store, '2021-01-01 10:00:00', 'DL-ANCIENT');

        $rows = $this->draw($merchant);

        $this->assertStringContainsString('DL-RECENT', $rows);
        $this->assertStringContainsString('DL-ANCIENT', $rows,
            'With no period requested the report dropped an old order, so adding the filter '
            . 'silently narrowed what every existing reader sees.');
    }

    /** What the shipped control submits when the merchant picks `All time` and presses Apply. */
    public function test_choosing_all_time_in_the_control_is_not_a_period(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->order($store, '2021-01-01 10:00:00', 'DL-ANCIENT');

        $rows = $this->draw($merchant, [
            AnalyticsPeriod::KEY_PERIOD => '',
            AnalyticsPeriod::KEY_FROM => '',
            AnalyticsPeriod::KEY_TO => '',
        ]);

        $this->assertStringContainsString('DL-ANCIENT', $rows,
            'Submitting the control with All time selected narrowed the table, so the default '
            . 'option does not return the view the merchant had before.');
    }

    public function test_the_screen_offers_the_period_control(): void
    {
        [$merchant] = $this->merchantWhoMayRead();

        $page = (string) $this->actingAs($merchant->fresh())->get(self::URI)->getContent();

        $this->assertStringContainsString('name="ss_period"', $page,
            'The report accepts a period and the page offers no control to choose one.');

        $this->assertStringContainsString('name="ss_from"', $page);
        $this->assertStringContainsString('name="ss_to"', $page);

        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*>\s*' . preg_quote(__('All time'), '/') . '\s*<\/option>/u',
            $page,
            'The control does not offer the unfiltered view as an option.'
        );
    }

    /**
     * STILL ONE STORE'S ORDERS. The window joins the store predicate rather than replacing it;
     * `Order` is not in `config/tenancy.php`'s `scoped_models`, so nothing else would confine the
     * DataTable's query.
     */
    public function test_the_window_does_not_replace_the_store_predicate(): void
    {
        [$merchantA, $storeA] = $this->merchantWhoMayRead();
        [, $storeB] = $this->makeTenant();

        $this->order($storeA, '2026-02-10 10:00:00', 'DL-MINE');
        $this->order($storeB, '2026-02-11 10:00:00', 'DL-THEIRS');

        $rows = $this->draw($merchantA, self::FEBRUARY);

        $this->assertStringContainsString('DL-MINE', $rows);
        $this->assertStringNotContainsString('DL-THEIRS', $rows,
            "Another tenant's order was drawn inside this store's window, so the date predicate "
            . 'replaced the store predicate rather than joining it.');
    }
}

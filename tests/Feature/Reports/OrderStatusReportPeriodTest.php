<?php

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Support\Analytics\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE ORDER-STATUS REPORT COUNTED ALL TIME AND COULD NOT BE NARROWED.
 * =============================================================================================
 *
 * `showOrderStatusReport()` counted every order the store has ever taken and passed the view a
 * hardcoded `'periodLabel' => __('All time')` — a placeholder that names the limitation without
 * removing it. A merchant asking "how many orders were cancelled last month" could not ask it.
 *
 * WHY THIS SCREEN AND NOT THE ONES GAP-221 NAMED. That row's next action said to filter
 * `order_category_reports` and `order_brand_reports` first. Measured 2026-11-22: both ALREADY
 * have a filter — they render `sale_by_category` and `sale_by_brand`, which carry
 * `<x-ss.report-filter>` and post `chart_data` + `Date`. The row's underlying census grepped for
 * `type="date"`, which a JS range picker never emits, so six filtered screens were recorded as
 * unfiltered. This is one of the five that genuinely lack a period and can be tested.
 *
 * IT USES `AnalyticsPeriod` RATHER THAN THE LEGACY `chart_data` VOCABULARY, deliberately, and
 * that is a choice worth stating rather than burying.
 *
 *   The six already-filtered report screens post `chart_data=last-month|this-month|seven-day|year`
 *   plus a `Date` range parsed on `' to '`. `/analytics` posts `ss_period` / `ss_from` / `ss_to`
 *   through `AnalyticsPeriod`, which CLAMPS the range (`MAX_SPAN_DAYS`), labels it, and yields a
 *   comparable baseline through `MetricWindow::previous()`.
 *
 *   GAP-221 names `AnalyticsPeriod` as the building block, and it is the one with tests and a
 *   clamp. Using it here means the reports centre briefly carries two period vocabularies — but
 *   it is CONVERGENCE on the newer one rather than a third invention, and the legacy six are the
 *   ones that should migrate. Recorded on the row so the next reader does not "fix" this by
 *   reverting it to `chart_data`.
 *
 * NO FIGURE'S MEANING CHANGES. The counts are the same counts; only the window moves. With no
 * period requested the screen must answer exactly as before, which the last test pins.
 */
class OrderStatusReportPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function order($store, string $when, int $status): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'OS-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => $status,
            'final_price' => 10.00,
            'order_date' => now()->parse($when),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openReport($merchant, array $query = [])
    {
        return $this->actingAs($merchant->fresh())
            ->get('/Status-reports' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Reports');
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant, $store];
    }

    public function test_a_requested_period_narrows_the_counts(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $cancel = \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL;

        // Two cancellations inside the window, one well outside it.
        $this->order($store, '2026-02-10 10:00:00', $cancel);
        $this->order($store, '2026-02-20 10:00:00', $cancel);
        $this->order($store, '2025-06-01 10:00:00', $cancel);

        $response = $this->openReport($merchant, [
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2026-02-01',
            AnalyticsPeriod::KEY_TO => '2026-02-28',
        ]);

        $response->assertOk();

        $counts = $response->viewData('orderStatusCounts');

        $this->assertSame(2, $counts[__('Cancel')],
            'The order-status report counted every cancellation the store has ever taken rather '
            . 'than the ones inside the requested period, so a merchant cannot ask what happened '
            . 'last month.');

        $this->assertSame(2, $response->viewData('orderStatusTotal'),
            'The total still spans all time while the per-status counts are windowed, so the two '
            . 'figures on one screen describe different periods.');
    }

    /**
     * THE LABEL MUST FOLLOW THE WINDOW. A figure whose caption says "All time" while the number
     * covers February is the defect this whole workstream keeps fixing: a true number under a
     * false label.
     */
    public function test_the_label_describes_the_window_that_was_counted(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $response = $this->openReport($merchant, [
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2026-02-01',
            AnalyticsPeriod::KEY_TO => '2026-02-28',
        ]);

        $response->assertOk();

        $this->assertNotSame(__('All time'), $response->viewData('periodLabel'),
            'A period was requested and the caption still reads "All time", so the number is '
            . 'windowed and its label is not.');
    }

    /**
     * THE UNASKED CASE MUST NOT MOVE. With no period in the query string the screen answers
     * exactly as it did before: every order, labelled "All time". A filter that silently imposes
     * a default window would change every existing reader's figure without being asked to.
     */
    public function test_with_no_period_requested_the_report_still_covers_all_time(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $cancel = \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL;

        $this->order($store, '2026-02-10 10:00:00', $cancel);
        $this->order($store, '2021-01-01 10:00:00', $cancel);

        $response = $this->openReport($merchant);

        $response->assertOk();

        $this->assertSame(2, $response->viewData('orderStatusCounts')[__('Cancel')],
            'With no period requested the report dropped an old order, so adding the filter '
            . 'silently narrowed what every existing reader sees.');

        $this->assertSame(__('All time'), $response->viewData('periodLabel'));
    }

    /**
     * THE CONTROL MUST BE ON THE PAGE, and this is the assertion I have had to add three times
     * today for the same reason.
     *
     * A handler that accepts `ss_period` and a screen that offers no way to send it is a
     * capability nobody can use -- the same shape as a conversion baseline computed and never
     * rendered, and a breakdown shown on screen and absent from the export. Asserting the
     * handler's return value proves the query; only asserting the PAGE proves the feature.
     */
    public function test_the_screen_offers_the_period_control(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $response = $this->openReport($merchant);

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('name="ss_period"', $body,
            'The report accepts a period and the page offers no control to choose one, so the '
            . 'filter exists only for somebody who hand-writes a query string.');

        $this->assertStringContainsString('name="ss_from"', $body);
        $this->assertStringContainsString('name="ss_to"', $body);

        /*
         * ASSERTED ON THE OPTION ELEMENT, NOT ON THE WORDS. A mutation deleting the `All time`
         * option passed a plain `assertStringContainsString(__('All time'), $body)`, because the
         * same words appear in the CHART CAPTION beneath it -- the unfiltered report is labelled
         * "All time" too. The census was satisfied by a different element saying the same thing,
         * which is the trap CLAUDE.md records in three syntaxes.
         */
        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*>\s*' . preg_quote(__('All time'), '/') . '\s*<\/option>/u',
            $body,
            'The control does not offer the unfiltered view as an option, so a merchant who picks '
            . 'a period cannot get back to the report they had before.'
        );
    }

    /**
     * STILL ONE STORE'S ORDERS. The window is a new predicate beside the store predicate, not
     * instead of it -- `Order` is NOT in `config/tenancy.php`'s `scoped_models`, so nothing else
     * would confine this.
     */
    public function test_the_window_does_not_replace_the_store_predicate(): void
    {
        [$merchantA, $storeA] = $this->merchantWhoMayRead();
        [$merchantB, $storeB] = $this->makeTenant();

        $cancel = \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL;

        $this->order($storeA, '2026-02-10 10:00:00', $cancel);
        $this->order($storeB, '2026-02-11 10:00:00', $cancel);

        $response = $this->openReport($merchantA, [
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2026-02-01',
            AnalyticsPeriod::KEY_TO => '2026-02-28',
        ]);

        $response->assertOk();

        $this->assertSame(1, $response->viewData('orderStatusCounts')[__('Cancel')],
            "Another tenant's cancellation was counted inside this store's window, so the date "
            . 'predicate replaced the store predicate rather than joining it.');
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Support\Analytics\AnalyticsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE COUNTRY REPORT COUNTED ALL TIME AND COULD NOT BE NARROWED.
 * =============================================================================================
 *
 * Second of the five screens the corrected GAP-221 row identifies as genuinely unfiltered,
 * suite-reachable and date-dimensioned. Same shape as `showOrderStatusReport()`: a hardcoded
 * `'periodLabel' => __('All time')`, a placeholder that NAMES the limitation without removing it.
 *
 * THIS SCREEN PRINTS TWO NUMBERS FROM TWO SEPARATE QUERIES, AND THAT IS THE WHOLE RISK.
 *
 *   `$billingData` is a join — `order_billing_details` → `countries` → `orders` — and supplies
 *   both the chart and `$withCountry`. `$total` is a plain `Order::count()`.
 *
 *   The footer prints them TOGETHER: "Orders with a billing country: X of Y total orders". A
 *   window applied to the join and not to the count would leave a February chart sitting beside
 *   an all-time total, and the footer would read as though most of the store's orders had lost
 *   their billing country. That is the EXACT defect this screen was already fixed for once —
 *   two numbers counting different things printed side by side — reintroduced through a
 *   different door. So the window must land on BOTH queries, and a test must hold them together
 *   rather than check each alone.
 *
 * `$unmapped` is derived from `$billingData` and follows it for free; it is asserted anyway,
 * because "derived, therefore correct" is an inference and this file is evidence.
 */
class CountryOrderReportPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Insert an order for $store on $when, with a billing row naming $country. */
    private function orderFrom($store, string $when, ?int $countryId): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'OC-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
            'final_price' => 10.00,
            'order_date' => now()->parse($when),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($countryId === null) {
            return;
        }

        DB::table('order_billing_details')->insert([
            'order_id' => $orderId,
            'country' => (string) $countryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function country(string $name): int
    {
        return DB::table('countries')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function openReport($merchant, array $query = [])
    {
        return $this->actingAs($merchant->fresh())
            ->get('/order-country-reports' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /**
     * A principal this screen admits.
     *
     * THE GRANT IS NOT WHAT ADMITS THEM, and the helper used to imply it did by naming the wrong
     * permission entirely ('Manage Reports'; the action is mapped to
     * `ReportPermission::ORDERS`). It passed regardless, because `makeTenant()` creates a
     * type-`admin` user, so `ReportAccess::permits()` returns true at the TENANT OWNER limb
     * before any permission is consulted. The name is corrected here so the helper stops
     * claiming something it does not prove -- but the authorization question itself belongs to
     * `ReportAuthorizationTest`, which already owns it, and is deliberately not re-asserted here.
     */
    private function merchantWhoMayRead(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order Reports');   // ReportPermission::ORDERS, which is private
        Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private const FEBRUARY = [
        AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
        AnalyticsPeriod::KEY_FROM => '2026-02-01',
        AnalyticsPeriod::KEY_TO => '2026-02-28',
    ];

    public function test_a_requested_period_narrows_the_per_country_counts(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $syria = $this->country('Syria');

        $this->orderFrom($store, '2026-02-10 10:00:00', $syria);
        $this->orderFrom($store, '2026-02-20 10:00:00', $syria);

        /* ONE OUTSIDE ON EACH SIDE. Every "outside" fixture in this file first sat BEFORE the
           window, so a mutation dropping only the UPPER bound survived all seven tests -- the
           half of the range nothing was asking about. */
        $this->orderFrom($store, '2025-06-01 10:00:00', $syria);
        $this->orderFrom($store, '2026-05-01 10:00:00', $syria);

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $rows = collect($response->viewData('billingData'))
            ->keyBy('country_name');

        $this->assertSame(2, (int) $rows['Syria']->total_orders,
            'The country report counted every order the store has ever taken from Syria rather '
            . 'than the ones inside the requested period, so a merchant cannot ask which markets '
            . 'bought last month.');
    }

    /**
     * THE TWO FIGURES IN THE FOOTER MUST DESCRIBE THE SAME PERIOD.
     *
     * This is the assertion that earns the test. `$withCountry` comes off the join and `$total`
     * off a separate `Order::count()`; windowing one and not the other prints a February chart
     * under an all-time total and tells the merchant their orders have lost their billing
     * country. The screen was fixed for precisely that confusion once already.
     */
    public function test_the_total_is_windowed_with_the_chart(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $syria = $this->country('Syria');

        // Inside the window: one with a country, one without.
        $this->orderFrom($store, '2026-02-10 10:00:00', $syria);
        $this->orderFrom($store, '2026-02-12 10:00:00', null);

        // Outside it, on BOTH sides: two more of each, which neither figure may count.
        $this->orderFrom($store, '2025-06-01 10:00:00', $syria);
        $this->orderFrom($store, '2025-06-02 10:00:00', null);
        $this->orderFrom($store, '2026-05-01 10:00:00', $syria);
        $this->orderFrom($store, '2026-05-02 10:00:00', null);

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $this->assertSame(1, (int) $response->viewData('withCountry'),
            'Orders with a billing country were counted outside the requested window.');

        $this->assertSame(2, (int) $response->viewData('total'),
            'The chart is windowed and the total beside it still spans all time, so the footer '
            . 'reads "1 of 4 total orders" and tells the merchant three orders lost their '
            . 'billing country when in truth they are simply in another month.');
    }

    /**
     * THE COUNT THE MAP CANNOT DRAW FOLLOWS THE WINDOW TOO. Derived from `$billingData`, so it
     * should come for free — asserted because "should" is an inference.
     */
    public function test_the_unmapped_count_follows_the_window(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        // A name absent from the controller's hardcoded country-code map.
        $nowhere = $this->country('Kingdom of Nowhere');

        $this->orderFrom($store, '2026-02-10 10:00:00', $nowhere);
        $this->orderFrom($store, '2025-06-01 10:00:00', $nowhere);
        $this->orderFrom($store, '2026-05-01 10:00:00', $nowhere);

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $this->assertSame(1, (int) $response->viewData('unmapped'),
            'The orders this map cannot draw were counted across all time while the chart beside '
            . 'them was windowed.');
    }

    public function test_the_label_describes_the_window_that_was_counted(): void
    {
        [$merchant] = $this->merchantWhoMayRead();

        $response = $this->openReport($merchant, self::FEBRUARY);
        $response->assertOk();

        $this->assertNotSame(__('All time'), $response->viewData('periodLabel'),
            'A period was requested and the caption still reads "All time", so the number is '
            . 'windowed and its label is not.');
    }

    /**
     * THE UNASKED CASE MUST NOT MOVE. `AnalyticsPeriod::fromRequest([])` falls through to
     * `last_30_days`, so resolving a period unconditionally would silently narrow every existing
     * reader's figure to the last thirty days without anyone asking for it.
     */
    public function test_with_no_period_requested_the_report_still_covers_all_time(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $syria = $this->country('Syria');

        $this->orderFrom($store, '2026-02-10 10:00:00', $syria);
        $this->orderFrom($store, '2021-01-01 10:00:00', $syria);

        $response = $this->openReport($merchant);
        $response->assertOk();

        $rows = collect($response->viewData('billingData'))->keyBy('country_name');

        $this->assertSame(2, (int) $rows['Syria']->total_orders,
            'With no period requested the report dropped an old order, so adding the filter '
            . 'silently narrowed what every existing reader sees.');

        $this->assertSame(2, (int) $response->viewData('total'));
        $this->assertSame(__('All time'), $response->viewData('periodLabel'));
    }

    /**
     * THE CONTROL MUST BE ON THE PAGE. A handler that accepts `ss_period` with no control to
     * send it is a capability nobody can use.
     */
    public function test_the_screen_offers_the_period_control(): void
    {
        [$merchant] = $this->merchantWhoMayRead();

        $response = $this->openReport($merchant);
        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('name="ss_period"', $body,
            'The report accepts a period and the page offers no control to choose one, so the '
            . 'filter exists only for somebody who hand-writes a query string.');

        $this->assertStringContainsString('name="ss_from"', $body);
        $this->assertStringContainsString('name="ss_to"', $body);

        /* ASSERTED ON THE OPTION ELEMENT, NOT ON THE WORDS: the same words appear in the chart
           caption beneath it, so a plain string search is satisfied by a different element. */
        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*>\s*' . preg_quote(__('All time'), '/') . '\s*<\/option>/u',
            $body,
            'The control does not offer the unfiltered view as an option, so a merchant who picks '
            . 'a period cannot get back to the report they had before.'
        );
    }

    /**
     * WHAT THE SHIPPED CONTROL ACTUALLY SUBMITS, which no test was sending.
     *
     * The first option is `value=""`, so a merchant who picks `All time` and presses Apply sends
     * `?ss_period=&ss_from=&ss_to=` -- NOT an empty query string. The opt-in hinges on
     * `$request->filled()`, which is false for `''`; `has()` or `isset()` would be true, and the
     * control's own default option would quietly become a thirty-day window. Every other test
     * here sends either nothing at all or a full custom triple, so that substitution would have
     * moved every existing reader's figure with the whole file still green.
     *
     * Added 2026-11-22 after an independent review traced the form's real payload. The behaviour
     * is correct today; this is a ratchet, not a repair.
     */
    public function test_choosing_all_time_in_the_control_is_not_a_period(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $syria = $this->country('Syria');

        $this->orderFrom($store, '2026-02-10 10:00:00', $syria);
        $this->orderFrom($store, '2021-01-01 10:00:00', $syria);

        $response = $this->openReport($merchant, [
            AnalyticsPeriod::KEY_PERIOD => '',
            AnalyticsPeriod::KEY_FROM => '',
            AnalyticsPeriod::KEY_TO => '',
        ]);
        $response->assertOk();

        $rows = collect($response->viewData('billingData'))->keyBy('country_name');

        $this->assertSame(2, (int) $rows['Syria']->total_orders,
            'Submitting the control with All time selected narrowed the report, so the default '
            . 'option does not return the view the merchant had before.');

        $this->assertSame(2, (int) $response->viewData('total'));
        $this->assertSame(__('All time'), $response->viewData('periodLabel'));
    }

    /**
     * STILL ONE STORE'S ORDERS. The window joins the store predicate rather than replacing it;
     * `Order` is not in `config/tenancy.php`'s `scoped_models`, so nothing else would confine
     * either query.
     */
    public function test_the_window_does_not_replace_the_store_predicate(): void
    {
        [$merchantA, $storeA] = $this->merchantWhoMayRead();
        [$merchantB, $storeB] = $this->makeTenant();

        $syria = $this->country('Syria');

        $this->orderFrom($storeA, '2026-02-10 10:00:00', $syria);
        $this->orderFrom($storeB, '2026-02-11 10:00:00', $syria);

        $response = $this->openReport($merchantA, self::FEBRUARY);
        $response->assertOk();

        $rows = collect($response->viewData('billingData'))->keyBy('country_name');

        $this->assertSame(1, (int) $rows['Syria']->total_orders,
            "Another tenant's order was counted inside this store's window, so the date predicate "
            . 'replaced the store predicate rather than joining it.');

        $this->assertSame(1, (int) $response->viewData('total'),
            "The total counted another tenant's order inside this store's window.");
    }
}

<?php

namespace Tests\Feature\Analytics;

use App\Models\Customer;
use App\Support\Metrics\MerchantAnalyticsFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-4a — the merchant analytics figures, before any screen renders them.
 *
 * The aggregator is tested on its own because the hard parts are arithmetic and tenancy, not markup:
 * which rows are counted, which store they belong to, what happens when a denominator is zero, and
 * whether a figure that cannot be stated honestly says so instead of showing a number.
 *
 * `$now` IS INJECTED. The window is "the last 30 days", so a test that relied on the wall clock would
 * pass or fail depending on when it ran; every case here fixes the instant and places rows relative to it.
 *
 * WHAT IS DELIBERATELY NOT RE-TESTED HERE: the money tiles. They are `FinanceLedgerFigures`', which has
 * its own tests for ledger coverage, the unknown-currency fold and the two-real-currencies dead end. This
 * only asserts that they are passed through, so a refactor cannot quietly drop them.
 */
final class MerchantAnalyticsFiguresTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A fixed instant, so "the last 30 days" is the same window in every run. */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-20 12:00:00');
    }

    private function logVisit(int $storeId, CarbonImmutable $when): void
    {
        $visit = new Visit();
        // The row's own date is the point of the fixture, so Eloquent must not stamp it with "now".
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->created_at = $when;
        $visit->updated_at = $when;
        $visit->save();
    }

    private function customerIn(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'shopper-'.$this->uniqueFixtureRef().'@example.test',
            'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Tenancy and the window
     * ---------------------------------------------------------------------------------------------
     */

    public function test_orders_and_visits_are_counted_only_for_this_store(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $now = $this->now();

        $this->makeOrder($mine, ['order_date' => $now->subDays(2)]);
        $this->makeOrder($theirs, ['order_date' => $now->subDays(2)]);
        $this->logVisit((int) $mine->id, $now->subDays(2));
        $this->logVisit((int) $theirs->id, $now->subDays(2));
        $this->logVisit((int) $theirs->id, $now->subDays(3));

        $figures = MerchantAnalyticsFigures::forStore((int) $mine->id, 'USD', $now);

        $this->assertStringContainsString('1', (string) $figures['orders']['value']);
        $this->assertStringContainsString('1', (string) $figures['visits']['value']);
    }

    public function test_rows_outside_the_window_are_not_counted(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->makeOrder($store, ['order_date' => $now->subDays(2)]);      // inside
        $this->makeOrder($store, ['order_date' => $now->subDays(400)]);    // long before
        $this->makeOrder($store, ['order_date' => $now->addDays(5)]);      // dated in the future
        $this->logVisit((int) $store->id, $now->subDays(2));
        $this->logVisit((int) $store->id, $now->subDays(400));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsString('1', (string) $figures['orders']['value'],
            'an order outside the stated period was counted in it');
        $this->assertStringContainsString('1', (string) $figures['visits']['value']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Conversion — the figure most easily made dishonest
     * ---------------------------------------------------------------------------------------------
     */

    public function test_conversion_is_orders_over_visits(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        for ($i = 0; $i < 2; $i++) {
            $this->makeOrder($store, ['order_date' => $now->subDays(1)]);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->logVisit((int) $store->id, $now->subDays(1));
        }

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsString('25.00%', (string) $figures['conversion']['value']);
    }

    /** No visits is NOT nought per cent: dividing by nothing is undefined and must read as "no data". */
    public function test_conversion_with_no_visits_shows_no_number_and_says_why(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();
        $this->makeOrder($store, ['order_date' => $now->subDays(1)]);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNull($figures['conversion']['value'],
            'a store with no recorded page views was told its conversion is a number');
        $this->assertStringContainsStringIgnoringCase('nothing to divide by', $figures['conversion']['hint']);
    }

    /** The limit is permanent, so it is on the tile every time rather than only when it looks odd. */
    public function test_every_visit_derived_figure_says_it_counts_requests_not_people(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();
        $this->logVisit((int) $store->id, $now->subDays(1));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        foreach (['visits', 'conversion'] as $key) {
            $this->assertStringContainsStringIgnoringCase('not a count of people', $figures[$key]['hint'],
                $key.' does not say that a page view is not a person');
        }
    }

    public function test_more_orders_than_visits_is_reported_rather_than_hidden(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        for ($i = 0; $i < 3; $i++) {
            $this->makeOrder($store, ['order_date' => $now->subDays(1)]);
        }
        $this->logVisit((int) $store->id, $now->subDays(1));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsStringIgnoringCase('more orders than recorded page views',
            $figures['conversion']['hint']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * New versus returning
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_buyer_who_ordered_before_the_window_is_returning(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $returning = $this->customerIn((int) $store->id);
        $this->makeOrder($store, ['customer_id' => $returning->id, 'is_guest' => 0, 'order_date' => $now->subDays(200)]);
        $this->makeOrder($store, ['customer_id' => $returning->id, 'is_guest' => 0, 'order_date' => $now->subDays(1)]);

        $fresh = $this->customerIn((int) $store->id);
        $this->makeOrder($store, ['customer_id' => $fresh->id, 'is_guest' => 0, 'order_date' => $now->subDays(1)]);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);
        $value = (string) $figures['customers']['value'];

        $this->assertStringContainsString('1', $value);
        $this->assertStringContainsStringIgnoringCase('returning', $value);
        $this->assertStringContainsStringIgnoringCase('new', $value);
    }

    public function test_orders_with_no_customer_are_counted_in_neither_and_stated(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        // makeOrder's default is a guest order: customer_id 0, is_guest 1.
        $this->makeOrder($store, ['order_date' => $now->subDays(1)]);
        $this->makeOrder($store, ['order_date' => $now->subDays(1)]);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsStringIgnoringCase('without a customer account',
            $figures['customers']['hint'],
            'guest orders were silently folded into new buyers or dropped without a word');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Shape, and the delegated money tiles
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_money_tiles_are_passed_through_from_the_finance_figures(): void
    {
        [, $store] = $this->makeTenant();

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $this->now());

        $this->assertArrayHasKey('coverage', $figures);
        $this->assertInstanceOf(\App\Support\Metrics\LedgerCoverage::class, $figures['coverage']);

        foreach (['payments', 'profit'] as $key) {
            $this->assertArrayHasKey($key, $figures, $key.' was dropped from the dashboard figures');
            $this->assertArrayHasKey('hint', $figures[$key]);
        }
    }

    public function test_every_activity_tile_states_its_period(): void
    {
        [, $store] = $this->makeTenant();

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $this->now());

        foreach (['orders', 'visits', 'conversion', 'customers'] as $key) {
            $this->assertNotSame('', trim((string) $figures[$key]['period']),
                $key.' does not say which period it covers, so the number means nothing');
            $this->assertNotSame('', trim((string) $figures[$key]['hint']),
                $key.' carries no explanation of what it counts');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Coupon discounts — the one tile that may refuse to show a number (P5-5, section 9)
     * ---------------------------------------------------------------------------------------------
     */

    /** @param array<string, mixed> $extra */
    private function discountedOrder($store, array $extra, bool $withRedemptionRow): void
    {
        $order = $this->makeOrder($store, array_merge(['coupon_price' => 10.0], $extra));

        if ($withRedemptionRow) {
            DB::table('order_coupon_details')->insert([
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'coupon_id' => 1,
                'coupon_name' => 'TEN', 'coupon_code' => 'TEN',
                'coupon_discount_type' => 'flat', 'coupon_discount_number' => 10.0,
                'coupon_discount_amount' => 10.0, 'coupon_final_amount' => 90.0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Every discounted order in the window has its redemption row, so section 9 is authoritative for
     * this window and the tile shows the money.
     */
    public function test_coupon_discounts_are_shown_when_every_redemption_is_recorded(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, ['order_date' => $now->subDays(2)], true);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNotNull($figures['redemptions']['value'],
            'the records are complete for this window, so withholding the figure is withholding '
            . 'something the data supports');
        $this->assertStringContainsString('10.00', (string) $figures['redemptions']['value']);
        $this->assertStringContainsString('USD', (string) $figures['redemptions']['value']);
        $this->assertStringContainsStringIgnoringCase('not reconstructed',
            $figures['redemptions']['hint'],
            'even a shown figure must say that earlier redemptions are not rebuilt');

        /*
         * AND IT STATES ITS SETTLEMENT BASIS, which a mutation proved was unpinned: deleting the
         * clause failed no test. `CouponRedemption` joins `orders` with no `payment_status` and
         * no cancellation predicate, so a coupon on an unpaid or later-cancelled order counts in
         * full. The Orders and Payments tiles on the same card both spell their basis out; a
         * merchant reading this one as "what coupons cost me" would otherwise overstate it by the
         * cancelled and unpaid share with nothing on screen to say so.
         */
        $this->assertStringContainsStringIgnoringCase('whatever their payment status',
            $figures['redemptions']['hint'],
            'the figure does not say that it counts coupons on unpaid and cancelled orders');
    }

    /**
     * A discounted order in a NAMED currency, with its redemption row. The window and the
     * completeness rule are the same as the happy case above; only the currency differs.
     */
    private function discountedIn($store, string $currency, float $amount, CarbonImmutable $when): void
    {
        $order = $this->makeOrder($store, [
            'coupon_price' => $amount,
            'order_date' => $when,
            'currency_code' => $currency,
        ]);

        DB::table('order_coupon_details')->insert([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'TEN', 'coupon_code' => 'TEN',
            'coupon_discount_type' => 'flat', 'coupon_discount_number' => $amount,
            'coupon_discount_amount' => $amount, 'coupon_final_amount' => 90.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*
     * ANA-RED. THE GUARD ASKED A DIFFERENT QUESTION FROM THE ONE THAT THROWS.
     *
     * `redemptions()` folded the unrecorded-currency bucket, then FILTERED a copy of
     * `byCurrency()` -- dropping zero amounts -- and guarded on `count($buckets) > 1` before
     * calling `$result->total()`. But `MetricResult::total()` throws when
     * `count($this->byCurrency) > 1` over the UNFILTERED array. So the guard that exists to
     * prevent the exception could not see the shape that causes it.
     *
     * MEASURED on this revision before the fix, with a throwaway probe:
     *
     *   a zero SYP bucket beside a real USD bucket   THREW MixedCurrencyException
     *   two zero buckets                             THREW MixedCurrencyException
     *   one SYP bucket, store setting USD            printed "250.00 USD" -- 250 Syrian pounds
     *
     * The first two are an UNCAUGHT exception on the two HTTP surfaces, GET /analytics and
     * GET /analytics/export. The third caller is different and an earlier draft of this comment
     * said otherwise: `ScheduledReportRunner` catches `\Throwable`, logs a warning and marks the
     * schedule FAILED, so there the symptom was a recurring report that silently never arrived --
     * arguably worse, because nobody watches a schedule, but not a 500. The mislabel case does
     * not throw at all and is the quietest of the three: the AMOUNT came from the surviving
     * bucket and the LABEL came from the store's `defult_currancy` setting, so a real number was
     * shown under the wrong currency with no caveat and no refusal.
     *
     * A ZERO-AMOUNT REDEMPTION ROW IS ORDINARY DATA, which is what makes the first two reachable
     * rather than theoretical: `order_coupon_details.coupon_discount_amount` is declared
     * `float default 0`.
     */

    public function test_a_zero_discount_in_another_currency_does_not_crash_the_figure(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        // One SYP order whose coupon discounted nothing, and one USD order that discounted 100.
        // Both have their redemption row, so the completeness rule is satisfied and the tile is
        // expected to SHOW a number -- nothing but the guard mismatch can stop it.
        $this->discountedIn($store, 'SYP', 0.0, $now->subDays(2));
        $this->discountedIn($store, 'USD', 100.0, $now->subDays(2));

        /*
         * THE PREMISE, ASSERTED RATHER THAN ASSUMED (review finding). This test is only about
         * anything if the zero-amount row really produces a SECOND currency bucket -- if
         * `CouponRedemption` ever stopped emitting a group for a zero sum, the test would pass
         * while exercising nothing. It does emit one: the metric INNER-joins the detail rows and
         * groups by `orders.currency_code`, so a `0.0` discount still yields its own group.
         */
        $measured = (new \App\Support\Metrics\CouponRedemption())->for(
            \App\Support\Metrics\MetricScope::store((int) $store->id),
            \App\Support\Metrics\MetricWindow::between($now->subDays(30), $now),
        )->byCurrency();

        $this->assertCount(2, $measured,
            'premise: the fixture must produce TWO currency buckets, or neither this test nor '
            . 'its mutation evidence means anything. Got: ' . json_encode($measured));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNotNull($figures['redemptions']['value'],
            'The figure refused a window in which exactly one currency carries any money.');
        $this->assertStringContainsString('100.00', (string) $figures['redemptions']['value'],
            'the one bucket that holds money is the one that must be reported');
        $this->assertStringContainsString('USD', (string) $figures['redemptions']['value']);
    }

    public function test_two_zero_discounts_in_two_currencies_do_not_crash_the_figure(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedIn($store, 'SYP', 0.0, $now->subDays(2));
        $this->discountedIn($store, 'USD', 0.0, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        // Nothing was discounted, in either currency, so the honest figure is zero -- not a
        // refusal, and certainly not a 500. The refusal is for two currencies that BOTH hold
        // money and have not been converted.
        $this->assertNotNull($figures['redemptions']['value'],
            'The figure refused a window in which no currency carries any money at all.');
        $this->assertStringContainsString('0.00', (string) $figures['redemptions']['value']);

        // AND THE FALLBACK CURRENCY, which a review found unpinned: with only the amount
        // asserted, replacing the whole no-bucket fallback with '' -- or deleting the block, so
        // that `(string) array_key_first([])` leaves an empty string -- still passed. A figure
        // with no unit is not a money figure.
        $this->assertStringContainsString('USD', (string) $figures['redemptions']['value'],
            'the zero figure carries no currency at all, so nothing names what the 0.00 is');
    }

    /**
     * A BLANK `defult_currancy` ROW, which is reachable: this screen's controller and
     * `ScheduledReportRunner` both read it with `->value('value') ?? 'USD'`, and `??` does not
     * catch an empty string. A review measured the consequence on one card -- the Payments tile
     * read `0.00 USD` from `FinanceLedgerFigures`' own blank substitution while this tile read
     * `0.00 ` with no unit. Two money figures side by side disagreeing about whether a figure has
     * a unit is the kind of thing a merchant notices and an engineer does not.
     */
    public function test_a_blank_store_currency_setting_still_names_a_currency(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, '', $now);

        $this->assertStringContainsString('0.00', (string) $figures['redemptions']['value'],
            'premise: the tile shows its zero, so the assertion below is about the unit');
        $this->assertStringContainsString('USD', (string) $figures['redemptions']['value'],
            'A blank currency setting left the figure with no unit, while the Payments tile on '
            . 'the same card substitutes USD for exactly this case.');
    }

    /**
     * THE REFUSAL LIMB, WHICH NOTHING PINNED. Measured while fixing the two cases above: deleting
     * `if (count($buckets) > 1)` failed no test in the suite, so the one branch that exists to
     * stop two unconverted currencies being added together was unprotected. It matters more after
     * the fix, not less, because the array that branch reads changed -- so it is pinned here
     * rather than left to the next person to discover.
     */
    public function test_two_real_currencies_are_refused_rather_than_added(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        // BOTH buckets hold money, so there is no honest single total and no rate to convert with.
        $this->discountedIn($store, 'SYP', 400.0, $now->subDays(2));
        $this->discountedIn($store, 'USD', 100.0, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNull($figures['redemptions']['value'],
            'Two currencies that both hold money were reduced to one number. 400 SYP and 100 USD '
            . 'are not 500 of anything.');

        $hint = (string) $figures['redemptions']['hint'];

        $this->assertStringContainsStringIgnoringCase('more than one currency', $hint,
            'the refusal must say WHY no number is shown, or it reads as missing data');

        // AND IT LISTS THEM, so the tile is not a dead end for a store that changed currency.
        // `FinanceLedgerFigures::tile()` already did this and this tile did not; the data is
        // already in hand, and a refusal that shows nothing reads as missing data rather than as
        // two honest numbers.
        $this->assertStringContainsString('400.00', $hint,
            'the refusal withholds the amounts it is refusing to combine');
        $this->assertStringContainsString('100.00', $hint);
        $this->assertStringContainsString('SYP', $hint);
    }

    /**
     * THE FOLD IS DISCLOSED. `assumingUnknownCurrencyIs()` counts rows that recorded NO currency
     * in the store's currency, and `MetricResult` attaches `CURRENCY_UNRECORDED` plus the folded
     * amount so that a surface can say so. This tile was the ONLY caller of that fold in the
     * application that said nothing -- and because `orders.currency_code` was deliberately never
     * backfilled, an unrecorded currency is the ordinary state of older rows, so the tile could
     * print a real amount under a code the data does not record. That is the same failure mode as
     * the mislabel above, arriving through the sanctioned door.
     */
    public function test_a_discount_with_no_recorded_currency_says_it_was_counted_as_the_stores(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        /*
         * AN ORDER WHOSE CURRENCY WAS NEVER RECORDED, AND IT CANNOT BE BUILT BY PASSING `''`.
         * Measured: the first version of this test did exactly that and failed, because
         * `Order`'s `creating` hook stamps the store's currency whenever the attribute is
         * `empty()` -- so `''` becomes a REAL code, no unknown bucket forms, and there is nothing
         * to fold or disclose. The column has to be NULLed AFTER the write, which is also the
         * honest shape: the migration that added it deliberately did not backfill, so a NULL is
         * what an older row really holds. `Order` is `Cachable`, so the raw write is followed by
         * a flush or Eloquent keeps serving the pre-write row.
         */
        $this->discountedIn($store, 'USD', 75.0, $now->subDays(2));
        DB::table('orders')->where('store_id', $store->id)->update(['currency_code' => null]);
        (new \App\Models\Order())->flushCache();

        $measured = (new \App\Support\Metrics\CouponRedemption())->for(
            \App\Support\Metrics\MetricScope::store((int) $store->id),
            \App\Support\Metrics\MetricWindow::between($now->subDays(30), $now),
        );

        $this->assertSame([\App\Support\Metrics\MetricResult::UNKNOWN_CURRENCY], array_keys($measured->byCurrency()),
            'premise: the fixture must produce the UNRECORDED-currency bucket, or this test '
            . 'asserts a disclosure about a fold that never happened. Got: '
            . json_encode($measured->byCurrency()));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNotNull($figures['redemptions']['value'],
            'premise: the fold happened and a number is shown, which is what needs disclosing');
        $this->assertStringContainsString('75.00', (string) $figures['redemptions']['value']);
        $this->assertStringContainsStringIgnoringCase('carry no currency',
            (string) $figures['redemptions']['hint'],
            'A real amount is shown under the store currency with nothing saying the data did '
            . 'not record one.');
    }

    /**
     * WHEN THE FIGURE IS IN A CURRENCY THAT IS NOT THE STORE'S, the tile says so. This became
     * necessary BECAUSE of the fix: taking the label from the bucket is what stops the mislabel,
     * and it also means a USD store can now correctly read `250.00 SYP` -- which, unexplained,
     * leaves a merchant unable to tell that nothing was converted.
     */
    public function test_a_figure_in_another_currency_says_it_was_not_converted(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedIn($store, 'SYP', 250.0, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsStringIgnoringCase('not converted',
            (string) $figures['redemptions']['hint'],
            'the figure is in SYP on a USD store and nothing says the two were not converted');
    }

    public function test_a_discount_recorded_in_another_currency_is_not_labelled_with_the_stores(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        // The ONLY redemption in the window is in SYP. The store's setting says USD.
        $this->discountedIn($store, 'SYP', 250.0, $now->subDays(2));

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);
        $value = (string) $figures['redemptions']['value'];

        $this->assertStringContainsString('250.00', $value,
            'premise: the amount is reported, so the assertion below is about its LABEL');
        $this->assertStringContainsString('SYP', $value,
            'The amount came from the SYP bucket and the label came from the store setting, so '
            . '250 Syrian pounds were reported as 250 US dollars.');
        $this->assertStringNotContainsString('USD', $value,
            'The wrong currency is still named beside the amount.');
    }

    /**
     * THE REFUSAL, and it is the point of the tile rather than caution: a discounted order with no
     * redemption row inside the window means the figure under-counts by an amount nothing bounds, so
     * NO NUMBER is shown. A figure with "this cannot be trusted" beneath it gets read as a figure.
     */
    public function test_coupon_discounts_show_no_number_when_a_redemption_is_missing(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($store, ['order_date' => $now->subDays(2)], true);
        $this->discountedOrder($store, ['order_date' => $now->subDay()], false);   // the gap

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNull($figures['redemptions']['value'],
            'a discounted order with no redemption record sits inside this window, so the total '
            . 'under-counts and must not be printed');
        $this->assertNotSame('', trim($figures['redemptions']['hint']),
            'a withheld figure must say why it is withheld');
        $this->assertStringContainsStringIgnoringCase('cannot be shown',
            $figures['redemptions']['hint']);
    }

    /** A store that has never discounted an order has nothing missing, so the tile shows 0.00. */
    public function test_a_store_that_never_used_a_coupon_shows_zero_discounts(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();
        $this->makeOrder($store, ['order_date' => $now->subDays(2)]);   // no coupon

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsString('0.00', (string) $figures['redemptions']['value'],
            'no discounted orders means nothing could have gone unrecorded, so the figure is 0 '
            . 'rather than unavailable');
    }

    /** Another store's unrecorded redemption must not withhold this store's figure. */
    public function test_another_stores_missing_redemption_does_not_withhold_this_stores_figure(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $now = $this->now();

        $this->discountedOrder($mine, ['order_date' => $now->subDays(2)], true);
        $this->discountedOrder($theirs, ['order_date' => $now->subDay()], false);

        $figures = MerchantAnalyticsFigures::forStore((int) $mine->id, 'USD', $now);

        $this->assertNotNull($figures['redemptions']['value'],
            'another tenant\'s gap reached this store\'s coverage verdict');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Repeat purchase (P5-6) — the tile whose PERIOD is not this screen's thirty days
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * A PAID order, i.e. one the settled retention basis can actually see.
     *
     * The financial row is UPDATED, not inserted. `OrderObserver::created()` already writes one for
     * every order, so inserting another gives the order two ledger rows and any `COUNT` over them
     * double-counts it. Measured while building this fixture: a hand-inserted row made
     * `LedgerCoverage` read two rows for one order.
     */
    private function paidOrder($store, array $extra = []): object
    {
        $order = $this->makeOrder($store, $extra);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 100.0, 'remaining_amount' => 0.0, 'paid_at' => now()]);

        return $order;
    }

    /**
     * An order THE LEDGER CANNOT SEE, which is what the pre-ledger period really looks like.
     *
     * It cannot be made by omission: the observer writes a financial row for every order, so the row
     * is created and then removed. That is faithful to the condition owner decision D1 is about --
     * an order placed before `store_order_financials` existed, or one whose row was lost to the
     * swallowed exception in `OrderObserver::created()`.
     */
    private function orderTheLedgerCannotSee($store, array $extra = []): object
    {
        $order = $this->makeOrder($store, $extra);

        DB::table('store_order_financials')->where('order_id', $order->id)->delete();

        return $order;
    }

    /**
     * A shopper with two paid orders is a repeat buyer, and the rate is shown over the LEDGER's
     * period rather than the screen's thirty days — because a repeat purchase needs two orders and a
     * thirty-day window would report every store as less loyal than it is.
     */
    public function test_repeat_purchase_is_measured_over_the_ledgers_own_period(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $repeat = $this->customerIn((int) $store->id);
        $this->paidOrder($store, ['customer_id' => $repeat->id, 'is_guest' => 0, 'order_date' => $now->subDays(200)]);
        $this->paidOrder($store, ['customer_id' => $repeat->id, 'is_guest' => 0, 'order_date' => $now->subDays(2)]);

        $once = $this->customerIn((int) $store->id);
        $this->paidOrder($store, ['customer_id' => $once->id, 'is_guest' => 0, 'order_date' => $now->subDays(2)]);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        // One of two recognisable buyers ordered twice.
        $this->assertStringContainsString('50.00%', (string) $figures['retention']['value']);

        /*
         * THE PERIOD MUST NOT BE THE THIRTY-DAY ONE. If it were, the 200-day-old order would fall
         * outside it and that shopper would read as a one-time buyer — the figure wrong in a
         * direction a merchant cannot see. This is the assertion that catches a tile wired to the
         * wrong window.
         */
        $this->assertStringNotContainsString('30', (string) $figures['retention']['period'],
            'the retention tile is using the screen\'s thirty-day window, which under-reports every '
            . 'store: a repeat purchase needs two orders');

        $this->assertStringContainsStringIgnoringCase('paid orders only', $figures['retention']['hint'],
            'the basis must be stated: a repeat rate over unconfirmed orders is not retention');
    }

    /** No financial record anywhere means no period the ledger can vouch for, so no figure. */
    public function test_repeat_purchase_shows_nothing_when_the_ledger_can_vouch_for_no_period(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        /*
         * UNDATED orders with no financial row, which is what `hasNothingVerified()` actually means.
         * A DATED unrowed order does NOT produce a null window — LedgerCoverage starts the verified
         * period the day AFTER it, so the window is non-null and merely excludes that order. Learned
         * by driving it: the first version of this fixture used a dated order and landed in the
         * "nothing to divide by" branch instead, which is correct behaviour for that input and not
         * the branch this case is about.
         */
        $buyer = $this->customerIn((int) $store->id);
        $this->orderTheLedgerCannotSee($store, ['customer_id' => $buyer->id, 'is_guest' => 0, 'order_date' => $now->subDays(2)]);
        $this->orderTheLedgerCannotSee($store, ['customer_id' => $buyer->id, 'is_guest' => 0, 'order_date' => $now->subDay()]);

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertNull($figures['retention']['value'],
            'with no verified period there is nothing to measure, and a number here would be a '
            . 'figure over a basis that does not exist');
        $this->assertStringContainsStringIgnoringCase('nothing is reconstructed',
            $figures['retention']['hint'],
            'the hint must say history is not rebuilt — that is owner decision D1, not a detail');
    }

    /** Guest orders cannot be attributed to a person, so they are excluded AND disclosed. */
    public function test_repeat_purchase_discloses_the_orders_it_excluded(): void
    {
        [, $store] = $this->makeTenant();
        $now = $this->now();

        $buyer = $this->customerIn((int) $store->id);
        $this->paidOrder($store, ['customer_id' => $buyer->id, 'is_guest' => 0, 'order_date' => $now->subDays(2)]);
        $this->paidOrder($store, ['order_date' => $now->subDays(2)]);   // guest: customer_id 0

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $now);

        $this->assertStringContainsStringIgnoringCase('no customer account',
            $figures['retention']['hint'],
            'an excluded order must be counted on the tile, not dropped silently');
    }

    public function test_a_store_with_no_activity_shows_zero_rather_than_failing(): void
    {
        [, $store] = $this->makeTenant();

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $this->now());

        $this->assertStringContainsString('0', (string) $figures['orders']['value']);
        $this->assertStringContainsString('0', (string) $figures['visits']['value']);
        $this->assertNull($figures['conversion']['value']);
    }
}

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

    public function test_a_store_with_no_activity_shows_zero_rather_than_failing(): void
    {
        [, $store] = $this->makeTenant();

        $figures = MerchantAnalyticsFigures::forStore((int) $store->id, 'USD', $this->now());

        $this->assertStringContainsString('0', (string) $figures['orders']['value']);
        $this->assertStringContainsString('0', (string) $figures['visits']['value']);
        $this->assertNull($figures['conversion']['value']);
    }
}

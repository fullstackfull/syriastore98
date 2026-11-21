<?php

namespace Tests\Feature\Analytics;

use App\Models\Setting;
use App\Support\Analytics\MetricThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * ANA-009 — THE ENGINE: a floor a merchant set, and what it is not.
 *
 * THE FIRST TWO TESTS ARE THE REQUIREMENT, not warm-up. ANA-009's traceability row names the trap
 * rather than the feature: what counts as a "drop" is a BUSINESS POLICY and this project does not
 * invent one. So "a store with no configuration has no thresholds" is the property that keeps the
 * implementation honest, and "nothing fires on a platform where nobody has configured anything" is
 * the shipped behaviour. A later refactor that helpfully supplied a default would break both, which
 * is exactly what these are for.
 *
 * ZERO IS NOT BLANK. Those are two different instructions from a merchant -- "tell me if I take
 * nothing" and "do not watch this" -- and a single `empty()` anywhere in the chain collapses them.
 * Two tests hold them apart, because the collapse is silent in both directions: a merchant either
 * stops being warned or starts being warned daily, and neither presents as a bug report that names
 * the cause.
 *
 * `Setting` IS NOT IN `config/tenancy.php`'s `scoped_models` -- measured, 11 models, Setting absent
 * -- so no global scope adds a `store_id` predicate for anybody. The explicit predicate in
 * `configuredFor()` is the ONLY tenant control, which is why a cross-tenant test is here rather than
 * left to the scope.
 */
final class MetricThresholdTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** A fixed clock, so "the last seven days" is the same seven days on every run. */
    private function now(): int
    {
        return (int) \Carbon\CarbonImmutable::parse('2026-06-15 12:00:00')->timestamp;
    }

    private function setThreshold(int $storeId, string $name, $value): void
    {
        Setting::putForStore($name, (string) $value, $storeId, 1);
    }

    /**
     * An order whose money the ledger can see, dated by `paid_at` — which is what
     * `CollectedCustomerPayments` reads, not the order date.
     *
     * The financial row is UPDATED, never inserted: `OrderObserver::created()` already writes one
     * per order, and a second row makes every aggregate double-count. That is measured in
     * `MerchantAnalyticsFiguresTest` and the same trap applies here.
     */
    private function paidOrder($store, float $amount, string $paidAt, string $currency = 'USD'): void
    {
        $order = $this->makeOrder($store, ['final_price' => $amount]);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update([
                'payment_status' => 'paid',
                'paid_amount' => $amount,
                'remaining_amount' => 0.0,
                'paid_at' => $paidAt,
                'currency_code' => $currency,
            ]);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * No policy is invented
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_store_that_has_configured_nothing_has_no_thresholds(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'a threshold existed for a store whose merchant set none — a default has been invented, '
            .'which ANA-009 exists not to do');
    }

    public function test_nothing_is_reported_as_breached_when_nothing_is_configured(): void
    {
        [, $store] = $this->makeTenant();

        // A store taking NO money at all. Any shipped floor above zero would fire here, so this
        // is the test that catches a default even if `configuredFor()` were taught to hide it.
        $this->assertSame([], MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now()),
            'a store with no configuration and no sales was reported as below a threshold');
    }

    public function test_no_default_number_appears_anywhere_in_the_class(): void
    {
        /*
         * A SOURCE ASSERTION, deliberately. The two tests above prove the behaviour for the shipped
         * state; this one states the intent so that a future "sensible default" has to argue with a
         * named test rather than slip in behind a green suite.
         *
         * Comments are STRIPPED FIRST. CLAUDE.md records four occasions on 2026-11-21 alone where a
         * census was satisfied or broken by prose ABOUT the thing it measures — and this class's
         * docblock says the words "no default" repeatedly.
         */
        $source = $this->sourceWithoutComments(app_path('Support/Analytics/MetricThreshold.php'));

        $this->assertStringNotContainsString('?? 0.0', $source,
            'a numeric fallback for a floor appeared in MetricThreshold');

        // The ONE number the class may carry is the window length, and only because a span has to
        // have a unit. It is asserted by name so that it stays the only one.
        preg_match_all('/\?\?\s*([0-9.]+)/', $source, $matches);

        $this->assertSame(['7'], $matches[1],
            'MetricThreshold gained a numeric fallback beyond the window length: '
            .implode(', ', $matches[1]));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Zero is not blank
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_floor_of_zero_is_a_configuration(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '0');

        $configured = MetricThreshold::configuredFor((int) $store->id);

        $this->assertArrayHasKey('payments_received', $configured,
            'a merchant who typed 0 ("tell me if I take nothing") was treated as having configured nothing');
        $this->assertSame(0.0, $configured['payments_received']['floor']);
    }

    public function test_a_blank_field_is_not_a_floor_of_zero(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '');

        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'an empty settings row was read as a floor of zero, so clearing the field would not switch the alert off');
    }

    public function test_a_negative_floor_is_refused_rather_than_compared(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '-50');

        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'a negative floor was accepted; a figure can never fall below it, so the alert would be dead and silent');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The comparison
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_store_below_its_own_floor_is_reported(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');

        $this->paidOrder($store, 120.0, '2026-06-14 10:00:00');

        $breaches = MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now());

        $this->assertCount(1, $breaches, 'a store that took 120 against a floor of 500 was not reported');
        $this->assertSame('payments_received', $breaches[0]['key']);
        $this->assertSame(500.0, $breaches[0]['floor']);
        $this->assertSame(120.0, $breaches[0]['actual']);
        $this->assertSame(7, $breaches[0]['days']);
        $this->assertSame('USD', $breaches[0]['currency']);
    }

    public function test_a_store_on_its_floor_is_not_reported(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '120');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');

        $this->paidOrder($store, 120.0, '2026-06-14 10:00:00');

        // EXACTLY ON the floor is not BELOW it. An off-by-one here alerts a merchant who hit their
        // number, which is the fastest way to make them switch the feature off.
        $this->assertSame([], MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now()),
            'a store that exactly met its floor was reported as below it');
    }

    public function test_money_outside_the_window_does_not_count_towards_the_floor(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');

        // Inside: today-6 is the first day of a 7-day inclusive window ending today.
        $this->paidOrder($store, 100.0, '2026-06-09 23:00:00');
        // Outside by one day. If the window were computed as `subDays(7)` this would be counted and
        // the store would read as 700 rather than 100 — i.e. not breached at all.
        $this->paidOrder($store, 600.0, '2026-06-08 10:00:00');

        $breaches = MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now());

        $this->assertCount(1, $breaches, 'the window swallowed a day it should not have');
        $this->assertSame(100.0, $breaches[0]['actual'],
            'money from before the window counted towards the floor');
    }

    public function test_a_mixed_currency_period_is_not_compared(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '7');

        $this->paidOrder($store, 100.0, '2026-06-14 10:00:00', 'USD');
        $this->paidOrder($store, 100.0, '2026-06-14 11:00:00', 'SYP');

        /*
         * There is no exchange rate at this layer, so 100 USD + 100 SYP is not a number. Summing
         * them would produce 200 against a floor denominated in one currency, and the alert would
         * be arithmetic nonsense in whichever direction it landed. The tiles refuse the same way.
         */
        $this->assertSame([], MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now()),
            'a mixed-currency period was compared against a single-currency floor');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Tenancy — the explicit predicate is the only control
     * ---------------------------------------------------------------------------------------------
     */

    public function test_another_stores_threshold_does_not_configure_this_one(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setThreshold((int) $theirs->id, 'alert_min_payments_received', '500');

        $this->assertSame([], MetricThreshold::configuredFor((int) $mine->id),
            'one store\'s floor configured another\'s — `Setting` is not in `scoped_models`, so the '
            .'explicit store_id predicate is the only thing standing between them');
    }

    public function test_another_stores_money_does_not_satisfy_this_stores_floor(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setThreshold((int) $mine->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $mine->id, 'alert_window_days', '7');

        // Their store had a good week. Mine took nothing.
        $this->paidOrder($theirs, 9000.0, '2026-06-14 10:00:00');

        $breaches = MetricThreshold::breachesFor((int) $mine->id, 'USD', $this->now());

        $this->assertCount(1, $breaches,
            'another tenant\'s takings lifted this store above its own floor');
        $this->assertSame(0.0, $breaches[0]['actual']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The window the merchant chose
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_merchants_own_window_is_used(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '2');

        $this->paidOrder($store, 400.0, '2026-06-10 10:00:00');

        $breaches = MetricThreshold::breachesFor((int) $store->id, 'USD', $this->now());

        $this->assertCount(1, $breaches);
        $this->assertSame(2, $breaches[0]['days'], 'the merchant\'s window was ignored');
        $this->assertSame(0.0, $breaches[0]['actual'],
            'a 2-day window reached back five days');
    }

    public function test_an_absurd_window_falls_back_rather_than_scanning_it(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '500');
        $this->setThreshold((int) $store->id, 'alert_window_days', '100000');

        $configured = MetricThreshold::configuredFor((int) $store->id);

        $this->assertSame(7, $configured['payments_received']['days'],
            'a 100,000-day window was accepted; that is a typo, and the metric layer would happily scan it');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The form's view of the rows
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_form_sees_a_value_the_checker_refuses(): void
    {
        [, $store] = $this->makeTenant();

        $this->setThreshold((int) $store->id, 'alert_min_payments_received', '-50');

        /*
         * THE DIFFERENCE BETWEEN `rawFor()` AND `configuredFor()`, and why both exist. The checker
         * refuses a negative floor silently, which is right. If the FORM showed the same filtered
         * view the merchant would see an empty field while the row still held their typo, re-save
         * the blank, and never learn what happened.
         */
        $this->assertSame([], MetricThreshold::configuredFor((int) $store->id),
            'premise: the checker refuses this value');

        $this->assertSame('-50', MetricThreshold::rawFor((int) $store->id)['payments_received'],
            'the form hid a stored value the checker refuses, so the merchant cannot see or correct it');
    }

    public function test_the_form_gets_every_key_as_a_string(): void
    {
        [, $store] = $this->makeTenant();

        $raw = MetricThreshold::rawFor((int) $store->id);

        foreach (array_merge(array_keys(MetricThreshold::METRICS), ['window_days']) as $key) {
            $this->assertArrayHasKey($key, $raw, "the form would have to handle a missing `{$key}`");
            $this->assertIsString($raw[$key], "`{$key}` reached the view as a non-string");
        }
    }
}

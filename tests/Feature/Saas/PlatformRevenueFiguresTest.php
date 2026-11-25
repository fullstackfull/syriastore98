<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use App\Services\SaasRevenueFigures;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MoneyText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-192 / SAAS-007 — "فصل إيرادات المنصة MRR/ARR عن GMV ومبيعات التجار".
 * =================================================================================================
 * SEPARATE PLATFORM REVENUE (MRR/ARR) FROM GMV AND MERCHANT SALES.
 *
 * What was measured against this requirement in the gap register: `GMV` appears four times in the
 * traceability matrix and **`MRR` and `ARR` appear zero times**. The half that was never built is
 * the platform's OWN revenue — what merchants pay Store Syria — as opposed to what shoppers pay
 * merchants, which is what every money figure in this product measures today.
 *
 * THE TWO ARE NOT THE SAME MONEY AND NOT EVEN THE SAME CURRENCY. Merchant sales live in
 * `store_order_financials.currency_code`, which differs per store; a plan price lives in
 * `plans.price`, which has NO currency column at all and is therefore denominated in whatever the
 * platform's own currency setting says. A screen that put the two near each other without saying
 * so would invite exactly the addition that cannot be made.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS AN ACCOUNTING CONVENTION HERE, AND WHAT WOULD BE AN INVENTED POLICY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * MRR normalises a yearly price by twelve and ARR is MRR times twelve. Those are the definitions
 * of the terms the plan asked for, not decisions about what this platform charges, and the screen
 * states both in words beside the figures.
 *
 * What this slice refuses to decide is which subscriptions COUNT. Rather than pick a rule and bury
 * it in a SUM, every subscription that is not a live recurring one is put in a named bucket and
 * COUNTED on the screen: non-recurring (`Unlimited`), free (price 0), in trial, lapsed, and "no
 * expiry recorded". So an owner reading the screen can see the population the figure was taken
 * from and say "no, a lapsed plan should still count" without any number having been silently
 * assumed. That is the same discipline `PlatformAnalyticsFigures` applies to stores the ledger
 * cannot vouch for: excluded and counted, never dropped and never counted as zero.
 *
 * THE SETTLED-STATUS SET IS CENSUSED, NOT GUESSED. `plan_orders.payment_status` is a free-text
 * column written by about forty gateway handlers in four spellings — `success`, `succeeded`,
 * `Succeeded`, `Approved` — and `PlanOrderSettledStatusCensusTest` re-derives that set from the
 * writers so that a new gateway's spelling reddens instead of silently dropping its money from
 * platform revenue. `is_refund` is excluded for the same reason: `PlanController::refund` sets it,
 * and a receipts figure that ignored it would overstate what the platform kept.
 */
final class PlatformRevenueFiguresTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-25 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    /** A merchant holding a plan. `plan_expire_date` is a STRING column holding `Y-m-d`. */
    private function subscriber(Plan $plan, ?string $expires, array $attributes = []): User
    {
        $merchant = $this->makeMerchant(array_merge([
            'plan_id' => $plan->id,
            'plan_expire_date' => $expires,
        ], $attributes));

        return $merchant->fresh();
    }

    private function planOrder(User $user, Plan $plan, array $attributes = []): PlanOrder
    {
        // plan_name, price_currency and txn_id are NOT NULL in the migration and the model's
        // $fillable omits columns the table declares, so the row is built with forceFill + save.
        $order = new PlanOrder();

        $order->forceFill(array_merge([
            'order_id' => 'PO-' . $this->uniqueFixtureRef(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => $plan->price,
            'price_currency' => 'USD',
            'txn_id' => 'TXN-' . $this->uniqueFixtureRef(),
            'payment_type' => 'Stripe',
            'payment_status' => 'succeeded',
            'is_refund' => 0,
            'created_at' => $this->now()->subDay(),
        ], $attributes))->save();

        return $order->refresh();
    }

    /** Figures are wrapped in bidi isolates for mixed-script rendering; compare without them. */
    private function plain(?string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', (string) $text);
    }

    /**
     * The all-time receipt rows, keyed by the heading each one is printed under.
     *
     * `['all_time']['rows']` AND NOT `['all_time']`, which is a defect this file's own new cases
     * made three times. `all_time` is `['rows' => [...], 'period' => ..., 'hint' => ...]`, so
     * keying THAT by `currency` reads `data_get('all time', 'currency')` for the scalar members,
     * collapses every row under one `''` key, and every `assertArrayHasKey('USD')` then fails for
     * a reason that has nothing to do with the measurement the case exists for. One reader, so the
     * shape is stated once.
     *
     * @return array<string, array{amount: string, orders: int}>
     */
    private function receiptRows(array $figures): array
    {
        $rows = [];

        foreach ($figures['receipts']['all_time']['rows'] as $row) {
            $rows[$row['currency']] = [
                'amount' => $this->plain($row['amount']),
                'orders' => (int) $row['orders'],
            ];
        }

        return $rows;
    }

    /** @return array<string, int> bucket key => count */
    private function buckets(array $figures): array
    {
        $counts = [];

        foreach ($figures['buckets'] as $bucket) {
            $counts[$bucket['key']] = $bucket['count'];
        }

        return $counts;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * MRR and ARR
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_monthly_plan_contributes_its_whole_price_to_mrr(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-12-25');
        $this->subscriber($plan, '2026-12-01');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(200.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(2, $this->buckets($figures)['monthly']);
    }

    /**
     * A YEARLY PRICE IS DIVIDED BY TWELVE, which is the definition of MRR rather than a decision
     * about this platform's prices — and the screen says so in words beside the figure.
     */
    public function test_a_yearly_plan_contributes_one_twelfth_of_its_price_to_mrr(): void
    {
        $plan = $this->makePlan(['price' => 1200, 'duration' => 'Year']);
        $this->subscriber($plan, '2027-06-01');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['yearly']);
    }

    public function test_arr_is_twelve_times_mrr(): void
    {
        $monthly = $this->makePlan(['price' => 50, 'duration' => 'Month']);
        $yearly = $this->makePlan(['price' => 600, 'duration' => 'Year']);
        $this->subscriber($monthly, '2026-12-25');
        $this->subscriber($yearly, '2027-06-01');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1200.0, $figures['recurring']['arr']['raw']);
    }

    /** The figure carries the platform's currency, because `plans.price` does not carry one. */
    public function test_the_recurring_figures_name_a_currency(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-12-25');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertNotSame('', $figures['currency']);
        $this->assertStringContainsString($figures['currency'],
            $this->plain($figures['recurring']['mrr']['value']),
            'an MRR with no currency beside it is not a money figure, and `plans.price` has no '
            . 'currency column of its own to take one from');
        $this->assertSame('100.00 '.$figures['currency'], $this->plain($figures['recurring']['mrr']['value']),
            'the whole money text is compared, not a substring of it: see the note on '
            . 'test_an_unsettled_order_is_excluded_and_counted for the mutation that proved why');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Who is NOT in the figure, and is counted instead
     * ---------------------------------------------------------------------------------------------
     */

    public function test_an_unlimited_plan_is_not_recurring_revenue_and_is_counted_separately(): void
    {
        $plan = $this->makePlan(['price' => 999, 'duration' => 'Unlimited']);
        $this->subscriber($plan, null);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw'],
            'a one-off lifetime price is not monthly recurring revenue; amortising it over an '
            . 'unknown lifetime would be inventing a figure');
        $this->assertSame(1, $this->buckets($figures)['non_recurring'],
            'the subscription must be COUNTED rather than silently dropped');
    }

    public function test_a_lapsed_subscription_is_excluded_and_counted(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-11-24');   // yesterday

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['lapsed']);
    }

    /** The boundary itself: a plan expiring TODAY is still live today. */
    public function test_a_subscription_expiring_today_still_counts(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-11-25');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['mrr']['raw'],
            'the expiry date is the last day of the subscription, not the first day after it');
    }

    public function test_a_free_plan_contributes_nothing_and_is_counted(): void
    {
        $plan = $this->makePlan(['price' => 0, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-12-25');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['free']);
        $this->assertSame(0, $this->buckets($figures)['monthly'],
            'a free subscription is not a paying monthly subscriber');
    }

    /**
     * A TRIAL IS NOT REVENUE YET. `PlanController::planTrial` sets `trial_expire_date` and the
     * merchant pays nothing until it passes, so counting the plan's price now would report money
     * that has not been agreed, let alone collected.
     */
    public function test_a_merchant_in_trial_is_excluded_and_counted(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, '2026-12-25', ['trial_expire_date' => '2026-12-05']);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['in_trial']);
        $this->assertSame(0, $this->buckets($figures)['monthly']);
    }

    /**
     * A RECURRING PLAN WITH NO EXPIRY IS A DATA-QUALITY FINDING, not an eternal subscription.
     *
     * `User::assignPlan()` always writes an expiry for `Month` and `Year` and writes null only for
     * `Unlimited`, so a `Month` row with no expiry means something else wrote it. Treating it as
     * live would inflate MRR with a row nobody can date; treating it as lapsed would hide it. It
     * gets its own bucket.
     */
    public function test_a_recurring_plan_with_no_expiry_is_neither_counted_nor_hidden(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->subscriber($plan, null);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['no_expiry_recorded']);
    }

    /** The platform does not pay itself, and staff are not subscribers. */
    public function test_the_operator_and_staff_rows_are_never_subscribers(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);

        $this->makePlatformAdmin(['plan_id' => $plan->id, 'plan_expire_date' => '2026-12-25']);

        $merchant = $this->subscriber($plan, '2026-12-25');
        $this->makeStaff($merchant, ['plan_id' => $plan->id, 'plan_expire_date' => '2026-12-25']);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['mrr']['raw'],
            'only the tenant root (type `admin`) is the platform\'s customer: the operator would '
            . 'be the platform billing itself, and a staff row shares its owner\'s subscription');
        $this->assertSame(1, $this->buckets($figures)['monthly']);
    }

    /** A merchant with no plan at all is not a subscription of any kind. */
    public function test_a_merchant_with_no_plan_is_in_no_bucket(): void
    {
        $this->makeMerchant(['plan_id' => null]);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(0, array_sum($this->buckets($figures)));
    }

    /**
     * A SUBSCRIPTION WHOSE PLAN ROW IS GONE is disclosed rather than priced at zero.
     *
     * `plans` rows are deletable from the operator's own screen while `users.plan_id` keeps
     * pointing at them, so this is reachable. A zero would read as "that merchant pays nothing".
     */
    public function test_a_subscription_pointing_at_a_missing_plan_is_counted_as_unreadable(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        DB::table('plans')->where('id', $plan->id)->delete();
        (new Plan)->flushCache();

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(1, $this->buckets($figures)['plan_missing'],
            'a merchant whose plan row no longer exists must be reported, not priced at zero');
        $this->assertSame($merchant->id, (int) User::withoutGlobalScopes()->find($merchant->id)->id,
            'premise: the merchant row itself is still there');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Receipts: what the platform actually collected
     * ---------------------------------------------------------------------------------------------
     */

    /** Each of the four spellings the gateway handlers really write is counted. */
    public function test_every_settled_spelling_a_gateway_writes_is_counted(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        foreach (['success', 'succeeded', 'Succeeded', 'Approved'] as $status) {
            $this->planOrder($merchant, $plan, ['payment_status' => $status, 'price' => 10]);
        }

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $rows = $figures['receipts']['all_time']['rows'];

        $this->assertCount(1, $rows, 'one currency was used, so there must be exactly one row');
        $this->assertSame('40.00 USD', $this->plain($rows[0]['amount']),
            'one of the four spellings a gateway writes was not counted as settled, so real money '
            . 'is missing from platform revenue');
    }

    public function test_an_unsettled_order_is_excluded_and_counted(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        $this->planOrder($merchant, $plan, ['payment_status' => 'succeeded', 'price' => 10]);

        foreach (['Pending', 'Rejected', 'Failed', 'pending'] as $status) {
            $this->planOrder($merchant, $plan, ['payment_status' => $status, 'price' => 500]);
        }

        $figures = SaasRevenueFigures::forPlatform($this->now());

        /*
         * THE WHOLE AMOUNT, NOT A SUBSTRING OF IT, AND A MUTATION IS WHY. The first version of
         * this case asserted `assertStringContainsString('10.00', ...)`; the mutation that counts
         * unsettled orders as revenue makes the figure 10 + 4 x 500 = `2,010.00 USD`, which
         * CONTAINS `10.00`, so the mutant SURVIVED and the test proved nothing about the predicate
         * it exists for. Every money assertion in this file is now an equality.
         */
        $this->assertSame('10.00 USD',
            $this->plain($figures['receipts']['all_time']['rows'][0]['amount']),
            'an unsettled plan order was counted as money the platform received');
        $this->assertSame(4, $figures['receipts']['excluded']['unsettled']);
    }

    /** A refunded plan order is money the platform gave back. */
    public function test_a_refunded_order_is_excluded_and_counted(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        $this->planOrder($merchant, $plan, ['payment_status' => 'succeeded', 'price' => 10]);
        $this->planOrder($merchant, $plan, ['payment_status' => 'succeeded', 'price' => 70, 'is_refund' => 1]);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame('10.00 USD',
            $this->plain($figures['receipts']['all_time']['rows'][0]['amount']),
            'a refunded plan order was counted as revenue the platform kept');
        $this->assertSame(1, $figures['receipts']['excluded']['refunded']);
    }

    /**
     * WHY `SaasRevenueFigures::isRefunded()` TOLERATES NULL, AND WHY NO BEHAVIOURAL TEST DRIVES IT.
     *
     * The producer treats a NULL refund flag as "not refunded", because reading it as refunded
     * would silently drop the platform's OLDEST revenue — the money nobody checks. ON THE SCHEMA
     * THESE MIGRATIONS BUILD THAT STATE IS UNREACHABLE, measured rather than assumed: the first
     * version of this case tried to write it and SQLite refused with `NOT NULL constraint failed:
     * plan_orders.is_refund`, because `2024_03_13_111849_add_is_refund_to_plan_orders_table`
     * declares `integer('is_refund')->default(0)` with no `nullable()`.
     *
     * So this test asserts the PREMISE instead of the behaviour, and that is the honest shape:
     * CLAUDE.md records that "the migrations are not the schema" — the restored production dump
     * has already refuted this file's own claims about two columns — so the defensive branch stays
     * for a production table that may differ, and the fact that nothing exercises it is written
     * down here rather than left to be inferred from a passing suite. A mutation that makes NULL
     * mean "refunded" therefore SURVIVES, correctly, and that is reported rather than hidden.
     */
    public function test_the_refund_flag_cannot_be_null_on_the_schema_these_migrations_build(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');
        $order = $this->planOrder($merchant, $plan, ['price' => 40]);

        $refused = false;

        try {
            DB::table('plan_orders')->where('id', $order->id)->update(['is_refund' => null]);
        } catch (\Illuminate\Database\QueryException $e) {
            $refused = true;
        }

        $this->assertTrue($refused,
            'plan_orders.is_refund accepted NULL on this schema. If that is now true in '
            . 'production too, the producer\'s null-tolerant branch becomes reachable and needs a '
            . 'behavioural test: a settled order with a null flag must still be counted as '
            . 'revenue.');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame('40.00 USD',
            $this->plain($figures['receipts']['all_time']['rows'][0]['amount'] ?? ''),
            'premise: the unrefunded order is counted as revenue');
    }

    /**
     * TWO CURRENCIES ARE TWO ROWS AND NEVER A TOTAL.
     *
     * `plan_orders.price_currency` is per order, and converting between currencies would need the
     * rate on each order's own date, which this schema does not record anywhere.
     */
    public function test_receipts_are_reported_per_currency_and_never_added_together(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        $this->planOrder($merchant, $plan, ['price' => 10, 'price_currency' => 'USD']);
        $this->planOrder($merchant, $plan, ['price' => 25, 'price_currency' => 'SYP']);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $rows = $figures['receipts']['all_time']['rows'];
        $byCurrency = [];

        foreach ($rows as $row) {
            $byCurrency[$row['currency']] = $this->plain($row['amount']);
        }

        $this->assertArrayHasKey('USD', $byCurrency);
        $this->assertArrayHasKey('SYP', $byCurrency);
        $this->assertSame('10.00 USD', $byCurrency['USD']);
        $this->assertSame('25.00 SYP', $byCurrency['SYP']);
        $this->assertArrayNotHasKey('total', $figures['receipts']['all_time'],
            'a cross-currency total cannot be computed from this schema and must not exist');
    }

    /** The recent window is its own figure, so a dead platform cannot read as a growing one. */
    public function test_the_recent_window_excludes_older_receipts(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        $this->planOrder($merchant, $plan, ['price' => 10, 'created_at' => $this->now()->subDays(2)]);
        $this->planOrder($merchant, $plan, ['price' => 70, 'created_at' => $this->now()->subDays(200)]);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame('80.00 USD',
            $this->plain($figures['receipts']['all_time']['rows'][0]['amount']),
            'the all-time figure must include the older order');
        $this->assertSame('10.00 USD',
            $this->plain($figures['receipts']['recent']['rows'][0]['amount']),
            'the recent figure must exclude the older order');
    }

    /**
     * AN ORDER WITH NO CURRENCY RECORDED gets its own bucket rather than joining somebody else's.
     *
     * `price_currency` is NOT NULL in the migration but is a free-text 10-character column, so an
     * empty string is reachable; putting it in the first currency's row would overstate that
     * currency.
     */
    public function test_an_order_with_no_currency_is_bucketed_as_unknown(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $merchant = $this->subscriber($plan, '2026-12-25');

        $this->planOrder($merchant, $plan, ['price' => 10, 'price_currency' => 'USD']);
        $this->planOrder($merchant, $plan, ['price' => 70, 'price_currency' => '']);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $currencies = array_column($figures['receipts']['all_time']['rows'], 'currency');

        $this->assertContains('USD', $currencies);
        $this->assertCount(2, $currencies,
            'the order with no currency joined a currency row that it is not denominated in');

        /*
         * AND THE BUCKET IS LABELLED, which is the half a count cannot see. An empty
         * `price_currency` kept as itself also produces two rows — and prints a BLANK beside an
         * amount, which is how a reader concludes the figure is in whatever currency the row above
         * is. `MoneyText::currencyLabel()` is the one place that names this bucket, in both
         * languages, and `PlatformAnalyticsFigures` already relies on it.
         */
        $this->assertContains(__('Currency not recorded'), $currencies,
            'the bucket for an order that recorded no currency printed a blank label, so its '
            . 'amount reads as belonging to the currency beside it');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The two kinds of money stay apart
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE REQUIREMENT ITSELF: a merchant's sales are not platform revenue.
     *
     * A paid store order of 5,000 must move none of these figures. Without this assertion the
     * whole slice could be satisfied by a figure that happens to read the wrong table.
     */
    public function test_a_merchants_own_sales_are_not_platform_revenue(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        [$merchantWithStore, $store] = $this->makeTenant();
        $merchantWithStore->plan_id = $plan->id;
        $merchantWithStore->plan_expire_date = '2026-12-25';
        $merchantWithStore->save();

        $order = $this->makeOrder($store, ['order_date' => $this->now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'paid_amount' => 5000.00,
            'remaining_amount' => 0.00,
            'paid_at' => $this->now()->subDay(),
        ]);

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['mrr']['raw'],
            'a shopper\'s payment to a merchant reached the platform\'s own revenue figure');
        $this->assertSame([], $figures['receipts']['all_time']['rows'],
            'a merchant\'s sales appeared in the platform\'s plan receipts');
    }

    /** Every figure states the period it covers; a money figure with no period misleads. */
    public function test_every_figure_states_its_period(): void
    {
        $figures = SaasRevenueFigures::forPlatform($this->now());

        foreach (['mrr', 'arr', 'subscribers'] as $key) {
            $this->assertNotSame('', (string) $figures['recurring'][$key]['period'],
                "the {$key} figure states no period");
            $this->assertNotSame('', (string) $figures['recurring'][$key]['hint'],
                "the {$key} figure carries no sentence saying what it is and is not");
        }

        foreach (['all_time', 'recent'] as $window) {
            $this->assertNotSame('', (string) $figures['receipts'][$window]['period']);
        }
    }

    /** With nothing on the platform at all, the figures are zero rather than an error. */
    public function test_an_empty_platform_reports_zero_rather_than_failing(): void
    {
        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(0.0, $figures['recurring']['mrr']['raw']);
        $this->assertSame(0.0, $figures['recurring']['arr']['raw']);
        $this->assertSame([], $figures['receipts']['all_time']['rows']);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  THE FOUR PROPERTIES THE INDEPENDENT FINANCIAL REVIEW FOUND UNPINNED (2026-11-25)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * F1b / F9 — THE FIGURE'S DENOMINATION MUST BE A CURRENCY, AND NO TEST DROVE IT AT ALL.
     *
     * `platformCurrency()` read `strtoupper(trim(CURRENCY_NAME))` and fell back to `USD` only when
     * the setting was EMPTY, so a `CURRENCY_NAME` of `Dollar` rendered the MRR tile as
     * `100.00 DOLLAR` — a token that is not a currency, presented as the unit of a money figure.
     *
     * AND THE REVIEW'S SHARPER POINT: no test anywhere seeded `CURRENCY_NAME` for this screen, so
     * the producer always answered through its fallback and the screen test compared the rendered
     * text against the value the producer had just emitted. A producer that returned a hardcoded
     * token would have passed both. The assertion was self-referential; this one is not.
     */
    public function test_a_currency_name_that_is_not_a_currency_code_does_not_become_the_denomination(): void
    {
        $operator = $this->makePlatformAdmin();

        DB::table('settings')->insert([
            'name' => 'CURRENCY_NAME', 'value' => 'Dollar',
            'created_by' => $operator->id, 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Cache::forget('super_admin_details');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame('USD', $figures['currency'],
            'a free-text `CURRENCY_NAME` of "Dollar" became the denomination of the platform MRR '
            . 'figure. `CurrencySymbol::code()` is this application\'s single definition of "is a '
            . 'currency code" (PHASE-05-METRIC-DEFINITIONS.md section 11 rule 3), and a reader that '
            . 'groups money by the value needs it rather than `clean()`.');
    }

    /** AND THE CONTROL: a real code IS used, so the fix is not just "always USD". */
    public function test_a_real_currency_code_in_the_setting_is_used_as_the_denomination(): void
    {
        $operator = $this->makePlatformAdmin();

        DB::table('settings')->insert([
            'name' => 'CURRENCY_NAME', 'value' => 'syp',
            'created_by' => $operator->id, 'store_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Cache::forget('super_admin_details');

        $this->assertSame('SYP', SaasRevenueFigures::forPlatform($this->now())['currency'],
            'a valid three-letter code was discarded, so the validator is refusing everything '
            . 'rather than refusing non-codes');
    }

    /**
     * F1a — A NON-CODE `price_currency` MUST NOT GET ITS OWN RECEIPT ROW.
     *
     * `plan_orders.price_currency` is `string(10)` written by ~40 gateway handlers from the same
     * free-text setting, so `$`, `Dollar` or `ل.س` are all storable. Upper-casing them gave each
     * its OWN row with a verbatim heading and a partial subtotal — splitting one real currency
     * across two rows, which is the FIN-SYM failure the metric definitions were written after.
     */
    public function test_a_non_code_price_currency_joins_the_unknown_bucket_rather_than_splitting_a_currency(): void
    {
        $plan = $this->makePlan(['price' => 100]);
        $merchant = $this->subscriber($plan, '2030-01-01');

        $this->planOrder($merchant, $plan, ['price' => 60, 'price_currency' => 'USD', 'payment_status' => 'success']);
        $this->planOrder($merchant, $plan, ['price' => 40, 'price_currency' => 'Dollar', 'payment_status' => 'success']);

        $rows = $this->receiptRows(SaasRevenueFigures::forPlatform($this->now()));

        $this->assertArrayNotHasKey('Dollar', $rows,
            'a `price_currency` of "Dollar" became its own currency row with its own subtotal, so '
            . 'one real currency is split across two rows and neither is the truth');
        $this->assertArrayNotHasKey('DOLLAR', $rows,
            'the same row under the spelling the previous `strtoupper(trim())` produced');

        $this->assertArrayHasKey('USD', $rows, 'the real code lost its own row');
        $this->assertSame('60.00 USD', $rows['USD']['amount'],
            'the USD row absorbed the non-code amount, which would be worse than splitting it');

        /*
         * AND THE 40 IS STILL ON THE SCREEN. Routing a non-code to the unknown bucket must not
         * DROP the money -- that would trade a split subtotal for a missing one, which is the
         * worse of the two. It joins the one bucket whose heading is spelled as itself.
         */
        $unknown = MoneyText::currencyLabel(MetricResult::UNKNOWN_CURRENCY);

        $this->assertArrayHasKey($unknown, $rows,
            'the non-code payment left the screen altogether instead of joining the bucket that '
            . 'exists for exactly this case');
        $this->assertSame('40.00 '.$unknown, $rows[$unknown]['amount'],
            'the unknown bucket does not hold the non-code amount');
        $this->assertSame(1, $rows[$unknown]['orders'], 'the unknown bucket lost its order count');
    }

    /**
     * F2 — A SETTLED PAYMENT MUST NOT VANISH FROM THE SCREEN ENTIRELY.
     *
     * `MoneyText::buckets()` drops any bucket under half a cent by default, so a currency whose
     * settled orders summed to 0.00 disappeared AND ITS ORDER COUNT WITH IT — the payment was in no
     * row, in no excluded count, and nowhere on the screen, while this class's own header says
     * "Nothing is dropped and nothing is counted as zero".
     *
     * REACHABLE ON A NORMAL PATH, which is why it is a defect and not a curiosity:
     * `StripePaymentController:136-142` writes a `PlanOrder` with `price = 0`,
     * `payment_status = 'succeeded'` and `receipt = 'free coupon'` when the charge is skipped. Free
     * plans and 100%-coupon purchases land there.
     */
    public function test_a_settled_payment_of_zero_still_appears_with_its_order_count(): void
    {
        $plan = $this->makePlan(['price' => 0]);
        $merchant = $this->subscriber($plan, '2030-01-01');

        $this->planOrder($merchant, $plan, [
            'price' => 0, 'price_currency' => 'USD',
            'payment_status' => 'succeeded', 'receipt' => 'free coupon',
        ]);

        $rows = $this->receiptRows(SaasRevenueFigures::forPlatform($this->now()));

        $this->assertArrayHasKey('USD', $rows,
            'a SETTLED payment of 0.00 vanished from the receipts entirely -- not in a row, not in '
            . 'an excluded count, nowhere. A free plan or a 100%-coupon purchase is an ordinary '
            . 'path, and the screen then reads "Not available", which is measurement-failure '
            . 'wording for a true and countable zero.');

        $this->assertSame('0.00 USD', $rows['USD']['amount'],
            'the row exists but does not state the zero it is there to state');
        $this->assertSame(1, $rows['USD']['orders'],
            'the row survived but its order count did not, so the payment is still uncounted');
    }

    /**
     * F6 — ARR MUST BE DERIVED FROM THE UNROUNDED MRR, and the price here is 100 ON PURPOSE.
     *
     * Rounding MRR to 2dp and then multiplying by twelve loses money visibly: one yearly plan at
     * 100 gives MRR 8.33 and an ARR of 99.96 against a true annual of 100.00. EVERY YEARLY PRICE IN
     * THIS FILE'S OTHER FIXTURES IS 1200 OR 600 — both exactly divisible by twelve — so the rounded
     * and unrounded derivations agreed for every case the suite drove and nothing could see it.
     * That is CLAUDE.md's trap: the fixture cannot tell the two readings apart.
     */
    public function test_the_annual_figure_is_not_lost_to_rounding_on_a_price_not_divisible_by_twelve(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Year']);
        $this->subscriber($plan, '2030-01-01');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $this->assertSame(100.0, $figures['recurring']['arr']['raw'], sprintf(
            'the annual figure is %s for a single yearly plan priced at 100. Deriving ARR from the '
            . 'ROUNDED monthly figure (8.33 x 12) gives 99.96; it must come from the unrounded '
            . 'accumulation.',
            var_export($figures['recurring']['arr']['raw'], true)
        ));
        $this->assertSame('100.00 '.$figures['currency'],
            $this->plain($figures['recurring']['arr']['value']),
            'the accumulation is right but the figure printed beside it is not');

        $this->assertSame(8.33, $figures['recurring']['mrr']['raw'],
            'the monthly figure should still be the rounded one for display');
    }

    /**
     * GAP-217's RULE WAS APPLIED HERE, AND THE ENGINE REFUSED THE PREMISE.
     *
     * A money figure read off a FLOAT column must disclose its precision -- that is settled for the
     * report tiles. `plan_orders.price` is declared `$table->float('price')`, so the caveat was
     * added to both receipt figures. Then `verify-saas-revenue-on-mysql.php` measured the table the
     * REAL migrations build on MariaDB 10.11: **Laravel 11's `float()` emits a MySQL `double`**
     * (`price double DEFAULT NULL`), and `2500000.49` -- SYP scale, where a single-precision
     * mantissa would drop the minor units -- stores and reads back exactly. A hand-written
     * `CREATE TABLE t (p float)` DOES lose them, which is what made the claim briefly look true.
     *
     * So the caveat came back out, and this case pins its ABSENCE across every figure on the screen.
     * It is not a style assertion: the caveat is a CLAIM ABOUT A COLUMN, and a sweep that attaches
     * caveats by column name -- `float` in a migration reads like one -- would put a limitation on
     * this money screen that the schema does not have. `MRR`/`ARR` are a second reason: they are
     * computed from `plans.price`, a VARCHAR, so the claim would be false for them twice over.
     *
     * WHAT WOULD MAKE THIS CASE WRONG rather than merely red: a migration that changes
     * `plan_orders.price` to a real `FLOAT`. The MySQL harness asserts the column type, so it goes
     * red in the same change and sends the reader here.
     */
    public function test_no_figure_claims_a_precision_limit_this_schema_does_not_have(): void
    {
        $figures = SaasRevenueFigures::forPlatform($this->now());
        $caveat = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        foreach (['all_time', 'recent'] as $window) {
            $this->assertStringNotContainsString($caveat, $figures['receipts'][$window]['hint'], sprintf(
                'the %s receipts figure claims a float-precision limit. `plan_orders.price` is a '
                . 'MySQL `double` (measured, not read off the migration), so the figure is exact in '
                . 'the minor units and the caveat is a fabricated limitation.',
                $window
            ));
        }

        foreach (['mrr', 'arr', 'subscribers'] as $figure) {
            $this->assertStringNotContainsString($caveat, $figures['recurring'][$figure]['hint'], sprintf(
                'the %s figure claims the float-precision limit, but it is computed from '
                . '`plans.price`, a VARCHAR, and parsed to a PHP double.',
                $figure
            ));
        }
    }
}

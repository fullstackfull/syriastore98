<?php

namespace Tests\Feature\Reports;

use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Metrics\FinanceLedgerFigures;
use App\Support\Metrics\WalletEntitlementFigure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-002 / GAP-221 — THE FINANCE REPORT COULD NOT BE NARROWED, AND THE METRIC LAYER COULD.
 * =============================================================================================
 *
 * Third of GAP-221's screens, and the one that needed its prerequisite checked hardest, because
 * two plausible readings of it are both wrong.
 *
 * WRONG READING 1: "it has no `periodLabel`, so it was never period-aware". It is period-aware
 * already, in the only place that matters: `FinanceLedgerFigures::forStore()` has taken
 * `?MetricWindow $requested` and `?string $requestedLabel` since D1 Option A, and it CLAMPS the
 * request to the verified ledger coverage and REFUSES, in words, a period that ends before the
 * records begin. `financeReport()` simply never passed one. So this slice wires an existing
 * capability to a control; it does not invent a window, and it must not re-implement the clamp.
 *
 * WRONG READING 2: "put the period on all three tiles". **THE THIRD TILE IS A BALANCE.**
 * `Available to withdraw` comes from `WalletEntitlementFigure::forStore()`, whose signature takes
 * no window at all and whose period reads `right now`. A date range over a position at an instant
 * is as meaningless as a date range over a stock level — the argument GAP-221 already uses to
 * exclude the stock reports from this workstream. Windowing it would not narrow a number; it
 * would invent one.
 *
 * So the screen deliberately carries BOTH semantics at once, and the test holds that: two flows
 * follow the control, one balance does not and keeps saying so. A test that only checked the
 * flows would pass just as happily against a change that silently windowed the balance.
 */
class FinanceReportPeriodTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/finance-reports';

    private const FEBRUARY = [
        AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
        AnalyticsPeriod::KEY_FROM => '2026-02-01',
        AnalyticsPeriod::KEY_TO => '2026-02-28',
    ];

    private function merchantWhoMayRead(): array
    {
        [, $decoy] = $this->makeTenant();              // store 1 is a decoy, never the tenant under test
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'View Finance Dashboard');
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant, $store, $decoy];
    }

    /** A payment of $amount collected on $paidAt, which is the day `CollectedCustomerPayments` counts. */
    private function payment($store, string $paidAt, string $amount): void
    {
        $order = $this->makeOrder($store, ['order_date' => $paidAt]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'paid_amount' => $amount,
            'paid_at' => $paidAt,
            'currency_code' => 'USD',
            'updated_at' => now(),
        ]);
    }

    private function open($merchant, array $query = [])
    {
        return $this->actingAs($merchant->fresh())
            ->get(self::URI . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** Figures are wrapped in bidi isolates for safe mixed-script rendering; compare without them. */
    private function plain(?string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', (string) $text);
    }

    /**
     * ASSERTED ON THE TILE'S OWN VALUE, NOT ON THE PAGE TEXT, and that correction is the point.
     *
     * The first version of this test put one payment inside the window and two outside, then
     * searched the body for the inside amount and against the outside ones. **A mutation that
     * never passed the window at all survived it.** The tile prints a SUM, not a list: with the
     * window gone the figure becomes 11,100.00, which does not contain the string `4,000.00` and
     * DOES contain the string `100.00`. Both assertions were satisfied by a number that was
     * wrong in exactly the way the test existed to detect.
     */
    public function test_a_requested_period_narrows_payments_received(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '123.45');
        $this->payment($store, '2025-06-01 10:00:00', '4000.00');   // before the window
        $this->payment($store, '2026-05-01 10:00:00', '7000.00');   // after it

        $response = $this->open($merchant, self::FEBRUARY);
        $response->assertOk();

        $value = $this->plain($response->viewData('ledgerFigures')['payments']['value']);

        $this->assertStringContainsString('123.45', $value,
            'The payment inside the requested period is not in the figure.');

        $this->assertStringNotContainsString('11,123.45', $value,
            'Payments collected outside the requested period are still being summed into it, so '
            . 'the finance report answers a different question from the one its control asks.');

        $this->assertStringNotContainsString('7,123.45', $value,
            'A payment collected AFTER the requested period is counted -- the window has a lower '
            . 'bound and no upper one.');

        $this->assertStringNotContainsString('4,123.45', $value,
            'A payment collected BEFORE the requested period is counted.');
    }

    /**
     * THE BALANCE MUST NOT FOLLOW THE CONTROL, and this is the assertion that earns the file.
     *
     * `Available to withdraw` is a position at an instant. Its assembler takes no window, and its
     * own period reads `right now`. Windowing it would not narrow a number, it would invent one —
     * and nothing else in this test would notice, because the two flow tiles would still be right.
     */
    public function test_the_withdrawable_balance_is_not_windowed(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '100.00');

        $response = $this->open($merchant, self::FEBRUARY);
        $response->assertOk();

        $wallet = WalletEntitlementFigure::forStore((int) $store->id, 'USD');
        $shown = $response->viewData('walletFigure');

        /*
         * COMPARED FIELD BY FIELD AGAINST THE ASSEMBLER, not searched for in the page. A mutation
         * that RELABELLED this tile with the chosen period -- the "make it consistent" change the
         * handler comment warns about -- survived a test that only asked whether the words `right
         * now` appeared somewhere on a page that also carries two other tiles.
         */
        $this->assertSame($this->plain($wallet['period']), $this->plain($shown['period']),
            'The balance tile was relabelled with the reporting period. It is a position at an '
            . 'instant: a merchant now reads it as money earned in February.');

        $this->assertSame($this->plain($wallet['value']), $this->plain($shown['value']),
            'The withdrawable balance changed when a reporting period was chosen. No date range '
            . 'can narrow a balance, so one that appears to has invented a figure.');
    }

    /**
     * THE UNASKED CASE MUST NOT MOVE. With no period in the query string the screen must answer
     * exactly as the assembler does when called with no window -- which is NOT the words "All
     * time": `FinanceLedgerFigures` labels the period from the ledger's own verified coverage.
     * Compared against the assembler rather than a literal, because a literal would pass while
     * the two drifted.
     */
    public function test_with_no_period_requested_the_figures_are_unchanged(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '100.00');
        $this->payment($store, '2021-01-01 10:00:00', '250.00');

        $expected = FinanceLedgerFigures::forStore((int) $store->id, 'USD');

        $body = $this->plain(html_entity_decode(
            $this->open($merchant)->getContent(), ENT_QUOTES | ENT_HTML5));

        foreach (['payments', 'profit'] as $tile) {
            if ($expected[$tile]['value'] === null) {
                continue;       // an unavailable figure renders as a state, not a number
            }

            $this->assertStringContainsString($this->plain($expected[$tile]['value']), $body,
                "Adding the period control changed the unfiltered {$tile} figure, so every "
                . 'existing reader now sees a different number without having asked for one.');
        }

        $this->assertStringContainsString($this->plain($expected['payments']['period']), $body,
            'The unfiltered period caption no longer matches the assembler.');
    }

    /**
     * ASSERTED POSITIVELY AGAINST THE REQUESTED LABEL. The first version only checked that the
     * UNFILTERED coverage caption was absent, which a mutation passing the window but dropping
     * the label survived: the assembler then falls back to a coverage-derived caption that is not
     * the one the merchant asked for and is not the one the test was looking for either.
     */
    public function test_the_caption_follows_the_requested_window(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '100.00');

        $requested = AnalyticsPeriod::fromRequest(self::FEBRUARY);

        $response = $this->open($merchant, self::FEBRUARY);
        $response->assertOk();

        $figures = $response->viewData('ledgerFigures');

        $this->assertSame($this->plain($requested->label()), $this->plain($figures['payments']['period']),
            'The caption does not name the period that was requested, so the number is windowed '
            . 'and its label is not.');

        $this->assertSame($this->plain($requested->label()), $this->plain($figures['profit']['period']),
            'The profit tile carries a different caption from the payments tile beside it, for '
            . 'the same requested period.');
    }

    /**
     * THE CLAMP IS THE ASSEMBLER'S, AND THE SCREEN MUST SURFACE WHAT IT SAYS. Asking for a period
     * that ends before the records begin is refused IN WORDS by `FinanceLedgerFigures::narrow()`,
     * with the date from which it could answer. A control that swallowed that and printed a
     * confident zero would be worse than no control at all.
     */
    public function test_a_period_before_the_records_begin_is_refused_in_words(): void
    {
        [$merchant, $store] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '100.00');

        /*
         * THE LEDGER MUST BE INCOMPLETE FOR THIS PATH TO EXIST, which the premise assertion below
         * is what taught me. With every order carrying a financial row the coverage window is
         * `allTime()`, whose `from()` is null -- unbounded, so `narrow()` clamps nothing and no
         * period can be refused. The refusal only exists for a store whose verified record STARTS
         * somewhere, so one dated order is inserted with NO financial row. Inserted raw, because
         * creating an Order through the model writes the row this fixture must not have.
         */
        DB::table('orders')->insert([
            'product_order_id' => 'FIN-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
            'final_price' => 10.00,
            'order_date' => '2025-12-31 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->open($merchant, [
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2019-01-01',
            AnalyticsPeriod::KEY_TO => '2019-01-31',
        ]);

        $response->assertOk();

        $requested = AnalyticsPeriod::fromRequest([
            AnalyticsPeriod::KEY_PERIOD => AnalyticsPeriod::CUSTOM,
            AnalyticsPeriod::KEY_FROM => '2019-01-01',
            AnalyticsPeriod::KEY_TO => '2019-01-31',
        ]);

        $expected = FinanceLedgerFigures::forStore(
            (int) $store->id,
            'USD',
            $requested->window(),
            $requested->label(),
        );

        /*
         * THE REFUSAL IS CARRIED IN `hint`, NOT A FIELD OF ITS OWN. `FinanceLedgerFigures::tile()`
         * puts it there when the result is null, deliberately ahead of the record-health note: the
         * record may be perfectly healthy and simply not cover the window the merchant picked.
         * Read from the assembler rather than restated, so this test cannot drift from the wording.
         */
        $this->assertNull($expected['payments']['value'],
            'PREMISE: the assembler was expected to refuse a period that ends before the ledger '
            . 'begins and instead returned a figure. Read FinanceLedgerFigures::narrow() before '
            . 'changing this test -- the fixture, not the screen, is what moved.');

        $refusal = (string) $expected['payments']['hint'];

        $body = $this->plain(html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5));

        /*
         * COUNTED, NOT MERELY FOUND. Both flow tiles are built by the same `tile()` call with the
         * same refusal, so a mutation dropping the hint from ONE of them left the other printing
         * it and `assertStringContainsString` was satisfied by the wrong element -- the trap
         * CLAUDE.md records, met here across two sibling tiles rather than two syntaxes.
         */
        $this->assertGreaterThanOrEqual(2, substr_count($body, $this->plain($refusal)),
            'The assembler refused the requested period in words and at least one of the two '
            . 'figures did not print the refusal, so the merchant sees an empty number with no '
            . 'reason for it.');
    }

    public function test_the_screen_offers_the_period_control(): void
    {
        [$merchant] = $this->merchantWhoMayRead();

        $response = $this->open($merchant);
        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('name="ss_period"', $body,
            'The report accepts a period and the page offers no control to choose one.');

        $this->assertStringContainsString('name="ss_from"', $body);
        $this->assertStringContainsString('name="ss_to"', $body);

        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*>\s*' . preg_quote(__('All time'), '/') . '\s*<\/option>/u',
            $body,
            'The control does not offer the unfiltered view as an option.'
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

        $this->payment($store, '2026-02-10 10:00:00', '100.00');
        $this->payment($store, '2021-01-01 10:00:00', '250.00');

        $expected = FinanceLedgerFigures::forStore((int) $store->id, 'USD');

        $response = $this->open($merchant, [
            AnalyticsPeriod::KEY_PERIOD => '',
            AnalyticsPeriod::KEY_FROM => '',
            AnalyticsPeriod::KEY_TO => '',
        ]);
        $response->assertOk();

        $shown = $response->viewData('ledgerFigures');

        $this->assertSame($this->plain($expected['payments']['value']), $this->plain($shown['payments']['value']),
            'Submitting the control with All time selected narrowed the figure, so the default '
            . 'option does not return the view the merchant had before.');

        $this->assertSame($this->plain($expected['payments']['period']), $this->plain($shown['payments']['period']));
    }

    /**
     * STILL ONE STORE'S MONEY. The window is a new predicate inside the assembler's scope, not a
     * replacement for it.
     */
    public function test_the_window_does_not_replace_the_store_predicate(): void
    {
        [$merchant, $store, $decoy] = $this->merchantWhoMayRead();

        $this->payment($store, '2026-02-10 10:00:00', '100.00');
        $this->payment($decoy, '2026-02-11 10:00:00', '77777.77');

        $body = $this->plain(html_entity_decode(
            $this->open($merchant, self::FEBRUARY)->getContent(), ENT_QUOTES | ENT_HTML5));

        $this->assertStringNotContainsString('77,777.77', $body,
            "Another tenant's payment is on this merchant's finance report inside the window.");
        $this->assertStringNotContainsString('77777.77', $body);
    }
}

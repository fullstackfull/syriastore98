<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use Tests\TestCase;

/**
 * FIN-SYM (second half) — A RECORDED VALUE THAT IS NOT A CURRENCY CODE IS NOT A CURRENCY.
 * =============================================================================================
 *
 * `MetricResult::make()` keyed its money buckets by the raw `currency_code`, so any non-empty
 * string became a currency of its own. Two measured consequences, neither hypothetical:
 *
 * P5-R14 MEASURED that `Api\ApiController::place_order()` and `::ordersave()` stamped
 * `orders.currency_code` from the store's SYMBOL setting, and owner decision D-N34 leaves the rows
 * already written in place -- `tests/Support/Security/bin/report-order-currency-codes.php` exists to
 * count them on production. So a store whose orders came through both the web writer and the
 * storefront API holds 'SYP' on some rows and 'ل.س' on others, and the metric layer read that as
 * TWO CURRENCIES. And case alone did the same: 'usd' and 'USD' are one currency and were two
 * buckets.
 *
 * WHAT THAT COSTS IS NOT AN EXCEPTION. `total()` does throw on more than one bucket, but every
 * shipped caller guards on the bucket count first -- `MerchantAnalyticsFigures`,
 * `FinanceLedgerFigures`, `WalletEntitlementFigure` and `MetricThreshold` all check before reading
 * it. So the figure is not a 500; it is SUPPRESSED OR SPLIT:
 *
 *   - a single-currency store's tile refuses to show one number, because the layer believes there
 *     are two;
 *   - the wallet surface prints "balances in more than one currency ... 100.00 SYP, 50.00 ل.س",
 *     offering a SYMBOL to the merchant as a currency beside its own code;
 *   - `MetricThreshold` SKIPS the comparison (`continue`), so a real floor breach on a
 *     single-currency store goes unreported.
 *
 * THE FIX INVENTS NO CONVERSION, and that is the constraint it is built around. A value that is
 * not a code means the writer recorded no currency, which is the state this class ALREADY has a
 * name and an approved treatment for: `UNKNOWN_CURRENCY`, folded into the store's own currency by
 * `assumingUnknownCurrencyIs()` only when a caller opts in, carrying
 * `MetricCaveat::CURRENCY_UNRECORDED` and the exact amount assumed. 'ل.س' rows are not converted
 * into SYP at a rate; they are declared uncurrencied and then, at the caller's choice, counted in
 * the store's own currency with the assumption stated on the screen. Two values that ARE codes and
 * normalise alike are summed, because they are one currency.
 */
class MetricResultCurrencyIdentityTest extends TestCase
{
    private function scope(): MetricScope
    {
        return MetricScope::store(7);
    }

    private function make(array $byCurrency, array $caveats = []): MetricResult
    {
        return MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, 'orders.order_date',
            $byCurrency, 3, $this->scope(), MetricWindow::allTime(), $caveats
        );
    }

    public function test_a_symbol_recorded_where_a_code_belongs_is_not_a_second_currency(): void
    {
        $result = $this->make(['SYP' => 100.0, 'ل.س' => 50.0]);

        $this->assertSame(
            ['SYP' => 100.0, MetricResult::UNKNOWN_CURRENCY => 50.0],
            $result->byCurrency(),
            'A symbol stamped into currency_code by the storefront-API writers is still read as a '
            . 'currency of its own, so a one-currency store looks like a two-currency store.'
        );
    }

    public function test_the_symbol_rows_fold_into_the_stores_currency_and_say_how_much(): void
    {
        $result = $this->make(['SYP' => 100.0, 'ل.س' => 50.0])->assumingUnknownCurrencyIs('SYP');

        $this->assertSame(['SYP' => 150.0], $result->byCurrency());
        $this->assertSame(150.0, $result->total(),
            'The tile still cannot show one number for a store that trades in exactly one currency.');
        $this->assertTrue($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED),
            'The figure folded an assumption in and does not say so.');
        $this->assertFalse($result->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertSame(50.0, $result->notes()['currency_assumed_amount'],
            'The merchant cannot see HOW MUCH of the figure rests on the assumption.');
    }

    public function test_nothing_is_converted_at_a_rate(): void
    {
        $result = $this->make(['ل.س' => 50.0])->assumingUnknownCurrencyIs('USD');

        $this->assertSame(50.0, $result->total(),
            'The amount changed, which means a rate was applied. No rate exists in this schema: '
            . '`currency_rates` holds current rates and nothing stamps one onto an order.');
        $this->assertSame('USD', $result->currency());
    }

    public function test_two_spellings_of_one_code_are_one_currency(): void
    {
        $result = $this->make(['usd' => 10.0, 'USD' => 5.0, ' USD ' => 1.0]);

        $this->assertSame(['USD' => 16.0], $result->byCurrency(),
            'Case and surrounding space split one currency into separate buckets, each with a '
            . 'partial subtotal.');
        $this->assertFalse($result->hasCaveat(MetricCaveat::MIXED_CURRENCY),
            'One currency spelled three ways is reported as a mixed-currency figure.');
        $this->assertSame(16.0, $result->total());
    }

    public function test_two_real_currencies_are_still_two_and_are_never_added(): void
    {
        $result = $this->make(['USD' => 10.0, 'SYP' => 50000.0]);

        $this->assertSame(['USD' => 10.0, 'SYP' => 50000.0], $result->byCurrency(),
            'Normalising currency identity must not collapse two GENUINELY different currencies.');
        $this->assertTrue($result->hasCaveat(MetricCaveat::MIXED_CURRENCY));
    }

    public function test_a_named_currency_is_found_whichever_way_the_caller_spells_it(): void
    {
        $result = $this->make(['usd' => 10.0]);

        $this->assertSame(10.0, $result->totalIn('USD'));
        $this->assertSame(10.0, $result->totalIn('usd'),
            'totalIn() compares the caller\'s spelling with the bucket key, so a caller who asks '
            . 'in lower case is told the store has nothing in that currency.');
        $this->assertSame(0.0, $result->totalIn('SYP'));
    }

    public function test_the_unknown_bucket_is_still_reachable_by_name(): void
    {
        $result = $this->make(['ل.س' => 50.0, 'USD' => 1.0]);

        $this->assertSame(50.0, $result->totalIn(MetricResult::UNKNOWN_CURRENCY),
            'Normalising the argument must not hide the one bucket key that is deliberately not a '
            . 'currency code.');
        $this->assertSame(50.0, $result->unrecordedCurrencyAmount());
    }

    /**
     * THE TARGET CURRENCY IS MERCHANT-WRITTEN TOO. `MerchantAnalyticsController` passes the raw
     * `defult_currancy` setting, and FIN-CUR-2 fixed the WRITER while owner decision D-N34 leaves
     * existing rows alone -- so the value handed in can itself be a symbol. Assuming the
     * unrecorded rows are "in ل.س" would name a bucket with a symbol and print it to the merchant
     * as a currency, which is the defect arriving by the other door.
     *
     * NOTHING IS ASSUMED in that case, and the result says so by NOT carrying the caveat: the
     * caller does not know the currency either, and `CURRENCY_UNRECORDED` would claim an
     * assumption was made. It does not throw, because every caller of this method is rendering a
     * merchant tile.
     */
    public function test_a_symbol_offered_as_the_target_currency_assumes_nothing(): void
    {
        $result = $this->make(['SYP' => 100.0, '' => 50.0])->assumingUnknownCurrencyIs('ل.س');

        $this->assertSame(
            ['SYP' => 100.0, MetricResult::UNKNOWN_CURRENCY => 50.0],
            $result->byCurrency(),
            'The unrecorded rows were folded into a bucket named with a SYMBOL, which the wallet '
            . 'and tile surfaces print to the merchant as a currency.'
        );
        $this->assertFalse($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED),
            'The result claims an assumption was made when the caller named no currency.');
        $this->assertArrayNotHasKey('currency_assumed_into', $result->notes());
    }

    /**
     * THE TARGET IS CANONICALISED BEFORE IT IS RECORDED, so the note a surface prints names the
     * currency the way the bucket does.
     */
    public function test_the_assumed_currency_is_recorded_canonically(): void
    {
        $result = $this->make(['SYP' => 100.0, '' => 50.0])->assumingUnknownCurrencyIs('syp');

        $this->assertSame(['SYP' => 150.0], $result->byCurrency());
        $this->assertSame('SYP', $result->notes()['currency_assumed_into'],
            'The note carries the caller\'s spelling rather than the currency, so a surface that '
            . 'prints it disagrees with the bucket it describes.');
    }

    /**
     * AND THE BUCKET THAT IS NOT A CURRENCY IS NOT PRINTED AS ONE.
     *
     * `MoneyText::buckets()` upper-cased every key, so `unknown` became `UNKNOWN` -- after which
     * the one site that labels that bucket no longer matched it and the wallet listing printed the
     * bare token to the merchant beside a balance. Reachable exactly when the fold is declined,
     * which is when the store's own setting is not a code either.
     */
    public function test_the_unrecorded_bucket_keeps_its_key_through_the_bucket_helper(): void
    {
        $buckets = \App\Support\Metrics\MoneyText::buckets(
            ['usd' => 10.0, MetricResult::UNKNOWN_CURRENCY => 2.0],
            false
        );

        // `ksort` puts it last, because lower-case 'u' sorts after 'U'. That is the order the
        // listing surfaces print, and the unrecorded bucket belongs after the real currencies.
        $this->assertSame(['USD' => 10.0, MetricResult::UNKNOWN_CURRENCY => 2.0], $buckets,
            'The unrecorded bucket was upper-cased into a token that looks like a currency code.');
    }

    public function test_an_amount_in_no_recorded_currency_is_labelled_rather_than_coded(): void
    {
        $plain = static fn (string $t): string => str_replace(["\u{2066}", "\u{2069}"], '', $t);

        $this->assertSame('2.00 Currency not recorded',
            $plain(\App\Support\Metrics\MoneyText::amount(2.0, MetricResult::UNKNOWN_CURRENCY)),
            'A merchant is shown the internal bucket token where a currency belongs.');

        $this->assertSame('10.00 USD', $plain(\App\Support\Metrics\MoneyText::amount(10.0, 'USD')),
            'A real currency code must still print as itself.');
    }

    public function test_a_null_or_empty_recorded_currency_is_unchanged_by_any_of_this(): void
    {
        $result = $this->make(['USD' => 10.0, '' => 2.0]);

        $this->assertSame(['USD' => 10.0, MetricResult::UNKNOWN_CURRENCY => 2.0], $result->byCurrency(),
            'The pre-existing null/empty treatment moved, which is a regression in the case this '
            . 'class was built for (2026_10_12_000102 deliberately did not backfill).');
    }
}

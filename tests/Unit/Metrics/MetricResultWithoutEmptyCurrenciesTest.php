<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use Tests\TestCase;

/**
 * GAP-152 — GAP-222's ZERO TRAP, DECIDED IN THE METRIC LAYER INSTEAD OF IN A VIEW.
 * =================================================================================================
 *
 * A currency whose total rounds to 0.00 — one free order placed after a store changed currency — is
 * a rounding artefact, not a currency the merchant was paid in. `MoneyText::buckets($x, true)` has
 * dropped it from the ledger tiles since GAP-222, and
 * `FinanceLedgerFiguresTest::test_a_currency_with_nothing_in_it_does_not_make_the_figure_mixed`
 * pins that a correct single-currency total is not withheld for it.
 *
 * WHAT WAS WRONG WAS WHERE IT HAPPENED. The view dropped the bucket; the `MetricResult` went on
 * carrying `MIXED_CURRENCY`. So the figure said one currency and its caveat list said more than
 * one, and the hand-composed hint settled the disagreement by printing neither. GAP-152's carrier
 * states every caveat a figure carries, so that disagreement had to be resolved rather than
 * absorbed: the narrowing is now one documented rule here, with the dropped codes in `notes()`.
 */
final class MetricResultWithoutEmptyCurrenciesTest extends TestCase
{
    private function figure(array $byCurrency, array $caveats = []): MetricResult
    {
        return MetricResult::make(
            'collected_customer_payments',
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.paid_at',
            $byCurrency,
            4,
            MetricScope::store(3),
            MetricWindow::allTime(),
            $caveats,
        );
    }

    public function test_the_premise_that_a_zero_bucket_makes_a_figure_mixed(): void
    {
        // Without this the test below could pass against a result that was never mixed at all.
        $mixed = $this->figure(['USD' => 100.0, 'SYP' => 0.0]);

        $this->assertTrue($mixed->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertSame(['USD', 'SYP'], $mixed->currencies());
    }

    public function test_a_currency_that_rounds_to_zero_is_dropped_and_the_figure_is_no_longer_mixed(): void
    {
        $narrowed = $this->figure(['USD' => 100.0, 'SYP' => 0.0])->withoutEmptyCurrencies();

        $this->assertSame(['USD'], $narrowed->currencies());
        $this->assertFalse($narrowed->hasCaveat(MetricCaveat::MIXED_CURRENCY),
            'one real currency and one rounding artefact is not a mixed-currency figure');
        $this->assertSame(100.0, $narrowed->total());
        $this->assertSame(['SYP'], $narrowed->notes()['empty_currencies_dropped'],
            'the decision must be visible in the notes, not silent');
    }

    public function test_two_real_currencies_are_untouched_and_stay_mixed(): void
    {
        $narrowed = $this->figure(['USD' => 100.0, 'SYP' => 900.0])->withoutEmptyCurrencies();

        $this->assertSame(['USD', 'SYP'], $narrowed->currencies());
        $this->assertTrue($narrowed->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertArrayNotHasKey('empty_currencies_dropped', $narrowed->notes());
    }

    public function test_a_sub_cent_amount_is_an_artefact_and_a_cent_is_not(): void
    {
        $artefact = $this->figure(['USD' => 100.0, 'SYP' => 0.004])->withoutEmptyCurrencies();
        $real = $this->figure(['USD' => 100.0, 'SYP' => 0.01])->withoutEmptyCurrencies();

        $this->assertSame(['USD'], $artefact->currencies());
        $this->assertSame(['USD', 'SYP'], $real->currencies(), 'one cent is money and must not be dropped');
        $this->assertTrue($real->hasCaveat(MetricCaveat::MIXED_CURRENCY));
    }

    public function test_the_same_object_comes_back_when_nothing_was_dropped(): void
    {
        $result = $this->figure(['USD' => 100.0]);

        $this->assertSame($result, $result->withoutEmptyCurrencies(),
            'nothing was dropped, so nothing is claimed and no copy is made');
    }

    public function test_a_figure_that_is_entirely_zero_keeps_its_zero_and_loses_no_other_caveat(): void
    {
        // A store with one paid order of 0.00: the tile has always shown "0.00 USD" rather than
        // "not available", and a caveat unrelated to currency must survive the narrowing.
        $narrowed = $this->figure(['USD' => 0.0], [MetricCaveat::EXCLUDES_PAYMENT_FEES])->withoutEmptyCurrencies();

        $this->assertSame([], $narrowed->currencies());
        $this->assertSame(0.0, $narrowed->total());
        $this->assertTrue($narrowed->hasCaveat(MetricCaveat::EXCLUDES_PAYMENT_FEES));
    }

    public function test_the_unrecorded_currency_bucket_is_dropped_only_when_it_is_empty_too(): void
    {
        $empty = $this->figure(['USD' => 100.0, MetricResult::UNKNOWN_CURRENCY => 0.0])->withoutEmptyCurrencies();
        $real = $this->figure(['USD' => 100.0, MetricResult::UNKNOWN_CURRENCY => 25.0])->withoutEmptyCurrencies();

        $this->assertSame(['USD'], $empty->currencies());
        $this->assertSame(['USD', MetricResult::UNKNOWN_CURRENCY], $real->currencies(),
            'money that recorded no currency is still money and is not an artefact');
    }
}

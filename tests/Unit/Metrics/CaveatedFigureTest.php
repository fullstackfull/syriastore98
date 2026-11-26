<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GAP-152 / P05-04 — THE CARRIER. A figure and the caveats that qualify it are ONE object.
 * =================================================================================================
 *
 * The gap register's next action is explicit about the shape: "Design the caveat carrier as part of
 * the metric object so a screen cannot render a figure without it, rather than adding caveats
 * per-view." This is the test of that design, and of the one property that matters: **the display
 * text is DERIVED from the caveat list, so no caller can produce a figure whose words omit a caveat
 * the metric attached.**
 *
 * WHAT THE PER-VIEW MECHANISM ACTUALLY COST, measured on 2026-11-25 before this class existed.
 * `StoreRevenueMargin::for()` attaches `EXCLUDES_PAYMENT_FEES` unconditionally — ANA-006, because
 * this product records no gateway fee anywhere, so every card-taking store's margin is overstated
 * by the gateway's cut. That figure is the `Estimated gross profit` tile on THREE screens
 * (`/finance`, `/analytics`, `reports/finance_report`) and in TWO export paths (the `/analytics`
 * download and the scheduled-report mail). The sentence was translated into both languages and
 * ratcheted by `MetricCaveatMessagesAreTranslatedTest`, and it reached **none** of those five
 * surfaces: grepping `resources/views/` and `app/` for it found one hit, a docblock. The hint on
 * each tile was composed by hand from prose, so the caveat was attached in one file and forgotten
 * in another — which is the whole of GAP-152 in one figure.
 *
 * THE SECOND HALF IS WHY A CARRIER THAT ONLY APPENDS GENERIC SENTENCES WOULD BE WRONG HERE, and it
 * is not hypothetical either. Two caveats on that same tile have a MEASURED reason not to be
 * printed in their own words:
 *
 *   * `EXCLUDES_SHIPPING_AND_TAX` reads "Margin excludes shipping and tax", which is false for an
 *     inclusive-tax store — `FinanceLedgerFiguresTest:416` asserts that sentence stays OFF the
 *     tile, and `StoreRevenueMargin`'s own docblock records it as GAP-213(d).
 *   * `COST_UNKNOWN` reads "...for some ORDERS ... those ROWS", the right machine key and the wrong
 *     noun for a surface counting products — `PHASE-05-METRIC-DEFINITIONS.md` §16 says so in
 *     terms, and warns that "a future consumer that prints `caveatMessages()` raw" would show it.
 *
 * So a caveat is answered EITHER by its own sentence OR by a sentence the surface declares for that
 * key, and there is no third option: `statedAs` cannot be empty, and the default is the generic
 * message. The failure mode of forgetting is therefore "the merchant reads a slightly generic
 * sentence", never "the merchant reads nothing".
 */
final class CaveatedFigureTest extends TestCase
{
    private function margin(array $caveats = [], array $notes = []): MetricResult
    {
        return MetricResult::make(
            'store_revenue_margin',
            MetricResult::RECORD_ENTITLEMENT,
            'store_order_financials.paid_at',
            ['USD' => 120.0],
            3,
            MetricScope::store(7),
            MetricWindow::allTime(),
            $caveats,
            $notes,
        );
    }

    public function test_the_premise_that_the_caveats_under_test_are_real_and_translated(): void
    {
        // Without this the assertions below could pass against a key this vocabulary does not
        // have, or against a sentence no merchant is ever shown in their own language.
        foreach ([MetricCaveat::EXCLUDES_PAYMENT_FEES, MetricCaveat::EXCLUDES_SHIPPING_AND_TAX, MetricCaveat::COST_UNKNOWN] as $caveat) {
            $this->assertTrue(MetricCaveat::exists($caveat), $caveat . ' is not in the vocabulary.');
            $this->assertNotSame('', trim(MetricCaveat::message($caveat)));
        }
    }

    public function test_the_hint_states_every_caveat_the_metric_attached(): void
    {
        $figure = CaveatedFigure::from(
            $this->margin([MetricCaveat::EXCLUDES_PAYMENT_FEES, MetricCaveat::PARTIAL_REFUNDS_EXCLUDED]),
            '120.00 USD',
            'from 2 November 2026',
            ['Sales value minus product cost.'],
        );

        $hint = $figure->hint();

        $this->assertStringContainsString('Sales value minus product cost.', $hint);

        foreach ([MetricCaveat::EXCLUDES_PAYMENT_FEES, MetricCaveat::PARTIAL_REFUNDS_EXCLUDED] as $caveat) {
            $this->assertStringContainsString(MetricCaveat::message($caveat), $hint,
                $caveat . ' is attached to the figure and absent from the words a merchant reads.');
        }
    }

    public function test_the_caveats_are_read_off_the_result_and_the_caller_cannot_narrow_them(): void
    {
        // `from()` has no caveat parameter at all: the only source is the metric's own list. A
        // surface that disagrees with a caveat must STATE it, which is visible, not drop it.
        $figure = CaveatedFigure::from($this->margin([MetricCaveat::COST_UNKNOWN]), '1.00 USD', 'all time');

        $this->assertSame([MetricCaveat::COST_UNKNOWN], $figure->caveats());
        $this->assertTrue($figure->hasCaveat(MetricCaveat::COST_UNKNOWN));
    }

    public function test_a_surface_may_answer_a_caveat_in_its_own_words_instead_of_the_generic_one(): void
    {
        $figure = CaveatedFigure::from(
            $this->margin([MetricCaveat::COST_UNKNOWN]),
            '1.00 USD',
            'all time',
            [],
            [MetricCaveat::COST_UNKNOWN => 'At least 2 paid orders have no product cost on record.'],
        );

        $this->assertStringContainsString('At least 2 paid orders have no product cost on record.', $figure->hint());
        $this->assertStringNotContainsString(MetricCaveat::message(MetricCaveat::COST_UNKNOWN), $figure->hint(),
            'the surface answered this caveat, so the generic wording must not be repeated beside it');

        // The caveat is still CARRIED. A surface answering it in better words does not make the
        // figure unqualified, and an export or a test must still be able to see that it applies.
        $this->assertSame([MetricCaveat::COST_UNKNOWN], $figure->caveats());
    }

    public function test_a_surface_cannot_answer_a_caveat_with_silence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . MetricCaveat::COST_UNKNOWN . '/');

        CaveatedFigure::from(
            $this->margin([MetricCaveat::COST_UNKNOWN]),
            '1.00 USD',
            'all time',
            [],
            [MetricCaveat::COST_UNKNOWN => '   '],
        );
    }

    public function test_a_surface_cannot_state_a_caveat_the_figure_does_not_carry(): void
    {
        // Dead text: a sentence written for a condition this figure is not in. It is also how a
        // surface would drift away from the metric after a caveat is removed.
        $this->expectException(InvalidArgumentException::class);

        CaveatedFigure::from(
            $this->margin([MetricCaveat::COST_UNKNOWN]),
            '1.00 USD',
            'all time',
            [],
            [MetricCaveat::MIXED_CURRENCY => 'Two currencies, not combined.'],
        );
    }

    public function test_an_unknown_caveat_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CaveatedFigure::of('1.00 USD', 'all time', ['invented_caveat']);
    }

    public function test_one_sentence_answering_two_caveats_is_said_once(): void
    {
        $sentence = 'Shipping and tax are outside this figure, and no gateway fee is recorded.';

        $figure = CaveatedFigure::from(
            $this->margin([MetricCaveat::EXCLUDES_SHIPPING_AND_TAX, MetricCaveat::EXCLUDES_PAYMENT_FEES]),
            '1.00 USD',
            'all time',
            [],
            [
                MetricCaveat::EXCLUDES_SHIPPING_AND_TAX => $sentence,
                MetricCaveat::EXCLUDES_PAYMENT_FEES => $sentence,
            ],
        );

        $this->assertSame(1, substr_count($figure->hint(), $sentence),
            'the same sentence answering two caveats must read once, not twice');
    }

    public function test_a_sentence_already_in_the_meaning_is_not_repeated_by_the_discharge(): void
    {
        /*
         * `FinanceLedgerFigures` answers EXCLUDES_SHIPPING_AND_TAX with the tile's own definition
         * sentence, which is ONE existing translation key covering both the definition and the
         * exclusion. Declaring it as the answer records WHERE the caveat is discharged; the hint
         * must still read once.
         */
        $meaning = 'Sales value minus product cost. Shipping is not included.';

        $figure = CaveatedFigure::from(
            $this->margin([MetricCaveat::EXCLUDES_SHIPPING_AND_TAX]),
            '1.00 USD',
            'all time',
            [$meaning],
            [MetricCaveat::EXCLUDES_SHIPPING_AND_TAX => $meaning],
        );

        $this->assertSame(1, substr_count($figure->hint(), $meaning));
    }

    public function test_the_hint_is_arabic_in_an_arabic_session(): void
    {
        $english = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        $this->app->setLocale('ar');

        $figure = CaveatedFigure::from($this->margin([MetricCaveat::EXCLUDES_PAYMENT_FEES]), '1.00 USD', 'all time');
        $arabic = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        // Asserted through the TRANSLATOR, not against a lang file: a key present in en.json and
        // missing from ar.json falls back to English, and only resolving it can see that.
        $this->assertNotSame($english, $arabic, 'the fee caveat has no Arabic, so this test cannot prove anything');
        $this->assertStringContainsString($arabic, $figure->hint());
        $this->assertStringNotContainsString($english, $figure->hint());
    }

    public function test_the_wire_form_carries_the_machine_keys_beside_the_value(): void
    {
        $figure = CaveatedFigure::from($this->margin([MetricCaveat::EXCLUDES_PAYMENT_FEES]), '120.00 USD', 'all time');

        $wire = $figure->toArray();

        $this->assertSame('120.00 USD', $wire['value']);
        $this->assertSame('all time', $wire['period']);
        $this->assertSame([MetricCaveat::EXCLUDES_PAYMENT_FEES], $wire['caveats']);
        $this->assertSame($figure->hint(), $wire['hint']);
        $this->assertTrue($wire['authoritative']);
    }

    public function test_the_legacy_array_reads_still_work_so_no_surface_has_to_change_at_once(): void
    {
        // Three Blades and `FigureCsv` read `$figure['value'|'period'|'hint']`. The carrier answers
        // those reads itself, so the object can travel where the array used to without a rewrite.
        $figure = CaveatedFigure::from($this->margin([MetricCaveat::EXCLUDES_PAYMENT_FEES]), '120.00 USD', 'all time');

        $this->assertSame('120.00 USD', $figure['value']);
        $this->assertSame('all time', $figure['period']);
        $this->assertSame($figure->hint(), $figure['hint']);
        $this->assertTrue(isset($figure['value']));
        $this->assertFalse(isset($figure['nonsense']));
    }

    public function test_a_blocking_caveat_makes_the_figure_unauthoritative(): void
    {
        $figure = CaveatedFigure::from(
            $this->margin([MetricCaveat::COUPON_REDEMPTION_UNRELIABLE]),
            null,
            'all time',
        );

        $this->assertFalse($figure->isAuthoritative());
        $this->assertFalse($figure->toArray()['authoritative']);
    }

    public function test_a_disclosure_for_several_figures_carries_the_whole_set_s_caveats(): void
    {
        /*
         * `StoreInventoryValuation` attaches ONE caveat list to SEVEN figures and names in
         * `notes()` which figures each applies to. The set gets one carrier, because the
         * alternative -- a per-tile subset -- is the view choosing which caveats to show.
         */
        $set = CaveatedFigure::forFigures('right now', [MetricCaveat::MONEY_FLOAT_PRECISION, MetricCaveat::COST_UNKNOWN], [], [
            MetricCaveat::COST_UNKNOWN => 'Cost is not recorded for 1 of 2 in-stock products.',
        ]);

        $this->assertFalse($set->isSingleFigure(), 'a set has no one value, and a tile must be able to refuse it');
        $this->assertNull($set->value());
        $this->assertStringContainsString(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION), $set->hint());
        $this->assertStringContainsString('Cost is not recorded for 1 of 2 in-stock products.', $set->hint());
        $this->assertStringNotContainsString(MetricCaveat::message(MetricCaveat::COST_UNKNOWN), $set->hint(),
            'the vocabulary wording is written for orders and rows; this surface counts products');
    }

    public function test_an_ordinary_figure_is_a_single_figure(): void
    {
        $this->assertTrue(CaveatedFigure::from($this->margin(), '1.00 USD', 'all time')->isSingleFigure());
        $this->assertTrue(CaveatedFigure::of('1.00 USD', 'all time')->isSingleFigure());
    }

    public function test_a_figure_with_no_value_still_carries_its_caveats(): void
    {
        // `value === null` is "we cannot say", which is exactly when the reason matters most.
        $figure = CaveatedFigure::of(null, 'all time', [MetricCaveat::MIXED_CURRENCY]);

        $this->assertNull($figure->value());
        $this->assertStringContainsString(MetricCaveat::message(MetricCaveat::MIXED_CURRENCY), $figure->hint());
    }
}

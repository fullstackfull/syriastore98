<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;

/**
 * P5-3e -- THE SALES-SUMMARY LABELS MUST BE TRUE OF THE ARITHMETIC BESIDE THEM.
 *
 * Owner recommendation 3 (2026-11-17): "Correct misleading 'before discounts' wording using
 * verified arithmetic, without silently changing monetary calculations." This test holds both
 * halves of that instruction at once, which is why it is one test class and not two.
 *
 * THE ARITHMETIC, VERIFIED BY READING EVERY WRITER rather than assumed:
 *
 *   `Cart::cart_list_cookie()`  -> $final_total = ($cart_final_price - $coupon_price)
 *                                                + $shipping_price
 *                                                + ($is_inclusive_tax ? 0 : $tax_price)
 *                                  and $cart_array['total_sub_price'] = $final_total.
 *
 *   `PaymentService`            -> $order->final_price = $total_sub_price [+ $delivery_price
 *                                                        when $plan->shipping_method == 'on'].
 *
 *   Cart's own `$shipping_price` is `(int) ($data['shipping_final_price'] ?? 0)`, and
 *   P01-SEC-14 H deliberately stops the caller's `shipping_final_price` reaching that read --
 *   so on the checkout path it is 0 and shipping enters ONCE, through `$delivery_price`.
 *
 * SO `orders.final_price` IS: merchandise after line discounts, MINUS the coupon, PLUS tax when
 * tax is exclusive, PLUS shipping when the plan charges it. The label "Order value, before
 * discounts" was false on two counts -- the coupon is already deducted, and shipping and tax are
 * included -- and its hint ("before any coupon was applied") contradicted the coupon tile's own
 * hint ("already deducted from order value") on the same card. The coupon tile was right.
 *
 * ONE REVIEW CLAIM REFUTED HERE, recorded so it is not propagated: an analysis pass held that
 * `final_price - delivery_price - tax_price` double-subtracts shipping and can go negative. It
 * cannot. `$delivery_price` is initialised to 0 and only computed when
 * `$plan->shipping_method == 'on'`, which is the same condition under which it was added -- so the
 * subtraction is exact in both branches and the net figure is merchandise-after-discount either
 * way.
 */
class SalesSummaryLabelTruthTest extends TestCase
{
    /**
     * Every file that can state a claim about a sales-summary figure.
     *
     * P05-15 ADDED THE LAST ENTRY, and it had to be added or this census would have started
     * passing for the wrong reason. The gross and net definitions used to be string literals
     * inside `_sales_stats.blade.php`, so grepping that view was the same as reading the
     * sentences. They now live in `ReportFigureNote` -- one source, read by the screens AND by the
     * five CSV exports -- so a view-only census would look at a file where the claim no longer is
     * and report no false claim because it found no claim at all.
     *
     * That is a weakening introduced by moving the sentence, caught by this test's own sibling
     * failing, and it is recorded here because the quiet version of it is a census that measures
     * nothing.
     */
    private const VIEWS = [
        'app/Support/Reports/ReportFigureNote.php',
        'resources/views/reports/_sales_stats.blade.php',
        'resources/views/reports/order_report.blade.php',
        'resources/views/reports/order_chart_data.blade.php',
        'resources/views/reports/order_bar_chart_data.blade.php',
        'resources/views/reports/bar_chart_order_report.blade.php',
    ];

    /**
     * Claims no figure on this card may make, with the reason each is false.
     *
     * Keyed by the phrase so a failure names the phrase rather than an index.
     */
    private const FALSE_CLAIMS = [
        'before discounts' => 'the coupon is subtracted inside Cart::cart_list_cookie() before '
            . 'the value ever reaches orders.final_price',
        'before any coupon' => 'same -- and the coupon tile on the same card says the opposite',
        'before any discount' => 'same',
        'excluding shipping' => 'shipping is INCLUDED in orders.final_price when the plan charges '
            . 'it, so a gross figure may not claim to exclude it',
        'excluding tax' => 'tax is INCLUDED in orders.final_price when tax is exclusive',
    ];

    private function source(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, $relative . ' is gone; this test has lost its subject.');

        return (string) file_get_contents($path);
    }

    public function test_no_sales_summary_view_claims_a_figure_is_before_discounts(): void
    {
        foreach (self::VIEWS as $relative) {
            $source = $this->source($relative);

            foreach (self::FALSE_CLAIMS as $phrase => $why) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $phrase,
                    $source,
                    sprintf(
                        '%s claims "%s" of a figure drawn from orders.final_price. That is false: %s.',
                        $relative,
                        $phrase,
                        $why
                    )
                );
            }
        }
    }

    public function test_the_card_does_not_contradict_itself_about_the_coupon(): void
    {
        /*
         * The defect this pins is not a wrong number, it is two statements on one card that cannot
         * both be true. The gross tile said the coupon had NOT been applied; the coupon tile said
         * it HAD, and already deducted. A merchant reading both learns nothing and trusts neither.
         */
        $source = $this->source('resources/views/reports/_sales_stats.blade.php');

        /*
         * THE COUPON SENTENCE MOVED TOO, for the same reason the gross one did: GAP-217 appended
         * the precision caveat to this tile, and the composition now lives in `ReportFigureNote`
         * rather than as a literal in the Blade. So this follows the data path -- the tile calls
         * the accessor AND the accessor's output carries the statement -- which is stronger than
         * the grep it replaces, for the third time in this file.
         */
        $this->assertStringContainsString(
            'ReportFigureNote::couponValueAppliedHint()',
            $source,
            'The coupon tile no longer reads its hint from ReportFigureNote, so the screen and the '
            . 'report exports can disagree about what the discount figure means.'
        );

        $this->assertStringContainsString(
            'already deducted from order value',
            \App\Support\Reports\ReportFigureNote::couponValueAppliedHint(),
            'The coupon tile no longer states that the discount is already deducted. That '
            . 'statement is the TRUE one, verified against Cart::cart_list_cookie().'
        );

        /*
         * THE GROSS TILE'S SENTENCE IS NO LONGER A LITERAL IN THIS FILE, so asserting it by
         * grepping the view would be asserting nothing. P05-15 moved both definitions into
         * `ReportFigureNote` so that the five CSV exports state what the screen states.
         *
         * Following the data path is STRONGER than the grep it replaces: the grep proved a
         * substring sat somewhere in a 60-line Blade file, while this proves the tile calls the
         * accessor AND that what the accessor returns contains the statement. A rename of the
         * method, a wrong method, or a reworded sentence each fail here; only the first of those
         * would have failed the grep.
         */
        $this->assertStringContainsString(
            'ReportFigureNote::grossOrderValueHint()',
            $source,
            'The gross tile no longer reads its hint from ReportFigureNote, so the screen and the '
            . 'CSV exports can now disagree about what the figure includes.'
        );

        $this->assertStringContainsString(
            'after coupons',
            \App\Support\Reports\ReportFigureNote::grossOrderValueHint(),
            'The gross tile no longer states that its figure is after coupons, so the card has '
            . 'stopped agreeing with itself -- the coupon tile on the same card says the discount '
            . 'is already deducted.'
        );

        /*
         * And the net tile beside it, for the same reason: both money tiles on this card now take
         * their wording from the one source, and both carry the precision caveat they were missing.
         */
        $this->assertStringContainsString(
            'ReportFigureNote::orderValueHint()',
            $source,
            'The net Order value tile no longer reads its hint from ReportFigureNote.'
        );
    }

    public function test_the_money_expressions_are_unchanged_by_the_relabelling(): void
    {
        /*
         * THE SECOND HALF OF THE INSTRUCTION: "without silently changing monetary calculations."
         *
         * A label change and an arithmetic change look identical in a release note and completely
         * different to a merchant. Pinning the two accumulators here means a future edit cannot
         * quietly alter what the tiles COMPUTE while adjusting what they SAY -- it would have to
         * change this test, which is the point at which somebody has to explain themselves.
         *
         * These are the expressions as measured, not as intended.
         */
        $source = $this->source('app/Http/Controllers/ReportController.php');

        /*
         * COUNTED, NOT MERELY PRESENT, and the first draft of this test got that wrong.
         * `assertStringContainsString` passes while ANY single occurrence survives -- and each
         * expression appears EIGHT times, once per period branch. Somebody could change seven
         * branches and leave one, and the test would have stayed green while seven of the five
         * period buttons reported something different. Measured counts, asserted exactly.
         */
        $this->assertSame(
            8,
            substr_count($source, '$TotalgrossSale += (float) $order->final_price;'),
            'The gross accumulator appears a different number of times. It must remain '
            . 'SUM(orders.final_price) in every period branch -- the only metric for which that '
            . 'sum is legitimate (PHASE-05-METRIC-DEFINITIONS.md §1). A branch that differs from '
            . 'its siblings is how the year view came to double its own aggregate once already.'
        );

        $this->assertSame(
            8,
            substr_count(
                $source,
                '$TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);'
            ),
            'The net accumulator appears a different number of times. Relabelling must not move '
            . 'the arithmetic in any branch; if the figure itself should change, that is a '
            . 'separate decision with its own before/after per surface.'
        );
    }

    public function test_both_corrected_labels_are_translated_into_arabic(): void
    {
        // The merchant admin is bilingual, and an untranslated label is a regression a sighted
        // Arabic merchant sees immediately.
        $ar = json_decode($this->source('resources/lang/ar.json'), true);

        $this->assertIsArray($ar);

        foreach ([
            'Order value, including shipping and tax',
            'Average order value, including shipping and tax',
        ] as $key) {
            $this->assertArrayHasKey($key, $ar, 'No Arabic for the corrected label "' . $key . '".');
            $this->assertNotSame(
                $key,
                $ar[$key],
                'The Arabic entry for "' . $key . '" is the English string.'
            );
        }
    }
}

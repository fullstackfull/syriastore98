<?php

namespace Tests\Unit\Ui;

use App\DataTables\OrderDataTable;
use PHPUnit\Framework\TestCase;

/**
 * THE ORDER GRID CARD: A CLOSED SPAN, A MONEY FIGURE, AND THE DATE IT NEVER SHOWED.  P2.5-DESIGN-C
 * =================================================================================================
 *
 * THE CARD SHOWED NO DATE AT ALL, while BOTH of its siblings do. The LIST has a `Date` column
 * (`OrderDataTable::getColumns()`, rendered through `dateOrDash($item->order_date)`), and the board
 * card carries one. An order grid with no date cannot be scanned: every card read
 * `<customer> / Price : <amount> / #<ref>` and nothing else.
 *
 * THE `<span>` AROUND THE PRICE WAS NEVER CLOSED, and the previous slice designed around it rather
 * than fixing it. CONFIRMED IN THE RAW SERVER BYTES, not only in the source -- the HTTP response
 * for one card ended `$10.00` and then `</div>` with no `</span>` between them. Chromium recovers,
 * which is exactly why it survived: the DOM is not mis-nested and nothing looks wrong. What it cost
 * was real all the same -- `OrderGridStatusLabelTest::test_the_badge_is_not_inside_the_unclosed_
 * text_dark_span` exists because anything placed after that span is auto-closed INSIDE it and
 * inherits the dark-mode override on `.text-dark`. A fixed card can be built on; an auto-recovered
 * one has to be worked around.
 *
 * `dateOrDash()` IS REUSED RATHER THAN REIMPLEMENTED, and that is the point of the test below that
 * drives it directly. The obvious way to add a date to a Blade view is
 * `Utility::dateFormat($order->order_date)` -- and that reintroduces **F19**, the epoch printed for
 * a missing date, on a second screen. F19 was already found and fixed once, on the list. The helper
 * was made public for this; `hasDate()` stays private, because it is that method's internal test
 * and not a second public rule.
 *
 * WHAT THIS FILE DOES NOT CLAIM. It reads the view's SOURCE, like its three sibling ratchets for
 * this file, so it answers *"did somebody take this out again?"* and not *"what does the card look
 * like?"*. The rendered proof is in
 * `docs/design-system/evidence/design-c-2026-11-04/`, measured in all four combinations.
 */
class OrderGridCardContentTest extends TestCase
{
    private const GRID = 'resources/views/order/grid.blade.php';

    private function grid(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::GRID;
        $this->assertFileExists($path, 'premise: the grid view is where it is expected');

        return (string) file_get_contents($path);
    }

    /**
     * The region between `.user-content` opening and its `</div>`, which is where all three of the
     * card's text spans live. Sliced the same way the sibling ratchets slice this file, so a
     * restructure fails here with a premise error rather than measuring the wrong text.
     */
    private function userContent(): string
    {
        $grid = $this->grid();

        $start = strpos($grid, '<div class="user-content">');
        $this->assertNotFalse($start, 'premise: the card still has a .user-content block');

        $end = strpos($grid, '<div class="card-body', $start);
        $this->assertNotFalse($end, 'premise: the card body still follows it');

        return substr($grid, $start, $end - $start);
    }

    /** EVERY span in that block opens and closes. This is the defect, stated as a count. */
    public function test_the_card_text_block_has_no_unclosed_span(): void
    {
        $region = $this->userContent();

        $opens = preg_match_all('/<span\b/', $region);
        $closes = preg_match_all('#</span>#', $region);

        $this->assertGreaterThan(0, $opens, 'premise: the block really contains spans');
        $this->assertSame(
            $opens,
            $closes,
            "The card's text block has {$opens} <span> opens and {$closes} closes. The price span "
            . 'was unclosed for the whole of Phase 02: the parser auto-closed it at the enclosing '
            . '</div>, so everything placed after it was swallowed into it and inherited the '
            . 'dark-mode override on .text-dark. See OrderGridStatusLabelTest, which exists '
            . 'because of it.'
        );
    }

    /** The money is a figure, with the design system's tabular treatment. */
    public function test_the_price_is_rendered_as_a_tabular_figure(): void
    {
        $region = $this->userContent();

        $this->assertMatchesRegularExpression(
            '/<span class="ss-num">\{\{ order_currency_format_with_sym\(/',
            $region,
            'the price must sit in an `.ss-num` span: equal-width digits so a column of cards '
            . 'aligns, and `unicode-bidi: isolate` so the figure stays one left-to-right run '
            . 'inside an Arabic line. It was a bare text run in a sentence.'
        );
    }

    /** The date exists at all, and comes from the one helper rather than a second rule. */
    public function test_the_card_shows_the_order_date_through_the_shared_helper(): void
    {
        $region = $this->userContent();

        $this->assertStringContainsString(
            "__('Date')",
            $region,
            'the card must show a date. It showed none, while the list and the board both do.'
        );
        $this->assertStringContainsString(
            'OrderDataTable::dateOrDash($order->order_date)',
            $region,
            'and it must come from `dateOrDash()`, not from `Utility::dateFormat()` directly. '
            . 'That helper prints the EPOCH for a missing date -- F19, already found and fixed '
            . 'once on the list. A second implementation is how it comes back.'
        );
        $this->assertStringNotContainsString(
            'Utility::dateFormat($order->order_date)',
            $region,
            'F19 again: `Utility::dateFormat()` has no missing-date guard.'
        );
    }

    /**
     * AND THE HELPER STILL BEHAVES, driven rather than assumed -- it was made public for this
     * view, so this file is now one of the things that keeps it honest.
     *
     * @dataProvider absentDates
     */
    public function test_an_absent_date_is_a_dash_and_never_the_epoch($value): void
    {
        $rendered = OrderDataTable::dateOrDash($value);

        $this->assertSame(
            '-',
            $rendered,
            'a missing date must render as a bare dash. ' . var_export($value, true)
            . " rendered as '{$rendered}'."
        );
        $this->assertStringNotContainsString('1970', $rendered);
    }

    public static function absentDates(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            "MySQL's old zero date" => ['0000-00-00'],
            'the epoch itself' => ['1970-01-01'],
            'the epoch with a time' => ['1970-01-01 00:00:00'],
        ];
    }

    /*
     * THE POSITIVE CONTROL IS NOT HERE, AND THAT IS DELIBERATE RATHER THAN A GAP.
     *
     * Without one, a `dateOrDash()` that returned `-` for EVERYTHING would satisfy all five cases
     * above -- so the control is load-bearing and had to go somewhere. It cannot go HERE: this
     * class is a plain PHPUnit TestCase, like its three sibling ratchets for this view, and the
     * absent-date cases never reach `Utility::dateFormat()` because `hasDate()` short-circuits
     * first. A real date does reach it, and it resolves the auth factory and then reads the
     * `settings` table for the merchant's date format -- measured, in that order, as two separate
     * failures while writing this.
     *
     * So the control lives in `tests/Feature/Merchant/OrderGridCardRendersTheDateTest`, which
     * drives the real route. That is the stronger place for it anyway: it proves the CARD shows a
     * date, not merely that a helper can format one.
     */
}

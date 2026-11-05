<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE ADMIN HAS TWO TABLE SYSTEMS AND I GAVE THEM DIFFERENT ANSWERS.  P2.5-WAVE-6
 * =================================================================================================
 *
 * Measured: 85 merchant Blade files render a table. 39 go through `x-datatable` (a DataTable,
 * styled under `.ss-dt-card-body`); the rest are hand-written `<table>` markup, and eight of those
 * carry the design system's own `.ss-table`. Both are merchant tables. Both show money. They had
 * diverged on two questions, and one of those divergences is MINE.
 *
 * 1. UPPERCASE HEADERS.
 *
 *    `.ss-table` had already met this question and answered it, at store-syria-ds.css:1393, with
 *    a rationale written down:
 *
 *        /* Arabic has no case, so uppercasing is meaningless and slightly harms
 *           legibility. Turn it off for Arabic headers. *(slash)
 *        [dir="rtl"] .ss-table thead th { text-transform: none; letter-spacing: 0; }
 *
 *    So English keeps `uppercase` + `0.04em`; Arabic gets neither. That is a DEFENSIBLE choice --
 *    all-caps with tracking is the conventional Latin table-header treatment, and Arabic's
 *    equivalent emphasis is weight and colour.
 *
 *    P2.5-WAVE-1 then met the same question on the DataTables side and answered it the OTHER way:
 *    `text-transform: none` in both languages, with weight and colour carrying the distinction.
 *    I did not know rule 1393 existed when I wrote that.
 *
 *    EITHER ANSWER IS DEFENSIBLE. BOTH ANSWERS IN ONE PRODUCT IS NOT. A merchant moving from
 *    /product (sentence case) to /finance (ALL CAPS in English, sentence case in Arabic) sees two
 *    table conventions in one admin, and in Arabic sees them render identically while in English
 *    they do not.
 *
 *    RESOLVED TOWARDS WAVE-1, and that is a design call overriding an existing written rationale,
 *    so here is the reasoning rather than just the rule. `.ss-table thead th` ALREADY carries
 *    `--ss-fg-muted`, `--ss-weight-semibold`, `--ss-text-xs` and a sunken background: the header is
 *    unmistakably a header with no help from case at all. Case was the only part of that treatment
 *    one of the two languages could not render, so it is the part to drop. The 39 DataTable files
 *    are also the larger half, and the smaller half moves to meet them.
 *
 * 2. THE NUMERIC COLUMN.
 *
 *    `.ss-table__num` existed and set `font-variant-numeric: tabular-nums`. WAVE-1 then added
 *    `.ss-dt-num` with tabular figures AND `text-align: end`. So a price in a DataTable aligned to
 *    the money edge and a price in a hand-written table did not -- for no reason beyond which
 *    component the screen happened to use.
 */
class TableSystemsAgreeTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function decls(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->css());
    }

    /** Extract one rule's body by its exact selector, declarations only. */
    private function rule(string $selector): string
    {
        $decls = $this->decls();
        $at = strpos($decls, $selector);
        $this->assertNotFalse($at, "the rule `{$selector}` is missing from " . self::SHEET);
        $open = strpos($decls, '{', $at);
        $close = strpos($decls, '}', $open);

        return substr($decls, $open, $close - $open);
    }

    /**
     * PREMISE. Both systems must still exist, or the agreement below is agreement about nothing.
     */
    public function test_both_table_systems_are_present(): void
    {
        $decls = $this->decls();

        $this->assertStringContainsString('.ss-table thead th', $decls, 'the hand-written table system is gone');
        $this->assertStringContainsString('.ss-dt-card-body', $decls, 'the DataTable system is gone');
    }

    /** 1. Neither system leans on a case transform one of the two languages cannot render. */
    public function test_neither_table_system_uppercases_its_headers(): void
    {
        $this->assertStringNotContainsString(
            'text-transform: uppercase',
            $this->rule('.ss-table thead th'),
            '.ss-table headers are still uppercased, so English and Arabic get different visual '
                . 'weight for the same element — and the DataTables beside them do not'
        );
    }

    /**
     * ...and the RTL-only undo goes with it. Leaving it would be dead CSS asserting a rule that
     * no longer exists, which is how a stylesheet starts lying about itself.
     */
    public function test_the_rtl_only_undo_is_gone_now_that_there_is_nothing_to_undo(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\[dir="rtl"\]\s*\.ss-table\s+thead\s+th[^{]*\{[^}]*text-transform:\s*none/s',
            $this->decls(),
            'the RTL-only `text-transform: none` survives, but there is no uppercase left for it '
                . 'to undo — it now only differs by letter-spacing, which is the same asymmetry in '
                . 'a smaller form'
        );
    }

    /** 2. Money aligns to the money edge in BOTH systems. */
    public function test_both_numeric_column_classes_align_and_use_tabular_figures(): void
    {
        foreach (['.ss-table__num', '.ss-dt-num'] as $sel) {
            $body = $this->rule($sel);
            $this->assertStringContainsString(
                'tabular-nums',
                $body,
                "{$sel} does not use tabular figures, so decimal points do not line up"
            );
            $this->assertStringContainsString(
                'text-align: end',
                $body,
                "{$sel} does not align to the end, so a price in one table system sits on a "
                    . 'different edge from the same price in the other'
            );
        }
    }

    /** `end`, not `right` — in either system a physical value strands money in Arabic. */
    public function test_neither_numeric_class_hard_codes_a_physical_side(): void
    {
        foreach (['.ss-table__num', '.ss-dt-num'] as $sel) {
            $this->assertDoesNotMatchRegularExpression(
                '/text-align:\s*(left|right)/',
                $this->rule($sel),
                "{$sel} hard-codes a physical side and mirrors wrongly in Arabic"
            );
        }
    }
}

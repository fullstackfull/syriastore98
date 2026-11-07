<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE ORDER TABLES WENT THROUGH THE DESIGN SYSTEM, NOT THE BOOTSTRAP DEFAULT.  P2.5-WAVE-11
 * =================================================================================================
 *
 * Three merchant order surfaces render a hand-written `<table class="table">`, outside the
 * `.ss-dt-card-body` scope that P2.5-WAVE-1 styled and so reached by none of it:
 *
 *   order/view.blade.php            the Order Detail line-items table -- the most-opened order surface
 *   order/order_show.blade.php      the quick-view MODAL, reached by the eye action on list and grid
 *   order/refund_details.blade.php  the refunded line items and amounts, on the screen that pays money out
 *
 * Measured on the running application (evidence/wave-11/before): each rendered the stock Bootstrap
 * treatment, and the quick-view modal's header read `ITEM QUANTITY TOTAL` in all-caps -- the only
 * live `text-transform: uppercase` the merchant admin still shipped, carried by `style.css:27498`
 * (and `style-rtl.css`, so the Arabic page loaded it too), which Arabic cannot render as emphasis
 * because it is unicameral. All three now carry `.ss-table`, whose header band answers that in both
 * languages (Wave 6) and whose `.ss-table__num` aligns money to the end edge in both directions.
 *
 * VISUAL ONLY. No order transition, refund, amount, permission or tenant predicate changed -- the
 * diff is the table class attribute on three lines. `modal-table` is kept on the quick-view table
 * because it carries the modal's own spacing; `ss-table` is added beside it, not in place of it.
 *
 * THE BITE. Reverting any of the three `class="table ss-table"` back to `class="table"` fails
 * test_every_order_table_is_a_design_system_table -- the count is asserted per table, so reverting
 * one of the three is caught, not masked by the other two.
 *
 * A UNIT TEST reading the Blade source: no database, no container, no module loader.
 */
class OrderTablesUseDesignSystemTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /** Each order view and the number of `<table>` elements measured in it (a magic-window premise). */
    private const ORDER_TABLES = [
        'resources/views/order/view.blade.php' => 1,
        'resources/views/order/order_show.blade.php' => 1,
        'resources/views/order/refund_details.blade.php' => 1,
    ];

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> every `<table ...>` opening tag in the source */
    private function tableTags(string $source): array
    {
        preg_match_all('/<table\b[^>]*>/s', $source, $m);

        return $m[0];
    }

    /** PREMISE. `.ss-table` must be a real rule, or asserting the markup uses it proves nothing. */
    public function test_the_design_system_table_rule_exists(): void
    {
        $this->assertStringContainsString(
            '.ss-table thead th',
            $this->read(self::SHEET),
            'the .ss-table system is gone from the stylesheet, so the class the order views carry '
                . 'resolves to nothing'
        );
    }

    /** Every table on every order surface is a design-system table. */
    public function test_every_order_table_is_a_design_system_table(): void
    {
        foreach (self::ORDER_TABLES as $view => $expected) {
            $tags = $this->tableTags($this->read($view));

            $this->assertGreaterThanOrEqual(
                $expected,
                count($tags),
                "premise: {$view} renders " . count($tags) . " <table> element(s), fewer than the "
                    . "{$expected} measured -- a table was dropped and is no longer covered here"
            );

            foreach ($tags as $i => $tag) {
                $this->assertMatchesRegularExpression(
                    '/\bss-table\b/',
                    $tag,
                    "table #{$i} in {$view} does not carry .ss-table, so it renders in the stock "
                        . "Bootstrap frame while the design-system tables beside it do not: {$tag}"
                );
            }
        }
    }

    /**
     * The quick-view modal keeps its `modal-table` class. `.ss-table` is added BESIDE it, never in
     * place of it: `modal-table` carries the modal's own cell spacing and dropping it would reflow
     * the dialog. This pins that both classes are present on that one table.
     */
    public function test_the_quick_view_modal_table_keeps_modal_table_beside_ss_table(): void
    {
        $source = $this->read('resources/views/order/order_show.blade.php');

        $this->assertMatchesRegularExpression(
            '/<table\b[^>]*\bclass\s*=\s*"[^"]*\bss-table\b[^"]*\bmodal-table\b|<table\b[^>]*\bclass\s*=\s*"[^"]*\bmodal-table\b[^"]*\bss-table\b/s',
            $source,
            'the quick-view table lost `modal-table` or `ss-table`; both must be present'
        );
    }
}

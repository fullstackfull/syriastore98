<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE GLYPH MUST MEAN WHAT THE CONTROL DOES.  P2.5-WAVE-15
 * =================================================================================================
 *
 * `order/view.blade.php`'s "Set order status" menu shipped **a checkmark on Cancel Order**, and
 * another on Return Order. Both differed from "Delivered" by exactly one thing: `text-danger`
 * instead of `text-success`. So the two money-destructive rows in the menu were drawn with the
 * glyph that means "done, successfully" and distinguished from the successful one **by colour
 * alone** -- which WCAG 1.4.1 forbids as a sole carrier, and which a merchant scanning the menu
 * reads as confirmation rather than cancellation.
 *
 * "Shipped" carried `fa-spinner`, the universal BUSY glyph, for a terminal state.
 *
 * The menu now reads as the progression it is, with a distinct glyph per row:
 *
 *     Confirmed    ti-circle-check     Cancel Order        ti-ban
 *     Picked Up    ti-package          Cancel and Replace  ti-refresh
 *     Shipped      ti-truck-delivery   Return Order        ti-arrow-back-up
 *     Delivered    ti-check
 *
 * ALSO REMOVED: `style="margin-left: -5px;"` from all seven rows. `me-1` is the logical margin and
 * mirrors; an inline PHYSICAL margin beats it in both directions, so Arabic got the nudge on the
 * wrong side and the class it was fighting never applied.
 *
 * Every name here was checked against `public/assets/fonts/tabler-icons.min.css` before use --
 * `IconNamesExistTest` enforces that generally, and this test pins the SEMANTICS it cannot see.
 */
class OrderStatusMenuIconSemanticsTest extends TestCase
{
    private const VIEW = 'resources/views/order/view.blade.php';

    private function read(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::VIEW;
        $this->assertFileExists($path);

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    /** @return array<string,string> label => icon class */
    private function menuIcons(): array
    {
        preg_match_all(
            '/<i class="ti (ti-[a-z0-9-]+)[^"]*"[^>]*><\/i>\s*\{\{\s*__\(\'\s*([^\']+?)\'\)\s*\}\}/',
            $this->read(),
            $m,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $hit) {
            $out[trim($hit[2])] = $hit[1];
        }

        return $out;
    }

    /** PREMISE. The scan must find the menu, or every assertion below passes over nothing. */
    public function test_the_scan_finds_the_status_menu(): void
    {
        $icons = $this->menuIcons();

        foreach (['Confirmed', 'Shipped', 'Delivered', 'Cancel Order', 'Return Order'] as $label) {
            $this->assertArrayHasKey($label, $icons,
                "the status-menu scan did not find the '{$label}' row, so this test measures nothing");
        }
    }

    /** No destructive row wears the success glyph. */
    public function test_no_destructive_row_is_drawn_with_a_checkmark(): void
    {
        $icons = $this->menuIcons();

        foreach (['Cancel Order', 'Return Order'] as $label) {
            $this->assertNotSame('ti-check', $icons[$label],
                "'{$label}' is drawn with the checkmark that means 'done successfully'. It is a "
                . 'money-destructive action and must not share the success glyph.');
            $this->assertNotSame('ti-circle-check', $icons[$label],
                "'{$label}' is drawn with a check-circle, which reads as confirmation.");
        }
    }

    /** And the rows are told apart by SHAPE, not only by colour (WCAG 1.4.1). */
    public function test_every_status_row_has_its_own_glyph(): void
    {
        $icons = $this->menuIcons();
        $dupes = array_keys(array_filter(array_count_values($icons), fn (int $n) => $n > 1));

        $this->assertSame([], $dupes,
            'two status rows share a glyph, so they are distinguished only by their colour class: '
            . implode(', ', $dupes));
    }

    /** The physical inline margin is gone, so the logical `me-1` can mirror. */
    public function test_the_menu_icons_carry_no_inline_physical_margin(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<i class="ti ti-[a-z0-9-]+[^"]*"\s+style="margin-left:/',
            $this->read(),
            'a status-menu icon still carries an inline `margin-left`, which beats the logical '
                . '`me-1` in both directions and strands the nudge on the wrong side in Arabic'
        );
    }
}

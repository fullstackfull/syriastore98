<?php

namespace Tests\Feature\DesignSystem;

use Tests\TestCase;

/**
 * THE ORDER BOARD MADE THE WHOLE PAGE SCROLL SIDEWAYS, AND THE BOARD WAS NOT THE PART AT FAULT.
 * =================================================================================================
 *
 * MEASURED in Chromium on `/order-kanban` at a 1440px viewport, in BOTH directions -- it is not an
 * RTL defect, although the Arabic screenshot is where it was first seen, with the page sitting
 * scrolled right, the sidebar off-screen and every heading clipped:
 *
 *     document scrollWidth 1735 against a 1440 viewport      -- +295px
 *     .ss-board        rect 1145, clientWidth 1145, scrollWidth 2008, overflow-x: auto
 *                      -> the board scrolls correctly and clips its own columns
 *     .dash-container  clientWidth 1185, scrollWidth 1480     -- the overflow starts here
 *     hiding .ss-board              -> 1480 drops to 1185
 *     hiding .ss-board__card-move   -> 1480 drops to 1185
 *     hiding .ss-select inside it   -> no change
 *
 * THE ESCAPEE IS THE MOVE CONTROL'S VISUALLY HIDDEN LABEL. `.ss-visually-hidden` is
 * `position: absolute` with `auto` offsets, so it sits at its static position relative to the
 * nearest POSITIONED ancestor -- and the board, its columns and its cards were all
 * `position: static`, which made the labels' offsetParent `.dash-container`, outside the scroll
 * container. An absolutely positioned box is not clipped by an ancestor's `overflow` unless that
 * ancestor is its containing block. 57 such labels were on the page; the furthest had a right edge
 * at 1735px, which IS the document width.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE, stated rather than implied. The Feature suite has no
 * browser, so it cannot measure a scrollWidth. It asserts the DECLARATION that makes the
 * difference, which is a source-reading ratchet in the sense `test/README`-style guards are used
 * elsewhere in this project: it answers *"did somebody remove this again?"*, not *"does the page
 * fit?"*. The behavioural proof is
 * `docs/development/phase-2.5/tools/measure-horizontal-overflow.cjs`, which drives the real admin
 * in Chromium in all four combinations and prints each page's document scrollWidth against its
 * viewport.
 */
class KanbanDoesNotOverflowTest extends TestCase
{
    private const CSS = 'public/css/store-syria-ds.css';

    private function css(): string
    {
        $path = base_path(self::CSS);
        $this->assertFileExists($path, 'premise: the design system stylesheet is where it is expected');

        return (string) file_get_contents($path);
    }

    /** The column must be a containing block, or its hidden labels resolve against the page. */
    public function test_a_board_column_is_a_positioning_context(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.ss-board__col\s*\{[^}]*position:\s*relative/s',
            $css,
            '`.ss-board__col` must declare `position: relative`. Without it the 57 visually hidden '
            . 'labels inside the board resolve against `.dash-container`, escape the scroll '
            . 'container and widen the document by 295px -- measured at a 1440px viewport.'
        );
    }

    /**
     * The board must still be the thing that scrolls. A "fix" that removed `overflow-x: auto`
     * would also stop the page overflowing, by making the board unusable.
     */
    public function test_the_board_is_still_its_own_scroll_container(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.ss-board\s*\{[^}]*overflow-x:\s*auto/s',
            $css,
            'the board itself must still scroll horizontally -- that is what a board does'
        );
        $this->assertMatchesRegularExpression(
            '/\.ss-board\s*\{[^}]*max-width:\s*100%/s',
            $css,
            'and it must still be bounded by its parent, or its intrinsic width widens the page'
        );
    }

    /**
     * `contain: paint` fixes the symptom and was rejected, because it would clip focus rings and
     * any deliberate overflow with it. If it ever appears on the board, this says why not.
     */
    public function test_the_board_is_not_fixed_with_paint_containment(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-board\s*\{[^}]*contain:\s*paint/s',
            $this->css(),
            '`contain: paint` on `.ss-board` would clip the focus ring of every control inside it. '
            . 'The positioning context on `.ss-board__col` is the fix.'
        );
    }

    /** The warning that stops the next scrolling component repeating it. */
    public function test_the_hazard_is_recorded_where_the_class_is_defined(): void
    {
        $css = $this->css();
        $at = strpos($css, '.ss-visually-hidden {');

        $this->assertNotFalse($at, 'premise: the class is defined in this file');

        $preamble = substr($css, max(0, $at - 1200), 1200);

        /* Case-insensitively, and that is not laziness: the comment writes POSITIONED in capitals
           for emphasis, and the first draft of this assertion searched for the lower-case form
           and failed against a comment that says exactly the right thing. A ratchet that reddens
           on a writer's emphasis is a ratchet people learn to ignore. */
        $this->assertStringContainsStringIgnoringCase(
            'positioned ancestor',
            $preamble,
            'the comment above `.ss-visually-hidden` must keep saying that it needs a positioned '
            . 'ancestor inside a scroll container. The next component to scroll horizontally will '
            . 'hit this, and the class is where somebody will look.'
        );
    }
}

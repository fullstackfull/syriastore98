<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-100 / P03-B-07 — THE SECTION-ORDER SCREEN'S KEYBOARD PATH, PINNED IN THE BLOCKING SUITE.
 * =================================================================================================
 *
 * `ThemeOrderKeyboardPathTest` drives the real route and asserts the DOM a merchant receives; it
 * lives in the Feature suite, which takes 32 minutes and is skipped entirely when Unit is red.
 * This file holds the few properties of the same fix that a census CAN establish honestly, so they
 * are checked on every push by the suite that blocks.
 *
 * SCOPED TO THE BLOCK, NOT TO THE FILE. Every assertion here runs against the view's own
 * `<script>` region, not the whole 116-line template. The reason is recorded in CLAUDE.md and was
 * measured four times in one day: a search over a whole file answers "does this string exist
 * anywhere", and a mutation that removed one component's rule survived because a different
 * component had the same rule. `scriptBlock()` fails loudly if the region cannot be found.
 *
 * AND WITH COMMENTS REMOVED. `Tests\Support\StripsSourceComments` exists because nine files had
 * made the same mistake: a comment explaining a defect is evidence somebody understood it, not
 * evidence the code is still there. The comments in the view being asserted on here describe the
 * keyboard path in detail and would satisfy every string match below.
 */
class ThemeOrderKeyboardContractTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/theme_customize/order.blade.php';

    /** The view's inline script, comments already stripped. */
    private function scriptBlock(): string
    {
        $code = $this->sourceWithoutComments(self::VIEW);

        $this->assertSame(1, preg_match('~<script>(.*?)</script>~s', $code, $m),
            'PREMISE: the order view has no single inline <script> region, so every assertion in '
            . 'this file would be scoped to nothing. If the script moved to its own file, point '
            . 'this helper at that file instead of widening the search to the template.');

        $block = trim($m[1]);

        $this->assertNotSame('', $block, 'PREMISE: the inline script region is empty.');

        return $block;
    }

    /**
     * THE MEASUREMENT THIS GAP IS NAMED FOR, INVERTED. `PHASE-03-M4-THEME-EDITOR-PLAN.md:298`
     * reported `keydown` count 0 on both first-party sortables and called it a WCAG 2.1.1 and
     * 2.5.7 failure. The fix is not a `keydown` listener — it is two real buttons, which Enter and
     * Space drive natively and which a pointer user can click without dragging, so it answers
     * 2.5.7 as well. What has to be true is that the script knows about them.
     *
     * AND THE ASSERTION IS THE SPECIFIC LOOKUP, NOT THE ATTRIBUTE NAME. Its first version was
     * `assertStringContainsString('data-ss-order-move', $block)` and a mutation SURVIVED it:
     * repointing the per-row lookup at `[data-nothing=...]` left the attribute name in the
     * delegated `closest()` call two functions away, so the test still passed against a script
     * that could no longer find either button. That is the "does this string exist anywhere"
     * failure CLAUDE.md records — the block scoping above is necessary and was not sufficient.
     * Both lookups are now named individually.
     */
    #[Test]
    public function test_the_script_drives_the_move_controls(): void
    {
        $block = $this->scriptBlock();

        $this->assertMatchesRegularExpression(
            '~closest\(\s*[\'"]\[data-ss-order-move\]~', $block,
            'The delegated click listener no longer looks for a move control, so activating a '
            . 'button does nothing. A control that is reachable by Tab and inert is not a '
            . 'keyboard path.');

        $this->assertMatchesRegularExpression(
            '~querySelector\(\s*[\'"]\[data-ss-order-move=~', $block,
            'Nothing looks up a row\'s own up/down control any more. That lookup is what sets the '
            . 'end stops after every move and what re-focuses the control that was pressed.');
    }

    /**
     * ONE WRITER FOR THE HIDDEN INPUT, AND THE DRAG GOES THROUGH IT TOO.
     *
     * The persistence path is `#hidden_order` -> the form POST -> `updateOrder()`. Before this fix
     * the drag's `stop` handler and the page's own initialisation each built that string
     * themselves, with the loop written out twice. Adding a third copy for the keyboard would have
     * been the obvious change and the wrong one: the end-stop states and the per-row position text
     * also have to be recomputed after a move, and a drag that skips them leaves nine rows
     * claiming positions they no longer hold.
     *
     * So there is exactly one assignment to the input's value in the whole script. This is
     * asserted by COUNT rather than by presence, because presence is satisfied by a keyboard path
     * that writes the input and a drag that still does its own thing.
     */
    #[Test]
    public function test_exactly_one_statement_writes_the_hidden_order_input(): void
    {
        $block = $this->scriptBlock();

        $writes = preg_match_all('~hidden\w*\s*\.\s*value\s*=|\$\(\s*[\'"]#hidden_order[\'"]\s*\)\s*\.\s*val\s*\(~', $block);

        $this->assertSame(1, $writes,
            "The script writes the hidden `orders` input {$writes} time(s); it must be exactly "
            . 'one. Two writers is how the drag and the keyboard path drift apart, and it is also '
            . 'how the position text and the end stops get updated on one path and not the other.');
    }

    /**
     * THE BLOCK AXIS DOES NOT MIRROR, AND THIS IS THE ASSERTION A REVIEWER WOULD WANT.
     *
     * The repository's other keyboard reorder inverts its keys under RTL and is right to:
     * `store-syria-ds.js` moves table COLUMNS with Left and Right, which are inline-axis keys, and
     * the first column paints on the right in Arabic. A vertical list is the opposite case. Up is
     * up in both directions, so a direction test in this script could only be a bug — and it is
     * exactly the kind of bug that is invisible in English and reported by an Arabic merchant.
     *
     * Asserted over the comment-stripped block, because the paragraph above this one in the view
     * explains RTL at length and contains every word being searched for.
     */
    #[Test]
    public function test_the_script_contains_no_direction_branch(): void
    {
        $block = $this->scriptBlock();
        $found = [];

        foreach (['rtl', "getAttribute('dir')", 'getAttribute("dir")', 'dir ===', 'dir =='] as $needle) {
            if (stripos($block, $needle) !== false) {
                $found[] = $needle;
            }
        }

        $this->assertSame([], $found,
            'The move script branches on text direction (' . implode(', ', $found) . '). RTL '
            . 'mirrors the inline axis only, so a vertical reorder must behave identically in '
            . 'Arabic and English; inverting up and down there would be the column-reorder fix '
            . 'applied to the wrong axis.');
    }

    /**
     * AN INERT CONTROL MUST STILL REFUSE THE ACTIVATION IT CAN STILL RECEIVE.
     *
     * The end stops are `aria-disabled`, not `disabled`, so the first row's up button stays in the
     * tab order — that is deliberate and `ThemeOrderKeyboardPathTest` asserts the spelling. The
     * consequence is that it can still be focused and still receives a click from Enter or Space:
     * `pointer-events: none` (store-syria-ds.css:891) stops a mouse and does nothing to a
     * keyboard. The handler therefore has to check.
     */
    #[Test]
    public function test_the_handler_refuses_an_inert_control(): void
    {
        $block = $this->scriptBlock();

        /*
         * THE GUARD STATEMENT, NOT THE ATTRIBUTE NAME. The first version of this assertion was
         * `assertStringContainsString('aria-disabled', $block)` and a mutation SURVIVED it:
         * deleting the guard outright left `setAttribute('aria-disabled', ...)` and
         * `removeAttribute('aria-disabled')` in the end-stop writer, so the name was still in the
         * block and the test passed against a handler that would happily move the first row above
         * itself. Scoping to the block was necessary and not sufficient; this names the read and
         * the early return together, which `setInert` cannot satisfy.
         */
        $this->assertMatchesRegularExpression(
            '~getAttribute\(\s*[\'"]aria-disabled[\'"]\s*\)\s*===\s*[\'"]true[\'"]\s*\)\s*\{\s*return~',
            $block,
            'The move handler never reads aria-disabled and returns. The end stops stay in the tab '
            . 'order on purpose, so Enter or Space on the first row\'s up button reaches the '
            . 'handler; pointer-events:none only stops the mouse. Without the check the first row '
            . 'moves above itself or the last row below itself.');
    }

    /**
     * FOCUS FOLLOWS THE ROW, NOT THE POSITION. Moving a node that contains the focused element is
     * a remove-and-insert, and the browser blurs on the remove — so after one press focus is on
     * the document and a second press goes nowhere. Re-focusing the same button is what makes
     * repeated presses work, and it is also why the control must not become `disabled` at the end
     * of its travel.
     */
    #[Test]
    public function test_the_script_restores_focus_after_a_move(): void
    {
        $block = $this->scriptBlock();

        $this->assertMatchesRegularExpression('~\.focus\s*\(~', $block,
            'The move handler never re-focuses anything. Reparenting a row blurs the button '
            . 'inside it, so the first press would be the last one that works.');
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-100 / P03-B-07 — THE ATTRIBUTE-OPTION SCREEN'S KEYBOARD PATH, PINNED IN THE BLOCKING SUITE.
 * =================================================================================================
 *
 * `Tests\Feature\DesignSystem\AttributeOptionKeyboardPathTest` drives the real route and asserts
 * the DOM a merchant receives; it lives in the Feature suite, which takes 32 minutes and is
 * skipped entirely when Unit is red. This file holds the few properties of the same fix that a
 * census CAN establish honestly, so they are checked on every push by the suite that blocks.
 *
 * It is the sibling of `ThemeOrderKeyboardContractTest`, which does the same job for the other
 * first-party sortable. The two screens differ in one structural way and it changes one assertion:
 * the theme screen persists through a hidden input and an explicit Save, so "one writer" there is
 * "exactly one statement assigns the input's value". THIS screen persists by AJAX, so one writer
 * is "exactly one statement sends the request" — and a second property the theme screen cannot
 * have at all: a page load must not POST.
 *
 * SCOPED TO THE BLOCK, NOT TO THE FILE. Every assertion here runs against the view's own inline
 * `<script>` region, not the whole template. The reason is recorded in CLAUDE.md and was measured
 * four times in one day: a search over a whole file answers "does this string exist anywhere", and
 * a mutation that removed one component's rule survived because a different component had the same
 * rule. `scriptBlock()` fails loudly if the region cannot be found.
 *
 * AND WITH COMMENTS REMOVED. The comments in the view being asserted on here describe the keyboard
 * path, the RTL reasoning and the inert-control guard at length, and would satisfy every string
 * match below. That is not hypothetical on this view: writing it produced BOTH directions of the
 * trap in one sitting — a JavaScript comment naming the delete-confirmation class broke
 * `PilotScreenContractTest`'s per-row count (6 for 5 rows), and a comment spelling Blade's json
 * directive with empty parentheses compiled to `json_encode(, 15)` and 500'd the screen.
 */
class AttributeOptionKeyboardContractTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/attribute_option/index.blade.php';

    /** The whole view, comments already stripped. */
    private function viewSource(): string
    {
        return $this->sourceWithoutComments(self::VIEW);
    }

    /** The view's inline script, comments already stripped. */
    private function scriptBlock(): string
    {
        $code = $this->viewSource();

        /*
         * `<script>` with no attributes. The view also loads jquery-ui through `<script src=...>`,
         * which this pattern deliberately does not match.
         */
        $this->assertSame(1, preg_match('~<script>(.*?)</script>~s', $code, $m),
            'PREMISE: the attribute-option view has no single attribute-less inline <script> '
            . 'region, so every assertion in this file would be scoped to nothing. If the script '
            . 'moved to its own file, point this helper at that file instead of widening the '
            . 'search to the template.');

        $block = trim($m[1]);

        $this->assertNotSame('', $block, 'PREMISE: the inline script region is empty.');

        /*
         * AND THE STRIPPER DID NOT EAT IT. `StripsSourceComments` uses its pattern path for Blade,
         * whose block-comment opener is guarded by a lookbehind that has been wrong before — it
         * swallowed 5,678 characters of two variant views once. A block that survived stripping
         * but lost its body would make every assertion below a statement about nothing.
         */
        $this->assertStringContainsString('function sync(', $block,
            'PREMISE: the stripped script no longer contains the one writer, so the stripper has '
            . 'eaten code rather than comments.');

        return $block;
    }

    /**
     * THE MEASUREMENT THIS GAP IS NAMED FOR, INVERTED. `PHASE-03-M4-THEME-EDITOR-PLAN.md:298`
     * reported `keydown` count 0 on BOTH first-party sortables and called it a WCAG 2.1.1 and
     * 2.5.7 failure. The fix is not a `keydown` listener — it is two real buttons, which Enter and
     * Space drive natively and which a pointer user can click without dragging, so it answers
     * 2.5.7 as well. What has to be true is that the script knows about them.
     *
     * AND THE ASSERTION IS THE SPECIFIC LOOKUP, NOT THE ATTRIBUTE NAME. The sibling test records
     * a mutation that SURVIVED `assertStringContainsString('data-ss-order-move', $block)`:
     * repointing the per-row lookup at a different attribute left the name in the delegated
     * `closest()` call two functions away, so the test passed against a script that could no
     * longer find either button. Both lookups are named individually here for the same reason.
     */
    #[Test]
    public function test_the_script_drives_the_move_controls(): void
    {
        $block = $this->scriptBlock();

        $this->assertMatchesRegularExpression(
            '~closest\(\s*[\'"]\[data-ss-option-move\]~', $block,
            'The delegated click listener no longer looks for a move control, so activating a '
            . 'button does nothing. A control that is reachable by Tab and inert is not a '
            . 'keyboard path.');

        $this->assertMatchesRegularExpression(
            '~querySelector\(\s*[\'"]\[data-ss-option-move=~', $block,
            'Nothing looks up a row\'s own up/down control any more. That lookup is what sets the '
            . 'end stops after every move and what re-focuses the control that was pressed.');
    }

    /**
     * ONE WRITER FOR THE PERSISTED ORDER, AND THE DRAG GOES THROUGH IT TOO.
     *
     * The persistence path on this screen is an AJAX POST to `attribute-option`, which is gated on
     * `Edit Attributes Option` and refuses the whole payload if any posted id belongs to another
     * tenant. Before this fix the drag's `stop` handler built the posted id list itself. Adding a
     * second copy for the keyboard would have been the obvious change and the wrong one: the
     * end-stop states and the per-row position text also have to be recomputed after a move, and a
     * drag that skips them leaves four rows claiming positions they no longer hold.
     *
     * So there is exactly one request-sending statement in the whole script. This is asserted by
     * COUNT rather than by presence, because presence is satisfied by a keyboard path that posts
     * and a drag that still does its own thing.
     */
    #[Test]
    public function test_exactly_one_statement_sends_the_reorder_request(): void
    {
        $block = $this->scriptBlock();

        $sends = preg_match_all('~\$\s*\.\s*(ajax|post)\s*\(|fetch\s*\(|XMLHttpRequest~', $block);

        $this->assertSame(1, $sends,
            "The script sends the reorder request {$sends} time(s); it must be exactly one. Two "
            . 'senders is how the drag and the keyboard path drift apart, and it is also how the '
            . 'position text and the end stops get updated on one path and not the other.');
    }

    /**
     * THE ONE WRITER IS ALSO THE ONE PLACE THE END STOPS AND THE POSITION TEXT ARE WRITTEN.
     *
     * Counting the senders is not enough on its own: a keyboard handler that reordered the DOM and
     * called the sender directly would still count one. What makes the two paths agree is that
     * neither of them touches the position text or the inert state itself.
     */
    #[Test]
    public function test_only_one_statement_writes_the_position_text(): void
    {
        $block = $this->scriptBlock();

        $writes = preg_match_all('~\.\s*textContent\s*=\s*fill\s*\(~', $block);

        $this->assertSame(1, $writes,
            "The script composes a row's position text in {$writes} place(s); it must be exactly "
            . 'one. A second one is a second writer, and the whole point of this slice is that the '
            . 'drag and the keyboard path recompute the order, the positions and the end stops '
            . 'through the same function.');
    }

    /**
     * A PAGE LOAD MUST NOT PERSIST ANYTHING, and this screen is the only one of the two that can
     * get this wrong.
     *
     * The theme-order screen's initialisation fills a hidden input, which is inert until the
     * merchant presses Save. Here the one writer sends an HTTP POST, so calling it unconditionally
     * on load would write the order the page was already serving — on every view of the screen, by
     * anyone with `Manage Attributes Option` but not `Edit Attributes Option`, whose POST the route
     * refuses with a 403 and an error toast on a page they only looked at.
     *
     * Two statements together: the guard inside the writer, and the initialisation that relies on
     * it. The browser harness measures the consequence (zero requests before the first press).
     */
    #[Test]
    public function test_the_page_initialisation_recomputes_without_persisting(): void
    {
        $block = $this->scriptBlock();

        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*!\s*moved\s*\)\s*\{\s*return~', $block,
            'The one writer no longer returns early when nothing moved, so the initialisation '
            . 'call below it POSTs the order on every page load.');

        $this->assertMatchesRegularExpression('~\bsync\s*\(\s*null\s*\)~', $block,
            'Nothing initialises the end stops and the position text with the no-move argument. '
            . 'Without it the first row\'s up control and the last row\'s down control are only '
            . 'inert because the Blade emitted them that way, and the two paths disagree the '
            . 'moment the markup changes.');
    }

    /**
     * ONE REQUEST AT A TIME. A drag is one gesture and one POST; a keyboard move is one POST PER
     * PRESS. Each POST carries the WHOLE order, so two in flight can be applied by the server in
     * the wrong sequence and persist a list the merchant never saw — a defect that needs a
     * four-press burst and a slow connection to show up, which is to say it shows up in production
     * and not here.
     */
    #[Test]
    public function test_the_script_serialises_its_requests(): void
    {
        $block = $this->scriptBlock();

        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*inFlight\s*\)\s*\{[^}]*return~', $block,
            'Nothing stops a second reorder POST being sent while the first is still in flight. '
            . 'Every POST carries the whole order, so two of them racing can persist an order the '
            . 'merchant never saw.');

        $this->assertMatchesRegularExpression('~if\s*\(\s*queued\s*\)~', $block,
            'A request suppressed while another was in flight is never re-sent, so the LAST move '
            . 'of a burst is the one that is lost — which is worse than the race it was added to '
            . 'prevent.');
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
     * Asserted over the comment-stripped block, because the paragraph inside the move handler
     * explains RTL at length and contains every word being searched for.
     */
    #[Test]
    public function test_the_script_contains_no_direction_branch(): void
    {
        $block = $this->scriptBlock();
        $found = [];

        /*
         * The sibling test's exact needle list. A sixth, `direction ===`, was added here and
         * removed again: the script's own up/down branch is spelled `direction === 'up'`, so it
         * matched legitimate code and the test failed against a correct script. None of the five
         * below collides with it — `dir ===` would need "dir" immediately followed by " ===", and
         * in `direction ===` the next characters are "ection".
         */
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
     * tab order — that is deliberate and `AttributeOptionKeyboardPathTest` asserts the spelling.
     * The consequence is that it can still be focused and still receives a click from Enter or
     * Space: `pointer-events: none` (store-syria-ds.css:891) stops a mouse and does nothing to a
     * keyboard. The handler therefore has to check.
     *
     * THE GUARD STATEMENT, NOT THE ATTRIBUTE NAME. The sibling test records a mutation that
     * survived `assertStringContainsString('aria-disabled', $block)`: deleting the guard outright
     * left `setAttribute('aria-disabled', ...)` and `removeAttribute('aria-disabled')` in the
     * end-stop writer, so the name was still in the block and the test passed against a handler
     * that would happily move the first row above itself. This names the read and the early return
     * together, which the end-stop writer cannot satisfy.
     */
    #[Test]
    public function test_the_handler_refuses_an_inert_control(): void
    {
        $block = $this->scriptBlock();

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

    /**
     * EXACTLY ONE LIVE REGION, ASSERTED AGAINST THE VIEW rather than against the rendered page.
     *
     * This is the half `AttributeOptionKeyboardPathTest` cannot answer, and its first draft tried
     * and FAILED before a line of the fix existed: a page-wide `//*[@aria-live]` count reads 3 on
     * this screen, because the command palette (`partision/command-palette.blade.php:47`) and the
     * sidebar's search-result region (`partision/sidebar.blade.php:182`) are rendered by the chrome
     * on every admin page. "The VIEW declares one region" is a question about the file, so it is
     * asked here, where the file is the subject.
     *
     * The keyboard path reuses `#ss-sort-status`, which shipped with the design-system pilot for
     * the drag's save announcement. A second region would be a second element a screen reader is
     * watching and a race between the move announcement and the failure announcement.
     */
    #[Test]
    public function test_the_view_declares_exactly_one_live_region_and_the_script_writes_that_one(): void
    {
        $source = $this->viewSource();

        $regions = preg_match_all('~aria-live\s*=~', $source);

        $this->assertSame(1, $regions,
            "The view declares {$regions} aria-live region(s); it must declare exactly one. The "
            . 'keyboard path reuses the pilot\'s #ss-sort-status rather than adding a second place '
            . 'a screen reader is watching.');

        $this->assertMatchesRegularExpression(
            '~getElementById\(\s*[\'"]ss-sort-status[\'"]\s*\)~', $this->scriptBlock(),
            'The script no longer resolves #ss-sort-status, so a move is reordered silently: a '
            . 'reorder changes nothing a screen reader is told about on its own.');
    }

    /**
     * NOTHING IN A RENDERED COMMENT MAY SPELL A STRING THE PILOT CONTRACT COUNTS.
     *
     * `PilotScreenContractTest` counts six literals over the whole rendered page and asserts an
     * exact figure for each. A Blade comment is stripped at compile time and is therefore safe; a
     * JavaScript `/* *\/` comment inside the inline script is RENDERED, and so is anything in the
     * markup. Writing this slice broke that census for real: a comment naming the delete hook's
     * class made the count read 6 for 5 rows.
     *
     * Asserted on the RAW file, deliberately — the comments are exactly what is being checked, so
     * stripping them would make this pass for free.
     */
    #[Test]
    public function test_no_rendered_comment_spells_a_literal_the_pilot_contract_counts(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::VIEW);

        $this->assertNotSame('', $raw, 'PREMISE: the view could not be read.');

        /* Blade comments never reach the page, so they are removed before counting. */
        $rendered = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        /*
         * literal => its MEASURED count in this view's own source, Blade comments removed.
         *
         * These are not the figures the pilot contract asserts about the PAGE — four of them are
         * zero here because `route()` and `Form::open()` emit them, and the route fragments never
         * appear as literals in the template at all. What this pins is that the view adds no
         * EXTRA occurrence, which is the only way a comment can move a page-wide count. If a
         * markup change legitimately alters one of these, change the figure here and in
         * `PilotScreenContractTest` together.
         */
        $counted = [
            'ss-sort-list' => 1,
            'class="ss-sort-item"' => 1,
            'show_confirm' => 1,
            'name="_method"' => 0,
            'add-attribute-option' => 0,
            'edit-attribute-option' => 0,
            'delete-attribute-option' => 0,
        ];

        $problems = [];

        foreach ($counted as $literal => $expected) {
            $actual = substr_count($rendered, $literal);

            if ($actual !== $expected) {
                $problems[] = sprintf('%s appears %d time(s) outside a Blade comment, expected %d',
                    $literal, $actual, $expected);
            }
        }

        $this->assertSame([], $problems,
            "A literal PilotScreenContractTest counts over the whole rendered page appears the "
            . "wrong number of times in this view:\n  " . implode("\n  ", $problems)
            . "\n\nIf the extra occurrence is in a JavaScript comment, reword it: a JS comment is "
            . 'rendered, unlike a Blade one. CLAUDE.md records this trap in both directions.');
    }
}

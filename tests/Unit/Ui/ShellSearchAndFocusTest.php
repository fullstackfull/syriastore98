<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-4a — two defects in the merchant shell, both found by driving the running admin.
 * =================================================================================================
 *
 * The shell is the highest-reach surface in the product: `layouts/app.blade.php` is the layout for
 * all 334 screens that set `@section('page-title')`. Both of these were measured in a real browser
 * against the real admin, not read out of a work-list — and the work-list's own entries for this
 * area turned out to be a mixture of live, stale and already-overridden, which is why.
 *
 * ---------------------------------------------------------------------------------------------
 *  1. THE SIDEBAR SEARCH EMPTIED THE WHOLE NAVIGATION AND SAID NOTHING
 * ---------------------------------------------------------------------------------------------
 *
 * MEASURED on /dashboard: with a term that matches nothing, `.dash-navbar > .dash-item` goes from
 * **13 visible to 0** and the five zone headings from **5 to 0**. The sidebar is blank below the
 * store switcher, with no message — indistinguishable from a broken page.
 *
 * WHO REACHES IT, and the answer is narrower than it looks, which is the part worth recording.
 * Clicking the search box opens the COMMAND PALETTE (`store-syria-ds.js`, a deliberate `click`
 * binding — P2-06-2 documents at length why it is `click` and not `focus`: a focus binding took
 * the keyboard user's focus into a dialog with its own Tab cycle and left 72 of 75 controls on
 * every merchant screen unreachable). `cmdOpen()` then focuses the palette's own input. So a
 * MOUSE user clicking the box lands in the palette and never drives this filter.
 *
 * A KEYBOARD USER DOES. Tab produces no click, so focus lands on `#sideBarsearch` with the palette
 * closed and every keystroke runs the navigation filter — verified by focusing the input without a
 * click and typing: `paletteOpen: false`, and the sidebar emptied. Escape from the palette returns
 * focus to the same box and reaches it the same way. The repair therefore serves exactly the
 * audience the palette's own redesign was written to protect.
 *
 * VERIFIED IN ARABIC TOO, by dispatching the `keyup` the handler really binds rather than relying
 * on synthetic keystrokes (which silently failed to register Arabic characters and made an earlier
 * reading of this look like "the filter matches everything"):
 *
 *     الطلبات   ->  1 of 13 visible, the right one, message hidden
 *     zzzzzz    ->  0 of 13 visible, message shown
 *     (empty)   -> 13 of 13 visible, message hidden
 *
 * ---------------------------------------------------------------------------------------------
 *  2. FIVE KEYBOARD-FOCUSABLE CONTROLS IN THE SHELL HAD NO FOCUS INDICATOR
 * ---------------------------------------------------------------------------------------------
 *
 * A census of every visible focusable element — focus it, keep the ones that really match
 * `:focus-visible`, read the computed `outline` and `box-shadow`:
 *
 *     /dashboard   before: 23 class patterns WITH an indicator, 3 WITHOUT (5 controls)
 *                  after:  26 WITH, 0 WITHOUT
 *     /order       before: 28 WITH, 0 WITHOUT        after: unchanged — nothing lost
 *
 * The five were vendored Bootstrap `.btn` variants in the dashboard's profile card: Quick add,
 * Share store link and Copy theme link. WCAG 2.4.7.
 *
 * A THIRD THING THE WORK-LIST CLAIMED, AND THE MEASUREMENT REFUTED. It reported a pre-rebrand
 * magenta glow (`rgba(146,44,136,.3)`) under every active and hovered sidebar item. The active
 * item's computed `box-shadow` is `rgba(0,0,0,.45) 0 2px 4px, rgba(0,0,0,.4) 0 6px 16px` — the
 * magenta is overridden. `#e9d5e7` on `.btn-light-primary` is overridden too (computed background
 * `rgba(0,0,0,0)`), and `--bs-btn-focus-shadow-rgb: 146,44,136` is set but never painted
 * (`box-shadow: none` under genuine `:focus-visible`). Dead declarations, not live defects, so
 * they are left alone rather than churned — and the finding is recorded here so the next reader
 * does not re-raise it.
 */
class ShellSearchAndFocusTest extends TestCase
{
    private const SIDEBAR = 'resources/views/partision/sidebar.blade.php';
    private const LAYOUT = 'resources/views/layouts/app.blade.php';
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];
    private const MARK = 'P2.5-WAVE-4a';

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path, 'premise: ' . $relative);

        return (string) file_get_contents($path);
    }

    // =============================================================================================
    //  1. the sidebar search's empty state
    // =============================================================================================

    public function test_the_sidebar_renders_a_no_results_element(): void
    {
        $sidebar = $this->read(self::SIDEBAR);

        $this->assertStringContainsString('id="ss-sidebar-search-empty"', $sidebar,
            'the sidebar has no no-results element, so a search that matches nothing leaves the '
            . 'navigation blank with no explanation');
    }

    public function test_the_no_results_element_is_announced_to_a_screen_reader(): void
    {
        /* The hiding happens in JavaScript with no page change, so without a live region a screen
         * reader user hears nothing at all and arrows into an empty list. */
        $tag = $this->emptyElementTag();

        $this->assertStringContainsString('role="status"', $tag, $tag);
        $this->assertStringContainsString('aria-live="polite"', $tag,
            'assertive would interrupt the character the merchant is still typing: ' . $tag);
    }

    public function test_the_no_results_element_starts_hidden(): void
    {
        /* It must not be in the accessibility tree, or in the layout, until the count really is
         * zero — otherwise every merchant sees "no menu item matches" on every page load. */
        $tag = $this->emptyElementTag();

        $this->assertStringContainsString('hidden', $tag, $tag);
    }

    public function test_the_message_is_translated_and_not_a_hard_coded_string(): void
    {
        $sidebar = $this->read(self::SIDEBAR);

        $this->assertStringContainsString("__('No menu item matches')", $sidebar,
            'the message must go through __(), because the admin is bilingual');

        foreach (['ar', 'en'] as $locale) {
            $translations = json_decode($this->read('resources/lang/' . $locale . '.json'), true);

            $this->assertIsArray($translations, $locale . '.json did not decode');
            $this->assertArrayHasKey('No menu item matches', $translations,
                $locale . '.json has no translation for the sidebar empty state');
            $this->assertNotSame('', trim((string) $translations['No menu item matches']));
        }

        $ar = json_decode($this->read('resources/lang/ar.json'), true);

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $ar['No menu item matches'],
            'the Arabic value contains no Arabic letter: ' . $ar['No menu item matches']);
    }

    public function test_the_handler_counts_matches_and_announces_the_result(): void
    {
        $layout = $this->read(self::LAYOUT);

        $start = strpos($layout, "\$('#sideBarsearch').on('keyup'");
        $this->assertNotFalse($start, 'premise: the sidebar filter is still bound on keyup');

        $handler = substr($layout, $start, 3000);

        $this->assertStringContainsString('ss-sidebar-search-empty', $handler,
            'the handler never looks the element up, so it can never be shown');
        $this->assertStringContainsString('announce(', $handler,
            'the handler does not announce its result');
        $this->assertGreaterThanOrEqual(3, substr_count($handler, 'announce('),
            'announce() must be called on all three paths — the empty-input early return, the '
            . 'counted result, and its own definition — or one of them silently leaves a stale '
            . 'message on screen');
    }

    public function test_the_echoed_term_is_written_as_text_and_never_as_html(): void
    {
        /* The term is merchant-typed input going back onto the merchant's own page. Driven proof
         * that this holds: typing `<img src=x onerror=alert(1)>` renders
         * `"&lt;img src=x onerror=alert(1)&gt;"` and creates zero <img> elements. */
        $layout = $this->read(self::LAYOUT);

        $start = (int) strpos($layout, "\$('#sideBarsearch').on('keyup'");
        $handler = substr($layout, $start, 3000);

        $this->assertMatchesRegularExpression(
            '/dash-search-empty__term\'\)\.text\(/',
            $handler,
            'the search term is not written with .text(); .html() here would put merchant-typed '
            . 'markup into the page'
        );
        $this->assertStringNotContainsString(".html('\" + ", $handler);
    }

    /** The opening tag of the no-results element, quote-aware. */
    private function emptyElementTag(): string
    {
        $sidebar = $this->read(self::SIDEBAR);

        /* Blade comments first: this file documents the element it renders, so a raw scan finds
         * the explanation before the markup. */
        $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $sidebar);

        $at = strpos($markup, 'id="ss-sidebar-search-empty"');
        $this->assertNotFalse($at, 'premise: the element is in the markup, not only in a comment');

        $open = strrpos(substr($markup, 0, $at), '<');
        $this->assertNotFalse($open);

        $close = strpos($markup, '>', $at);
        $this->assertNotFalse($close);

        return substr($markup, $open, $close - $open + 1);
    }

    // =============================================================================================
    //  2. the focus ring
    // =============================================================================================

    public function test_both_mirrored_sheets_carry_the_focus_ring_rule(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertStringContainsString(self::MARK, $this->read($sheet),
                $sheet . ' has no ' . self::MARK . ' block');
        }
    }

    public function test_the_two_sheets_carry_the_same_block_byte_for_byte(): void
    {
        /* THE P2.5-WAVE-16b LESSON. `rtl-custom.css` is loaded INSTEAD of `custom.css` on the
         * Arabic layout, never alongside it, so a fix written to one gives half the merchants
         * nothing. Byte equality is the only check that cannot be half-applied. */
        $blocks = [];

        foreach (self::SHEETS as $sheet) {
            $source = $this->read($sheet);
            $at = strpos($source, '/* ---- ' . self::MARK);

            $this->assertNotFalse($at, $sheet . ' has no ' . self::MARK . ' block opener');

            $end = strpos($source, '/* end ' . self::MARK . ' */', $at);
            $this->assertNotFalse($end, $sheet . ' has no ' . self::MARK . ' end marker');

            $blocks[$sheet] = substr($source, $at, $end - $at);
        }

        $this->assertSame(
            $blocks[self::SHEETS[0]],
            $blocks[self::SHEETS[1]],
            'the two sheets have drifted. The Arabic layout loads rtl-custom.css INSTEAD of '
            . 'custom.css, so the halves must be identical.'
        );
    }

    public function test_the_ring_uses_the_design_systems_tokens(): void
    {
        /* So it follows the theme and matches the 26 patterns that already had an indicator. A
         * literal colour here would be a third focus style on the same page. */
        $source = $this->read(self::SHEETS[0]);
        $at = (int) strpos($source, '/* ---- ' . self::MARK);
        $block = substr($source, $at, (int) strpos($source, '/* end ' . self::MARK . ' */', $at) - $at);

        $this->assertMatchesRegularExpression('/\.btn:focus-visible\s*\{/', $block,
            'the rule must key on :focus-visible, so a mouse click does not draw it');
        $this->assertStringContainsString('var(--ss-focus-ring', $block);
        $this->assertStringContainsString('var(--ss-focus-width', $block);
        $this->assertStringContainsString('var(--ss-focus-offset', $block);
    }

    public function test_premise_the_class_token_scan_finds_both_kinds_of_button(): void
    {
        /* Without this, the assertion below passes because the scan found nothing to look at. */
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/order/action.blade.php'
        );

        preg_match_all('/class="([^"]*)"/', $source, $attributes);

        $tokens = [];

        foreach ($attributes[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                $tokens[$class] = true;
            }
        }

        $this->assertArrayHasKey('ss-btn', $tokens,
            'premise: the order action view really does carry design-system buttons');
    }

    public function test_the_design_systems_own_buttons_are_not_touched(): void
    {
        /* `.ss-btn` carries no `.btn` class, so the design system's buttons keep their own focus
         * treatment. Asserted because "add a ring to every button" is the obvious next edit and it
         * would put two rings on one control. */
        $views = glob(dirname(__DIR__, 3) . '/resources/views/order/*.blade.php') ?: [];

        $this->assertNotEmpty($views, 'premise: there are order views to scan');

        /*
         * `\bbtn\b` IS THE WRONG TEST AND THE FIRST VERSION OF THIS USED IT. A hyphen is a word
         * boundary, so `\bbtn\b` matches the `btn` inside `ss-btn` and every design-system button
         * read as a collision. A CSS class selector does not work that way -- `.btn` matches a
         * whole class TOKEN -- so the rule was right and only the test was wrong. The token
         * boundary is a space or the edge of the attribute, which is what this matches.
         */
        $both = [];

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);

            preg_match_all('/class="([^"]*)"/', $source, $attributes);

            foreach ($attributes[1] as $attribute) {
                $classes = preg_split('/\s+/', trim($attribute)) ?: [];

                if (in_array('btn', $classes, true) && in_array('ss-btn', $classes, true)) {
                    $both[] = basename($view) . ': ' . $attribute;
                }
            }
        }

        $this->assertSame([], $both,
            'an element carries BOTH the `btn` and `ss-btn` class tokens, so the vendored focus '
            . 'ring and the design system\'s would both apply to it: ' . implode(' | ', $both));
    }
}

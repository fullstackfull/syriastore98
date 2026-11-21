<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * MKT-010 / GAP-220 — THE PRODUCT CARD'S CONVERSION ACTIONS, AT PARITY AND ACTUALLY STYLED.
 * =================================================================================================
 *
 * `MKT-010` asks for the quick-view / compare / PDF / CSV actions to work the same across the three
 * themes. `GAP-220` found that the thing they were at parity WITH was nothing at all: the wrapper
 * class `supdiv` occurred in **exactly one file in the whole repository** — the hook that emits it.
 * No theme stylesheet, no vendored module and no script styled or selected it, so its inline
 * `top: 40px` was inert and the buttons rendered as bare, unspaced icons.
 *
 * WHAT THIS FILE PINS, and each assertion exists because the opposite was true an hour ago:
 *
 *  1. THE WRAPPER HAS A DESIGN-SYSTEM CLASS AND A RULE TO MATCH. `supdiv` is kept beside it so
 *     anything outside this repository that targets it is not broken, but the styling hangs off
 *     `.ss-product-actions` — `store-syria-ds.css` is loaded by all three theme layouts and its own
 *     docblock claims 705 of 708 selectors are `.ss-`/`data-ss-` scoped, which a bare `.supdiv`
 *     rule would have falsified.
 *  2. ALL THREE THEMES CALL THE HOOK FROM THEIR PRODUCT CARD. greentic did not, which is why its
 *     shoppers had no quick view or compare at all.
 *  3. THE STYLING DOES NOT POSITION ANYTHING. A first draft gave the parent `position: relative`
 *     via `:has()`; reading the themes refuted it — techzonix and stylique already place the hook
 *     inside `absolute` containers, and `relative` would have overridden that and dropped the
 *     buttons out of the corner. The overlay was never missing; the wrapper's styling was.
 *  4. NOTHING IS HIDDEN UNTIL HOVER. That draft also faded the row out behind
 *     `@media (hover: hover)`, which is precisely how a conversion action becomes unreachable: a
 *     keyboard user never hovers and a touch device has no hover state.
 *  5. NO MODULE BUTTON IS NESTED INSIDE AN `<a>`. techzonix's call sat inside the product anchor,
 *     so the module's own links and buttons were interactive content inside a link — invalid HTML,
 *     and a shopper pressing "compare" could follow the outer link instead.
 *
 * THIS IS A SOURCE CENSUS, NOT A RENDER TEST, and that limit is stated rather than hidden: the
 * buttons themselves come from `ProductQuickView`, `ProductCompare`, `ProductsPDF` and `ProductCSV`,
 * which per CLAUDE.md load in NO test run unless an `add_on_managers` row is inserted. So the suite
 * cannot see the rendered buttons, and the four-combination visual capture (AR/EN x light/dark)
 * remains outstanding. What the suite CAN see is every structural precondition, which is what this
 * covers.
 */
final class ProductActionOverlayTest extends TestCase
{
    private const THEMES = ['techzonix', 'greentic', 'stylique'];

    private const HOOK = 'resources/views/front_end/hooks/action_link.blade.php';

    private const DS = 'public/css/store-syria-ds.css';

    private function read(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, $relative . ' is gone; re-point this census rather than deleting it.');

        return (string) file_get_contents($path);
    }

    /**
     * THE SAME FILE WITH ITS BLADE COMMENTS REMOVED, and this exists because it bit immediately.
     *
     * The anchor-nesting check below reads markup around the hook call. Written against the raw
     * file it reported techzonix as still nested -- because the Blade comment I had JUST added to
     * explain moving the call out of the anchor contains the literal text `<a>` twice. The code was
     * correct; the heuristic was reading prose.
     *
     * CLAUDE.md records this class of false reading, and `Tests\Support\StripsSourceComments`
     * exists for the PHP form of it. This is the Blade form: `{{-- ... --}}`. A census that counts
     * comments is a census that can be satisfied, or broken, by writing about the thing it measures.
     */
    private function readWithoutBladeComments(string $relative): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->read($relative));
    }

    /**
     * The same, for CSS `/* ... *\/` comments -- and it bit for the THIRD time in one slice.
     *
     * The block assertions below say the wrapper must not position or hide itself. Run against the
     * raw stylesheet they failed, because the comment above those rules EXPLAINS that a first draft
     * used `position: relative` and `@media (hover: hover)` and why both were wrong. The rules are
     * correct; the prose describing them is not a declaration.
     *
     * Three forms of the same false reading in one afternoon -- PHP docblocks, Blade comments and
     * now CSS comments -- which is why CLAUDE.md records it as a standing trap rather than an
     * anecdote: a census that reads comments can be satisfied, or broken, by writing about the
     * thing it measures.
     */
    private function readWithoutCssComments(string $relative): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->read($relative));
    }

    public function test_the_hook_carries_the_design_system_class_and_keeps_the_old_one(): void
    {
        $hook = $this->read(self::HOOK);

        $this->assertStringContainsString('class="ss-product-actions supdiv"', $hook,
            'The wrapper no longer carries both classes. `ss-product-actions` is what the design '
            . 'system styles; `supdiv` is kept because something outside this repository may still '
            . 'target it.');
    }

    /**
     * THE INERT INLINE OFFSET IS GONE. It did nothing while nothing positioned the element, and an
     * inline declaration beats any stylesheet rule short of `!important` -- so it would have begun
     * fighting the theme's own container the moment anything positioned it.
     */
    public function test_the_hook_has_no_inline_style(): void
    {
        $hook = $this->readWithoutBladeComments(self::HOOK);

        // The docblock discusses the old `top: 40px`; only an ATTRIBUTE on an element is a finding.
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*\sstyle=/', $hook,
            'The wrapper carries an inline style again. Express the offset in CSS, once, or it '
            . 'cannot be overridden by a theme or mirrored for Arabic.');
    }

    /**
     * EVERY ASSERTION IS SCOPED TO THE BLOCK, and that is not tidiness.
     *
     * The first version searched the whole 7,000-line stylesheet, and a mutation dropping this
     * wrapper's target size from 2.75rem to 1rem SURVIVED -- because `min-height: 2.75rem` appears
     * elsewhere in the design system for another component, so the assertion was satisfied by a
     * rule it was not about. A census over a whole file answers "does this string exist anywhere",
     * which is a different question from "does this component do it".
     */
    public function test_the_design_system_styles_the_wrapper(): void
    {
        $block = $this->wrapperBlock();

        foreach (['--ss-bg-elevated', '--ss-fg', '--ss-radius-md', '--ss-space-2'] as $token) {
            $this->assertStringContainsString($token, $block,
                'The wrapper does not use the ' . $token . ' token, so it will not follow dark '
                . 'mode -- every colour on this surface must be a token because the dark values '
                . 'are re-declared under [data-ss-theme="dark"] and nowhere else.');
        }

        $this->assertStringContainsString('min-height: 2.75rem', $block,
            'The action targets are smaller than the 44px WCAG 2.5.5 minimum, which is what makes '
            . 'them usable on a phone.');

        $this->assertStringContainsString('min-width: 2.75rem', $block);

        $this->assertStringContainsString('focus-visible', $block,
            'The actions have no visible focus ring, so a keyboard shopper cannot see where they '
            . 'are.');
    }

    /**
     * The `.ss-product-actions` rules ONLY, comment-free, so no assertion can be satisfied by
     * another component's rule or by the prose describing this one.
     */
    private function wrapperBlock(): string
    {
        $this->assertStringContainsString('PRODUCT-CARD CONVERSION ACTIONS', $this->read(self::DS),
            'The GAP-220 block is gone from the design system.');

        $css = $this->readWithoutCssComments(self::DS);

        $start = strpos($css, '.ss-product-actions {');

        $this->assertNotFalse($start, 'The `.ss-product-actions` rule is gone, which is the '
            . 'GAP-220 state: the buttons render as bare unspaced icons wherever the hook is called.');

        return substr($css, $start);
    }

    /**
     * THE STYLING MUST NOT POSITION THE WRAPPER OR HIDE IT.
     *
     * Both were in a first draft and both were wrong. The assertions are scoped to the
     * `.ss-product-actions` block so they say nothing about the rest of a 7,000-line stylesheet.
     */
    public function test_the_wrapper_block_neither_positions_nor_hides_itself(): void
    {
        $block = $this->wrapperBlock();

        $this->assertStringNotContainsString('position: absolute', $block,
            'The wrapper positions itself. techzonix and stylique already place the hook inside '
            . 'their own `absolute` containers, so this would fight theme markup.');

        $this->assertStringNotContainsString('position: relative', $block,
            'Something gives a parent a containing block. techzonix\'s `.pro-btn-wrapper` is '
            . '`absolute`, and `relative` would override it and drop the buttons out of the corner.');

        $this->assertStringNotContainsString('hover: hover', $block,
            'The actions are hidden behind a hover query. A keyboard user never hovers and a touch '
            . 'device has no hover state, so a conversion action gated on hover is unreachable for '
            . 'both.');

        $this->assertStringNotContainsString('visibility: hidden', $block);
        $this->assertStringNotContainsString('display: none', $block);

        /*
         * LOGICAL PROPERTIES ONLY. The storefront is bilingual and the card mirrors in Arabic;
         * `StorefrontAppearanceFoundationTest` pins the same convention for the appearance control.
         * Matched on a declaration (`property:`) so the prose above the rules is not a finding.
         */
        foreach (['right:', 'left:'] as $physical) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*' . preg_quote($physical, '/') . '/m',
                $block,
                'The wrapper block declares the physical property ' . $physical . ' -- use the '
                . 'logical equivalent so the card mirrors in Arabic.'
            );
        }
    }

    /**
     * EVERY THEME'S PRODUCT CARD CALLS THE HOOK. greentic's did not, so its shoppers had no quick
     * view and no compare at all -- not a styling gap, an absent feature.
     */
    public function test_every_theme_product_card_calls_the_hook(): void
    {
        foreach (self::THEMES as $theme) {
            $card = $this->readWithoutBladeComments("themes/{$theme}/views/components/product-card.blade.php");

            $calls = preg_match_all('/Product::actionLinks\s*\(/', $card);

            $this->assertSame(1, $calls, sprintf(
                "%s's product card calls Product::actionLinks() %d times; it must be exactly once. "
                . 'Zero is the GAP-220 state (the actions do not exist for that theme); more than '
                . 'once renders the buttons twice.',
                $theme,
                $calls
            ));
        }
    }

    /**
     * NO THEME NESTS THE HOOK INSIDE AN `<a>`.
     *
     * techzonix did. The module buttons are themselves links and buttons, so they were interactive
     * content inside a link: invalid HTML, and activating one could follow the outer product link
     * instead -- a shopper pressing "compare" lands on the product page.
     *
     * The check walks the card from the call BACKWARDS, counting anchor opens against closes. A
     * positive balance means the call sits inside an anchor that has not been closed.
     */
    public function test_no_theme_nests_the_actions_inside_an_anchor(): void
    {
        foreach (self::THEMES as $theme) {
            $card = $this->readWithoutBladeComments("themes/{$theme}/views/components/product-card.blade.php");

            $at = strpos($card, 'Product::actionLinks');

            $this->assertNotFalse($at, $theme . "'s card does not call the hook at all.");

            $before = substr($card, 0, $at);

            /*
             * THE NEAREST PRECEDING ANCHOR TAG DECIDES. Counting opens against closes does not
             * work on Blade: a card can open an `<a>` in one branch of an `@if` and close it in
             * another, so the running total is meaningless in source order. If the last anchor tag
             * before the call is an OPEN one, the call is inside it.
             */
            $lastOpen = max(
                (int) strrpos($before, '<a '),
                (int) strrpos($before, "<a\n"),
                (int) strrpos($before, '<a>')
            );
            $lastClose = (int) strrpos($before, '</a>');

            $this->assertLessThanOrEqual($lastClose, $lastOpen, sprintf(
                "%s's product card emits the conversion actions INSIDE an open <a> (last open at "
                . 'offset %d, last close at %d). The module buttons are links and buttons '
                . 'themselves, so that is interactive content inside a link: invalid HTML, and '
                . 'activating one can follow the product link instead -- a shopper pressing '
                . '"compare" lands on the product page.',
                $theme,
                $lastOpen,
                $lastClose
            ));
        }
    }

    /**
     * THE SUITE'S OWN BLIND SPOT, recorded as an assertion so it cannot be forgotten.
     *
     * The four buttons come from vendored modules that load in NO test run unless an
     * `add_on_managers` row is inserted (CLAUDE.md). So nothing in this file proves a button
     * RENDERS -- only that every structural precondition holds. If that ever changes, this test
     * fails and whoever is reading should replace this census with a real render test.
     */
    public function test_the_premise_that_the_module_buttons_are_still_module_gated(): void
    {
        $hook = $this->read(self::HOOK);

        foreach (['ProductQuickView', 'ProductCompare', 'ProductsPDF', 'ProductCSV'] as $module) {
            $this->assertStringContainsString("module_is_active('{$module}')", $hook, sprintf(
                'The hook no longer gates %s on `module_is_active()`. If these buttons are now '
                . 'first-party, the suite can finally render them -- replace this source census '
                . 'with a test that asserts the rendered markup and the accessible names.',
                $module
            ));
        }
    }
}

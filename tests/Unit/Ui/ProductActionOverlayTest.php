<?php

namespace Tests\Unit\Ui;

use DOMDocument;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExceptionInterface as CssSelectorException;
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
 *  6. THE CHILD RULE REACHES ONLY RENDERED CHILDREN. It was `.ss-product-actions > *`, and FIVE OF
 *     THE HOOK'S NINE ELEMENT CHILDREN ARE A `<style>` OR A `<script>`: an author `display`
 *     outranks the user-agent rule that keeps those out of the box tree, so the four modules' own
 *     CSS and JavaScript rendered as visible text over the product image. Measured 2026-11-25 in
 *     headless Chrome — see `test_the_design_system_styles_the_wrapper`, which now evaluates the
 *     selector against a DOM rather than reading it as a string.
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

    /**
     * THE HOOK'S ELEMENT CHILDREN, IN ORDER, AS MEASURED -- and five of the nine are not rendered.
     *
     * This is the sequence the four modules really produce, read out of a browser rather than
     * inferred: `button, style, script, style, button, script, button, style, a`. Measured
     * 2026-11-25 in headless Chrome against `public/css/store-syria-ds.css` plus the four modules'
     * own `src/resources/views/pages/button.blade.php` markup composed in the order
     * `action_link.blade.php` includes them, because per CLAUDE.md those modules load in NO test
     * run unless an `add_on_managers` row is inserted, so the suite cannot render them itself.
     *
     * Reading the four module files gives the same answer and says why:
     *   - `ProductQuickView` -- `<button class="quickview-btn">`, then a `<style>`, then an
     *     `@once` `<script>`.
     *   - `ProductCompare`   -- a `<style>`, then `<button id="compareButton" class="compare-btn">`,
     *     then an `@once` `<script>`.
     *   - `ProductsPDF`      -- `<button class="product-pdf-btn">` and nothing else.
     *   - `ProductCSV`       -- a `<style>`, then `<a class="csv-btn export-btn">`.
     *
     * WHAT WENT WRONG WITH `> *`: `display` in the design system is an AUTHOR declaration and the
     * `display: none` that keeps a `<style>` and a `<script>` out of the box tree is a USER-AGENT
     * one, so the author rule won. All five non-rendered children computed `display: flex`
     * (`inline-flex` blockifies inside a flex container), each took a 288px-wide box, and 3,433
     * characters of the modules' CSS and JavaScript painted as visible text over the product
     * image -- the wrapper measured 304x2384 inside a 322x322 card. With the selector scoped they
     * compute `display: none` at 0x0 and the wrapper measures 60x60.
     */
    private const MEASURED_CHILDREN = [
        ['button', 'quickview-btn'],
        ['style', null],
        ['script', null],
        ['style', null],
        ['button', 'compare-btn'],
        ['script', null],
        ['button', 'product-pdf-btn'],
        ['style', null],
        ['a', 'csv-btn export-btn'],
    ];

    /**
     * Element types a browser gives no box to, so a rule that reaches one can only ever make its
     * source text visible.
     *
     * THE LIST IS WIDER THAN WHAT THE FOUR MODULES EMIT TODAY, DELIBERATELY. The defect was a
     * selector that described what to skip instead of what to style, and an exclusion list is only
     * ever complete for the modules that already exist. `<style>` and `<script>` are the ones
     * measured in this hook; `<template>`, `<link>`, `<meta>`, `<noscript>`, `<title>` and `<base>`
     * are what a fifth module could reasonably add, and a selector that cannot reach any of them
     * is one the next module cannot break. (`<noscript>` is `display: none` only while scripting
     * is enabled, which is the storefront's case -- every one of these modules needs jQuery.)
     */
    private const NEVER_RENDERED = [
        'style', 'script', 'template', 'link', 'meta', 'noscript', 'title', 'base',
    ];

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

        /*
         * THE TOKEN MUST BE DECLARED, NOT MERELY NAMED, AND THIS CASE USED TO ASSERT ONLY THE NAME.
         *
         * It listed `--ss-bg-elevated`, which was DECLARED NOWHERE IN THE REPOSITORY -- so this
         * assertion passed on a token that does not exist, while `var(--ss-bg-elevated, #ffffff)`
         * painted the literal white fallback in dark mode and the conversion actions measured
         * **1.21:1**, i.e. invisible. The case's own message said the colour "must be a token
         * because the dark values are re-declared under [data-ss-theme=dark]"; that was true of
         * `--ss-fg` and false of the one it was checking. A name is not a declaration, and the
         * difference is the whole defect.
         *
         * The same block named `--ss-focus` for its ring, also undeclared, and the two MASKED each
         * other: with the surface wrongly white, the hard-coded `#2563eb` fallback measured 5.17:1
         * and passed, so correcting the surface alone would have dropped the ring to 2.75:1 on
         * `#262b33` -- below WCAG 1.4.11's 3:1. Both are now the declared tokens
         * (`--ss-surface` 11.81:1, `--ss-focus-ring` 7.60:1).
         *
         * `DesignSystemTokensAreDeclaredTest` is the general ratchet over every `var(--ss-*)` in the
         * design system; this case asks the narrower question it exists for -- that THIS block uses
         * tokens rather than hexes -- and now asks it in a form a nonexistent token cannot satisfy.
         */
        $declarations = $this->readWithoutCssComments(self::DS);

        foreach (['--ss-surface', '--ss-fg', '--ss-focus-ring', '--ss-radius-md', '--ss-space-2'] as $token) {
            $this->assertStringContainsString($token, $block,
                'The wrapper does not use the ' . $token . ' token, so it will not follow dark '
                . 'mode -- every colour on this surface must be a token because the dark values '
                . 'are re-declared under [data-ss-theme="dark"] and nowhere else.');

            $this->assertMatchesRegularExpression('/' . preg_quote($token, '/') . '\s*:/', $declarations, sprintf(
                '`%s` is used on this block and DECLARED NOWHERE, so the hard-coded fallback paints '
                . 'in every theme and the surface never switches. That is exactly how this block '
                . 'reached 1.21:1 in dark mode while this very case was green.',
                $token
            ));
        }

        $this->assertStringContainsString('min-height: 2.75rem', $block,
            'The action targets are smaller than the 44px WCAG 2.5.5 minimum, which is what makes '
            . 'them usable on a phone.');

        $this->assertStringContainsString('min-width: 2.75rem', $block);

        $this->assertStringContainsString('focus-visible', $block,
            'The actions have no visible focus ring, so a keyboard shopper cannot see where they '
            . 'are.');

        /*
         * AND THE CHILD RULE REACHES ONLY RENDERED CHILDREN. IT DID NOT, AND THE MODULES' OWN
         * SOURCE CODE RENDERED AS TEXT ON THE PRODUCT CARD.
         *
         * The rule was `.ss-product-actions > *` with `display: inline-flex` on it. Five of the
         * hook's nine element children are a `<style>` or a `<script>` (`MEASURED_CHILDREN` has
         * the sequence and how it was measured), an author `display` outranks the user-agent
         * `display: none` that keeps those out of the box tree, and so 3,433 characters of the
         * four modules' CSS and JavaScript painted over the product image -- `quickview-btn {
         * position: absol...` across the photo, the wrapper 304x2384 inside a 322x322 card.
         *
         * WHY THIS IS EVALUATED AND NOT READ. The suite cannot render these modules at all
         * (CLAUDE.md: no WorkDo module loads without an `add_on_managers` row), and a string
         * assertion cannot tell `> *` from `> a, > button` from `> :not(style):not(script)` --
         * the whole difference between them is what they MATCH. So the selector is run against a
         * document built in the measured child order, and the assertion is about the node set.
         *
         * THE PROBE IS WIDER THAN TODAY'S FOUR MODULES, which is the other half of the fix. An
         * exclusion list is complete only for the markup that already exists, so the probe also
         * holds a `<template>`, a `<link>`, a `<meta>`, a `<noscript>`, a `<title>` and a `<base>`
         * -- a selector that reaches none of those is one a fifth module in this hook cannot
         * break, and `> :not(style):not(script)` would fail here rather than pass.
         */
        $childRules = $this->childRules();

        $this->assertNotSame([], $childRules,
            'The `.ss-product-actions` block has no child rule at all, so the 44px WCAG 2.5.5 '
            . 'floor is carried by nothing. The modules size `.quickview-btn`, `.compare-btn` and '
            . '`.csv-btn` at 36x36 themselves and give `.product-pdf-btn` no stylesheet at all, so '
            . 'this is the only rule that puts any of them over the minimum. Scope the child rule; '
            . 'do not delete it.');

        [$xpath, $actions, $nonRendered] = $this->probeDom();

        $floorReaches = [];

        foreach ($childRules as $selector => $body) {
            $reached = $this->selects($xpath, $selector);

            $leaked = array_values(array_intersect_key($nonRendered, array_flip($reached)));

            $this->assertSame([], $leaked, sprintf(
                "The child rule `%s` reaches %d element(s) a browser renders no box for: %s. It "
                . "declares `display`, which is an AUTHOR declaration and beats the user-agent "
                . "`display: none` on those elements -- so their SOURCE TEXT paints on the product "
                . "card. That is exactly what `> *` did: measured 2026-11-25, 3,433 characters of "
                . "the four modules' CSS and JavaScript over the product image, wrapper 304x2384 "
                . "inside a 322x322 card. Select the children by what they ARE (a link, a button, "
                . "or something carrying one of those roles) rather than by what they are not; an "
                . "exclusion list only covers the modules that exist today.",
                $selector,
                count($leaked),
                implode(', ', $leaked)
            ));

            if (str_contains($body, 'min-width: 2.75rem') && str_contains($body, 'min-height: 2.75rem')) {
                $floorReaches = array_merge($floorReaches, $reached);
            }
        }

        /*
         * AND THE 44px FLOOR STILL APPLIES TO EVERY REAL BUTTON. This is the half that scoping a
         * selector can quietly destroy, and the block-level `min-height: 2.75rem` assertion above
         * cannot see it: that one answers "does the string exist in this block", which stays true
         * if the rule carrying it has been narrowed to something none of the four modules emit.
         * CLAUDE.md records a mutation dropping this very target size SURVIVING because another
         * component in the same 7,000-line stylesheet had the same rule. This asks the question
         * that mutation could not satisfy: does the rule that declares the floor select each of
         * the four action elements the modules really produce.
         */
        $unprotected = array_values(array_intersect_key($actions, array_flip(
            array_values(array_diff(array_keys($actions), $floorReaches))
        )));

        $this->assertSame([], $unprotected, sprintf(
            'No rule declaring BOTH `min-width: 2.75rem` and `min-height: 2.75rem` reaches %d of '
            . "the hook's real action elements: %s. 44px is WCAG 2.5.5 and is the whole of the "
            . '"usable on a phone" requirement for this row. Nothing else supplies it: the modules '
            . 'size `.quickview-btn`, `.compare-btn` and `.csv-btn` at 36x36 in their own CSS and '
            . 'give `.product-pdf-btn` no stylesheet at all. The CSV action is an `<a>`, so the '
            . 'floor reaches it only while something reinstates a flex or block display, which is '
            . 'the `display` in this same rule. Narrowing the child selector must not drop any of '
            . 'them.',
            count($unprotected),
            implode(', ', $unprotected)
        ));
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
     * THE COMPONENT'S CHILD RULES ONLY, as `selector => body`, comment-free.
     *
     * Every rule inside the block whose selector names `.ss-product-actions` AND uses the child
     * combinator. This is NARROWER than `wrapperBlock()` on purpose: that helper runs from the
     * component's first rule to the end of the file, which is exact only while this component
     * happens to be the last thing in the stylesheet. Requiring the component's own class in the
     * selector keeps the assertions below about THIS component whatever gets appended under it --
     * the same reason the rest of this file is scoped to the block at all.
     */
    private function childRules(): array
    {
        preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $this->wrapperBlock(), $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $match[1]));

            if (! str_contains($selector, '.ss-product-actions') || ! str_contains($selector, '>')) {
                continue;
            }

            $rules[$selector] = $match[2];
        }

        return $rules;
    }

    /**
     * A REAL DOM IN THE MEASURED CHILD ORDER, so the selector can be EVALUATED rather than read.
     *
     * A string assertion was never going to hold this: `> *`, `> :not(style):not(script)` and a
     * list of element types are three spellings whose difference is in what they MATCH, and the
     * only honest way to ask "can this reach a `<style>`" is to put a `<style>` in a document and
     * run the selector. The nodes are built with `createElement` rather than parsed from HTML
     * because libxml's HTML parser relocates `<style>` and `<link>` into `<head>`, which would
     * quietly empty the probe and make every assertion below pass for the wrong reason.
     *
     * Returns `[$xpath, $actions, $nonRendered]`, the two maps keyed by the probe id and valued
     * with a readable `tag.class` for the failure message.
     */
    private function probeDom(): array
    {
        $children = self::MEASURED_CHILDREN;

        // Plus one of every non-rendered type not already in the measured sequence: the point of
        // the fix is that an UNFORESEEN one cannot be reached either.
        foreach (self::NEVER_RENDERED as $tag) {
            if (! in_array($tag, array_column($children, 0), true)) {
                $children[] = [$tag, null];
            }
        }

        $dom = new DOMDocument();
        $html = $dom->createElement('html');
        $body = $dom->createElement('body');

        // The hook's own wrapper, both classes, exactly as `action_link.blade.php` emits it.
        $wrapper = $dom->createElement('div');
        $wrapper->setAttribute('class', 'ss-product-actions supdiv');

        $actions = [];
        $nonRendered = [];

        foreach ($children as $i => $child) {
            [$tag, $class] = $child;

            $element = $dom->createElement($tag);
            $element->setAttribute('id', 'probe' . $i);

            if ($class !== null) {
                $element->setAttribute('class', $class);
            }

            $wrapper->appendChild($element);

            $label = $tag . ($class === null ? '' : '.' . str_replace(' ', '.', $class));

            if (in_array($tag, self::NEVER_RENDERED, true)) {
                $nonRendered['probe' . $i] = $label;
            } else {
                $actions['probe' . $i] = $label;
            }
        }

        $body->appendChild($wrapper);
        $html->appendChild($body);
        $dom->appendChild($html);

        return [new DOMXPath($dom), $actions, $nonRendered];
    }

    /**
     * The probe ids one CSS selector actually selects.
     *
     * STATE PSEUDO-CLASSES ARE DROPPED FIRST, and that is sound rather than convenient: removing
     * `:focus-visible` from a selector can only WIDEN the set of elements it matches, so what is
     * measured here is an over-approximation of what the real selector reaches. For the question
     * "can this reach a `<style>`" that is the conservative direction -- if the wider form cannot,
     * the real one cannot either. A conversion with no answer FAILS rather than skips, because a
     * selector this helper cannot evaluate is a decision for a human, not a silent pass.
     */
    private function selects(DOMXPath $xpath, string $selector): array
    {
        $widened = (string) preg_replace(
            '/:(?:hover|focus|focus-visible|focus-within|active|visited|any-link|target|disabled|enabled|checked)\b/',
            '',
            $selector
        );

        try {
            $expression = (new CssSelectorConverter())->toXPath($widened);
        } catch (CssSelectorException $e) {
            $this->fail(sprintf(
                "The child selector `%s` cannot be evaluated (%s), so this case can no longer "
                . 'answer whether it reaches a <style> or a <script> -- which is the defect it '
                . 'exists for. Teach `selects()` the new construct deliberately; do not delete '
                . 'the assertion.',
                $selector,
                $e->getMessage()
            ));
        }

        $found = $xpath->query($expression);

        $this->assertNotFalse($found, 'XPath for `' . $selector . '` did not evaluate: ' . $expression);

        $ids = [];

        foreach ($found as $node) {
            $ids[] = $node->getAttribute('id');
        }

        return $ids;
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

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-256 / P03-B-07 — THE SHAPE OF THE CONVERSION-ACTION REACHABILITY FIX, PINNED.
 * =================================================================================================
 *
 * WHAT THIS FILE IS AND IS NOT. The behaviour — Tab reaching the button, the focus reveal, the
 * touch branch, the icon contrast — is measured in a real Chromium by
 * `tests/Support/bin/check-product-action-keyboard-live.cjs`, against a page rendered out of
 * process by `tests/Feature/Storefront/ProductActionKeyboardReachTest.php` with the four WorkDo
 * conversion modules really loaded. None of that can live in the suite: per CLAUDE.md no module
 * loads in a test run without an `add_on_managers` row, and loading one in-process pollutes later
 * tests through the process-global Composer `ClassLoader`.
 *
 * What CAN live in the suite is the SHAPE of the fix, and the shape is where the traps are. Every
 * assertion below exists because the opposite was true, or nearly was:
 *
 *  1. THE RESTING STATE MUST RESTORE `visibility`, UNCONDITIONALLY. `visibility: hidden` removes an
 *     element from the tab order, so a reveal keyed on `:focus`, `:focus-visible` or
 *     `:focus-within` is DEAD CODE — focus can never arrive. A future tidy-up that folds the
 *     resting rule into a focus selector would silently reinstate the WCAG 2.1.1 failure while
 *     looking like a simplification, and nothing visual would change for the person doing it.
 *  2. THE RESTING STATE MUST ALSO REMOVE POINTER EVENTS. `visibility: hidden` took the button out
 *     of hit testing as well; `opacity: 0` does not. Lifting visibility without replacing that
 *     leaves a 44x44 invisible control at `z-index: 2` over the product image — measured: a click
 *     at its centre would have hit the button instead of the photo.
 *  3. AND EVERY REVEAL MUST PUT POINTER EVENTS BACK, for all three ancestor classes the modules
 *     themselves use. This is the half the register row warned about: the modules' own hover rules
 *     restore `opacity`, `visibility` and `transform` and nothing else, so a bare
 *     `pointer-events: none` would have broken the mouse.
 *  4. THE ALWAYS-VISIBLE BUTTON MUST NOT BE TOUCHED. `ProductsPDF` ships no stylesheet and is the
 *     one of the four a keyboard already reached. A fix written as "every child of the wrapper"
 *     would have hidden it — a regression in the middle of an accessibility fix.
 *  5. NO COMMENT IN THE LAYER MAY CLOSE EARLY, AND THIS ONE IS NOT HYPOTHETICAL. The first draft of
 *     section 6 described the mirrored ancestor classes with a real glob containing a comment
 *     terminator. The comment ended mid-sentence, the remaining prose became live CSS, and
 *     Chromium's error recovery consumed the next qualified rule it found — rule 6a, the resting
 *     state, the one declaration the whole fix depends on. Openers and terminators still balanced
 *     17/17, braces still balanced 34/34, and the live browser measurement reported the three
 *     buttons still `visibility: hidden`, which reads exactly like "the override does not win on
 *     specificity". It won fine. It was not there. CLAUDE.md records this failure mode for Blade
 *     and for censuses that read comments; this was the same mistake one layer down, where the
 *     prose about a fix deleted the fix.
 *  6. THE OVERRIDE MUST WIN ON SPECIFICITY, NOT SOURCE ORDER. The modules' `<style>` blocks render
 *     in the BODY and this sheet is linked in `<head>`, so at equal specificity the module wins.
 *     Computed here rather than asserted.
 *
 * COMMENTS ARE STRIPPED BEFORE ANY SEARCH, parser-faithfully — `/* ... *\/` left to right, first
 * terminator wins, which is what a browser does and what produced defect 5. And every assertion is
 * SCOPED to the rule it is about, found by its selector list, never by searching a 365-line
 * stylesheet for a string: CLAUDE.md records a mutation that survived because another component
 * carried the same declaration.
 */
final class ProductActionKeyboardReachCssTest extends TestCase
{
    private const LAYER = 'public/css/storefront-appearance.css';

    /** The three buttons whose own module stylesheet takes them out of the tab order. */
    private const SUBJECTS = ['quickview-btn', 'compare-btn', 'csv-btn'];

    /** The ancestor classes the three modules' own `:hover` rules name. */
    private const ANCESTORS = ['product-box', 'product-item', 'product-card'];

    /** The fourth button: visible today, and it must stay out of every rule below. */
    private const ALWAYS_VISIBLE = 'product-pdf-btn';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function raw(string $relative): string
    {
        $path = self::root() . '/' . $relative;

        $this->assertFileExists($path, $relative . ' is gone; re-point this census rather than deleting it.');

        return (string) file_get_contents($path);
    }

    /**
     * The stylesheet's CODE, with comments removed the way a CSS PARSER removes them.
     *
     * The pattern below is NON-GREEDY and left-to-right, so the FIRST terminator closes the
     * comment — identical to a browser, which is the whole point. A greedy or "balanced" stripper
     * would hide defect 5 by being cleverer than the thing it models.
     *
     * AND THIS DOCBLOCK HIT THE SAME TRAP WHILE BEING WRITTEN. Quoting the pattern verbatim put a
     * comment terminator inside a PHP docblock, which closed it mid-sentence and made the file a
     * syntax error — the third instance in this one change, after the CSS comment that ate rule 6a.
     * Three languages, one mistake: prose about a delimiter must not contain the delimiter.
     */
    private function live(string $relative): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->raw($relative));
    }

    /**
     * Every rule in the live stylesheet as `['selectors' => list<string>, 'decls' => string,
     * 'media' => ?string]`, one level of `@media` nesting, which is all this file uses.
     *
     * Parsing rather than string-searching is what makes the assertions below SCOPED: each one
     * names the rule it is about by its selector list and reads only that rule's declarations.
     *
     * @return list<array{selectors: list<string>, decls: string, media: ?string}>
     */
    private function rules(string $css): array
    {
        $out = [];
        $len = strlen($css);
        $i = 0;
        $media = null;
        $mediaEnd = null;

        while ($i < $len) {
            /*
             * SKIP WHITESPACE AND BLOCK TERMINATORS FIRST, and reset the media context on the one
             * that closes the at-rule.
             *
             * The first version of this parser did not, and the consequence was not a crash: the
             * `}` closing section 5's `@media (prefers-color-scheme: dark)` block was swept into
             * the NEXT rule's prelude, so the unconditional resting rule parsed with the selector
             * `} .ss-product-actions .quickview-btn` and inherited section 5's media context. Four
             * assertions then failed reporting that the fix was missing — while it was there and
             * measurably working in the browser. A parser that is wrong in this direction invents
             * defects; one that is wrong in the other hides them.
             */
            while ($i < $len && (ctype_space($css[$i]) || $css[$i] === '}')) {
                if ($css[$i] === '}' && $mediaEnd !== null && $i === $mediaEnd - 1) {
                    $media = null;
                    $mediaEnd = null;
                }

                $i++;
            }

            if ($i >= $len) {
                break;
            }

            $brace = strpos($css, '{', $i);

            if ($brace === false) {
                break;
            }

            $prelude = trim(substr($css, $i, $brace - $i));

            if (str_starts_with($prelude, '@')) {
                /* An at-rule with a block: record its condition and descend one level. */
                $depth = 1;
                $j = $brace + 1;

                while ($j < $len && $depth > 0) {
                    if ($css[$j] === '{') {
                        $depth++;
                    } elseif ($css[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }

                $media = $prelude;
                $mediaEnd = $j;
                $i = $brace + 1;

                continue;
            }

            $close = strpos($css, '}', $brace);

            if ($close === false) {
                break;
            }

            $out[] = [
                'selectors' => array_values(array_filter(array_map('trim', explode(',', $prelude)))),
                'decls' => trim(substr($css, $brace + 1, $close - $brace - 1)),
                'media' => $media,
            ];

            $i = $close + 1;
        }

        return $out;
    }

    /** The rule whose selector list is exactly this set, in the given media context. */
    private function ruleFor(array $selectors, ?string $media = null): ?array
    {
        foreach ($this->rules($this->live(self::LAYER)) as $rule) {
            if ($rule['media'] !== null && $media === null) {
                continue;
            }
            if ($media !== null && ($rule['media'] === null || ! str_contains($rule['media'], $media))) {
                continue;
            }
            if ($rule['selectors'] === $selectors) {
                return $rule;
            }
        }

        return null;
    }

    /** `.ss-product-actions .<cls>` for each subject, in order — the resting rule's selector list. */
    private function restingSelectors(): array
    {
        return array_map(static fn ($c) => '.ss-product-actions .' . $c, self::SUBJECTS);
    }

    private function assertDeclares(array $rule, string $property, string $value, string $why): void
    {
        $this->assertMatchesRegularExpression(
            '/(^|;)\s*' . preg_quote($property, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*(;|$)/',
            $rule['decls'],
            $why . "\nThe rule's declarations are:\n  " . str_replace("\n", "\n  ", $rule['decls'])
        );
    }

    /* ============================================================ 5. the comment that ate a rule */

    /**
     * NO COMMENT IN THE LAYER CLOSES EARLY.
     *
     * The invariant is exact: a sequential parse must consume as many comments as there are `/*`
     * openers. When a comment closes early, its INTENDED terminator is left over as an orphan and
     * the walk consumes fewer comments than there are openers — which is precisely what the broken
     * draft looked like (17 openers, 17 terminators, 16 comments).
     *
     * Counting openers against terminators does NOT catch it, and that is the point: both were 17.
     */
    #[Test]
    public function test_no_comment_in_the_layer_closes_early(): void
    {
        $css = $this->raw(self::LAYER);
        $openers = substr_count($css, '/*');
        $parsed = 0;
        $i = 0;

        while (($a = strpos($css, '/*', $i)) !== false) {
            $b = strpos($css, '*/', $a + 2);

            $this->assertNotFalse($b,
                'A comment opened at line ' . (substr_count(substr($css, 0, $a), "\n") + 1)
                . ' and is never terminated, so every rule after it is invisible to the browser.');

            $parsed++;
            $i = $b + 2;
        }

        $this->assertSame($openers, $parsed,
            "The layer has {$openers} `/*` openers but a parser consumes only {$parsed} comments, so "
            . "at least one comment closes EARLY and the prose after it is live CSS. This has "
            . "happened here once already: a glob containing a comment terminator inside section 6's "
            . "docblock closed it mid-sentence, and Chromium's error recovery then swallowed rule 6a "
            . '— the resting state, the whole fix. Openers and terminators balanced, braces '
            . 'balanced, and the only symptom was a live measurement that looked like a specificity '
            . 'failure.');
    }

    /**
     * AND NO COMMENT PROSE HAS LEAKED INTO THE LIVE STYLESHEET.
     *
     * A backtick is the canary: this repository's stylesheet comments are full of them (they quote
     * selectors, tokens and file paths) and CSS has no syntax that uses one. So a backtick outside
     * a comment means prose is being parsed as CSS — whatever the cause, including an opener that
     * was never a comment at all.
     */
    #[Test]
    public function test_no_comment_prose_leaks_into_the_live_stylesheet(): void
    {
        $live = $this->live(self::LAYER);

        $this->assertStringNotContainsString('`', $live,
            'The comment-stripped stylesheet still contains a backtick, so prose that was meant to '
            . 'be a comment is being parsed as CSS. Leaked text near: '
            . substr($live, max(0, strpos($live, '`') - 120), 240));
    }

    /* ======================================================= 1 & 2. the resting state */

    /**
     * THE RESTING RULE EXISTS, IS UNCONDITIONAL, AND RESTORES `visibility`.
     *
     * "Unconditional" is asserted by looking it up with NO media context and by the next test
     * checking its selectors carry no state pseudo-class. A `visibility: visible` that only applied
     * under `:focus` or `@media (hover: hover)` would be the dead code this gap is made of.
     */
    #[Test]
    public function test_the_resting_rule_restores_visibility_for_all_three_buttons(): void
    {
        $rule = $this->ruleFor($this->restingSelectors());

        $this->assertNotNull($rule,
            'There is no unconditional rule in ' . self::LAYER . ' whose selector list is exactly '
            . implode(', ', $this->restingSelectors()) . '. That rule IS the GAP-256 fix: '
            . '`visibility: hidden` from the modules takes these three buttons out of the tab order, '
            . 'and nothing but an unconditional `visibility: visible` puts them back.');

        $this->assertDeclares($rule, 'visibility', 'visible',
            'The resting rule does not declare `visibility: visible`. Everything else in this fix is '
            . 'cosmetic; this is the declaration that makes the three conversion actions focusable '
            . 'at all (WCAG 2.1.1, Level A).');

        $this->assertDeclares($rule, 'opacity', '0',
            'The resting rule does not keep the buttons unpainted. The modules hid them at rest and '
            . 'whether a storefront card SHOULD show its conversion actions at rest is a design '
            . 'decision this fix deliberately does not take.');

        $this->assertDeclares($rule, 'pointer-events', 'none',
            '`visibility: hidden` took these buttons out of HIT TESTING as well as out of the tab '
            . 'order. `opacity: 0` does not. Without `pointer-events: none` the fix leaves a 44x44 '
            . 'invisible control at z-index 2 over the product image, and a mouse user clicking the '
            . 'photo fires Quick view, Compare, or a CSV download.');
    }

    /**
     * THE RESTING RULE IS REACHED BY NO STATE AT ALL.
     *
     * This is the assertion that fails if someone "simplifies" the fix into a focus-driven one.
     */
    #[Test]
    public function test_the_resting_rule_depends_on_no_hover_or_focus_state(): void
    {
        $rule = $this->ruleFor($this->restingSelectors());

        $this->assertNotNull($rule, 'The resting rule is gone; see the test above.');
        $this->assertNull($rule['media'],
            'The resting rule sits inside `' . (string) $rule['media'] . '`. A conditional '
            . 'reachability fix is not one: the tab order must be restored for every device.');

        foreach ($rule['selectors'] as $selector) {
            $this->assertDoesNotMatchRegularExpression('/:(hover|focus|focus-visible|focus-within|active)\b/', $selector,
                "The resting selector `{$selector}` is gated on a state. Focus CANNOT enter an "
                . 'element that `visibility: hidden` has removed from the tab order, so a '
                . '`:focus`-reveal of a hidden control never fires — it is dead code that reads '
                . 'like a fix. That is the central trap in GAP-256.');
        }
    }

    /* ============================================================ 4. the control group */

    /**
     * `ProductsPDF` IS NOT TOUCHED ANYWHERE IN THE LAYER.
     *
     * It ships no stylesheet, so it is the one of the four buttons a keyboard already reached. A
     * rule written as "every interactive child of the wrapper" would have hidden it.
     */
    #[Test]
    public function test_the_always_visible_pdf_button_is_not_touched_by_the_layer(): void
    {
        /*
         * NARROWED, NOT WEAKENED, AND THE DISTINCTION IS THE WHOLE POINT. What this protects is
         * that GAP-256's REACHABILITY layer never touches the one button that was already
         * reachable: its module ships no stylesheet, so it is the control group, and a
         * reachability fix that hid it would have made things worse.
         *
         * GAP-273 then gave `.product-pdf-btn` a rule in this same file for an unrelated reason:
         * its `ti ti-printer` icon is a TABLER class that no storefront stylesheet declares, so the
         * glyph computed `content: none` and a `0 x 0` box — the control was EMPTY, not illegible,
         * and the row's "1.00:1 white-on-white" was the element's inherited colour read on an
         * element with no painted content. Section 7 supplies the glyph from the Font Awesome the
         * storefront already loads and colours the PSEUDO-ELEMENT, which the vendored inline
         * `style="color: white"` cannot reach.
         *
         * So the assertion now says what it always meant: no rule in this file may change that
         * button's VISIBILITY, OPACITY or POINTER EVENTS. A glyph is not a reachability change, and
         * the live harness proves the button's reachability is unchanged — 128 checks, 0 failed,
         * including its touch tap target and its place in the tab order.
         */
        $live = $this->live(self::LAYER);

        foreach ($this->rulesMentioning($live, self::ALWAYS_VISIBLE) as $rule) {
            foreach (['visibility', 'opacity', 'pointer-events', 'display', 'transform'] as $property) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<![-a-z])' . preg_quote($property, '/') . '\s*:/',
                    $rule,
                    'a live rule in ' . self::LAYER . ' changes `' . $property . '` for `.'
                    . self::ALWAYS_VISIBLE . '`, which is the control group for GAP-256: it is '
                    . 'visible and reachable today, and a reachability fix that hides it has made '
                    . 'things worse. The rule was: ' . trim($rule)
                );
            }
        }
    }

    /**
     * GAP-273. THE PDF GLYPH RULE, AND THE BOUND THE DARK-MODE ALLOWLIST PROMISES.
     *
     * `StorefrontDarkModeLayerTest`'s allowlist admits `.product-pdf-btn i.ti-printer::before` as
     * the first UNSCOPED rule in that stylesheet to declare a colour — the exact thing that gate
     * exists to stop, because the 1.21:1 panels it remembers were literals leaking a light value
     * into dark. The entry's argument is that this colour is a TOKEN and therefore holds no
     * appearance-specific value to leak. This is the assertion that makes the argument checkable,
     * and the allowlist entry says in terms: if this is ever removed, remove the entry with it.
     *
     * Measured through the real cascade on the rendered card, both locales, both appearances:
     *     light  rgb(91,95,102)   on rgb(255,255,255)  = 6.42:1
     *     dark   rgb(167,171,178) on rgb(38,43,51)     = 6.17:1
     * against WCAG 1.4.11's 3:1 for a non-text control. Before: `content: none`, box `0 x 0` — no
     * glyph at all, which is not what GAP-273's row records.
     */
    #[Test]
    public function test_the_pdf_glyph_rule_takes_its_ink_from_a_token(): void
    {
        $rules = $this->rulesMentioning($this->live(self::LAYER), 'i.ti-printer::before');

        $this->assertCount(1, $rules,
            'there must be exactly one live rule for the PDF glyph. ' . count($rules) . ' found.');

        $rule = $rules[0];

        /* A TOKEN, NOT A LITERAL. This is the whole basis of the allowlist entry. */
        $this->assertMatchesRegularExpression(
            '/color\s*:\s*var\(\s*--ss-[a-z-]+/',
            $rule,
            'the PDF glyph rule no longer takes its ink from a design-system token. An unscoped '
            . 'literal colour in this stylesheet is the defect the dark-mode gate exists to '
            . 'prevent, and it would reproduce GAP-273 in whichever appearance it was not written '
            . 'for. The rule was: ' . trim($rule)
        );

        $this->assertDoesNotMatchRegularExpression(
            '/color\s*:\s*(#|rgb|hsl|white|black)/i',
            $rule,
            'the PDF glyph rule declares a literal colour: ' . trim($rule)
        );

        /*
         * NO `!important`. GAP-273's row expected this repair to need one, because an inline style
         * cannot be out-specified. It does not: an inline style is a declaration for the ELEMENT
         * and not for its `::before`, so this rule wins by being the only declaration there. If an
         * `!important` ever appears here, that reasoning has been abandoned and should be re-read
         * rather than patched.
         */
        $this->assertStringNotContainsString('!important', $rule,
            'the PDF glyph rule has acquired an `!important`. It does not need one: the inline '
            . 'style it works around is a declaration for the element, not for its pseudo-element.');

        /*
         * THE CODEPOINT MUST BE THE ONE THE THEME ACTUALLY SHIPS. `.fa-print` is declared in the
         * theme's own Font Awesome as `--fa: "\f02f"`. Hard-coding a glyph that the loaded font
         * does not have would paint a replacement box, which is a defect that LOOKS like a fix.
         */
        $this->assertMatchesRegularExpression('/content\s*:\s*"\\\\f02f"/', $rule,
            'the PDF glyph rule no longer requests \f02f: ' . trim($rule));

        $theme = (string) file_get_contents(base_path('themes/stylique/assets/css/all.min.css'));

        $this->assertMatchesRegularExpression(
            '/\.fa-print\s*\{\s*--fa:\s*"\\\\f02f"/',
            $theme,
            'the theme\'s Font Awesome no longer declares `.fa-print` with that codepoint, so the '
            . 'glyph this rule requests may not exist in the font the storefront loads.'
        );

        $this->assertStringContainsString('font-family: "Font Awesome 6 Free"', $rule,
            'the rule must name the family the storefront actually loads; the vendored class is a '
            . 'Tabler one and Tabler is not loaded on the storefront at all.');
    }

    /**
     * Every live declaration block whose selector mentions $class.
     *
     * Comment-stripped by `live()` before it gets here, because this file's own prose names
     * `.product-pdf-btn` repeatedly and a census that reads comments can be satisfied — or broken
     * — by prose about the thing it measures.
     *
     * @return list<string>
     */
    private function rulesMentioning(string $live, string $class): array
    {
        $rules = [];

        if (preg_match_all('/([^{}]*)\{([^{}]*)\}/', $live, $all, PREG_SET_ORDER) === 0) {
            return $rules;
        }

        foreach ($all as $match) {
            if (str_contains($match[1], $class)) {
                $rules[] = $match[2];
            }
        }

        return $rules;
    }

    /* ============================================================ 3. every reveal */

    /**
     * EVERY REVEAL IS ONE DECLARATION BLOCK, AND IT RESTORES POINTER EVENTS.
     *
     * One block is the design decision, not a tidiness: the mouse path, the keyboard path and
     * `pointer-events` are restored together, so it is not possible to fix the keyboard and leave
     * the mouse broken, or to restore paint and forget activation.
     */
    #[Test]
    public function test_the_reveal_block_restores_paint_and_pointer_events_together(): void
    {
        $reveal = $this->revealRule();

        $this->assertDeclares($reveal, 'pointer-events', 'auto',
            'The reveal block does not put `pointer-events` back. The modules\' own hover rules '
            . 'restore only `opacity`, `visibility` and `transform`, so without this the mouse user '
            . 'sees the buttons and cannot click them — the exact failure the register row warned '
            . 'the naive fix would cause.');

        $this->assertDeclares($reveal, 'opacity', '1', 'The reveal block does not paint the button.');
        $this->assertDeclares($reveal, 'visibility', 'visible', 'The reveal block does not keep the button visible.');
        $this->assertDeclares($reveal, 'transform', 'translateX(0)',
            'The reveal block does not undo the modules\' `transform: translateX(20px)`, so a '
            . 'revealed button sits 20px off its place. `transform` is PHYSICAL, so in Arabic that '
            . 'displacement runs into the card rather than away from it.');
    }

    /**
     * THE REVEAL COVERS ALL THREE ANCESTOR CLASSES THE MODULES THEMSELVES USE, ON HOVER.
     *
     * Only `product-card` occurs in theme markup today. The other two are mirrored because the
     * MODULES name them: a host override that covered two of three would silently stop covering
     * the mouse path for any call site using the third.
     */
    #[Test]
    #[DataProvider('ancestorProvider')]
    public function test_the_reveal_mirrors_every_module_hover_ancestor(string $ancestor): void
    {
        $selectors = $this->revealRule()['selectors'];

        foreach (self::SUBJECTS as $button) {
            $this->assertContains(".{$ancestor}:hover .ss-product-actions .{$button}", $selectors,
                "The reveal block does not mirror `.{$ancestor}:hover` for `.{$button}`. The three "
                . 'modules each restore their button under `.product-box:hover`, '
                . '`.product-item:hover` AND `.product-card:hover`; an override that re-adds '
                . '`pointer-events` for only some of them breaks the mouse on the rest.');
        }
    }

    /**
     * AND THE KEYBOARD PATH IS COVERED THREE WAYS, each for a reason.
     *
     * The ancestor `:focus-within` brings the actions into view as soon as Tab reaches the product
     * link beside them; the wrapper's own `:focus-within` and the button's own `:focus` are the
     * fallbacks for a call site that carries none of the three ancestor classes.
     */
    #[Test]
    public function test_the_reveal_covers_the_keyboard_path_without_relying_on_the_ancestor(): void
    {
        $selectors = $this->revealRule()['selectors'];

        foreach (self::SUBJECTS as $button) {
            $this->assertContains(".ss-product-actions:focus-within .{$button}", $selectors,
                "The wrapper's own `:focus-within` does not reveal `.{$button}`, so a call site "
                . 'carrying none of the three ancestor classes would focus an invisible control — '
                . 'reachable but unseeable, which is WCAG 2.4.7 instead of 2.1.1.');

            $this->assertContains(".ss-product-actions .{$button}:focus", $selectors,
                "The button's own `:focus` does not reveal `.{$button}`.");
        }

        foreach (self::ANCESTORS as $ancestor) {
            foreach (self::SUBJECTS as $button) {
                $this->assertContains(".{$ancestor}:focus-within .ss-product-actions .{$button}", $selectors,
                    "`.{$ancestor}:focus-within` does not reveal `.{$button}`.");
            }
        }
    }

    /* ============================================================ the touch branch */

    /**
     * A TOUCH DEVICE HAS NO HOVER STATE, so the actions are simply present there.
     *
     * Asserted as a `(hover: none)` query specifically: `(pointer: coarse)` is a different question
     * (a stylus is coarse and hovers; a touch laptop is fine and does not) and `max-width` is not
     * the question at all.
     */
    #[Test]
    public function test_there_is_a_touch_branch_keyed_on_hover_none(): void
    {
        $rule = $this->ruleFor($this->restingSelectors(), '(hover: none)');

        $this->assertNotNull($rule,
            'There is no `@media (hover: none)` rule for the three buttons. A phone can never '
            . 'satisfy any `:hover` selector and a shopper does not press Tab on one, so without '
            . 'this branch the three conversion actions stay invisible on every touch device.');

        $this->assertDeclares($rule, 'opacity', '1', 'The touch branch does not paint the buttons.');
        $this->assertDeclares($rule, 'visibility', 'visible', 'The touch branch does not make the buttons visible.');
        $this->assertDeclares($rule, 'pointer-events', 'auto', 'The touch branch does not make the buttons tappable.');
    }

    /**
     * The modules declare `transition: all 0.3s ease` and no reduced-motion arm, and this fix gives
     * that transition a NEW trigger — focus. A 20px slide on every Tab is motion a shopper who
     * asked for less should not get.
     */
    #[Test]
    public function test_the_reveal_is_instant_under_reduced_motion(): void
    {
        $rule = $this->ruleFor($this->restingSelectors(), '(prefers-reduced-motion: reduce)');

        $this->assertNotNull($rule,
            'There is no `@media (prefers-reduced-motion: reduce)` arm for the three buttons, so a '
            . 'shopper who asked for less motion now gets a 20px slide and a fade on every Tab '
            . 'press — motion this change introduced by giving the modules\' transition a new '
            . 'trigger.');

        $this->assertDeclares($rule, 'transition', 'none', 'The reduced-motion arm does not drop the transition.');
    }

    /* ============================================================ 6. specificity, computed */

    /**
     * THE OVERRIDE BEATS THE MODULES ON SPECIFICITY, COMPUTED RATHER THAN ASSERTED.
     *
     * The modules' `<style>` blocks render in the BODY and this sheet is linked in `<head>`
     * (asserted both ways by `ProductActionKeyboardReachTest`), so at equal specificity the module
     * wins on source order and the override measures no change at all. Every selector here must
     * therefore out-rank `.quickview-btn` (0,1,0) at rest and `.product-card:hover .quickview-btn`
     * (0,3,0) on hover.
     */
    #[Test]
    public function test_every_selector_out_ranks_the_module_rule_it_overrides(): void
    {
        foreach ($this->restingSelectors() as $selector) {
            $this->assertGreaterThan(self::specificity('.quickview-btn'), self::specificity($selector),
                "The resting selector `{$selector}` does not out-rank the module's own "
                . '`.quickview-btn` (0,1,0). The module\'s rule renders LATER in the document, so at '
                . 'equal specificity it wins on source order and this override does nothing.');
        }

        $moduleHover = self::specificity('.product-card:hover .quickview-btn');

        foreach ($this->revealRule()['selectors'] as $selector) {
            if (! str_contains($selector, ':hover')) {
                continue;
            }

            $this->assertGreaterThan($moduleHover, self::specificity($selector),
                "The reveal selector `{$selector}` does not out-rank the module's own "
                . '`.product-card:hover .quickview-btn` (0,3,0).');
        }
    }

    /**
     * NO `!important` ANYWHERE IN SECTION 6, which is a claim the stylesheet makes in prose and
     * this is what makes it true. Specificity is sufficient here; the rest of this layer needs
     * `!important` only to beat Tailwind utilities injected after it, and these buttons carry no
     * utility classes.
     */
    #[Test]
    public function test_the_fix_needs_no_important(): void
    {
        $rules = array_merge(
            [$this->revealRule(), $this->ruleFor($this->restingSelectors())],
            array_filter([
                $this->ruleFor($this->restingSelectors(), '(hover: none)'),
                $this->ruleFor($this->restingSelectors(), '(prefers-reduced-motion: reduce)'),
            ])
        );

        foreach ($rules as $rule) {
            $this->assertStringNotContainsString('!important', (string) $rule['decls'],
                'A section-6 rule uses `!important`. The stylesheet claims none is needed because '
                . 'specificity suffices; if that stopped being true, say why there rather than '
                . 'deleting this assertion.');
        }
    }

    /* ======================================= the bound on the dark-mode layer's exception */

    /**
     * SECTION 6 DECLARES NOTHING THAT CAN CHANGE A COLOUR, IN EITHER APPEARANCE.
     *
     * THIS IS THE ASSERTION THAT PAYS FOR AN EXCEPTION IN ANOTHER FILE'S GATE, and it is the whole
     * reason the exception is defensible rather than a widening.
     *
     * `StorefrontDarkModeLayerTest::test_no_rule_applies_outside_an_appearance_scope_except_the_
     * declared_one` asserts that no rule in `storefront-appearance.css` applies regardless of
     * appearance, because every previous rule in that file maps a Tailwind utility to a token and
     * one that leaked across appearances is how this project shipped 1.21:1 white-on-white panels.
     * Section 6 is the first rule set in the layer that is NOT a colour mapping: it is a tab-order
     * repair, and `visibility`, `opacity`, `pointer-events`, `transform` and `transition` have no
     * appearance. Scoping it to `[data-ss-theme]` would be worse than redundant — that attribute is
     * written by an inline script, and a WCAG Level A fix that stops working when a script is
     * blocked or a layout omits the appearance partial is not a fix.
     *
     * So the exception is granted on the grounds that these rules CANNOT carry a colour regression,
     * and this test is what makes that a measured property of the stylesheet rather than a promise
     * in a docblock. The property allowlist is deliberately tiny: a future `color`, `background`,
     * `background-color`, `border-color`, `fill` or `outline-color` in section 6 fails HERE, in the
     * file that asked for the exception, instead of silently enjoying it.
     *
     * The rule set measured is EXACTLY the set the exception permits — every rule all of whose
     * selectors contain `.ss-product-actions` — so the two cannot drift apart.
     */
    #[Test]
    public function test_the_unscoped_section_declares_no_property_that_can_change_a_colour(): void
    {
        /* Reachability and motion only. Nothing here is painted. */
        $permitted = ['visibility', 'opacity', 'pointer-events', 'transform', 'transition'];

        $seen = 0;

        foreach ($this->rules($this->live(self::LAYER)) as $rule) {
            $isSectionSix = $rule['selectors'] !== []
                && array_reduce($rule['selectors'],
                    static fn ($carry, $s) => $carry && str_contains($s, '.ss-product-actions'), true);

            if (! $isSectionSix) {
                continue;
            }

            $seen++;

            foreach (explode(';', $rule['decls']) as $declaration) {
                $declaration = trim($declaration);

                if ($declaration === '') {
                    continue;
                }

                $property = strtolower(trim(substr($declaration, 0, (int) strpos($declaration, ':'))));

                $this->assertContains($property, $permitted,
                    "Section 6 of " . self::LAYER . " declares `{$property}`, which is not one of "
                    . implode(', ', $permitted) . ".\n\n"
                    . "That matters outside this file. `StorefrontDarkModeLayerTest::test_no_rule_"
                    . "applies_outside_an_appearance_scope_except_the_declared_one` allows these "
                    . "selectors to apply in BOTH appearances, and the ONLY argument for that "
                    . "exception is that a tab-order repair declares nothing a dark page would need "
                    . "to re-declare. A property that can paint breaks the argument: either scope "
                    . "the new rule to `[data-ss-theme]` with an `auto` arm as sections 1-3 do, or "
                    . "re-argue the exception in that gate. Do not add it to the list here.\n\n"
                    . "Selectors: " . implode(', ', array_slice($rule['selectors'], 0, 3))
                    . (count($rule['selectors']) > 3 ? ', ...' : ''));
            }
        }

        /* A census that measured nothing would pass. */
        $this->assertSame(4, $seen,
            "Expected exactly 4 section-6 rules (resting, reveal, `@media (hover: none)`, "
            . "`@media (prefers-reduced-motion: reduce)`) and found {$seen}. If the section was "
            . 'restructured, re-derive this figure deliberately — a rule that stopped matching '
            . 'would not be colour-checked, and it would still be enjoying the dark-mode gate\'s '
            . 'exception.');
    }

    /* ============================================================ the drift guard */

    /**
     * THE VENDORED MODULES STILL DECLARE THE HIDING THIS OVERRIDE EXISTS TO BEAT.
     *
     * When a vendor update fixes its own stylesheet, this fails and says so, rather than leaving a
     * permanent override nobody dares remove. `packages/workdo/` must not be edited, so this reads
     * it and nothing more.
     */
    #[Test]
    #[DataProvider('moduleProvider')]
    public function test_the_vendored_module_still_hides_its_own_button(string $module, string $class): void
    {
        $source = $this->raw("packages/workdo/{$module}/src/resources/views/pages/button.blade.php");

        $this->assertMatchesRegularExpression(
            '/\.' . preg_quote($class, '/') . '\s*\{[^}]*visibility:\s*hidden[^}]*\}/s',
            $source,
            "{$module} no longer declares `.{$class} { ... visibility: hidden ... }`. GAP-256 may be "
            . 'fixed at source; if so, section 6 of ' . self::LAYER . ' is now dead code and should '
            . 'be reassessed and removed deliberately, not left in place to fight a rule that is '
            . 'gone.'
        );
    }

    /** The one module that ships no stylesheet, asserted in the other direction. */
    #[Test]
    public function test_products_pdf_still_ships_no_stylesheet(): void
    {
        $this->assertStringNotContainsString('<style',
            $this->raw('packages/workdo/ProductsPDF/src/resources/views/pages/button.blade.php'),
            'ProductsPDF has acquired a stylesheet. It is the control group precisely because it had '
            . 'none; if it now hides itself, GAP-256 covers four buttons and not three and this '
            . 'census is measuring the wrong set.');
    }

    /* ============================================================ helpers */

    public static function ancestorProvider(): array
    {
        return array_map(static fn ($a) => [$a], self::ANCESTORS);
    }

    public static function moduleProvider(): array
    {
        return [
            ['ProductQuickView', 'quickview-btn'],
            ['ProductCompare', 'compare-btn'],
            ['ProductCSV', 'csv-btn'],
        ];
    }

    /**
     * The reveal rule: the one whose selector list contains the card-hover mirror. Found by content
     * rather than by position, so reordering the stylesheet does not break this census.
     */
    private function revealRule(): array
    {
        foreach ($this->rules($this->live(self::LAYER)) as $rule) {
            if ($rule['media'] === null
                && in_array('.product-card:hover .ss-product-actions .quickview-btn', $rule['selectors'], true)) {
                return $rule;
            }
        }

        $this->fail('There is no unconditional reveal rule mirroring `.product-card:hover` for the '
            . 'conversion actions in ' . self::LAYER . '. Without it the mouse path loses '
            . '`pointer-events` and the keyboard path has nothing to key on.');
    }

    /**
     * A selector's specificity as one comparable integer, (ids, classes, elements) base-256.
     *
     * Classes, attribute selectors and pseudo-CLASSES all count at the class level; pseudo-ELEMENTS
     * count at the element level. That is enough for the flat selectors this layer uses and it is
     * computed, not copied out of a comment.
     */
    private static function specificity(string $selector): int
    {
        $s = $selector;
        $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $s);
        $classes = preg_match_all('/\.[A-Za-z0-9_-]+/', $s)
            + preg_match_all('/\[[^\]]+\]/', $s)
            + preg_match_all('/(?<!:):(?!:)[A-Za-z-]+(?:\([^)]*\))?/', $s);
        $elements = preg_match_all('/::[A-Za-z-]+/', $s)
            + preg_match_all('/(?:^|[\s>+~])([a-z][a-z0-9]*)/i', preg_replace('/\.[A-Za-z0-9_-]+|#[A-Za-z0-9_-]+|\[[^\]]+\]|:{1,2}[A-Za-z-]+(?:\([^)]*\))?/', ' ', $s));

        return $ids * 65536 + $classes * 256 + $elements;
    }
}

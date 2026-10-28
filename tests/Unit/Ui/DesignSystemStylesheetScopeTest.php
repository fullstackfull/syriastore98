<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * The design system's stylesheet cannot reach an element outside the design system.
 * =================================================================================================
 *
 * WHY THIS IS A TEST AND NOT A COMMENT. `resources/views/layouts/app.blade.php` loads
 * `store-syria-ds.css` on EVERY admin page, across all 70 vendored WorkDo modules, and justifies
 * that with an inventory of the file's non-`.ss-` selectors written in prose. That inventory has
 * now gone stale: P2-03-1 added rules it does not list, and its counts no longer match the file.
 * Nothing noticed, because nothing checked.
 *
 * It is a claim about a file, so a file is what should answer it -- the same reasoning
 * `ClaudeConfigurationTest` applies to the skills manifest, and the same lesson CLAUDE.md records
 * about prose that was "wrong in both directions".
 *
 * THE PROPERTY, stated exactly: every selector in the stylesheet must be CONFINED by a design
 * system token -- an `.ss-` class or a `data-ss-` attribute somewhere in the selector. A selector
 * so confined cannot match an element unless that element is inside, or is part of, the design
 * system. `.ss-table th` qualifies: its subject is a bare `th`, but it can only ever match a `th`
 * inside an `.ss-table`.
 *
 * This is deliberately NOT the stricter rule "every selector's SUBJECT carries an `.ss-` class".
 * That rule is false here and the layout comment already records one reason --
 * `.ss-sort-list .ui-sortable-placeholder` targets jQuery UI's own class -- and measuring the file
 * turns up 24 distinct subject shapes in that position (`th`, `td`, `tr:hover`, `summary`, `img`,
 * `td::before` and so on), every one of them confined by an ancestor. Asserting the strict rule
 * would mean 24 exemptions that say nothing; asserting confinement says the thing that matters.
 *
 * WHAT A FAILURE MEANS. Somebody added a rule that can paint an element the design system does not
 * own -- a vendored module's `.card`, a theme's `.btn`. That is not necessarily wrong, but it is
 * exactly the change that should be read by a person rather than merged silently, which is what a
 * ratchet is for.
 */
class DesignSystemStylesheetScopeTest extends TestCase
{
    private const STYLESHEET = __DIR__ . '/../../../public/css/store-syria-ds.css';

    /**
     * Selectors allowed to carry no design-system token at all.
     *
     * Only `:root` qualifies, and only because a custom-property declaration paints nothing: it
     * defines variables that `.ss-` rules then read. If this list ever needs a second entry, that
     * entry is a rule which really can reach outside the design system, and it needs a reason
     * written next to it rather than an addition made to make a test pass.
     */
    private const UNCONFINED_ALLOWED = [':root'];

    /** `from` / `to` / `37%` inside `@keyframes` are animation stops, not selectors. */
    private const KEYFRAME_STOP = '/^(from|to|-?\d+(\.\d+)?%)$/i';

    // ---------------------------------------------------------------------------------------
    //  P2-01-16 — LOGICAL PROPERTIES. A physical edge is the wrong edge in one of two languages.
    // ---------------------------------------------------------------------------------------

    /**
     * The properties that have a logical counterpart, and the counterpart to use.
     *
     * `margin-left` in an LTR layout means "the side the text starts on"; in the Arabic build it
     * means the side it ENDS on, and the rule then paints the wrong edge. This storefront and
     * admin are bilingual Arabic (RTL) and English (LTR) by design, so a physical edge in the
     * shared stylesheet is a defect in exactly one of the two directions -- which is the hardest
     * kind to notice, because the build you are looking at is usually fine.
     *
     * `top` / `bottom` and their `-block-` counterparts are NOT in this list. They do not flip
     * with writing direction in any locale this product ships, so rewriting them would be churn
     * with no reader who benefits.
     */
    private const PHYSICAL_TO_LOGICAL = [
        'margin-left' => 'margin-inline-start',
        'margin-right' => 'margin-inline-end',
        'padding-left' => 'padding-inline-start',
        'padding-right' => 'padding-inline-end',
        'border-left' => 'border-inline-start',
        'border-right' => 'border-inline-end',
        'border-left-width' => 'border-inline-start-width',
        'border-right-width' => 'border-inline-end-width',
        'border-left-color' => 'border-inline-start-color',
        'border-right-color' => 'border-inline-end-color',
        'border-left-style' => 'border-inline-start-style',
        'border-right-style' => 'border-inline-end-style',
        'border-top-left-radius' => 'border-start-start-radius',
        'border-top-right-radius' => 'border-start-end-radius',
        'border-bottom-left-radius' => 'border-end-start-radius',
        'border-bottom-right-radius' => 'border-end-end-radius',
        'left' => 'inset-inline-start',
        'right' => 'inset-inline-end',
        'text-align: left' => 'text-align: start',
        'text-align: right' => 'text-align: end',
        'float: left' => 'float: inline-start',
        'float: right' => 'float: inline-end',
    ];

    /**
     * PREMISE, and it is not optional here: the detector must be able to SEE a physical property.
     *
     * Every assertion below is satisfied by a detector that matches nothing, so without this a
     * broken regex or a comment-stripping bug would report a clean stylesheet forever. This feeds
     * the real detector a string that must trip it, and a logical one that must not.
     */
    public function test_the_physical_property_detector_actually_detects(): void
    {
        $offenders = $this->physicalDeclarationsIn(
            ".ss-fake { margin-left: 4px; padding-inline-start: 8px; }\n"
            . ".ss-other { text-align: left; }\n"
        );

        $this->assertSame(
            ['margin-left', 'text-align: left'],
            array_values(array_unique(array_column($offenders, 'property'))),
            'the detector did not find a physical declaration it was handed, so a clean result '
            . 'from it would mean nothing'
        );

        $this->assertSame(
            [],
            $this->physicalDeclarationsIn(
                ".ss-fake { margin-inline-start: 4px; inset-inline-end: 0; text-align: start; }\n"
            ),
            'the detector flagged a logical declaration, so it would force churn rather than fixes'
        );
    }

    /**
     * THE RATCHET. No physical edge where a logical property exists.
     *
     * MEASURED BEFORE THIS LANDED: the stylesheet was already clean -- the work this row describes
     * had been done without a row to record it. So this test is not a fix; it is the thing that
     * stops the next contributor undoing one, which the row's own acceptance asks for in as many
     * words ("a ratchet that fails on a new one"). The lesson it embodies is the one this phase
     * has now met four times: an unasserted property is one edit away from an absent one.
     */
    public function test_the_stylesheet_uses_logical_properties_only(): void
    {
        $offenders = $this->physicalDeclarationsIn($this->stylesheet());

        $report = array_map(
            fn (array $o) => sprintf(
                '  line %d: `%s` -- use `%s`',
                $o['line'],
                $o['property'],
                self::PHYSICAL_TO_LOGICAL[$o['property']]
            ),
            $offenders
        );

        $this->assertSame([], $offenders,
            "store-syria-ds.css carries physical edges, which paint the wrong side of the element "
            . "in the Arabic (RTL) build:\n" . implode("\n", $report));
    }

    /**
     * Physical declarations in a stylesheet, as [line, property] pairs.
     *
     * COMMENTS ARE BLANKED FIRST, and that is a lesson this repository paid for twice: a
     * source-text scanner that does not strip comments matches its own prose, so a file explaining
     * why it avoids `margin-left` would fail a test looking for `margin-left`. Blanked rather than
     * deleted, so the line numbers in the report still point at the real lines.
     *
     * @return list<array{line: int, property: string}>
     */
    private function physicalDeclarationsIn(string $css): array
    {
        $css = (string) preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]),
            $css
        );

        $offenders = [];

        foreach (explode("\n", $css) as $index => $line) {
            foreach (self::PHYSICAL_TO_LOGICAL as $physical => $logical) {
                // A declaration, not a word: the property must be followed by a colon, and must
                // not be the tail of a longer property name (`border-left` must not match inside
                // `border-left-width`, which has its own entry).
                $pattern = str_contains($physical, ':')
                    ? '/(^|[;{\s])' . preg_quote($physical, '/') . '\s*(;|}|$)/i'
                    : '/(^|[;{\s])' . preg_quote($physical, '/') . '\s*:/i';

                if (preg_match($pattern, $line)) {
                    $offenders[] = ['line' => $index + 1, 'property' => $physical];
                }
            }
        }

        return $offenders;
    }

    private function stylesheet(): string
    {
        return (string) file_get_contents(self::STYLESHEET);
    }

    public function test_the_stylesheet_exists_and_was_really_parsed(): void
    {
        // THE PREMISE. Every assertion below is satisfied by an empty selector list, so a parser
        // that silently matched nothing -- or a moved file -- would make this suite pass while
        // measuring nothing at all.
        $this->assertFileExists(self::STYLESHEET);

        $selectors = $this->selectors();

        $this->assertGreaterThan(
            300,
            count($selectors),
            'Parsed too few selectors out of store-syria-ds.css. The file is ~2,500 lines; a count '
            . 'this low means the parser broke, not that the stylesheet shrank.'
        );

        // And it really found the shapes the layout comment talks about, so "0 unconfined" below
        // is a reading of the real file rather than of a list the parser dropped on the floor.
        $this->assertContains('.ss-scope *', $selectors);
    }

    public function test_every_selector_is_confined_by_a_design_system_token(): void
    {
        $unconfined = [];

        foreach ($this->selectors() as $selector) {
            if (in_array($selector, self::UNCONFINED_ALLOWED, true)) {
                continue;
            }

            // `.ss-` catches the classes; `data-ss-` catches the attribute hooks
            // (`[data-ss-theme="dark"]`, `[data-ss-notification-bell]`), which the design system
            // owns by the same naming convention.
            if (str_contains($selector, '.ss-') || str_contains($selector, 'data-ss-')) {
                continue;
            }

            $unconfined[] = $selector;
        }

        $this->assertSame(
            [],
            $unconfined,
            "store-syria-ds.css is loaded on every admin page, across all 70 vendored modules.\n"
            . "These selectors carry no `.ss-` class and no `data-ss-` attribute, so nothing stops\n"
            . "them matching an element the design system does not own:\n\n  "
            . implode("\n  ", $unconfined)
            . "\n\nIf that is intended, say why in " . basename(__FILE__) . "'s UNCONFINED_ALLOWED."
        );
    }

    /**
     * The stylesheet's selectors, comments and at-rule preludes removed.
     *
     * Hand-rolled rather than pulled in as a dependency: this needs to answer one question about
     * one file, and a CSS parser is a package to vet, pin and justify for a single assertion.
     *
     * @return list<string>
     */
    private function selectors(): array
    {
        $css = (string) file_get_contents(self::STYLESHEET);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $selectors = [];
        $buffer = '';
        $depth = 0;

        // Tracks which at-rule opened each brace level, so a `@keyframes` body's `0%` and `to`
        // are not mistaken for selectors. `@media` and `@supports` bodies DO hold real selectors,
        // so they are not skipped -- which is the whole reason this is a stack and not a flag:
        // the dark-mode block is `@media (prefers-color-scheme: dark) { [data-ss-theme="auto"] … }`
        // and its inner selector must be measured.
        $atRuleStack = [];

        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $prelude = trim($buffer);
                $buffer = '';

                $isAtRule = str_starts_with($prelude, '@');
                $atRuleStack[$depth] = $isAtRule ? strtolower($prelude) : null;
                $depth++;

                if ($isAtRule) {
                    continue;
                }

                $insideKeyframes = false;
                foreach ($atRuleStack as $level => $rule) {
                    if ($level < $depth - 1 && $rule !== null && str_starts_with($rule, '@keyframes')) {
                        $insideKeyframes = true;
                    }
                }

                foreach (explode(',', $prelude) as $one) {
                    $one = trim((string) preg_replace('/\s+/', ' ', $one));

                    if ($one === '' || ($insideKeyframes && preg_match(self::KEYFRAME_STOP, $one))) {
                        continue;
                    }

                    $selectors[] = $one;
                }

                continue;
            }

            if ($char === '}') {
                unset($atRuleStack[$depth - 1]);
                $depth = max(0, $depth - 1);
                $buffer = '';

                continue;
            }

            if ($depth === 0 || ($atRuleStack[$depth - 1] ?? null) !== null) {
                // At the top level, or directly inside an at-rule body, what accumulates is the
                // next prelude. Inside a declaration block it is `color: red;` and is discarded
                // by the `}` branch above.
                $buffer .= $char;
            }
        }

        return array_values(array_unique($selectors));
    }
}

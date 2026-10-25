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

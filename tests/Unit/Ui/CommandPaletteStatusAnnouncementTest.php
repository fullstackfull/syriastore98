<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-07 — what the command palette announces, to the one audience that cannot see it.
 * ================================================================================================
 *
 * The palette's own markup comment says `aria-live="polite"` is there because "the result COUNT is
 * announced as it changes, which is the one thing…". MEASURED on the running page, in both
 * languages, that was not what happened. Three separate defects, all in the status region:
 *
 * 1. THE COUNT WAS A BARE NUMBER. `cmd.status.textContent = String(cmd.items.length)` — a screen
 *    reader said "5" and nothing else, to the one audience that cannot see the list being counted.
 *
 * 2. THE COUNT WAS NEVER HEARD ANYWAY. `cmdRender` wrote it and then activated row 0 in the very
 *    next statement, and `cmdActions` overwrote the region synchronously. An `aria-live="polite"`
 *    region announces only the value it holds at the next rendering opportunity, so the count was
 *    replaced before it could be read. Measured: the region read "#P25PROBE-55 — Actions: 1" every
 *    time, never a count. Fixed by passing `announce: false` on that one initial activation, so
 *    the SET change is announced on search and the OPTION on arrow keys — one region, two events,
 *    no competition, which is what the component's own comment says it wants.
 *
 * 3. THE ACTION LINE WAS BUILT IN ENGLISH WORD ORDER: `… + actions.length + ' ' + label`. In
 *    Arabic that renders "1 الإجراءات" — a bare numeral in front of a definite plural, which is
 *    not grammatical. Both strings are now "LABEL: COUNT", which needs no plural machinery because
 *    it is correct for every count in both languages.
 *
 * MEASURED AFTER, all four paths, both languages:
 *
 *     after a search     "Results: 5"                  "النتائج: 5"
 *     after ArrowDown    "#P25PROBE-54 — Actions: 1"   "#P25PROBE-54 — الإجراءات: 1"
 *     no results         "Nothing found"               "لا توجد نتائج"
 *
 * ONE MORE THING THIS PINS, and it is not cosmetic. `__('Results')` already returned "النتائج"
 * before the key was added, because this installation's translator resolves JSON keys WITHOUT
 * case sensitivity and `ar.json` carried a lowercase `"results"`. That is undocumented behaviour
 * to depend on, and the project's own Arabic coverage ratchet reads the file rather than the
 * translator, so the key is now present with its exact casing in both files.
 */
class CommandPaletteStatusAnnouncementTest extends TestCase
{
    private const PALETTE = 'resources/views/partision/command-palette.blade.php';
    private const JS = 'public/js/store-syria-ds.js';

    public function test_the_results_label_is_translated_and_passed_from_blade(): void
    {
        $blade = $this->read(self::PALETTE);

        $this->assertStringContainsString(
            'data-ss-results-label="{{ __(\'Results\') }}"',
            $blade,
            'The count needs a word, and the word has to be translated. Building it in javascript '
            . 'would hard-code English for every merchant reading Arabic.'
        );

        // The five attributes beside it are the established pattern; this is the sixth.
        foreach (['data-ss-empty-text', 'data-ss-error-text', 'data-ss-searching-text'] as $sibling) {
            $this->assertStringContainsString($sibling, $blade);
        }
    }

    public function test_the_count_is_announced_with_its_label_not_as_a_bare_number(): void
    {
        $js = $this->withoutComments($this->read(self::JS));

        $this->assertSame(
            0,
            preg_match('/cmd\.status\.textContent\s*=\s*String\(cmd\.items\.length\)/', $js),
            'A screen reader reading this region said "5" and nothing else.'
        );

        $this->assertMatchesRegularExpression(
            "/cmd\\.status\\.textContent\\s*=\\s*\\(cmd\\.input\\.getAttribute\\('data-ss-results-label'\\)[^;]*\\+\\s*': '\\s*\\+\\s*cmd\\.items\\.length/s",
            $js,
            'The result status must read LABEL then COUNT: "Results: 5".'
        );
    }

    public function test_the_action_count_follows_its_label_rather_than_leading_it(): void
    {
        $js = $this->withoutComments($this->read(self::JS));

        $this->assertSame(
            0,
            preg_match("/\\+\\s*actions\\.length\\s*\\+\\s*' '\\s*\\+/s", $js),
            'Count-then-label renders "1 الإجراءات" in Arabic — a numeral in front of a definite '
            . 'plural, which is not grammatical. The label leads and the count follows.'
        );

        $this->assertMatchesRegularExpression(
            "/getAttribute\\('data-ss-actions-label'\\)[^;]*\\+\\s*': '\\s*\\+\\s*actions\\.length/s",
            $js
        );
    }

    public function test_the_first_activation_does_not_overwrite_the_count(): void
    {
        $js = $this->withoutComments($this->read(self::JS));

        $this->assertStringContainsString(
            'cmdActivate(0, false)',
            $js,
            'Without the flag, `cmdRender` writes the count and the activation on the next line '
            . 'overwrites it synchronously, so a polite live region never announces it.'
        );

        $this->assertStringContainsString('function cmdActivate(i, announce)', $js);
        $this->assertStringContainsString('function cmdActions(option, announce)', $js);

        $this->assertMatchesRegularExpression(
            '/if\s*\(cmd\.status\s*&&\s*announce\s*!==\s*false\)/',
            $js,
            'The guard is what makes the flag do anything.'
        );

        // Arrow keys must still announce: they pass no flag, so `announce` is undefined and the
        // `!== false` guard lets them through. If either became `cmdActivate(n, false)` the
        // selection would stop being announced at all.
        $this->assertStringContainsString('cmdActivate(cmd.active + 1)', $js);
        $this->assertStringContainsString('cmdActivate(cmd.active - 1)', $js);
    }

    public function test_the_label_exists_in_both_language_files_with_its_exact_casing(): void
    {
        foreach (['resources/lang/ar.json' => 'النتائج', 'resources/lang/en.json' => 'Results'] as $file => $expected) {
            $map = json_decode($this->read($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey(
                'Results',
                $map,
                $file . ' resolves `__(\'Results\')` today only because this installation\'s '
                . 'translator matches JSON keys without case sensitivity and the file carries a '
                . 'lowercase "results". That is undocumented behaviour to rely on.'
            );

            $this->assertSame($expected, $map['Results']);
            $this->assertGreaterThan(3_000, count($map));
        }
    }

    private function withoutComments(string $js): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}

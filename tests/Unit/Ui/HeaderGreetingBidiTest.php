<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-A-03 — the merchant's own name in the header, in an Arabic admin.
 * ======================================================================
 *
 * MEASURED IN THE RUNNING ARABIC ADMIN, by reading the rendered client rects of each child node
 * rather than the source string -- the source order is not the question, the VISUAL order is:
 *
 *   before   visual, left to right:  ["Amina Haddad", "!مرحبًا"]      the mark on the wrong side,
 *                                                                     and no separator at all
 *   after    visual, left to right:  ["!", "Amina Haddad", "مرحباً،"]
 *
 * In an RTL paragraph that second reading is correct: right to left it is the greeting, then the
 * name, then the exclamation mark at the end, which is where it belongs.
 *
 * WHY IT HAPPENED. `!` is a bidi-NEUTRAL character. The Unicode bidirectional algorithm resolves
 * a neutral between a left-to-right run (the Latin name) and the paragraph end against the
 * PARAGRAPH direction, which is RTL here -- so it is placed to the left of the name rather than
 * after it. Nothing is wrong with the translation or the markup order; the name simply needed its
 * own isolated embedding level. `<bdi>` is exactly that element, and it is the element form of the
 * `unicode-bidi: isolate` this design system already applies in eight places to numbers, statuses
 * and chart labels. The merchant's own name was the one run nothing isolated, and a Latin name in
 * an Arabic interface is the common case in this product, not an edge case.
 *
 * AND THE SEPARATOR WAS A SECOND, INDEPENDENT DEFECT. The English key is `'Hi, '` -- comma AND
 * trailing space. `ar.json` mapped it to `"مرحباً"` with neither, so the greeting and the name ran
 * together. The replacement uses the ARABIC comma `،` (U+060C), not the Latin `,`, because that is
 * the correct mark in Arabic text.
 */
class HeaderGreetingBidiTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_merchant_name_is_bidi_isolated_in_the_header(): void
    {
        $blade = $this->read('resources/views/partision/header.blade.php');

        $this->assertMatchesRegularExpression(
            '/<bdi>\s*\{\{[^}]*Auth::user\(\)->name[^}]*\}\}\s*<\/bdi>/',
            $blade,
            'The header no longer wraps the merchant name in <bdi>. Without it the trailing "!" '
            . 'is a bidi-neutral character and resolves against the RTL paragraph, landing on the '
            . 'wrong side of a Latin name. Measured in the running Arabic admin before the fix.'
        );
    }

    public function test_the_exclamation_mark_is_outside_the_isolate(): void
    {
        $blade = $this->read('resources/views/partision/header.blade.php');

        $this->assertStringContainsString('</bdi>!', $blade,
            'The "!" belongs to the greeting, not to the name, so it must sit OUTSIDE the isolate. '
            . 'Inside it, the isolate would carry the mark along with the name and the defect '
            . 'returns in a different shape.');
    }

    public function test_the_arabic_greeting_carries_its_own_separator(): void
    {
        $json = $this->read('resources/lang/ar.json');
        $map  = json_decode($json, true);

        $this->assertIsArray($map, 'ar.json is not valid JSON');
        $this->assertArrayHasKey('Hi, ', $map);

        $value = $map['Hi, '];

        $this->assertStringEndsWith(' ', $value,
            'The English key is "Hi, " -- comma AND trailing space. The Arabic value must carry '
            . 'its own separator or the greeting runs straight into the merchant name.');
        $this->assertStringContainsString("\u{060C}", $value,
            'Arabic text uses the Arabic comma U+060C, not the Latin ",".');
    }

    /**
     * THE PREMISE: `!` really is bidi-neutral, so the two cases above cannot be satisfied by a
     * character that was never going to move. Asserted from Unicode's own bidirectional class
     * rather than from the prose, so the reasoning survives a rewrite of these comments.
     */
    public function test_the_exclamation_mark_really_is_bidi_neutral(): void
    {
        $this->assertTrue(
            extension_loaded('intl'),
            'ext-intl is required to assert the premise; it is in composer.json require.'
        );

        // ON (Other Neutral) is the bidirectional class that makes "!" resolve against the
        // paragraph direction instead of against the run beside it.
        $this->assertSame(
            \IntlChar::CHAR_DIRECTION_OTHER_NEUTRAL,
            \IntlChar::charDirection('!'),
            '"!" is no longer Other Neutral, which would mean the defect this slice fixes cannot '
            . 'occur. Re-measure in the running Arabic admin before changing anything here.'
        );
    }
}

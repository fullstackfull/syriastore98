<?php

namespace Tests\Unit\Loyalty;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * `MKT-005` — EVERY STRING THE LOYALTY HISTORY SHOWS A MERCHANT HAS AN ARABIC TRANSLATION.
 * =================================================================================================
 *
 * WHY A RATCHET AND NOT A ONE-OFF PASS. CLAUDE.md: *"Bilingual Arabic (RTL) and English (LTR). Any
 * UI change must be checked in both directions."* A missing entry in `resources/lang/ar.json` is the
 * quietest possible failure: `__('Balance after')` with no translation returns **the English
 * string**, so the Arabic page renders, in the right direction, with English words in it — HTTP 200,
 * no error, no log line. A rendering test cannot catch it either, because the page it renders is
 * perfectly valid. Only a census of the keys can.
 *
 * MEASURED WHEN THIS LANDED: of the **29** distinct `__()` strings in the history partial, **5
 * already had an Arabic entry and 24 did not**. All 24 were added in the same change, reusing the
 * loyalty vocabulary already in that file — «نقاط الولاء» for loyalty points, which
 * `SegmentDefinition`'s VIP rule already ships — rather than inventing a second terminology for the
 * same feature.
 *
 * COMMENTS ARE STRIPPED, and here it is load-bearing rather than ceremonial: the partial's docblock
 * discusses `__()` and quotes the bidi reasoning at length, and the controller's comment quotes
 * `MKT-005`'s Arabic text. CLAUDE.md records **eight** occasions on which a census was satisfied —
 * or broken — by prose about the thing it measures, four of them on one day, in three syntaxes.
 * Blade comments are `{{-- --}}`, which `StripsSourceComments` does not remove, so they are stripped
 * here as well.
 *
 * WHAT IT DOES NOT CLAIM: that the Arabic reads like Arabic, or that it is the terminology the owner
 * would choose. That is human review and is named as such in the handover rather than implied by a
 * green test.
 */
final class LoyaltyStringsAreTranslatedTest extends TestCase
{
    use StripsSourceComments;

    private const ARABIC = 'resources/lang/ar.json';

    /** The surfaces a merchant actually reads. */
    private const SOURCES = [
        'resources/views/customer/loyalty-history.blade.php',
    ];

    /** @return list<string> */
    private function translatableStrings(): array
    {
        $found = [];

        foreach (self::SOURCES as $relative) {
            $code = $this->sourceWithoutComments($relative);

            /* Blade comments too — `StripsSourceComments` handles PHP's three forms, not `{{-- --}}`. */
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);

            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $matches);

            foreach ($matches[1] as $literal) {
                $key = str_replace("\\'", "'", $literal);

                if (! in_array($key, $found, true)) {
                    $found[] = $key;
                }
            }
        }

        return $found;
    }

    /** @return array<string, string> */
    private function arabic(): array
    {
        $path = dirname(__DIR__, 3) . '/' . self::ARABIC;

        $this->assertFileExists($path, 'premise: the Arabic catalogue is where this expects it.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'premise: the Arabic catalogue is valid JSON.');

        return $decoded;
    }

    /**
     * THE REACHABILITY PREMISE. A census that finds nothing passes vacuously — a renamed file, a
     * changed quoting style or a regex that stopped matching all read as "every string is
     * translated". CLAUDE.md records that family of failure four times over.
     */
    public function test_premise_the_census_actually_finds_the_surfaces_strings(): void
    {
        $strings = $this->translatableStrings();

        $this->assertGreaterThanOrEqual(
            25,
            count($strings),
            'the census found fewer strings than this surface has. It was 29 when written, so a '
            . 'collapse to a handful means the regex or the file list has stopped matching and '
            . 'every assertion below would pass for the wrong reason.'
        );

        $this->assertContains(
            'Loyalty points history',
            $strings,
            'and it must find the heading, which is the one string the screen cannot render '
            . 'without.'
        );
    }

    /**
     * COMMENTS ARE REALLY STRIPPED — asserted, because the whole census depends on it and because
     * the partial's docblock contains `__()` calls inside prose.
     */
    public function test_premise_prose_about_a_string_is_not_counted_as_a_string(): void
    {
        $code = $this->sourceWithoutComments(self::SOURCES[0]);
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);

        $this->assertStringNotContainsString(
            'answers \'does this string exist anywhere\'',
            $code,
            'the Blade comment block is still in the censused text, so a sentence mentioning a '
            . 'translation key would be indistinguishable from a call to one.'
        );
    }

    public function test_every_string_the_loyalty_history_shows_has_an_arabic_translation(): void
    {
        $arabic = $this->arabic();

        $missing = [];

        foreach ($this->translatableStrings() as $key) {
            if (! array_key_exists($key, $arabic)) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These strings have no Arabic entry, so the Arabic loyalty history renders them in "
            . "English with no error:\n  - " . implode("\n  - ", $missing)
        );
    }

    /**
     * An entry that is PRESENT and EMPTY is worse than a missing one: `__()` returns the empty
     * string, so the Arabic page renders a blank cell where a label belongs.
     */
    public function test_no_arabic_entry_for_this_surface_is_blank(): void
    {
        $arabic = $this->arabic();

        $blank = [];

        foreach ($this->translatableStrings() as $key) {
            if (array_key_exists($key, $arabic) && trim((string) $arabic[$key]) === '') {
                $blank[] = $key;
            }
        }

        $this->assertSame([], $blank, 'blank Arabic entries render as empty labels: '
            . implode(', ', $blank));
    }

    /**
     * And the Arabic is actually Arabic — an entry copied from the English key renders the English
     * string while passing a presence census.
     */
    public function test_the_arabic_entries_are_not_copies_of_the_english(): void
    {
        $arabic = $this->arabic();

        $copies = [];

        foreach ($this->translatableStrings() as $key) {
            if (! array_key_exists($key, $arabic)) {
                continue;
            }

            if (! preg_match('/\p{Arabic}/u', (string) $arabic[$key])) {
                $copies[] = $key;
            }
        }

        $this->assertSame(
            [],
            $copies,
            "These Arabic entries contain no Arabic letter at all, so they render as English while "
            . "satisfying a presence check:\n  - " . implode("\n  - ", $copies)
        );
    }
}

<?php

namespace Tests\Unit\Saas;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * EVERY SENTENCE THE PHASE 06 SAAS OPERATOR SURFACES PRINT IS DECLARED IN BOTH LANGUAGES.
 * =================================================================================================
 *
 * `__()` RETURNS ITS KEY when nothing is declared. So an undeclared English sentence renders
 * correctly in English BY ACCIDENT and renders IN ENGLISH on the Arabic page — and no test that
 * merely checks "the hint is not empty" can tell the two apart. `AnalyticsMessagesAreDeclaredTest`
 * is the same ratchet over the Phase 05 analytics surfaces; it found seven undeclared sentences,
 * one of which stated the opposite of what the code did. This is that ratchet over the surfaces
 * this workstream adds, and it is a separate file rather than four more rows in that one because
 * the two workstreams are being built at once and a shared list is a shared merge conflict.
 *
 * A HAND-CHECKED DIFF IS NOT THIS. Every slice here could say "each new sentence declared in both
 * files, verified by reading the diff", and that catches what the author remembered to look at.
 * The argument for a ratchet over a habit is the sentence an EARLIER slice left behind.
 *
 * COMMENTS ARE STRIPPED, through the shared trait, and here it is load-bearing rather than
 * precautionary: `SaasPlatformResources`'s own header quotes the sentences it prints while
 * explaining why they are worded as they are, and the docblock of `SaasResourceProbe` names
 * `MemFree` and `MemAvailable` in prose. CLAUDE.md records four occasions in one day on which a
 * census was satisfied — or broken — by prose about the thing it measures.
 *
 * WHAT IT DOES NOT COVER, stated rather than implied: `__()` only, in the sources listed below.
 * Nothing in them uses `@lang()` or `trans()`, and a key built at runtime from a variable cannot be
 * read statically by any census. The vendored `packages/workdo/` modules are out of scope here as
 * everywhere: their strings are not ours to declare.
 */
final class PlatformResourceMessagesAreDeclaredTest extends TestCase
{
    use StripsSourceComments;

    /** The Phase 06 SaaS operator surfaces this workstream owns: producer, screen and controller. */
    private const SURFACES = [
        'app/Services/SaasPlatformResources.php',
        'app/Services/SaasResourceProbe.php',
        'resources/views/superadmin/platform_resources.blade.php',
        'app/Http/Controllers/SuperAdminResourcesController.php',
        'app/Services/SaasRevenueFigures.php',
        'resources/views/superadmin/platform_revenue.blade.php',
        'app/Http/Controllers/SuperAdminRevenueController.php',
    ];

    /**
     * THE SOURCES THAT ARE SUPPOSED TO BE SILENT, and asserted to be.
     *
     * The probe returns the machine's figures or null and never a sentence; the controller hands
     * the producer's result to a view and composes nothing. Both are in the scanned set so that
     * the day a label appears in either, the label is judged — but requiring them to YIELD keys
     * would be asserting the opposite of their design, and that is how the first version of this
     * premise failed.
     */
    private const SILENT_SURFACES = [
        'app/Services/SaasResourceProbe.php',
        'app/Http/Controllers/SuperAdminResourcesController.php',
        'app/Http/Controllers/SuperAdminRevenueController.php',
    ];

    private function repoPath(string $relative = ''): string
    {
        return dirname(__DIR__, 3) . ($relative === '' ? '' : '/' . $relative);
    }

    /**
     * @return array<string, list<string>> key => the sources that print it
     */
    private function keysByOrigin(): array
    {
        $keys = [];

        foreach (self::SURFACES as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach ([
                "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
                '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/',
            ] as $pattern) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $raw) {
                    $keys[stripcslashes($raw)][] = $relative;
                }
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function language(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->repoPath('resources/lang/' . $file)), true);

        $this->assertIsArray($decoded, "resources/lang/{$file} is not readable as JSON.");

        return $decoded;
    }

    /**
     * THE PREMISE. `assertSame([], $missing)` is satisfied just as well by a scan that read
     * nothing, and this one goes through a comment stripper and two regular expressions, each of
     * which can silently return empty.
     */
    public function test_the_census_reads_the_surfaces_it_claims_to(): void
    {
        $keys = $this->keysByOrigin();

        $this->assertGreaterThan(80, count($keys), sprintf(
            'Only %d translation keys were found across %d sources. 105 were measured when the '
            . 'revenue surfaces joined this ratchet, so the extraction has broken and every '
            . 'assertion below is vacuous.',
            count($keys),
            count(self::SURFACES)
        ));

        foreach (self::SURFACES as $surface) {
            $fromThisFile = array_filter($keys, fn (array $origins): bool => in_array($surface, $origins, true));

            if (in_array($surface, self::SILENT_SURFACES, true)) {
                $this->assertSame([], array_keys($fromThisFile),
                    "{$surface} has started printing text. It is a measurement or dispatch layer "
                    . 'and the sentences belong in the producer or the view; if that has genuinely '
                    . 'changed, take it out of SILENT_SURFACES deliberately.');

                continue;
            }

            $this->assertNotEmpty($fromThisFile,
                "No translation key was extracted from {$surface}. Either the file stopped printing "
                . 'text through __(), or the extraction no longer reads it.');
        }
    }

    public function test_every_key_these_surfaces_print_is_declared_in_english_and_arabic(): void
    {
        $en = $this->language('en.json');
        $ar = $this->language('ar.json');

        $undeclared = [];

        foreach ($this->keysByOrigin() as $key => $origins) {
            $absent = [];

            if (! array_key_exists($key, $en)) {
                $absent[] = 'en';
            }

            if (! array_key_exists($key, $ar)) {
                $absent[] = 'ar';
            }

            if ($absent !== []) {
                $undeclared[] = sprintf(
                    '[%s] missing from %s: %s',
                    implode(', ', array_unique(array_map('basename', $origins))),
                    implode(' and ', $absent),
                    mb_strimwidth($key, 0, 120, '...')
                );
            }
        }

        sort($undeclared);

        $this->assertSame([], $undeclared, sprintf(
            "%d string(s) a Phase 06 operator surface prints are not declared in both language "
            . "files. `__()` returns its KEY when nothing is declared, so these read correctly in "
            . "English by accident and read IN ENGLISH on the Arabic page. Declare each in BOTH "
            . "resources/lang/en.json and resources/lang/ar.json — and if the sentence already "
            . "exists under slightly different wording, REUSE that key rather than adding a near "
            . "duplicate.\n  %s",
            count($undeclared),
            implode("\n  ", $undeclared)
        ));
    }

    /**
     * THE ARABIC IS ARABIC, not the English sentence copied into the Arabic file.
     *
     * A catalogue entry whose value equals its key is what a bulk tool leaves behind, and it
     * passes the declaration test above while rendering English on the Arabic page — the exact
     * failure that test exists to catch, one level down. Keys that are the same string in both
     * languages legitimately exist (a currency code, a function name), so what is asserted is that
     * each Arabic value contains at least one Arabic letter OR is identical in `en.json` too.
     */
    public function test_the_arabic_declarations_are_not_the_english_sentence(): void
    {
        $ar = $this->language('ar.json');
        $en = $this->language('en.json');

        $untranslated = [];

        foreach (array_keys($this->keysByOrigin()) as $key) {
            if (! array_key_exists($key, $ar)) {
                continue;   // the test above owns that failure
            }

            $value = (string) $ar[$key];

            if (preg_match('/\p{Arabic}/u', $value) === 1) {
                continue;
            }

            if (($en[$key] ?? null) === $value && mb_strlen($value) < 30) {
                continue;   // a short token that is the same in both languages
            }

            $untranslated[] = mb_strimwidth($key, 0, 120, '...');
        }

        sort($untranslated);

        $this->assertSame([], $untranslated,
            "These keys are declared in ar.json with no Arabic in the value, so the Arabic page "
            . "renders them in English:\n  " . implode("\n  ", $untranslated));
    }
}

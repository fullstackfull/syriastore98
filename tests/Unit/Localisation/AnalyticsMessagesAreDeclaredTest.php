<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * P05-16 -- EVERY SENTENCE THE PHASE 05 ANALYTICS SURFACES PRINT IS DECLARED IN BOTH LANGUAGES.
 * =================================================================================================
 *
 * `__()` RETURNS ITS KEY when nothing is declared. So an undeclared English sentence renders
 * correctly in English BY ACCIDENT and renders IN ENGLISH on the Arabic page, and no test that
 * merely checks "the hint is not empty" -- which is what most of this project's analytics tests
 * check -- can tell the two apart. `StorefrontMessagesAreDeclaredTest` was built for exactly this
 * failure mode on the storefront, where a census found 115 such keys. This is the same census over
 * the surfaces Phase 05 added.
 *
 * IT FOUND SEVEN, AND ONE OF THEM WAS SAYING THE OPPOSITE OF WHAT THE CODE DOES. Measured
 * 2026-11-24 over the sources below: 183 distinct keys, 7 declared in NEITHER `en.json` nor
 * `ar.json` -- six disclosure sentences on the cross-tenant Repeat-purchase and Buyers figures and
 * on the merchant coupon and wallet-currency notes, plus:
 *
 *     "Orders with no customer account, which cannot be attributed to a person
 *      and are counted here at all: :count."
 *
 * Guest orders are EXCLUDED from that headcount -- the method's own docblock says they are "in
 * neither the numerator nor the denominator of anything" -- so the sentence beside the number told
 * the operator the reverse. It is gone, replaced by the declared sentence the merchant tile beside
 * it already uses, which the docblock claimed it used.
 *
 * WHY THE COUNT OF NEW SENTENCES HAD BEEN CHECKED BY HAND AND THAT WAS NOT ENOUGH. Each GAP-162
 * slice stated "six new sentences, each declared in BOTH language files", verified by reading the
 * diff. That catches what the author remembers to look at. It did not catch six sentences added by
 * EARLIER slices, which is the whole argument for a ratchet over a habit.
 *
 * SCOPE IS DERIVED, NOT LISTED, for `app/Support/Metrics/` -- every file in it, so a metric class
 * added tomorrow is covered the day it is added. The four surface files are named because there is
 * no route-file import list to read them from: the analytics routes are declared inline in
 * `routes/web.php` with fully-qualified class names.
 *
 * WHAT IT DOES NOT COVER, stated rather than implied: `__()` only. Nothing in these sources uses
 * `@lang()` or `trans()` -- measured, zero occurrences in all 33 files -- and a key built at
 * runtime from a variable cannot be read statically by any census. The vendored `packages/workdo/`
 * modules are out of scope here as everywhere: their strings are not ours to declare.
 *
 * COMMENTS ARE STRIPPED, through the shared trait, and here it is LOAD-BEARING rather than
 * precautionary -- measured, by bypassing it: the comment left in `PlatformAnalyticsFigures` where
 * that sentence was removed quotes the removed `__()` call verbatim, so a census reading comments
 * extracts the defective key from the explanation of its own fix and fails. The first draft of this
 * paragraph claimed the stripper was needed for THIS file's docblock, which is wrong: the test file
 * is not one of the scanned sources. CLAUDE.md records four occasions in one day on which a census
 * was satisfied -- or broken -- by prose about the thing it measures; this is the fifth, and the
 * only reason it is not a defect is that the mutation run asked.
 */
class AnalyticsMessagesAreDeclaredTest extends TestCase
{
    use StripsSourceComments;

    /** The Phase 05 analytics surfaces: two screens and the two controllers behind them. */
    private const SURFACES = [
        'resources/views/analytics/index.blade.php',
        'resources/views/platform/analytics.blade.php',
        'app/Http/Controllers/MerchantAnalyticsController.php',
        'app/Http/Controllers/PlatformAnalyticsController.php',
    ];

    private function repoPath(string $relative = ''): string
    {
        return dirname(__DIR__, 3) . ($relative === '' ? '' : '/' . $relative);
    }

    /** @return list<string> every source whose `__()` keys this file judges, as repo-relative paths */
    private function sources(): array
    {
        $metrics = glob($this->repoPath('app/Support/Metrics/*.php')) ?: [];

        $this->assertGreaterThan(20, count($metrics),
            'app/Support/Metrics/ holds far fewer files than the metric layer has, so the glob is '
            . 'wrong and this census is reading almost nothing.');

        $sources = self::SURFACES;

        foreach ($metrics as $absolute) {
            $sources[] = substr($absolute, strlen($this->repoPath()) + 1);
        }

        return $sources;
    }

    /**
     * @return array<string, list<string>> key => the sources that print it
     */
    private function keysByOrigin(): array
    {
        $keys = [];

        foreach ($this->sources() as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach ([
                "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
                '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/',
            ] as $pattern) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $raw) {
                    $key = stripcslashes($raw);
                    $keys[$key][] = $relative;
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
     * THE PREMISE. An `assertSame([], $missing)` is satisfied just as well by a scan that read
     * nothing, and this one reads 33 files through a comment stripper and two regular expressions
     * -- three things that can each silently return empty.
     */
    public function test_the_census_reads_the_surfaces_it_claims_to(): void
    {
        $keys = $this->keysByOrigin();

        $this->assertGreaterThan(150, count($keys), sprintf(
            'Only %d translation keys were found across %d sources. 183 were measured on '
            . '2026-11-24, so the extraction has broken and every assertion below is vacuous.',
            count($keys),
            count($this->sources())
        ));

        foreach (self::SURFACES as $surface) {
            $fromThisFile = array_filter($keys, fn (array $origins): bool => in_array($surface, $origins, true));

            $this->assertNotEmpty($fromThisFile,
                "No translation key was extracted from {$surface}. Either the file stopped printing "
                . 'text through __(), or the extraction no longer reads it.');
        }
    }

    public function test_every_key_the_analytics_surfaces_print_is_declared_in_english_and_arabic(): void
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
            "%d string(s) an analytics surface prints are not declared in both language files. "
            . "`__()` returns its KEY when nothing is declared, so these read correctly in English "
            . "by accident and read IN ENGLISH on the Arabic page. Declare each in BOTH "
            . "resources/lang/en.json and resources/lang/ar.json -- and if the sentence already "
            . "exists under slightly different wording, REUSE that key rather than adding a near "
            . "duplicate.\n  %s",
            count($undeclared),
            implode("\n  ", $undeclared)
        ));
    }
}

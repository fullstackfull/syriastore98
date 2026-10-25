<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two shipped translation files are well-formed and carry no duplicate keys.
 * =============================================================================================
 *
 * WHY THIS EXISTS, AND IT IS NOT THE REASON IT LOOKS LIKE. Ledger finding F2 recorded that
 * `resources/lang/ar.json` held **17 duplicated keys** (2,874 raw keys, 2,857 distinct) and was
 * deliberately left alone: `json_decode` keeps the LAST occurrence, so behaviour was
 * well-defined, and de-duplicating rewrites a translation file nobody asked to have rewritten.
 *
 * THEY ARE GONE NOW, AND NOT ON PURPOSE. Several Phase 02 increments added keys to these files
 * by decoding and re-encoding them, and a round-trip through an associative array silently drops
 * a duplicate. That is a file rewrite F2 said should be an owner decision, and it happened as a
 * side effect of adding a string -- so it is stated in the ledger rather than discovered later.
 *
 * MEASURED, because "behaviour-preserving" is a claim and not an argument. Every key duplicated
 * on `main` was compared to the value this branch now resolves for it:
 *
 *     duplicated keys on main : 17
 *     effective values changed: 0
 *     keys present on main and absent now: 0
 *
 * Zero, because a re-encode keeps the same occurrence `json_decode` already kept. So the 17 dead
 * lines went and every effective string is byte-identical to `main`.
 *
 * WHAT THIS TEST IS FOR GOING FORWARD. Not the history above -- the next one. A duplicate
 * reintroduced by a merge or a hand edit is invisible: the file parses, the application works,
 * and the earlier of the two translations is simply never used, which is indistinguishable from
 * a missing translation when somebody later tries to fix the string they can see in the file.
 * This fails instead.
 *
 * A UNIT TEST, with no framework boot: it reads two files and parses them, so it needs neither a
 * database nor the container.
 */
class TranslationFileIntegrityTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function languageFileProvider(): array
    {
        return [
            'ar' => ['resources/lang/ar.json'],
            'en' => ['resources/lang/en.json'],
        ];
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    #[DataProvider('languageFileProvider')]
    public function test_the_file_is_valid_json(string $relative): void
    {
        $path = $this->path($relative);

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(),
            "{$relative} is not valid JSON: " . json_last_error_msg()
            . ' -- Laravel\'s JSON loader throws on this, so EVERY translated string on EVERY '
            . 'screen falls back to its key.');

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
    }

    #[DataProvider('languageFileProvider')]
    public function test_the_file_has_no_duplicate_keys(string $relative): void
    {
        $path = $this->path($relative);
        $raw = (string) file_get_contents($path);

        /*
         * Counted from the TEXT, because the parsed array cannot answer this question -- a
         * duplicate is exactly what parsing destroys. The pattern matches a quoted key at the
         * start of a line followed by a colon, allowing backslash escapes inside it (several
         * keys carry `\"` and `\\`).
         *
         * One-key-per-line is a property of these two files, not of JSON in general: both are
         * written with `indent=4` and one entry per line. A reformat to a single line would make
         * this pattern find nothing, which is why the premise below asserts the count instead of
         * trusting it.
         */
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $matches);

        $rawKeys = $matches[1];
        $parsed = json_decode($raw, true);

        $this->assertIsArray($parsed);

        // PREMISE: the scan found as many keys as the parser did, give or take the duplicates it
        // is looking for. A pattern that matched nothing -- after a reformat, say -- would make
        // the assertion below pass for free, and a scan that passes vacuously is the single most
        // common way a census in this project has missed what it claimed to cover.
        $this->assertGreaterThanOrEqual(count($parsed), count($rawKeys),
            "the duplicate scan of {$relative} found FEWER keys than the parser did, so the "
            . 'pattern no longer matches this file\'s formatting and this test is measuring '
            . 'nothing. Fix the pattern rather than the expectation.');

        $duplicates = array_keys(array_filter(
            array_count_values($rawKeys),
            fn (int $count) => $count > 1
        ));

        $this->assertSame([], $duplicates, sprintf(
            "%s declares %d key(s) more than once: %s\n"
            . "PHP's json_decode keeps the LAST occurrence, so the earlier translation is dead "
            . 'text -- which looks exactly like a missing translation to whoever next tries to '
            . 'correct the string they can see in the file. Keep one entry per key.',
            $relative,
            count($duplicates),
            implode(', ', array_slice($duplicates, 0, 20))
        ));
    }

    /**
     * Every key `ar.json` carries, `en.json` carries too.
     *
     * NOT the other way round, deliberately: English is the source language and `__()` falls back
     * to the key itself, so an English string with no `en.json` entry still renders correctly. An
     * Arabic-only key is the asymmetry that matters -- it means a string was translated and then
     * renamed or removed on the English side, and nothing will ever look it up again.
     */
    public function test_arabic_declares_no_key_english_has_dropped(): void
    {
        $ar = json_decode((string) file_get_contents($this->path('resources/lang/ar.json')), true);
        $en = json_decode((string) file_get_contents($this->path('resources/lang/en.json')), true);

        $this->assertIsArray($ar);
        $this->assertIsArray($en);

        $orphans = array_diff(array_keys($ar), array_keys($en));

        /*
         * A COUNT, NOT ZERO. This file inherits 31 such keys from the vendored platform and from
         * before Phase 02 -- they are not this phase's to delete, and asserting zero would mean
         * either a sweep nobody authorised or an immediately-skipped test. The number is pinned
         * so a NEW one fails: adding an Arabic key without its English sibling raises it.
         *
         * 31 IS MEASURED, not chosen. (A first draft of this file asserted 97, which was a
         * number nobody had counted -- it passed, and would have gone on passing while 66 new
         * orphans accumulated underneath it. A ratchet set above the real value is not a
         * ratchet.) If a legitimate cleanup lowers it, lower the expectation in the commit that
         * does the cleanup.
         */
        $this->assertLessThanOrEqual(31, count($orphans), sprintf(
            "ar.json now declares %d key(s) that en.json does not (was 31).\n"
            . "New ones: a string was translated into Arabic without an English entry, or was "
            . 'renamed on the English side and left behind here. First few: %s',
            count($orphans),
            implode(' | ', array_slice($orphans, 0, 10))
        ));
    }
}

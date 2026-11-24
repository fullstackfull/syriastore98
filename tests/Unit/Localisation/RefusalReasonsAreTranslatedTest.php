<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\TestCase;

/**
 * A REFUSAL REASON SHOWN TO A MERCHANT MUST EXIST IN BOTH LANGUAGE FILES.
 *
 * `AbandonedMessageDispatch::blockedReason()` returns the sentence a merchant is shown when the
 * abandoned-basket WhatsApp nudge is not sent. Four of its five sentences were in both
 * `resources/lang/en.json` and `ar.json`; the fifth — the marketing-consent refusal added by D8-2b
 * — was in NEITHER, so an Arabic merchant was shown the raw English sentence while every sibling
 * reason rendered in Arabic.
 *
 * WHY THE EXISTING RATCHET CANNOT CATCH THIS, which is the whole reason this file exists.
 * `TranslationFileIntegrityTest` compares `ar.json` AGAINST `en.json`: it catches a key present in
 * one and missing from the other, which is the mistake that reddened CI earlier in this work
 * (seventeen Arabic strings against fourteen English ones). A key missing from BOTH files is
 * symmetric, so it passes that comparison silently — and `__()` returns the key itself, so the
 * screen looks fine in English and nobody notices. That is the blind spot, and it is only visible
 * from the SOURCE side: you have to start from the strings the code can emit.
 *
 * WHY THIS IS NOT REPO-WIDE, and that is deliberate rather than lazy. Asserting that every `__()`
 * literal in `app/` exists in both files would fail massively and for a legitimate reason: `__()`
 * falls back to its key, and much of this codebase relies on that for English. Making that a gate
 * would be a different, much larger decision about how this product is translated. What is pinned
 * here is the narrow class where the fallback is actually harmful: a sentence that EXPLAINS A
 * REFUSAL to a merchant working in Arabic. Add a method to `SOURCES` when it joins that class.
 */
final class RefusalReasonsAreTranslatedTest extends TestCase
{
    /** file => the method whose returns are merchant-facing refusal reasons. */
    private const SOURCES = [
        'app/Services/AbandonedMessageDispatch.php' => 'blockedReason',
    ];

    /** @return array<string, array<string, bool>> */
    private function strings(string $relative): array
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;

        $this->assertFileExists($path, "{$relative} does not exist — this test's premise is stale.");

        preg_match_all(
            "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
            (string) file_get_contents($path),
            $matches
        );

        return array_fill_keys(
            array_map(static fn (string $s): string => str_replace("\\'", "'", $s), $matches[1]),
            true
        );
    }

    /** @return array<string, string> */
    private function language(string $file): array
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/' . $file;

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "resources/lang/{$file} is not valid JSON: " . json_last_error_msg());

        return $decoded;
    }

    public function test_the_premise_that_these_sources_emit_refusal_sentences(): void
    {
        foreach (self::SOURCES as $relative => $method) {
            $found = $this->strings($relative);

            // Without this the two real assertions below would pass vacuously on an empty scan —
            // the failure mode this project has actually hit with source-scanning tests.
            $this->assertGreaterThanOrEqual(
                4,
                count($found),
                "Found " . count($found) . " translatable strings in {$relative}; the scan is probably stale "
                . "(expected the {$method}() reasons)."
            );
        }
    }

    public function test_every_refusal_sentence_exists_in_english(): void
    {
        $en = $this->language('en.json');

        foreach (self::SOURCES as $relative => $method) {
            foreach (array_keys($this->strings($relative)) as $sentence) {
                $this->assertArrayHasKey(
                    $sentence,
                    $en,
                    "{$relative} can show this sentence and resources/lang/en.json has no entry for it:\n  "
                    . $sentence
                );
            }
        }
    }

    public function test_every_refusal_sentence_exists_in_arabic(): void
    {
        $ar = $this->language('ar.json');

        foreach (self::SOURCES as $relative => $method) {
            foreach (array_keys($this->strings($relative)) as $sentence) {
                $this->assertArrayHasKey(
                    $sentence,
                    $ar,
                    "{$relative} can show this sentence and resources/lang/ar.json has no entry for it, so an "
                    . "Arabic merchant is shown the raw English:\n  " . $sentence
                );
            }
        }
    }
}

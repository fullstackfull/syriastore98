<?php

namespace Tests\Unit\Localisation;

use App\Support\Metrics\MetricCaveat;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * EVERY CAVEAT MESSAGE MUST EXIST IN BOTH LANGUAGE FILES, BECAUSE A CAVEAT IS SHOWN TO A MERCHANT.
 *
 * `MetricCaveat::message()` returns `__(self::MESSAGES[$caveat])`, so an untranslated entry renders
 * as its own English key on an Arabic screen. Measured on 2026-11-21, before ANA-007 put the first
 * one on a screen: **11 of the 13 messages were in NEITHER `en.json` nor `ar.json`**. The only two
 * that were translated are the coupon pair, added days earlier when a figures class happened to
 * repeat their sentences as its own `__()` literals.
 *
 * THAT IS WHY THE VOCABULARY HAD NEVER REACHED A SCREEN. `MetricCaveat` had zero consumers outside
 * `app/Support/Metrics/` and `tests/` — recorded as the unclosed half of `GAP-201` — so
 * "an export must carry the same caveats as the screen" was vacuous for all six `ReportController`
 * exports: there was nothing on the screens to carry. Showing one would have shown an Arabic
 * merchant raw English, which is the defect `RefusalReasonsAreTranslatedTest` was written for one
 * commit earlier, in the same shape, in a different place.
 *
 * WHY A WHOLE-VOCABULARY GATE IS PROPORTIONATE HERE, where a repo-wide one would not be.
 * `MESSAGES` is a closed, hand-written list of 13 sentences whose entire purpose is to be displayed.
 * There is no legitimate reason for one to be missing, and the next person to put a caveat on a
 * screen should not have to rediscover this. Contrast the deliberately narrow scope of
 * `RefusalReasonsAreTranslatedTest`, which explains why asserting every `__()` literal in `app/`
 * would be wrong.
 *
 * It reads `MESSAGES` by reflection rather than re-listing the sentences, so a new caveat is covered
 * the moment it is added — a copy of the list here would be one more thing to forget.
 */
final class MetricCaveatMessagesAreTranslatedTest extends TestCase
{
    /** @return array<string, string> caveat key => English sentence */
    private function messages(): array
    {
        // A CONSTANT, not a property — `getProperty('MESSAGES')` throws, which is how the first
        // version of this file failed. `getReflectionConstant()` reaches a private const too.
        $constant = (new ReflectionClass(MetricCaveat::class))->getReflectionConstant('MESSAGES');

        $this->assertNotFalse($constant, 'MetricCaveat::MESSAGES no longer exists.');

        /** @var array<string, string> $messages */
        $messages = $constant->getValue();

        return $messages;
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

    public function test_the_premise_that_the_vocabulary_can_be_read(): void
    {
        $messages = $this->messages();

        // Without this, both assertions below would pass vacuously if reflection ever stopped
        // finding MESSAGES — the source-scan failure mode this project has hit before.
        $this->assertGreaterThanOrEqual(13, count($messages),
            'Read ' . count($messages) . ' caveat messages; MetricCaveat::MESSAGES is 13 or more, so '
            . 'this scan is stale.');

        foreach ($messages as $key => $sentence) {
            $this->assertIsString($sentence, "The message for {$key} is not a string.");
            $this->assertNotSame('', trim($sentence), "The message for {$key} is empty.");
        }
    }

    public function test_every_caveat_message_exists_in_english(): void
    {
        $en = $this->language('en.json');

        foreach ($this->messages() as $key => $sentence) {
            $this->assertArrayHasKey($sentence, $en,
                "MetricCaveat::{$key} has no entry in resources/lang/en.json:\n  " . $sentence);
        }
    }

    public function test_every_caveat_message_exists_in_arabic(): void
    {
        $ar = $this->language('ar.json');

        foreach ($this->messages() as $key => $sentence) {
            $this->assertArrayHasKey($sentence, $ar,
                "MetricCaveat::{$key} has no entry in resources/lang/ar.json, so an Arabic merchant "
                . "would be shown this raw English sentence:\n  " . $sentence);
        }
    }
}

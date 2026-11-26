<?php

namespace Tests\Unit\Kyc;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * EVERY STRING THE VERIFICATION SURFACES SHOW A MERCHANT HAS AN ARABIC TRANSLATION.
 * =================================================================================================
 *
 * WHY A RATCHET AND NOT A ONE-OFF PASS. CLAUDE.md: *"Bilingual Arabic (RTL) and English (LTR). Any
 * UI change must be checked in both directions."* A missing entry in `resources/lang/ar.json` is
 * the quietest possible failure: `__('Document type')` with no translation returns THE ENGLISH
 * STRING, so the Arabic page renders, in the right direction, with English words in it — HTTP 200,
 * no error, no log line. `KycScreensRenderBilinguallyTest` would not catch it either, because the
 * page it renders is perfectly valid. Only a census of the keys can.
 *
 * MEASURED WHEN THIS LANDED: of the 83 `__()` strings across these seven files, **9 already had an
 * Arabic entry and 74 did not**. All 74 were added in the same change, using the master plan's own
 * section 06-C vocabulary — توثيق for verification, الوثائق الخاصة for private documents,
 * المراجعات for reviews, التجديد for renewal, سجلات الوصول for access logs — rather than a
 * freshly invented terminology for an owner-approved requirement.
 *
 * COMMENTS ARE STRIPPED, and here that is load-bearing rather than ceremonial: these files carry
 * long docblocks that QUOTE `__('kyc.kind.commercial_register')` while explaining why the dotted
 * key must never reach a page. A census reading comments would demand a translation for a key no
 * screen renders. CLAUDE.md records eight occasions on which a census was satisfied — or broken —
 * by prose about the thing it measures.
 *
 * THE `kyc.` PREFIX IS EXCLUDED, DELIBERATELY. `KycAuthority::kindLabel()` uses
 * `lang/<locale>/kyc.php`'s `kind.<name>` keys when they exist and humanises the machine name
 * otherwise, because the document kinds are OWNER-DECIDED and cannot be shipped translated. A
 * census demanding them would be demanding an answer to `D-P6-46`.
 *
 * WHAT IT DOES NOT CLAIM: that the Arabic reads like Arabic, or that it is the terminology the
 * owner would choose. That is human review, and it is named as such in the handover rather than
 * implied by a green test.
 */
class KycStringsAreTranslatedTest extends TestCase
{
    use StripsSourceComments;

    private const ARABIC = 'resources/lang/ar.json';

    /** The surfaces whose strings a merchant or an operator actually reads. */
    private const SOURCES = [
        'resources/views/kyc/index.blade.php',
        'resources/views/kyc/queue.blade.php',
        'resources/views/kyc/access-log.blade.php',
        'app/Http/Controllers/KycVerificationController.php',
        'app/Services/Kyc/KycDocumentStore.php',
        'app/Services/Kyc/KycReview.php',
        'app/Services/Kyc/KycAccessLog.php',
    ];

    /** @return list<string> */
    private function translatableStrings(): array
    {
        $found = [];

        foreach (self::SOURCES as $relative) {
            $code = $this->sourceWithoutComments($relative);

            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $matches);

            foreach ($matches[1] as $literal) {
                $key = str_replace("\\'", "'", $literal);

                // See the class docblock: the owner-decided document kinds are not censused.
                if (str_starts_with($key, 'kyc.')) {
                    continue;
                }

                $found[$key] = true;
            }
        }

        return array_keys($found);
    }

    private function arabic(): array
    {
        $path = dirname(__DIR__, 3) . '/' . self::ARABIC;

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, self::ARABIC . ' is not valid JSON');

        return $decoded;
    }

    /** The census cannot pass by finding nothing. */
    public function test_the_census_finds_the_strings_it_is_about(): void
    {
        $strings = $this->translatableStrings();

        $this->assertGreaterThan(
            60,
            count($strings),
            'the walk found almost no translatable strings, so the assertion below would be vacuous'
        );

        $this->assertContains('Verification', $strings);
        $this->assertContains('Document access log', $strings);
    }

    public function test_every_string_these_surfaces_show_has_an_arabic_entry(): void
    {
        $arabic = $this->arabic();
        $missing = [];

        foreach ($this->translatableStrings() as $key) {
            if (! array_key_exists($key, $arabic)) {
                $missing[] = $key;
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "These verification strings have no Arabic translation, so the Arabic page renders them\n"
            . "in English with no error and no log line:\n  "
            . implode("\n  ", $missing)
            . "\n\nAdd each one to " . self::ARABIC . '.'
        );
    }

    /** And no entry is an empty string, which renders as nothing at all rather than as English. */
    public function test_no_arabic_entry_for_these_surfaces_is_empty(): void
    {
        $arabic = $this->arabic();
        $empty = [];

        foreach ($this->translatableStrings() as $key) {
            if (array_key_exists($key, $arabic) && trim((string) $arabic[$key]) === '') {
                $empty[] = $key;
            }
        }

        $this->assertSame([], $empty, 'an empty translation renders as a blank, which is worse than English');
    }

    /**
     * A TRANSLATION CARRYING A PLACEHOLDER MUST CARRY THE SAME ONES.
     *
     * `__('Valid until :date', ['date' => ...])` with a translation that dropped `:date` renders
     * "صالح حتى" and the date simply vanishes — no error, no empty string, just a missing fact on a
     * compliance screen. The reverse, a translation naming a placeholder the caller never passes,
     * prints the literal `:date` to the reader.
     */
    public function test_placeholders_survive_translation(): void
    {
        $arabic = $this->arabic();
        $problems = [];

        foreach ($this->translatableStrings() as $key) {
            if (! array_key_exists($key, $arabic)) {
                continue;       // reported by the test above
            }

            preg_match_all('/:([a-z_]+)/', $key, $english);
            preg_match_all('/:([a-z_]+)/', (string) $arabic[$key], $translated);

            $want = array_unique($english[1]);
            $have = array_unique($translated[1]);

            sort($want);
            sort($have);

            if ($want !== $have) {
                $problems[] = $key . ' — expects ' . json_encode($want) . ', translation has ' . json_encode($have);
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }
}

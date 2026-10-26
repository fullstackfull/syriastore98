<?php

namespace Tests\Unit\Merchant;

use PHPUnit\Framework\TestCase;

/**
 * P2-03-4. Every `__()` string on a Phase 02 merchant surface has an Arabic translation.
 * =====================================================================================
 *
 * Arabic is the platform's primary language. A `__('Cost Price')` with no `ar.json` key does not
 * fail, warn or log -- it renders the English key, so the Arabic form shows an English label and
 * nothing anywhere says so. The only way to know is to compare the two sets, which is what this
 * does.
 *
 * MEASURED when it was written: 16 untranslated strings across these surfaces, and FOUR of them
 * were added by EARLIER PHASE 02 SLICES OF THIS PROJECT -- 'You are not allowed to do this.',
 * 'The delivery boy could not be assigned.', 'Your session has expired. Please reload the page.',
 * 'Something went wrong. Please try again.'. Work done under a bilingual rule had shipped
 * English-only four times before anything measured it. That is the case for a ratchet rather
 * than a one-off sweep.
 *
 * SCOPE IS THE DIRECTORY LIST, DELIBERATELY. It covers the surfaces Phase 02 owns, not the whole
 * `resources/views` tree (~900 vendor-derived files whose backlog is not this phase's). Adding a
 * directory here is how a later phase widens it.
 *
 * ON THE EXEMPTION. Exactly one string is exempt and it is named in full below with the reason.
 * The list is asserted to be EXACTLY that one, so a second exemption cannot be added quietly.
 *
 * NOT A DUPLICATE OF `Tests\Unit\Localisation\TranslationFileIntegrityTest`, which was already
 * here and asks a different question. That one compares the two JSON files to each other -- valid
 * JSON, no duplicate keys, and a ratchet on keys `ar` declares that `en` does not. This one
 * compares the VIEWS to `ar.json`: a string can be absent from both files and that test passes
 * while the merchant sees English. The two caught different halves of the same change -- this one
 * found the 16 untranslated strings, and that one then failed because the first fix added them to
 * `ar.json` only. Translations belong in BOTH files.
 */
class ArabicUiCoverageTest extends TestCase
{
    /**
     * Surfaces Phase 02 is responsible for.
     *
     * @return list<string> glob patterns relative to the repository root
     */
    private const SURFACES = [
        'resources/views/product/*.blade.php',
        'resources/views/order/*.blade.php',
        'resources/views/components/ss/*.blade.php',
        'resources/views/dashboard/*.blade.php',
        'resources/views/layouts/*.blade.php',
    ];

    /**
     * THE ONE EXEMPT STRING, and why it is not translated.
     *
     * A WorkDo demo return policy hard-coded into the PRINTED CUSTOMER RECEIPT
     * (`order/receipt.blade.php:262`). It names an unrelated brand ("a Milo Showroom"), and it
     * is truncated mid-sentence -- it ends "and sales c". Translating it would publish a refund
     * policy that is not Store Syria's, in Arabic, on every printed receipt. Removing or
     * rewriting it states a refund rule, which is a commercial decision and reserved
     * ("ولا تغيّر قواعد التسوية أو رد الأموال دون ضرورة مثبتة ومراجعة مستقلة").
     *
     * It is recorded as a decision point in
     * `docs/development/phase-2/P2-03-4-VARIANT-PERSISTENCE-DECISION.md`; the owner supplies the
     * real text or authorises dropping the paragraph, and then this exemption goes away.
     */
    private const EXEMPT = [
        'Merchandise may not be returned for refund at any time. Power bases cannot be',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param  list<string>|null  $patterns  a subset of SURFACES, or null for all of them
     * @return array<string,string> key => first file it was seen in
     */
    private static function collectKeys(?array $patterns = null): array
    {
        $keys = [];
        foreach ($patterns ?? self::SURFACES as $pattern) {
            foreach (glob(self::root() . '/' . $pattern) ?: [] as $file) {
                $source = file_get_contents($file);
                if ($source === false) {
                    continue;
                }

                // `__('...')` and `__("...")`, with escaped quotes allowed inside.
                preg_match_all(
                    '/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]|__\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,)]/',
                    $source,
                    $m,
                    PREG_SET_ORDER
                );

                foreach ($m as $match) {
                    $raw = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $key = str_replace(["\\'", '\\"'], ["'", '"'], $raw);

                    // `__($variable)` and `__(':placeholder')` are not literal strings to translate.
                    if (str_starts_with($key, ':')) {
                        continue;
                    }

                    $keys[$key] ??= str_replace(self::root() . '/', '', $file);
                }
            }
        }

        return $keys;
    }

    /** @return array<string,string> */
    private static function arJson(): array
    {
        $path = self::root() . '/resources/lang/ar.json';
        self::assertFileExists($path);

        /** @var array<string,string> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * PER SURFACE, not in total. A whole-scan threshold does not do the job: deleting the
     * `product/` pattern left the other four yielding 227 keys, which cleared a 200 floor and
     * the premise passed while a third of the surface area went unscanned. Measured -- the first
     * version of this test was written with a total and the revert check caught it. Each glob is
     * therefore asserted to match files AND to yield strings on its own.
     */
    public function test_every_surface_is_scanned_and_yields_strings(): void
    {
        foreach (self::SURFACES as $pattern) {
            $files = glob(self::root() . '/' . $pattern) ?: [];
            $this->assertNotEmpty($files,
                "premise: `{$pattern}` matched no files, so nothing in it is checked for a "
                . 'translation. Fix the pattern or remove it deliberately.');

            $keys = self::collectKeys([$pattern]);
            $this->assertNotEmpty($keys,
                "premise: `{$pattern}` matched " . count($files) . ' file(s) but yielded no '
                . '`__()` strings, which means the regex no longer matches this surface.');
        }
    }

    public function test_every_phase_02_surface_string_has_an_arabic_translation(): void
    {
        $ar = self::arJson();
        $keys = self::collectKeys();

        $missing = [];
        foreach ($keys as $key => $file) {
            if (array_key_exists($key, $ar)) {
                continue;
            }

            foreach (self::EXEMPT as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }

            $missing[] = sprintf('%s  [%s]', $this->oneLine($key), $file);
        }

        $this->assertSame([], $missing,
            "These strings render in ENGLISH on an Arabic merchant's screen. Add each to "
            . "`resources/lang/ar.json`:\n  " . implode("\n  ", $missing));
    }

    /**
     * The exemption list is exactly one entry, and that entry is still present in the view it
     * exempts. Both halves matter: the first stops a second exemption being added quietly, the
     * second means the exemption disappears once the string does.
     */
    public function test_the_exemption_list_is_exactly_the_one_declared_string(): void
    {
        $this->assertCount(1, self::EXEMPT,
            'a second exemption was added -- read the EXEMPT docblock; an untranslated string is '
            . 'exempt only when translating it would state a commercial rule');

        $receipt = file_get_contents(self::root() . '/resources/views/order/receipt.blade.php');
        $this->assertNotFalse($receipt);
        $this->assertStringContainsString(self::EXEMPT[0], $receipt,
            'the exempt string is no longer in the receipt, so the exemption should be deleted '
            . 'along with it');
    }

    private function oneLine(string $value): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($flat) > 90 ? (mb_substr($flat, 0, 90) . '...') : $flat;
    }
}

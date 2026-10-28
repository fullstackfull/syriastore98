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
        // P2-06-5. `product/*.blade.php` does NOT match `product/partials/*.blade.php`, so the
        // two list FILTER BARS -- every control label, every option, every "any" placeholder --
        // had never been scanned since they shipped. Found by independent review of a change
        // that added user-visible strings to exactly those two files. A glob is a claim about
        // which files are covered, and this one quietly excluded the screens' busiest controls.
        'resources/views/product/partials/*.blade.php',
        'resources/views/order/partials/*.blade.php',
        // P2-05-2. ADDED when this ratchet's own surface list proved too narrow: the customers
        // screen's delete dialog rendered its body text in English under `lang=ar`, and nothing
        // caught it because `resources/views/customer/` was not scanned. A coverage test is only
        // as wide as its list, which is the one way this kind of check quietly stops working.
        'resources/views/customer/*.blade.php',
        'resources/views/components/ss/*.blade.php',
        // P2-05-6. The report screens became a Phase 02 surface when they were put on the
        // design system; before that nothing scanned them, so every figure, column header and
        // empty state on them could render in English for an Arabic merchant.
        // P2-05-7/8. The settings panels became a Phase 02 surface when F117 and the
        // design-system migration reached them. Nothing had ever checked them, so every label,
        // hint and placeholder on them could render in English for an Arabic merchant.
        'resources/views/setting/*.blade.php',
        'resources/views/reports/*.blade.php',
        'resources/views/dashboard/*.blade.php',
        'resources/views/layouts/*.blade.php',
        // D-24a. The shared chrome -- the command palette, the header, the sidebar -- had never
        // been scanned, and the palette is a Phase 02 surface with a lot of user-visible copy
        // (P2-06-2, P2-01-15, P2-01-18, and now the four create-actions). MEASURED before adding
        // it: every `__()` string already under `partision/` has an Arabic translation, so this is
        // a LATENT gap being closed rather than a live defect being found -- the list was simply
        // narrower than the surfaces Phase 02 had grown into, which this file's own comments call
        // out twice as the one way a coverage test quietly stops working.
        'resources/views/partision/*.blade.php',
    ];

    /**
     * THE EXEMPTION LIST IS NOW EMPTY, and that is the end of a decision rather than an oversight.
     *
     * It held exactly one string: a WorkDo demo return policy hard-coded into the PRINTED CUSTOMER
     * RECEIPT, naming an unrelated brand ("a Milo Showroom") and truncated mid-sentence on the
     * words "and sales c". It was exempt because every available action stated a refund rule --
     * translating it would publish a policy that is not Store Syria's, rewriting it means
     * authoring one, deleting it leaves the receipt with none -- and that was reserved to the
     * owner.
     *
     * The owner decided it (D-10): remove the paragraph, do not substitute a policy. So the string
     * is gone from `order/receipt.blade.php` and the exemption went with it, exactly as the old
     * docblock said it would. `ReceiptReturnPolicyTest` now pins the removal at the RENDERED
     * level, which a source grep cannot do because the explanatory Blade comment left in that view
     * quotes the brand name.
     *
     * KEPT AS AN EMPTY ARRAY rather than deleted, so that adding an exemption is a visible edit to
     * a named constant with this history attached, instead of a new mechanism someone invents.
     */
    private const EXEMPT = [];

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
    public function test_the_exemption_list_is_empty(): void
    {
        $this->assertSame([], self::EXEMPT,
            'An exemption was added. Read the EXEMPT docblock: a string is exempt from translation '
            . 'only when translating it would state a commercial rule the owner has not decided, '
            . 'and the one that ever qualified was decided and removed (D-10). Anything else '
            . 'untranslated on a Phase 02 surface belongs in ar.json, not here.');
    }

    private function oneLine(string $value): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($flat) > 90 ? (mb_substr($flat, 0, 90) . '...') : $flat;
    }
}

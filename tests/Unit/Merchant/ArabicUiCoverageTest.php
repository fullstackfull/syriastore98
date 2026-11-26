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

        /*
         * GAP-104's official-site content editor. Added because its strings were invisible to this
         * census for the SAME reason the comment three lines above records: the glob list is the
         * scan, so a new directory is simply not looked at. `resources/views/platform/*.blade.php`
         * is non-recursive, so `platform/content/` was unscanned and fourteen of that screen's
         * sixteen new strings would have shipped untranslated with this test green.
         *
         * That is now the third time this list has been the thing at fault rather than the code it
         * measures. A coverage test is only as wide as its list.
         */
        'resources/views/platform/content/*.blade.php',
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
        // P2-03-4 / #12. The finance screens -- the wallet-transaction ledger, the
        // order-financials table and the withdrawal-requests screen -- became a Phase 02
        // surface when Wave 10 put their tables on the design system. Nothing had ever
        // scanned them, so measured when this line was added: 45 `__()` strings rendered in
        // ENGLISH for an Arabic merchant on the screens that show their own money.
        'resources/views/finance/*.blade.php',

        // ADDED 2026-11-21. `/analytics` (P5-4b, P5-5, P5-6) and `/platform/analytics` (P5-7) were
        // built with every string in `__()` and both language files updated by hand, but NEITHER
        // directory was scanned — so that discipline rested on nobody forgetting. Measured before
        // adding: zero `resources/views/analytics` or `.../platform` globs here, which is why a
        // read-only audit of the MKT/ANA gaps flagged this as the likeliest way the next analytics
        // slice goes red. Adding the globs covers RETROACTIVELY: every string already on those two
        // screens is now asserted, not just future ones.
        'resources/views/analytics/*.blade.php',
        'resources/views/platform/*.blade.php',

        // ADDED WITH THE SURFACE ITSELF (MKT-004, 2026-11-21), which is the only time this list has
        // ever been widened BEFORE a gap existed rather than after one was measured. This file's own
        // comments call out twice that a coverage test is only as wide as its list and that a narrow
        // glob is the one way such a check quietly stops working -- so a new merchant screen gets
        // its glob in the same change that creates it, not in the audit that finds it later.
        'resources/views/customer_segments/*.blade.php',
        // MKT-007, added with the surface itself for the same reason as the line above.
        'resources/views/recovery_queue/*.blade.php',

        // ADDED 2026-11-25, GAP-100 / P03-B-07, and this pair is the clearest case this list has
        // ever had for the warning it already repeats twice.
        //
        // `theme_customize/` holds the theme editor's section-order screen. Commit 5608bd54 gave
        // that screen its keyboard path and introduced FIVE user-visible strings, every one of
        // them correctly wrapped in `__()` — and no glob here matched the directory, so all five
        // rendered in ENGLISH for an Arabic merchant and nothing said so. `__()` does not warn.
        // MEASURED before this line was added: four of the five absent from ar.json (the fifth,
        // ':section moved to position :n of :total', is invisible to `collectKeys()` because keys
        // beginning with a colon are skipped as non-literals — see the note on that skip below).
        //
        // `attribute_option/` is the design-system pilot screen and the second of the two
        // sortables GAP-100 names. MEASURED before adding: all 24 of its `__()` strings already
        // have Arabic, so that glob closes a LATENT gap rather than a live one — it is added in
        // the same change that puts new strings on the screen, which this file's own comments
        // call the only time the list has ever been widened before a gap existed.
        'resources/views/theme_customize/*.blade.php',
        'resources/views/attribute_option/*.blade.php',
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

                    /*
                     * A BARE PLACEHOLDER IS NOT A STRING TO TRANSLATE. A KEY THAT MERELY BEGINS
                     * WITH ONE IS.
                     *
                     * TIGHTENED 2026-11-25. This was `str_starts_with($key, ':')`, which skipped
                     * every key whose first character is a colon — and that is a hole, not a
                     * filter. MEASURED across all 18 globs above: TEN keys were being skipped,
                     * including ':count left, above the low-stock level of :threshold.',
                     * ':n notifications you have not read' and the two reorder announcements this
                     * change adds. All ten happen to be translated already, so closing the hole
                     * turns nothing red — but a sentence that opens with a value is an ordinary
                     * sentence, and until now this census was blind to the whole class of them.
                     *
                     * `__($variable)` never reached here in the first place: the pattern above
                     * only matches a QUOTED literal, so a variable argument never produces a key.
                     * What is genuinely not translatable is a literal that is nothing but a
                     * placeholder, e.g. `__(':duration')`, and that is what this now skips.
                     */
                    if (preg_match('/^:[a-zA-Z_][a-zA-Z0-9_]*$/', $key) === 1) {
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

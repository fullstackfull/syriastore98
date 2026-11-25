<?php

namespace Tests\Unit\Localisation;

use App\Payments\PaymentTypeLabel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * P03-ACC-02 / GAP-096 — THE THREE NAMED ARABIC INVENTORY NUMBERS, PINNED IN BOTH DIRECTIONS.
 * ===========================================================================================
 *
 * `PHASE-03-EXECUTION-PREP.md` §Acceptance criteria / Bilingual names three numbers and demands
 * that each be *"closed or explicitly accepted with a reason"*:
 *
 *     35 English keys with no Arabic row
 *     28 Arabic values identical to their key      (29 when GAP-096 re-measured it; 26 now)
 *     31 containing no Arabic-script character     (28 now)
 *
 * NEITHER HALF OF THAT CRITERION HAD HAPPENED, AND THE REASON IS WHY THIS FILE EXISTS. The
 * numbers were not closed, and they were not dispositioned either -- and the one ratchet that
 * looked like it covered them, `TranslationFileIntegrityTest`, bounds a DIFFERENT number: the
 * ar-only SURPLUS (keys `ar.json` declares that `en.json` does not, 29, bounded at 31). Phase 03
 * added roughly 380 Arabic strings, so the file grew while all three measured defects stood
 * still. GAP-096's own wording is the point: *"the ratchet that exists measures something else,
 * which makes the gap easy to mistake for closed."*
 *
 * RE-MEASURED 2026-11-25 against the tree as it stands, which is the first thing the next person
 * should redo rather than trust:
 *
 *     en.json 4,676 keys   ar.json 4,670 keys   (the prep inventory counted 3,527 / 3,523)
 *     missing Arabic rows                  35   UNCHANGED since the criterion was written
 *     Arabic value identical to its key    29 -> 26 after this change
 *     Arabic value with no Arabic script   31 -> 28 after this change
 *
 * WHAT THE TRIAGE FOUND, and it is not what the gap's next action assumed. All 35 of the
 * "missing Arabic rows" are PAYMENT-GATEWAY BRAND NAMES, every one of them a canonical
 * `PaymentTypeLabel::BRANDS` entry -- so the absence is not a backlog, it is the SHIPPED FIX.
 * `PaymentBrandNameTranslationTest` records why: Stripe was being listed as `الشريط` ("the
 * ribbon"), Paystack as `كومة الدفع` ("payment pile"), DPO as `مدير حماية البيانات` ("Data
 * Protection Officer"), and a merchant looking for Stripe could not find it. P2.5-SET-03 REMOVED
 * those Arabic values on purpose; `__()` then falls back to the key and the Arabic screen prints
 * `Stripe`, which is what it should print. Translating these 35 would revert a measured fix and
 * turn that test red for 15 of them. Writing them back as identity rows (`"Stripe": "Stripe"`)
 * would render identically and merely move 35 rows from this group into the next one.
 *
 * So the disposition for group 1 is "correct as-is", and `test_every_english_key_with_no_arabic_row_is_a_canonical_payment_gateway_brand`
 * is what makes that a RULE rather than a comment: a new missing key that is NOT a gateway brand
 * fails, and says so.
 *
 * GROUP 2 IS A STRICT SUBSET OF GROUP 3 -- measured, and asserted below, because it halves the
 * triage and nothing said so. Every value byte-identical to its key necessarily contains no
 * Arabic script; the only rows in group 3 and not group 2 are the two that differ from their key
 * by WHITESPACE ALONE. So the real universe is 28 values, not 26 + 28 = 54.
 *
 * THREE OF THEM WERE GENUINELY UNTRANSLATED AND ARE NOW TRANSLATED. Each was decided by the
 * house style already in the file rather than by taste:
 *
 *     AOV      -> متوسط قيمة الطلب   a customers-table column title whose four siblings
 *                                    (`Order Value`, `Payments Collected`, `Net Retained`,
 *                                    `Status`) are all Arabic -- `CustomerDataTable:298`
 *     QTY      -> كمية                a POS table header beside `الاسم` / `الضريبة` / `السعر`;
 *                                    `Qty`, `Quantity` and `QUANTITY` already all read `كمية`,
 *                                    so this is the file's own word, not a new one
 *     S3 URL   -> عنوان S3            the fourth field of one settings panel whose other four
 *                                    read `مفتاح S3`, `منطقة S3`, `دلو S3`, `نقطة نهاية S3`,
 *                                    and `URL` alone reads `عنوان`
 *
 * THE REMAINING 25 ARE CORRECT AS THEY STAND, each with its reason recorded against it in
 * `DISPOSITION` below and its category explained on `REASONS`. No key may appear there without
 * one, which is asserted.
 *
 * A UNIT TEST WITH NO FRAMEWORK BOOT: it reads two files, parses them, and reflects one
 * first-party constant. No database, no container, no locale.
 */
final class ArabicInventoryRatchetTest extends TestCase
{
    /**
     * GROUP 1 — the 35 English keys with no Arabic row. ALL are gateway brand names.
     *
     * Kept untranslated by P2.5-SET-03. Do not add Arabic values here: `__()` falls back to the
     * key, which prints the brand in the provider's own spelling, and that is the fix.
     *
     * Both casings of two of them are present (`Stripe`/`STRIPE`, `Yookassa`/`yookassa`) because
     * both literals exist in the views; the brand map folds case, so both resolve to one brand.
     */
    private const MISSING_ARABIC_ROW = [
        'Aamarpay',
        'Benefit',
        'Braintree',
        'Cashfree',
        'Coingate',
        'Cybersource',
        'DPO',
        'DPO Pay',
        'Flutterwave',
        'IyziPay',
        'Mercado Pago',
        'Midtrans',
        'Mollie',
        'MyFatoorah',
        'Nepalste',
        'Paddle',
        'Paiement Pro',
        'PayFast',
        'PayHere',
        'PayU',
        'PaymentWall',
        'Paystack',
        'Paytabs',
        'PhonePe',
        'PowerTranz',
        'Razorpay',
        'STRIPE',
        'Sofort',
        'Sspay',
        'Stripe',
        'Tap',
        'Telegram',
        'Xendit',
        'Yookassa',
        'yookassa',
    ];

    /**
     * THE REASON CATEGORIES, so that "with a reason" is a closed vocabulary and not free text.
     *
     * A category is a claim about the STRING, not about effort. Each one says why an Arabic
     * reader is correctly served by a value carrying no Arabic script.
     */
    private const REASONS = [
        // A company, product or service name. Rendered in the owner's own spelling in every
        // locale, for the same reason `PaymentBrandNameTranslationTest` gives: a merchant has to
        // be able to find it. `Delivery Syria` is the courier integration this platform ships
        // (`api/delivery-syria/...`, `DeliverySyriaCredential`), `LYNOMIA OTP` is first-party.
        'BRAND' => 'a company, product or service name, rendered in its owner\'s own spelling',

        // The same rule as BRAND, but these are the payment gateways specifically -- held here as
        // an identity row rather than, as in group 1, by having no row at all. Both spellings
        // render the brand; the two groups are the same decision reached two ways.
        'GATEWAY_BRAND' => 'a payment gateway name; the same decision as group 1, held as an identity row',

        // An initialism Arabic readers read as the Latin letters. The file already holds this
        // distinction explicitly: `SEO` stays `SEO` while `SEO Settings` is
        // `إعدادات تحسين محركات البحث`, and `QR Code` is `رمز QR`. `POS` additionally has to stay
        // in one vocabulary with `PaymentTypeLabel::STORED_LITERALS`, which prints `POS`
        // untranslated on the order list -- and F146 found a translated `__('POS')` is how `没有`
        // got written into `orders.payment_type` in the first place.
        'INITIALISM' => 'a technical initialism Arabic readers read in Latin letters',

        // A unit symbol appended to a number (`GetCacheSize() . ' ' . __('MB')`), not a word.
        'UNIT_SYMBOL' => 'a unit symbol printed after a numeral, not a word',

        // Demo or example content: a sample phone number, a sample address, a `placeholder=`
        // format hint. There is no Arabic form of a made-up phone number.
        'DEMO_DATA' => 'sample or placeholder content, with no prose to translate',

        // A value some machine reads: a domain suffix, a version token that must equal its own
        // `value=` attribute, a comma-separated list of a gateway\'s channel codes.
        'MACHINE_VALUE' => 'a value consumed by code or by a third party, not read as language',

        // A numeric notation that is already language-neutral (`24/7`, `5.0`).
        'NOTATION' => 'a language-neutral numeric notation',

        // Punctuation. `" : "` is a separator printed after an attribute name; the Arabic value
        // drops the padding spaces, which is correct Arabic typography and deliberate.
        'PUNCTUATION' => 'punctuation, correct in Arabic without the English padding',

        // The view hands it to a third-party widget that is configured not to paint it:
        // `myfatoorah/includes/sectionForm.blade.php` sets `style.label.display = false`, so the
        // four `*Label` values are inert configuration. Translating one changes nothing on screen.
        'NOT_RENDERED' => 'passed to a widget that is configured not to display it',

        // A key nothing looks up. `"Twilio SID "` carries a TRAILING SPACE; the view calls
        // `__('Twilio SID')`, whose own row is correctly `معرّف Twilio (SID)`. The dead row is
        // kept rather than deleted because `en.json` declares it too, so removing it from
        // `ar.json` alone would move it into group 1 and raise that number.
        'DEAD_KEY' => 'a key no call site looks up; its live sibling is translated',
    ];

    /**
     * GROUPS 2 AND 3 — every value with no Arabic script, and why each is correct as it stands.
     *
     * 28 rows. The 26 of them that are BYTE-IDENTICAL to their key are group 2; the two listed in
     * `WHITESPACE_ONLY` differ from their key by whitespace alone and are group 3's only extras.
     */
    private const DISPOSITION = [
        ' : ' => 'PUNCTUATION',
        '(555) 123-4567' => 'DEMO_DATA',
        '.myshopify.com' => 'MACHINE_VALUE',
        '18761234567' => 'DEMO_DATA',
        '24/7' => 'NOTATION',
        '5.0' => 'NOTATION',
        'AWS S3' => 'BRAND',
        'AuthorizeNet' => 'GATEWAY_BRAND',
        'CVV' => 'INITIALISM',
        'CinetPay' => 'GATEWAY_BRAND',
        'Delivery Syria' => 'BRAND',
        'LYNOMIA OTP' => 'BRAND',
        'MB' => 'UNIT_SYMBOL',
        'NMI' => 'GATEWAY_BRAND',
        'OMCIV2,MOMO,CARD,FLOOZ ,PAYPAL' => 'MACHINE_VALUE',
        'POS' => 'INITIALISM',
        'PWA' => 'INITIALISM',
        'PaiementPro' => 'GATEWAY_BRAND',
        'PayTR' => 'GATEWAY_BRAND',
        'SEO' => 'INITIALISM',
        'SSLCommerz' => 'GATEWAY_BRAND',
        'Shopify' => 'BRAND',
        'Twilio SID ' => 'DEAD_KEY',
        'info@greentic.com' => 'DEMO_DATA',
        'myfatoorah.cardHolderNameLabel' => 'NOT_RENDERED',
        'v2' => 'MACHINE_VALUE',
        'v3' => 'MACHINE_VALUE',
        'xyz.com' => 'DEMO_DATA',
    ];

    /** The two rows in group 3 and not group 2: their value differs from the key by whitespace only. */
    private const WHITESPACE_ONLY = [' : ', 'Twilio SID '];

    /**
     * The 28 dispositioned keys as STRINGS.
     *
     * `DISPOSITION` is written as a PHP array literal, and PHP coerces a numeric string key in a
     * literal to an int exactly as `json_decode` does -- so `'18761234567' => 'DEMO_DATA'` is
     * stored under the integer. See `stringKeys()` for the reading this trap already cost.
     *
     * @return list<string>
     */
    private static function dispositionedKeys(): array
    {
        return array_map('strval', array_keys(self::DISPOSITION));
    }

    /**
     * THE THREE NAMED NUMBERS, as this change leaves them and as GAP-096's row now records them.
     *
     * Asserted by VALUE as well as by membership. Membership alone is the stronger check, but the
     * gap register and the acceptance criterion both speak in numbers, and a reader comparing the
     * document to the suite should find the same three figures in both.
     */
    private const EXPECTED_COUNTS = [
        'missing Arabic row' => 35,
        'value identical to its key' => 26,
        'value with no Arabic script' => 28,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> */
    private static function decode(string $relative): array
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * The keys of a decoded language file, every one of them a STRING.
     *
     * `json_decode` COERCES A NUMERIC KEY TO AN INT: `"18761234567"` -- a sample phone number, and
     * one of the 28 dispositioned rows -- comes back as the integer 18761234567 while its VALUE
     * stays the string `"18761234567"`. So `$value === $key` is false for that row under strict
     * comparison, and it silently dropped out of the key-identical scan: measured 25 where the
     * same census run outside PHPUnit said 26.
     *
     * AND CASTING AT THE WRITE SIDE DOES NOT FIX IT -- the first version of this file did
     * `$out[(string) $key] = $value`, which PHP coerces straight back to an integer key, because
     * a numeric string is never a string array key. The cast has to happen on the way OUT, at
     * every read, which is what this helper and the `(string) $key` in each loop below do.
     *
     * @param  array<array-key,mixed>  $decoded
     * @return list<string>
     */
    private static function stringKeys(array $decoded): array
    {
        return array_map('strval', array_keys($decoded));
    }

    /** English keys with no row at all in `ar.json`. @return list<string> */
    private static function missingArabicRows(): array
    {
        $missing = array_values(array_diff(
            self::stringKeys(self::decode('resources/lang/en.json')),
            self::stringKeys(self::decode('resources/lang/ar.json'))
        ));
        sort($missing, SORT_STRING);

        return $missing;
    }

    /** Arabic values byte-identical to their own key. @return list<string> */
    private static function valuesIdenticalToTheirKey(): array
    {
        $hits = [];
        foreach (self::decode('resources/lang/ar.json') as $key => $value) {
            if (is_string($value) && $value === (string) $key) {
                $hits[] = (string) $key;
            }
        }
        sort($hits, SORT_STRING);

        return $hits;
    }

    /** Arabic values carrying not one Arabic-script character. @return list<string> */
    private static function valuesWithNoArabicScript(): array
    {
        $hits = [];
        foreach (self::decode('resources/lang/ar.json') as $key => $value) {
            if (is_string($value) && preg_match('/\p{Arabic}/u', $value) !== 1) {
                $hits[] = (string) $key;
            }
        }
        sort($hits, SORT_STRING);

        return $hits;
    }

    /**
     * The failure text, which is the whole point of a ratchet: it has to say which direction
     * moved and what to do about each.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function ratchetMessage(string $what, array $expected, array $actual, string $onNew, string $onGone): string
    {
        $new = array_values(array_diff($actual, $expected));
        $gone = array_values(array_diff($expected, $actual));

        $lines = [sprintf(
            'The number of Arabic rows that are "%s" moved: %d declared, %d measured.',
            $what,
            count($expected),
            count($actual)
        )];

        if ($new !== []) {
            $lines[] = sprintf("\nNEW (%d) -- these are a NEW GAP:\n  %s\n%s",
                count($new), implode("\n  ", $new), $onNew);
        }

        if ($gone !== []) {
            $lines[] = sprintf("\nNO LONGER PRESENT (%d) -- this is an IMPROVEMENT, and it must not pass silently:\n  %s\n%s",
                count($gone), implode("\n  ", $gone), $onGone);
        }

        $lines[] = "\nThis is P03-ACC-02 / GAP-096. Whichever direction moved, update this file's "
            . "constants AND the EXPECTED_COUNTS above AND the GAP-096 row in "
            . 'docs/development/MASTER-PLAN-GAP-REGISTER.md in the same change, so the document '
            . 'and the suite never state different numbers again -- which is the mistake GAP-096 '
            . 'records.';

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------------------- premises

    /**
     * Both files are read, parse, and are the size they should be.
     *
     * Three of the assertions below are `assertSame` over a declared list, which an empty read
     * would fail rather than pass -- but the COUNTS could still be satisfied by a file that had
     * been truncated to something small and consistent, so the floor is asserted outright.
     */
    public function test_premise_both_language_files_parse_and_are_the_size_they_should_be(): void
    {
        $en = self::decode('resources/lang/en.json');
        $ar = self::decode('resources/lang/ar.json');

        $this->assertGreaterThan(4000, count($en),
            'en.json read as ' . count($en) . ' keys, so every scan in this file covers almost nothing.');
        $this->assertGreaterThan(4000, count($ar),
            'ar.json read as ' . count($ar) . ' keys, so every scan in this file covers almost nothing.');
    }

    /**
     * The Arabic-script probe really distinguishes Arabic from Latin.
     *
     * `\p{Arabic}` needs PCRE built with Unicode property support. Without it the pattern either
     * errors (returning false, which `!== 1` reads as "no Arabic script") and group 3 swells to
     * the whole file, or matches nothing. Both directions would be caught by the set assertion,
     * but not NAMED -- and a census whose instrument is broken is the failure this project has
     * hit most often, so it is checked on known values instead of inferred.
     */
    public function test_premise_the_arabic_script_probe_distinguishes_arabic_from_latin(): void
    {
        $this->assertSame(1, preg_match('/\p{Arabic}/u', 'نقل'),
            'The \p{Arabic} probe does not match Arabic text, so the no-Arabic-script scan would '
            . 'report the entire file. Check that PCRE has Unicode property support.');
        $this->assertSame(0, preg_match('/\p{Arabic}/u', 'Stripe'),
            'The \p{Arabic} probe matches Latin text, so the no-Arabic-script scan would report nothing.');
        $this->assertSame(1, preg_match('/\p{Arabic}/u', 'عنوان S3'),
            'A mixed Arabic/Latin value must count as carrying Arabic script; three of the rows '
            . 'this change translated are of exactly that shape.');
    }

    /** Every dispositioned row names a reason, and every reason is one of the declared categories. */
    public function test_premise_every_dispositioned_value_carries_a_declared_reason(): void
    {
        $unknown = [];
        foreach (self::DISPOSITION as $key => $reason) {
            if (! array_key_exists($reason, self::REASONS)) {
                $unknown[] = sprintf('%s => %s', (string) $key, $reason);
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "These rows claim a reason category that REASONS does not declare:\n  %s\n"
            . 'P03-ACC-02 asks for a reason per group, not free text. Add the category to REASONS '
            . 'with the sentence that justifies it, or use an existing one.',
            implode("\n  ", $unknown)
        ));

        $this->assertCount(28, self::DISPOSITION,
            'premise: DISPOSITION should hold one row per value with no Arabic script; it holds '
            . count(self::DISPOSITION) . '. A row removed here without the translation that '
            . 'justifies it makes the set assertions below fail with a less useful message.');

        foreach (self::WHITESPACE_ONLY as $key) {
            $this->assertArrayHasKey($key, self::DISPOSITION,
                'A whitespace-only row must also carry a reason: ' . $key);
        }
    }

    // ------------------------------------------------------------------------- the three numbers

    public function test_english_keys_with_no_arabic_row_are_exactly_the_dispositioned_set(): void
    {
        $expected = self::MISSING_ARABIC_ROW;
        sort($expected, SORT_STRING);
        $actual = self::missingArabicRows();

        $this->assertSame($expected, $actual, $this->ratchetMessage(
            'an English key with no Arabic row',
            $expected,
            $actual,
            'If the new key is a PAYMENT GATEWAY BRAND it belongs in MISSING_ARABIC_ROW above and '
            . 'needs no Arabic value -- `__()` falls back to the key, which prints the brand. '
            . 'ANYTHING ELSE renders in ENGLISH on an Arabic merchant\'s screen: add it to '
            . 'resources/lang/ar.json, do not add it to this list.',
            'If an Arabic row was added for a gateway brand, read '
            . 'PaymentBrandNameTranslationTest first: P2.5-SET-03 removed those values because '
            . 'Stripe was being rendered `الشريط`. If the key was deleted from en.json instead, '
            . 'drop it from this list.'
        ));
    }

    /**
     * THE DISPOSITION AS A RULE, NOT A COMMENT.
     *
     * Group 1's acceptance rests entirely on all 35 being gateway brands. A comment saying so
     * decays; this does not. Every key with no Arabic row must fold to a canonical
     * `PaymentTypeLabel::BRANDS` entry -- by its identifier or by its label, case-insensitively,
     * which covers both shipped casings of Stripe and Yookassa and the spaced `Mercado Pago`.
     */
    public function test_every_english_key_with_no_arabic_row_is_a_canonical_payment_gateway_brand(): void
    {
        $brands = (new ReflectionClass(PaymentTypeLabel::class))->getConstant('BRANDS');

        $this->assertIsArray($brands);
        $this->assertGreaterThan(40, count($brands),
            'premise: PaymentTypeLabel::BRANDS came back with ' . count($brands) . ' entries, so '
            . 'the check below would accept almost nothing -- or, if empty, pass vacuously.');

        $vocabulary = [];
        foreach ($brands as $id => $label) {
            $vocabulary[strtolower((string) $id)] = true;
            $vocabulary[strtolower((string) $label)] = true;
        }

        // premise: the folding rule recognises a brand it must recognise, and refuses prose.
        $this->assertArrayHasKey('stripe', $vocabulary);
        $this->assertArrayHasKey('mercado pago', $vocabulary);
        $this->assertArrayNotHasKey('cost price', $vocabulary);

        $notBrands = [];
        foreach (self::missingArabicRows() as $key) {
            if (! isset($vocabulary[strtolower(trim($key))])) {
                $notBrands[] = $key;
            }
        }

        $this->assertSame([], $notBrands, sprintf(
            "%d English key(s) have no Arabic row and are NOT a payment gateway brand:\n  %s\n\n"
            . "That breaks the only reason group 1 of P03-ACC-02 is accepted. A brand name is "
            . "correctly left untranslated, because `__()` falls back to the key and prints the "
            . "provider's own spelling -- P2.5-SET-03, after Stripe shipped as `الشريط`. Anything "
            . "else with no Arabic row renders in ENGLISH for an Arabic merchant and nothing warns. "
            . 'Add a translation to resources/lang/ar.json.',
            count($notBrands),
            implode("\n  ", $notBrands)
        ));
    }

    public function test_arabic_values_identical_to_their_key_are_exactly_the_dispositioned_set(): void
    {
        $expected = array_values(array_diff(self::dispositionedKeys(), self::WHITESPACE_ONLY));
        sort($expected, SORT_STRING);
        $actual = self::valuesIdenticalToTheirKey();

        $this->assertSame($expected, $actual, $this->ratchetMessage(
            'byte-identical to its key',
            $expected,
            $actual,
            'An Arabic value equal to its English key renders ENGLISH. That is correct only for a '
            . 'brand, an initialism, a unit, sample data, a machine value or punctuation -- add it '
            . 'to DISPOSITION with one of the REASONS categories. Otherwise translate it: look at '
            . 'the neighbouring rows in ar.json for register and tone.',
            'A row was translated. Remove it from DISPOSITION in the same change.'
        ));
    }

    public function test_arabic_values_with_no_arabic_script_are_exactly_the_dispositioned_set(): void
    {
        $expected = self::dispositionedKeys();
        sort($expected, SORT_STRING);
        $actual = self::valuesWithNoArabicScript();

        $this->assertSame($expected, $actual, $this->ratchetMessage(
            'carrying no Arabic-script character',
            $expected,
            $actual,
            'An Arabic value with no Arabic script in it renders Latin text to an Arabic reader. '
            . 'Add it to DISPOSITION with one of the REASONS categories if that is correct for '
            . 'this string, otherwise translate it.',
            'A row was translated. Remove it from DISPOSITION in the same change.'
        ));
    }

    /**
     * GROUP 2 IS A SUBSET OF GROUP 3, with the two whitespace-only rows as the only difference.
     *
     * Not decoration: it is why the triage is 28 decisions and not 54, and the two assertions
     * above would otherwise look like independent numbers that happen to move together. If this
     * ever fails, one of the two scans has stopped measuring what it claims.
     */
    public function test_the_key_identical_rows_are_the_no_script_rows_minus_the_whitespace_only_ones(): void
    {
        $identical = self::valuesIdenticalToTheirKey();
        $noScript = self::valuesWithNoArabicScript();

        $this->assertSame([], array_values(array_diff($identical, $noScript)),
            'A value byte-identical to its English key cannot contain Arabic script, so group 2 '
            . 'must be a subset of group 3. It is not, which means one of the two scans is wrong.');

        $extras = array_values(array_diff($noScript, $identical));
        sort($extras, SORT_STRING);
        $expected = self::WHITESPACE_ONLY;
        sort($expected, SORT_STRING);

        $this->assertSame($expected, $extras, sprintf(
            "The rows in group 3 but not group 2 should be exactly the %d that differ from their "
            . "key by WHITESPACE alone; measured: %s\n"
            . 'A new one is a value that is neither Arabic nor its own key -- read it before '
            . 'listing it.',
            count($expected),
            implode(' | ', $extras)
        ));
    }

    /** The three figures this file pins are the three figures GAP-096's row states. */
    public function test_the_three_numbers_are_the_ones_the_gap_register_records(): void
    {
        $measured = [
            'missing Arabic row' => count(self::missingArabicRows()),
            'value identical to its key' => count(self::valuesIdenticalToTheirKey()),
            'value with no Arabic script' => count(self::valuesWithNoArabicScript()),
        ];

        $this->assertSame(self::EXPECTED_COUNTS, $measured,
            "The three P03-ACC-02 numbers are no longer 35 / 26 / 28.\n"
            . 'Measured: ' . json_encode($measured) . "\n"
            . 'Update EXPECTED_COUNTS, this file\'s constants and the GAP-096 row in '
            . 'docs/development/MASTER-PLAN-GAP-REGISTER.md together. GAP-096 exists because a '
            . 'document and the code stated different numbers for months.');
    }

    // ------------------------------------------------------------------- placeholder parity (P4-1)

    /**
     * NO ARABIC VALUE MAY ADD A PLACEHOLDER ITS ENGLISH SIDE DOES NOT DECLARE.
     *
     * `TranslationFileIntegrityTest` already covers the other direction -- a token in the key that
     * the Arabic value DROPS, which is how `:product added successfully.` rendered
     * `:تم إضافة المنتج بنجاح`. The direction here had nothing watching it, and it fails the other
     * way round: a `:token` the caller never passes is not substituted, so Laravel prints it
     * LITERALLY and the merchant reads `:count` on the screen.
     *
     * COMPARED AGAINST THE ENGLISH VALUE, not only the key. For almost every row the two are the
     * same text, but they need not be, and the acceptance criterion is about the two languages
     * agreeing -- so the English VALUE is the right side of the comparison. Measured over all
     * 4,670 Arabic rows at the time of writing: 0 in this direction, in both readings.
     *
     * ABSOLUTE, NOT A RATCHET. There is nothing to grandfather: it is already zero.
     */
    public function test_no_arabic_value_adds_a_placeholder_its_english_side_does_not_declare(): void
    {
        $en = self::decode('resources/lang/en.json');
        $ar = self::decode('resources/lang/ar.json');

        $tokens = static function (string $text): array {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $text, $m);

            return array_values(array_unique($m[1]));
        };

        // premise: the token pattern finds the placeholders this file really has.
        $withTokens = 0;
        foreach (self::stringKeys($ar) as $key) {
            if (preg_match('/:[a-zA-Z_]/', $key) === 1) {
                $withTokens++;
            }
        }
        $this->assertGreaterThan(50, $withTokens,
            'Almost no key was seen to declare a placeholder, so this scan had nothing to compare.');

        $invented = [];
        foreach ($ar as $rawKey => $value) {
            if (! is_string($value)) {
                continue;
            }

            $key = (string) $rawKey;
            $english = array_key_exists($rawKey, $en) && is_string($en[$rawKey]) ? $en[$rawKey] : $key;
            $extra = array_diff($tokens($value), $tokens($english), $tokens($key));

            if ($extra !== []) {
                $invented[] = sprintf(':%s  in  "%s"  ->  "%s"', implode(', :', $extra), $key, $value);
            }
        }

        $this->assertSame([], $invented, sprintf(
            "%d Arabic value(s) carry a placeholder neither the English value nor the key "
            . "declares:\n%s\n"
            . 'Nothing substitutes it, so Laravel prints the token itself and the merchant reads '
            . 'a literal `:token`. Remove it, or add the matching replacement on the English side.',
            count($invented),
            implode("\n", $invented)
        ));
    }

    /**
     * The three strings this change translated are still translated.
     *
     * The set assertions above would catch a revert as a count moving, but they would not say
     * WHICH, and these three are the only closures P03-ACC-02 got -- so they are named, with the
     * value, the way this project's other localisation tests pin `Yes` and `No`.
     */
    public function test_the_three_strings_this_change_translated_are_still_translated(): void
    {
        $ar = self::decode('resources/lang/ar.json');
        $en = self::decode('resources/lang/en.json');

        $closed = [
            'AOV' => 'متوسط قيمة الطلب',
            'QTY' => 'كمية',
            'S3 URL' => 'عنوان S3',
        ];

        foreach ($closed as $key => $arabic) {
            $this->assertArrayHasKey($key, $en,
                $key . ' left en.json; an Arabic row with no English sibling is never looked up again.');
            $this->assertSame($arabic, $ar[$key] ?? null, sprintf(
                'The Arabic value for "%s" is no longer %s. These three were the only rows '
                . 'P03-ACC-02 genuinely closed -- each was chosen from the house style already in '
                . 'the file (`Qty`/`Quantity` already read `كمية`; the S3 panel\'s other fields '
                . 'read `مفتاح S3` / `منطقة S3` / `دلو S3`). Do not revert one without a reason.',
                $key,
                $arabic
            ));
        }
    }
}

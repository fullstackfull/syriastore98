<?php

namespace App\Support\Search;

/**
 * THE ONE ARABIC FOLDER IN THIS REPOSITORY.  `CUST-003`, owner decision `J38`.
 * =================================================================================================
 *
 * Extracted 2026-11-27 from `App\Support\Docs\DocumentationSearch::normalise()`, which had been the
 * only Arabic normaliser here and served only the documentation centre. `GAP-190` sized the
 * storefront half of `CUST-003` — «بحث ذكي ومرادفات وفلاتر ديناميكية» — around that fact, so this
 * class is that half's foundation and the documentation centre now folds through it too.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY ARABIC NEEDS FOLDING AND LATIN MOSTLY DOES NOT
 * -------------------------------------------------------------------------------------------------
 *
 * A shopper types what they hear; the merchant typed what is correct. In Arabic those differ in
 * ways a byte comparison cannot see, and a database collation does not help: MySQL's default
 * `utf8mb4_*_ci` collations are case- and accent-insensitive for Latin and do **nothing** about
 * Arabic orthography.
 *
 *   - `أ إ آ ٱ` are all written for the same alef; a shopper types the bare `ا`.
 *   - `ة` at the end of a word is often typed `ه`  (`شاشة` / `شاشه`).
 *   - `ى` (alef maqsura) is often typed `ي`, and `ئ`/`ؤ` are typed bare.
 *   - Tashkeel (short-vowel marks) and tatweel (`ـ`) are decorative, never typed in a search box,
 *     and DO appear in carefully written product names.
 *   - Arabic-Indic digits `٠١٢٣٤٥٦٧٨٩` and ASCII `0123456789` are the same numbers.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT HERE
 * -------------------------------------------------------------------------------------------------
 *
 * **No prose typography.** `« » “ ” ’ ‘ — –` are folded by `DocumentationSearch` on top of this,
 * because its corpus is prose. They are NOT folded here, and that is load-bearing rather than an
 * omission: folding an em dash would make a shopper's Latin `Sony - WH-1000` match a product stored
 * `Sony — WH-1000`, which is a row today's search does not return. See
 * `Tests\Unit\Search\ArabicSearchTextTest::the_product_folder_leaves_prose_typography_alone`.
 *
 * **No stemming, no synonym dictionary, no fuzzy matching, no transliteration.** Each is a real
 * improvement and each can be wrong in ways a shopper cannot see. `CUST-003`'s word «مرادفات» can
 * be read as a curated synonym list, and that reading is not engineering's to pick — `J38` already
 * records the unresolved half of this requirement. Orthographic folding is what makes the search
 * usable in Arabic and is all that is claimed.
 *
 * **No extended Arabic-Indic (Persian/Urdu) digits `۰-۹`.** They are a different block from the
 * Arabic-Indic `٠-٩` above. Adding them would change what the documentation centre matches, which
 * is outside this slice; recorded as a lead, not fixed quietly.
 *
 * **Length is not preserved.** Measured 2026-11-27: `mb_strtolower("\u{0130}")` (`İ`) returns TWO
 * characters where one went in. Nothing here may therefore assume a folded string is no longer
 * than its input — it is why `products.name_normalised` is a `text` column and not a `varchar(255)`
 * beside `products.name`.
 */
final class ArabicSearchText
{
    /**
     * Single-character substitutions, applied in ONE `strtr` pass after the diacritics are removed.
     *
     * Public because two tests read it: the extraction pin walks its whole key/value alphabet, and
     * the disjointness check proves `DocumentationSearch`'s prose map cannot see its output. A test
     * that hard-codes its own copy of a map is testing its copy.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
        'ة' => 'ه',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Tashkeel (U+064B..U+0652), superscript alef (U+0670) and tatweel (U+0640). */
    private const DIACRITICS = '/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u';

    /**
     * Fold away everything a reader will not type.
     *
     * Total: every string has a fold, the empty string folds to itself, and a `preg_replace`
     * failure falls back to the un-stripped text rather than to `null`.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;

        return strtr($text, self::MAP);
    }
}

<?php

namespace Tests\Unit\Search;

use App\Support\Docs\DocumentationSearch;
use App\Support\Search\ArabicSearchText;
use App\Support\Search\ProductNameIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `CUST-003` — ONE ARABIC FOLDER, SHARED, SO TWO COPIES CANNOT DRIFT.
 * =================================================================================================
 *
 * `GAP-190` / owner decision `J38`: *"`App\Support\Docs\DocumentationSearch::normalise()` is a
 * first-party Arabic normaliser already in this repository (alef variants, teh marbuta, alef
 * maqsura, tatweel, diacritics), applied only to the documentation centre. Product search is one
 * `LIKE %term%` on `products.name`. Normalising the query alone is insufficient — `LIKE` cannot
 * normalise the haystack."*
 *
 * -------------------------------------------------------------------------------------------------
 * REUSE OR EXTRACT: EXTRACTED, AND THE ARGUMENT IS A MEASUREMENT
 * -------------------------------------------------------------------------------------------------
 *
 * `DocumentationSearch::normalise()` does **two** jobs in one `strtr`. One is Arabic orthography
 * (and Arabic-Indic digits), which product search needs verbatim. The other is **prose typography**
 * — `« » “ ” ’ ‘ — –` folded to ASCII quotes and hyphens — which product search must NOT have:
 *
 *     measured, this file's own `the_product_folder_leaves_prose_typography_alone`:
 *       DocumentationSearch::normalise('Sony — WH-1000')  ->  'sony - wh-1000'
 *       ProductNameIndex::normalise('Sony — WH-1000')     ->  'sony — wh-1000'
 *
 * With the prose layer, a shopper typing the ASCII `Sony - WH-1000` would start matching a product
 * stored with an em dash — a row today's search does not return. The brief for this slice forbids
 * exactly that: *"Do not change what any search RETURNS for a Latin-script query."* So calling
 * `DocumentationSearch::normalise()` from product search is not available, and copying the Arabic
 * half into a second place is the drift this test exists to prevent.
 *
 * Hence: the Arabic half moves **down** into `ArabicSearchText::fold()`, the documentation centre
 * keeps its prose layer **on top of it**, and product search uses the shared half alone. There is
 * one implementation of Arabic folding in the repository, and
 * {@see self::the_documentation_normaliser_is_exactly_the_shared_fold_plus_its_prose_layer} pins
 * that the extraction changed nothing the documentation centre does — over the whole key alphabet
 * of both maps, not over a sample.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE EXTRACTION IS SAFE AS TWO `strtr` PASSES WHERE IT USED TO BE ONE
 * -------------------------------------------------------------------------------------------------
 *
 * `strtr()` with an array is a single longest-match pass, so splitting one map into two passes is
 * only equivalent when the two maps cannot see each other's output. They cannot, and it is checked
 * rather than asserted in prose: the Arabic map's outputs are `ا ي و ه` and ASCII digits, none of
 * which is a key of the prose map; the prose map's outputs are `" ' -`, none of which is a key of
 * the Arabic map. {@see self::the_two_maps_cannot_see_each_others_output} proves the disjointness
 * from the live maps, so adding a key to either one that breaks it turns this red.
 */
final class ArabicSearchTextTest extends TestCase
{
    /**
     * Each pair is "what the author wrote" and "what a reader types", and they must fold together.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function orthographyPairs(): array
    {
        return [
            'hamza on alef'          => ['أطفال', 'اطفال'],
            'hamza under alef'       => ['إضاءة', 'اضاءة'],
            'madda on alef'          => ['آلة', 'الة'],
            'alef wasla'             => ['ٱسم', 'اسم'],
            'teh marbuta for heh'    => ['شاشة', 'شاشه'],
            'alef maqsura for yeh'   => ['مصطفى', 'مصطفي'],
            'yeh with hamza'         => ['رئيسي', 'رييسي'],
            'waw with hamza'         => ['مؤشر', 'موشر'],
            'tashkeel'               => ['مِفْتَاحٌ', 'مفتاح'],
            'tatweel'                => ['حاســوب', 'حاسوب'],
            'arabic-indic digits'    => ['هاتف ٣٢', 'هاتف 32'],
            'all of it at once'      => ['مسوّدة ٱلأطفال ٥', 'مسوده الاطفال 5'],
        ];
    }

    #[Test]
    #[DataProvider('orthographyPairs')]
    public function the_shared_folder_folds_what_a_reader_will_not_type(string $written, string $typed): void
    {
        $this->assertSame(
            ArabicSearchText::fold($typed),
            ArabicSearchText::fold($written),
            "'{$written}' and '{$typed}' must fold to the same thing"
        );
    }

    /**
     * THE ANTI-CONSTANT GUARD. A folder that returned `''` would satisfy every pair above.
     */
    #[Test]
    public function the_shared_folder_does_not_collapse_genuinely_different_words(): void
    {
        $this->assertNotSame(ArabicSearchText::fold('متجر'), ArabicSearchText::fold('منتج'));
        $this->assertNotSame(ArabicSearchText::fold('قميص'), ArabicSearchText::fold('حاسوب'));
        $this->assertNotSame(ArabicSearchText::fold('phone'), ArabicSearchText::fold('tablet'));
        $this->assertNotSame('', ArabicSearchText::fold('قميص'));
    }

    /**
     * THE EXTRACTION PIN, IN BOTH DIRECTIONS AND OVER THE WHOLE ALPHABET.
     *
     * Every key and every value of both maps, each on its own and all of them concatenated, plus
     * the documentation suite's own fixtures. If `DocumentationSearch::normalise()` ever stops
     * being "the shared fold, then the prose layer", this says so — which is the only thing that
     * stops the two drifting once they are two files.
     */
    #[Test]
    public function the_documentation_normaliser_is_exactly_the_shared_fold_plus_its_prose_layer(): void
    {
        $alphabet = array_merge(
            array_keys(ArabicSearchText::MAP),
            array_values(ArabicSearchText::MAP),
            array_keys(DocumentationSearch::PROSE_MAP),
            array_values(DocumentationSearch::PROSE_MAP),
            ['مسوّدة', 'قالب', 'Theme', 'ORDER', 'هاتف ٣٢', 'مصطفى', 'حاســوب'],
            [implode('', array_keys(ArabicSearchText::MAP))
                . implode('', array_keys(DocumentationSearch::PROSE_MAP))],
        );

        foreach ($alphabet as $sample) {
            $this->assertSame(
                strtr(ArabicSearchText::fold($sample), DocumentationSearch::PROSE_MAP),
                DocumentationSearch::normalise($sample),
                'DocumentationSearch::normalise() must stay exactly ArabicSearchText::fold() '
                . 'followed by its own prose map, for ' . bin2hex($sample)
            );
        }
    }

    /**
     * WHAT MAKES THE TWO-PASS SPLIT EQUIVALENT TO THE ONE-PASS `strtr` IT REPLACED.
     *
     * Read off the live maps, so a new key in either that breaks the property fails here rather
     * than silently changing the documentation centre's answers.
     */
    #[Test]
    public function the_two_maps_cannot_see_each_others_output(): void
    {
        $this->assertSame([], array_intersect(
            array_values(ArabicSearchText::MAP),
            array_keys(DocumentationSearch::PROSE_MAP)
        ), 'an Arabic fold output is a prose-map key, so two passes no longer equal one');

        $this->assertSame([], array_intersect(
            array_values(DocumentationSearch::PROSE_MAP),
            array_keys(ArabicSearchText::MAP)
        ), 'a prose-map output is an Arabic-map key, so the pass order now matters');
    }

    /**
     * THE REASON PRODUCT SEARCH CANNOT JUST CALL THE DOCUMENTATION NORMALISER.
     *
     * The brief: *"Do not change what any search RETURNS for a Latin-script query."* Folding an em
     * dash to a hyphen would make a shopper's `Sony - WH-1000` match a product stored
     * `Sony — WH-1000`, which is a new row in a Latin result set.
     */
    #[Test]
    public function the_product_folder_leaves_prose_typography_alone(): void
    {
        $this->assertSame('sony - wh-1000', DocumentationSearch::normalise('Sony — WH-1000'),
            'premise: the documentation normaliser DOES fold an em dash — that is the whole point '
            . 'of this case. Without this the assertion below proves nothing.');

        $this->assertSame('sony — wh-1000', ProductNameIndex::normalise('Sony — WH-1000'),
            'product folding must leave prose typography exactly as stored, or a Latin-script '
            . 'query starts returning rows it does not return today');

        foreach (array_keys(DocumentationSearch::PROSE_MAP) as $typographic) {
            $this->assertSame($typographic, ProductNameIndex::normalise($typographic),
                'product folding changed ' . bin2hex($typographic));
        }
    }

    /**
     * A LATIN PRODUCT NAME FOLDS TO ITS OWN LOWER CASE AND NOTHING ELSE.
     *
     * This is the property the Latin control at the doors rests on: under MySQL's default
     * case-insensitive collation and SQLite's ASCII-insensitive `LIKE`, matching
     * `lower(name) LIKE %lower(term)%` selects exactly the rows `name LIKE %term%` selects, so the
     * added limb cannot change a Latin result set.
     */
    #[Test]
    public function a_latin_name_folds_to_its_own_lower_case(): void
    {
        foreach (['Blue Cotton Shirt', 'iPhone 15 Pro', 'ACME-500/XL', 'Café Noir', '50% Linen'] as $name) {
            $this->assertSame(mb_strtolower($name, 'UTF-8'), ProductNameIndex::normalise($name),
                $name . ' folded to something other than its own lower case');
        }
    }

    /** Folding is not allowed to invent content for an empty or whitespace name. */
    #[Test]
    public function folding_is_total_and_keeps_empty_input_empty(): void
    {
        $this->assertSame('', ArabicSearchText::fold(''));
        $this->assertSame('', ProductNameIndex::normalise(''));
        $this->assertSame('   ', ProductNameIndex::normalise('   '));
    }
}

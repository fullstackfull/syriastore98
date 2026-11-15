<?php

namespace Tests\Unit\Platform;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use App\Support\Docs\DocumentationSearch;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M6 — SEARCH, AND THE ARABIC ORTHOGRAPHY A READER WILL NOT TYPE.
 * =================================================================================================
 *
 * A search box that works in English and silently misses in Arabic is the failure this suite is
 * built around, because it is invisible to anyone testing in English: a reader types `مسوده` and
 * the article titled `مسوّدة` does not come back, and there is no error anywhere. Every
 * normalisation rule is driven as its own named case, so each one is evidence rather than a line
 * in a function somebody trusted.
 *
 * ALSO ASSERTED: the renderer's two SECURITY options. Raw HTML in a source file is stripped, and an
 * unsafe link loses its href. The corpus is written by us, so neither is load-bearing today — they
 * are pinned so that they stay true the day somebody pastes markup into a help article.
 */
class DocumentationSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    // --- normalisation --------------------------------------------------------------------------

    public static function arabicPairs(): array
    {
        return [
            'hamza above alef'  => ['أحمد', 'احمد'],
            'hamza below alef'  => ['إدارة', 'اداره'],
            'madda alef'        => ['آخر', 'اخر'],
            'wasla alef'        => ['ٱسم', 'اسم'],
            'alef maqsura'      => ['على', 'علي'],
            'ta marbuta'        => ['مسودة', 'مسوده'],
            'hamza on waw'      => ['مسؤول', 'مسوول'],
            'hamza on ya'       => ['قائمة', 'قايمه'],
            'tashkeel'          => ['مُسَوَّدَة', 'مسوده'],
            'tatweel'           => ['مســودة', 'مسوده'],
            'arabic-indic digits' => ['٢٠٢٦', '2026'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('arabicPairs')]
    public function the_normaliser_folds_what_a_reader_will_not_type(string $written, string $typed): void
    {
        $this->assertSame(
            DocumentationSearch::normalise($typed),
            DocumentationSearch::normalise($written),
            "'{$written}' and '{$typed}' must normalise to the same thing"
        );
    }

    #[Test]
    public function the_normaliser_does_not_collapse_genuinely_different_words(): void
    {
        /* The opposite direction, and the one that makes the test above mean something: a
           normaliser that returned a constant would satisfy every case in the provider. */
        $this->assertNotSame(
            DocumentationSearch::normalise('متجر'),
            DocumentationSearch::normalise('منتج')
        );
        $this->assertNotSame(
            DocumentationSearch::normalise('theme'),
            DocumentationSearch::normalise('order')
        );
    }

    #[Test]
    public function terms_drop_punctuation_single_characters_and_duplicates(): void
    {
        $this->assertSame(['theme', 'editor'], DocumentationSearch::terms('Theme, editor!'));
        $this->assertSame(['theme'], DocumentationSearch::terms('theme a theme'));
        $this->assertSame([], DocumentationSearch::terms('   ?  '));
        $this->assertSame([], DocumentationSearch::terms('a'));
    }

    // --- ranking --------------------------------------------------------------------------------

    #[Test]
    public function a_title_match_outranks_a_passing_mention(): void
    {
        $results = DocumentationSearch::run('en', 'theme');

        $this->assertNotEmpty($results);
        $this->assertSame('your-storefront/choose-a-theme', $results[0]['article']['id']);
    }

    #[Test]
    public function an_arabic_query_finds_the_arabic_article(): void
    {
        /* The whole point of the normaliser, driven end to end: the article is titled `اختر قالباً`
           and the reader types the bare, unvowelled form. */
        $results = DocumentationSearch::run('ar', 'قالب');

        $this->assertNotEmpty($results, 'an Arabic query returned nothing');

        $ids = array_map(static fn ($r) => $r['article']['id'], $results);
        $this->assertContains('your-storefront/choose-a-theme', $ids);
    }

    #[Test]
    public function an_arabic_query_typed_without_the_orthography_still_matches(): void
    {
        /* `مسوّدة` is written with shadda and ta marbuta throughout the corpus; a reader types
           `مسوده`. Without normalisation this returns nothing at all. */
        $results = DocumentationSearch::run('ar', 'مسوده');

        $this->assertNotEmpty($results, 'the Arabic normalisation is not reaching the search');
    }

    #[Test]
    public function a_query_that_matches_nothing_returns_nothing(): void
    {
        $this->assertSame([], DocumentationSearch::run('en', 'zzzzqqqq'));
        $this->assertSame([], DocumentationSearch::run('en', ''));
        $this->assertSame([], DocumentationSearch::run('en', '   '));
    }

    #[Test]
    public function every_result_carries_a_snippet_without_markdown_syntax(): void
    {
        foreach (DocumentationSearch::run('en', 'theme') as $result) {
            $this->assertNotSame('', $result['snippet']);
            $this->assertStringNotContainsString('##', $result['snippet']);
            $this->assertStringNotContainsString('**', $result['snippet']);
        }
    }

    #[Test]
    public function the_result_set_is_bounded(): void
    {
        /* A query matching a very common word must not return the whole corpus unbounded. */
        $results = DocumentationSearch::run('en', 'the store');

        $this->assertLessThanOrEqual(DocumentationSearch::MAX_RESULTS, count($results));
    }

    // --- rendering ------------------------------------------------------------------------------

    #[Test]
    public function raw_html_in_a_source_file_is_stripped_not_rendered(): void
    {
        $html = DocumentationRenderer::html("Text <script>alert(1)</script> and <b>bold</b>.\n");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('Text', $html);
    }

    #[Test]
    public function an_unsafe_link_loses_its_href(): void
    {
        $html = DocumentationRenderer::html("[click](javascript:alert(1))\n");

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('click', $html);
    }

    #[Test]
    public function headings_get_ids_that_match_the_table_of_contents(): void
    {
        $markdown = "## Register\n\ntext\n\n### البداية\n\nمزيد\n";

        $html = DocumentationRenderer::html($markdown);
        $toc  = DocumentationRenderer::tableOfContents($markdown);

        $this->assertCount(2, $toc);

        foreach ($toc as $entry) {
            /* THE FAILURE THIS CATCHES: a contents panel whose links go nowhere, which is worse
               than having none. The id and the link come from one function so they cannot drift. */
            $this->assertStringContainsString('id="' . $entry['id'] . '"', $html, $entry['text']);
        }
    }

    #[Test]
    public function a_heading_inside_a_code_fence_is_not_a_contents_entry(): void
    {
        $toc = DocumentationRenderer::tableOfContents("## Real\n\n```\n## Not a heading\n```\n");

        $this->assertCount(1, $toc);
        $this->assertSame('Real', $toc[0]['text']);
    }
}

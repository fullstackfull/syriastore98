<?php

namespace Tests\Unit\Ui;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ARABIC HEADING IDS SPLIT ARABIC WORDS IN HALF — found while closing GAP-106 (P03-D-03).
 * =================================================================================================
 *
 * GAP-106's second half is "native Arabic typography review is outstanding", correctly recorded as
 * an owner item. This is not that review; it is a measurable defect found on the way to the first
 * half, in the one function `DocumentationRenderer` documents as making Arabic headings
 * addressable.
 *
 * WHAT THE RENDERER PROMISES, verbatim from its own docblock: "ARABIC HEADINGS GET ARABIC IDs. The
 * slug keeps any Unicode letter or digit, so `## البداية` becomes `id="البداية"`."
 *
 * WHAT IT DID. `[^\p{L}\p{N}]+` replaces anything that is not a letter or a digit with a hyphen —
 * and an Arabic diacritic is NEITHER. Shadda, fatha, damma, kasra and the rest are Unicode general
 * category **Mn (Mark, non-spacing)**, not Lo, so each one became a hyphen INSIDE a word:
 *
 *       سجّل                   ->  سج-ل
 *       أكّد بريدك الإلكتروني  ->  أك-د-بريدك-الإلكتروني
 *       ما الذي تحدّده الباقة  ->  ما-الذي-تحد-ده-الباقة
 *       إن غيّرت رأيك لاحقاً   ->  إن-غي-رت-رأيك-لاحقا
 *
 * MEASURED ACROSS THE CORPUS: 24 of the 58 Arabic headings — 41% — produced an id with a word
 * broken in the middle. The example in the docblock is undiacriticised, which is exactly why the
 * promise read as kept.
 *
 * WHY IT WENT UNNOTICED AND WHY NOTHING ELSE CAUGHT IT. The id and the contents-panel link come
 * from the SAME `slug()` call, which the renderer chose deliberately so the two cannot drift — so
 * the table of contents worked perfectly, every anchor resolved, and the only visible symptom was
 * a mangled fragment in the address bar after a reader clicked one. A test asserting "every TOC
 * link resolves" passes on a mangled id as readily as on a correct one.
 *
 * THE FIX IS ONE CHARACTER CLASS: marks join letters and digits as characters a slug keeps.
 *
 * BLAST RADIUS, MEASURED RATHER THAN ASSUMED. Changing a heading id breaks any saved link to it.
 * The documentation corpus contains ZERO body links in either locale (measured 2026-11-25 —
 * `DocumentationCorpusLinkTest` records the same finding), so nothing in the repository points at
 * any of these fragments, and the ids being changed are ones a reader could not sensibly have
 * bookmarked: they name a word that does not exist. The alternative — leaving them — keeps 24
 * broken ids to avoid breaking links to 24 broken ids.
 */
class DocumentationArabicHeadingIdTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> heading => [heading, expected id] */
    public static function diacriticisedHeadings(): array
    {
        return [
            'shadda mid-word' => ['سجّل', 'سجّل'],
            'shadda and a sentence' => ['أكّد بريدك الإلكتروني', 'أكّد-بريدك-الإلكتروني'],
            'two shaddas' => ['ما الذي تحدّده الباقة', 'ما-الذي-تحدّده-الباقة'],
            'tanween at the end' => ['إن غيّرت رأيك لاحقاً', 'إن-غيّرت-رأيك-لاحقاً'],
            'undiacriticised still works' => ['البداية', 'البداية'],
            'latin still works' => ['Prepare the file', 'prepare-the-file'],
            'digits survive' => ['الخطوة 2', 'الخطوة-2'],
        ];
    }

    #[Test]
    #[DataProvider('diacriticisedHeadings')]
    public function test_a_diacritic_does_not_split_an_arabic_word(string $heading, string $expected): void
    {
        $this->assertSame($expected, DocumentationRenderer::slug($heading),
            "`{$heading}` slugged to `" . DocumentationRenderer::slug($heading) . '`. An Arabic '
            . 'diacritic is Unicode category Mn — a mark, not a letter — so a slug that keeps only '
            . 'letters and digits turns every one of them into a hyphen inside the word.');
    }

    /**
     * The whole corpus, which is what makes this a control rather than seven examples.
     *
     * @return array<string, array{0: string, 1: string}> label => [locale, file]
     */
    public static function arabicArticles(): array
    {
        $root = dirname(__DIR__, 3) . '/' . DocumentationLibrary::ROOT . '/ar';

        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir . '/*.md') ?: [] as $file) {
                $out[basename($dir) . '/' . basename($file)] = ['ar', $file];
            }
        }

        return $out;
    }

    #[Test]
    #[DataProvider('arabicArticles')]
    public function test_no_arabic_heading_in_the_corpus_produces_a_split_word(string $locale, string $file): void
    {
        $meta = DocumentationLibrary::readFrontMatter($file);
        $this->assertNotNull($meta, basename($file) . ' did not parse.');

        $headings = DocumentationRenderer::tableOfContents((string) $meta['__body']);

        /* `_category.md` has no headings; every article must have some, or the case is vacuous. */
        if (basename($file) === '_category.md') {
            $this->assertSame([], $headings);

            return;
        }

        $this->assertNotSame([], $headings,
            basename($file) . ' has no headings at all, so this case measures nothing.');

        $broken = [];

        foreach ($headings as $heading) {
            /* A hyphen in an id is correct where the SOURCE had a separator. It is wrong where the
               source had none — which is precisely what a diacritic-as-separator produces. The
               check is therefore per word: a single source word must slug to a single id segment. */
            foreach (preg_split('/\s+/u', $heading['text']) as $word) {
                $word = trim($word);

                if ($word === '' || preg_match('/[\p{P}\p{S}]/u', $word) === 1) {
                    /* A word carrying its own punctuation may legitimately split. */
                    continue;
                }

                if (str_contains(DocumentationRenderer::slug($word), '-')) {
                    $broken[] = $word . ' -> ' . DocumentationRenderer::slug($word)
                        . '   (in heading "' . $heading['text'] . '")';
                }
            }
        }

        $this->assertSame([], $broken,
            basename($file) . ": a single word slugged to several id segments, so the heading's "
            . "`id` and its contents link both name a word that does not exist:\n  "
            . implode("\n  ", $broken));
    }

    /** The id the renderer writes into the HTML must be the same string the TOC links to. */
    #[Test]
    public function test_the_rendered_heading_id_matches_the_contents_link(): void
    {
        $markdown = "## أكّد بريدك الإلكتروني\n\nنص.\n\n### ما الذي تحدّده الباقة\n\nنص.";

        $html = DocumentationRenderer::html($markdown);
        $toc = DocumentationRenderer::tableOfContents($markdown);

        $this->assertCount(2, $toc);

        foreach ($toc as $entry) {
            $this->assertStringContainsString('id="' . $entry['id'] . '"', $html,
                "The contents panel links to `#{$entry['id']}` and no heading carries that id. "
                . 'The two come from one function precisely so they cannot drift.');
        }
    }
}

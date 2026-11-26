<?php

namespace Tests\Unit\Ui;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-107 (P03-D-04) — THE EDITORIAL STATE OF AN ARTICLE, AND THE CENSUS THAT KEEPS IT HONEST.
 * =================================================================================================
 *
 * The gap, verbatim: "working documentation exists; an editorial workflow does not". Its next
 * action: "Name a documentation owner and write down the release process (who drafts, who reviews
 * Arabic, who approves, how a change reaches production); ADD THE SUPPORTING FRONT-MATTER FIELDS IF
 * THE PROCESS NEEDS THEM."
 *
 * WHAT WAS MEASURED AT HEAD. `DocumentationLibrary::readArticle()` parsed `title`, `summary`,
 * `order`, `related` and `updated`. There was no author, owner, reviewer or status field of any
 * kind, every article in the tree was public the moment it was committed, and the de facto process
 * was "a developer edits a Markdown file and it ships with the next deploy". A half-written Arabic
 * translation could not be committed for review without being published to every visitor.
 *
 * THE FIELD IS `status` AND IT HAS THREE VALUES: `draft`, `review`, `published`. Only `published`
 * is public.
 *
 * THE TWO DEFAULTS ARE DIFFERENT AND THE ASYMMETRY IS THE DESIGN, not an oversight:
 *
 *   - A file that declares NO `status` AT ALL predates the workflow, so it is PUBLISHED. The corpus
 *     was 32 published articles before this field existed; a field whose introduction removes 32
 *     working help pages from production cannot be introduced. `PlatformDocsBilingualTest` also
 *     writes a fixture article with no status and asserts HTTP 200, which is a test this change is
 *     not permitted to edit — so the legacy default is load-bearing, not merely convenient.
 *   - A file that declares a status NOBODY CAN READ (`status: publishd`) is a DRAFT. The author
 *     plainly meant to say something and we cannot tell what; the one safe reading of an
 *     unintelligible publication state is "not approved yet".
 *
 * AND THE CENSUS BELOW IS WHAT STOPS THE LEGACY DEFAULT BEING A LOOPHOLE: every shipped article
 * file must declare an explicit, recognised status, in both locales, with the two agreeing. So a
 * NEW article added without the key fails the Unit suite — which is a CI gate — rather than
 * silently publishing itself, and a typo'd value fails there too rather than silently removing a
 * page from production. The runtime is lenient in the direction that keeps readers served; CI is
 * strict in the direction that keeps the workflow real.
 */
class DocumentationEditorialStatusTest extends TestCase
{
    use StripsSourceComments;

    private const KEY = 'status';

    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    /**
     * Every article file in the corpus, both locales, read from disk rather than from the index.
     *
     * `_category.md` is excluded: a category is a heading, not prose that goes through review.
     *
     * @return array<string, array{0: string, 1: string}> label => [locale, absolute path]
     */
    public static function articleFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/' . DocumentationLibrary::ROOT;
        $out  = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (glob($root . '/' . $locale . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                foreach (glob($dir . '/*.md') ?: [] as $file) {
                    if (basename($file) === '_category.md') {
                        continue;
                    }
                    $out[$locale . '/' . basename($dir) . '/' . basename($file)] = [$locale, $file];
                }
            }
        }

        return $out;
    }

    /** A provider that found nothing is a green run with zero cases. */
    #[Test]
    public function test_the_census_really_found_both_halves_of_the_corpus(): void
    {
        $cases = self::articleFiles();
        $en = count(array_filter(array_keys($cases), static fn ($k) => str_starts_with($k, 'en/')));
        $ar = count(array_filter(array_keys($cases), static fn ($k) => str_starts_with($k, 'ar/')));

        $this->assertGreaterThan(10, $en, 'the census found almost no English articles.');
        $this->assertSame($en, $ar, 'the two locales hold different numbers of article files.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The vocabulary
     * ---------------------------------------------------------------------------------------------
     */

    #[Test]
    public function test_only_published_is_public(): void
    {
        $this->assertTrue(DocumentationStatus::isPublic(DocumentationStatus::PUBLISHED));
        $this->assertFalse(DocumentationStatus::isPublic(DocumentationStatus::DRAFT));
        $this->assertFalse(DocumentationStatus::isPublic(DocumentationStatus::REVIEW));

        /* The direction that matters: anything this class does not recognise is NOT public. A
           future fourth state added to the vocabulary and forgotten here must fail closed. */
        $this->assertFalse(DocumentationStatus::isPublic('anything-else'));
        $this->assertFalse(DocumentationStatus::isPublic(''));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function writtenValues(): array
    {
        return [
            'published'            => ['published', DocumentationStatus::PUBLISHED],
            'draft'                => ['draft', DocumentationStatus::DRAFT],
            'review'               => ['review', DocumentationStatus::REVIEW],
            'mixed case'           => ['Published', DocumentationStatus::PUBLISHED],
            'shouted'              => ['DRAFT', DocumentationStatus::DRAFT],
            'padded'               => ['  review  ', DocumentationStatus::REVIEW],
            'in review'            => ['in review', DocumentationStatus::REVIEW],
            'in-review'            => ['in-review', DocumentationStatus::REVIEW],
            'in_review'            => ['in_review', DocumentationStatus::REVIEW],
            'live'                 => ['live', DocumentationStatus::DRAFT],
            'typo'                 => ['publishd', DocumentationStatus::DRAFT],
            'empty value'          => ['', DocumentationStatus::DRAFT],
        ];
    }

    #[Test]
    #[DataProvider('writtenValues')]
    public function test_a_declared_value_reads_as_the_state_its_author_meant(string $written, string $expected): void
    {
        $this->assertSame($expected, DocumentationStatus::of([self::KEY => $written]),
            "front matter `status: {$written}` must read as `{$expected}`");
    }

    /**
     * THE LEGACY DEFAULT, PINNED IN ITS OWN TEST so that flipping it is a deliberate act with a
     * failing test attached rather than a one-word edit nobody notices.
     */
    #[Test]
    public function test_a_file_that_declares_no_status_at_all_is_treated_as_published(): void
    {
        $this->assertSame(DocumentationStatus::PUBLISHED, DocumentationStatus::of([]),
            'A file with no `status` key predates the workflow. Treating it as a draft would have '
            . 'removed 32 working help pages from production on the day the field was introduced, '
            . 'and `PlatformDocsBilingualTest` writes exactly such a file and asserts HTTP 200. '
            . 'The census in this file is what stops this default being a loophole.');

        $this->assertFalse(DocumentationStatus::isDeclared([]),
            'the library must be able to tell "no key" from "published", or the census cannot.');
        $this->assertTrue(DocumentationStatus::isDeclared([self::KEY => 'draft']));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The corpus
     * ---------------------------------------------------------------------------------------------
     */

    #[Test]
    #[DataProvider('articleFiles')]
    public function test_every_article_declares_an_explicit_editorial_status(string $locale, string $file): void
    {
        $meta = DocumentationLibrary::readFrontMatter($file);
        $rel  = str_replace(dirname(__DIR__, 3) . '/', '', $file);

        $this->assertNotNull($meta, $rel . ' has no front-matter block at all.');

        $this->assertTrue(DocumentationStatus::isDeclared($meta),
            $rel . ' declares no `' . self::KEY . '` field. A file with no status is PUBLISHED at '
            . 'runtime (the legacy default), so an article committed without one publishes itself. '
            . 'Add `' . self::KEY . ': draft`, `' . self::KEY . ': review` or `' . self::KEY
            . ': published` to its front matter.');

        $written = trim((string) $meta[self::KEY]);

        $this->assertContains(DocumentationStatus::of($meta), DocumentationStatus::ALL,
            $rel . ': `' . self::KEY . ': ' . $written . '` is not a state this product has.');

        /* THE ASSERTION THAT IS NOT A TAUTOLOGY. `of()` answers DRAFT for a value it cannot read,
           so asserting that `of()` returned a member of the vocabulary passes for every typo ever
           typed. `isRecognised()` asks the different question — did this written value reach its
           state through the vocabulary, or through the fallback — and a typo removes a page from
           production, so it has to be a failed build instead. */
        $this->assertTrue(DocumentationStatus::isRecognised($written),
            $rel . ': `' . self::KEY . ': ' . $written . '` is not a value this product recognises, '
            . 'so it reads as a DRAFT and the article would disappear from the public centre.');
    }

    #[Test]
    public function test_the_two_locales_declare_the_same_editorial_status(): void
    {
        $byId = [];

        foreach (self::articleFiles() as [$locale, $file]) {
            $meta = DocumentationLibrary::readFrontMatter($file);
            $id   = basename(dirname($file)) . '/' . basename($file);

            $byId[$id][$locale] = DocumentationStatus::of((array) $meta);
        }

        $this->assertGreaterThan(10, count($byId), 'PREMISE: the census found almost nothing.');

        $disagreeing = [];
        foreach ($byId as $id => $byLocale) {
            if (count(array_unique($byLocale)) > 1) {
                $disagreeing[] = $id . ' — ' . json_encode($byLocale);
            }
        }

        $this->assertSame([], $disagreeing,
            "An article is published in one language and not in the other. The reader of the "
            . "unpublished language gets the English text with a 'not translated yet' notice, "
            . "which is not what a half-finished translation should do:\n  "
            . implode("\n  ", $disagreeing));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The library
     * ---------------------------------------------------------------------------------------------
     */

    #[Test]
    public function test_the_library_exposes_the_status_on_every_article_it_indexes(): void
    {
        foreach (DocumentationLibrary::LOCALES as $locale) {
            $articles = DocumentationLibrary::articles($locale);

            $this->assertGreaterThan(10, count($articles),
                "the library indexed almost nothing for {$locale}, so this is not a measurement.");

            foreach ($articles as $article) {
                $this->assertArrayHasKey(self::KEY, $article,
                    "the library's article array has no `" . self::KEY . '` key.');

                $this->assertSame(DocumentationStatus::PUBLISHED, $article[self::KEY],
                    "{$locale} / {$article['id']}: the PUBLIC read API returned an article whose "
                    . 'status is `' . $article[self::KEY] . '`. Only published articles may reach '
                    . 'a reader (GAP-107).');
            }
        }
    }
}

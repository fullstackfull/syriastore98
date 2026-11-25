<?php

namespace Tests\Unit\Ui;

use App\Support\Docs\DocumentationLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-108 (P03-D-05) — A READER CAN TELL HOW OLD AN ARTICLE IS.
 * =================================================================================================
 *
 * The gap, verbatim: "A reader cannot tell how old an article is or which release it describes. Not
 * recorded as outstanding anywhere." Its next action: "Add a version or last-reviewed field to
 * front matter, render it, and pin it with a test that every article carries one." This file is the
 * third of those three.
 *
 * WHAT WAS MEASURED AT HEAD. `DocumentationLibrary::readArticle()` parsed exactly four keys —
 * `title`, `summary`, `order`, `related` — and the article view rendered the title, the summary, a
 * contents panel, the body, related links and a pager. Nothing anywhere carried a date, so a
 * merchant reading "how an order moves" had no way to tell whether it described the product as it
 * is or as it was a year ago. 32 articles, 0 with any freshness signal.
 *
 * THE FIELD IS `updated` AND NOT A VERSION, AND THAT IS A MEASUREMENT RATHER THAN A PREFERENCE.
 * The gap offers the choice ("a version OR last-reviewed field"). This application has NO release
 * identifier: `composer.json` carries no `version`, `config/app.php` carries none, and nothing in
 * the repository numbers a release. A version field would therefore have had to be invented, and an
 * invented release number on a help article is worse than no release number — it looks
 * authoritative and means nothing. The date half of the gap is closed; the version half is reported
 * as needing an owner decision about whether the product has releases to name at all.
 *
 * THE LABEL NEEDED NO LANGUAGE-FILE CHANGE. "Last Updated" is already declared in
 * `resources/lang/en.json` and `resources/lang/ar.json` ("آخر تحديث"), so the line reads correctly
 * in both directions, and `<time datetime="...">` carries the machine-readable ISO value the
 * localised text does not.
 *
 * WHY EVERY ARTICLE CARRIES THE SAME DATE TODAY, said plainly rather than hidden: this change is
 * when the field was introduced, so every article's value is the day it was added. The field only
 * starts carrying information once an article is edited and its date moves. A test that demanded
 * VARIED dates today would be a test that the corpus has a history it does not have.
 *
 * BOTH LOCALES, SEPARATELY. `DocumentationLibrary::article()` falls back to English when an Arabic
 * file is missing, so iterating one locale's `articles()` would silently measure English files
 * twice and report an Arabic corpus that was never checked. The provider below walks the FILES.
 */
class DocumentationArticleFreshnessTest extends TestCase
{
    /** The front-matter key, and the one place its name is written in this file. */
    private const KEY = 'updated';

    /**
     * Every article file in the corpus, both locales, read from disk rather than from the index.
     *
     * `_category.md` is excluded: a category is not an article and has no body to go stale.
     *
     * @return array<string, array{0: string, 1: string}> label => [locale, absolute path]
     */
    public static function articleFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/' . DocumentationLibrary::ROOT;

        $out = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (glob($root . '/' . $locale . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                foreach (glob($dir . '/*.md') ?: [] as $file) {
                    if (basename($file) === '_category.md') {
                        continue;
                    }

                    $label = $locale . '/' . basename($dir) . '/' . basename($file);
                    $out[$label] = [$locale, $file];
                }
            }
        }

        return $out;
    }

    /**
     * THE PREMISE. A provider that found nothing produces a green run with zero cases, which reads
     * exactly like a corpus in which every article is correct.
     */
    #[Test]
    public function test_the_census_really_found_both_halves_of_the_corpus(): void
    {
        $cases = self::articleFiles();

        $en = count(array_filter(array_keys($cases), static fn ($k) => str_starts_with($k, 'en/')));
        $ar = count(array_filter(array_keys($cases), static fn ($k) => str_starts_with($k, 'ar/')));

        $this->assertGreaterThan(10, $en, 'The census found almost no English articles.');
        $this->assertGreaterThan(10, $ar, 'The census found almost no Arabic articles.');
        $this->assertSame($en, $ar,
            "The two locales hold different numbers of articles ({$en} English, {$ar} Arabic). The "
            . 'library falls back to English for a missing Arabic file, so this test would then be '
            . 'measuring English files twice and reporting an Arabic corpus it never read.');
    }

    #[Test]
    #[DataProvider('articleFiles')]
    public function test_every_article_declares_when_it_was_last_updated(string $locale, string $file): void
    {
        $meta = DocumentationLibrary::readFrontMatter($file);

        $this->assertNotNull($meta, basename($file) . ' has no front-matter block at all.');

        $this->assertArrayHasKey(self::KEY, $meta,
            str_replace(dirname(__DIR__, 3) . '/', '', $file) . ' carries no `' . self::KEY
            . '` field, so a reader cannot tell how old it is (GAP-108). Add '
            . '`' . self::KEY . ': YYYY-MM-DD` to its front matter.');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $meta[self::KEY],
            str_replace(dirname(__DIR__, 3) . '/', '', $file) . ": `" . self::KEY . '` is `'
            . $meta[self::KEY] . '`, which is not an ISO date. The view puts the raw value in '
            . '`<time datetime="...">`, where anything else is invalid HTML and unreadable to a '
            . 'machine.');

        /* A date in the future is the shape a copy-paste or a wrong year leaves behind, and it
           makes the article look fresher than it is — the exact failure this field exists to
           prevent. */
        $this->assertLessThanOrEqual(date('Y-m-d'), $meta[self::KEY],
            str_replace(dirname(__DIR__, 3) . '/', '', $file) . ': the ' . self::KEY
            . ' date is in the future.');
    }

    /** The library must actually carry the field through to the view, in both locales. */
    #[Test]
    #[DataProvider('locales')]
    public function test_the_library_exposes_the_date_on_every_article_it_indexes(string $locale): void
    {
        DocumentationLibrary::forget();

        $articles = DocumentationLibrary::articles($locale);

        $this->assertGreaterThan(10, count($articles),
            "The library indexed almost nothing for {$locale}, so this is not a measurement.");

        foreach ($articles as $article) {
            $this->assertArrayHasKey(self::KEY, $article,
                "The library's article array has no `" . self::KEY . '` key, so the view has '
                . 'nothing to render however the files are written.');

            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $article[self::KEY],
                "{$locale} / {$article['id']}: the library exposed `" . self::KEY . '` as `'
                . $article[self::KEY] . '`, not an ISO date. An article whose front matter is '
                . 'malformed must still RENDER — the body is what a reader came for — so the '
                . 'library returns an empty string rather than hiding the article, and this '
                . 'assertion is what stops that fallback being silent.');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        $out = [];
        foreach (DocumentationLibrary::LOCALES as $locale) {
            $out[$locale] = [$locale];
        }

        return $out;
    }

    /**
     * THE RENDERED SIDE. The gap asks for the field to be RENDERED, not merely parsed.
     *
     * Asserted against the view source with comments stripped rather than against an HTTP response,
     * because `/platform/docs/...` renders the whole platform chrome and this is a Unit test; the
     * machine-readable attribute and the localised label are both properties of the template.
     */
    #[Test]
    public function test_the_article_view_renders_the_date_as_a_machine_readable_time_element(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/views/platform/docs/article.blade.php';
        $this->assertFileExists($path);

        /* Blade comments stripped: this change added a comment to that view explaining the field,
           and the comment names both `<time` and the key. CLAUDE.md records four censuses in one
           day satisfied by prose about the thing they measure. */
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        $this->assertNotSame('', trim($source), 'Stripping removed the whole view.');

        $this->assertStringContainsString('<time', $source,
            'The article view renders no <time> element, so the date is not machine-readable and '
            . 'the field is invisible to a reader (GAP-108).');

        $this->assertMatchesRegularExpression(
            '/<time[^>]*datetime="\{\{\s*\$article\[\'' . self::KEY . '\'\]\s*\}\}"/',
            $source,
            'The <time> element does not carry the article\'s own ' . self::KEY . ' value in its '
            . '`datetime` attribute.'
        );

        $this->assertStringContainsString("__('Last Updated')", $source,
            'The date is rendered with no label, so a reader sees a bare date and has to guess '
            . 'what it means. "Last Updated" is already declared in both language files, so this '
            . 'reads correctly in Arabic and English without a language-file change.');

        /* A date printed only when it happens to be set would make the field optional in practice
           and let a future article ship without one while every test still passed. */
        $this->assertMatchesRegularExpression(
            "/@if\s*\(\s*\\\$article\['" . self::KEY . "'\]\s*!==\s*''\s*\)/",
            $source,
            'The view does not guard the date on an empty value. The library returns an empty '
            . 'string for malformed front matter rather than hiding the article, so an unguarded '
            . 'render would emit `<time datetime="">`.'
        );
    }
}

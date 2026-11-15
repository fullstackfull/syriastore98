<?php

namespace Tests\Unit\Platform;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M6 — THE CORPUS ITSELF, ASSERTED AS DATA.
 * =================================================================================================
 *
 * The documentation is files in `resources/docs/`, so the failures it can have are file failures:
 * a front-matter block somebody breaks while editing prose, a `related:` id that names nothing, a
 * category with no `_category.md`, an article present in one language and silently absent from the
 * other. None of those produces an error anywhere — the article simply stops existing, or a link
 * quietly disappears. This suite is what makes them visible.
 */
class DocumentationLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    #[Test]
    public function every_shipped_markdown_file_parses(): void
    {
        $root  = base_path(DocumentationLibrary::ROOT);
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with($file->getFilename(), '.md')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'PREMISE: no documentation files found.');
        $this->assertGreaterThanOrEqual(40, count($files), 'PREMISE: the corpus shrank unexpectedly.');

        $broken = [];
        foreach ($files as $file) {
            $meta = DocumentationLibrary::readFrontMatter($file);

            if ($meta === null) {
                $broken[] = str_replace(base_path() . '/', '', $file) . ' — no front-matter block';
                continue;
            }
            if (trim((string) ($meta['title'] ?? '')) === '') {
                $broken[] = str_replace(base_path() . '/', '', $file) . ' — no title';
            }
        }

        $this->assertSame([], $broken,
            "A documentation file would be silently invisible:\n  " . implode("\n  ", $broken));
    }

    #[Test]
    public function every_related_id_names_a_real_article(): void
    {
        /* `related()` DROPS an id that names nothing, so a typo in front matter is invisible on the
           page. This is what makes it visible. */
        $dangling = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (DocumentationLibrary::articles($locale) as $article) {
                foreach ($article['related'] as $id) {
                    [$category, $slug] = array_pad(explode('/', $id, 2), 2, '');

                    if (DocumentationLibrary::article($locale, $category, $slug) === null) {
                        $dangling[] = "{$locale} {$article['id']} -> {$id}";
                    }
                }
            }
        }

        $this->assertSame([], $dangling,
            "A `related:` id names nothing and would be dropped in silence:\n  "
            . implode("\n  ", $dangling));
    }

    #[Test]
    public function the_two_languages_carry_the_same_articles(): void
    {
        /* The fallback means a missing Arabic article still renders — in English, with a notice.
           That is the right behaviour for a reader and the wrong thing to leave undetected, so the
           gap is asserted here rather than relied upon. */
        $en = array_map(static fn ($a) => $a['id'], DocumentationLibrary::articles('en'));
        $ar = [];
        foreach (DocumentationLibrary::categories('ar') as $category) {
            foreach ($category['articles'] as $article) {
                $ar[] = $article['id'];
            }
        }

        sort($en);
        sort($ar);

        $this->assertSame($en, $ar, 'an article exists in one language and not the other');
        $this->assertNotEmpty($en);
    }

    #[Test]
    public function an_untranslated_article_falls_back_rather_than_vanishing(): void
    {
        /* Driven against the real mechanism by asking for a locale the corpus does not ship. */
        $article = DocumentationLibrary::article('fr', 'getting-started', 'create-your-account');

        $this->assertNotNull($article);
        $this->assertSame('en', $article['locale'], 'the fallback must report the locale it really used');
    }

    #[Test]
    public function categories_and_articles_keep_their_declared_order(): void
    {
        $categories = DocumentationLibrary::categories('en');

        $this->assertSame('getting-started', $categories[0]['slug'],
            'the ordering prefix on the directory name is not being honoured');

        $first = $categories[0]['articles'];
        $this->assertSame('create-your-account', $first[0]['slug']);
        $this->assertSame('choose-a-plan', $first[1]['slug']);
    }

    #[Test]
    public function the_numeric_prefix_is_stripped_from_the_public_slug(): void
    {
        /* The files are named `01-...` so they sort on disk; the URL must not carry that. */
        foreach (DocumentationLibrary::articles('en') as $article) {
            $this->assertDoesNotMatchRegularExpression('/^\d+-/', $article['slug'], $article['id']);
            $this->assertDoesNotMatchRegularExpression('/^\d+-/', $article['category'], $article['id']);
        }
    }

    #[Test]
    public function neighbours_run_within_a_category_and_stop_at_its_edges(): void
    {
        $first = DocumentationLibrary::article('en', 'getting-started', 'create-your-account');
        $n     = DocumentationLibrary::neighbours('en', $first);

        $this->assertNull($n['prev'], 'the first article has no predecessor');
        $this->assertSame('choose-a-plan', $n['next']['slug']);

        $last = DocumentationLibrary::article('en', 'getting-started', 'create-your-first-store');
        $n    = DocumentationLibrary::neighbours('en', $last);

        $this->assertSame('choose-a-plan', $n['prev']['slug']);
        $this->assertNull($n['next'], 'the last article in a category has no successor');
    }

    #[Test]
    public function every_article_carries_a_summary_and_at_least_one_heading(): void
    {
        /* A summary is printed on three surfaces (index, category, search) and an article without
           one shows a blank line in each. Headings are what the contents panel is built from. */
        $thin = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (DocumentationLibrary::articles($locale) as $article) {
                if (trim($article['summary']) === '') {
                    $thin[] = "{$locale} {$article['id']} — no summary";
                }
                if (DocumentationRenderer::tableOfContents($article['body']) === []) {
                    $thin[] = "{$locale} {$article['id']} — no headings";
                }
            }
        }

        $this->assertSame([], $thin, implode("\n  ", $thin));
    }

    #[Test]
    public function an_unknown_locale_resolves_to_the_fallback(): void
    {
        $this->assertSame('en', DocumentationLibrary::resolveLocale('fr'));
        $this->assertSame('en', DocumentationLibrary::resolveLocale(''));
        $this->assertSame('ar', DocumentationLibrary::resolveLocale('ar'));
    }
}

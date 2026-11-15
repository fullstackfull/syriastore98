<?php

namespace Tests\Feature\Platform;

use App\Support\Docs\DocumentationLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M6 — THE DOCUMENTATION CENTRE IN ARABIC.
 * =================================================================================================
 *
 * Two different bilingual failures live here and they need different assertions.
 *
 *   1. **The CHROME is English on an Arabic page.** `__()` returns its key when a translation is
 *      missing, and the key here IS the English sentence — so a missing translation puts English
 *      back on the page. Asserted as "the English string is ABSENT", which is the direction that
 *      can actually fail. (M4's bilingual test asserted Arabic PRESENT and passed with its key
 *      deleted, because the Arabic was a substring of another sentence.)
 *
 *   2. **The CONTENT is English on an Arabic page.** Different mechanism entirely: the article
 *      body comes from `resources/docs/ar/...`, not from a translation file. Asserted against the
 *      real Arabic file's own text, and the fallback case is asserted to SAY SO rather than to be
 *      silently English.
 */
class PlatformDocsBilingualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    #[Test]
    public function the_arabic_centre_is_right_to_left_and_the_english_one_is_not(): void
    {
        foreach (['/platform/docs', '/platform/docs/getting-started', '/platform/docs/search?q=x'] as $url) {
            $ar = $this->withSession(['LANGUAGE' => 'ar'])->get($url)->getContent();
            $this->assertStringContainsString('dir="rtl"', $ar, "no RTL on {$url}");

            $en = $this->withSession(['LANGUAGE' => 'en'])->get($url)->getContent();
            $this->assertStringContainsString('dir="ltr"', $en, "no LTR on {$url}");
        }
    }

    #[Test]
    public function the_centre_chrome_is_translated(): void
    {
        $html = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform/docs')->getContent();

        $this->assertStringContainsString('كيف تستخدم ستور سوريا', $html);
        $this->assertStringContainsString('ابحث في الوثائق', $html);

        /* THE ASSERTIONS THAT CAN FAIL. */
        $this->assertStringNotContainsString('How to use Store Syria', $html);
        $this->assertStringNotContainsString('Search the documentation', $html);
    }

    #[Test]
    public function an_article_is_served_from_the_arabic_corpus(): void
    {
        $en = $this->withSession(['LANGUAGE' => 'en'])
            ->get('/platform/docs/your-storefront/edit-your-theme');
        $en->assertOk();
        $en->assertSee('Nothing you type is live yet', escape: false);

        $ar = $this->withSession(['LANGUAGE' => 'ar'])
            ->get('/platform/docs/your-storefront/edit-your-theme');
        $ar->assertOk();

        $html = $ar->getContent();

        /* The Arabic file's own heading, and the English one's absence. */
        $this->assertStringContainsString('لا شيء تكتبه يصبح مباشراً فوراً', $html);
        $this->assertStringNotContainsString('Nothing you type is live yet', $html);
        $this->assertStringNotContainsString('Where the editor is', $html);
    }

    #[Test]
    public function the_arabic_category_and_article_titles_come_from_the_arabic_files(): void
    {
        $html = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform/docs')->getContent();

        foreach (DocumentationLibrary::categories('ar') as $category) {
            $this->assertStringContainsString($category['title'], $html);
        }

        /* And the English titles are not also on the page. */
        foreach (DocumentationLibrary::categories('en') as $category) {
            $this->assertStringNotContainsString($category['title'], $html, $category['slug']);
        }
    }

    #[Test]
    public function the_contents_panel_links_resolve_in_arabic_too(): void
    {
        /* Arabic headings get Arabic ids. A contents panel whose links go nowhere is worse than
           none, and this is the language where it is most likely to break. */
        $html = $this->withSession(['LANGUAGE' => 'ar'])
            ->get('/platform/docs/your-storefront/edit-your-theme')
            ->getContent();

        $this->assertMatchesRegularExpression('/<h2 id="[^"]+">/u', $html);

        preg_match_all('/href="#([^"]+)"/u', $html, $links);
        $this->assertNotEmpty($links[1], 'the Arabic page has no contents links at all');

        foreach ($links[1] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "contents link #{$id} goes nowhere");
        }
    }

    /**
     * THE FALLBACK NOTICE, DRIVEN AGAINST A REAL GAP.
     *
     * The shipped corpora are complete — `DocumentationLibraryTest` asserts that — so there is no
     * untranslated article to request. Rather than assert nothing, this writes one: an English
     * article with no Arabic counterpart, exactly the state the fallback exists for. The file is
     * removed in `finally`, and `DocumentationLibrary::forget()` runs on both sides because the
     * index is cached.
     *
     * An earlier version of this test asked for locale `fr` instead. That resolves to the English
     * corpus, so the article was never a fallback and the notice was never reachable — it asserted
     * only that the page did not 404.
     */
    #[Test]
    public function an_untranslated_article_is_served_in_english_and_says_so(): void
    {
        $file = base_path('resources/docs/en/01-getting-started/99-untranslated-fixture.md');

        file_put_contents($file, <<<'MD'
            ---
            title: Untranslated Fixture Article
            summary: A fixture with no Arabic counterpart.
            order: 99
            ---

            ## Fixture Heading

            Fixture body text.
            MD);

        try {
            DocumentationLibrary::forget();

            $response = $this->withSession(['LANGUAGE' => 'ar'])
                ->get('/platform/docs/getting-started/untranslated-fixture');

            $response->assertOk();
            $html = $response->getContent();

            /* The English content is served... */
            $this->assertStringContainsString('Untranslated Fixture Article', $html);
            $this->assertStringContainsString('Fixture body text.', $html);

            /* ...AND the reader is told why, rather than being left to think the product speaks
               English to them at random. */
            $this->assertStringContainsString('لم تُترجَم هذه الصفحة بعد', $html);

            /* The body is marked as English so a screen reader does not read it with an Arabic
               voice, and laid out left to right. */
            $this->assertStringContainsString('lang="en"', $html);
        } finally {
            @unlink($file);
            DocumentationLibrary::forget();
        }
    }

    #[Test]
    public function search_in_arabic_reaches_the_arabic_corpus(): void
    {
        $response = $this->withSession(['LANGUAGE' => 'ar'])
            ->get('/platform/docs/search?q=' . urlencode('قالب'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('نتائج البحث عن', $html);
        $this->assertStringContainsString('اختر قالباً', $html);
        $this->assertStringNotContainsString('Results for', $html);
    }
}

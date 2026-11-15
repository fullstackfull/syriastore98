<?php

namespace Tests\Feature\Platform;

use App\Http\Controllers\PlatformDocsController;
use App\Support\Docs\DocumentationLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M6 — THE DOCUMENTATION CENTRE OVER HTTP.
 * =================================================================================================
 *
 * THE FAILURE THIS SUITE IS SHAPED AROUND: `/platform/docs/search` and `/platform/docs/{category}`
 * are both two-segment GETs, so the later pattern would swallow the first and answer it with a 404
 * for a category called "search". From outside, that 404 and a genuinely missing category look
 * identical — so the route is resolved through the REAL router and the controller METHOD is
 * asserted, which is the only check that can tell them apart.
 *
 * The second shape guarded here is the one CLAUDE.md records: a layout that `@yield`s renders
 * perfect chrome, HTTP 200 and an EMPTY CONTENT AREA for a view written in the `<x-*-layout>`
 * shape. Every assertion below names a string that exists only inside the page's own content.
 */
class PlatformDocsRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    #[Test]
    public function the_library_actually_ships_content(): void
    {
        /* PREMISE. Every assertion in this file is vacuous against an empty corpus, and an empty
           corpus is a realistic failure: a path change or a parser regression produces one
           silently. */
        $en = DocumentationLibrary::categories('en');
        $ar = DocumentationLibrary::categories('ar');

        $this->assertGreaterThanOrEqual(5, count($en), 'the English corpus is missing or unparsed');
        $this->assertGreaterThanOrEqual(5, count($ar), 'the Arabic corpus is missing or unparsed');
        $this->assertGreaterThanOrEqual(15, count(DocumentationLibrary::articles('en')));
    }

    #[Test]
    public function the_index_lists_every_category_and_article(): void
    {
        $response = $this->get('/platform/docs');

        $response->assertOk();
        $response->assertSee('How to use Store Syria', escape: false);

        $html = $response->getContent();

        foreach (DocumentationLibrary::categories('en') as $category) {
            $this->assertStringContainsString($category['title'], $html);

            foreach ($category['articles'] as $article) {
                $this->assertStringContainsString($article['title'], $html);
            }
        }
    }

    #[Test]
    public function a_category_page_renders_its_own_articles(): void
    {
        $category = DocumentationLibrary::categories('en')[0];

        $response = $this->get('/platform/docs/' . $category['slug']);

        $response->assertOk();
        $response->assertSee($category['title'], escape: false);

        foreach ($category['articles'] as $article) {
            $response->assertSee($article['title'], escape: false);
        }
    }

    #[Test]
    public function an_article_renders_its_body_breadcrumbs_and_contents(): void
    {
        $article = DocumentationLibrary::article('en', 'getting-started', 'create-your-account');

        $this->assertNotNull($article, 'PREMISE: the fixture article moved.');

        $response = $this->get('/platform/docs/getting-started/create-your-account');
        $response->assertOk();

        $html = $response->getContent();

        /* The body is RENDERED, not printed as Markdown. */
        $this->assertStringContainsString('<h2 id="register">', $html);
        $this->assertStringNotContainsString('## Register', $html);

        /* Breadcrumbs name the trail, and the contents panel links to the headings. */
        $this->assertStringContainsString('ss-ps-breadcrumb', $html);
        $this->assertStringContainsString('Getting started', $html);
        $this->assertStringContainsString('href="#register"', $html);

        /* Related articles and the in-category pager. */
        $this->assertStringContainsString('Choose a plan', $html);
        $this->assertStringContainsString('ss-ps-pager', $html);
    }

    #[Test]
    public function an_unknown_category_or_article_is_a_404_not_a_silent_redirect(): void
    {
        $this->get('/platform/docs/no-such-category')->assertNotFound();
        $this->get('/platform/docs/getting-started/no-such-article')->assertNotFound();
    }

    /**
     * THE PRECEDENCE TEST. `/platform/docs/search` must reach `search()`, not `category()`.
     */
    #[Test]
    public function the_router_sends_each_documentation_url_to_the_right_method(): void
    {
        $cases = [
            '/platform/docs'                                       => 'index',
            '/platform/docs/search'                                => 'search',
            '/platform/docs/getting-started'                    => 'category',
            '/platform/docs/getting-started/create-your-account' => 'article',
        ];

        foreach ($cases as $url => $method) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($url, 'GET'));

            $this->assertSame(
                PlatformDocsController::class,
                $route->getController()::class,
                "{$url} left the documentation controller"
            );
            $this->assertSame(
                $method,
                $route->getActionMethod(),
                "{$url} resolved to {$route->getActionMethod()}() instead of {$method}()"
            );
        }
    }

    #[Test]
    public function the_search_page_answers_a_real_query_with_real_results(): void
    {
        $response = $this->get('/platform/docs/search?q=' . urlencode('theme'));

        $response->assertOk();
        $response->assertSee('Results for', escape: false);
        /* A specific article that genuinely discusses themes. */
        $response->assertSee('Choose a theme', escape: false);
    }

    #[Test]
    public function an_empty_query_prompts_rather_than_listing_everything(): void
    {
        $html = $this->get('/platform/docs/search')->getContent();

        $this->assertStringContainsString('Type what you are stuck on', $html);
        $this->assertStringNotContainsString('Results for', $html);
    }

    #[Test]
    public function a_query_that_matches_nothing_says_so(): void
    {
        $html = $this->get('/platform/docs/search?q=' . urlencode('zzzzqqqq'))->getContent();

        $this->assertStringContainsString('Nothing matched that', $html);
    }

    #[Test]
    public function the_query_is_echoed_escaped_and_capped(): void
    {
        /* The query is printed back to the reader. Blade escapes it; this pins that it does, since
           an unescaped echo of a search box is the oldest reflected-XSS shape there is. */
        $html = $this->get('/platform/docs/search?q=' . urlencode('<script>alert(1)</script>'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        /* And it is capped, so a very long value is neither stored in a heading nor pushed through
           the normaliser at length. */
        $long = str_repeat('a', 500);
        $html = $this->get('/platform/docs/search?q=' . $long)->getContent();
        $this->assertStringNotContainsString($long, $html);
    }

    #[Test]
    public function every_documentation_page_carries_the_site_chrome(): void
    {
        foreach ([
            '/platform/docs',
            '/platform/docs/search?q=theme',
            '/platform/docs/getting-started',
            '/platform/docs/getting-started/create-your-account',
        ] as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString('ss-ps-header', $html, "header missing on {$url}");
            $this->assertStringContainsString('ss-ps-footer', $html, "footer missing on {$url}");
            $this->assertStringContainsString('css/store-syria-platform.css', $html, "stylesheet missing on {$url}");
        }
    }

    #[Test]
    public function every_article_in_the_corpus_renders(): void
    {
        /* A sweep, not a sample. A single unparsed file or a broken `related` id would otherwise
           sit undetected until a reader found it. */
        foreach (['en', 'ar'] as $locale) {
            foreach (DocumentationLibrary::articles($locale) as $article) {
                $url = '/platform/docs/' . $article['category'] . '/' . $article['slug'];

                $this->withSession(['LANGUAGE' => $locale])
                    ->get($url)
                    ->assertOk();
            }
        }
    }
}

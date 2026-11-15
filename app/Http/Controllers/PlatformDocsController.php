<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use App\Support\Docs\DocumentationSearch;
use Illuminate\Http\Request;

/**
 * PHASE 03 M6 — THE BILINGUAL DOCUMENTATION CENTRE.
 * =================================================================================================
 *
 * Merchant-facing help: how to open a shop, edit a theme, run a catalogue, handle orders and look
 * after the account. Public, because somebody deciding whether to subscribe should be able to read
 * what using the product is actually like.
 *
 * NOT `/api-docs`. That is `packages/workdo/Api/src/routes/web.php:18`, it sits behind the merchant
 * auth stack, it is vendored, and it documents the HTTP API rather than the product. The owner's
 * brief names it explicitly as the thing this is not.
 *
 * -------------------------------------------------------------------------------------------------
 * THE LOCALE IS THE REQUEST'S, NOT A URL SEGMENT. `setlocate` has already resolved it from the
 * session, the cookie or the super admin's language, and the language switch in the site chrome
 * already works on every other page. Putting `/en/` and `/ar/` in the path would give the same
 * article two addresses, split whatever link equity it earns, and leave the switch in the header
 * disagreeing with the one in the URL.
 *
 * The cost of that choice is that a shared link opens in the reader's language rather than the
 * sender's. For help content that is the better failure: a reader wants their own language.
 *
 * WHEN AN ARTICLE IS NOT TRANSLATED YET the library falls back to English and the view SAYS SO.
 * A 404 would be worse (the reader came from a link that should work) and a silent swap would be
 * worse still (we would never find out which articles are missing).
 */
class PlatformDocsController extends Controller
{
    /** The centre's front page: every category with its articles. */
    public function index()
    {
        $locale = $this->locale();

        return view('platform.docs.index', [
            'locale'     => $locale,
            'categories' => DocumentationLibrary::categories($locale),
        ]);
    }

    public function category(string $category)
    {
        $locale = $this->locale();
        $row    = DocumentationLibrary::category($locale, $category);

        if ($row === null) {
            /* A category that does not exist is a 404, not a redirect to the index: a wrong URL
               should say it is wrong rather than quietly showing something else. */
            abort(404);
        }

        return view('platform.docs.category', [
            'locale'     => $locale,
            'category'   => $row,
            'categories' => DocumentationLibrary::categories($locale),
        ]);
    }

    public function article(string $category, string $article)
    {
        $locale = $this->locale();
        $row    = DocumentationLibrary::article($locale, $category, $article);

        if ($row === null) {
            abort(404);
        }

        return view('platform.docs.article', [
            'locale'      => $locale,
            'article'     => $row,
            /* TRUE when the reader asked for one language and got another. The view must show it;
               see the class docblock. */
            'isFallback'  => $row['locale'] !== $locale,
            'html'        => DocumentationRenderer::html($row['body']),
            'toc'         => DocumentationRenderer::tableOfContents($row['body']),
            'related'     => DocumentationLibrary::related($locale, $row),
            'neighbours'  => DocumentationLibrary::neighbours($locale, $row),
            'category'    => DocumentationLibrary::category($locale, $row['category']),
            'categories'  => DocumentationLibrary::categories($locale),
        ]);
    }

    public function search(Request $request)
    {
        $locale = $this->locale();

        /* The query is a string and nothing else. It is echoed back to the reader, so it is capped
           rather than trusted: an unbounded value would be a very long line in a heading and a very
           large string through the normaliser on every result. Escaping is the view's job and Blade
           does it; this is about size, not about markup. */
        $query = (string) $request->query('q', '');
        $query = mb_substr(trim($query), 0, 120);

        return view('platform.docs.search', [
            'locale'     => $locale,
            'query'      => $query,
            'results'    => $query === '' ? [] : DocumentationSearch::run($locale, $query),
            'categories' => DocumentationLibrary::categories($locale),
        ]);
    }

    /** The locale the centre ships, derived from the request's own. */
    private function locale(): string
    {
        return DocumentationLibrary::resolveLocale(app()->getLocale() ?: 'en');
    }
}

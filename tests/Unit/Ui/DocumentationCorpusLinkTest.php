<?php

namespace Tests\Unit\Ui;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-109 (P03-D-06) — LINK CHECKING AS A STANDING CONTROL, NOT A ONE-OFF RUN.
 * =================================================================================================
 *
 * The gap, verbatim: "The only link class present in the corpus is checked in CI; the browser crawl
 * that would catch the rest is manual and samples one article per locale. If a body link is ever
 * added, nothing checks it." Its next action offers two routes; this file takes the second, "add a
 * corpus-wide Markdown link assertion".
 *
 * MEASURED FIRST, AND THE MEASUREMENT SHAPES THE WHOLE FILE: the corpus contains **zero** body
 * links today. No inline `[text](target)`, no autolink, no reference definition, no bare URL, in
 * either locale. `DocumentationLibraryTest::every_related_id_names_a_real_article` covers the one
 * class that does exist — `related:` ids in front matter — which is exactly the "only link class
 * present in the corpus is checked" the gap describes.
 *
 * SO A PLAIN "EVERY LINK RESOLVES" TEST WOULD BE VACUOUS, AND VACUOUS FOREVER. It would iterate an
 * empty set, assert nothing, and report green — and it would still report green on the day somebody
 * added a dead link, if the extractor had a bug, because an extractor that finds nothing is
 * indistinguishable from a corpus with nothing to find. That is the failure this repository has
 * recorded repeatedly under a different name: a census satisfied by its own blind spot.
 *
 * THE CONTROL IS THEREFORE BUILT IN TWO HALVES.
 *
 *   1. THE VALIDATOR IS PROVEN AGAINST FIXTURES. Every kind of broken link is driven through
 *      `problems()` and must be rejected; every kind of good one must be accepted. These cases do
 *      not depend on the corpus at all, so they keep working — and keep failing on a regression —
 *      whether the corpus has zero links or four hundred.
 *   2. THE PROVEN VALIDATOR IS THEN RUN OVER THE WHOLE CORPUS, both locales, every article. Today
 *      that finds nothing to complain about because there is nothing to complain about. The day a
 *      writer adds `[the theme editor](/platform/docs/your-storefront/edit-your-theme)` it is
 *      checked, with no further work, which is what "standing control" means.
 *
 * WHAT IS CHECKED, AND THE ONE LIMITATION, STATED RATHER THAN HIDDEN.
 *
 *   - A docs link is resolved against the real index: `/platform/docs/<category>/<article>` must
 *     name an article the library holds, `/platform/docs/<category>` a category it holds.
 *   - Any other absolute internal path is matched against the application's own route table. THE
 *     LIMITATION: the storefront registers `/{slug}/...` patterns, so a nonsense path can be
 *     absorbed by a catch-all and pass. Docs links — the overwhelmingly likely kind in this
 *     corpus, and the kind the gap is about — are resolved exactly instead, which is why they are
 *     handled separately rather than left to the router.
 *   - A fragment-only link must name a heading that exists in the SAME article, resolved through
 *     `DocumentationRenderer::slug` — the same function that writes the heading ids, so the two
 *     cannot drift.
 *   - A scheme must be https, mailto or tel. `javascript:`, `data:` and `http:` are refused.
 *     CommonMark's `allow_unsafe_links => false` already strips the first two at render time; this
 *     makes it a control on the SOURCE as well, so a dangerous link is a failed build rather than a
 *     silently empty anchor a writer never notices.
 *   - A relative target is refused outright. The docs routes are two-segment, so `../x/y` resolves
 *     differently from a category page than from an article page, and a link that works on one
 *     page and 404s on another is worse than one that never worked.
 *
 * NO NETWORK. An external link's host is not fetched: CI has no egress, a fetch would make the
 * suite depend on somebody else's uptime, and a 200 today says nothing about tomorrow. The
 * assertion is on the form of the URL, not its availability.
 */
class DocumentationCorpusLinkTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> locale, id, body */
    public static function articleBodies(): array
    {
        $root = dirname(__DIR__, 3) . '/' . DocumentationLibrary::ROOT;

        $out = [];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            foreach (glob($root . '/' . $locale . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $category = preg_replace('/^\d+[-_]/', '', basename($dir));

                foreach (glob($dir . '/*.md') ?: [] as $file) {
                    if (basename($file) === '_category.md') {
                        continue;
                    }

                    $slug = preg_replace('/^\d+[-_]/', '', pathinfo($file, PATHINFO_FILENAME));
                    $meta = DocumentationLibrary::readFrontMatter($file);

                    $out[$locale . '/' . $category . '/' . $slug] = [
                        $locale,
                        $category . '/' . $slug,
                        (string) ($meta['__body'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------
    // THE EXTRACTOR AND THE VALIDATOR
    // ---------------------------------------------------------------------------------------------

    /**
     * Every link target in a Markdown body.
     *
     * FENCED CODE IS REMOVED FIRST. `[text](url)` inside a code sample is a code sample, not a
     * link, and CommonMark does not render it as one — so treating it as a link would make a
     * documentation example of Markdown syntax an unfixable failure.
     *
     * Four syntaxes, because a control that knows one is a control a writer can walk around
     * without meaning to: inline `[a](b)`, autolink `<https://…>`, reference definition
     * `[label]: target`, and a bare URL in prose.
     *
     * @return list<string>
     */
    private function targets(string $body): array
    {
        $body = (string) preg_replace('/```.*?```/s', '', $body);
        $body = (string) preg_replace('/^(?: {4}|\t).*$/m', '', $body);

        $out = [];

        if (preg_match_all('/\[[^]]*\]\(\s*([^)\s]*)[^)]*\)/', $body, $m) > 0) {
            $out = array_merge($out, $m[1]);
        }

        if (preg_match_all('/<((?:[a-z][a-z0-9+.-]*:|\/|#)[^>\s]*)>/i', $body, $m) > 0) {
            $out = array_merge($out, $m[1]);
        }

        if (preg_match_all('/^\[[^]]+\]:\s*(\S+)/m', $body, $m) > 0) {
            $out = array_merge($out, $m[1]);
        }

        /* A bare URL in prose. CommonMark's default does not autolink it, so it renders as text
           and is NOT a link — but it is a URL a reader will copy, so the same form rules apply. */
        if (preg_match_all('~(?<![(<\]])\bhttps?://[^\s)<>]+~i', $body, $m) > 0) {
            $out = array_merge($out, $m[0]);
        }

        return array_values(array_unique(array_filter($out, static fn ($t) => $t !== '')));
    }

    /**
     * What is wrong with each link in a body, as human-readable sentences.
     *
     * @return list<string>
     */
    private function problems(string $locale, string $articleId, string $body): array
    {
        $problems = [];

        foreach ($this->targets($body) as $target) {
            $problem = $this->problemWith($locale, $articleId, $body, $target);

            if ($problem !== null) {
                $problems[] = $articleId . ' (' . $locale . '): `' . $target . '` — ' . $problem;
            }
        }

        /* An empty inline target — `[text]()` — survives the extractor's filter as the empty
           string, so it is caught here rather than silently dropped. */
        if (preg_match('/\[[^]]*\]\(\s*\)/', $body) === 1) {
            $problems[] = $articleId . ' (' . $locale . '): a link with an empty target.';
        }

        return $problems;
    }

    private function problemWith(string $locale, string $articleId, string $body, string $target): ?string
    {
        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $target, $m) === 1) {
            $scheme = strtolower($m[1]);

            if (in_array($scheme, ['mailto', 'tel'], true)) {
                return strlen($target) > strlen($scheme) + 1
                    ? null
                    : 'a ' . $scheme . ' link with nothing after the scheme.';
            }

            if ($scheme === 'https') {
                return filter_var($target, FILTER_VALIDATE_URL) !== false
                    ? null
                    : 'not a well-formed URL.';
            }

            if ($scheme === 'http') {
                return 'an insecure http link; use https.';
            }

            return 'a `' . $scheme . ':` link. Only https, mailto and tel are allowed — '
                . 'CommonMark strips the dangerous schemes at render time, leaving an anchor that '
                . 'silently goes nowhere.';
        }

        if (str_starts_with($target, '#')) {
            $fragment = substr($target, 1);

            foreach (DocumentationRenderer::tableOfContents($body) as $heading) {
                if ($heading['id'] === $fragment) {
                    return null;
                }
            }

            return 'a fragment naming no heading in this article.';
        }

        if (! str_starts_with($target, '/')) {
            return 'a relative target. The docs routes are two-segment, so this resolves '
                . 'differently from a category page than from an article page. Use an absolute path.';
        }

        return $this->problemWithInternalPath($locale, $target);
    }

    private function problemWithInternalPath(string $locale, string $path): ?string
    {
        $path = strtok($path, '#?');
        $trimmed = trim((string) $path, '/');

        if ($trimmed === 'platform/docs' || $trimmed === 'platform/docs/search') {
            return null;
        }

        if (str_starts_with($trimmed, 'platform/docs/')) {
            $rest = explode('/', substr($trimmed, strlen('platform/docs/')));

            if (count($rest) === 1) {
                return DocumentationLibrary::category($locale, $rest[0]) === null
                    ? 'names no documentation category.'
                    : null;
            }

            if (count($rest) === 2) {
                return DocumentationLibrary::article($locale, $rest[0], $rest[1]) === null
                    ? 'names no documentation article.'
                    : null;
            }

            return 'has more path segments than any documentation route takes.';
        }

        /* Everything else goes to the router. See the class docblock for why this is weaker than
           the docs resolution above. */
        try {
            Route::getRoutes()->match(Request::create('/' . $trimmed, 'GET'));
        } catch (\Throwable) {
            return 'matches no route in this application.';
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // HALF ONE: THE VALIDATOR IS PROVEN, WHATEVER THE CORPUS CONTAINS
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> a body, and it must be rejected. */
    public static function brokenLinks(): array
    {
        return [
            'dead docs article' => ['See [this](/platform/docs/products/no-such-article).'],
            'dead docs category' => ['See [this](/platform/docs/no-such-category).'],
            'too many docs segments' => ['See [this](/platform/docs/products/add-a-product/extra).'],
            'dead internal path' => ['See [this](/definitely-not-a-route-xyzzy/nope/nope).'],
            'relative target' => ['See [this](../orders/how-an-order-moves).'],
            'bare relative target' => ['See [this](how-an-order-moves).'],
            'javascript scheme' => ['See [this](javascript:alert(1)).'],
            'data scheme' => ['See [this](data:text/html;base64,PHA+).'],
            'insecure http' => ['See [this](http://example.com/page).'],
            'empty target' => ['See [this]().'],
            'unknown fragment' => ["## Real heading\n\nSee [this](#not-a-heading)."],
            'autolink with a bad scheme' => ['See <javascript:alert(1)> here.'],
            'reference definition to a dead path' => ["See [a][b].\n\n[b]: /nope-nope-nope/x/y"],
            'bare insecure url in prose' => ['Read more at http://example.com/thing for details.'],
        ];
    }

    #[Test]
    #[DataProvider('brokenLinks')]
    public function test_the_validator_rejects_every_kind_of_broken_link(string $body): void
    {
        DocumentationLibrary::forget();

        $this->assertNotSame([], $this->problems('en', 'products/add-a-product', $body),
            "The validator accepted a link it must refuse. The corpus has no body links today, so "
            . 'these fixtures are the ONLY thing standing between a dead link and a green build.');
    }

    /** @return array<string, array{0: string}> a body, and it must be accepted. */
    public static function goodLinks(): array
    {
        return [
            'docs index' => ['See [the centre](/platform/docs).'],
            'docs search' => ['See [search](/platform/docs/search).'],
            'real category' => ['See [products](/platform/docs/products).'],
            'real article' => ['See [adding a product](/platform/docs/products/add-a-product).'],
            'real article in another category' => ['See [orders](/platform/docs/orders/how-an-order-moves).'],
            'https url' => ['See [the spec](https://www.w3.org/TR/WCAG21/).'],
            'mailto' => ['Write to [us](mailto:help@example.com).'],
            'own heading' => ["## Prepare the file\n\nSee [above](#prepare-the-file)."],
            'arabic heading fragment' => ["## جهّز الملف\n\nانظر [أعلاه](#جهّز-الملف)."],
            'autolink https' => ['Read <https://www.w3.org/TR/WCAG21/> for the detail.'],
            'link inside a fenced block is not a link' => ["```\n[x](/nope-nope/a/b)\n```"],
            'path with a fragment' => ['See [this](/platform/docs/products/add-a-product#what-you-need).'],
        ];
    }

    #[Test]
    #[DataProvider('goodLinks')]
    public function test_the_validator_accepts_every_kind_of_good_link(string $body): void
    {
        DocumentationLibrary::forget();

        $this->assertSame([], $this->problems('en', 'products/import-from-a-file', $body),
            'The validator refused a link that is fine, which would make the control something a '
            . 'writer has to work around rather than something that helps them.');
    }

    /**
     * THE EXTRACTOR'S OWN PREMISE. Everything above depends on `targets()` finding links at all; an
     * extractor that returns an empty list makes every rejection case above pass for the wrong
     * reason, because `problems()` would be comparing two empty sets.
     */
    #[Test]
    public function test_the_extractor_finds_each_link_syntax(): void
    {
        $body = <<<'MD'
            Inline [one](/platform/docs) and [two](https://example.com/a).

            An autolink <https://example.com/b> and a reference [three][r].

            [r]: /platform/docs/search

            A bare https://example.com/c in prose.
            MD;

        $found = $this->targets($body);

        foreach ([
            '/platform/docs',
            'https://example.com/a',
            'https://example.com/b',
            '/platform/docs/search',
            'https://example.com/c',
        ] as $expected) {
            $this->assertContains($expected, $found,
                "The extractor missed `{$expected}`, so every link of that syntax is unchecked.");
        }
    }

    /** And it must NOT find a link that CommonMark does not render as one. */
    #[Test]
    public function test_the_extractor_ignores_links_inside_code(): void
    {
        $fenced = "Text.\n\n```markdown\n[x](/nope-nope-nope)\n```\n\nMore text.";

        $this->assertSame([], $this->targets($fenced),
            'A link inside a fenced code block was extracted. CommonMark renders it as text, so '
            . 'treating it as a link would make a Markdown example impossible to document.');
    }

    // ---------------------------------------------------------------------------------------------
    // HALF TWO: THE PROVEN VALIDATOR, OVER THE WHOLE CORPUS
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('articleBodies')]
    public function test_every_link_in_every_article_resolves(string $locale, string $id, string $body): void
    {
        DocumentationLibrary::forget();

        /* The body must be real: a front-matter parse that failed would hand this an empty string
           and the article would pass with nothing read. */
        $this->assertGreaterThan(200, strlen($body),
            "{$locale}/{$id}: the body came back too short to be the article, so this case is "
            . 'measuring a parse failure rather than its links.');

        $problems = $this->problems($locale, $id, $body);

        $this->assertSame([], $problems,
            "Broken links in the documentation corpus:\n  " . implode("\n  ", $problems));
    }

    /** The census reached both halves of the corpus. */
    #[Test]
    public function test_the_census_covers_both_locales(): void
    {
        $keys = array_keys(self::articleBodies());

        $en = count(array_filter($keys, static fn ($k) => str_starts_with($k, 'en/')));
        $ar = count(array_filter($keys, static fn ($k) => str_starts_with($k, 'ar/')));

        $this->assertGreaterThan(10, $en, 'The link census found almost no English articles.');
        $this->assertSame($en, $ar,
            "The link census covers {$en} English articles and {$ar} Arabic ones. An Arabic "
            . 'article nobody reads is an Arabic article nobody checks.');
    }
}

<?php

namespace Tests\Feature\Storefront;

use App\Support\Docs\DocumentationLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-106 (P03-D-03) AND GAP-108 (P03-D-05) — RENDERED, IN BOTH DIRECTIONS.
 * =================================================================================================
 *
 * Two register rows, one page, and they share a harness because they share the failure mode: a
 * thing that is correct in the source file and wrong on the page a reader gets.
 *
 * GAP-106, verbatim: "Code-sample rendering is untested because no article contains one." That was
 * exactly true — 32 articles, zero fenced code blocks, while `public/css/store-syria-platform.css`
 * had carried `.ss-ps-prose pre` and `.ss-ps-prose pre code` rules since M6 for content that did
 * not exist. So the one hard case in a bilingual documentation centre — a left-to-right code sample
 * inside a right-to-left page — had never been rendered, let alone looked at.
 *
 * WHY `dir="ltr"` ON THE CODE AND NOT CSS. Inside an RTL block the Unicode bidirectional algorithm
 * reorders runs of neutral characters by the surrounding paragraph direction. A CSV header line
 * such as `sku,name,price,stock` is almost entirely neutral — commas, letters, no strong RTL
 * characters — so in an Arabic article the browser is free to lay its segments out right-to-left,
 * and a reader copying it gets a line that does not parse. `text-align: left` does not fix that: it
 * moves the block, not the character order. `dir="ltr"` on the element is what resets the embedding
 * level, and it has to be in the MARKUP because the markup is generated from Markdown.
 *
 * GAP-108 is the date, and it is here as well as in `DocumentationArticleFreshnessTest` because
 * that test asserts the template and this one asserts the page. A guarded `@if` that never fires —
 * because the library emptied the field, or because the view was handed an array without the key —
 * is invisible to a source assertion and obvious here.
 *
 * THE ARABIC CASE IS NOT THE ENGLISH CASE WITH A FLAG. Each assertion runs against a session
 * locale of `ar` and of `en`, and each run first checks the document really came back in the
 * direction asked for. Phase 03's own contrast work found option names at 1.00:1 in one appearance
 * and not the other; a direction pair that does not verify the direction is the same mistake.
 */
class PlatformDocsArticleRenderTest extends TestCase
{
    /** The article this change gave a code block to, as `category/slug`. */
    private const CODE_ARTICLE = 'products/import-from-a-file';

    private function url(string $id): string
    {
        return '/platform/docs/' . $id;
    }

    private function page(string $locale, string $id): string
    {
        $response = $this->withSession(['LANGUAGE' => $locale])->get($this->url($id));

        $this->assertSame(200, $response->getStatusCode(),
            "{$this->url($id)} did not render in {$locale}.");

        $html = $response->getContent();

        $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html,
            "The {$locale} request did not come back in the expected direction, so this case is "
            . 'not measuring what it claims to.');

        return $html;
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['en' => ['en'], 'ar' => ['ar']];
    }

    /** @return array<string, array{0: string, 1: string}> every article x both locales. */
    public static function articlesInBothLocales(): array
    {
        /* Read from the files rather than from the booted library: a provider runs before the
           application exists, so `base_path()` is unavailable here. */
        $root = dirname(__DIR__, 3) . '/' . DocumentationLibrary::ROOT . '/en';

        $out = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $category = preg_replace('/^\d+[-_]/', '', basename($dir));

            foreach (glob($dir . '/*.md') ?: [] as $file) {
                if (basename($file) === '_category.md') {
                    continue;
                }

                $slug = preg_replace('/^\d+[-_]/', '', pathinfo($file, PATHINFO_FILENAME));
                $id = $category . '/' . $slug;

                foreach (['en', 'ar'] as $locale) {
                    $out["{$id} ({$locale})"] = [$locale, $id];
                }
            }
        }

        return $out;
    }

    /** THE PREMISE: the provider found the corpus, and the article GAP-106 needs is in it. */
    #[Test]
    public function test_the_provider_found_the_corpus_including_the_code_article(): void
    {
        $cases = self::articlesInBothLocales();

        $this->assertGreaterThan(20, count($cases),
            'The provider found almost no articles, so a green run below proves nothing.');

        $this->assertArrayHasKey(self::CODE_ARTICLE . ' (ar)', $cases,
            'The article that is supposed to carry the code sample is not in the corpus, so the '
            . 'GAP-106 cases below would silently test nothing.');
    }

    // ---------------------------------------------------------------------------------------------
    // GAP-108 — the date reaches the page
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('articlesInBothLocales')]
    public function test_every_rendered_article_shows_when_it_was_last_updated(string $locale, string $id): void
    {
        $html = $this->page($locale, $id);

        $this->assertMatchesRegularExpression('/<time[^>]*datetime="\d{4}-\d{2}-\d{2}"/', $html,
            "{$this->url($id)} ({$locale}) renders no dated <time> element, so a reader cannot "
            . 'tell how old the page is (GAP-108). An empty `datetime` means the library emptied '
            . 'the field — check the article\'s front matter parses.');
    }

    #[Test]
    #[DataProvider('locales')]
    public function test_the_date_carries_a_label_in_the_readers_own_language(string $locale): void
    {
        $html = $this->page($locale, self::CODE_ARTICLE);

        $expected = $locale === 'ar' ? 'آخر تحديث' : 'Last Updated';

        $this->assertStringContainsString($expected, $html,
            "The {$locale} page does not label the date (expected \"{$expected}\"). A bare date "
            . 'leaves the reader guessing whether it is when the page was written, reviewed or '
            . 'published.');
    }

    /**
     * The ISO date is a left-to-right sequence of neutral characters, so inside an Arabic
     * paragraph the bidirectional algorithm may reorder its hyphen-separated groups.
     */
    #[Test]
    public function test_the_date_is_isolated_from_the_arabic_paragraph_direction(): void
    {
        $html = $this->page('ar', self::CODE_ARTICLE);

        preg_match('/<time[^>]*>.*?<\/time>/s', $html, $m);

        $this->assertNotEmpty($m, 'No <time> element on the Arabic page.');

        $time = $m[0];

        /*
         * THIS ASSERTED `dir="ltr"` UNTIL 2026-11-26, AND THE MECHANISM CHANGED BECAUSE THE
         * CONTENT DID. The original reason — kept here because it is still true of what it
         * described — was: "the date is not direction-isolated on an RTL page, so `2026-11-25` is
         * free to be laid out as `25-11-2026` by the bidirectional algorithm."
         *
         * That was correct while the VISIBLE text was the ISO string: a run of digits and hyphens
         * is bidi-neutral, so forcing an LTR base was exactly right. The visible text is now
         * `25 تشرين الثاني 2026` — Arabic WORDS beside digits — and forcing LTR on that is the
         * opposite of right: it lays the month name out in the wrong order against the numerals.
         *
         * So the assertion now tests the PROPERTY the test is named for, isolation, instead of the
         * one mechanism that used to deliver it. `<bdi>` isolates the value from the surrounding
         * paragraph without imposing a direction on it, letting the content's own first strong
         * character decide — Arabic for the Arabic page, Latin for the English one.
         *
         * IT IS STRICTER THAN WHAT IT REPLACED, NOT LOOSER: it additionally refuses a forced LTR
         * base, which the old assertion REQUIRED and which is now the defect. Both directions
         * bite — remove the `<bdi>` and the first assertion fails; put `dir="ltr"` back on the
         * Arabic page and the second does.
         */
        $this->assertMatchesRegularExpression(
            '/<bdi[^>]*>|dir="(ltr|rtl|auto)"|unicode-bidi\s*:\s*(isolate|plaintext)/',
            $time,
            'The date is not direction-isolated on an RTL page, so it is free to be reordered by '
            . 'the bidirectional algorithm against the surrounding Arabic paragraph.'
        );

        $this->assertStringNotContainsString(
            'dir="ltr"',
            $time,
            'The date is rendered as Arabic words (`25 تشرين الثاني 2026`), and forcing an LTR '
            . 'base direction on Arabic words lays the month name out in the wrong order against '
            . 'the digits. Isolate it (`<bdi>`), do not override it. If the visible text is ever '
            . 'returned to a bare ISO string, `dir="ltr"` becomes correct again and this '
            . 'assertion is the one to change — with a reason, as this one carries.'
        );

        $this->assertStringContainsString(
            'datetime="',
            $time,
            'The machine-readable `datetime` must stay, and stay ISO: it is the only part a parser '
            . 'reads, and localising it would break every consumer while helping no reader.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // GAP-106 — a code sample, and it reads left-to-right on a right-to-left page
    // ---------------------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('locales')]
    public function test_the_corpus_contains_a_rendered_code_block(string $locale): void
    {
        $html = $this->page($locale, self::CODE_ARTICLE);

        $this->assertMatchesRegularExpression('/<pre[^>]*>\s*<code/', $html,
            "The {$locale} copy of " . self::CODE_ARTICLE . ' renders no fenced code block. '
            . 'GAP-106: the centre\'s code-sample rendering was untested because no article '
            . 'contained one, while the stylesheet had carried `.ss-ps-prose pre` rules all along.');

        /* The sample's column names are not invented: they are the headings
           `packages/workdo/ProductCSV/src/app/Exports/ProductsExportData.php:15` really emits. A
           documentation code block showing a made-up schema would be worse than none, and the
           article's own advice is to export the template rather than invent a layout. */
        $this->assertMatchesRegularExpression(
            '/<pre[^>]*>\s*<code[^>]*>[^<]*Product Slug Name[^<]*<\/code>/s',
            $html,
            'The code block does not contain the product-file header it is supposed to show, so '
            . 'something other than the sample is being matched.'
        );
    }

    #[Test]
    #[DataProvider('locales')]
    public function test_a_code_block_is_direction_isolated_in_both_languages(string $locale): void
    {
        $html = $this->page($locale, self::CODE_ARTICLE);

        preg_match('/<pre[^>]*>/', $html, $pre);
        $this->assertNotEmpty($pre, 'No <pre> element.');

        $this->assertStringContainsString('dir="ltr"', $pre[0],
            "The code block on the {$locale} page is not direction-isolated. On the Arabic page "
            . 'that lets the bidirectional algorithm reorder a line of neutral characters such as '
            . '`sku,name,price,stock`, giving a reader a line that does not parse when pasted. '
            . 'It is asserted in BOTH languages because the attribute comes from the renderer, '
            . 'which must not start depending on the request\'s locale.');
    }

    #[Test]
    public function test_inline_code_is_direction_isolated_too(): void
    {
        $html = $this->page('ar', self::CODE_ARTICLE);

        /* The prose block only — `<code>` appears in the page chrome nowhere, but scoping the
           search to the article keeps that true if the chrome ever changes. */
        $at = strpos($html, 'ss-ps-prose');
        $this->assertNotFalse($at, 'The article prose block is not on the page.');
        $prose = substr($html, $at);

        preg_match_all('/<code[^>]*>/', $prose, $all);

        $inline = array_values(array_filter(
            $all[0],
            static fn ($tag) => ! str_contains($tag, 'language-')
        ));

        $this->assertNotEmpty($inline, 'The article has no inline code at all to measure.');

        foreach ($inline as $tag) {
            $this->assertStringContainsString('dir="ltr"', $tag,
                'Inline code inside Arabic prose is not direction-isolated: ' . $tag . '. A short '
                . 'run of neutral characters is exactly the case the bidirectional algorithm '
                . 'reorders, and a column name is unreadable once it has been.');
        }
    }

    /**
     * The renderer's existing security decisions must survive the change that added `dir`.
     *
     * `html_input => strip` and `allow_unsafe_links => false` are the two options
     * `DocumentationRenderer` documents as security choices, and the article view is the only
     * unescaped output on the platform site. Nothing in the repository drove them before this —
     * the view's own comment names a `DocumentationRendererTest` that does not exist (measured
     * 2026-11-25: zero files in `tests/` reference the class).
     */
    #[Test]
    public function test_the_code_block_content_is_escaped_and_not_rendered_as_markup(): void
    {
        $html = $this->page('ar', self::CODE_ARTICLE);

        $this->assertStringNotContainsString('<script', $html,
            'The article page emitted a script tag.');

        /* A `<pre>` whose body contained real markup would have had it rendered rather than
           displayed; CommonMark escapes code-block content, and this is what proves the `dir`
           post-processing did not reopen it. */
        preg_match('/<pre[^>]*>(.*?)<\/pre>/s', $html, $m);
        $this->assertNotEmpty($m, 'No code block to inspect.');

        $this->assertStringNotContainsString('<b>', $m[1],
            'Markup inside the code block was rendered rather than shown.');
    }
}

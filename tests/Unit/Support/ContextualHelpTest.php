<?php

namespace Tests\Unit\Support;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Help\ContextualHelp;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 06 `SUPPORT-002` — «مساعدة سياقية وإعلانات للويب وFlutter.» — THE CONTEXTUAL-HELP HALF.
 * =================================================================================================
 *
 * `SUPPORT-002` asks for contextual help on the web AND in the Flutter admin app.
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` recorded the whole requirement as **NOT IMPLEMENTED**,
 * and its gap (b) says the owner has not settled "what contextual is to mean". What is engineering
 * and not copy is the MECHANISM: a surface a merchant is looking at, and the help for that surface,
 * in their own language, on both clients.
 *
 * -------------------------------------------------------------------------------------------------
 * THERE IS NO SECOND STORE OF HELP TEXT, AND THAT IS THE LOAD-BEARING DECISION HERE.
 *
 * `App\Support\Docs\DocumentationLibrary` is already a bilingual platform documentation centre —
 * 16 articles per locale in `resources/docs/{en,ar}/`, with a published/draft state, a locale
 * fallback that is visible rather than silent, and a cached index. `ContextualHelp` adds a MAP from
 * surface to article id and resolves through that library. Writing help prose into a new table or a
 * new directory would be a second corpus to keep bilingual, a second thing to review, and the
 * `ARCH-002` parallel-engine shape.
 *
 * SO THE MAP IS THE ONLY NEW CONTENT, AND A MAP CAN ONLY FAIL IN ONE WAY: an id that names nothing.
 * `every_declared_article_resolves_in_both_languages` is therefore the most important case in this
 * file. It asserts BOTH locales separately rather than "resolves": `DocumentationLibrary::article()`
 * falls back to the other language, so an id present only in English resolves for an Arabic reader
 * and a one-locale assertion would photograph that as working.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SURFACE VOCABULARY IS CLIENT-NEUTRAL ON PURPOSE.
 *
 * The web resolves a surface from the Laravel route name; the Flutter app has no Laravel route
 * names and sends the surface key itself. If the keys WERE route names the app's contract would be
 * coupled to this repository's routing table, and a route rename — which happens — would silently
 * empty the app's help. `surface_keys_are_not_laravel_route_names` pins that.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ROUTE MATCH IS ON THE WHOLE FIRST SEGMENT, NEVER A STRING PREFIX, AND A TEST DRIVES THE CASE
 * THAT MADE THE DIFFERENCE. `plan-coupon.index` is the PLATFORM OPERATOR's screen and
 * `str_starts_with($name, 'plan')` is true of it. A prefix match would hang the merchant's
 * plan-limits help off an operator surface. `plan-coupon.index` is asserted to resolve to nothing.
 */
class ContextualHelpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DocumentationLibrary::forget();
    }

    /**
     * THE TYPO GUARD, AND IT IS ASSERTED PER LOCALE RATHER THAN ONCE.
     *
     * See the class docblock: a single `article()` call cannot tell "present in Arabic" from
     * "absent in Arabic and fell back to English", because that is precisely what the library is
     * designed to do for a reader.
     */
    #[Test]
    public function every_declared_article_resolves_in_both_languages(): void
    {
        $published = DocumentationLibrary::published();

        $missing = [];

        foreach (ContextualHelp::SURFACES as $surface => $ids) {
            foreach ($ids as $id) {
                foreach (DocumentationLibrary::LOCALES as $locale) {
                    if (! isset($published[$locale]['articles'][$id])) {
                        $missing[] = $locale . ':' . $id . ' (surface ' . $surface . ')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "ContextualHelp::SURFACES names documentation articles that do not exist, PER LOCALE.\n"
            . "An article id is a `category/slug` key of DocumentationLibrary::published(), and the\n"
            . "slugs have their numeric file prefixes stripped -- `products/add-a-product`, not\n"
            . "`03-products/01-add-a-product`. A missing Arabic half is the failure that matters:\n"
            . "the library falls back to English for the reader, so the help still appears and only\n"
            . "this assertion says the Arabic article was never written."
        );
    }

    #[Test]
    public function every_surface_declares_at_least_one_article(): void
    {
        foreach (ContextualHelp::SURFACES as $surface => $ids) {
            $this->assertNotSame(
                [],
                $ids,
                'The surface `' . $surface . '` is declared with no articles, so it is a help '
                . 'affordance that opens on nothing. Remove the surface or give it an article.'
            );
        }
    }

    /**
     * THE MAP IS CHECKED AGAINST THE REAL ROUTER, so a route rename reddens here rather than
     * silently emptying the help on that screen.
     */
    #[Test]
    public function every_mapped_route_segment_names_at_least_one_registered_route(): void
    {
        $segments = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name === '') {
                continue;
            }

            $segments[explode('.', $name)[0]] = true;
        }

        $unknown = [];

        foreach (array_keys(ContextualHelp::routeSegments()) as $segment) {
            if (! isset($segments[$segment])) {
                $unknown[] = $segment;
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "ContextualHelp maps route-name segments that no registered route carries any more.\n"
            . 'A renamed route does not break anything visibly -- the help just stops appearing on '
            . "that screen -- so this is the only thing that will tell you."
        );
    }

    #[Test]
    public function a_mapped_route_resolves_to_its_surface(): void
    {
        $this->assertSame('products', ContextualHelp::surfaceForRoute('product.index'));
        $this->assertSame('products', ContextualHelp::surfaceForRoute('product.create'));
        $this->assertSame('orders', ContextualHelp::surfaceForRoute('order.index'));
        $this->assertSame('storefront', ContextualHelp::surfaceForRoute('theme.customize'));
        $this->assertSame('overview', ContextualHelp::surfaceForRoute('dashboard'));
    }

    /**
     * THE OPERATOR SCREEN THAT A PREFIX MATCH WOULD HAVE CLAIMED. See the class docblock.
     */
    #[Test]
    public function a_route_whose_first_segment_merely_starts_with_a_mapped_one_resolves_to_nothing(): void
    {
        $this->assertNull(
            ContextualHelp::surfaceForRoute('plan-coupon.index'),
            '`plan-coupon.index` is the platform operator\'s plan-coupon screen. '
            . '`str_starts_with($name, "plan")` is TRUE of it, so a prefix match would hang the '
            . 'merchant\'s plan help off an operator surface. The match is on the whole first '
            . 'segment.'
        );

        $this->assertNull(ContextualHelp::surfaceForRoute('product-attribute-option.create'));
        $this->assertNull(ContextualHelp::surfaceForRoute('order-note.store'));
    }

    #[Test]
    public function an_unknown_or_absent_route_resolves_to_nothing(): void
    {
        $this->assertNull(ContextualHelp::surfaceForRoute(null));
        $this->assertNull(ContextualHelp::surfaceForRoute(''));
        $this->assertNull(ContextualHelp::surfaceForRoute('nothing.like.this'));
        $this->assertSame([], ContextualHelp::articlesForRoute(null, 'en'));
    }

    /**
     * A SURFACE KEY ARRIVES FROM THE FLUTTER APP AS REQUEST INPUT, so the hostile shapes are
     * driven. Nothing is ever concatenated into a path: the key is looked up in `SURFACES` and an
     * absent key answers `[]`.
     */
    #[Test]
    public function a_hostile_surface_key_resolves_to_nothing_and_reads_no_file(): void
    {
        foreach (['../../etc/passwd', 'products/../orders', '', '0', 'PRODUCTS', 'products ', '..'] as $hostile) {
            $this->assertSame(
                [],
                ContextualHelp::articlesForSurface($hostile, 'en'),
                'The surface key ' . var_export($hostile, true) . ' resolved to help. A surface key '
                . 'is caller-supplied on the Flutter path and must only ever be a key lookup in '
                . 'ContextualHelp::SURFACES.'
            );
        }
    }

    #[Test]
    public function articles_come_back_in_the_readers_language(): void
    {
        $english = ContextualHelp::articlesForSurface('products', 'en');
        $arabic  = ContextualHelp::articlesForSurface('products', 'ar');

        $this->assertNotSame([], $english);
        $this->assertCount(count($english), $arabic);

        $this->assertSame('en', $english[0]['locale']);
        $this->assertSame('ar', $arabic[0]['locale']);

        $this->assertNotSame(
            $english[0]['title'],
            $arabic[0]['title'],
            'The Arabic and English titles of the same article are identical, which means one '
            . 'language is being served for both. The corpus ships both.'
        );

        foreach ([$english, $arabic] as $set) {
            foreach ($set as $article) {
                foreach (['id', 'title', 'summary', 'url', 'locale', 'is_fallback'] as $key) {
                    $this->assertArrayHasKey($key, $article);
                }

                $this->assertFalse($article['is_fallback']);
                $this->assertStringContainsString('/platform/docs/', $article['url']);
            }
        }
    }

    /**
     * BOTH DIRECTIONS OF THE FALLBACK FLAG, which is what stops it from being decoration.
     *
     * `is_fallback` is true when the reader's language does not have the article and the other
     * language was served. The corpus currently ships every article in both, so the TRUE direction
     * cannot be produced from the real corpus -- it is produced by asking for a locale the library
     * does not ship at all, which resolves to the first locale and reports the substitution.
     */
    #[Test]
    public function an_unshipped_locale_falls_back_and_says_so(): void
    {
        $french = ContextualHelp::articlesForSurface('products', 'fr');

        $this->assertNotSame([], $french, 'A reader whose locale the centre does not ship must '
            . 'still get help; an empty help panel is worse than help in another language.');

        foreach ($french as $article) {
            $this->assertSame(
                DocumentationLibrary::LOCALES[0],
                $article['locale'],
                'An unshipped locale must resolve to the centre\'s first locale.'
            );
            $this->assertTrue(
                $article['is_fallback'],
                'The reader is being served a language they did not ask for and the payload does '
                . 'not say so. The web panel and the app both print a notice from this flag; '
                . 'without it the substitution is invisible.'
            );
        }
    }

    /**
     * THE APP'S VOCABULARY MUST NOT BE THIS REPOSITORY'S ROUTING TABLE. See the class docblock.
     */
    #[Test]
    public function surface_keys_are_not_laravel_route_names(): void
    {
        $routeNames = [];

        foreach (app('router')->getRoutes() as $route) {
            if ($name = $route->getName()) {
                $routeNames[$name] = true;
            }
        }

        foreach (array_keys(ContextualHelp::SURFACES) as $surface) {
            $this->assertArrayNotHasKey(
                $surface,
                $routeNames,
                'The surface key `' . $surface . '` is also a Laravel route name. The Flutter app '
                . 'sends surface keys, so a key that is a route name couples the shipped app\'s '
                . 'contract to this repository\'s routing table.'
            );

            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*$/',
                $surface,
                'A surface key crosses the wire from the app. Keep it lowercase, hyphenated and '
                . 'without dots so it needs no encoding and cannot look like a path.'
            );
        }
    }

    /**
     * THE WHOLE VOCABULARY, for the app to cache in one call — and every entry resolved.
     */
    #[Test]
    public function the_vocabulary_lists_every_surface_with_its_resolved_articles(): void
    {
        $all = ContextualHelp::vocabulary('ar');

        $this->assertSame(
            array_keys(ContextualHelp::SURFACES),
            array_column($all, 'surface'),
            'vocabulary() must list every declared surface, in declaration order, so the app can '
            . 'cache the whole map in one call.'
        );

        foreach ($all as $entry) {
            $this->assertNotSame([], $entry['articles']);
            $this->assertSame('ar', $entry['articles'][0]['locale']);
        }
    }
}

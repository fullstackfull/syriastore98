<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-118 — THE FOUR LAYOUT COPIES AND THE ONE COMPOSER THAT FEEDS THEM MUST NOT DRIFT APART.
 * ===============================================================================================
 *
 * `front_end/layouts/app.blade.php` exists FOUR times — once per installed theme plus the
 * `resources/views` fallback — and the theme's own wins at runtime through `Theme::set()` →
 * `prependLocation()`. CLAUDE.md records the consequence: a layout-level storefront change made in
 * fewer than four places silently does nothing for the other themes.
 *
 * GAP-118's wiring deliberately did NOT edit any of the four. Each already calls
 *
 *     {!! metaKeywordSetting($metakeyword ?? null, $metadesc ?? null, $metaimage ?? null, $slug) !!}
 *
 * and `App\Providers\ViewServiceProvider`'s composer — registered for the view NAME
 * `front_end.layouts.app`, under which all four resolve — now shares those three keys off the store
 * the route names. That is a two-sided contract held together by four variable names, and NOTHING
 * ELSE CHECKS IT:
 *
 *   * `Tests\Feature\Storefront\StorefrontSeoMetaPerStoreTest` drives real HTTP requests, which is
 *     the claim that matters — but it can only reach the copies a store can actually be served.
 *     MEASURED there: `theme_id` of 'stylique', 'greentic' or 'techzonix' serves that theme's copy,
 *     `''` serves stylique's (via `config('theme.active')`), and an uninstalled theme name answers
 *     503 before the composer runs at all. So the `resources/views` FALLBACK COPY IS REACHED BY NO
 *     STORE, and no HTTP test can cover it.
 *   * Renaming the shared key on either side, or adding a fifth theme whose layout coalesces to a
 *     literal instead, would leave that copy printing `config('app.name')` again — the exact defect
 *     GAP-118 was opened for — with every HTTP test still green.
 *
 * This file is that guard, and it is the only cover the fallback copy has. Comments are stripped
 * first: a census that reads comments can be satisfied by prose about the thing it measures, which
 * has happened four times in this repository in three different syntaxes.
 */
class StorefrontSeoMetaCallSitesTest extends TestCase
{
    use StripsSourceComments;

    /** The fallback is last and is the one no HTTP test can reach. */
    private const LAYOUTS = [
        'stylique' => 'themes/stylique/views/front_end/layouts/app.blade.php',
        'greentic' => 'themes/greentic/views/front_end/layouts/app.blade.php',
        'techzonix' => 'themes/techzonix/views/front_end/layouts/app.blade.php',
        'fallback' => 'resources/views/front_end/layouts/app.blade.php',
    ];

    private const COMPOSER = 'app/Providers/ViewServiceProvider.php';

    /** The three keys the composer shares and the layouts read. `$slug` was always passed. */
    private const SHARED = ['metakeyword', 'metadesc', 'metaimage'];

    /**
     * THE CENSUS IS CLOSED IN BOTH DIRECTIONS. A fifth theme, or a theme that stops shipping its
     * own layout, fails here — which is the signal to come and decide, rather than to discover it
     * from a storefront serving the platform's name as its title.
     */
    public function test_the_four_copies_are_exactly_the_copies_that_exist(): void
    {
        $onDisk = glob(base_path('themes/*/views/front_end/layouts/app.blade.php')) ?: [];
        $onDisk = array_map(static fn (string $p): string => str_replace(base_path() . '/', '', $p), $onDisk);
        sort($onDisk);

        $declared = array_values(array_filter(
            self::LAYOUTS,
            static fn (string $path): bool => str_starts_with($path, 'themes/')
        ));
        sort($declared);

        $this->assertSame($declared, $onDisk,
            'The set of theme layout copies on disk is not the set this file declares. A new theme '
            . 'needs a row here (and its own storefront row in StorefrontSeoMetaPerStoreTest), '
            . 'because the SEO meta reaches a theme only through its own copy of this layout.');

        $this->assertFileExists(base_path(self::LAYOUTS['fallback']),
            'the resources/views fallback copy is gone');
        $this->assertCount(4, self::LAYOUTS);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function layouts(): array
    {
        $cases = [];
        foreach (self::LAYOUTS as $name => $path) {
            $cases[$name] = [$name, $path];
        }

        return $cases;
    }

    /**
     * Each copy must take all three values from the SHARED variables. A copy that coalesced to a
     * literal, or dropped an argument, would silently serve the platform's name again.
     */
    #[DataProvider('layouts')]
    public function test_every_copy_reads_the_three_shared_meta_variables(string $name, string $path): void
    {
        $source = $this->sourceWithoutComments($path);

        $this->assertStringContainsString('metaKeywordSetting(', $source,
            "{$name} no longer calls metaKeywordSetting(), so this copy emits no SEO meta at all.");

        foreach (self::SHARED as $variable) {
            $this->assertMatchesRegularExpression(
                '/metaKeywordSetting\([^)]*\$' . $variable . '\b/',
                $source,
                "{$name} does not pass \${$variable} to metaKeywordSetting(). The composer shares "
                . "'{$variable}' for this call site; a copy that stops reading it falls back to "
                . 'config(app.name) and that store\'s configured value reaches nothing.'
            );
        }

        // `$slug` too: it is what makes og:url and twitter:url per-store, and it is the argument
        // whose percent-encoded Arabic form once took the whole storefront down.
        $this->assertMatchesRegularExpression('/metaKeywordSetting\([^)]*\$slug\b/', $source,
            "{$name} does not pass \$slug, so og:url and twitter:url stop being per-store.");
    }

    /**
     * THE OTHER HALF OF THE CONTRACT. The composer must share exactly the keys the four copies
     * read, off the store the ROUTE names — not `getCurrentStore()`, which answers for a signed-in
     * merchant's own store on any storefront request.
     */
    public function test_the_composer_shares_those_keys_off_the_route_resolved_store(): void
    {
        $source = $this->sourceWithoutComments(self::COMPOSER);

        foreach (self::SHARED as $key) {
            $this->assertStringContainsString("'" . $key . "' => \$" . $key, $source,
                "ViewServiceProvider no longer shares '{$key}' with the storefront views, so all "
                . 'four layout copies coalesce it to null and the store\'s configured value is '
                . 'dropped again. That is GAP-118.');
        }

        $this->assertStringContainsString('Utility::GetValueByName($name, $store->id)', $source,
            'the composer must read each setting against the store it resolved from the route '
            . 'slug. Utility::GetValueByName() with no store id defaults to getCurrentStore(), '
            . 'which answers for a signed-in merchant\'s OWN store on every storefront request -- '
            . 'so the default would print one tenant\'s SEO metadata into another\'s <head>.');

        $this->assertStringNotContainsString('GetValueByName($name)', $source,
            'a GetValueByName() call with no store id has appeared in the storefront composer.');

        // And the store itself still comes from the route, which is the premise of all of it.
        $this->assertStringContainsString("request()->route('storeSlug')", $source,
            'the composer no longer resolves its store from the route slug.');
    }
}

<?php

namespace Tests\Unit\Ui;

use Qirolab\Theme\Theme;
use Tests\TestCase;

/**
 * A THEME MAY NOT DEFINE A VIEW NAME THAT AN ADMIN SCREEN EXTENDS.
 * =================================================================================================
 *
 * THE TRAP, MEASURED RATHER THAN ARGUED. `Theme::set()` PREPENDS the active theme's view directory,
 * so for the rest of that request the theme's file wins over `resources/views` for any name they
 * share. Measured in this file:
 *
 *     before any Theme::set()        layouts.app -> resources/views/layouts/app.blade.php
 *     after Theme::set('stylique')   layouts.app -> themes/stylique/views/layouts/app.blade.php
 *     finder paths                   themes/stylique/views | resources/views
 *
 * `resources/views/layouts/app.blade.php` is the WorkDo ADMIN layout -- 60,101 bytes, extended by
 * **122** views under `resources/views`. `themes/stylique/views/layouts/app.blade.php` is 1,160
 * bytes and its entire `<body>` is the literal text `// ... existing code ...`: no `@yield`, no
 * `$slot`, no content of any kind. So if an admin screen ever resolved `layouts.app` while stylique
 * were active it would answer HTTP 200 with no page in it -- the invisible failure CLAUDE.md
 * records for the `$slot`/`@yield` mismatch, except that here even the chrome is a placeholder.
 * `config('theme.active')` is `stylique`, so a store with an empty `theme_id` gets exactly that
 * theme.
 *
 * -------------------------------------------------------------------------------------------------
 * IT IS LATENT TODAY, AND THAT WAS ESTABLISHED BEFORE ANYTHING WAS WRITTEN. All three
 * `Theme::set()` call sites were read and driven:
 *
 *   `ViewServiceProvider` -- inside `View::composer(['front_end.layouts.app', 'main_file',
 *   'front_end.pages.*', 'front_end.Auth.*'], ...)`, so it fires only while a STOREFRONT view is
 *   being composed. An admin view does not trigger it.
 *
 *   `ActiveTheme` -- on the storefront group in `routes/customer.php` only.
 *
 *   `ThemeDraftPreview` -- the one call site on an ADMIN-group route
 *   (`theme-draft/{storeSlug}/{version}/preview`). Its `abort(404)` and `abort(403)` both run
 *   BEFORE its `Theme::set()`, so a refused request never sets a theme; the success path delegates
 *   to `HomeController::storeSlug()`, which renders the storefront; and `ThemeVersionController::
 *   refuse()` returns JSON or `back()`, never a view. There is also no `resources/views/errors/`
 *   directory, so the abort pages cannot extend `layouts.app` either.
 *
 * So no request was found in which an admin view renders after a theme is set. THIS FILE IS
 * THEREFORE A PIN, NOT A FIX -- it exists so the day that changes, something says so.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE RATCHET IS ON THE COLLISION AND NOT ON ANY ONE SCREEN. "Every admin surface that can be
 * rendered while a theme is active" is not enumerable by inspection -- it depends on middleware
 * order, on which view composer fires, and on `FileViewFinder`'s memoisation, which
 * `ThemeDraftPreview`'s own docblock records as not cleared by `prependLocation()`. The COLLISION
 * is enumerable, it is the necessary condition for any instance of the trap, and it is small: of
 * 20 theme-defined names, six also exist under `resources/views`, and exactly ONE of those six is a
 * name an admin view actually extends.
 *
 * The other five -- `components.blog-card`, `components.header-button`, `components.product-card`,
 * `components.product-card-button`, `components.search-popup` -- are storefront components whose
 * `resources/views` copies are the FALLBACK a theme is meant to override. That is the mechanism
 * working as designed, which is why the ratchet is keyed on "a name an ADMIN view extends" and not
 * on collisions in general.
 *
 * THE FILE IS PROVABLY DEAD, WHICH IS REPORTED RATHER THAN ACTED ON. Nothing in any theme extends
 * or includes `layouts.app` (stylique's only `@extends` is `front_end.layouts.app`, a different
 * name), and no test, config, build step or seeder references the path. Deleting
 * `themes/stylique/views/layouts/app.blade.php` would remove the trap at its root and break
 * nothing measurable -- but the brief for a latent trap was a pin rather than a change, so the
 * deletion is a recommendation with its evidence, not a diff.
 */
class ThemeViewsDoNotShadowAdminViewsTest extends TestCase
{
    /**
     * The one collision that matters today, with the reason it is tolerated: the file is dead and
     * the trap is latent. An addition here means a theme can replace an admin screen.
     *
     * DECLARED AS `name @ theme`, NOT AS `name`, AND THAT IS A CAUGHT HOLE RATHER THAN A STYLE.
     * The first version of this ratchet declared bare names, and its own mutation test exposed it:
     * adding `themes/greentic/views/layouts/app.blade.php` -- a SECOND theme shipping the same
     * colliding name, which widens the trap from stylique stores to greentic ones -- left the
     * intersection of NAMES unchanged, so the test stayed GREEN. Keyed on the pair, that mutation
     * fails as it should. A ratchet over a set is only as sharp as the identity of its members.
     */
    private const DECLARED_SHADOWS = ['layouts.app @ stylique'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Every view name any theme defines, as the finder would address it. */
    private static function themeDefinedNames(): array
    {
        $names = [];

        foreach (glob(self::root() . '/themes/*/views', GLOB_ONLYDIR) ?: [] as $base) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $rel = substr($file->getPathname(), strlen($base) + 1);
                $names[str_replace('/', '.', substr($rel, 0, -strlen('.blade.php')))][] = $base;
            }
        }

        return $names;
    }

    /**
     * Every view name an ADMIN view depends on.
     *
     * `front_end.*` is excluded because that IS the storefront: a theme overriding those names is
     * the feature, not the trap. BLADE COMMENTS ARE STRIPPED FIRST -- this very repository records
     * eight occasions on which a census was satisfied, or broken, by prose about the thing it
     * measures, and the docblock above names `layouts.app` repeatedly.
     */
    private static function namesAdminViewsDependOn(): array
    {
        $names = [];
        $base = self::root() . '/resources/views';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $rel = substr($file->getPathname(), strlen($base) + 1);
            if (str_starts_with($rel, 'front_end/')) {
                continue;
            }

            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

            if (preg_match_all('/@(?:extends|include|includeIf|includeWhen|includeFirst)\(\s*\'([^\']+)\'/', $source, $m)) {
                foreach ($m[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    public function test_no_theme_defines_a_view_name_an_admin_screen_extends(): void
    {
        $themeNames = self::themeDefinedNames();
        $adminDeps = self::namesAdminViewsDependOn();

        /* PREMISES: both censuses must have found something, or the intersection is empty for the
           wrong reason and this test passes while measuring nothing. */
        $this->assertGreaterThan(10, count($themeNames),
            'premise: no theme view names were found, so the intersection below is vacuous');
        $this->assertContains('layouts.app', $adminDeps,
            'premise: no admin view appears to extend layouts.app, so this census is not reading '
            . 'the admin tree');

        $shadows = [];

        foreach ($themeNames as $name => $bases) {
            if (! in_array($name, $adminDeps, true)) {
                continue;
            }

            foreach ($bases as $base) {
                /* `themes/<theme>/views` -> `<theme>`, so the pair names the file that would win. */
                $theme = basename(dirname($base));
                $shadows[] = $name . ' @ ' . $theme;
            }
        }

        $shadows = array_values(array_unique($shadows));
        sort($shadows);

        $expected = self::DECLARED_SHADOWS;
        sort($expected);

        $this->assertSame($expected, $shadows,
            "The set of view names a THEME defines and an ADMIN screen extends has changed.\n"
            . 'Found: ' . implode(', ', $shadows) . "\nDeclared: " . implode(', ', $expected) . "\n"
            . "`Theme::set()` PREPENDS the theme's view directory, so for any shared name the "
            . "theme's file WINS once that theme is active -- and `config('theme.active')` is "
            . "stylique, so a store with an empty theme_id gets it. A NEW entry here means a theme "
            . 'can now replace an admin screen -- including a SECOND theme shipping a name one '
            . 'theme already shadows, which widens the trap to that theme\'s stores; a REMOVED '
            . 'entry means the trap is gone and the declaration should go with it.');
    }

    /**
     * THE MECHANISM, so the reasoning above cannot rot into folklore. Keyed on the ordering rather
     * than on any one name, because the ordering is what makes every collision dangerous.
     */
    public function test_an_active_theme_takes_precedence_over_the_admin_view_root(): void
    {
        $finder = view()->getFinder();

        /* `find()` memoises by name and `prependLocation()` does not clear it -- the trap
           `ThemeDraftPreview`'s docblock records. Flushing makes each lookup a real lookup. */
        $finder->flush();
        $adminPath = $finder->find('layouts.app');

        $this->assertStringContainsString('/resources/views/layouts/app.blade.php', $adminPath,
            'premise: with no theme active, layouts.app does not resolve to the admin layout');

        $finder->flush();
        Theme::set('stylique');

        $paths = $finder->getPaths();
        $themeIndex = null;
        $appIndex = null;

        foreach (array_values($paths) as $i => $path) {
            if ($themeIndex === null && str_contains($path, '/themes/stylique/views')) { $themeIndex = $i; }
            if ($appIndex === null && str_ends_with(rtrim($path, '/'), '/resources/views')) { $appIndex = $i; }
        }

        $this->assertNotNull($themeIndex, 'Theme::set() did not add the theme view directory at all.');
        $this->assertNotNull($appIndex, 'the application view root is no longer in the finder paths.');
        $this->assertLessThan($appIndex, $themeIndex,
            "The theme's view directory no longer PRECEDES resources/views. If `Theme::set()` now "
            . 'APPENDS instead of prepending, the whole shadowing trap is gone and the declaration '
            . 'in this file should be removed -- but check the storefront still overrides its '
            . 'fallback views, because that mechanism depends on the same ordering.');
    }
}

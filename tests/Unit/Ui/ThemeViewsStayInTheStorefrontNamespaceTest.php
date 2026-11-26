<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GAP-265. A THEME MAY SHADOW STOREFRONT VIEWS. IT MAY NOT SHADOW THE MERCHANT PANEL'S.
 * =================================================================================================
 *
 * `ActiveTheme::handle()` calls `Theme::set($store->theme_id)`, which `prependLocation()`s
 * `themes/<theme>/views` onto the view finder's path list, and `FileViewFinder` MEMOISES
 * name -> path. So for the rest of that PHP process, EVERY view name the theme defines resolves to
 * the theme's file — not only the storefront ones.
 *
 * That is the theme mechanism working as designed for storefront names, and it is a trap for any
 * other name. `themes/stylique/views/layouts/app.blade.php` defined `layouts.app`, which is the
 * MERCHANT PANEL's layout name, and printed `$store->name`. Measured by execution: after one
 * storefront request in the same process, `GET /order` as a provisioned merchant answered
 * **HTTP 500**, `Undefined variable $store (View: themes/stylique/views/layouts/app.blade.php)`;
 * the same request in a clean process answered 200. `config('theme.active')` is `stylique`, so it
 * is the fallback every store with an empty `theme_id` gets.
 *
 * WHY THIS IS A PROPERTY TEST AND NOT A CHECK FOR THAT ONE FILE. Deleting or renaming one file
 * closes one instance. The hazard is structural: a theme's whole `views/` tree is prepended, so
 * the next `layouts/`, `partials/` or `auth/` file somebody adds under `themes/<t>/views` is the
 * same defect with a different name and would be just as invisible — correct chrome, HTTP 200 or
 * 500 depending on the file, and nothing in any test pointing at the theme. `RouteShadowingTest`
 * was written for exactly this reason after a URI-specific assertion passed while instance two
 * sat live, and this is the view-layer analogue.
 *
 * WHAT IS ALLOWED. A theme may define any name under the two namespaces the storefront renders
 * through — `front_end.*` and `components.*`. MEASURED: all three themes' `main_file.blade.php`
 * `@extends('front_end.layouts.app')`, and the five shadowed `components/*` files are the
 * storefront's product and blog cards, used only from `front_end/*` views and theme `main_file`s;
 * no merchant-panel view references any of them. Those collisions are the feature.
 *
 * WHAT IS NOT. Anything else. There is no allowlist entry for a single file, deliberately: an
 * exception for one path is how the next one gets added.
 *
 * NOTE WHAT THIS DOES NOT COVER, so it is not read as more than it is. It compares NAMES against
 * `resources/views`, which is the host half. A theme view that shadows a name defined only inside
 * a `packages/workdo/*` module's own view namespace is not visible here, and a theme that defines
 * a brand-new panel-shaped name the host does not have yet is not either — that one becomes a
 * collision only when the host adds the file, at which point this test starts failing, which is
 * the point.
 */
class ThemeViewsStayInTheStorefrontNamespaceTest extends TestCase
{
    /** The namespaces the storefront renders through, and which a theme therefore MAY shadow. */
    private const STOREFRONT_PREFIXES = ['front_end/', 'components/'];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, list<string>> theme => relative view paths */
    private function themeViews(): array
    {
        $out = [];

        foreach (glob($this->root() . '/themes/*', GLOB_ONLYDIR) ?: [] as $themeDir) {
            $viewRoot = $themeDir . '/views';

            if (! is_dir($viewRoot)) {
                continue;
            }

            $found = [];

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($viewRoot, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $found[] = ltrim(str_replace($viewRoot, '', $file->getPathname()), '/');
                }
            }

            sort($found);
            $out[basename($themeDir)] = $found;
        }

        return $out;
    }

    /**
     * PREMISE. There are themes, and they have views. Without this the assertion below passes
     * against an empty set — the failure mode that let the first GAP-268 ratchet examine 0 of
     * 1,500 routes and go green.
     */
    #[Test]
    public function test_premise_the_theme_view_trees_were_actually_read(): void
    {
        $views = $this->themeViews();

        $this->assertGreaterThanOrEqual(
            3,
            count($views),
            'PREMISE: fewer than three theme view trees were found, so the assertion below is '
            . 'measuring almost nothing.'
        );

        $total = array_sum(array_map('count', $views));

        $this->assertGreaterThan(
            30,
            $total,
            'PREMISE: only ' . $total . ' theme views were read across ' . count($views)
            . ' themes. The walk is not finding the files.'
        );
    }

    /**
     * THE DEFECT. No theme view may shadow a host view outside the storefront namespaces.
     */
    #[Test]
    public function test_no_theme_shadows_a_host_view_outside_the_storefront_namespaces(): void
    {
        $violations = [];

        foreach ($this->themeViews() as $theme => $views) {
            foreach ($views as $relative) {
                $isStorefront = false;

                foreach (self::STOREFRONT_PREFIXES as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        $isStorefront = true;

                        break;
                    }
                }

                if ($isStorefront) {
                    continue;
                }

                if (is_file($this->root() . '/resources/views/' . $relative)) {
                    $violations[] = sprintf(
                        'themes/%s/views/%s shadows resources/views/%s  (view name: %s)',
                        $theme,
                        $relative,
                        $relative,
                        str_replace('/', '.', preg_replace('/\.blade\.php$/', '', $relative))
                    );
                }
            }
        }

        sort($violations);

        $this->assertSame([], $violations, sprintf(
            "%d theme view(s) shadow a host view OUTSIDE the storefront namespaces (%s):\n  %s\n\n"
            . "A theme's whole `views/` tree is prepended to the view finder by `Theme::set()`, and "
            . "`FileViewFinder` memoises name -> path, so for the rest of the PHP process this name "
            . "resolves to the THEME's file for every surface — the merchant panel included. That "
            . "is GAP-265: `themes/stylique/views/layouts/app.blade.php` defined `layouts.app`, the "
            . "panel's own layout name, and `GET /order` as a merchant answered HTTP 500 after one "
            . "storefront request in the same process.\n\n"
            . 'Move the file under `front_end/` or `components/` if it is a storefront view, or out '
            . 'of `themes/<t>/views` entirely if it is not. Do NOT add an allowlist entry for it.',
            count($violations),
            implode(', ', self::STOREFRONT_PREFIXES),
            implode("\n  ", $violations)
        ));
    }
}

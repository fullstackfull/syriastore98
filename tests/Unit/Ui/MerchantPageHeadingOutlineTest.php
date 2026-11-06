<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * PACKAGE A — THERE WAS NO `<h1>` ON ANY MERCHANT PAGE.
 * ================================================================================================
 *
 * Measured on the running application at 1440x900 before this package, reading the VISIBLE
 * heading elements of `/dashboard` in document order:
 *
 *     [3,3,3,3,3,2,2,2,2,2,2,4,4,4,5,4,4,6,6,6,6,6,6]
 *      \_______/  \_________/  \_____________________/
 *       sidebar    the page     card titles and, inside
 *       zones      title        <td>s, product names
 *
 * Two skips -- `h2 -> h4` and `h4 -> h6` -- and `grep -rn "<h1"` over the whole admin layout
 * chain returned NOTHING. So each of the 152 views that set `@section('page-title')` announced
 * itself as a subsection of a document with no subject.
 *
 * AFTER, same instrument, same page: `[3,3,3,3,3,1,2,2,2,2,2,2,2,2,3,2,2]`, skips NONE, and at
 * 390px (where the sidebar is off-canvas) `[1,2,2,2,2,2,2,2,2,3,2,2]`, skips NONE.
 *
 * NOTHING MOVED VISUALLY, and that was measured rather than assumed: the card titles read
 * 20px/600 before as `<h4>` and 20px/600 after as `<h2 class="h4">`; the store name 16px/600
 * before as `<h5>` and 16px/600 after as `<h3 class="h5">`. `class="h3"` on the layout's h1 does
 * the same job for the page title.
 *
 * THE SIDEBAR'S ZONE CAPTIONS STAY AT h3, DELIBERATELY. `partision/menu-zones.blade.php` carries
 * the reason in its own docblock -- "a real <h3>, not a styled <span>, so a screen-reader user can
 * jump between zones instead of walking every link" -- and the owner's directive forbids removing
 * a way to reach something. A left sidebar precedes main content in the DOM, so NO level
 * assignment makes its headings follow the page's h1; strict outline purity and a sidebar layout
 * are incompatible, and WCAG requires structure to be programmatically determinable (1.3.1), not
 * sequential levels. The rejected alternative was converting them to `aria-labelledby` on each
 * `<ul>`, which would have cost heading-jump navigation to buy an outline nobody audits.
 *
 * WHAT THIS TEST DOES NOT TRY TO ASSERT, and why. A static scan for "a heading inside a <td>" was
 * written and thrown away: over raw bytes it reported this package's own explanatory comment as a
 * violation, and it matched card titles three files away because a non-greedy window ran past an
 * unclosed `<td>`. Three genuine cases survive in `theme_analytic.blade.php` (lines 39, 74, 81 --
 * `$url->total` and "No Data found" as `<h6>`); they are a reports surface and are recorded for
 * package D rather than smuggled in here behind a detector that cries wolf. The rendered outline
 * is checked by `docs/development/phase-2.5/tools/audit-shell-and-dashboard.cjs`, in a browser,
 * which is the only place it can be checked honestly.
 */
class MerchantPageHeadingOutlineTest extends TestCase
{
    private const LAYOUT = 'resources/views/layouts/app.blade.php';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(self::root() . '/' . $rel);
    }

    /** Blade comments removed, because this package's own notes quote the markup they replaced. */
    private static function strip(string $src): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;
    }

    public function test_the_shared_layout_emits_the_page_title_as_an_h1(): void
    {
        $code = self::strip(self::read(self::LAYOUT));

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*class="[^"]*\bss-page-title\b[^"]*"[^>]*>@yield\(\'page-title\'\)<\/h1>/',
            $code,
            'The merchant page title must be the document h1. It was an h2, and no h1 existed anywhere.'
        );
        $this->assertStringNotContainsString('<h2 class="h3 mb-0 ss-scope ss-page-title">', $code,
            'the old h2 page title is back');
    }

    /** `class="h3"` is what keeps the change an outline change rather than a visual one. */
    public function test_the_h1_keeps_the_rendered_type_scale_it_had_as_an_h2(): void
    {
        $this->assertMatchesRegularExpression(
            '/<h1 class="h3 [^"]*ss-page-title"/',
            self::strip(self::read(self::LAYOUT)),
            'without class="h3" the title jumps to the host h1 size and every merchant page moves'
        );
    }

    /**
     * The block is conditional, and that is not tidiness: unconditional, a view that sets no
     * section rendered an EMPTY heading, and as an h1 that is an empty h1.
     */
    public function test_the_page_title_block_is_conditional(): void
    {
        $code = self::strip(self::read(self::LAYOUT));
        $open = strpos($code, "@hasSection('page-title')");

        $this->assertNotFalse($open, 'the page-title heading must not render for a view that sets none');
        $this->assertLessThan(
            strpos($code, "ss-page-title"),
            $open,
            'the @hasSection guard must come before the heading it guards'
        );
    }

    /**
     * ONE PAGE-HEADER MECHANISM. `notification/index.blade.php` rendered `<x-ss.page-header>` AND
     * set `@section('page-title')`, so a merchant saw the title twice; measured as two h1 elements
     * once the layout's became an h1. The section could not just be deleted -- the layout feeds
     * `@yield('page-title')` into `<title>` at line 122 -- so the view moved onto the layout's
     * header, which already renders the same `.ss-page-header__subtitle` class and an action area.
     */
    public function test_no_view_sets_the_page_title_and_also_renders_the_page_header_component(): void
    {
        $both = [];
        $dir = self::root() . '/resources/views';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            $rel = str_replace(self::root() . '/', '', $f->getPathname());
            if ($rel === self::LAYOUT) {
                continue;
            }
            $code = self::strip((string) file_get_contents($f->getPathname()));
            if (preg_match("/@section\('page-title'/", $code) && str_contains($code, '<x-ss.page-header')) {
                $both[] = $rel;
            }
        }

        $this->assertSame([], $both,
            "A view that does both renders the page title twice.\n" . implode("\n", $both));
    }

    /** ANTI-VACUITY: the scan has to be finding views at all. */
    public function test_the_page_title_section_is_really_used_across_the_admin(): void
    {
        $count = 0;
        $dir = self::root() . '/resources/views';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')
                && preg_match("/@section\('page-title'/", (string) file_get_contents($f->getPathname()))) {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(100, $count,
            "measured 152 views on 2026-11-06; a collapse to near zero means the scan broke, not that the admin did");
    }
}

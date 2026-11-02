<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-14 / WS A3 — the breadcrumb contract, asserted over every merchant screen at once.
 * =================================================================================================
 *
 * The acceptance is "one breadcrumb component used by every merchant screen; current page not a
 * link; RTL separator correct; AA on both themes", and three of those four are properties of the
 * whole set of screens rather than of any one of them. So this test scans the sections themselves:
 * a per-screen test would pass while the next screen added broke the rule.
 *
 * WHAT WAS MEASURED BEFORE ANY OF IT WAS WRITTEN, because the rule had to fit the real markup:
 * 107 views define `@section('breadcrumb')`; 105 of them contain `<li>` items (the two that do not
 * are `dashboard.blade.php` and `superadmin/dashboard.blade.php`, and the layout suppresses the
 * breadcrumb on the `dashboard` route anyway); **0** sections end in a link, so "current page not
 * a link" holds structurally; **5** carried MORE THAN ONE `aria-current="page"` — `product/
 * {create,edit,show}` and `plans/{create,edit}`, where it sat on both the parent link and the leaf,
 * so a screen reader announced two current pages and one of them linked somewhere else; and **68**
 * carried none at all, so most screens never identified the current page to assistive tech.
 *
 * ONE UNIFORM RULE COVERS BOTH DEFECTS: `aria-current="page"` on the last item only. That is what
 * these cases assert, and it is why no per-file exception list is needed.
 *
 * WHY THE HOST THEME'S BREADCRUMB HAD TO GO (F151). It is Bootstrap's: `.breadcrumb` sets
 * `--bs-breadcrumb-item-active-color: #6c757d` and `--bs-breadcrumb-divider-color: #6c757d`, and
 * those declarations are BYTE-IDENTICAL in `public/assets/css/style.css:5343` and
 * `public/assets/css/style-dark.css:5350`. One colour in both stylesheets cannot be legible in both
 * themes. Computed on the real dark page token `--ss-bg: #1b2523`, `#6c757d` is **3.35:1** — below
 * AA — against **7.22:1** for `--ss-fg-muted` and **13.30:1** for `--ss-fg`.
 *
 * AND THE CONVERSION IS NOT OPTIONAL COSMETICS: Bootstrap's `.breadcrumb-item::before` injects its
 * own `/` glyph, so leaving the children on the legacy class while the list carries `.ss-breadcrumb`
 * renders TWO separators.
 */
class BreadcrumbContractTest extends TestCase
{
    /** @return array<string, string> path => the section body */
    private function sections(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match("/@section\('breadcrumb'\)(.*?)@endsection/s", $src, $m) !== 1) {
                continue;
            }
            // Only sections that actually render items are in scope.
            if (preg_match('/<li\b/', $m[1]) !== 1 && ! str_contains($m[1], '<li')) {
                continue;
            }
            $found[str_replace($root . '/', '', $file->getPathname())] = $m[1];
        }

        return $found;
    }

    /** @return string[] */
    private function items(string $body): array
    {
        preg_match_all('/<li\b[^>]*>.*?<\/li>/s', $body, $m);

        return $m[0];
    }

    public function test_the_premise_that_there_are_many_sections_to_check(): void
    {
        // Without this, every scan below passes vacuously if the regex stops matching.
        $this->assertGreaterThan(90, count($this->sections()),
            'the scan found almost no breadcrumb sections, so every assertion in this file is vacuous');
    }

    public function test_no_merchant_screen_still_uses_the_host_themes_breadcrumb_item_class(): void
    {
        $offenders = [];

        foreach ($this->sections() as $path => $body) {
            foreach ($this->items($body) as $li) {
                if (preg_match('/class="[^"]*\bbreadcrumb-item\b/', $li) === 1) {
                    $offenders[] = $path;
                    break;
                }
            }
        }

        $this->assertSame([], $offenders,
            "these screens still carry Bootstrap's `breadcrumb-item`, which renders a SECOND `/` "
            . "separator beside the design system's pseudo-element and takes its colour from a "
            . "declaration that is identical in the light and dark stylesheets (F151):\n  "
            . implode("\n  ", $offenders));
    }

    public function test_every_breadcrumb_marks_exactly_one_item_as_the_current_page(): void
    {
        $wrong = [];

        foreach ($this->sections() as $path => $body) {
            $marked = 0;
            foreach ($this->items($body) as $li) {
                if (str_contains($li, 'aria-current="page"')) {
                    $marked++;
                }
            }
            if ($marked !== 1) {
                $wrong[$path] = $marked;
            }
        }

        $this->assertSame([], $wrong,
            'a breadcrumb must identify exactly one current page. More than one announces two; '
            . "none announces none. Offenders (path => count):\n  "
            . implode("\n  ", array_map(
                fn ($p, $c) => "$p => $c", array_keys($wrong), array_values($wrong))));
    }

    public function test_the_item_marked_as_the_current_page_is_never_a_link(): void
    {
        $offenders = [];

        foreach ($this->sections() as $path => $body) {
            foreach ($this->items($body) as $li) {
                if (str_contains($li, 'aria-current="page"') && str_contains($li, '<a ')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], $offenders,
            "these screens announce an item as the current page while it links somewhere else:\n  "
            . implode("\n  ", $offenders));
    }

    public function test_every_breadcrumb_link_carries_the_design_system_link_class(): void
    {
        $offenders = [];

        foreach ($this->sections() as $path => $body) {
            foreach ($this->items($body) as $li) {
                if (str_contains($li, '<a ') && ! str_contains($li, 'ss-breadcrumb__link')) {
                    $offenders[] = $path;
                }
            }
        }

        $this->assertSame([], $offenders,
            "these screens have a breadcrumb link with no `ss-breadcrumb__link`, so it keeps the "
            . "host theme's brand colour instead of a token redefined per theme:\n  "
            . implode("\n  ", $offenders));
    }

    public function test_no_screen_supplies_its_own_home_item(): void
    {
        // The Home item lives in the component so it is identical everywhere and cannot drift.
        $offenders = [];

        foreach ($this->sections() as $path => $body) {
            if (preg_match("/route\('dashboard'\)/", $body) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders,
            "these screens link to the dashboard from inside their own breadcrumb section, which "
            . "duplicates the component's Home item:\n  " . implode("\n  ", $offenders));
    }

    // =============================================================================================
    //  THE SHARED LAYOUT
    // =============================================================================================

    private function layout(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/layouts/app.blade.php'
        );
    }

    /**
     * The layout with its Blade comments removed.
     *
     * A bare substring scan is only as good as the distinctness of what it matches, and the first
     * version of `test_the_layout_no_longer_hardcodes_the_host_themes_breadcrumb_list` failed
     * against the correct layout because the comment explaining the change QUOTES the markup it
     * replaced. Strip the commentary and assert against the markup, which is what the rule is
     * about -- and which stays true however the next comment is worded.
     */
    private function layoutMarkup(): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->layout());
    }

    public function test_the_layout_renders_the_one_breadcrumb_component(): void
    {
        $this->assertStringContainsString('<x-ss.breadcrumb>', $this->layout(),
            'the shared layout does not use the breadcrumb component, so each screen is back to '
            . 'inheriting whatever the host theme does');
    }

    public function test_the_layout_no_longer_hardcodes_the_host_themes_breadcrumb_list(): void
    {
        $this->assertStringNotContainsString('<ul class="breadcrumb', $this->layoutMarkup(),
            "the layout still renders Bootstrap's breadcrumb list");
        $this->assertStringNotContainsString('class="breadcrumb-item', $this->layoutMarkup(),
            'the layout still emits a legacy breadcrumb item of its own');
    }

    /**
     * THE PREVIEW HARNESS MUST RENDER THE DESIGN SYSTEM'S BREADCRUMB, NOT THE HOST THEME'S.
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * THIS IS F251's SECOND FAILURE, AND NOTHING COULD HAVE CAUGHT IT
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * `docs/design-system/tools/render-preview.php` builds the page chrome for every fixture the
     * two real-browser suites measure. Its header emitted Bootstrap's `<ul class="breadcrumb">`
     * with a BARE `<a href="#dashboard">Home</a>` — no `ss-` class — while
     * `layouts/app.blade.php` has rendered `<x-ss.breadcrumb>` since P2-01-14, which puts
     * `ss-breadcrumb__link` on the Home crumb like every other crumb. The harness's own comment
     * even cited a line range of the layout that no longer held the markup.
     *
     * So the fixture was a hybrid that exists nowhere in the product, and
     * `docs/design-system/tools/browser-checks.cjs` reported the consequence as a DESIGN-SYSTEM
     * DEFECT: its focus-indicator probe (`a[href], button`) found 13 controls, 12 ringed at
     * 3.74:1 and one — that Home link — ringed at 1.00:1, printed as *"12 of 13"*. Measured: every
     * `ss-*` control was already correct. The accessibility gap was in the measuring instrument.
     *
     * **A BROWSER CHECK CANNOT CATCH THIS, WHICH IS WHY THE ASSERTION LIVES HERE.** The harness is
     * what the browser suite measures, so a stale harness is invisible to it by construction — it
     * faithfully reports whatever the harness renders. The agreement between the harness and the
     * component is a source-level fact, and this is the only place it can be pinned.
     *
     * Asserted in BOTH directions, because either alone is escapable: the harness must emit the
     * component's classes, and it must NOT emit the host theme's.
     */
    public function test_the_preview_harness_renders_the_design_system_breadcrumb(): void
    {
        /* `dirname(__DIR__, 3)` like every other reader in this file: this is a UNIT test, so no
           application container is booted and `base_path()` is unavailable. */
        $harness = (string) file_get_contents(
            dirname(__DIR__, 3) . '/docs/design-system/tools/render-preview.php'
        );

        $this->assertNotSame('', $harness, 'premise: the preview harness could not be read');

        /* The harness has to emit the component's own classes, Home crumb included. */
        foreach (['ss-breadcrumb', 'ss-breadcrumb__item', 'ss-breadcrumb__link'] as $class) {
            $this->assertStringContainsString($class, $harness,
                "the preview harness does not emit `{$class}`, so every fixture the real-browser "
                . 'suites measure carries chrome the product no longer renders — and the suites '
                . 'will report that difference as a design-system defect, which is exactly what '
                . 'F251 was');
        }

        /*
         * AND IT MUST NOT HAVE GONE BACK TO THE HOST THEME'S LIST — asserted over the harness with
         * its HTML COMMENTS STRIPPED, and the first draft of this very case is why.
         *
         * The harness's explanatory comment QUOTES the markup it replaced, so a raw
         * `assertStringNotContainsString('<ul class="breadcrumb', ...)` failed against the
         * documentation rather than the code. That is the "ratchet matching a comment that quotes
         * the defect it scans for" failure this repository records, met here one layer out: the
         * quote sits inside an `<!-- … -->` block inside a PHP heredoc, so PHP tokenisation does
         * not reach it and only stripping HTML comments does.
         */
        $code = (string) preg_replace('/<!--.*?-->/s', '', $harness);

        $this->assertNotSame('', $code, 'premise: stripping comments emptied the harness');
        $this->assertStringContainsString('ss-breadcrumb__link', $code,
            'premise: the design-system classes live only in a comment, so the assertions below '
            . 'are reading prose rather than markup');

        $this->assertStringNotContainsString('<ul class="breadcrumb', $code,
            "the preview harness renders Bootstrap's breadcrumb list again");
        $this->assertStringNotContainsString('class="breadcrumb-item', $code,
            'the preview harness emits a legacy breadcrumb item again');

        /*
         * THE HOME CRUMB SPECIFICALLY, because it is the one that was wrong and the one the
         * focus-indicator probe tripped over. A harness that classed the trail but not Home would
         * satisfy every assertion above.
         */
        $this->assertMatchesRegularExpression(
            '/<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link"[^>]*>Home<\/a><\/li>/',
            $code,
            "the preview harness's HOME crumb is not a design-system link. That single element is "
            . 'what made the browser suite read 12 of 13 controls as ringed.'
        );

        /* AND THE LANDMARK STRUCTURE, mirroring what the component is asserted to be above. */
        $this->assertStringContainsString('<nav aria-label="Breadcrumb">', $code,
            'the preview harness does not wrap the trail in a labelled navigation landmark, so the '
            . 'fixture and the component disagree about the ARIA pattern');
        $this->assertStringContainsString('<ol class="ss-scope ss-breadcrumb', $code,
            'the preview harness does not use an ORDERED list inside ss-scope, so either the '
            . 'sequence semantics or the opt-in stylesheet scope differs from the component');
    }

    public function test_the_component_is_a_labelled_navigation_landmark_around_an_ordered_list(): void
    {
        $component = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/components/ss/breadcrumb.blade.php'
        );

        $this->assertMatchesRegularExpression('/<nav\b[^>]*aria-label=/', $component,
            'a breadcrumb is a navigation landmark and needs a name to be distinguishable from the '
            . 'other navigation regions on the page');
        /* THE CLASS LIST, NOT A PREFIX. This asserted the literal `<ol class="ss-breadcrumb`
           and broke the moment WS A added `ss-scope` to the component roots — against markup
           that is still an ordered list carrying the breadcrumb class. The property is "an <ol>
           that is the breadcrumb"; where in its class attribute the name sits is not. */
        $this->assertMatchesRegularExpression('/<ol\b[^>]*\bclass="[^"]*\bss-breadcrumb\b/', $component,
            'a breadcrumb trail is a sequence, so it is an ordered list carrying .ss-breadcrumb');
    }

    public function test_the_separator_is_a_pseudo_element_and_is_mirrored_for_rtl(): void
    {
        // Not a literal glyph: a hardcoded "/" or ">" points one way and is read aloud.
        $css = (string) file_get_contents(
            dirname(__DIR__, 3) . '/public/css/store-syria-ds.css'
        );

        $this->assertStringContainsString('.ss-breadcrumb__item + .ss-breadcrumb__item::before', $css);
        $this->assertStringContainsString('[dir="rtl"] .ss-breadcrumb__item + .ss-breadcrumb__item::before', $css,
            'the separator is not mirrored, so it points the wrong way in Arabic');
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * NAV-004 — EVERY NAVIGATION ICON NAMES A GLYPH THE ADMIN'S OWN STYLESHEET DEFINES.
 * =================================================================================================
 *
 * THIS TEST EXISTS BECAUSE TWO OF THEM DID NOT, AND BOTH ARRIVED WITH THE OPERATOR'S PLATFORM
 * GROUP. Measured 2026-11-27 against the committed `int/checkpoint` tree:
 *
 *   `layout-dashboard`   the `Across the platform` group header — a TOP-LEVEL entry, so
 *                        `partision/menu-tree.blade.php` really renders
 *                        `<i class="ti ti-layout-dashboard">` for it, and the pinned webfont has
 *                        no such rule, so the group header was the one item in the operator's
 *                        sidebar with NO glyph beside its label.
 *   `chart-histogram`    the `Platform analytics` child. Latent rather than visible — a CHILD
 *                        entry renders no `<i>` at all — but the value was wrong just the same,
 *                        and `App\Services\MerchantPageDirectory` hands this same `icon` key to
 *                        anything that lists a page by icon.
 *
 * WHY NEITHER WAS CAUGHT BY ANYTHING. An icon name is not checkable by eye: Tabler's published
 * icon list contains BOTH of these names, so a developer who looks them up finds them and is
 * wrong anyway. What matters is not what Tabler publishes but what
 * `public/assets/fonts/tabler-icons.min.css` — the one icon stylesheet
 * `resources/views/layouts/app.blade.php` loads — actually contains. That file is a pinned build;
 * this test asks it.
 *
 * A MISSING GLYPH IS INVISIBLE TO EVERY OTHER KIND OF TEST HERE. The markup is well formed, the
 * class is present, the response is 200, the accessible name comes from the sibling `<span>`, and
 * a contrast walk steps over an element with no text node. Only the rendered pixels differ, and
 * only by the absence of something — which is the hardest thing to notice in a screenshot and the
 * easiest to miss in a review.
 *
 * ================================= HOW THE CENSUS IS KEYED =================================
 *
 * CLAUDE.md: *WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD.* So
 * this does not carry a list of known-bad icon names. It reads EVERY `'icon' => '<literal>'` the
 * two host listeners declare and requires each one to be present in the stylesheet: a name nobody
 * thought of is caught by default, and the next invented icon reddens this file rather than
 * shipping as a hole.
 *
 * COMMENTS ARE STRIPPED, and it is load-bearing here rather than precautionary: the fix for the
 * two names above is explained in a comment in `SuperAdminMenuListener` that NAMES BOTH OF THEM,
 * `'icon' => 'layout-dashboard'` included in prose form. Without the stripper this census would
 * read the explanation as a declaration and fail on a file it had just certified — CLAUDE.md §3
 * records that shape eight times, four of them in one day.
 *
 * SCOPE, STATED RATHER THAN IMPLIED. The two HOST listeners only. The 70 vendored
 * `packages/workdo/` modules declare their own icons and their strings are not ours to change; a
 * broken one there is a vendored defect and editing it is forbidden. Only single-quoted literals
 * are read — no listener in `app/Listeners` builds an icon name from a variable, and a runtime key
 * cannot be read statically by any census.
 */
final class NavigationIconGlyphsResolveTest extends TestCase
{
    use StripsSourceComments;

    /**
     * The HOST's two navigation listeners. `CompanyMenuListener` builds the merchant sidebar and
     * `SuperAdminMenuListener` the operator's; between them they are every entry this repository
     * declares.
     */
    private const LISTENERS = [
        'app/Listeners/SuperAdminMenuListener.php',
        'app/Listeners/CompanyMenuListener.php',
    ];

    /** The one icon stylesheet `layouts/app.blade.php` loads. */
    private const ICON_STYLESHEET = 'public/assets/fonts/tabler-icons.min.css';

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * Every menu entry the host listeners declare, as `['icon' => ?string, 'parent' => ?string]`.
     *
     * READ FROM THE `$menu->add([...])` BLOCKS, not from two independent greps for `'icon'` and
     * `'parent'`. Two separate greps would pair the Nth icon with the Nth parent and silently
     * misalign the moment one entry omits a key — and the misalignment would make this test pass
     * for the wrong reason, because it would be comparing real values against each other.
     *
     * @return list<array{file: string, name: string, icon: ?string, parent: ?string}>
     */
    private function entries(): array
    {
        $entries = [];

        foreach (self::LISTENERS as $relative) {
            $path = $this->repoPath($relative);

            $this->assertFileExists($path, $relative . ' is gone, so this census is vacuous.');

            $code = $this->sourceWithoutComments($path);

            /* Each `add([...])` call, non-greedy to the closing `]);` of that call. */
            preg_match_all('/->add\(\s*\[(.*?)\]\s*\)\s*;/s', $code, $blocks);

            foreach ($blocks[1] as $block) {
                $read = static function (string $key) use ($block): ?string {
                    return preg_match(
                        "/'" . $key . "'\s*=>\s*(?:'((?:[^'\\\\]|\\\\.)*)'|(null))/",
                        $block,
                        $m
                    ) === 1 ? (($m[2] ?? '') === 'null' ? null : $m[1]) : null;
                };

                $entries[] = [
                    'file' => $relative,
                    'name' => (string) $read('name'),
                    'icon' => $read('icon'),
                    'parent' => $read('parent'),
                ];
            }
        }

        return $entries;
    }

    /** Every `.ti-<name>` rule the pinned stylesheet defines. */
    private function definedGlyphs(): array
    {
        $path = $this->repoPath(self::ICON_STYLESHEET);

        $this->assertFileExists($path, self::ICON_STYLESHEET . ' is gone; the admin has no icons.');

        preg_match_all('/\.ti-([a-z0-9-]+):before/', (string) file_get_contents($path), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * PREMISE, in both directions. `assertSame([], $missing)` is satisfied just as well by a
     * census that read nothing, and this one goes through a comment stripper and three regular
     * expressions, each of which can silently return empty.
     */
    #[Test]
    public function test_premise_the_census_reads_the_listeners_and_the_stylesheet(): void
    {
        $entries = $this->entries();

        $this->assertGreaterThan(60, count($entries), sprintf(
            'only %d menu entries were extracted from the two host listeners. 94 were measured '
            . 'when this file was written (87 of them carrying an icon, 60 of them children), so '
            . 'the extraction has broken and every assertion below is vacuous.',
            count($entries)
        ));

        $withIcons = array_filter($entries, static fn (array $e): bool => ($e['icon'] ?? '') !== '');

        $this->assertGreaterThan(30, count($withIcons),
            'almost no entry carried an icon, so the icon assertion below has nothing to judge.');

        $glyphs = $this->definedGlyphs();

        /* 1,298 MEASURED, and the figure is the point: Tabler's current release ships well over
           five thousand icons, so this pinned build is about a quarter of the published set. That
           is exactly why a name looked up in the documentation is not evidence that it renders
           here, and why both names this file was written for are real Tabler icons. */
        $this->assertGreaterThan(1000, count($glyphs), sprintf(
            'only %d `.ti-*` rules were read out of %s; 1,298 were measured when this file was '
            . 'written. The webfont build has changed shape and this census is now asserting '
            . 'against almost nothing.',
            count($glyphs),
            self::ICON_STYLESHEET
        ));

        /* AND THE CENSUS CAN SAY NO. A name the stylesheet certainly does not define must be
           reported as absent — otherwise a `str_contains`-shaped mistake would make every icon
           "present" and the test could not fail at all. */
        $this->assertNotContains('store-syria-not-a-real-icon', $glyphs);
    }

    /**
     * EVERY DECLARED ICON RESOLVES TO A GLYPH.
     */
    #[Test]
    public function test_every_navigation_icon_names_a_glyph_the_stylesheet_defines(): void
    {
        $glyphs = $this->definedGlyphs();
        $missing = [];

        foreach ($this->entries() as $entry) {
            $icon = (string) ($entry['icon'] ?? '');

            if ($icon === '') {
                continue;
            }

            if (! in_array($icon, $glyphs, true)) {
                $missing[] = sprintf('%s: %s declares icon `%s`',
                    basename($entry['file']), $entry['name'], $icon);
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "%d navigation entr%s name an icon that %s does not define. `menu-tree.blade.php` "
            . "renders `<i class=\"ti ti-<name>\">` and an undefined name paints NOTHING — a hole "
            . "in the sidebar beside a label, which no status code, no markup assertion and no "
            . "contrast walk can see. Tabler publishes more names than this pinned build "
            . "contains, so look the name up IN THE STYLESHEET and not in the documentation:\n  %s",
            count($missing),
            count($missing) === 1 ? 'y' : 'ies',
            self::ICON_STYLESHEET,
            implode("\n  ", $missing)
        ));
    }

    /**
     * AND A TOP-LEVEL ENTRY HAS AN ICON AT ALL, which is the other way to render nothing.
     *
     * `menu-tree.blade.php` emits the `<i>` only for `isTop`, and it emits it unconditionally —
     * `<i class="ti ti-"></i>` for an empty name. So an empty icon on a top-level entry is the
     * same hole as an undefined one, reached by a different route. A CHILD entry legitimately
     * carries `'icon' => ''`: seven of `CompanyMenuListener`'s do, the renderer prints no `<i>`
     * for them, and requiring one would be asserting the opposite of the design.
     */
    #[Test]
    public function test_every_top_level_entry_carries_an_icon(): void
    {
        $iconless = [];
        $children = 0;

        foreach ($this->entries() as $entry) {
            if ($entry['parent'] !== null) {
                $children++;

                continue;
            }

            if ((string) ($entry['icon'] ?? '') === '') {
                $iconless[] = basename($entry['file']) . ': ' . $entry['name'];
            }
        }

        /* The premise for the `continue` above: if the parent read ever broke and answered
           non-null for everything, this test would pass by skipping every entry. */
        $this->assertGreaterThan(10, $children,
            'the parent read found almost no child entries, so this test may be skipping '
            . 'top-level entries instead of judging them.');

        sort($iconless);

        $this->assertSame([], $iconless,
            "a TOP-LEVEL navigation entry carries no icon. `menu-tree.blade.php` renders the `<i>` "
            . "unconditionally for a top-level item, so this paints `<i class=\"ti ti-\">` — an "
            . "empty glyph box beside the label:\n  " . implode("\n  ", $iconless));
    }
}

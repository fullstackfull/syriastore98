<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Every `ti-*` icon this application's own code asks for exists in the bundled font.
 * =================================================================================================
 *
 * A MISSING ICON NAME FAILS SILENTLY AND COMPLETELY. `class="ti ti-does-not-exist"` is valid CSS
 * on a valid element: the `::before` is generated, the font loads, and the character simply is not
 * in it. Nothing is logged, nothing is thrown, no test notices, and the control renders as an
 * empty box the width of its padding.
 *
 * THREE SHIPPED THAT WAY, found by cropping one table row at 3x and counting the glyphs:
 *
 *   - `ti-layout-sidebar-right-expand` on the ORDER list's quick-preview button (P2-04-5)
 *   - the same name on the PRODUCT list's quick-preview button (P2-03)
 *   - `ti-category` in the storefront menu builder's type list
 *
 * The first two were a control a merchant could not see, in every screenshot of both lists, for as
 * long as the feature had existed. The bundled font has `ti-layout-sidebar-right` and no `-expand`
 * variant at all; it has no `ti-categor*` anything.
 *
 * KEYED ON THE FONT, NOT ON A LIST SOMEBODY MAINTAINS. The expectation is read out of
 * `tabler-icons.min.css` itself, so upgrading the font changes what passes without anyone editing
 * this file -- and a name that was valid and is dropped by an upgrade fails here rather than going
 * blank in production.
 *
 * WHAT IS READ AND WHAT IS NOT. `resources/views/` and `app/` -- this application's own markup and
 * the DataTables classes that build markup as strings. `packages/workdo/` is NOT read: it is
 * vendored, it is not ours to edit, and a finding there could only be reported, not fixed. Nor is
 * `public/js/`, where no icon class is currently written. Both exclusions are deliberate and the
 * reachability assertion below is what stops the scan passing because it found nothing.
 */
class IconNamesExistTest extends TestCase
{
    private const FONT = 'public/assets/fonts/tabler-icons.min.css';

    /** @var string[] */
    private const ROOTS = ['resources/views', 'app'];

    private function base(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] the icon names the font defines */
    private function fontIcons(): array
    {
        $css = file_get_contents($this->base() . '/' . self::FONT);
        $this->assertNotFalse($css, self::FONT . ' is not readable');

        preg_match_all('/\.(ti-[a-z0-9-]+):before/', $css, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array<string, string[]> icon name => files that ask for it */
    private function usedIcons(): array
    {
        $used = [];

        foreach (self::ROOTS as $root) {
            $dir = $this->base() . '/' . $root;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $txt = file_get_contents($file->getPathname());

                /*
                 * `ti` FOLLOWED BY `ti-something`, which is the convention everywhere in this
                 * codebase (`class="ti ti-pencil"`). Matching a bare `ti-...` would also catch
                 * prose, CSS selectors and this very docblock -- and a comment that mentions a
                 * name is exactly how a source-grep ratchet becomes satisfiable by writing about
                 * it. The pair is markup and nothing else.
                 */
                if (preg_match_all('/\bti\s+(ti-[a-z0-9-]+)/', $txt, $m)) {
                    foreach ($m[1] as $name) {
                        $used[$name][] = str_replace($this->base() . '/', '', $file->getPathname());
                    }
                }
            }
        }

        foreach ($used as $name => $files) {
            $used[$name] = array_values(array_unique($files));
        }

        return $used;
    }

    /**
     * REACHABILITY FIRST. Without this the scan passes when the regex stops matching, when a
     * directory is renamed, or when the font stops parsing -- three ways this file could report
     * "everything is fine" about nothing at all.
     */
    public function test_the_scan_reaches_both_the_font_and_the_markup(): void
    {
        $font = $this->fontIcons();
        $used = $this->usedIcons();

        $this->assertGreaterThan(500, count($font),
            'the icon font parsed to fewer names than it can possibly have -- the selector '
            . 'pattern or the file has changed');

        $this->assertGreaterThan(40, count($used),
            'the markup scan found almost no icon names, so a pass below would mean nothing');

        // A name this project definitely uses, as a canary on the regex itself.
        $this->assertArrayHasKey('ti-pencil', $used,
            'the scan did not find ti-pencil, which the order and product row actions both use');
    }

    public function test_every_icon_this_application_asks_for_exists_in_the_bundled_font(): void
    {
        $font = $this->fontIcons();
        $missing = [];

        foreach ($this->usedIcons() as $name => $files) {
            if (! in_array($name, $font, true)) {
                $missing[] = $name . '  <- ' . implode(', ', $files);
            }
        }

        sort($missing);

        $this->assertSame([], $missing,
            "These icon classes are written in this application's own markup and are NOT in "
            . self::FONT . ", so they render as an empty box:\n  " . implode("\n  ", $missing)
            . "\n\nPick a name the font actually has -- grep the font file for the nearest "
            . 'match. Do not add the name to any allowlist: there is none, by design.');
    }
}

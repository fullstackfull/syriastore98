<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-31 — the light surfaces, and the dark ink, that survive dark mode on the PAGE.
 * ================================================================================================
 *
 * FOUND BY CENSUS. `census-light-surfaces-in-dark.cjs` drives 31 merchant routes with the admin in
 * dark mode and reports every element painting an OPAQUE background above 0.72 luminance over an
 * area large enough to read as a SURFACE rather than a chip or an avatar. A class-name grep cannot
 * answer this: `bg-white` appears 40 times in resources/views and most of those are Tailwind
 * components that never load, while three of the four findings arrived from theme rules carrying
 * no telling class at all.
 *
 *     /finance              div.card-header.bg-white   1145x55   lum 1.00
 *     /finance              select.form-select          249x41   lum 1.00
 *     /finance/withdrawals  a.btn.btn-sm.btn-light      137x31   lum 0.98
 *     dashboard +3          div.cat-img                  75x60   lum 0.95
 *     /profile              a.list-group-item           268x54   lum 1.00
 *
 * AFTER: 0 light surfaces across the same 31 routes.
 *
 * THE HEADER IS FIXED IN THE MARKUP, NOT OVERRIDDEN. `bg-white` is Bootstrap's `!important`
 * utility and it is simply wrong on a card that has a theme; `.card-header`'s own
 * `--bs-card-cap-bg` already follows it. An override would have left a wrong declaration alive
 * underneath a correct one.
 *
 * AND REMOVING IT EXPOSED A LARGER DEFECT THE CENSUS COULD NOT SEE. The white block was hiding
 * dark ink: with it gone the heading measured 1.10:1 on the dark card. Measuring the family showed
 * that regression was the SMALL half -- `.card-header` text was ALREADY 1.10:1 on
 * /finance/withdrawals, /stock-reports, /profile, /blog and /pages. Nine of ten below AA, eight of
 * them pre-existing, because the host's dark stylesheet redefines `--bs-card-cap-bg` and not
 * `--bs-card-cap-color`. A heading nobody can read is not a lesser defect than a white block; it
 * is one a BACKGROUND census cannot find. After: 10 of 10 at 11.83:1.
 *
 * `.dash-content` IS LOAD-BEARING CONFINEMENT, not decoration. `.modal-content` is deliberately
 * WHITE in dark mode: the host keeps it so, P2.5-A-01 accepted that and fixed the INK on it, and
 * check-dark-form-surfaces-live.cjs re-verifies 20 checks across both directions. A dark arm
 * written as `[data-ss-theme="dark"] .form-select` reaches into those modals and undoes that
 * verified work. Modals are appended to <body>, outside `.dash-content`, so this cannot.
 */
class DarkPageSurfaceTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const FINANCE = 'resources/views/finance/index.blade.php';

    /** Every selector in the block must carry this, or it can reach the white modals. */
    private const CONFINEMENT = '[data-ss-theme="dark"] .dash-content';

    public function test_the_finance_card_header_does_not_force_white(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bcard-header\b[^"]*\bbg-white\b/',
            $this->read(self::FINANCE),
            'The `bg-white` utility is back on the finance card header. It is `!important` and has '
            . 'no dark arm, so it paints 1145x55 of pure white on the dark page -- measured at '
            . 'luminance 1.00. `.card-header` already follows the theme without it.'
        );
    }

    public function test_every_dark_arm_is_confined_to_the_page_and_cannot_reach_a_modal(): void
    {
        $block = $this->block();

        $this->assertNotSame('', $block, 'store-syria-ds.css has no P2.5-WAVE-31 block.');

        preg_match_all('/^([^{}\n][^{}]*)\{/m', $block, $found);

        $this->assertNotEmpty($found[1], 'The WAVE-31 block declares no rules.');

        foreach ($found[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                $selector = trim($selector);

                if ($selector === '') {
                    continue;
                }

                $this->assertStringStartsWith(
                    self::CONFINEMENT,
                    $selector,
                    'A WAVE-31 rule is not confined to `' . self::CONFINEMENT . '`: ' . $selector
                    . '. Modals are appended to <body> outside `.dash-content`, and `.modal-content` '
                    . 'is deliberately WHITE in dark mode with its ink fixed by P2.5-A-01. An '
                    . 'unconfined dark arm darkens those modals and undoes 20 verified checks.'
                );
            }
        }
    }

    public function test_the_block_repairs_the_heading_ink_as_well_as_the_surfaces(): void
    {
        $block = $this->block();

        $this->assertMatchesRegularExpression(
            '/\.card-header\s*\{[^}]*color:\s*var\(--ss-fg\)/s',
            $block,
            'The card heading no longer takes its colour from the theme. The host redefines '
            . '`--bs-card-cap-bg` for dark mode and NOT `--bs-card-cap-color`, so without this the '
            . 'heading is dark ink on a dark card: measured 1.10:1 on nine of ten headers.'
        );

        foreach (['.form-select', '.btn-light', '.cat-img', '.list-group-item'] as $surface) {
            $this->assertStringContainsString(
                $surface,
                $block,
                'The dark arm for ' . $surface . ' is gone; the census found it painting a light '
                . 'surface on the dark page.'
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/:\s*#[0-9a-f]{3,8}\b/i',
            $block,
            'A literal colour appeared in the WAVE-31 block. These are dark arms: a hex cannot '
            . 'follow a theme, and the palette is a token system two earlier slices re-solved.'
        );
    }

    public function test_the_census_tool_is_committed_so_the_figures_can_be_re_run(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/docs/development/phase-2.5/tools/census-light-surfaces-in-dark.cjs',
            'The census that produced this wave\'s numbers is gone, so nobody can re-run or argue '
            . 'with them.'
        );
    }

    private function block(): string
    {
        $css = $this->read(self::DS_SHEET);
        $open = strpos($css, 'P2.5-WAVE-31: the light surfaces that survive dark mode');
        $end = strpos($css, 'P2.5-WAVE-30: the toast a merchant cannot read', (int) $open);

        if ($open === false || $end === false || $end < $open) {
            return '';
        }

        $block = substr($css, $open, $end - $open);

        /* the slice starts inside the opening comment, so its tail has no `/*` to pair with */
        if (($close = strpos($block, '*/')) !== false) {
            $first = strpos($block, '/*');

            if ($first === false || $close < $first) {
                $block = substr($block, $close + 2);
            }
        }

        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path, $relative . ' is missing.');

        return (string) file_get_contents($path);
    }
}

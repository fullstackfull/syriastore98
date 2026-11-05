<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE SHARED CHROME CARRIES NO EMOJI, NO TYPOGRAPHIC GLYPH, NO HARD-CODED ICON COLOUR.  P2.5-WAVE-15
 * =================================================================================================
 *
 * These three sites are in partials that render on EVERY merchant screen, so each defect was
 * shipped a few hundred times over:
 *
 *   1. `layouts/cookie_consent.blade.php` titled the cookie dialog
 *      `'{{ $setting['cookie_title'] }} . 📢'` -- a loudspeaker EMOJI, plus a stray " . " before
 *      it. It is included by `app.blade.php` (every merchant screen) and `guest.blade.php` (the
 *      five auth screens). An emoji is not an icon: it renders in the platform's own colour and
 *      style, ignores the theme, and in Arabic sits on the wrong side of the sentence.
 *
 *   2. `MerchantQuickActions` gave all four quick-create rows `'glyph' => '+'` -- a typographic
 *      plus sign rendered as TEXT, two inches from `header.blade.php`'s real `ti ti-plus`. Now the
 *      service names an icon class and `command-palette.blade.php` renders it as an `<i>`.
 *
 *   3. `layouts/global-pagination.blade.php` drew its arrows as inline SVG with hard-coded fills:
 *      `#060606` EIGHT times on the numbered pager, and `#10B77F` twice on Prev/Next. Against
 *      `--bs-pagination-bg: #1c232f` on the dark page the first measures about 1.28:1 --
 *      effectively invisible. The second was DEAD as well as wrong: `custom.css:3599-3619`
 *      overrides `.nex-prev-btn-wrp .arrow-btn svg path` per theme (1..5), so one pagination block
 *      was coloured by two mechanisms at once. All ten now use `currentColor`, which follows the
 *      colour the theme already sets and leaves the per-theme override winning where it exists.
 *
 * THE FAVOURITE STAR IS DELIBERATELY NOT CONVERTED. `command-palette.blade.php` swaps ★ (U+2605)
 * and ☆ (U+2606) through `[data-ss-fav-glyph]`, and that pair is a correct filled/outline
 * distinction. The bundled font has NO filled-star counterpart -- `ti-star-filled` is absent; the
 * only pair available is `ti-star` (outline) against `ti-star-off` (a star with a slash), and a
 * crossed-out star reads as "blocked", not "not yet favourited". Converting it would trade a
 * correct affordance for a worse one to win a coherence number.
 */
class ShellGlyphContractTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** No emoji anywhere in the chrome that renders on every screen. */
    public function test_the_cookie_dialog_title_carries_no_emoji(): void
    {
        $src = $this->read('resources/views/layouts/cookie_consent.blade.php');

        $this->assertSame(
            0,
            preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $src),
            'an emoji is back in the cookie dialog, which renders on every merchant screen and the '
                . 'five auth screens. An emoji ignores the theme and is not an icon.'
        );
        $this->assertStringNotContainsString(
            "cookie_title'] }} . ",
            $src,
            'the stray " . " before the removed emoji is back in the dialog title'
        );
    }

    /** The quick-create rows name an icon, not a character. */
    public function test_every_quick_action_names_an_icon_class(): void
    {
        $service = $this->read('app/Services/MerchantQuickActions.php');

        preg_match_all("/'glyph'\s*=>\s*'([^']*)'/", $service, $m);
        $this->assertNotEmpty($m[1], 'premise: no quick-action glyphs found, so this measures nothing');

        foreach ($m[1] as $glyph) {
            $this->assertMatchesRegularExpression(
                '/^ti-[a-z0-9-]+$/',
                $glyph,
                "a quick action still renders the typographic glyph '{$glyph}' as text instead of "
                    . 'naming a Tabler icon class'
            );
        }

        $this->assertStringContainsString(
            'class="ti {{ $ssAction[\'glyph\'] }}"',
            $this->read('resources/views/partision/command-palette.blade.php'),
            'the command palette no longer renders the quick-action glyph as an icon element'
        );
    }

    /** The pager arrows follow the theme instead of a hard-coded near-black. */
    public function test_the_pager_arrows_carry_no_hard_coded_fill(): void
    {
        $src = $this->read('resources/views/layouts/global-pagination.blade.php');

        $this->assertDoesNotMatchRegularExpression(
            '/fill="#[0-9a-fA-F]{3,8}"/',
            $src,
            'a pager arrow is painted with a hard-coded colour again; on the dark page the previous '
                . '#060606 measured about 1.28:1 against the pagination background'
        );
        $this->assertGreaterThanOrEqual(
            10,
            substr_count($src, 'fill="currentColor"'),
            'fewer than the ten measured pager arrows follow currentColor'
        );
    }
}

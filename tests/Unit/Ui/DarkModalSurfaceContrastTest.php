<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-A-01 / A-02 (ledger F126) — the white modal surface, and every form control, in dark mode.
 * ================================================================================================
 *
 * MEASURED IN A REAL BROWSER FIRST, twice, because the two measurements disagree and the
 * disagreement is the point:
 *
 *   docs/development/phase-2.5/tools/measure-f126-cascade.cjs   (the four layout stylesheets)
 *   docs/development/phase-2.5/tools/check-dark-form-surfaces-live.cjs (the running application)
 *
 *   before : cascade 7 of 26 surfaces below AA   |  live  modal title 1.00:1, WHITE ON WHITE
 *   after  : cascade 1 of 26 (F152, out of slice) |  live  20 checks, 0 failed, both directions
 *
 * THREE HOST RULES LAND ON A SURFACE THEY WERE NOT WRITTEN FOR. `style-dark.css` keeps
 * `--bs-modal-bg: #ffffff` (:6234), so a Bootstrap modal is WHITE while the admin is dark, and
 * three dark-page rules paint it:
 *
 *   :29442  h1,h2,h3,h4,h5,h6 { color: var(--bs-white) !important }   ->  1.00:1   the title
 *   :29465  label             { color: #808191 !important }           ->  3.84:1
 *   :4482   custom.css's own `html[data-ss-theme="dark"] body label`  ->  2.52:1
 *   :29454  p:not([class^="ss-"])                                     ->  3.84:1
 *
 * WHY NARROWING custom.css's BARE SELECTOR CANNOT FIX THIS, which is what the F126 row proposed:
 * the host carries its OWN bare `label` rule at :29465. Narrowing ours uncovers the host's
 * #808191, which is still below AA on white. The surface has to be named, not the element.
 *
 * AND WHY THE VALUES MUST BE LAYER-1 PRIMITIVES. The first version of A-02 used the semantic
 * tokens `var(--ss-fg)` / `var(--ss-fg-subtle)` so host fields would match `.ss-input`. Measured,
 * it REGRESSED the ten views that carry `data-ss-theme="light"` from 3.71:1 to **1.13:1**: a
 * semantic token re-themes with the nearest scope, and the host field's `background-color:#292A33`
 * does not. Match the token layer to the SURFACE, not to the component.
 *
 * THIS TEST IS THE RATCHET, not the measurement. It pins each rule in both stylesheets and
 * recomputes the WCAG ratio from the two colours, so a revert or a tidy-up fails here with the
 * arithmetic attached. It also forbids the two specific mistakes above by name.
 */
class DarkModalSurfaceContrastTest extends TestCase
{
    private const CUSTOM_SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    private const DARK_SHEETS = [
        'public/assets/css/style-dark.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    /** The host modal's surface in dark mode — `--bs-modal-bg` in style-dark.css. */
    private const MODAL_BG = [255, 255, 255];

    /** The host form control's own surface in dark mode — style-dark.css:2377. */
    private const FIELD_BG = [41, 42, 51];

    private const MODAL_PRIMARY   = [26, 35, 34];    // --ss-grey-900 #1a2322
    private const MODAL_SECONDARY = [91, 102, 101];  // --ss-grey-600 #5b6665
    private const FIELD_VALUE     = [238, 241, 241]; // --ss-grey-100 #eef1f1
    private const FIELD_PLACEHOLD = [157, 163, 180]; // #9DA3B4

    // ------------------------------------------------------------------ the arithmetic

    public function test_the_modal_primary_token_clears_aa_on_the_white_modal(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::MODAL_PRIMARY, self::MODAL_BG),
            'labels and the modal title must clear AA on the white modal surface');
    }

    public function test_the_modal_secondary_token_clears_aa_on_the_white_modal(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::MODAL_SECONDARY, self::MODAL_BG),
            'prose, hints and helper text must clear AA on the white modal surface');
    }

    public function test_the_field_value_and_placeholder_both_clear_aa_on_the_dark_field(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::FIELD_VALUE, self::FIELD_BG));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::FIELD_PLACEHOLD, self::FIELD_BG));
    }

    /**
     * A placeholder that reads as loudly as a typed value is not a placeholder. The separation is
     * asserted so a future "contrast fix" cannot resolve the ratio by flattening the hierarchy.
     */
    public function test_the_placeholder_stays_visibly_subordinate_to_the_value(): void
    {
        $value = $this->luminance(self::FIELD_VALUE);
        $place = $this->luminance(self::FIELD_PLACEHOLD);

        $this->assertGreaterThan(1.8, $value / $place,
            'the placeholder must stay clearly dimmer than a typed value');
    }

    /**
     * THE PREMISE, so none of the cases above can be satisfied by a colour that was always fine.
     * Each replaced value really does fail on the surface it lands on.
     */
    public function test_every_replaced_colour_really_was_below_aa(): void
    {
        $this->assertSame(1.0, round($this->ratio([255, 255, 255], self::MODAL_BG), 2),
            'the modal title really was white on white');
        $this->assertLessThan(4.5, $this->ratio([157, 163, 180], self::MODAL_BG),
            '#9DA3B4 really was below AA on the white modal');
        $this->assertLessThan(4.5, $this->ratio([128, 129, 145], self::MODAL_BG),
            "the host's own #808191 really was below AA on the white modal too");
        $this->assertLessThan(4.5, $this->ratio([128, 129, 145], self::FIELD_BG),
            'the form control value really was below AA on its own surface');
        $this->assertLessThan(4.5, $this->ratio([108, 117, 125], self::FIELD_BG),
            'the placeholder really was below AA on its own surface');
    }

    /**
     * The regression that measurement caught: a semantic token on a surface that does not
     * re-theme. Kept as arithmetic so the reason survives even if the prose is trimmed.
     */
    public function test_the_semantic_token_that_was_tried_first_really_would_regress_it(): void
    {
        $lightScopeFg = [26, 35, 34];   // var(--ss-fg) resolved inside data-ss-theme="light"

        $this->assertLessThan(1.5, $this->ratio($lightScopeFg, self::FIELD_BG),
            'var(--ss-fg) inside a light scope lands near-black on the still-dark field');
    }

    // ------------------------------------------------------------------ the source

    public function test_the_host_still_carries_the_three_rules_this_slice_exists_to_beat(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertMatchesRegularExpression('/h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\s*\{[^}]*color:\s*var\(--bs-white\)\s*!important/i',
                $css, $sheet . ': the forced-white heading rule is gone. If the host theme was '
                . 'updated, re-measure the modal title and rewrite this slice in the same commit '
                . '-- do NOT weaken this assertion.');
            $this->assertMatchesRegularExpression('/--bs-modal-bg:\s*#ffffff/i',
                $css, $sheet . ': the modal surface is no longer white in dark mode. The whole '
                . 'premise of P2.5-A-01 has changed; re-measure before touching the rules.');
        }
    }

    /**
     * The selector assertions anchor on `,` or `{` deliberately. The first version used
     * `\.modal-title[^{]*\{`, and a bite test that renamed the selector to `.modal-title-DROPPED`
     * still satisfied it -- `[^{]*` happily ate the suffix. A ratchet that a rename slips past is
     * the control this repository keeps finding in its own CI.
     */
    public function test_both_custom_stylesheets_name_the_modal_surface(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertStringContainsString('P2.5-A-01', $css, $sheet . ' has lost the A-01 block');
            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.modal-content\s+\.modal-title\s*(,|\{)[^}]*color:\s*var\(--ss-grey-900/is',
                $css, $sheet . ' no longer gives the modal title a dark foreground');
            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.modal-content\s+label\s*(,|\{)/i',
                $css, $sheet . ' no longer scopes labels to the modal surface');
        }
    }

    public function test_both_custom_stylesheets_fix_the_form_control(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertStringContainsString('P2.5-A-02', $css, $sheet . ' has lost the A-02 block');
            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.form-control\s*(,|\{)[^}]*color:\s*var\(--ss-grey-100/is',
                $css, $sheet . ' no longer lifts the dark form control to an AA value');
        }
    }

    /**
     * THE RULE THE REGRESSION LEFT, asserted rather than written down: these two blocks must not
     * reach for a token that re-themes. `--ss-fg`, `--ss-fg-muted` and `--ss-fg-subtle` all change
     * value inside a `data-ss-theme="light"` container; the surfaces here do not.
     */
    public function test_neither_block_uses_a_token_that_re_themes(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css   = $this->read($sheet);
            $start = strpos($css, 'P2.5-A-01');
            $this->assertNotFalse($start);
            $end = strpos($css, '/* end P2.5-A-02');
            $this->assertNotFalse($end, $sheet . ' has lost the A-02 end marker, so this check '
                . 'cannot bound the block it is meant to inspect and must not report success.');
            $block = substr($css, $start, $end - $start);

            // The prose explains the regression and names the tokens, so only DECLARATIONS count.
            preg_match_all('/^\s*color:\s*([^;]+);/mi', $block, $m);
            foreach ($m[1] as $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/var\(\s*--ss-fg(-muted|-subtle)?\s*[,)]/i',
                    $value,
                    'A-01/A-02 declare `' . trim($value) . '`. That token re-themes inside a '
                    . 'data-ss-theme="light" container while these surfaces do not, which measured '
                    . '1.13:1 when it was tried. Use a layer-1 primitive.'
                );
            }
        }
    }

    /**
     * LIGHT MODE MUST BE UNREACHABLE BY CONSTRUCTION, not by argument. `custom.css` is served in
     * BOTH themes, so a rule here that forgets its `data-ss-theme="dark"` hook silently repaints
     * the light admin -- and a contrast fix that regresses the other theme is the defect this
     * slice exists to avoid, not a new one to introduce. Every selector in both blocks is checked
     * after comments are stripped, because the prose contains commas and braces and an earlier
     * version of this very check read the comment as 46 leaking selectors.
     */
    public function test_every_selector_in_both_blocks_is_scoped_to_dark(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css   = $this->read($sheet);
            $start = strpos($css, '/* =============================================================================================
   P2.5-A-01');
            $end   = strpos($css, '/* end P2.5-A-02');
            $this->assertNotFalse($start, $sheet . ' has lost the A-01 banner');
            $this->assertNotFalse($end, $sheet . ' has lost the A-02 end marker');

            $block = (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $start, $end - $start));

            preg_match_all('/([^{}]+)\{/', $block, $m);
            $selectors = [];
            foreach ($m[1] as $group) {
                foreach (explode(',', $group) as $sel) {
                    $sel = trim((string) preg_replace('/\s+/', ' ', $sel));
                    if ($sel !== '') {
                        $selectors[] = $sel;
                    }
                }
            }

            $this->assertNotEmpty($selectors, $sheet . ': no selector was found in the A-01/A-02 '
                . 'blocks. The markers may have moved -- this check must not report success when '
                . 'it could not take its measurement.');

            foreach ($selectors as $sel) {
                $this->assertStringStartsWith('html[data-ss-theme="dark"]', $sel,
                    $sheet . ': `' . $sel . '` is not scoped to dark mode, so it repaints the '
                    . 'LIGHT admin too. custom.css is served in both themes.');
            }
        }
    }

    /**
     * Both directions, byte for byte. The Arabic admin loads `rtl-custom.css` INSTEAD of
     * `custom.css` (app.blade.php:141), so a colour fix in one and not the other reaches half the
     * merchants. These blocks contain no physical property, so they are identical by construction
     * and any drift is a mistake.
     */
    public function test_the_two_directions_carry_byte_identical_blocks(): void
    {
        $blocks = [];
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css   = $this->read($sheet);
            $start = strpos($css, '/* =============================================================================================
   P2.5-A-01');
            $this->assertNotFalse($start, $sheet . ' has lost the A-01 block');
            $end = strpos($css, '/* end P2.5-A-02');
            $this->assertNotFalse($end, $sheet . ' has lost the A-02 end marker');
            $blocks[$sheet] = substr($css, $start, $end - $start);
        }

        $this->assertSame(
            array_values($blocks)[0],
            array_values($blocks)[1],
            'custom.css and rtl-custom.css have drifted. The Arabic admin loads the RTL copy '
            . 'instead of the LTR one, so a fix present in only one reaches only one direction.'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function read(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function ratio(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}

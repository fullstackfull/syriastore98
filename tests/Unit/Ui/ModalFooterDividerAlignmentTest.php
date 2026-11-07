<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-20 — the modal footer's divider lines up with the field above it.
 * ================================================================================================
 *
 * 56 merchant modal views put `.modal-footer` INSIDE the form's `.row`. MEASURED on the running
 * page, with the box model read off the live elements rather than inferred:
 *
 *     .row          margin-inline -12px   width 482px   --bs-gutter-x 1.5rem
 *     .col-12       padding-inline  12px  width 482px   -> its field's border box starts 12px in
 *     .modal-footer padding-inline  16px  width 482px   margin 0
 *
 * Bootstrap 5 gives every `.row > *` the gutter padding, and the footer keeps its own 16px instead
 * -- but padding is INSIDE the border box, and `border-top` is drawn ON the border box. So the
 * divider spans the whole row while the field is inset by the gutter, and the line runs wider than
 * the control it sits under. Measured across four combinations and three modals:
 *
 *     footer outside the row (faq create)   field 20/20   footer 20/20   delta  +0/+0   aligned
 *     footer inside  the row (3 modals)     field 20/20   footer  8/8    delta -12/-12  overhangs
 *
 * Twelve of sixteen readings overhang, by exactly half the gutter, in both directions and both
 * themes. That is a geometry fact, not a judgement: the fix has to drive both deltas to zero.
 *
 * WHY ONE CSS RULE AND NOT 56 VIEW EDITS. Moving the footer out of the `.row` in every view is 56
 * files of risk for a 12px correction, and each edit could land differently. The rule states the
 * relationship once, in the sheet, and the measurement proves it in the browser.
 *
 * WHY custom.css AND NOT store-syria-ds.css. The design system sheet is confined -- every selector
 * must carry an `.ss-` class or a `data-ss-` attribute, asserted by DesignSystemStylesheetScopeTest
 * -- and this rule must match in BOTH themes. `data-ss-theme` is absent on the light page
 * (MEASURED: `data-ss-theme=null`, sheet=style.css), so a `[data-ss-theme]` selector would fix the
 * dark page and leave the light one overhanging. This is a host-markup correction and it belongs
 * with the other host-selector rules, in the mirrored pair.
 *
 * AND IT MUST BE IN BOTH SHEETS. custom.css serves LTR and rtl-custom.css serves Arabic INSTEAD of
 * it, so a rule in one reaches about half the merchants. P2.5-WAVE-16b shipped exactly that
 * mistake; this test is the per-file guard against repeating it.
 */
class ModalFooterDividerAlignmentTest extends TestCase
{
    private const SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    /** Half of Bootstrap's `--bs-gutter-x`, which is what the footer has to come in by. */
    private const GUTTER_HALF_PX = 12;

    public function test_both_sheets_carry_the_alignment_rule(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.modal-content\s+\.row\s*>\s*\.modal-footer\s*\{[^}]*'
                . 'margin-inline:\s*calc\(var\(--bs-gutter-x[^)]*\)\s*\*\s*\.?5\)/is',
                $this->read($sheet),
                $sheet . ': the modal footer is not pulled in by half the gutter, so its divider '
                . 'still runs 12px wider than the field above it in every modal this sheet serves.');
        }
    }

    /**
     * The width has to come down by the WHOLE gutter or the margins push the footer past the row.
     * A margin alone on a `.row > *` -- which Bootstrap gives `width: 100%` -- overflows.
     */
    public function test_the_rule_also_narrows_the_box_so_it_cannot_overflow(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.modal-content\s+\.row\s*>\s*\.modal-footer\s*\{[^}]*'
                . 'width:\s*calc\(100%\s*-\s*var\(--bs-gutter-x[^)]*\)\)/is',
                $this->read($sheet),
                $sheet . ': the rule sets a margin without narrowing the box, so the footer is '
                . '100% + 24px wide and overflows the row it sits in.');
        }
    }

    /** The two directions are compared so one cannot drift from the other. */
    public function test_the_two_sheets_carry_byte_identical_blocks(): void
    {
        $blocks = array_map(fn ($s) => $this->block($s), self::SHEETS);

        $this->assertSame($blocks[0], $blocks[1],
            'The P2.5-WAVE-20 block differs between custom.css and rtl-custom.css. The Arabic '
            . 'layout loads rtl-custom.css INSTEAD of custom.css, so a difference here means the '
            . 'two halves of the merchant base see different geometry.');
    }

    /**
     * It must be a LOGICAL property. `margin-left`/`margin-right` would need mirroring by hand and
     * the mirrored sheets are byte-identical, so a physical property here would be wrong in one of
     * the two directions by construction.
     */
    public function test_the_rule_uses_logical_properties_only(): void
    {
        foreach (self::SHEETS as $sheet) {
            // Comments FIRST. The block's own rationale names `margin-left` to explain why it is
            // not used, and the first version of this test matched that prose and failed -- the
            // "detector matching its own comment" shape this project keeps producing. Twice in
            // one session: ModalFooterButtonContrastTest's selector parser hit it too.
            // The capture starts INSIDE the opening marker comment, so its first characters are
            // prose with no `/*` to pair with: drop everything up to the first `*/` BEFORE
            // stripping whole comments. Without this the rationale is read as declarations.
            $declarations = $this->block($sheet);
            if (($end = strpos($declarations, '*/')) !== false) {
                $declarations = substr($declarations, $end + 2);
            }
            $declarations = preg_replace('/\/\*.*?\*\//s', '', $declarations);

            foreach (['margin-left', 'margin-right', 'padding-left', 'padding-right'] as $physical) {
                $this->assertStringNotContainsString($physical, $declarations,
                    $sheet . ': the block uses the physical property `' . $physical . '`. The two '
                    . 'sheets are byte-identical, so a physical inline property is wrong in one of '
                    . 'the two directions no matter which value it takes.');
            }
        }
    }

    /**
     * PREMISE, asserted rather than remembered: the gutter really is 1.5rem, so "half the gutter"
     * really is the 12px the measurement found. If the host ever retunes it, the rule still holds
     * because it is written in terms of the variable -- but this figure in the docblock would lie.
     */
    public function test_premise_the_host_gutter_is_still_one_and_a_half_rem(): void
    {
        $host = $this->read('public/assets/css/style.css');

        $this->assertMatchesRegularExpression('/--bs-gutter-x:\s*1\.5rem/i', $host,
            'The host gutter is no longer 1.5rem, so the 12px in this file\'s docblock and in '
            . 'the evidence is stale. The rule itself is written against the variable and still '
            . 'holds; re-measure and correct the prose.');
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function block(string $sheet): string
    {
        $css = $this->read($sheet);

        $this->assertTrue(
            (bool) preg_match('/\/\* ---- P2\.5-WAVE-20(.*?)\/\* end P2\.5-WAVE-20/s', $css, $m),
            $sheet . ': the P2.5-WAVE-20 markers are missing, so this test cannot tell which rules '
            . 'belong to the slice.');

        return trim($m[1]);
    }
}

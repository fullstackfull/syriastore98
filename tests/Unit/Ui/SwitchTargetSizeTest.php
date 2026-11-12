<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE ROW STATUS SWITCH IS 28 x 17.5 AND WCAG 2.2 AA ASKS FOR 24 x 24.  P2.5-DESIGN-K
 * =================================================================================================
 *
 * SC 2.5.8 Target Size (Minimum) is satisfied by a 24x24 CSS px target OR by 24px of spacing OR by
 * the inline-in-a-sentence exception. Measured across /customer /order /product-list /dashboard
 * /finance /product-brand /product-label with every exception implemented, only
 * **3 of 477 targets fail**, and they are all this one component:
 *
 *     input.form-check-input.page-checkbox   28 x 17.5   nearest other target 1.8px away
 *
 * The spacing exception cannot rescue it: the row's action cluster sits **1.8px** away, and the
 * exception needs a 24px-diameter circle to touch nothing.
 *
 * THE CAUSE IS THE UNIT, NOT THE NUMBER. Bootstrap sizes the switch in `em`
 * (`.form-check-input { height: 1.25em }`, `.form-switch .form-check-input { width: 2em }`), so it
 * tracks the LOCAL font size -- and this admin's base is 14px, not 16px. 1.25em of 14px is 17.5px.
 * The same stylesheet on a 16px page would give 20px, still short, so the unit has to change as
 * well as the value.
 *
 * WHY THIS IS NOT THE "43 SMALL TARGETS" THE SCREEN AUDIT REPORTS. `audit-merchant-screens.cjs`
 * flags anything under 44px for a non-anchor, which is Apple's HIG RECOMMENDATION, and mixes it
 * with the 24px WCAG REQUIREMENT in one count. Against the requirement, measured properly, the
 * number is 3. The audit's figure is not wrong, it is a different question.
 *
 * MEASURED BY INJECTION BEFORE IT WAS WRITTEN, over three candidate sizes on the live page: the
 * row height stays 55.4px and the table width stays 1168px for every one of them, so the switch
 * grows into space the row already had and nothing reflows.
 */
class SwitchTargetSizeTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /** WCAG 2.2 SC 2.5.8 Target Size (Minimum), AA. */
    private const MIN_TARGET_PX = 24.0;

    private const ROOT_FONT_PX = 16.0;

    private function rule(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        $this->assertMatchesRegularExpression(
            '/\.form-switch\s+\.form-check-input\s*\{/',
            $css,
            'the design system does not size the switch at all. Bootstrap sizes it in `em`, which '
            . "this admin's 14px base shrinks to 17.5px — under the 24px SC 2.5.8 asks for."
        );

        preg_match('/\.form-switch\s+\.form-check-input\s*\{([^}]*)\}/', $css, $m);

        return $m[1];
    }

    /**
     * THE UNIT IS THE DEFECT. An `em` value is relative to the element's own font size, so the
     * same declaration yields a different target on every screen whose type scale differs —
     * which is exactly how a control that reads as 20px on a mock became 17.5px in the product.
     */
    public function test_the_switch_is_not_sized_in_em(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(width|height)\s*:\s*[\d.]+em\b/',
            $this->rule(),
            'the switch must not be sized in `em`: it then tracks the local font size, and this '
            . "admin's base is 14px. That is what produced 28 x 17.5."
        );
    }

    /** @dataProvider dimensions */
    public function test_the_switch_clears_the_minimum_target(string $property): void
    {
        $rule = $this->rule();

        $this->assertMatchesRegularExpression(
            '/\b' . $property . '\s*:\s*[\d.]+(rem|px)\b/',
            $rule,
            "the switch rule must declare `{$property}` in rem or px."
        );

        preg_match('/\b' . $property . '\s*:\s*([\d.]+)(rem|px)\b/', $rule, $m);
        $px = $m[2] === 'rem' ? ((float) $m[1]) * self::ROOT_FONT_PX : (float) $m[1];

        $this->assertGreaterThanOrEqual(
            self::MIN_TARGET_PX,
            $px,
            sprintf(
                '`%s: %s%s` resolves to %.1fpx at a %.0fpx root, under the %.0fpx SC 2.5.8 (AA) '
                . 'asks for. The spacing exception is unavailable here: the row action cluster is '
                . '1.8px away.',
                $property,
                $m[1],
                $m[2],
                $px,
                self::ROOT_FONT_PX,
                self::MIN_TARGET_PX
            )
        );
    }

    /**
     * AND IT MUST STAY A SWITCH. Clearing the minimum by making it square would conform and look
     * like a checkbox; the control is a toggle and its shape is what says so. Bootstrap's own
     * ratio is 1.6:1, and anything near that reads correctly.
     */
    public function test_the_switch_keeps_its_shape(): void
    {
        $rule = $this->rule();
        preg_match('/\bwidth\s*:\s*([\d.]+)(rem|px)\b/', $rule, $w);
        preg_match('/\bheight\s*:\s*([\d.]+)(rem|px)\b/', $rule, $h);
        $this->assertCount(3, $w, 'premise: the rule declares a width');
        $this->assertCount(3, $h, 'premise: the rule declares a height');

        $wpx = $w[2] === 'rem' ? ((float) $w[1]) * self::ROOT_FONT_PX : (float) $w[1];
        $hpx = $h[2] === 'rem' ? ((float) $h[1]) * self::ROOT_FONT_PX : (float) $h[1];

        $this->assertGreaterThan(
            1.3,
            $wpx / $hpx,
            sprintf('%.0f x %.0f is too square for a toggle — it reads as a checkbox.', $wpx, $hpx)
        );
    }

    public static function dimensions(): array
    {
        return ['width' => ['width'], 'height' => ['height']];
    }
}

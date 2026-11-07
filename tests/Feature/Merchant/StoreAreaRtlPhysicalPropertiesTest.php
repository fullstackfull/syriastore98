<?php

namespace Tests\Feature\Merchant;

use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-WAVE-6 — the one kind of RTL defect this project's mechanism CANNOT reach.
 * =================================================================================================
 *
 * The admin layout swaps stylesheets for Arabic: `custom.css` becomes `rtl-custom.css` and
 * `style-dark.css` becomes `rtl-style-dark.css` (`layouts/app.blade.php:181-189`). That handles
 * every physical property written in a SHEET.
 *
 * **It cannot touch an inline `style` attribute.** An inline declaration outranks any sheet rule
 * that is not `!important`, and the swap replaces sheets, not attributes. So a `right:` or a
 * physical `border-radius` written into the markup survives the mirror and stays pinned to the
 * physical side while everything around it moves.
 *
 * `store/plan.blade.php` had exactly one:
 *
 *     style="top: 10px; right: 10px; border-radius:5px 0 0 5px"
 *
 * on the "Active" badge of the merchant's current plan. In Arabic the card mirrored and the badge
 * did not. Its rounded corners — meant for the inner edge facing the card — faced outward.
 *
 * The repair is logical properties: `inset-inline-end` is `right` in LTR and `left` in RTL, and the
 * two `border-*-start-radius` longhands round the inline-start corners, which is what
 * `5px 0 0 5px` meant in LTR.
 *
 * **THIS TEST IS A RATCHET FOR THE WHOLE AREA, not just that line.** A grep over the 25 files of
 * customers / stores / delivery boys found no other inline physical offset today, which is what the
 * work-list claimed and what is now asserted rather than believed. A new one fails here.
 */
class StoreAreaRtlPhysicalPropertiesTest extends SecurityTestCase
{
    private const VIEWS = __DIR__ . '/../../../resources/views/';

    /** The area P2.5-WAVE-6 owns. */
    private const AREA = ['store', 'customer', 'deliveryboy'];

    /**
     * Physical inset and corner properties, inside a `style="..."` attribute only.
     *
     * `top` and `bottom` are deliberately NOT here: the block axis does not mirror, so `top: 10px`
     * is correct in both directions and flagging it would be noise that gets the test suppressed.
     */
    private const PHYSICAL = [
        'left:', 'right:',
        'margin-left:', 'margin-right:',
        'padding-left:', 'padding-right:',
        'border-left:', 'border-right:',
        'border-radius:',
        'text-align: left', 'text-align: right',
        'float: left', 'float: right',
    ];

    public function test_premise_the_layout_really_swaps_sheets_for_arabic(): void
    {
        $layout = (string) file_get_contents(self::VIEWS . 'layouts/app.blade.php');

        $this->assertStringContainsString('rtl-custom.css', $layout,
            'the admin layout no longer swaps in an RTL stylesheet, so the premise of this test — '
            . '"sheets mirror, inline attributes do not" — no longer describes the product');
    }

    public function test_the_plan_badge_uses_logical_properties(): void
    {
        $source = (string) file_get_contents(self::VIEWS . 'store/plan.blade.php');

        $this->assertStringContainsString('inset-inline-end', $source,
            'the Active-plan badge no longer uses a logical inset, so in Arabic it pins to the '
            . 'physical right while the card around it mirrors');

        $this->assertStringContainsString('border-start-start-radius', $source,
            'the badge no longer uses logical corner radii, so its rounded edge faces outward in '
            . 'Arabic instead of toward the card');
    }

    public function test_the_badge_icon_is_not_painted_on_its_own_colour(): void
    {
        $source = (string) file_get_contents(self::VIEWS . 'store/plan.blade.php');

        $this->assertDoesNotMatchRegularExpression(
            '/<i[^>]*\bti ti-circle\b[^>]*\btext-primary\b/',
            $source,
            'the Active badge\'s dot carries `text-primary` inside a `bg-primary` badge — the '
            . 'brand colour on the brand colour, so the dot is a hole rather than a mark. Let it '
            . 'inherit the badge foreground instead'
        );
    }

    /**
     * THE RATCHET. Any NEW inline physical offset anywhere in the area fails this.
     */
    public function test_no_inline_physical_offset_anywhere_in_the_area(): void
    {
        $offenders = [];

        foreach (self::AREA as $dir) {
            foreach (glob(self::VIEWS . $dir . '/*.blade.php') ?: [] as $file) {
                $source = (string) file_get_contents($file);

                // Only look inside style="..." attributes. A physical property in a <style> block
                // or in a class name is a different question with a different fix.
                if (! preg_match_all('/style\s*=\s*"([^"]*)"/i', $source, $m)) {
                    continue;
                }

                foreach ($m[1] as $declaration) {
                    foreach (self::PHYSICAL as $needle) {
                        if (stripos($declaration, $needle) !== false) {
                            $offenders[] = basename(dirname($file)) . '/' . basename($file)
                                . ' — style="' . trim($declaration) . '"';
                            continue 3;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "An inline physical property was added to a view in the customers / stores / delivery "
            . "boys area. The admin layout mirrors Arabic by SWAPPING STYLESHEETS "
            . "(layouts/app.blade.php:181-189), and that cannot reach a `style` attribute — an "
            . "inline declaration outranks any sheet rule that is not `!important`. Use the logical "
            . "property instead: `inset-inline-start/end` for `left`/`right`, `margin-inline-*`, "
            . "`padding-inline-*`, `border-inline-*`, the `border-*-*-radius` longhands, "
            . "`text-align: start/end`, `float: inline-start/end`.\n  "
            . implode("\n  ", $offenders));
    }
}

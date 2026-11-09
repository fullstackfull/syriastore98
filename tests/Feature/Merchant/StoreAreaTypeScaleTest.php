<?php

namespace Tests\Feature\Merchant;

use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-WAVE-6 — two places where competing size mechanisms made the SMALLEST one win.
 * =================================================================================================
 *
 * `public/assets/css/style.css` carries both of these:
 *
 *     .text-sm { font-size: 0.75rem !important; }     :15112   → 12px, and it is !important
 *     .h6      { font-size: 0.9375rem; }              :263     → 15px, and it is not
 *
 * So `class="h6 text-sm"` is not "a heading, small". It is **12px with an inert `.h6`** — and the
 * markup reads as though a deliberate size was chosen when the opposite one is painted.
 *
 * TWO SITES IN THIS AREA, measured rather than assumed:
 *
 *   store/custom_domain.blade.php:37  a ~300-character cPanel/Plesk instruction paragraph marked up
 *                                     as `<h6 class="text-muted text-sm my-2">`. The screen's ONLY
 *                                     instructions, rendered at 12px — and announced to a screen
 *                                     reader as a level-6 heading, because prose was wearing a
 *                                     heading element. Now `<x-ss.help>`, which the design system
 *                                     provides for exactly this: `--ss-text-sm` is **0.8125rem /
 *                                     13px**, not 12px, and it brings an info panel with a
 *                                     `border-inline-start` accent instead of three utility classes.
 *
 *   customer/view.blade.php:72        the order Date cell, `h6 text-sm font-weight-bold mb-0` — one
 *                                     column at 12px bold while every sibling cell in the row is a
 *                                     bare `<span>` at the table's own size. Now a bare `<span>`
 *                                     like its siblings. The date is data, like the price beside it.
 *
 * **The size change is modest and is not the point.** 12px → 13px on one paragraph, and one table
 * column stops being the odd one out. What actually changes is that prose stops being a heading and
 * a cell stops carrying three contradictory instructions about its own size.
 */
class StoreAreaTypeScaleTest extends SecurityTestCase
{
    private const VIEWS = __DIR__ . '/../../../resources/views/';
    private const AREA = ['store', 'customer', 'deliveryboy'];

    public function test_premise_the_two_rules_really_do_collide_in_the_vendored_sheet(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/style.css');

        $this->assertMatchesRegularExpression('/\.text-sm\s*\{\s*font-size:\s*0\.75rem\s*!important/s', $css,
            '`.text-sm` is no longer `0.75rem !important`, so the collision this slice repaired no '
            . 'longer exists and these repairs should be re-read rather than inherited');

        $this->assertMatchesRegularExpression('/\.h6\s*\{\s*font-size:\s*0\.9375rem/s', $css,
            '`.h6` is no longer 0.9375rem; same reasoning');
    }

    public function test_premise_the_design_systems_help_size_is_larger_than_the_utility(): void
    {
        $ds = (string) file_get_contents(__DIR__ . '/../../../public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression('/--ss-text-sm:\s*0\.8125rem/', $ds,
            'the design system\'s small size is no longer 0.8125rem, so moving the instructions '
            . 'onto `<x-ss.help>` may no longer make them larger than the 0.75rem they were');
    }

    public function test_the_domain_instructions_are_guidance_and_not_a_heading(): void
    {
        $source = (string) file_get_contents(self::VIEWS . 'store/custom_domain.blade.php');

        $this->assertStringContainsString('<x-ss.help', $source,
            'the cPanel/Plesk instructions are no longer on the design system\'s help component, '
            . 'so the screen\'s only guidance is back to whatever size and semantics the raw '
            . 'utility classes give it');

        // The prose must not be inside a heading element any more. Only the live markup is
        // searched: this file's own explanatory comment quotes the old class list.
        $live = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertDoesNotMatchRegularExpression('/<h6[^>]*\btext-sm\b/', (string) $live,
            'a paragraph is wearing a heading element again on store/custom_domain');
    }

    public function test_the_order_date_cell_matches_its_sibling_cells(): void
    {
        $source = (string) file_get_contents(self::VIEWS . 'customer/view.blade.php');
        $live = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertDoesNotMatchRegularExpression('/class="h6 text-sm font-weight-bold/', $live,
            'the order Date cell carries `h6 text-sm font-weight-bold` again — three size '
            . 'mechanisms where `.text-sm`\'s `!important` wins, so the column renders 12px bold '
            . 'while every cell beside it renders at the table\'s own size');
    }

    /**
     * THE RATCHET. `.h6` and `.text-sm` on one element is always the same bug: it reads as a
     * deliberate size and paints the other one.
     */
    public function test_no_element_in_the_area_stacks_h6_with_text_sm(): void
    {
        $offenders = [];

        foreach (self::AREA as $dir) {
            foreach (glob(self::VIEWS . $dir . '/*.blade.php') ?: [] as $file) {
                $live = (string) preg_replace(
                    '/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file)
                );

                if (preg_match_all('/class="([^"]*)"/', $live, $m)) {
                    foreach ($m[1] as $classes) {
                        $words = preg_split('/\s+/', trim($classes)) ?: [];
                        if (in_array('h6', $words, true) && in_array('text-sm', $words, true)) {
                            $offenders[] = basename(dirname($file)) . '/' . basename($file)
                                . ' — class="' . $classes . '"';
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "`.h6` and `.text-sm` were stacked on one element again. `.text-sm` is "
            . "`font-size: 0.75rem !important` (style.css:15112) and `.h6` is `0.9375rem` (:263), "
            . "so the `.h6` is INERT and the element renders at 12px — the markup says one size "
            . "and the page paints another. Pick one, or use a design-system size token.\n  "
            . implode("\n  ", $offenders));
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-GRID-ICONS — the Show and Edit icons on the order grid card measured 1.47:1 in dark mode.
 * =================================================================================================
 *
 * `public/css/custom.css` (and its Arabic mirror `public/css/rtl-custom.css`) carry an unguarded
 *
 *     .order-card .btn:hover i,
 *     .order-card .btn i { color: #000000; }
 *
 * At 0-3-1 / 0-2-1 that beats the host dark sheet's own rescue rule
 * `.btn i { color: var(--bs-white) }` (public/assets/css/style-dark.css:19635-19638, 0-1-1), which
 * carries no `!important`. The two affected controls -- Show and Edit in
 * resources/views/order/grid.blade.php -- are transparent buttons
 * (`--bs-btn-bg: transparent`, style-dark.css:3446) over the host's dark card
 * (`--bs-card-bg: #292A33`, style-dark.css:5006, painted :5015), so #000000 paints on #292A33:
 * **1.47:1**, against WCAG 1.4.11's 3:1 for a non-text graphic that conveys the control.
 *
 * THE FIX IS A DARK-ONLY ADDITION, NOT AN EDIT. The rule above is CORRECT in light mode (#000 on
 * the white card is 21:1), so it is left untouched and a dark-scoped block is appended after it.
 *
 * THE SCOPE IS `.border`, WHICH IS WHAT SEPARATES THE UNFILLED CARD CONTROLS FROM THE FILLED PAIR.
 * The conditional return Approve/Cancel buttons are `.btn-primary` / `.btn-danger`, i.e.
 * `background-color: var(--theme-color)` (style-dark.css:3535-3538) where `--theme-color` is one
 * of ten host presets or `var(--color-customColor)`. Across those presets the safe foreground
 * FLIPS -- #000 is 8.42:1 on the gold preset #c0a145 but 2.33:1 on the grey #48494b -- so no
 * unguarded foreground is safe on them. That is a separate defect needing `--ss-onbrand`
 * (custom.css derives it by script); this rule must not reach them.
 *
 * AND THE BLOCK MUST CARRY NO `!important`: `.text-danger` is `!important`
 * (style-dark.css:9414-9417, `--bs-danger-rgb: 255, 58, 110` at :54), which is the only reason the
 * Delete icon stays #FF3A6E = 4.13:1. An `!important` here would flatten the destructive-action
 * signal to the same grey as Show and Edit -- the ratio would improve while the meaning was lost.
 *
 * WHY THIS FILE ASSERTS STRUCTURE AND NOT JUST TEXT. The obvious tests for this repair -- "both
 * sheets carry the selector", "the two directions are byte-identical", "no selector matches in
 * light mode" -- all PASS when the block is inserted one line too early, inside the existing
 * declaration block, where CSS Nesting makes it resolve to `<i> ... html` and match nothing. Three
 * of them would certify a no-op. So test (a) below pins the block as a TOP-LEVEL rule, and it is
 * the one assertion here that cannot pass by accident.
 */
class GridCardIconContrastTest extends TestCase
{
    private const SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    private const GRID = 'resources/views/order/grid.blade.php';

    /** style-dark.css:5006 `--bs-card-bg`, painted :5015. */
    private const DARK_CARD = '#292A33';

    /** The unguarded declaration, which is right in light mode and stays. */
    private const BLACK = '#000000';

    /** `--ss-fg` on [data-ss-theme="dark"], public/css/store-syria-ds.css. */
    private const SS_FG_DARK = '#e8eaed';

    /** `rgba(var(--bs-danger-rgb), 1)` in dark mode. */
    private const DANGER_DARK = '#FF3A6E';

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function sheet(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function luminance(array $rgb): float
    {
        $channel = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($this->rgb($a));
        $lb = $this->luminance($this->rgb($b));

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Everything from the block's comment opener to the end of its closing marker comment. */
    private function block(string $relative): string
    {
        $css = $this->sheet($relative);

        $marker = strpos($css, 'P2.5-GRID-ICONS start');
        $this->assertNotFalse($marker, "$relative has no P2.5-GRID-ICONS block, so the dark-mode "
            . 'Show/Edit icons on the order grid card are still #000000 on #292A33 = 1.47:1');

        $start = strrpos(substr($css, 0, $marker), '/*');
        $this->assertNotFalse($start, "$relative: no comment opener before the start marker");

        $end = strpos($css, 'P2.5-GRID-ICONS end', $start);
        $this->assertNotFalse($end, "$relative: the P2.5-GRID-ICONS block is unbounded");

        $close = strpos($css, '*/', $end);
        $this->assertNotFalse($close, "$relative: the end marker's comment is never closed");

        return substr($css, $start, ($close + 2) - $start);
    }

    private function rulesOnly(string $block): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    /** @return list<string> one entry per comma-separated selector in the block */
    private function selectors(string $relative): array
    {
        $rules = $this->rulesOnly($this->block($relative));

        preg_match_all('/([^{}]+)\{/', $rules, $m);

        $out = [];
        foreach ($m[1] as $group) {
            foreach (explode(',', $group) as $one) {
                $one = trim($one);
                if ($one !== '') {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }

    // =============================================================================================
    //  PREMISE — the defect is real, and the chosen replacement clears the threshold
    // =============================================================================================

    public function test_premise_the_unguarded_black_really_is_invisible_on_the_dark_card(): void
    {
        $ratio = $this->contrast(self::BLACK, self::DARK_CARD);

        // Computed here with the WCAG formula: #000000 on #292A33 = 1.47:1.
        $this->assertSame(1.47, round($ratio, 2), sprintf(
            '#000000 on the host dark card measures %.2f:1, not the 1.47:1 this slice was '
            . 'written against; re-measure before trusting the rest of this file', $ratio));

        $this->assertLessThan(3.0, $ratio, 'WCAG 1.4.11 asks 3:1 for a non-text control graphic');
    }

    public function test_the_replacement_clears_the_non_text_contrast_threshold(): void
    {
        $ratio = $this->contrast(self::SS_FG_DARK, self::DARK_CARD);

        // Computed here with the WCAG formula: #e8eaed on #292A33 = 11.83:1.
        $this->assertSame(11.83, round($ratio, 2), sprintf(
            '--ss-fg on the host dark card measures %.2f:1', $ratio));

        $this->assertGreaterThanOrEqual(4.5, $ratio);
    }

    public function test_the_delete_icon_is_already_legible_and_must_keep_its_own_colour(): void
    {
        $ratio = $this->contrast(self::DANGER_DARK, self::DARK_CARD);

        // Computed here with the WCAG formula: #FF3A6E on #292A33 = 4.13:1.
        $this->assertSame(4.13, round($ratio, 2), sprintf(
            'the dark danger red measures %.2f:1 on the card', $ratio));

        $this->assertGreaterThanOrEqual(3.0, $ratio, 'so there is nothing to fix on Delete, and '
            . 'an !important in the new block would destroy its signal for no gain');
    }

    public function test_light_mode_was_never_the_defect(): void
    {
        $ratio = $this->contrast(self::BLACK, '#FFFFFF');

        // Computed here with the WCAG formula: #000000 on #FFFFFF = 21.00:1.
        $this->assertSame(21.0, round($ratio, 2));
    }

    // =============================================================================================
    //  (a) THE BLOCK IS A TOP-LEVEL RULE — the one assertion that cannot pass by accident
    // =============================================================================================

    /**
     * Inserted one line early -- after the `color: #000000;` instead of after the closing `}` --
     * the new rule nests inside the existing declaration block. On an engine with CSS Nesting it
     * resolves relative to its parent, i.e. an `html` descendant of an `<i>`, which can never
     * match; on an older engine the whole thing is a bogus declaration and is discarded. Either
     * way the icons stay at 1.47:1 while every text-matching assertion passes.
     */
    public function test_the_block_is_a_top_level_rule_and_not_nested_in_the_rule_above(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->sheet($sheet);
            $block = $this->block($sheet);

            $offset = strpos($css, $block);
            $this->assertNotFalse($offset);

            $before = rtrim($this->rulesOnly(substr($css, 0, $offset)));

            $this->assertSame('}', substr($before, -1), "$sheet: the P2.5-GRID-ICONS block is not "
                . 'preceded by a closing brace, so it is nested inside the rule above it and '
                . 'matches nothing. Insert it AFTER the `}` of `.order-card .btn i`, not after '
                . 'the declaration.');

            $rules = $this->rulesOnly($block);
            $this->assertSame(substr_count($rules, '{'), substr_count($rules, '}'),
                "$sheet: braces do not balance inside the P2.5-GRID-ICONS block");
            $this->assertGreaterThan(0, substr_count($rules, '{'),
                "$sheet: the P2.5-GRID-ICONS block contains no rule at all");
        }
    }

    // =============================================================================================
    //  (b) LIGHT MODE IS UNREACHABLE BY CONSTRUCTION
    // =============================================================================================

    public function test_every_selector_in_the_block_is_scoped_to_the_dark_theme(): void
    {
        foreach (self::SHEETS as $sheet) {
            $selectors = $this->selectors($sheet);
            $this->assertNotEmpty($selectors, "$sheet: premise, the block contains selectors");

            foreach ($selectors as $one) {
                $this->assertStringStartsWith('html[data-ss-theme="dark"]', $one,
                    "$sheet: `$one` also applies in light mode, where #000000 on the white card "
                    . 'is already 21:1 and nothing needs changing');
            }
        }
    }

    // =============================================================================================
    //  (c) THE FILLED RETURN PAIR IS OUT OF REACH
    // =============================================================================================

    public function test_every_selector_requires_the_border_class(): void
    {
        foreach (self::SHEETS as $sheet) {
            foreach ($this->selectors($sheet) as $one) {
                $this->assertStringContainsString('.btn.border', $one,
                    "$sheet: `$one` does not require `.border`, so it also recolours the filled "
                    . 'return Approve/Cancel buttons, whose background is the merchant-chosen '
                    . '--theme-color. #000 is 8.42:1 on the #c0a145 preset but 2.33:1 on '
                    . '#48494b: no unguarded foreground is safe there.');
            }
        }
    }

    // =============================================================================================
    //  (d) NO !important, SO Delete STAYS RED
    // =============================================================================================

    public function test_the_block_carries_no_important(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertStringNotContainsString('!important', $this->rulesOnly($this->block($sheet)),
                "$sheet: an !important here outranks `.text-danger`'s own !important "
                . '(style-dark.css:9416) and flattens the Delete icon to the same grey as Show '
                . 'and Edit, destroying the destructive-action signal');
        }
    }

    // =============================================================================================
    //  (e) THE LIGHT-MODE RULE IS UNTOUCHED
    // =============================================================================================

    public function test_the_unguarded_rule_is_left_exactly_as_it_was(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->sheet($sheet);

            $this->assertMatchesRegularExpression(
                '/(?<![\w\]\-])\.order-card \.btn:hover i,\s*\n\.order-card \.btn i \{\s*\n\s*color: #000000;\s*\n\}/',
                $css,
                "$sheet: the unguarded `.order-card .btn i { color: #000000 }` has been edited or "
                . 'scoped. It is CORRECT in light mode (21:1 on the white card); editing it '
                . 'changes an appearance every LTR light-mode merchant already has, and releasing '
                . 'dark mode to style-dark.css:19637 would hand the filled return pair a white '
                . 'icon on the merchant-chosen brand fill.');
        }
    }

    // =============================================================================================
    //  (f) BOTH DIRECTIONS, BYTE-IDENTICAL
    // =============================================================================================

    public function test_the_two_directions_are_byte_identical(): void
    {
        $this->assertSame($this->block(self::SHEETS[0]), $this->block(self::SHEETS[1]),
            'custom.css and rtl-custom.css have drifted inside the P2.5-GRID-ICONS block. '
            . 'rtl-custom.css is loaded INSTEAD of custom.css for an Arabic merchant, so a '
            . 'one-sheet fix reaches only half of them.');
    }

    // =============================================================================================
    //  (g) PREMISE IN THE MARKUP — `.border` really is the discriminator
    // =============================================================================================

    public function test_premise_the_markup_still_distinguishes_the_two_groups_by_border(): void
    {
        $grid = (string) file_get_contents($this->path(self::GRID));

        $this->assertSame(2, preg_match_all('/class="(?:x-3 )?btn btn-sm border me-2"/', $grid, $m),
            'premise: the Show and Edit controls on the order grid card are the transparent '
            . '`btn btn-sm border me-2` pair the new rule is written for');

        // The filled pair the rule must NOT reach.
        $this->assertStringContainsString('btn btn-sm btn-primary return_request', $grid);
        $this->assertStringContainsString('btn btn-sm btn-danger return_request', $grid);

        preg_match_all('/class="btn btn-sm btn-(?:primary|danger) return_request[^"]*"/', $grid, $filled);
        $this->assertCount(2, $filled[0], 'premise: two filled return controls');
        foreach ($filled[0] as $one) {
            $this->assertStringNotContainsString(' border', $one,
                "premise: the filled return control `$one` must not carry `border`, or the new "
                . 'rule would reach it after all');
        }

        // And the Delete control, which keeps its own colour through text-danger.
        $this->assertStringContainsString('ti ti-trash text-danger', $grid);
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-A-06 — the customer activity timeline stays WHITE in the dark admin.
 * =================================================================================================
 *
 * `.my-custom-timeline .tl-box { background: #ffffff }` is declared in both first-party sheets
 * (public/css/custom.css, public/css/rtl-custom.css) and, byte for byte, in both VENDORED dark
 * sheets (public/assets/css/style-dark.css:28010, rtl-style-dark.css:27965) — the host theme never
 * themed the component. So on /customer/{id}/timeline in dark mode every log entry renders as a
 * white card on the host's #22242c page, immediately beside four `.card` siblings on the same view
 * that correctly render `--bs-card-bg: #292A33` (style-dark.css:5006, consumed :5015).
 *
 * THIS IS A CONSISTENCY DEFECT, NOT AN ACCESSIBILITY ONE, and the arithmetic below says so rather
 * than letting the row be credited as a contrast fix. Computed here with the WCAG formula:
 *
 *     today      #293240 (--bs-body-color, identical at line 71 of style.css AND style-dark.css)
 *                on #ffffff                                            12.93:1   fully legible
 *     .time      #505050 on #ffffff                                     8.06:1   fully legible
 *
 * WHICH IS EXACTLY WHY A BACKGROUND-ONLY FIX IS A TRAP — it turns a readable off-theme card into
 * an unreadable on-theme one:
 *
 *     #293240 on #292A33                                                1.10:1
 *     #505050 on #292A33                                                1.77:1
 *
 * TWO MORE TRAPS, both asserted below as negative premises so the repair cannot be simplified into
 * them:
 *
 *   - `.tl-btn` declares no colour in any of the four sheets, so an ink on `.tl-box` INHERITS into
 *     the pastel log-type chips: #eeeff1 on #E0FFF5 measures 1.08:1. The chip needs its own dark
 *     guard, scoped `.tl-box .tl-btn` so it cannot reach order/view.blade.php:619, where the SAME
 *     `.tl-btn` class sits on a dark `.card-header.note-bg-color` and is inked in the OPPOSITE
 *     direction by the shipped P2-04-6 rule.
 *   - the ink must be a LAYER-1 PRIMITIVE. `var(--ss-fg)` / `var(--ss-fg-muted)` re-theme inside a
 *     `data-ss-theme="light"` container — and this project has added that wrapper to several views
 *     — while the background this rule paints does not. Measured: `--ss-grey-900` (what `--ss-fg`
 *     resolves to in a light scope) on the #292A33 this rule just painted is 1.18:1, which is the
 *     regression DarkModalSurfaceContrastTest already ratchets against. That rule is re-asserted
 *     over this block rather than remembered.
 */
class DarkTimelineSurfaceContrastTest extends TestCase
{
    private const CUSTOM_SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    private const DARK_SHEETS = [
        'public/assets/css/style-dark.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    /** The host's dark card — `--bs-card-bg`, style-dark.css:5006. The four siblings on this view. */
    private const CARD_BG = [41, 42, 51];       // #292A33

    /** The host's dark page — `--bs-body-bg`, style-dark.css:72. */
    private const PAGE_BG = [34, 36, 44];       // #22242c

    private const WHITE = [255, 255, 255];

    /** `--bs-body-color`, line 71 of BOTH style.css and style-dark.css. Never re-inked for dark. */
    private const HOST_INK = [41, 50, 64];      // #293240

    /** `.my-custom-timeline .tl-box .time-wrapper .time` today. */
    private const HOST_TIME_INK = [80, 80, 80]; // #505050

    private const GREY_100 = [238, 239, 241];   // --ss-grey-100 #eeeff1, store-syria-ds.css:169
    private const GREY_900 = [26, 29, 35];      // --ss-grey-900 #1a1d23, store-syria-ds.css:182
    private const TIME_INK = [157, 163, 180];   // #9DA3B4 — already ratcheted on #292A33 by
                                                // DarkModalSurfaceContrastTest's FIELD_PLACEHOLD

    /**
     * THE TIMESTAMP'S REAL GROUND, and the correction of a test below that measured the wrong one.
     * P2.5-WAVE-16c
     *
     * `.time` is not transparent. custom.css:1706 gives the span its own CHIP:
     * `.time { display:inline-block; padding:4px 6px; background: rgb(248,248,248); }`. So the
     * timestamp never sits on the card at all -- it sits on a near-white chip, in BOTH themes,
     * because that generic rule had no dark arm.
     *
     * `test_the_timestamp_ink_clears_aa_on_the_dark_card` asserted 5.66:1 of TIME_INK against
     * CARD_BG and PASSED, while the running page measured 2.37:1. It was measuring a ground the
     * component does not have. Found by measure-timeline-pills.cjs, which reads the EFFECTIVE
     * background off the live page instead of the sheet -- the whole reason that tool exists.
     */
    private const TIME_CHIP_LIGHT = [248, 248, 248]; // rgb(248,248,248), custom.css:1706
    private const TIME_CHIP_DARK = [34, 36, 44];     // #22242C — the slab, not a new colour

    /** Every `.tl-btn` pastel fill in the sheet, so the guard is proved on all four, not one. */
    private const CHIP_FILLS = [
        '.tl-btn.licence-btn'  => [224, 255, 245], // #E0FFF5 — the only variant this view renders
        '.tl-btn.order-btn'    => [255, 243, 214], // #FFF3D6
        '.tl-btn.support-btn'  => [255, 241, 241], // #FFF1F1
        '.tl-btn.register-btn' => [236, 255, 228], // #ECFFE4
    ];

    // =============================================================================================
    //  THE PREMISES — the defect is real, and each simplification of the repair is a trap
    // =============================================================================================

    /**
     * If the host theme ever themes this component itself, this slice is redundant and should be
     * DELETED rather than left asserting a dead rule. That is why this fails loudly.
     */
    public function test_premise_the_vendored_dark_theme_still_leaves_the_box_white(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertMatchesRegularExpression(
                '/\.my-custom-timeline\s+\.tl-box\s*\{[^}]*background:\s*#ffffff\s*;/i',
                $css,
                $sheet . ': the host dark theme no longer paints the timeline box white. The '
                . 'premise of this slice has changed -- re-measure the view and rewrite the block '
                . 'in the same commit; do NOT weaken this assertion.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\.my-custom-timeline\s+\.tl-box\s*\{[^}]*background:\s*#ffffff\s*!important/i',
                $css,
                $sheet . ': the host white now carries !important, so the first-party override no '
                . 'longer wins on specificity alone and the block must be re-measured.'
            );

            $this->assertMatchesRegularExpression('/--bs-card-bg:\s*#292A33/i', $css,
                $sheet . ': the host dark card is no longer #292A33, so matching the four .card '
                . 'siblings on this view now needs a different value.');
        }
    }

    public function test_premise_the_first_party_sheets_are_the_other_half_of_the_white(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.my-custom-timeline\s+\.tl-box\s*\{[^}]*background:\s*#ffffff\s*;/i',
                $this->read($sheet),
                $sheet . ': the light declaration this block exists to override in dark mode is '
                . 'gone. If the component was rebuilt, delete this slice instead of keeping it.'
            );
        }
    }

    /**
     * RE-GRADE EVIDENCE. 12.93:1 — the component is fully legible today, so this row is
     * cosmetic/low and must not be counted against a real contrast failure.
     */
    public function test_premise_today_the_white_box_is_legible_so_this_is_not_an_aa_failure(): void
    {
        $this->assertSame(12.93, round($this->ratio(self::HOST_INK, self::WHITE), 2));
        $this->assertSame(8.06, round($this->ratio(self::HOST_TIME_INK, self::WHITE), 2));
    }

    /** 1.10:1 and 1.77:1 — the readable off-theme card becomes an invisible on-theme one. */
    public function test_premise_a_background_only_repair_would_make_the_entry_unreadable(): void
    {
        $this->assertSame(1.10, round($this->ratio(self::HOST_INK, self::CARD_BG), 2),
            'the host ink is never re-inked for dark mode, so the background alone is not enough');
        $this->assertSame(1.77, round($this->ratio(self::HOST_TIME_INK, self::CARD_BG), 2),
            'the timestamp needs its own value for the same reason');
    }

    /** 1.08:1 — F126 in mirror image, one level down, if the chip guard is dropped. */
    public function test_premise_an_unguarded_box_ink_would_destroy_all_four_chips(): void
    {
        foreach (self::CHIP_FILLS as $chip => $fill) {
            $this->assertLessThan(1.5, $this->ratio(self::GREY_100, $fill), sprintf(
                '%s: the box ink inherits into the pastel chip at %.2f:1 unless it is guarded',
                $chip, $this->ratio(self::GREY_100, $fill)));
        }
    }

    /** 1.18:1 — what a re-theming token would have produced on the surface this rule paints. */
    public function test_premise_a_re_theming_ink_token_would_have_regressed_the_box(): void
    {
        $this->assertLessThan(1.5, $this->ratio(self::GREY_900, self::CARD_BG), sprintf(
            'var(--ss-fg) resolves to --ss-grey-900 inside a data-ss-theme="light" container and '
            . 'lands at %.2f:1 on the still-dark box', $this->ratio(self::GREY_900, self::CARD_BG)));
    }

    // =============================================================================================
    //  THE ARITHMETIC OF THE REPAIR — computed here, not copied from a design note
    // =============================================================================================

    /** 12.39:1 on #292A33, 13.46:1 on the #22242c page behind it. */
    public function test_the_box_ink_clears_aa_on_the_dark_card(): void
    {
        $this->assertSame(12.39, round($this->ratio(self::GREY_100, self::CARD_BG), 2));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::GREY_100, self::PAGE_BG));
    }

    /**
     * 5.66:1 — a pairing DarkModalSurfaceContrastTest already pins on this surface. KEPT, but it
     * is NOT the timestamp's ground: see TIME_CHIP_LIGHT. What it proves is that the ink is right
     * for the card, which matters because the chip's dark arm sits between the two.
     */
    public function test_the_timestamp_ink_clears_aa_on_the_dark_card(): void
    {
        $this->assertSame(5.66, round($this->ratio(self::TIME_INK, self::CARD_BG), 2));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::TIME_INK, self::CARD_BG));
    }

    /**
     * PREMISE: the chip is real and un-themed. If the host ever drops that background, the dark
     * arm below becomes redundant and should be DELETED, not left painting a dead rule.
     */
    public function test_premise_the_timestamp_carries_its_own_light_chip(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/^\.time\s*\{[^}]*background:\s*rgb\(248,\s*248,\s*248\)\s*;/mi',
                $this->read($sheet),
                $sheet . ': the generic `.time` chip is gone, so the dark arm for it is now dead code.');
        }
    }

    /**
     * THE DEFECT, stated as the live measurement found it: #9DA3B4 on rgb(248,248,248) is 2.37:1.
     * The dark arm darkened the INK and left the CHIP light, which is worse than doing neither --
     * the pre-fix pairing (#505050 on #F8F8F8) was 7.59:1 and legible.
     */
    public function test_the_unarmed_chip_is_the_failure_this_slice_repairs(): void
    {
        $this->assertSame(2.37, round($this->ratio(self::TIME_INK, self::TIME_CHIP_LIGHT), 2));
        $this->assertLessThan(4.5, $this->ratio(self::TIME_INK, self::TIME_CHIP_LIGHT));
        // and the ink-only arm made it worse than the untouched host pairing
        $this->assertGreaterThan(
            $this->ratio(self::TIME_INK, self::TIME_CHIP_LIGHT),
            $this->ratio(self::HOST_TIME_INK, self::TIME_CHIP_LIGHT));
    }

    /** 6.14:1. The chip recesses to the slab, mirroring light where it is darker than the card. */
    public function test_the_timestamp_ink_clears_aa_on_its_own_dark_chip(): void
    {
        $this->assertSame(6.14, round($this->ratio(self::TIME_INK, self::TIME_CHIP_DARK), 2));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::TIME_INK, self::TIME_CHIP_DARK));
    }

    /**
     * The chip must be DARKER than the card it sits on, as it is in light (#F8F8F8 on #ffffff).
     * A chip lighter than its card reads as raised, which inverts the component's own hierarchy.
     */
    public function test_the_dark_chip_stays_recessed_below_its_card(): void
    {
        $this->assertLessThan(
            $this->luminance(self::CARD_BG), $this->luminance(self::TIME_CHIP_DARK));
        $this->assertLessThan(
            $this->luminance(self::WHITE), $this->luminance(self::TIME_CHIP_LIGHT));
    }

    /** Both sheets actually carry the arm. This is the half-fix guard, by file. */
    public function test_both_sheets_arm_the_timestamp_chip(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+\.tl-box\s+'
                . '\.time-wrapper\s+\.time\s*\{[^}]*background:\s*#22242C\s*;/i',
                $this->read($sheet),
                $sheet . ': the timestamp chip has no dark background, so #9DA3B4 lands on '
                . 'rgb(248,248,248) at 2.37:1 for every merchant this sheet serves.');
        }
    }

    /**
     * A timestamp that reads as loudly as the entry is not a timestamp. Asserted so a future
     * "contrast fix" cannot resolve a ratio by flattening the hierarchy.
     */
    public function test_the_timestamp_stays_subordinate_to_the_entry_text(): void
    {
        $this->assertGreaterThan(1.8,
            $this->luminance(self::GREY_100) / $this->luminance(self::TIME_INK));
    }

    /** 15.31:1 - 16.07:1 on the four fills, against 1.08:1 unguarded. */
    public function test_the_chip_guard_clears_aa_on_every_pastel_fill(): void
    {
        $expected = [
            '.tl-btn.licence-btn'  => 15.91,
            '.tl-btn.order-btn'    => 15.31,
            '.tl-btn.support-btn'  => 15.36,
            '.tl-btn.register-btn' => 16.07,
        ];

        foreach (self::CHIP_FILLS as $chip => $fill) {
            $ratio = $this->ratio(self::GREY_900, $fill);
            $this->assertGreaterThanOrEqual(4.5, $ratio);
            $this->assertSame($expected[$chip], round($ratio, 2), $chip);
        }
    }

    // =============================================================================================
    //  THE BLOCK ITSELF — present, in both directions, and unable to reach anything else
    // =============================================================================================

    public function test_both_stylesheets_carry_every_rule_in_the_block(): void
    {
        $required = [
            'the box surface and its ink' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+\.tl-box\s*(,|\{)'
                . '[^}]*background:\s*#292A33\s*;[^}]*color:\s*var\(--ss-grey-100/is',
            'the timestamp' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+\.tl-box\s+'
                . '\.time-wrapper\s+\.time\s*(,|\{)[^}]*color:\s*#9DA3B4\s*;/is',
            'the chip guard' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+\.tl-box\s+'
                . '\.tl-btn\s*(,|\{)[^}]*color:\s*var\(--ss-grey-900/is',
            'the rail' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+\.timeline:before\s*,/is',
            'the connector stub' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+'
                . '\.support-time::before\s*(,|\{)[^}]*background:\s*#3E3F4A\s*;/is',
            'the node, BOTH its fill and its 3px ring' =>
                '/html\[data-ss-theme="dark"\]\s+body\s+\.my-custom-timeline\s+'
                . '\.support-time::after\s*(,|\{)[^}]*background-color:\s*#292A33\s*;'
                . '[^}]*border-color:\s*#3E3F4A\s*;/is',
        ];

        foreach (self::CUSTOM_SHEETS as $sheet) {
            $block = $this->block($sheet);

            foreach ($required as $what => $pattern) {
                $this->assertMatchesRegularExpression($pattern, $block,
                    $sheet . ' is missing ' . $what . ' in the dark timeline block');
            }
        }
    }

    /**
     * The Arabic admin loads rtl-custom.css INSTEAD of custom.css, so a fix in one reaches half
     * the merchants. The block carries no directional property, so it is identical by
     * construction and any drift is a mistake.
     */
    public function test_the_two_directions_carry_byte_identical_blocks(): void
    {
        $this->assertSame($this->block(self::CUSTOM_SHEETS[0]), $this->block(self::CUSTOM_SHEETS[1]),
            'custom.css and rtl-custom.css have drifted inside the dark timeline block');
    }

    /**
     * LIGHT MODE MUST BE UNREACHABLE BY CONSTRUCTION. custom.css is served in both themes, so an
     * unscoped rule here paints the LIGHT admin's white card dark.
     */
    public function test_every_selector_in_the_block_is_scoped_to_dark(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $selectors = $this->selectors($sheet);

            foreach ($selectors as $one) {
                $this->assertStringStartsWith('html[data-ss-theme="dark"]', $one,
                    $sheet . ': `' . $one . '` is not scoped to dark mode, so it repaints the '
                    . 'LIGHT admin too.');
            }
        }
    }

    /**
     * `.tl-btn`, `.time` and `.timeline` are SHARED class names, so every selector has to name a
     * container. The census below is the reason, asserted rather than trusted.
     *
     * TWO CONTAINER NAMES ARE ACCEPTED, and the second is not a loophole.  P2.5-WAVE-16c
     * `.my-custom-timeline` is CONDITIONAL in the view:
     *
     *     <div @if (count($logs) > 0) class="my-custom-timeline" @endif>
     *
     * so a rule scoped under it silently misses the case where the customer has registered but has
     * no activity -- precisely the state P2.5-WAVE-16 added an empty state for, and the state the
     * dev fixture renders. `.custom-timeline-inner` is the UNCONDITIONAL container, and the premise
     * below asserts it is unique to this one view, so unlike `.timeline` it cannot bleed.
     */
    public function test_every_selector_in_the_block_names_the_timeline_container(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            foreach ($this->selectors($sheet) as $one) {
                $this->assertTrue(
                    str_contains($one, '.my-custom-timeline') || str_contains($one, '.custom-timeline-inner'),
                    $sheet . ': `' . $one . '` names neither timeline container, and .tl-btn, '
                    . '.time and .timeline all appear on other surfaces.'
                );
            }
        }
    }

    /**
     * PREMISE for the rule above: the unconditional container really is this screen's alone, so
     * allowing it as a scope cannot let the block repaint anything else.
     */
    public function test_the_unconditional_container_is_unique_to_this_screen(): void
    {
        $views = glob(dirname(__DIR__, 3) . '/resources/views/**/*.blade.php', GLOB_BRACE) ?: [];
        $hits = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/resources/views', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'custom-timeline-inner')) {
                $hits[] = basename($file->getPathname());
            }
        }

        $this->assertSame(['timeline.blade.php'], $hits,
            '`custom-timeline-inner` is no longer unique to the customer timeline, so scoping the '
            . 'dark block to it could now repaint another surface: ' . implode(', ', $hits));
    }

    public function test_the_class_names_this_block_touches_really_are_shared(): void
    {
        $views = $this->viewsContaining('tl-btn');
        $this->assertCount(6, $views, 'the .tl-btn census moved: ' . implode(', ', $views));
        $this->assertContains('resources/views/order/view.blade.php', $views,
            'order/view.blade.php is the merchant admin page where the SAME .tl-btn class is '
            . 'inked in the opposite direction by P2-04-6, which is why the guard is scoped to '
            . '.tl-box rather than written bare.');

        // MEASURED CORRECTION to the review note that claimed `.timeline` is also in
        // front_end/common/offer.blade.php: that view carries `deal-timeline`, which the exact
        // class selector `.timeline` does not match. `.time` is the second genuinely shared leaf
        // class -- 7 views, including the merchant admin order detail page and its note partial.
        $time = $this->viewsContaining('class="time"');
        $this->assertContains('resources/views/order/view.blade.php', $time,
            'the .time class is shared with the merchant admin order detail page, which is why '
            . 'the timestamp rule names the container and the box');
        $this->assertGreaterThan(1, count($time), 'premise: .time really is a shared class name');

        $this->assertSame(['resources/views/customer/timeline.blade.php'],
            $this->viewsContaining('my-custom-timeline'),
            'the container class is no longer confined to one view, so the blast radius of this '
            . 'block has grown and the measurement must be redone.');
    }

    /**
     * THE GUARD MUST NOT REACH THE ORDER DETAIL PAGE. Every selector that mentions a shared leaf
     * class must also be inside `.tl-box`.
     */
    public function test_the_chip_guard_cannot_reach_the_order_detail_page(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            foreach ($this->selectors($sheet) as $one) {
                if (str_contains($one, '.tl-btn')) {
                    $this->assertStringContainsString('.tl-box', $one,
                        $sheet . ': `' . $one . '` inks .tl-btn outside the timeline box, so it '
                        . 'also hits order/view.blade.php:619.');
                }
            }
        }
    }

    /**
     * The same lesson DarkModalSurfaceContrastTest ratchets, re-asserted here: layer-1 primitives
     * only, because the surface this block paints does not re-theme.
     */
    public function test_the_block_uses_no_ink_token_that_re_themes(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $block = (string) preg_replace('#/\*.*?\*/#s', '', $this->block($sheet));

            preg_match_all('/color:\s*([^;]+);/i', $block, $m);
            $this->assertNotEmpty($m[1], $sheet . ': no colour declaration was found in the '
                . 'block, so this check could not take its measurement and must not pass.');

            foreach ($m[1] as $value) {
                $this->assertDoesNotMatchRegularExpression('/var\(\s*--ss-fg(-muted|-subtle)?\s*[,)]/i',
                    $value,
                    $sheet . ' declares `' . trim($value) . '`. That token re-themes inside a '
                    . 'data-ss-theme="light" container while #292A33 does not -- 1.18:1 when it '
                    . 'was tried. Use a layer-1 primitive.');
            }
        }
    }

    /**
     * NEGATIVE CONTROL: the repair must take nothing away. The `.end-date` pills carry their own
     * `color: #ffffff` and are immune to inheritance; re-inking them would put dark ink on a pink
     * pill, so this block must leave them alone whatever else changes.
     *
     * THE SECOND HALF OF THIS DOCBLOCK USED TO SAY their own AA failure was a still-open row.
     * That is no longer true and the assertion below never depended on it: re-measured
     * 2026-11-07, all eight grounds those pills can take give 4.53:1 to 6.55:1 against their
     * white ink, and the label is 10px/600 so 4.5:1 is the bar. The numbers are recorded at
     * `public/css/custom.css:1972`, beside the colours. The assertion stands unchanged -- what
     * it guards is that THIS block does not reach those pills, which is true regardless.
     */
    public function test_the_block_does_not_touch_the_end_date_pills(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertStringNotContainsString('.end-date', $this->block($sheet),
                $sheet . ': this block must not re-ink the .end-date pills.');
        }

        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.my-custom-timeline\s+\.tl-box\s+\.end-date\s*\{[^}]*color:\s*#ffffff/i',
                $this->read($sheet),
                $sheet . ': the pill no longer declares its own ink, so it is now exposed to '
                . 'inheritance from .tl-box and this block must be re-measured.');
        }
    }

    // ------------------------------------------------------------------ helpers

    /** The block this slice owns, bounded by its own markers. */
    private function block(string $relative): string
    {
        $css = $this->read($relative);

        $marker = strpos($css, 'P2.5-A-06');
        $this->assertNotFalse($marker, $relative . ' has no P2.5-A-06 block');

        // BACK UP TO THE COMMENT OPENER. The marker sits INSIDE the banner comment, so slicing
        // from it would hand the prose above the rules to the comment-stripping regex with no
        // opener to match, and every sentence would reach the selector parser as if it were CSS.
        $start = strrpos(substr($css, 0, $marker), '/*');
        $this->assertNotFalse($start, $relative . ' has no comment opener before the marker');

        $end = strpos($css, '/* end P2.5-A-06', $start);
        $this->assertNotFalse($end, $relative . ' has no P2.5-A-06 end marker, so the block is '
            . 'unbounded and every check against it would read the rest of the sheet.');

        return substr($css, $start, $end - $start);
    }

    /** @return list<string> every selector in the block, comments stripped first. */
    private function selectors(string $relative): array
    {
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $this->block($relative));

        preg_match_all('/([^{}]+)\{/', $block, $m);

        $out = [];
        foreach ($m[1] as $group) {
            foreach (explode(',', $group) as $sel) {
                $sel = trim((string) preg_replace('/\s+/', ' ', $sel));
                if ($sel !== '') {
                    $out[] = $sel;
                }
            }
        }

        $this->assertNotEmpty($out, $relative . ': no selector was found in the block. The markers '
            . 'may have moved -- this check must not report success when it could not measure.');

        return $out;
    }

    /** @return list<string> repository-relative Blade paths, sorted. */
    private function viewsContaining(string $needle): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/resources/views', \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($found);

        return $found;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
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
        $channel = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }
}

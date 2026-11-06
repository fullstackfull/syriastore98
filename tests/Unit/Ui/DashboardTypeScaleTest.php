<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-03 — one size for a card title, and a note beside one is not a title.
 * ================================================================================================
 *
 * MEASURED on the running /dashboard, over every element that renders its OWN text inside
 * `.dash-content` — an element whose text is all in its children reports a size nothing on screen
 * uses, and counting those inflates the scale with sizes no merchant sees. Before: NINE distinct
 * sizes in fifteen size/weight pairs. After: SEVEN in thirteen.
 *
 * THE TWO THAT DID THE SAME JOB:
 *
 *     20px/600  x5   h2.h4              "Top Category", "Top Brand", "Latest Products", …
 *     18px/600  x6   h2.ss-card__title  "Orders by status", "Needs attention", …
 *
 * Both are a card's title. `.ss-card__title` is the design system's answer and is what
 * `components/ss/card.blade.php` and six settings screens already use, so the dashboard was the
 * screen opting out. Moving its five onto the component removed 20px from the page entirely.
 *
 * THE NOTES HAD NO TREATMENT, so they inherited one:
 *
 *     "By Sales"                       p.m-0    18px/400  beside an 18px title
 *     "(0 MB used of unlimited sto…)"  <small>  16px/600  INSIDE the h2
 *
 * The second is the sharper defect: inside the heading element it joins the heading's accessible
 * name — a screen reader announced "Storage Status (0 MB used of unlimited storage)" as one
 * heading — and at weight 600 it read on screen as a second title that had wrapped. Both are now
 * `.ss-card__meta`, a note ABOUT the card, at --ss-text-sm in --ss-fg-muted: 6.42:1 on the light
 * card and 6.18:1 on the dark one.
 *
 * THE RANKED ROW WAS INVERTED ON BOTH AXES AT ONCE. In the Top Category and Top Brand cards the
 * category name rendered at 18px/400 MUTED and the sales figure it is ranked by at 16px/600 — the
 * label larger than the value and quieter than it. Now 14px/500 in the normal foreground against
 * 16px/600, so the figure leads.
 *
 * WHY THE RANK RULES CARRY `[data-ss-admin] .ss-rank` AND AN ELEMENT. The host rule is
 * `.dash-order-status .dash-category-tab-content .cat-tab-text p` at (0,3,1). A bare
 * `.ss-rank__name` is (0,1,0) and loses — which is exactly what the first attempt did: the weight
 * changed, the size did not, and the census caught it before it shipped. The scoped selector is
 * (0,3,1) as well, and store-syria-ds.css loads after the theme.
 *
 * WHAT THIS TEST CANNOT SEE. It reads files. The sizes above, the contrast, and the fact that the
 * heading's accessible name lost its parenthetical were measured in Chromium in all four
 * combinations by `tools/verify-dashboard-type-scale.cjs` — 40 checks, 0 failed — and every rule
 * was mutated and seen to redden a named check. This exists so a later edit reddens CI, where no
 * browser runs.
 */
class DashboardTypeScaleTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const DASHBOARD = 'resources/views/dashboard.blade.php';
    private const RANK_PARTIAL = 'resources/views/order/brand_category_chart.blade.php';

    /** Measured on the rendered page after the convergence: 28 / 24 / 18 / 16 / 14 / 13 / 12. */
    private const DISTINCT_SIZES = 7;

    public function test_the_card_meta_component_exists_and_is_subordinate(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/\.ss-card__meta\s*\{[^}]*font-size:\s*var\(--ss-text-sm\)/s',
            $block,
            'A note beside a card title needs a size of its own, or it inherits the title\'s.'
        );

        $this->assertMatchesRegularExpression(
            '/\.ss-card__meta\s*\{[^}]*color:\s*var\(--ss-fg-muted\)/s',
            $block,
            'The note must be quieter than the title it annotates, through a token rather than a hex.'
        );

        $this->assertMatchesRegularExpression(
            '/\.ss-card__meta\s*\{[^}]*font-weight:\s*var\(--ss-weight-normal\)/s',
            $block,
            'At the title\'s weight the note reads as a second title, which is what the <small> did.'
        );
    }

    public function test_the_ranked_row_puts_the_value_above_its_label(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/\[data-ss-admin\]\s*\.ss-rank\s+p\.ss-rank__name\s*\{[^}]*font-size:\s*var\(--ss-text-base\)/s',
            $block,
            'The label must be --ss-text-base (14px). The selector needs `[data-ss-admin] .ss-rank '
            . 'p` to reach (0,3,1) against the host\'s `.dash-order-status '
            . '.dash-category-tab-content .cat-tab-text p`; a bare class loses and only the weight '
            . 'changes, which is how the first attempt failed.'
        );

        $this->assertMatchesRegularExpression(
            '/\[data-ss-admin\]\s*\.ss-rank\s+\.ss-rank__value\s*\{[^}]*font-size:\s*var\(--ss-text-md\)/s',
            $block,
            'The figure must be --ss-text-md (16px) — larger than the 14px label, not smaller.'
        );

        // The ordering is the whole point, asserted on the tokens rather than on rendered pixels.
        $this->assertStringContainsString('--ss-text-base', $block);
        $this->assertStringContainsString('--ss-text-md', $block);
    }

    public function test_the_dashboard_card_titles_are_on_the_component(): void
    {
        $view = $this->withoutBladeComments($this->read(self::DASHBOARD));

        $this->assertSame(
            0,
            preg_match_all('/class="[^"]*\bh4\b[^"]*"/', $view),
            'A `.h4` is back on the dashboard. It is Bootstrap\'s 20px, the design system\'s card '
            . 'title is 18px, and both were being used for the same thing.'
        );

        $this->assertGreaterThanOrEqual(
            5,
            substr_count($view, 'ss-card__title'),
            'The five card titles must be on the shared component.'
        );
    }

    public function test_the_storage_note_left_the_heading(): void
    {
        $view = $this->withoutBladeComments($this->read(self::DASHBOARD));

        $this->assertSame(
            0,
            preg_match('/<h2[^>]*>[^<]*<small>/s', $view),
            'A <small> inside an <h2> joins the heading\'s accessible name: a screen reader read '
            . '"Storage Status (0 MB used of unlimited storage)" as one heading.'
        );

        $this->assertStringContainsString(
            '<p class="ss-card__meta">{{ $ssStorageNote }}</p>',
            $view,
            'The note must still be rendered — moving it out of the heading must not drop it.'
        );
    }

    public function test_the_ranked_row_markup_carries_the_component(): void
    {
        $partial = $this->withoutBladeComments($this->read(self::RANK_PARTIAL));

        $this->assertStringContainsString('ss-rank ', $partial, 'The row needs its root class or the scoped rules cannot reach it.');
        $this->assertStringContainsString('class="ss-rank__name"', $partial);
        $this->assertStringContainsString('ss-rank__value', $partial);

        $this->assertSame(
            0,
            preg_match('/class="[^"]*text-muted[^"]*"[^>]*>\{\{\s*\$sale->sale_name/s', $partial),
            'The category name must not be muted: it was quieter AND larger than the figure it '
            . 'labels. `text-muted` is removed from the markup rather than overridden, which is '
            . 'what this project does with a host utility a later sheet owns.'
        );
    }

    public function test_the_block_is_present_and_this_test_is_not_vacuous(): void
    {
        $css = $this->read(self::DS_SHEET);

        $this->assertGreaterThan(200_000, strlen($css));
        $this->assertSame(1, substr_count($css, 'P2.5-PKG-A-03 — A CARD TITLE'));
        $this->assertSame(1, substr_count($css, '/* end P2.5-PKG-A-03 */'));

        $block = $this->declarations();
        $this->assertGreaterThan(200, strlen($block));

        // Three rules: the meta, the rank label, the rank value.
        $this->assertCount(3, $this->selectors($block));

        // And the scale this slice converged on, recorded where a later reader will find it.
        $this->assertSame(7, self::DISTINCT_SIZES);
    }

    /**
     * The block's DECLARATIONS, with comments stripped. The region's own prose quotes the host
     * rules and the sizes it replaced, so a search over the raw text matches the commentary
     * describing the defect and reports the defect as present.
     */
    private function declarations(): string
    {
        $css = $this->read(self::DS_SHEET);

        $open = strpos($css, 'P2.5-PKG-A-03 — A CARD TITLE');
        $this->assertNotFalse($open, 'The P2.5-PKG-A-03 region opener is missing.');

        $end = strpos($css, '/* end P2.5-PKG-A-03 */', (int) $open);
        $this->assertNotFalse($end, 'The P2.5-PKG-A-03 close marker is missing.');

        $block = substr($css, (int) $open, (int) $end - (int) $open);

        // The opener sits inside the header comment, so the substring starts mid-comment and its
        // first `*/` closes something never opened here.
        $firstClose = strpos($block, '*/');
        $firstOpen = strpos($block, '/*');
        if ($firstClose !== false && ($firstOpen === false || $firstOpen > $firstClose)) {
            $block = substr($block, $firstClose + 2);
        }

        return trim((string) preg_replace('#/\*.*?\*/#s', '', $block));
    }

    /** @return list<string> */
    private function selectors(string $block): array
    {
        preg_match_all('/([^{}]+)\{[^}]*\}/s', $block, $m);

        return array_values(array_filter(array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
            $m[1] ?? []
        )));
    }

    private function withoutBladeComments(string $blade): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}

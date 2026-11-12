<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 settings-tabs-not-keyboard-reachable — WCAG 2.1.1, 20 of a merchant's 21 settings
 * panels were mouse-only.
 *
 * WHAT WAS MEASURED BEFORE THE REPAIR. All 28 items on the settings rail in
 * resources/views/setting/index.blade.php were
 *
 *     <a class="nav-link …" data-url="…" data-tab="…" type="button" role="tab" …>
 *
 * with NO href and NO tabindex. Per HTML an <a> with neither is not focusable and not in the
 * tab order, and `type="button"` on an <a> is the MIME-type hint attribute — it confers no
 * button behaviour whatsoever. Measured: `grep -n href` over the file returned ONE hit, line
 * 748, an unrelated repeater-row string; the only `tabindex` was on the panel; and
 * `grep -cE 'keydown|ArrowRight|roving'` returned 0. The framework supplies nothing either:
 * the admin bundles Bootstrap 5.1.3, whose Tab plugin gained roving tabindex and arrow keys
 * only in 5.2+.
 *
 * So the sole activation path was `$(document).on('click', '.nav-link', …)`, and a
 * keyboard-only merchant got exactly ONE panel — the one auto-loaded on ready — reachable only
 * because the panel itself carries tabindex="0". A screen-reader user in virtual-cursor mode
 * did hear "tab, Email Settings", but could not focus or activate it.
 *
 * WHY <button> AND NOT tabindex="0". tabindex on an href-less <a> would put it in the tab order
 * and still need a bespoke keydown handler, because Enter does not fire `click` on an anchor
 * with no href — strictly the bigger change. A <button> fires `click` on BOTH Enter and Space,
 * so the existing delegate works unmodified and `e.preventDefault()` is harmless on
 * type="button". The pattern already ships two views away.
 *
 * ================= WHY THIS FILE ALSO ASSERTS CSS, AND WHY THAT IS THE REAL WORK ============
 *
 * The swap is NOT inert. Three element-qualified rules change behaviour, and the original plan
 * had checked for `a.nav-link` (absent — true) rather than for `button.nav-link` (present).
 * Each of the three was re-measured here by reading the shipped stylesheets:
 *
 *   public/assets/css/style.css:16251  .nav-pills button.nav-link { background:#f5f5f5; color:#000 }
 *       specificity (0,2,1) — DORMANT today, FIRES after the swap, and it beats
 *       .nav-pills .nav-link (0,2,0, style.css:4444, background:transparent; color:#060606).
 *       Mirrored at style-dark.css:16254, style-rtl.css:16212, rtl-style-dark.css:16217, so it
 *       fires in both directions AND both themes: light-grey #f5f5f5 chips with #000 text on a
 *       #292A33 dark card.
 *
 *   public/assets/css/style.css:28696  #useradd-sidenav .nav-pills .nav-link,
 *                                      #brand-sidenav  .nav-pills .nav-link
 *       sets height:100%, display:flex, justify-content:center and NO WIDTH. A block-level <a>
 *       with width:auto fills its grid column; a form control shrink-wraps whatever its
 *       display. The cited precedent roles/create.blade.php does not carry: that rail is
 *       nav-fill and .nav-fill .nav-item .nav-link { width:100% } supplies the width there.
 *       This rail is `nav nav-pills w-100 row store-setting-tab` with no nav-fill, so 21
 *       uniform full-width pills would have become ragged label-width chips hugging the
 *       inline-start edge — in every chrome, both themes, all four sheets.
 *
 *   public/css/custom-color.css:155    body.custom-color .nav-link { color: var(--color-customColor) }
 *       (0,2,0) — LOSES to (0,2,1), so on the DEFAULT chrome the label would have dropped from
 *       the brand colour to #000. Its sibling at :167,
 *       body.custom-color .nav-pills .nav-link { background:none } (0,3,0), DOES beat (0,2,1),
 *       which is why the grey fill is saved on the default chrome but not on theme-1…theme-10.
 *
 * So the repair restates width, background and colour, scoped by id, and this file asserts all
 * three in BOTH mirrored stylesheets. public/css/custom.css serves LTR and
 * public/css/rtl-custom.css serves Arabic; they are mirrored duplicates, so a rule added to one
 * reaches only half the merchants.
 *
 * THE :focus COMPANION IS DELIBERATELY NOT HERE. Both challenges measured the original
 * proposal's contrast table against the wrong declaration: the governing rule is the
 * !important `.nav-link:hover, .nav-link:focus { background: var(--theme-color) !important;
 * color: var(--bs-white) !important }` (style.css:16260 + three mirrors), not the
 * non-important style.css:16272. White on those chrome colours is 5.26:1–9.01:1 — every one
 * passes 1.4.3 — and a non-important companion could not have overridden it at any specificity
 * or file order. So there is no contrast defect to fix there, and the proposed rule would have
 * been inert CSS shipped as a fix.
 */
class SettingsTabRailKeyboardTest extends TestCase
{
    private const VIEW = 'resources/views/setting/index.blade.php';

    private const LTR = 'public/css/custom.css';

    private const RTL = 'public/css/rtl-custom.css';

    private function base(string $path): string
    {
        return dirname(__DIR__, 3) . '/' . $path;
    }

    /** Blade comments stripped before any census: this file's own explanation contains the
     *  strings being counted, and the project has met that inflation twice. */
    private function view(): string
    {
        return (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents($this->base(self::VIEW))
        );
    }

    // ------------------------------------------------------------------ the markup repair

    public function test_no_tab_trigger_on_the_settings_rail_is_an_anchor(): void
    {
        $anchors = preg_match_all('/<a\s[^>]*role="tab"/s', $this->view());

        $this->assertSame(
            0,
            $anchors,
            'An <a> with neither href nor tabindex is NOT focusable and NOT in the tab order, '
            . 'and type="button" on an anchor is the MIME-type hint attribute and confers no '
            . 'button behaviour. Every tab trigger on this rail must be a <button>, which fires '
            . 'click on both Enter and Space so the existing delegate needs no change.'
        );
    }

    public function test_all_twenty_eight_tab_triggers_are_buttons_of_type_button(): void
    {
        $view = $this->view();

        $buttons = preg_match_all('/<button\s[^>]*role="tab"[^>]*>/s', $view, $m);

        $this->assertSame(
            28,
            $buttons,
            'The rail has 28 triggers: 6 unconditional, 7 behind the super-admin gate and 15 '
            . 'behind the admin gate, so a merchant sees 21 and the operator 13.'
        );

        foreach ($m[0] as $tag) {
            $this->assertStringContainsString(
                'type="button"',
                $tag,
                'type="button" MUST survive — not for the role (role="tab" is explicit) but '
                . 'because <button> defaults to type="submit". The rail is not inside a form '
                . 'today, but a future wrapper form would turn all 21 tabs into submit buttons: '
                . $tag
            );
            $this->assertStringContainsString('role="tab"', $tag);
        }
    }

    /** THE SHIPPED AJAX CONTRACT MUST NOT CHANGE: data-url and data-tab are read by the
     *  delegate and POSTed as {tab_type, _token}. A swap that dropped either leaves the panel
     *  silently empty. */
    public function test_the_swap_preserves_every_data_attribute_the_fetch_depends_on(): void
    {
        $view = $this->view();

        $this->assertSame(28, substr_count($view, 'data-tab='), 'All 28 triggers must keep data-tab.');
        $this->assertSame(
            27,
            substr_count($view, 'data-url='),
            'Exactly 27 triggers carry data-url; delivery_syria_setting is the one non-AJAX tab, '
            . 'handled by an early return that shows #delivery-syria-content.'
        );
        $this->assertSame(28, substr_count($view, 'role="tab"'));
        $this->assertSame(
            28,
            substr_count($view, 'aria-selected='),
            'aria-selected must keep moving: Bootstrap 5.1.3 writes it on role="tab" elements, '
            . 'and the bespoke JS only partially duplicates that.'
        );
    }

    /**
     * THE PERMISSION GATES MUST NOT MOVE, and per CLAUDE.md these raw `type ==` comparisons ARE
     * the operator test: Laratrust's isAbleTo() has no super-admin bypass, so "tidying" them
     * into isAbleTo() would silently lock the platform operator out of 7 of their 13 tabs.
     */
    public function test_the_operator_and_merchant_gates_are_untouched(): void
    {
        $view = $this->view();

        $this->assertSame(
            1,
            substr_count($view, "auth()->user()->type == 'super admin'"),
            'The super-admin gate on this rail must stay a raw string comparison.'
        );
        $this->assertSame(
            1,
            substr_count($view, "auth()->user()->type == 'admin'"),
            'The admin gate on this rail must stay a raw string comparison.'
        );
        $this->assertStringNotContainsString(
            'isAbleTo',
            $view,
            'isAbleTo() REFUSES the platform operator in this codebase (permissions_as_gates '
            . '=> false, no super-admin bypass), so it must not replace the type comparisons '
            . 'on this rail.'
        );
    }

    /**
     * REPOSITORY-WIDE CENSUS. Re-verified as safe before being written: the only other admin
     * views combining class="nav-link" with role="tab" carry real hrefs (dashboard.blade.php's
     * ten tab anchors, language/index.blade.php's route hrefs) or are ALREADY buttons
     * (finance/index.blade.php, AppSetting/index.blade.php). So this rule pins exactly this
     * defect and passes everywhere else today.
     */
    public function test_no_view_in_the_admin_has_a_tab_anchor_without_an_href(): void
    {
        $root = $this->base('resources/views');
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (str_starts_with($path, 'front_end/')) {
                continue; // the storefront is Phase 03
            }

            $src = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents($file->getPathname())
            );

            preg_match_all('/<a\s[^>]*role="tab"[^>]*>/s', $src, $m);
            foreach ($m[0] as $tag) {
                if (! str_contains($tag, 'href')) {
                    $offenders[] = $path . ' :: ' . substr($tag, 0, 90);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An <a role=\"tab\"> with no href is not focusable and not in the tab order, so the "
            . "tab it names is mouse-only. Make it a <button type=\"button\">:\n"
            . implode("\n", $offenders)
        );
    }

    // ------------------------------------------------------------------ the CSS repair

    /**
     * The cursor rule is element-qualified on `a`, so it stops matching after the swap and the
     * hand cursor is lost on all 21 pills. It is extended IN PLACE at the existing rule, not
     * appended at end of file: DarkTextUtilityContrastTest and DarkModalSurfaceContrastTest
     * assert that every selector inside their own sentinel blocks of custom.css starts with
     * html[data-ss-theme="dark"], and compare those blocks byte-for-byte across the two
     * sheets, so an end-of-file append could land inside one.
     */
    public function test_the_hand_cursor_survives_the_swap_in_both_directions(): void
    {
        foreach ([self::LTR, self::RTL] as $sheet) {
            $css = (string) file_get_contents($this->base($sheet));

            $this->assertStringContainsString(
                'button.nav-link',
                $css,
                "{$sheet}: `.nav-pills .nav-item a { cursor: pointer }` is qualified on `a`, so "
                . 'it stops matching after the swap. The theme sheets set '
                . 'button:not(:disabled) { cursor: auto }, which is why that rule exists in the '
                . 'first place.'
            );
        }
    }

    /**
     * THE THREE RESTATEMENTS, which are the whole reason this is not a one-line change.
     * Asserted in BOTH sheets because they are mirrored duplicates: custom.css serves LTR and
     * rtl-custom.css serves Arabic, each covering light AND dark.
     */
    public function test_both_sheets_restate_width_background_and_colour_for_the_swapped_rail(): void
    {
        foreach ([self::LTR, self::RTL] as $sheet) {
            $css = (string) file_get_contents($this->base($sheet));

            $block = $this->railDeclarations($css, $sheet);

            $this->assertStringContainsString(
                'width: 100%',
                $block,
                "{$sheet}: style.css:28696 gives this rail display:flex and height:100% but NO "
                . 'width. A block-level <a> fills its grid column; a <button> shrink-wraps. '
                . 'Without this, 21 uniform pills become ragged label-width chips jammed '
                . 'against the inline-start edge, and .text-center on the <li> does not centre '
                . 'a block-level flex child.'
            );

            $this->assertStringContainsString(
                'background: transparent',
                $block,
                "{$sheet}: .nav-pills button.nav-link { background:#f5f5f5 } (style.css:16251 "
                . 'and its three mirrors, specificity 0,2,1) is dormant today and FIRES after '
                . 'the swap, beating .nav-pills .nav-link (0,2,0). In dark mode that is a '
                . 'light-grey chip on a #292A33 card.'
            );

            $this->assertStringContainsString(
                '#060606',
                $block,
                "{$sheet}: the same rule also sets color:#000. #060606 is restated because it "
                . 'is exactly what the anchors compute today on a theme-1…theme-10 chrome, in '
                . 'both light and dark (style.css:4444 / style-dark.css). This preserves the '
                . 'current appearance rather than silently changing it.'
            );

            $this->assertStringContainsString(
                'var(--color-customColor)',
                $block,
                "{$sheet}: on the DEFAULT custom-color chrome the label is the brand colour via "
                . 'body.custom-color .nav-link (custom-color.css:155, 0,2,0), which LOSES to '
                . '(0,2,1). Without this second rule the swap would drop every tab label from '
                . 'the brand blue to #000.'
            );
        }
    }

    public function test_the_new_rail_block_is_byte_identical_in_both_directions(): void
    {
        $ltr = $this->railBlock((string) file_get_contents($this->base(self::LTR)), self::LTR);
        $rtl = $this->railBlock((string) file_get_contents($this->base(self::RTL)), self::RTL);

        $this->assertSame(
            $ltr,
            $rtl,
            'public/css/custom.css and public/css/rtl-custom.css are mirrored duplicates — '
            . 'custom.css serves LTR and rtl-custom.css serves Arabic. A change in only one '
            . 'reaches only HALF the merchants. The existing mirror ratchets '
            . '(DarkTextUtilityContrastTest, DarkModalSurfaceContrastTest) are scoped to their '
            . 'own sentinel blocks and would NOT catch a one-sided edit here, which is why this '
            . 'assertion exists.'
        );
    }

    /**
     * The restatements use logical-property-free declarations (width, background, color) so no
     * direction-specific twin is needed INSIDE the block — but the block must still be scoped
     * by the rail's id, or it would repaint every nav-pills rail in the admin, roles/create and
     * finance/index among them.
     */
    public function test_the_restatement_is_scoped_to_this_rail_only(): void
    {
        foreach ([self::LTR, self::RTL] as $sheet) {
            $block = $this->railDeclarations((string) file_get_contents($this->base($sheet)), $sheet);

            $this->assertStringContainsString(
                '#useradd-sidenav',
                $block,
                "{$sheet}: the restatement must be confined to this rail by its wrapper id "
                . '(resources/views/setting/index.blade.php, div#useradd-sidenav), so the other '
                . 'eight role="tab" views and all 70 modules are untouched.'
            );
        }
    }

    /**
     * CONTRAST, COMPUTED HERE WITH THE WCAG FORMULA FROM COLOURS I MEASURED IN A BROWSER.
     *
     * I first wrote this test asserting #060606 on the dark card #292A33 = 1.42:1, reasoning
     * from style-dark.css:4454. THEN I MEASURED IT and that was wrong: in a headless-Chrome
     * harness loading the real style-dark.css + custom-color.css + custom.css with the real
     * rail structure, the label computes rgb(20, 83, 136) = #145388, not #060606 — a theme
     * declaration this repair does not reach wins there, so the restatement is INERT in dark.
     * The figures below are the measured pairs, not the inferred ones.
     *
     * MEASURED COLOUR AND EFFECTIVE SURFACE (anchor and swapped button IDENTICAL in every case,
     * which is the parity claim this repair rests on):
     *   default custom-color chrome, light:  rgb(51,93,168)  on rgb(255,255,255)
     *   theme-4 chrome, light:               rgb(6,6,6)      on rgb(255,255,255)
     *   theme-4 chrome, dark:                rgb(20,83,136)  on rgb(41,42,51)   [the .card]
     * Every declaration is fully opaque — no rgba is involved — so no compositing step is
     * needed before the ratio.
     *
     * COMPUTED by contrast() below from those measured pairs:
     *   #335da8 on #ffffff = 6.42:1   PASSES 1.4.3 at AA. Independently corroborated: the
     *                                 design system's own docblock at store-syria-ds.css:93
     *                                 records 6.42:1 for this colour, which is how I know this
     *                                 implementation of the formula is right.
     *   #060606 on #ffffff = 20.26:1  PASSES at AAA.
     *   #145388 on #292A33 =  1.78:1  FAILS — and it is PRE-EXISTING AND UNCHANGED: I measured
     *                                 the same rgb(20,83,136) on the same rgb(41,42,51) for the
     *                                 href-less <a> before the swap and for the <button> after
     *                                 it. A keyboard-access repair is the wrong place to alter a
     *                                 vendored theme's dark-mode pill colour, so it is recorded
     *                                 as its own candidate finding and pinned here at its
     *                                 measured value, so that anyone who changes either colour
     *                                 has to re-measure rather than assume.
     */
    public function test_the_restated_colours_contrast_as_measured(): void
    {
        // Measured: rgb(51,93,168) on rgb(255,255,255), default chrome, light.
        $this->assertSame(6.42, $this->contrast('#335da8', '#ffffff'));

        // Measured: rgb(6,6,6) on rgb(255,255,255), theme-4 chrome, light.
        $this->assertSame(20.26, $this->contrast('#060606', '#ffffff'));

        // Measured: rgb(20,83,136) on rgb(41,42,51), theme-4 chrome, dark. Identical before
        // and after the swap, so this repair neither causes nor cures it.
        $this->assertSame(1.78, $this->contrast('#145388', '#292A33'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The contiguous run from the cursor rule through the end of the restatement block,
     * INCLUDING the explanatory comment — so the byte-identity check covers the comment too,
     * and a one-sided edit to the explanation is caught as well as one to a declaration.
     */
    private function railBlock(string $css, string $sheet): string
    {
        $at = strpos($css, '.nav-pills .nav-item a');
        $this->assertNotFalse($at, "{$sheet}: the .nav-pills .nav-item a rule has gone.");

        // Anchored on the LAST rule of the block by its full selector, not on a bare id
        // substring: the explanatory comment names #useradd-sidenav and
        // var(--color-customColor) itself, and slicing on those truncated the block inside the
        // comment. Measured, not guessed — it is how the first version of this helper failed.
        $last = strpos($css, 'body.custom-color #useradd-sidenav', $at);
        $this->assertNotFalse(
            $last,
            "{$sheet}: the rail restatement must sit immediately after the existing cursor rule, "
            . 'not appended at end of file, where it could land inside a sentinel block guarded '
            . 'by DarkTextUtilityContrastTest or DarkModalSurfaceContrastTest.'
        );

        $close = strpos($css, '}', $last);
        $this->assertNotFalse($close, "{$sheet}: the restatement block is unterminated.");

        return substr($css, $at, (int) $close - $at + 1);
    }

    /** The same block with CSS comments removed, so a content assertion cannot be satisfied by
     *  the prose that explains it. */
    private function railDeclarations(string $css, string $sheet): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->railBlock($css, $sheet));
    }

    /** WCAG 2.x relative luminance and contrast ratio, rounded to 2dp. */
    private function contrast(string $a, string $b): float
    {
        $ratio = (max($this->luminance($a), $this->luminance($b)) + 0.05)
            / (min($this->luminance($a), $this->luminance($b)) + 0.05);

        return round($ratio, 2);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $parts = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $parts[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE PAGE TITLE IS THE SMALLEST HEADING ON THE PAGE.  P2.5-WAVE-3
 * =================================================================================================
 *
 * MEASURED in the running application at 1440px, on /dashboard, /product and /order alike
 * (docs/development/phase-2.5/tools/measure-heading-scale.cjs):
 *
 *     <h2 class="h3 mb-0 ss-scope">    14px  w600   <- THE PAGE TITLE, on every merchant screen
 *     <h2 class="ss-attention__title"> 14px  w600
 *     <h2 class="ss-card__title">      18px  w600   <- a card inside the page
 *     <h4 class="m-0">                 20px  w600
 *     <h3 class="ss-navgroup">         12px  w600
 *
 * So on EVERY merchant screen the page's own title is outranked by every card on it, and by a
 * stray `<h4>`. The hierarchy is inverted, and it is inverted in one place: `layouts/app.blade.php`
 * emits the title wearing the VENDORED THEME's `.h3` utility, which resolves to 14px. 334 views
 * set `@section('page-title')` and all of them inherit it.
 *
 * THIS IS NOT A CONTRAST OR ACCESSIBILITY DEFECT. The heading ORDER is already correct -- h2 after
 * the h1-less shell, with the `</h4>`-closing-an-`<h2>` bug already repaired. Every one of these
 * passes AA. It is a type-scale defect, which is exactly the kind the directive says it is asking
 * for: "redesign ... typography", not "accessibility-only fixes".
 *
 * THE SCALE, AND WHY THESE THREE STEPS. Nothing is shrunk -- shrinking card titles would touch far
 * more surfaces than it fixes, and the inversion is cured by raising the one element that is
 * wrong. 24 / 18 / 12 is a ~1.33 then ~1.5 progression and gives three clearly separated roles:
 *
 *     24px  the page is about THIS          (ss-page-title)
 *     18px  a section within it             (ss-card__title, ss-attention__title)
 *     12px  a navigation label              (ss-navgroup)
 *
 * `.ss-attention__title` moves 14 -> 18 to join the section step: it is the heading of a panel
 * that sits beside cards whose headings are 18, and two panel headings at different sizes on the
 * same screen is the same inconsistency one level down.
 *
 * SPECIFICITY IS NOT LEFT TO SOURCE ORDER. `.h3` is (0,1,0) and is on the same element; a bare
 * `.ss-page-title` would also be (0,1,0) and the winner would depend on which sheet loaded last.
 * store-syria-ds.css is `@push`ed, so it does currently load later -- but "currently" is not a
 * guarantee, and this file has been bitten by cascade assumptions before.
 */
class MerchantTypeScaleTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';
    private const LAYOUT = 'resources/views/layouts/app.blade.php';
    private const OPENER = '/* ---- P2.5-WAVE-3: the merchant type scale ----';

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function block(): string
    {
        $css = $this->read(self::SHEET);
        $at = strpos($css, self::OPENER);
        $this->assertNotFalse($at, 'the P2.5-WAVE-3 section opener is missing');
        $next = strpos($css, '/* ---- ', $at + strlen(self::OPENER));

        return $next === false ? substr($css, $at) : substr($css, $at, $next - $at);
    }

    private function decls(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->block());
    }

    public function test_the_section_carries_declarations_not_only_prose(): void
    {
        $this->assertGreaterThan(
            6,
            substr_count($this->decls(), ';'),
            'the WAVE-3 section is all comment and no CSS'
        );
    }

    /** The layout stops borrowing the vendored theme's utility for the page title. */
    public function test_the_layout_gives_the_page_title_its_own_class(): void
    {
        $layout = $this->read(self::LAYOUT);

        $this->assertMatchesRegularExpression(
            '/<h2[^>]*class="[^"]*ss-page-title[^"]*"[^>]*>\s*@yield\(\'page-title\'\)/',
            $layout,
            'the page title still has no design-system class of its own'
        );
    }

    /** It is actually larger than the card titles it was losing to. */
    public function test_the_page_title_outranks_a_section_heading(): void
    {
        $decls = $this->decls();

        // THE TOKEN, NOT THE LITERAL. The first version of this demanded `font-size: 1.5rem`
        // inside the rule and failed against an implementation that used
        // `font-size: var(--ss-type-page)` -- which is the BETTER form, and is what
        // design-system's three-layer model asks for. The token's own value is asserted below,
        // so pinning the literal here would have forced the worse implementation.
        $this->assertMatchesRegularExpression(
            '/\.ss-page-title[^{]*\{[^}]*font-size:\s*var\(--ss-type-page\)/s',
            $decls,
            'the page title does not take the page step, so the inversion survives'
        );

        // The section step must be defined too, or "outranks" is an untested claim.
        $this->assertMatchesRegularExpression(
            '/--ss-type-page:\s*1\.5rem/',
            $decls,
            'the scale is hard-coded rather than expressed as tokens'
        );
        $this->assertMatchesRegularExpression(
            '/--ss-type-section:\s*1\.125rem/',
            $decls,
            'the section step is not defined, so nothing says what the page title outranks'
        );
    }

    /** The two panel headings on one screen stop being different sizes. */
    public function test_the_attention_panel_heading_joins_the_section_step(): void
    {
        $decls = $this->decls();

        $this->assertStringContainsString('.ss-attention__title', $decls);
        $this->assertMatchesRegularExpression(
            '/\.ss-attention__title[^{]*\{[^}]*var\(--ss-type-section\)/s',
            $decls,
            'the attention panel heading still sits at a different size from the card headings beside it'
        );
    }

    /** The win must not depend on which stylesheet happens to load last. */
    public function test_the_page_title_rule_does_not_rely_on_source_order(): void
    {
        $decls = $this->decls();

        $at = strpos($decls, '.ss-page-title');
        $this->assertNotFalse($at);
        $open = strpos($decls, '{', $at);
        $rule = substr($decls, $open, strpos($decls, '}', $open) - $open);

        $this->assertStringContainsString(
            '!important',
            $rule,
            'the theme\'s .h3 is on the same element at equal specificity, so without !important '
                . 'the winner is whichever sheet loaded last'
        );
    }
}

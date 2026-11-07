<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE EMPTY STATE IS A TABLE ROW WITH A SENTENCE IN IT.  P2.5-WAVE-2
 * =================================================================================================
 *
 * MEASURED on /coupon (0 rows) at 1440px: the cell is 44px tall, `text-align: center`, 14px, one
 * concatenated grey sentence. `hasIcon: false`. `hasAction: false`. And `dataTables_empty` appears
 * ZERO times in public/css/store-syria-ds.css and ZERO times in public/css/custom.css -- there is
 * no styling for it anywhere.
 *
 * THE COPY WAS ALREADY DONE PROPERLY and is not touched here. `dataTableEmptyState()` already
 * distinguishes `emptyTable` (the list holds nothing) from `zeroRecords` (a filter hid everything)
 * -- a distinction core DataTables gets wrong, which that helper's own comment documents with
 * sentinel strings. It already carries a per-table title and hint. What is missing is that the two
 * sentences are CONCATENATED INTO ONE STRING and rendered as one undifferentiated line.
 *
 * So this is presentation only:
 *
 *   * the title and the hint become separate elements, so the type scale can tell them apart;
 *   * the cell gets vertical room instead of a 44px row;
 *   * an icon, chosen per list for its SEMANTICS -- a package for products, a receipt for orders,
 *     people for customers -- and `aria-hidden`, because the text already says it and a screen
 *     reader should not hear it twice;
 *   * the FILTERED case gets a DIFFERENT icon, because it is a different situation with a
 *     different way out: nothing is wrong with the list, a filter is hiding it.
 *
 * WHY THIS AND NOT THE ACTION BUTTON the empty state should also have: the copy already says
 * "Add your first product with the button above", and wiring a real create route per table is a
 * controller-level change rather than a presentational one. It is the next slice, not this one.
 */
class TableEmptyStatePresentationTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';
    private const HELPER = 'app/Helper/helper.php';
    private const OPENER = '/* ---- P2.5-WAVE-2: the empty list ----';

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
        $this->assertNotFalse($at, 'the P2.5-WAVE-2 section opener is missing');
        $next = strpos($css, '/* ---- ', $at + strlen(self::OPENER));

        return $next === false ? substr($css, $at) : substr($css, $at, $next - $at);
    }

    /**
     * The whole of dataTableEmptyState(), bounded by the NEXT function declaration.
     *
     * NOT `substr($helper, $at, 2600)`. This project has now been bitten by a magic window FOUR
     * times: a comment above or inside the thing under test grows, the window silently stops
     * covering the end of it, and assertions about "the function" become assertions about its
     * first N characters. It happened to this very test the moment the implementation landed --
     * the added comment pushed `zeroRecords` past 2600 and two cases failed against correct code.
     */
    private function emptyStateFunction(): string
    {
        $helper = $this->read(self::HELPER);
        $at = strpos($helper, 'function dataTableEmptyState');
        $this->assertNotFalse($at, 'dataTableEmptyState() is gone from ' . self::HELPER);

        $next = strpos($helper, "\n    function ", $at + 10);

        return $next === false ? substr($helper, $at) : substr($helper, $at, $next - $at);
    }

    /** Declarations only -- a comment naming a selector must never satisfy an assertion. */
    private function decls(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->block());
    }

    public function test_the_section_carries_declarations_not_only_prose(): void
    {
        $this->assertGreaterThan(
            12,
            substr_count($this->decls(), ';'),
            'the WAVE-2 section is all comment and no CSS, so the rest of this file is vacuous'
        );
    }

    /** The two sentences stop being one string. */
    public function test_the_title_and_hint_are_separate_elements(): void
    {
        $helper = $this->read(self::HELPER);

        $this->assertStringContainsString('ss-dt-empty-title', $helper);
        $this->assertStringContainsString('ss-dt-empty-hint', $helper);
        $this->assertDoesNotMatchRegularExpression(
            "/'emptyTable'\s*=>\s*\\\$title\s*\.\s*' '\s*\.\s*\\\$hint/",
            $helper,
            'emptyTable still concatenates the two sentences into one undifferentiated line'
        );
    }

    /** The icon is per-list and semantic, not one generic glyph everywhere. */
    public function test_each_known_list_carries_its_own_semantic_icon(): void
    {
        $helper = $this->read(self::HELPER);

        $body = $this->emptyStateFunction();

        foreach (['product-table', 'order-table', 'customer-table', 'category-table', 'coupon-table'] as $t) {
            $this->assertStringContainsString($t, $body);
        }

        // Five known lists plus the fallback, each naming a Tabler icon.
        //
        // MATCHES THE TOKEN, NOT THE RENDERED CLASS. The map stores `'ti-package'` and the
        // renderer supplies the `ti ` family prefix, so a `/ti ti-\w+/` regex finds only the
        // single occurrence inside the closure -- which is how this assertion first failed
        // against a correct implementation carrying seven distinct icons.
        preg_match_all("/'(ti-[a-z0-9-]+)'/", $body, $m);
        $this->assertGreaterThanOrEqual(
            6,
            count(array_unique($m[0])),
            'the lists share one generic icon instead of carrying semantically correct ones'
        );
    }

    /** The filtered case is a different situation and must not borrow the empty-list icon. */
    public function test_the_filtered_case_is_presented_differently_from_the_empty_list(): void
    {
        $helper = $this->read(self::HELPER);

        $body = $this->emptyStateFunction();

        $zeroAt = strpos($body, "'zeroRecords'");
        $this->assertNotFalse($zeroAt);
        $zero = substr($body, $zeroAt, 420);

        $this->assertMatchesRegularExpression(
            "/'ti-(search|filter|zoom)[a-z0-9-]*'/",
            $zero,
            'the filtered-empty case uses the same icon as a genuinely empty list, so the two read alike'
        );
    }

    /** It stops being a 44px table row. */
    public function test_the_empty_cell_is_given_vertical_room_and_a_type_scale(): void
    {
        $decls = $this->decls();

        $this->assertStringContainsString('.dataTables_empty', $decls);
        $this->assertMatchesRegularExpression(
            '/\.ss-dt-empty-title[^{]*\{[^}]*font-weight:\s*[56]\d\d/s',
            $decls,
            'the title is not typographically distinct from the hint beneath it'
        );
        $this->assertMatchesRegularExpression(
            '/padding:\s*(\d\d|\d\.\d+rem)/',
            $decls,
            'the empty cell keeps the 11.2px row padding, so it still reads as a row rather than a state'
        );
    }

    /** Scope -- unscoped, this would restyle every empty table in all 70 vendored modules. */
    public function test_every_rule_is_scoped_to_the_merchant_table_card(): void
    {
        $unscoped = [];
        foreach (preg_split('/\}/', $this->decls()) ?: [] as $rule) {
            if (!str_contains($rule, '{')) {
                continue;
            }
            $sel = trim(explode('{', $rule)[0]);
            if ($sel === '' || str_starts_with($sel, '@') || str_contains($sel, 'data-ss-theme') || str_contains($sel, ':root')) {
                continue;
            }
            if (!str_contains($sel, '.ss-dt-card-body') && !str_contains($sel, '.ss-dt-')) {
                $unscoped[] = $sel;
            }
        }

        $this->assertSame([], $unscoped, "these escape the table card:\n  " . implode("\n  ", $unscoped));
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 finance-money-figure-contrast — the money in the wallet ledger renders at 1.80:1.
 * =================================================================================================
 *
 * WHAT IS WRONG. Every money figure on the merchant finance screen is painted by a HOST Bootstrap
 * utility, `.text-success` or `.text-danger`, and in light mode both are below AA on the surface
 * they are actually drawn on. Measured, not argued:
 *
 * SEVEN FIGURES AT SIX SITES, not the three the finding named — it missed the debit counterpart
 * of the ledger, the discount column and the negative branch of gross profit, which sit in the
 * same two tables and were equally unreadable. Named by column rather than by line, because line
 * numbers in this project go stale:
 *
 *     column / figure                        class            light            dark
 *     Potential Profit KPI, positive         text-success     1.80:1  FAIL     7.93:1  passes
 *     wallet ledger, Credit                  text-success     1.80:1  FAIL     7.93:1  passes
 *     order financials, Gross profit >= 0    text-success     1.80:1  FAIL     7.93:1  passes
 *     Potential Profit KPI, negative         text-danger      4.00:1  FAIL     3.56:1  FAIL
 *     wallet ledger, Debit                   text-danger      4.00:1  FAIL     3.56:1  FAIL
 *     order financials, Discount             text-danger      4.00:1  FAIL     3.56:1  FAIL
 *     order financials, Gross profit < 0     text-danger      4.00:1  FAIL     3.56:1  FAIL
 *
 * AND EVERY ONE OF THOSE RATIOS WAS RE-MEASURED IN CHROMIUM on the running dev server at
 * /finance, by reading `getComputedStyle().color` off the real element and walking up to the
 * first non-transparent ancestor background. The pre-fix and post-fix markup were measured in the
 * SAME page so the cascade is identical. The harness is
 * `docs/development/phase-2.5/tools/measure-finance-money-contrast-live.cjs`, which states its own
 * preconditions and can be re-run against the dev server: 25 readings over five combinations, resolving
 * to 8 distinct colour-on-surface pairs, and the browser agreed with the arithmetic below to the
 * second decimal in every one. Two numbers in the finding are wrong and are corrected here: the
 * credit figure is 1.80:1, not 1.78 — the row is pure #ffffff, which gives 1.7976 — and
 * `--ss-green-800` is 7.82:1 on white, not "about 7.4".
 *
 * 1.80:1 is below even the 3:1 floor WCAG allows for non-text. A merchant looking at the Credit
 * column of a financial ledger sees the row and effectively no number in it.
 *
 * THREE CORRECTIONS TO THE FINDING THAT CHANGE WHAT THE FIX HAS TO BE. Each is asserted below
 * rather than left in prose, because every one of them was wrong somewhere upstream.
 *
 * (1) THE SUCCESS DEFECT IS LIGHT-MODE ONLY. `--bs-success-rgb: 111, 217, 67` is byte-identical in
 *     all four host sheets (style.css:51, style-dark.css:51, style-rtl.css:51,
 *     rtl-style-dark.css:51), so `.text-success` is #6FD943 in both themes — 1.80:1 on a white row
 *     but 7.93:1 on the host's dark card. A "fix" that hard-codes one replacement colour for both
 *     themes would REGRESS dark mode. The repair must be theme-aware, which is why it uses the
 *     design system's `--ss-success-fg` rather than a hex.
 *
 * (2) THE RENDERED DANGER COLOUR IS NOT THE THEME'S. `public/css/custom.css:195` — and
 *     `rtl-custom.css:195` — declare `.text-danger { color: red !important; }`, and the layout
 *     loads custom.css AFTER #main-style-link (app.blade.php:158-171). Two author `!important`
 *     declarations of equal specificity, so the later one wins: `.text-danger` renders #FF0000,
 *     not the theme's #FF3A6E. #FF0000 is 4.00:1 light and 3.56:1 dark — both below AA, and in
 *     dark it is also below the value the theme alone would have given. Any measurement taken
 *     from the theme sheet alone is of a colour that never reaches a screen.
 *
 * (3) THE OBVIOUS FIX IS A NO-OP, AND THAT IS THE WHOLE POINT OF THIS FILE. `.text-success`
 *     (style.css:9395) and `.text-danger` (:9410) are `!important`, and custom.css adds a second
 *     `!important` for danger. So an inline `style="color: var(--ss-success-fg)"` loses, and so
 *     does any plain `.ss-money--credit { color: … }` rule, at any position in the cascade —
 *     `!important` outranks source order and specificity alike. Wrapping the table in `.ss-scope`
 *     changes nothing either, because the design system never styles `.text-success` at all.
 *
 * SO THE CLASS IS REMOVED FROM THE MARKUP, NOT OVERRIDDEN. The alternative — adding
 * `.text-success { … !important }` to store-syria-ds.css — is refused for three reasons, and the
 * third is decisive:
 *
 *   - it starts an `!important` war with the host theme on a selector the host owns;
 *   - `.text-success` is painted in all 70 vendored WorkDo modules, so the override's blast radius
 *     is the entire product, for a defect measured on one screen;
 *   - `Tests\Unit\Ui\DesignSystemStylesheetScopeTest` asserts that EVERY selector in
 *     store-syria-ds.css is confined by an `.ss-` class or `data-ss-` attribute. A bare
 *     `.text-success` rule would fail that ratchet, and loosening it to admit a host utility is a
 *     larger change than this defect justifies.
 *
 * `Tests\Feature\Merchant\FinanceStatusBadgeContrastTest` reached the same conclusion for the
 * `.badge bg-*` pills one column to the left, for the same reasons, and migrated the markup. This
 * follows that precedent: `.ss-money--credit` / `.ss-money--debit`, defined once in the design
 * system against `--ss-success-fg` / `--ss-danger-fg`, which already invert per theme.
 *
 * WHAT IS NOT CLAIMED. This file measures the design system's tokens and the finance markup. It
 * does not prove the rendered result; a stylesheet test cannot. The real render was measured in
 * Chromium on the running dev server and the ratios are recorded in the slice's evidence.
 */
class FinanceMoneyFigureContrastTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const VIEW = self::REPO . '/resources/views/finance/index.blade.php';
    private const DS = self::REPO . '/public/css/store-syria-ds.css';

    /** The host theme sheets. All four carry the same utility values; the test checks that. */
    private const HOST_SHEETS = [
        'public/assets/css/style.css',
        'public/assets/css/style-dark.css',
        'public/assets/css/style-rtl.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    /** The first-party sheets loaded after the host's, one per text direction. */
    private const CUSTOM_SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    /** The two DARK host sheets, which carry a heading rule the light pair does not. */
    private const DARK_SHEETS = [
        'public/assets/css/style-dark.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    private const LIGHT_SHEETS = [
        'public/assets/css/style.css',
        'public/assets/css/style-rtl.css',
    ];

    /**
     * The two surfaces a money figure is really drawn on, MEASURED IN CHROMIUM on the running dev
     * server at /finance — `getComputedStyle` walked up from the figure to the first non-transparent
     * ancestor background. Not read from a stylesheet, because the row inherits the card.
     */
    private const LIGHT_ROW = [255, 255, 255];
    private const DARK_CARD = [41, 42, 51];

    /** The six money-figure sites this slice owns, as `[line-anchor, tone]`. */
    private const SITES = [
        ['inventoryPotentialProfit', 'both'],
        ['transaction->amount', 'both'],
        ['financial->discount_amount', 'debit'],
        ['financial->gross_profit', 'both'],
    ];

    // =============================================================================================
    //  INSTRUMENT
    // =============================================================================================

    private function file(string $relative): string
    {
        $path = str_starts_with($relative, '/') ? $relative : self::REPO . '/' . $relative;
        $this->assertFileExists($path, "$relative is not on disk");

        return (string) file_get_contents($path);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $colour): array
    {
        $colour = trim($colour);

        // The one named colour this codebase actually uses in a colour declaration.
        if (strcasecmp($colour, 'red') === 0) {
            return [255, 0, 0];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $colour, $m)) {
            return [
                (int) hexdec(substr($m[1], 0, 2)),
                (int) hexdec(substr($m[1], 2, 2)),
                (int) hexdec(substr($m[1], 4, 2)),
            ];
        }

        if (preg_match('/^(\d+)\s*,\s*(\d+)\s*,\s*(\d+)$/', $colour, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        $this->fail("cannot read `$colour` as a colour");
    }

    private function luminance(array $rgb): float
    {
        $channel = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    private function contrast(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * THE INSTRUMENT CONTROL. Every threshold below is an assertGreaterThan, so a maths bug that
     * returned a large number for every pair would pass all of them. This is the negative control:
     * a pair that must come out BELOW the threshold, and does.
     */
    public function test_the_contrast_instrument_can_fail_a_pair(): void
    {
        $nearWhite = $this->contrast($this->rgb('#fdfdfd'), self::LIGHT_ROW);

        $this->assertLessThan(1.1, $nearWhite, sprintf(
            'near-white on white measured %.2f:1; the instrument is not discriminating', $nearWhite));

        $black = $this->contrast([0, 0, 0], self::LIGHT_ROW);

        $this->assertGreaterThan(20.0, $black, sprintf(
            'black on white measured %.2f:1, which should be 21:1; the instrument is miscalibrated',
            $black));
    }

    // =============================================================================================
    //  TOKEN READING — every ratio below is computed from a value parsed out of a real stylesheet
    // =============================================================================================

    /**
     * Read a custom-property declaration out of a stylesheet, honouring the cascade.
     *
     * THIS IS NOT "find the selector and read forward", AND THE FIRST DRAFT OF THIS FILE WAS.
     * store-syria-ds.css has TWO top-level blocks whose selector list contains `:root` — the
     * primitives at :73, and the semantic layer at :234, which is declared as
     * `:root, [data-ss-theme="light"] { … }`. A reader that stops at the first `:root {` sees the
     * primitives only and reports `--ss-success-fg` as undeclared, which is exactly what it did.
     *
     * So: strip comments (they are full of selector-shaped prose and of braces), walk the real
     * top-level blocks, keep every one whose comma-separated selector list contains the target,
     * and return the LAST declaration of the property across them — which is what the browser
     * would use.
     */
    private function token(string $css, string $selector, string $property): string
    {
        $value = null;

        foreach ($this->blocks($css) as [$selectors, $body]) {
            if (! in_array($selector, $selectors, true)) {
                continue;
            }
            if (preg_match_all('/' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $body, $m)) {
                $value = trim(end($m[1]));
            }
        }

        $this->assertNotNull($value, "`$property` is not declared for `$selector`");

        return $value;
    }

    /**
     * Top-level `selector { body }` pairs, comments removed.
     *
     * @return list<array{0:list<string>,1:string}>
     */
    private function blocks(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $out = [];
        $depth = 0;
        $selector = '';
        $body = '';

        for ($i = 0, $n = strlen($css); $i < $n; $i++) {
            $c = $css[$i];

            if ($c === '{') {
                $depth++;
                if ($depth === 1) {
                    $body = '';
                    continue;
                }
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $out[] = [$this->selectorList($selector), $body];
                    $selector = '';
                    continue;
                }
            }

            if ($depth === 0) {
                $selector .= $c;
            } else {
                $body .= $c;
            }
        }

        $this->assertNotEmpty($out, 'the stylesheet parsed to no blocks at all');

        return $out;
    }

    /**
     * Split a block's selector text into a selector list.
     *
     * THE PROLOGUE HAS TO BE CUT OFF FIRST, AND THAT IS NOT A DETAIL — it cost this file a round.
     * `public/assets/css/style.css` opens with `@charset "UTF-8";` and an `@import url(…)` for
     * Google Fonts. Those are STATEMENT at-rules: they end in `;` and have no block of their own,
     * so a brace-walking parser accumulates them into the selector text of whatever rule comes
     * next — here the sheet's `:root`. Comma-splitting that gives ONE item reading
     * `@charset "UTF-8"; @import url(…); :root`, which matches nothing, and the host-sheet premise
     * tests reported `--bs-success-rgb` as undeclared while the value sat at :51 the whole time.
     *
     * A selector can never contain a `;` — that is CSS grammar, not a guess — so everything up to
     * and including the last `;` is prologue. The `;` characters inside the font URL's
     * `wght@400;500;600;700` are harmless because the statement's own terminator comes after them.
     *
     * @return list<string>
     */
    private function selectorList(string $selector): array
    {
        $semi = strrpos($selector, ';');
        if ($semi !== false) {
            $selector = substr($selector, $semi + 1);
        }

        return array_values(array_filter(array_map('trim', explode(',', $selector))));
    }

    /** Resolve one `var(--x)` hop against `:root`, which is where the primitives live. */
    private function resolve(string $css, string $value): string
    {
        if (preg_match('/^var\(\s*(--[\w-]+)\s*\)$/', $value, $m)) {
            return $this->token($css, ':root', $m[1]);
        }

        return $value;
    }

    private function dsToken(string $property, string $theme): array
    {
        $css = $this->file(self::DS);
        $selector = $theme === 'dark' ? '[data-ss-theme="dark"]' : ':root';

        return $this->rgb($this->resolve($css, $this->token($css, $selector, $property)));
    }

    // =============================================================================================
    //  THE PREMISE — the defect is real, in the colour that really renders
    // =============================================================================================

    /**
     * CORRECTION (1): the success utility is one colour in BOTH themes, so the defect is
     * light-mode only and the repair must not flatten dark mode to the same value.
     */
    public function test_premise_the_success_utility_is_identical_in_all_four_host_sheets(): void
    {
        $values = [];

        foreach (self::HOST_SHEETS as $sheet) {
            $values[$sheet] = $this->token($this->file($sheet), ':root', '--bs-success-rgb');
        }

        $this->assertCount(1, array_unique($values), '--bs-success-rgb has drifted between the host '
            . 'sheets, so the light/dark reasoning in this file no longer holds: '
            . json_encode($values));
    }

    public function test_premise_the_success_utility_is_unreadable_on_a_light_row(): void
    {
        $success = $this->rgb($this->token($this->file(self::HOST_SHEETS[0]), ':root', '--bs-success-rgb'));

        $ratio = $this->contrast($success, self::LIGHT_ROW);

        $this->assertLessThan(3.0, $ratio, sprintf(
            '.text-success measured %.2f:1 on a white row. If the host theme has been re-tuned '
            . 'this slice is redundant and should be removed, not left asserting a dead defect.',
            $ratio));
    }

    public function test_premise_the_success_utility_was_already_fine_in_dark_mode(): void
    {
        $success = $this->rgb($this->token($this->file(self::HOST_SHEETS[1]), ':root', '--bs-success-rgb'));

        $ratio = $this->contrast($success, self::DARK_CARD);

        $this->assertGreaterThan(4.5, $ratio, sprintf(
            '.text-success measured %.2f:1 on the host dark card. This is asserted because it is '
            . 'the fact that makes a single-colour replacement WRONG.', $ratio));
    }

    /**
     * CORRECTION (2): the danger colour that renders is `red` from custom.css, not the theme's
     * #FF3A6E. Both first-party sheets must carry it, or the two directions disagree.
     */
    public function test_premise_custom_css_overrides_the_theme_danger_colour_in_both_directions(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css = $this->file($sheet);

            $this->assertMatchesRegularExpression(
                '/\.text-danger\s*\{\s*color:\s*red\s*!important;\s*\}/', $css,
                "$sheet no longer declares `.text-danger { color: red !important }`. That rule is "
                . 'why the rendered danger colour is #FF0000; if it is gone, re-measure the ratios '
                . 'in this file against the theme value instead.');
        }
    }

    public function test_premise_the_rendered_danger_colour_fails_aa_in_both_themes(): void
    {
        $red = $this->rgb('red');

        $light = $this->contrast($red, self::LIGHT_ROW);
        $dark = $this->contrast($red, self::DARK_CARD);

        $this->assertLessThan(4.5, $light, sprintf('red on a white row measured %.2f:1', $light));
        $this->assertLessThan(4.5, $dark, sprintf('red on the dark card measured %.2f:1', $dark));
    }

    /**
     * CORRECTION (3): the trap. Both utilities are `!important`, so nothing short of removing the
     * class or out-importanting the host can change what paints.
     */
    public function test_premise_both_host_utilities_are_important_so_an_override_must_be_too(): void
    {
        foreach (self::HOST_SHEETS as $sheet) {
            $css = $this->file($sheet);

            foreach (['success', 'danger'] as $tone) {
                $found = preg_match(
                    '/\.text-' . $tone . '\s*\{[^}]*color:[^;]*!important;/s', $css, $m);

                $this->assertSame(1, $found, "$sheet: `.text-$tone` is not an !important colour "
                    . 'declaration. If the host dropped !important, a plain design-system rule '
                    . 'would now win and removing the class is no longer necessary.');
            }
        }
    }

    /**
     * THE SECOND !important TRAP, AND IT WAS FOUND ONLY BY RENDERING THE PAGE.
     *
     * `style-dark.css:29442` and `rtl-style-dark.css:29398` declare
     * `h1,h2,h3,h4,h5,h6 { color: var(--bs-white) !important; }`. The two LIGHT sheets carry no
     * such rule at all — asserted below, because that asymmetry is the whole reason the defect
     * was invisible in light mode.
     *
     * An `!important` on an ELEMENT selector beats an ordinary class rule however specific. So the
     * first version of this repair, which put `.ss-money--credit` on the `<h4>` of the Potential
     * Profit KPI, was a NO-OP IN DARK MODE: Chromium reported `rgb(255, 255, 255)` with
     * `.ss-money--credit` matched and overridden. Every assertion in this file still passed, and
     * the light theme looked repaired. The host class it replaced was itself `!important`, so the
     * old markup DID get its colour — meaning the naive fix silently traded a working dark-mode
     * colour for white.
     *
     * The repair is to put the tone on a `<span>` inside the heading. A span is not a heading, so
     * the host rule cannot match it, and the design system wins with no `!important` of its own.
     */
    public function test_premise_the_dark_sheets_paint_every_heading_white_with_important(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\s*\{\s*color:[^;]*!important;/s',
                $this->file($sheet),
                "$sheet no longer paints headings white with !important. If the host dropped that "
                . 'rule, a money tone may sit on a heading again and this slice can be simplified.');
        }

        foreach (self::LIGHT_SHEETS as $sheet) {
            $this->assertDoesNotMatchRegularExpression(
                '/h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\s*\{\s*color:[^;]*!important;/s',
                $this->file($sheet),
                "$sheet has gained an !important heading colour. The light theme would now have "
                . 'the same no-op the dark theme had, and this file\'s light-mode readings are stale.');
        }
    }

    /**
     * SO NO MONEY TONE MAY SIT ON A HEADING ELEMENT. This is the assertion that reddens on the
     * no-op above; every ratio test in this file passed while it was broken.
     */
    public function test_no_money_tone_sits_on_a_heading_element(): void
    {
        $offenders = [];

        foreach ($this->toneOwners() as [$line, $tag, $text]) {
            if (preg_match('/^h[1-6]$/i', $tag)) {
                $offenders[] = "$line: <$tag> carries the tone -- $text";
            }
        }

        $this->assertSame([], $offenders,
            "a money tone is on a heading element, where the dark sheets' `h1..h6 { color: "
            . 'var(--bs-white) !important }` overrides it -- measured in Chromium as white, '
            . "14.25:1, with the class matched and losing. Put the tone on a <span> inside the "
            . "heading:\n" . implode("\n", $offenders));
    }

    /**
     * Which ELEMENT each `ss-money--` tone is actually attached to.
     *
     * WHY THIS IS NOT A REGEX OVER THE LINE, and the reason is a false green this file already
     * produced. The first version matched `/<h[1-6][\s>][^>]*ss-money--/`. The KPI's bad markup is
     *
     *     <h4 class="mb-0 ss-money {{ $x >= 0 ? 'ss-money--credit' : 'ss-money--debit' }}">
     *
     * and `[^>]*` stops dead at the `>` of the Blade `>=` operator, before ever reaching
     * `ss-money--`. The assertion passed against the exact markup it exists to reject: reverting
     * the repair did not redden the test, which is the one outcome this project treats as
     * disqualifying. A Blade attribute is not an HTML attribute and cannot be scanned as one.
     *
     * So the tag is found by walking BACKWARDS from each tone to the nearest tag opener instead.
     * `<` is only treated as an opener when a letter or `/` follows it, so a Blade `<` comparison
     * operator (always followed by a space here) is not mistaken for one.
     *
     * @return list<array{0:int,1:string,2:string}> line number, tag name, the line
     */
    private function toneOwners(): array
    {
        $out = [];

        foreach (explode("\n", $this->file(self::VIEW)) as $i => $line) {
            $from = 0;

            while (($at = strpos($line, 'ss-money--', $from)) !== false) {
                $from = $at + 1;

                $before = substr($line, 0, $at);
                if (! preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)/', $before, $m, PREG_OFFSET_CAPTURE)) {
                    continue;   // the tone's opening tag is on an earlier line
                }

                $last = end($m[1]);
                $out[] = [$i + 1, $last[0], trim($line)];
            }
        }

        $this->assertNotEmpty($out, 'premise: no `ss-money--` tone was found in the view at all, so '
            . 'this test is vacuous');

        return $out;
    }

    /**
     * AND THE INSTRUMENT ITSELF IS CONTROLLED, because the version it replaces passed against the
     * markup it was written to reject. This drives the same reader over the bad shape directly.
     */
    public function test_the_tone_owner_reader_identifies_a_heading_in_blade_markup(): void
    {
        $bad = '<h4 class="mb-0 ss-money {{ $x >= 0 ? \'ss-money--credit\' : \'ss-money--debit\' }}">';
        $good = '<span class="ss-money {{ $x >= 0 ? \'ss-money--credit\' : \'ss-money--debit\' }}">';

        $owner = static function (string $line): ?string {
            $at = strpos($line, 'ss-money--');
            if ($at === false) {
                return null;
            }
            if (! preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)/', substr($line, 0, $at), $m)) {
                return null;
            }

            return (string) end($m[1]);
        };

        $this->assertSame('h4', $owner($bad),
            'the reader does not see the heading in Blade markup containing `>=`, which is exactly '
            . 'how the first version of this test passed against a reverted repair');
        $this->assertSame('span', $owner($good));
    }

    // =============================================================================================
    //  THE REPAIR — the design-system tones clear AA on the surfaces that were measured
    // =============================================================================================

    public function test_the_credit_tone_clears_aa_in_both_themes(): void
    {
        $light = $this->contrast($this->dsToken('--ss-success-fg', 'light'), self::LIGHT_ROW);
        $dark = $this->contrast($this->dsToken('--ss-success-fg', 'dark'), self::DARK_CARD);

        $this->assertGreaterThanOrEqual(4.5, $light,
            sprintf('--ss-success-fg measured %.2f:1 on a white row', $light));
        $this->assertGreaterThanOrEqual(4.5, $dark,
            sprintf('--ss-success-fg measured %.2f:1 on the host dark card', $dark));
    }

    public function test_the_debit_tone_clears_aa_in_both_themes(): void
    {
        $light = $this->contrast($this->dsToken('--ss-danger-fg', 'light'), self::LIGHT_ROW);
        $dark = $this->contrast($this->dsToken('--ss-danger-fg', 'dark'), self::DARK_CARD);

        $this->assertGreaterThanOrEqual(4.5, $light,
            sprintf('--ss-danger-fg measured %.2f:1 on a white row', $light));
        $this->assertGreaterThanOrEqual(4.5, $dark,
            sprintf('--ss-danger-fg measured %.2f:1 on the host dark card', $dark));
    }

    /**
     * AND THE REPAIR MUST NOT BE A DOWNGRADE WHERE THE UTILITY ALREADY PASSED. Dark-mode success
     * was 7.93:1; the replacement has to be at least as good, or this slice trades a light-mode
     * failure for a dark-mode regression.
     */
    public function test_the_credit_tone_does_not_regress_dark_mode(): void
    {
        $was = $this->contrast(
            $this->rgb($this->token($this->file(self::HOST_SHEETS[1]), ':root', '--bs-success-rgb')),
            self::DARK_CARD);

        $now = $this->contrast($this->dsToken('--ss-success-fg', 'dark'), self::DARK_CARD);

        $this->assertGreaterThanOrEqual($was, $now, sprintf(
            'dark-mode credit went from %.2f:1 to %.2f:1 — the repair made dark mode worse', $was, $now));
    }

    /**
     * THE SIGN MUST NOT DEPEND ON THE COLOUR — and contrast ratio is the WRONG instrument for
     * asking whether two tones look different.
     *
     * The first draft of this test asserted `contrast(credit, debit) > 1.3` and failed, which
     * looked like a defect in the token choice and is not. Both light tones are dark (#165e34,
     * #8c1d17) and both dark tones are light (#7fd6a0, #f3a9a4), so their RELATIVE LUMINANCE is
     * close by construction — measured 1.17:1 light and 1.09:1 dark. A contrast ratio compares
     * luminance only; a dark green and a dark red of equal luminance are plainly different hues
     * and score 1.0. Asserting a ratio between two foregrounds measures nothing anybody sees.
     *
     * The requirement that actually applies is WCAG 1.4.1: colour must not be the only way the
     * information is conveyed. It is not, and this asserts the real cue instead — the ledger puts
     * credits and debits in SEPARATE COLUMNS, each figure carrying an explicit `+` or `-`. A
     * colour-blind merchant, or one reading a printout, still reads the sign.
     */
    public function test_the_sign_of_a_ledger_figure_does_not_depend_on_colour(): void
    {
        $view = $this->file(self::VIEW);

        $credit = null;
        $debit = null;

        foreach (explode("\n", $view) as $line) {
            if (! str_contains($line, 'transaction->amount')) {
                continue;
            }
            if (str_contains($line, 'ss-money--credit') || str_contains($line, 'text-success')) {
                $credit = $line;
            }
            if (str_contains($line, 'ss-money--debit') || str_contains($line, 'text-danger')) {
                $debit = $line;
            }
        }

        $this->assertNotNull($credit, 'premise: the ledger has a credit figure');
        $this->assertNotNull($debit, 'premise: the ledger has a debit figure');

        $this->assertMatchesRegularExpression('/>\s*\+\{\{\s*number_format/', $credit,
            'the credit figure no longer carries an explicit `+`, so its sign is conveyed by '
            . 'colour alone (WCAG 1.4.1)');
        $this->assertMatchesRegularExpression('/>\s*-\{\{\s*number_format/', $debit,
            'the debit figure no longer carries an explicit `-`, so its sign is conveyed by '
            . 'colour alone (WCAG 1.4.1)');
    }

    /** And the two tones must at least be different values, or the colour cue is absent too. */
    public function test_the_two_money_tones_are_different_values(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $this->assertNotSame(
                $this->dsToken('--ss-success-fg', $theme),
                $this->dsToken('--ss-danger-fg', $theme),
                "$theme: the credit and debit tones resolve to the same colour");
        }
    }

    // =============================================================================================
    //  THE STYLESHEET — the rules exist, and they are theme-aware rather than one hard-coded hex
    // =============================================================================================

    public function test_the_design_system_declares_both_money_tones(): void
    {
        $css = $this->file(self::DS);

        foreach (['credit' => '--ss-success-fg', 'debit' => '--ss-danger-fg'] as $tone => $token) {
            $found = preg_match(
                '/\.ss-money--' . $tone . '\s*\{[^}]*color:\s*var\(\s*' . preg_quote($token, '/')
                . '\s*\)/s', $css);

            $this->assertSame(1, $found, "store-syria-ds.css does not paint `.ss-money--$tone` "
                . "from `var($token)`. A hard-coded hex there would be wrong in one of the two "
                . 'themes — see test_premise_the_success_utility_was_already_fine_in_dark_mode.');
        }
    }

    /**
     * NO `!important` IN THE NEW RULES. If one is ever added it means somebody put the host class
     * back on the markup and papered over it, which re-opens the blast radius this slice avoided.
     */
    public function test_the_money_rules_win_on_the_cascade_without_important(): void
    {
        $css = $this->file(self::DS);

        // THE SLICE HAS TO BE TAKEN FROM THE MARKER, NOT FROM THE FIRST `.ss-money`. The first
        // occurrence of that string is INSIDE the block's banner comment, which explains the
        // !important trap and therefore contains the word `!important` five times. Slicing from
        // it and then grepping for `!important` fails against a correct stylesheet -- which is
        // what the first draft of this test did. Same shape of mistake the A-05 test records.
        $at = strpos($css, '/* P2.5-MONEY.');
        $this->assertNotFalse($at, 'premise: the stylesheet has a marked P2.5-MONEY block');

        $end = strpos($css, '/* P2.5-MONEY end */', $at);
        $this->assertNotFalse($end, 'the P2.5-MONEY block has no end marker, so it is unbounded');

        $rules = (string) preg_replace('#/\*.*?\*/#s', '', substr($css, $at, $end - $at));

        $this->assertMatchesRegularExpression('/\.ss-money--credit\s*\{/', $rules,
            'premise: the slice really contains the rules, not just the comment');

        $this->assertStringNotContainsString('!important', $rules,
            'an !important in the money rules means the host utility is still on the markup; '
            . 'remove the class instead');
    }

    // =============================================================================================
    //  THE MARKUP — the host utility is gone from every money figure on the screen
    // =============================================================================================

    /**
     * THE ASSERTION THAT ACTUALLY CATCHES THE NO-OP. A fix that adds the design-system rule and
     * LEAVES the host class on the span changes nothing on screen, and every ratio assertion above
     * would still pass. This is the one that reddens.
     */
    public function test_no_money_figure_still_carries_the_host_colour_utility(): void
    {
        $lines = explode("\n", $this->file(self::VIEW));
        $offenders = [];

        foreach ($lines as $i => $line) {
            if (! preg_match('/text-(success|danger)/', $line)) {
                continue;
            }
            // `number_format(` is what makes a line a money figure rather than an icon or a label.
            if (! str_contains($line, 'number_format(')) {
                continue;
            }
            $offenders[] = ($i + 1) . ': ' . trim($line);
        }

        $this->assertSame([], $offenders, "these money figures still carry the host colour utility, "
            . "which is !important and beats the design system:\n" . implode("\n", $offenders));
    }

    public function test_every_money_figure_carries_a_design_system_tone(): void
    {
        $view = $this->file(self::VIEW);

        foreach (self::SITES as [$anchor, $tone]) {
            $found = false;

            foreach (explode("\n", $view) as $line) {
                if (str_contains($line, $anchor) && str_contains($line, 'ss-money--')) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found,
                "no line mentioning `$anchor` carries an `ss-money--` tone; the $tone money figure "
                . 'is unstyled or still on the host utility');
        }
    }

    /**
     * BOTH SIGNS, AT EVERY SITE THAT HAS TWO. A repair that fixed only the credit column would
     * leave the debit column at 4.00:1 beside it and the ledger incoherent.
     */
    public function test_both_tones_are_present_in_the_view(): void
    {
        // BLADE COMMENTS ARE STRIPPED FIRST. The view carries a `{{-- … --}}` comment explaining
        // the heading trap, and that comment names `.ss-money--credit`. Counting the raw file
        // therefore credits the repair with a tone that renders nothing — the count would still
        // have cleared its threshold with one of the three real credit sites missing.
        $view = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->file(self::VIEW));

        $this->assertStringNotContainsString('THE TONE IS ON THE SPAN', $view,
            'premise: the Blade comments really were stripped');

        $credits = preg_match_all('/ss-money--credit/', $view);
        $debits = preg_match_all('/ss-money--debit/', $view);

        $this->assertGreaterThanOrEqual(3, $credits,
            "expected the three positive money sites to carry `ss-money--credit`, found $credits");
        $this->assertGreaterThanOrEqual(4, $debits,
            "expected the four negative money sites to carry `ss-money--debit`, found $debits");
    }
}

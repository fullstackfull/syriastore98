<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * 34 OF 35 SITES PAINTING THE MERCHANT'S BRAND COLOUR AS TEXT FAILED AA ON THE DARK PAGE. P2.5-DESIGN-G
 * =================================================================================================
 *
 * `--ss-brand-ontext` (resources/views/layouts/app.blade.php) already solves this for the light
 * page: it darkens the merchant's own colour until it clears 4.5:1, so no brand colour changes and
 * a colour-picker value is handled by the same arithmetic as the ten presets.
 *
 * IT DARKENS AGAINST A HARD-CODED LIGHT PAGE, `[249, 250, 251]`. For a merchant whose colour is
 * already dark the loop exits on its FIRST test and returns the brand unchanged — correct on
 * white, and the whole defect on a dark surface.
 *
 * MEASURED across /dashboard /finance /customer /order /product-list /setting, reading
 * `--theme-color` off the live body rather than nominating a colour:
 *
 *     light    0 of 44 brand-as-text sites fail
 *     dark    34 of 35 fail                       <- /setting contributes 20: its whole tab rail
 *
 * Store Syria's own blue, `#335da8`, measures 2.22:1 on the card surface `rgb(41,42,51)`.
 *
 * THE PREVIOUS PACKAGE MEASURED DARK AS COMPLIANT AND WROTE THAT INTO custom.css — "the BRIGHT
 * brand colour is already compliant there … so dark mode needs no change and must not get one."
 * That was measured on the DEFAULT theme-3 green (#10B77F, 5.34:1) and is true of it. It is not
 * true of a darker brand, and the product's own brand is one. The note is corrected in place
 * rather than deleted, because the reasoning was right and only its generality was wrong.
 *
 * WHAT THIS FILE DOES. It does not match strings for a colour. It PARSES the constants out of the
 * shipped script and RUNS the algorithm over every brand a merchant can actually choose — the ten
 * presets plus Store Syria's own — then asserts each result clears AA on the dark surface. Change
 * the surface, the step or the direction in the Blade file and this recomputes and fails.
 */
class BrandTextOnDarkContrastTest extends TestCase
{
    private const LAYOUT = 'resources/views/layouts/app.blade.php';

    /** The ten presets in public/assets/css/style.css, plus the colour this product ships. */
    private const BRANDS = [
        'theme-1' => '#584ed2', 'theme-2' => '#75c251', 'theme-3' => '#10B77F',
        'theme-4' => '#145388', 'theme-5' => '#b9406b', 'theme-6' => '#008ecc',
        'theme-7' => '#922c88', 'theme-8' => '#c0a145', 'theme-9' => '#48494b',
        'theme-10' => '#0c7785', 'store-syria' => '#335da8',
    ];

    /**
     * The CARD surface, not the page. Both were measured — page `rgb(34,36,44)`, card
     * `rgb(41,42,51)` — and for LIGHT text the LIGHTER surface is the harder one, so a value that
     * clears the card clears the page too. Computing against the page would under-shoot every
     * card on every screen, which is where the failing text actually sits.
     */
    private const DARK_SURFACE = [41, 42, 51];

    private function layout(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::LAYOUT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function lum(array $c): float
    {
        $f = static function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    private static function ratio(array $a, array $b): float
    {
        $x = self::lum($a);
        $y = self::lum($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** The surface and step the SHIPPED script uses, read out of it rather than restated here. */
    private function shippedConstants(): array
    {
        $src = $this->layout();

        $this->assertMatchesRegularExpression(
            '/--ss-brand-ontext-dark/',
            $src,
            'the dark counterpart of `--ss-brand-ontext` is not computed at all. On a dark page '
            . 'the light token is the brand colour unchanged, which measured 2.22:1.'
        );

        preg_match('/var\s+DARK_SURFACE\s*=\s*\[\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\]/', $src, $s);
        $this->assertCount(4, $s, 'premise: the script declares a DARK_SURFACE it measures against');

        preg_match('/lift\s*\+=\s*([0-9.]+)/', $src, $st);
        $this->assertCount(2, $st, 'premise: the script declares the step it lifts by');

        return [[(int) $s[1], (int) $s[2], (int) $s[3]], (float) $st[1]];
    }

    /**
     * THE SURFACE MUST NOT BE DARKER THAN THE ONE THE TEXT SITS ON. Using the page `[34,36,44]`
     * would pass its own arithmetic and still leave every card under AA — the quiet failure this
     * whole package is about.
     */
    public function test_the_script_measures_against_the_lighter_dark_surface(): void
    {
        [$surface] = $this->shippedConstants();

        $this->assertGreaterThanOrEqual(
            self::lum(self::DARK_SURFACE),
            self::lum($surface),
            'the script computes against a surface DARKER than the measured card `rgb(41,42,51)`. '
            . 'For light text the lighter surface is the harder one, so this under-shoots exactly '
            . 'the cards the failing text sits in.'
        );
    }

    /** It must LIFT towards white. Darkening is the light-page direction and makes dark worse. */
    public function test_the_script_lifts_towards_white_rather_than_darkening(): void
    {
        $src = $this->layout();
        $at = strpos($src, '--ss-brand-ontext-dark');
        $this->assertNotFalse($at);

        $window = substr($src, max(0, $at - 900), 1100);

        $this->assertMatchesRegularExpression(
            '/\(\s*255\s*-\s*v\s*\)/',
            $window,
            'the dark variant must move the brand TOWARDS WHITE — `v + (255 - v) * lift`. The '
            . 'light mechanism multiplies towards black, and on a dark page that is the wrong '
            . 'direction: applying it unconditionally is what took the header from 0 failures to 2.'
        );
    }

    /**
     * THE ALGORITHM IS RUN, NOT DESCRIBED. Every brand a merchant can choose goes through the
     * shipped surface and step, and every result must clear AA.
     *
     * @dataProvider brands
     */
    public function test_every_brand_a_merchant_can_choose_clears_aa_on_dark(string $name, string $hex): void
    {
        [$surface, $step] = $this->shippedConstants();
        $brand = self::rgb($hex);

        $lift = 0.0;
        $text = $brand;
        while ($lift <= 1.0) {
            $text = array_map(static fn ($v) => (int) round($v + (255 - $v) * $lift), $brand);
            if (self::ratio($text, $surface) >= 4.5) {
                break;
            }
            $lift += $step;
        }

        $got = self::ratio($text, self::DARK_SURFACE);

        $this->assertGreaterThanOrEqual(
            4.5,
            round($got, 2),
            sprintf(
                '%s (%s) lifts to rgb(%s) and still measures %.2f:1 on the card surface. The loop '
                . 'ran out of range before reaching AA, which means the step or the bound is wrong.',
                $name,
                $hex,
                implode(',', $text),
                $got
            )
        );
    }

    /**
     * AND IT MUST NOT WASH THE BRAND OUT. Clearing AA by lifting all the way to white would pass
     * the case above and lose the merchant's colour, which is the thing the F158 decision
     * protects. Every preset must keep a recognisable amount of its own hue.
     *
     * @dataProvider brands
     */
    public function test_the_lift_stops_at_aa_rather_than_washing_the_brand_out(string $name, string $hex): void
    {
        [$surface, $step] = $this->shippedConstants();
        $brand = self::rgb($hex);

        $lift = 0.0;
        $text = $brand;
        while ($lift <= 1.0) {
            $text = array_map(static fn ($v) => (int) round($v + (255 - $v) * $lift), $brand);
            if (self::ratio($text, $surface) >= 4.5) {
                break;
            }
            $lift += $step;
        }

        $this->assertLessThan(
            0.70,
            $lift,
            sprintf('%s (%s) had to be lifted %d%% of the way to white to clear AA. At that point '
                . 'it is no longer the merchant\'s colour.', $name, $hex, (int) round($lift * 100))
        );

        // A grey brand (theme-9 #48494b) has no hue to keep, so the spread test applies only to
        // the brands that have one. Lifting preserves differences in proportion, not absolutely.
        $brandSpread = max($brand) - min($brand);
        if ($brandSpread >= 24) {
            $this->assertGreaterThan(
                8,
                max($text) - min($text),
                sprintf('%s lost its hue: rgb(%s) is flat grey.', $name, implode(',', $text))
            );
        }
    }


    /** Both delivery paths. `rtl-custom.css` is served for Arabic and is a separate file. */
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    private function sheet(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * RTL IS A SEPARATE FILE AND HAS BEEN FORGOTTEN BEFORE. Arabic merchants are served
     * `rtl-custom.css`; a fix written only into `custom.css` reaches none of them, and the
     * English screenshots would show it working.
     *
     * @dataProvider sheets
     */
    public function test_the_dark_token_is_applied_in_both_delivery_paths(string $rel): void
    {
        $this->assertStringContainsString(
            'var(--ss-brand-ontext-dark',
            $this->sheet($rel),
            "{$rel} does not apply `--ss-brand-ontext-dark`. The LTR and RTL sheets are separate "
            . 'files and both are shipped.'
        );
    }

    /**
     * THE THREE EXCLUSIONS ARE THE THREE REGRESSIONS, each one measured in the running
     * application after it shipped into the working tree:
     *
     *   `.ss-btn`     the DESIGN SYSTEM'S OWN button class is not `.btn`, so `:not(.btn)` let it
     *                 through: rgb(120,148,198) on the cyan fill of "Add Product" and
     *                 "Create Order", 1.47:1, where both had been white.
     *   `.bg-white`   /finance hard-codes a WHITE card header inside a dark card. The dark token
     *                 on a white strip took a passing 6.42:1 to 3.07:1.
     *   `.active`     an ACTIVE tab sits on the BRAND FILL, so its label is white via
     *                 `--ss-onbrand`. Both arms hit it and produced rgb(51,93,168) on
     *                 rgb(51,93,168) -- THE SAME COLOUR TWICE, 1:1 -- on a tab that was correct
     *                 before this package touched it.
     *
     * @dataProvider sheets
     */
    public function test_the_rule_excludes_the_three_surfaces_that_are_not_the_dark_page(string $rel): void
    {
        $css = $this->sheet($rel);
        $heading = strpos($css, 'P2.5-DESIGN-G');
        $this->assertNotFalse($heading, 'premise: the block exists in ' . $rel);

        // ANCHORED ON THE COMMENT OPENER. Slicing from the heading starts INSIDE the block
        // comment, so the stripper below has no `/*` to open on and the prose survives -- and
        // the prose names `.nav-link` without `:not(.active)` while explaining why it needs it.
        // That is the second time this exact mistake has been made in this package's tests.
        $at = strrpos(substr($css, 0, $heading), '/*');
        $this->assertNotFalse($at, 'premise: the block is a comment followed by its rules');
        $block = substr($css, $at);

        // Only the declarations, so a mention in the prose cannot satisfy this.
        $rules = implode("\n", preg_split('/\}/', (string) preg_replace('#/\*.*?\*/#s', '', $block)));

        // THE EXCLUSIONS ARE CHECKED ON THE ARM THAT NEEDS THEM, NOT ANYWHERE IN THE BLOCK.
        // The first version of this asked whether `:not(.ss-btn)` appeared in the block at all,
        // and the bite walked straight through it: deleting it from the `.dash-content a` arm
        // left it in the `.bg-white a` arm, so the string was still there and the case stayed
        // GREEN while the 1.47:1 regression was back. A guard has to name the rule it guards.
        preg_match('/(html\[data-ss-theme="dark"\]\s+body\s+\.dash-content\s+a[^,{\s]*)/', $rules, $arm);
        $this->assertCount(2, $arm, "{$rel}: premise: the plain-anchor arm exists and is scoped to .dash-content");

        foreach ([':not(.btn)', ':not(.ss-btn)', ':not(.nav-link)'] as $exclusion) {
            $this->assertStringContainsString(
                $exclusion,
                $arm[1],
                "{$rel}: the plain-anchor arm `{$arm[1]}` must carry `{$exclusion}`. `.ss-btn` is "
                . "the design system's OWN button class and is not `.btn`, which is how "
                . 'rgb(120,148,198) ended up on the cyan fill of the primary call to action.'
            );
        }

        // The lookbehind keeps this off the anchor arm's OWN `:not(.nav-link)` exclusion,
        // which is a `.nav-link` that must NOT be followed by `:not(.active)`.
        preg_match_all('/(?<!:not\()\.nav-link(?!:not\(\.active\))/', $rules, $bare);
        $this->assertSame(
            [],
            $bare[0],
            "{$rel}: every `.nav-link` arm must be `:not(.active)`. An active tab sits on the "
            . 'BRAND FILL, and both arms hit it: rgb(51,93,168) on rgb(51,93,168), 1:1.'
        );

        $this->assertStringContainsString('.bg-white', $rules, "{$rel}: a light island inside dark mode keeps the LIGHT token.");
    }

    /**
     * AND THE LIGHT TOKEN IS WHAT THE LIGHT ISLAND GETS. Writing the dark token there would pass
     * the case above while reproducing the 3.07:1.
     *
     * @dataProvider sheets
     */
    public function test_the_light_island_takes_the_light_token(string $rel): void
    {
        $css = $this->sheet($rel);
        $at = strpos($css, '.bg-white .nav-link:not(.active)');
        $this->assertNotFalse($at, "premise: {$rel} scopes a rule to the light island");

        $rule = substr($css, $at, 420);
        $decl = substr($rule, (int) strpos($rule, '{'));

        $this->assertStringContainsString('var(--ss-brand-ontext,', $decl, "{$rel}: the white strip is a LIGHT surface and takes the light token.");
        $this->assertStringNotContainsString('--ss-brand-ontext-dark', $decl, "{$rel}: the dark token on the white strip measured 3.07:1.");
    }

    public static function sheets(): array
    {
        return ['custom.css' => ['public/css/custom.css'], 'rtl-custom.css' => ['public/css/rtl-custom.css']];
    }

    public static function brands(): array
    {
        $out = [];
        foreach (self::BRANDS as $name => $hex) {
            $out[$name] = [$name, $hex];
        }

        return $out;
    }
}

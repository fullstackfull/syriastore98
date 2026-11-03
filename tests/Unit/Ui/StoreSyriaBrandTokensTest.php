<?php

namespace Tests\Unit\Ui;

use App\Support\Branding\StoreSyriaBrand;
use PHPUnit\Framework\TestCase;

/**
 * P2.5-BR-01/02 — the brand is the logo's, and this test reads the logo to prove it.
 * =================================================================================================
 *
 * The design system's brand ramp has been derived from a logo twice now. The first derivation was
 * methodologically sound and still wrong, because it read
 * storage/app/public/uploads/2/*logoStore.svg — an upload under STORE ID 2, a tenant's own
 * teal-green recolour of the mark (measured hue 167.2–197.5°). Nothing caught it, because nothing
 * in the suite could see a picture.
 *
 * So this test opens the artwork. docs/design-system/brand/store-syria-logo-reference.png is a
 * committed copy of the authoritative wordmark the owner supplied on 2026-11-03 (the runtime copy
 * at storage/app/public/uploads/ is gitignored, so a test could not rely on it). Every claim below
 * is measured from those pixels at run time — if someone re-points the brand at a hue the logo does
 * not contain, this fails with the numbers.
 *
 * IT DOES NOT ASSERT A LOOK. It asserts three relationships the owner's decision actually requires:
 * the brand is inside the logo's gradient; the retired teal is referenced by nothing; and the
 * status hues stay far enough from the brand and from each other to remain distinguishable.
 */
class StoreSyriaBrandTokensTest extends TestCase
{
    private const LOGO = __DIR__ . '/../../../docs/design-system/brand/store-syria-logo-reference.png';
    private const SHEET = __DIR__ . '/../../../public/css/store-syria-ds.css';
    private const LAYOUT = __DIR__ . '/../../../resources/views/layouts/app.blade.php';

    /** @return array{hues: list<float>, dominant: string} */
    private function sampleLogo(): array
    {
        $im = imagecreatefrompng(self::LOGO);
        $this->assertNotFalse($im, 'the committed brand reference artwork could not be decoded');
        imagepalettetotruecolor($im);
        $w = imagesx($im);
        $h = imagesy($im);

        $hues = [];
        $counts = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;      // 0 opaque .. 127 transparent
                if ($alpha > 27) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                [$hue, $sat, $val] = $this->toHsv($r, $g, $b);
                if ($sat <= 0.25 || $val <= 0.15) {
                    continue;
                }
                $hues[] = $hue;
                $key = sprintf('#%02x%02x%02x', $r, $g, $b);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        imagedestroy($im);
        arsort($counts);

        return ['hues' => $hues, 'dominant' => (string) array_key_first($counts)];
    }

    /** @return array{0: float, 1: float, 2: float} hue in degrees, saturation, value */
    private function toHsv(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;

        $hue = 0.0;
        if ($d > 0) {
            if ($max === $r) {
                $hue = fmod(($g - $b) / $d, 6);
            } elseif ($max === $g) {
                $hue = ($b - $r) / $d + 2;
            } else {
                $hue = ($r - $g) / $d + 4;
            }
            $hue *= 60;
            if ($hue < 0) {
                $hue += 360;
            }
        }

        return [$hue, $max > 0 ? $d / $max : 0.0, $max];
    }

    private function hueOfHex(string $hex): float
    {
        $hex = ltrim($hex, '#');
        return $this->toHsv(
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2))
        )[0];
    }

    private function sheet(): string
    {
        return (string) file_get_contents(self::SHEET);
    }

    /** Resolve a token to a literal hex, following one level of var() into the primitives. */
    private function resolve(string $token, string $scope): ?string
    {
        $css = $this->sheet();

        $block = $scope === 'light'
            ? $this->between($css, ':root,', '[data-ss-theme="dark"] {')
            : $this->between($css, '[data-ss-theme="dark"] {', '@media (prefers-color-scheme: dark)');

        if (! preg_match('/' . preg_quote($token, '/') . '\s*:\s*([^;]+);/', $block, $m)) {
            return null;
        }
        $value = trim($m[1]);

        if (preg_match('/var\(\s*(--ss-[a-z0-9-]+)/i', $value, $v)) {
            // Primitives live in the first :root block, before the semantic layers.
            $primitives = $this->between($css, ':root {', 'LAYER 2');
            if (preg_match('/' . preg_quote($v[1], '/') . '\s*:\s*(#[0-9a-f]{3,8})\s*;/i', $primitives, $p)) {
                return strtolower($p[1]);
            }
            return null;
        }

        return preg_match('/^#[0-9a-f]{3,8}$/i', $value) ? strtolower($value) : null;
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $a = strpos($haystack, $start);
        $this->assertNotFalse($a, "could not find '$start' in the stylesheet");
        $b = strpos($haystack, $end, $a);
        $this->assertNotFalse($b, "could not find '$end' after '$start'");

        return substr($haystack, $a, $b - $a);
    }

    // =============================================================================================
    //  THE PREMISE — the artwork really is what the brand claims
    // =============================================================================================

    public function test_premise_the_committed_artwork_spans_the_hue_band_the_brand_claims(): void
    {
        ['hues' => $hues, 'dominant' => $dominant] = $this->sampleLogo();

        $this->assertGreaterThan(5000, count($hues),
            'the brand reference artwork has almost no chromatic pixels — is it the right file?');

        $this->assertEqualsWithDelta(StoreSyriaBrand::HUE_MIN, min($hues), 0.5,
            'the artwork no longer starts where StoreSyriaBrand::HUE_MIN says it does');
        $this->assertEqualsWithDelta(StoreSyriaBrand::HUE_MAX, max($hues), 0.5,
            'the artwork no longer ends where StoreSyriaBrand::HUE_MAX says it does');

        $this->assertSame(StoreSyriaBrand::BLUE, $dominant,
            'the single most common chromatic colour in the logo is no longer the brand blue');
    }

    /** And the cyan anchor really sits in the artwork's densest cyan band. */
    public function test_premise_the_cyan_anchor_is_where_the_artwork_is_densest_in_cyan(): void
    {
        ['hues' => $hues] = $this->sampleLogo();
        $cyanHue = $this->hueOfHex(StoreSyriaBrand::CYAN);

        $near = count(array_filter($hues, fn ($h) => abs($h - $cyanHue) <= 3.0));

        $this->assertGreaterThan(0.2 * count($hues), $near,
            sprintf('only %d of %d chromatic pixels sit within 3° of the cyan anchor (%.1f°)',
                $near, count($hues), $cyanHue));
    }

    // =============================================================================================
    //  THE TOKENS
    // =============================================================================================

    public function test_the_ramp_is_anchored_on_the_measured_logo_colours(): void
    {
        $primitives = $this->between($this->sheet(), ':root {', 'LAYER 2');

        $this->assertMatchesRegularExpression(
            '/--ss-blue-600:\s*' . preg_quote(StoreSyriaBrand::BLUE, '/') . '\s*;/i', $primitives,
            'blue-600 is no longer the logo\'s own blue');

        preg_match('/--ss-cyan-600:\s*(#[0-9a-f]{6})\s*;/i', $primitives, $m);
        $this->assertNotEmpty($m, 'the cyan ramp has no 600 step');
        $this->assertEqualsWithDelta(
            $this->hueOfHex(StoreSyriaBrand::CYAN), $this->hueOfHex($m[1]), 1.5,
            'cyan-600 drifted off the logo\'s cyan hue');
    }

    /**
     * EVERY brand-carrying semantic token, in BOTH themes, resolves inside the logo's own gradient.
     * This is the assertion that would have caught the teal ramp in Sprint 01.
     */
    public function test_every_brand_token_resolves_inside_the_logos_gradient(): void
    {
        $tokens = ['--ss-brand', '--ss-brand-hover', '--ss-brand-active', '--ss-brand-accent',
            '--ss-link', '--ss-border-brand', '--ss-focus-ring'];

        foreach (['light', 'dark'] as $scope) {
            foreach ($tokens as $token) {
                $hex = $this->resolve($token, $scope);
                $this->assertNotNull($hex, "$token did not resolve to a colour in the $scope theme");

                $hue = $this->hueOfHex($hex);
                $this->assertTrue(StoreSyriaBrand::hueIsInBand($hue), sprintf(
                    '%s resolves to %s (hue %.1f°) in the %s theme, outside the logo\'s %.1f–%.1f° gradient',
                    $token, $hex, $hue, $scope, StoreSyriaBrand::HUE_MIN, StoreSyriaBrand::HUE_MAX));
            }
        }
    }

    /** The retired teal ramp is referenced by nothing. Keeping the values is fine; using them is not. */
    public function test_nothing_consumes_the_retired_teal_ramp(): void
    {
        $css = $this->sheet();
        $afterPrimitives = substr($css, strpos($css, 'LAYER 2'));

        $this->assertStringNotContainsString('var(--ss-teal-', $afterPrimitives,
            'a semantic or component token still reads the retired 172° teal ramp');
    }

    // =============================================================================================
    //  THE OWNER'S DISTINCTNESS RULE
    // =============================================================================================

    /**
     * "Keep warning, error, success and informational colors semantically distinct."
     *
     * Moving the brand onto blue put it ten degrees from the old info blue, which is why info moved
     * to indigo. This pins the outcome: no status hue may sit inside the brand's gradient, and no
     * two statuses may sit within 25° of each other.
     */
    public function test_no_status_hue_collides_with_the_brand_or_with_another_status(): void
    {
        $statuses = [];
        foreach (['success', 'warning', 'danger', 'info'] as $name) {
            $hex = $this->resolve("--ss-{$name}-fg", 'light');
            $this->assertNotNull($hex, "--ss-{$name}-fg did not resolve");
            $statuses[$name] = $this->hueOfHex($hex);
        }

        foreach ($statuses as $name => $hue) {
            $this->assertFalse(StoreSyriaBrand::hueIsInBand($hue), sprintf(
                'the %s status sits at %.1f°, inside the brand gradient — a merchant cannot tell '
                . 'a status from the brand', $name, $hue));
        }

        foreach ($statuses as $a => $ha) {
            foreach ($statuses as $b => $hb) {
                if ($a >= $b) {
                    continue;
                }
                $gap = min(abs($ha - $hb), 360 - abs($ha - $hb));
                $this->assertGreaterThan(25.0, $gap, sprintf(
                    '%s (%.1f°) and %s (%.1f°) are only %.1f° apart', $a, $ha, $b, $hb, $gap));
            }
        }
    }

    // =============================================================================================
    //  THE HOST CHROME
    // =============================================================================================

    /**
     * The admin layout's no-colour fallback. A store that HAS chosen a colour still wins; this is
     * only the default, and it used to be theme-3 green at hue 160° — 29.5° outside the gradient,
     * which put two identities on every page.
     */
    public function test_the_chrome_default_is_the_brand_and_not_theme_three(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT);

        $this->assertMatchesRegularExpression(
            '/if \(!isset\(\$setting\[.color.\]\)\) \{\s*\$themeColor = .custom-color.;/', $layout,
            'the admin layout fell back to a theme-N class again, so a store that has chosen no '
            . 'colour wears a brand the logo does not contain');

        $this->assertStringContainsString('StoreSyriaBrand::CHROME_DEFAULT', $layout,
            'the chrome default is a literal again rather than the one brand source');

        $this->assertTrue(
            StoreSyriaBrand::hueIsInBand($this->hueOfHex(StoreSyriaBrand::CHROME_DEFAULT)),
            'the chrome default is not a colour the logo contains');
    }

    /** White-labelling still wins: a store with its own colour is untouched by the default. */
    public function test_a_store_that_chose_a_colour_still_overrides_the_default(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT);

        $this->assertStringContainsString("\$color = \$setting['color'];", $layout,
            'the per-store colour branches were removed, so merchants lost white-labelling');
    }
}

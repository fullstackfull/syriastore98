<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P2.5-WAVE-18 — every required merchant field carries the design system's required marker.
 * ================================================================================================
 *
 * MEASURED BEFORE THE CHANGE: 14 required fields carried a marker and 149 did not, across 56
 * merchant views. The convention was not missing -- it was already defined, already documented and
 * already shipping:
 *
 *   public/css/store-syria-ds.css:907   .ss-label__required { color: var(--ss-danger); ... }
 *                                       with the reason beside it: "Optional-marking is better
 *                                       than required-marking when most fields are required, but
 *                                       the host's forms mark required, so we match the host."
 *   resources/views/components/ss/secret-field.blade.php:41   already emits exactly this shape.
 *
 * So this slice applies an existing decision to the views that never received it. It is not a new
 * design choice and does not need one.
 *
 * THE MARKER SITS AFTER THE LABEL, NOT INSIDE IT. `Form::label()` escapes its text unless a fourth
 * argument disables escaping, and threading `$escape_html = false` through 149 call sites to
 * inject markup is a worse trade than one sibling span. It changes nothing for assistive
 * technology either way: the marker is `aria-hidden`, and what actually announces the constraint
 * is the control's own `required` attribute.
 *
 * THIS TEST IS THE AUTHORITY, not the tool. docs/development/phase-2.5/tools/mark-required-fields.py
 * applies the same rule and is re-runnable, but if the two ever disagree this one fails and the
 * tool is what gets corrected.
 *
 * WHY front_end IS EXCLUDED: the storefront is Phase 03 by the approved six-phase plan. Excluded
 * by directory, and the count below is asserted so the exclusion cannot quietly swallow the
 * merchant tree too.
 */
class RequiredFieldMarkerTest extends TestCase
{
    /**
     * ONE SHAPE, and the loosened version is gone deliberately.
     *
     * The first draft of this test also accepted `<sup class="text-danger">*</sup>`, the shape 14
     * fields already used -- and then MEASURING it showed why that was wrong: `.text-danger` is
     * `rgb(255,58,110)`, IDENTICAL in style.css and style-dark.css, and it fails AA on every
     * ground this application has: 3.45:1 on the white modal, 4.13:1 on the dark card, 4.48:1 on
     * the dark page. Accepting it would have let the ratchet certify an illegible marker.
     *
     * Only TWO merchant views carried that shape (plans/request and payment/authorizenet, five
     * each, one of them with a DUPLICATE class attribute), so converging them cost ten lines and
     * left the merchant tree with a single marker. The other 34 `<sup>` markers are all under
     * front_end/, which is Phase 03 and out of this slice.
     */
    private const MARKER_SHAPES = '/ss-label__required/';

    /** The census must really have walked the merchant tree. */
    private const MINIMUM_VIEWS_PARSED = 350;

    public function test_premise_the_census_walks_the_merchant_views(): void
    {
        [$parsed, , ] = $this->census();

        $this->assertGreaterThan(self::MINIMUM_VIEWS_PARSED, $parsed,
            'Only ' . $parsed . ' merchant views were parsed. The walk is broken and the '
            . 'assertion below proves nothing.');
    }

    /** And it must really find required fields, or "none unmarked" is vacuously true. */
    public function test_premise_the_census_finds_required_fields_at_all(): void
    {
        [, $withMarker, $without] = $this->census();

        $this->assertGreaterThan(100, $withMarker + count($without),
            'The census found ' . ($withMarker + count($without)) . ' required fields in the '
            . 'merchant views. It measured 163 when this slice was written, so a number this low '
            . 'means the detector stopped matching and the result is meaningless.');
    }

    /**
     * Bite-tested: deleting the marker from resources/views/tag/create.blade.php fails this with
     * that file and the label named.
     */
    public function test_no_required_merchant_field_is_left_unmarked(): void
    {
        [, , $without] = $this->census();

        $this->assertSame([], $without,
            "These required fields carry no required marker, although the design system defines "
            . "one at store-syria-ds.css:907:\n  " . implode("\n  ", $without));
    }

    // =============================================================================================
    //  THE MARKER'S COLOUR — found by MEASURING, not by reading the file
    // =============================================================================================

    /**
     * `.ss-label__required` is painted `var(--ss-danger)`, a SEMANTIC token: #b3261e in a light
     * scope and #f3a9a4 in a dark one. The modal is a LIGHT surface inside a DARK scope -- the
     * exact collision P2.5-WAVE-17a repaired for the Cancel button, one element over.
     *
     * MEASURED on the running page the moment the 149 markers landed, before any of this was
     * suspected: 24 markers across four combinations, and all twelve DARK ones read 1.91:1 --
     * rgb(243,169,164) on the white modal. Shipping the markers without this rule would have put
     * an illegible asterisk on every required field in every dark modal, which is worse than the
     * unmarked state it replaced. The static ratchet above would have passed throughout.
     */
    public function test_the_semantic_danger_token_fails_on_the_white_modal_in_dark(): void
    {
        $this->assertSame(1.91, round($this->ratio(self::DANGER_DARK, self::MODAL_BG), 2));
        $this->assertLessThan(4.5, $this->ratio(self::DANGER_DARK, self::MODAL_BG));
    }

    /** 6.54:1 — the light-scope primitive, which is what the rule pins. */
    public function test_the_primitive_clears_aa_on_the_white_modal(): void
    {
        $this->assertSame(6.54, round($this->ratio(self::RED_700, self::MODAL_BG), 2));
        $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::RED_700, self::MODAL_BG));
    }

    /**
     * AND WHY IT MUST BE CONFINED TO THE MODAL. On the two dark grounds the semantic token is
     * RIGHT (7.48:1 and 8.12:1) and the primitive would be unreadable. An unconfined rule trades
     * one failure for two.
     */
    public function test_an_unconfined_repair_would_destroy_the_dark_grounds(): void
    {
        foreach ([[self::CARD_BG, 7.48, 2.18], [self::PAGE_BG, 8.12, 2.37]] as [$ground, $semantic, $primitive]) {
            $this->assertSame($semantic, round($this->ratio(self::DANGER_DARK, $ground), 2));
            $this->assertGreaterThanOrEqual(4.5, $this->ratio(self::DANGER_DARK, $ground));

            $this->assertSame($primitive, round($this->ratio(self::RED_700, $ground), 2));
            $this->assertLessThan(4.5, $this->ratio(self::RED_700, $ground));
        }
    }

    public function test_the_design_system_sheet_carries_the_confined_marker_override(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s+\.modal-content\s+\.ss-label__required[^{]*\{'
            . '[^}]*color:\s*var\(--ss-red-700\)/i',
            $css,
            'The modal-scoped required-marker override is missing, so every required asterisk in '
            . 'every dark modal reads at 1.91:1.');

        $this->assertStringNotContainsString('[data-ss-theme="dark"] .ss-label__required {', $css,
            'An UNCONFINED dark rule for the marker would break the two dark grounds -- see the '
            . 'test above for the arithmetic.');
    }

    // =============================================================================================

    private const MODAL_BG    = [255, 255, 255]; // --bs-modal-bg, style-dark.css:6238
    private const CARD_BG     = [41, 42, 51];
    private const PAGE_BG     = [34, 36, 44];
    private const RED_700     = [179, 38, 30];   // --ss-red-700 #b3261e, a LAYER-1 primitive
    private const DANGER_DARK = [243, 169, 164]; // what --ss-danger resolves to in a dark scope

    private function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(array $a, array $b): float
    {
        $l1 = $this->luminance($a);
        $l2 = $this->luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * @return array{0:int, 1:int, 2:list<string>} views parsed, marked count, unmarked descriptions
     */
    private function census(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $parsed = 0;
        $marked = 0;
        $without = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace($root . '/', '', $file->getPathname());

            if (str_starts_with($relative, 'front_end/')) {
                continue;   // Phase 03
            }

            $parsed++;
            $source = (string) file_get_contents($file->getPathname());

            // Comments go first so the detector cannot match its own prose.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
            $source = preg_replace('/<!--.*?-->/s', '', $source);

            foreach ($this->blocks($source) as $block) {
                if (! $this->isRequired($block) || ! $this->hasLabel($block)) {
                    continue;
                }

                if (preg_match(self::MARKER_SHAPES, $block)) {
                    $marked++;
                    continue;
                }

                $label = preg_match("/__\(\s*'([^']{1,60})'/", $block, $m) ? $m[1] : '(unnamed)';
                $without[] = $relative . ' -- ' . $label;
            }
        }

        sort($without);

        return [$parsed, $marked, $without];
    }

    /** @return list<string> the body of each form-group / column wrapper */
    private function blocks(string $source): array
    {
        $parts = preg_split(
            '/(<div[^>]*class="[^"]*(?:form-group|col-md-|col-lg-|col-sm-|col-xl-|col-12)[^"]*"[^>]*>)/',
            $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        $out = [];
        for ($i = 2; $i < count($parts); $i += 2) {
            $out[] = $parts[$i];
        }

        return $out;
    }

    private function isRequired(string $block): bool
    {
        return (bool) preg_match(
            '/[\'"]required[\'"]\s*=>\s*[\'"]required[\'"]'
            . '|(?<![\w-])required(?:\s*=\s*["\']required["\'])?\s*(?=[\/>\s])/i',
            $block);
    }

    private function hasLabel(string $block): bool
    {
        return (bool) preg_match('/\{\{\s*Form::label|\{!!\s*Form::label|<label\b/', $block);
    }
}

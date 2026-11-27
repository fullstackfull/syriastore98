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
     * THE COUNT IS RATCHETED, NOT FLOORED, because a floor of 100 lets a 165-field detector decay
     * to 101 in silence and the figure that proves `isRequired()` was NARROWED and not WEAKENED
     * would then live only in a docblock.
     *
     * 165 is measured on this tree: 167 before the translated-copy redaction, 165 after, the two
     * removed being `AppSetting/index.blade.php` (pre-existing, a note rather than a control) and
     * `setting/recaptcha_setting.blade.php` (GAP-060 WS5's instruction). See `isRequired()`.
     *
     * IF THIS FAILS LEGITIMATELY — somebody added or removed a required merchant field — the fix
     * is to change the number here in the same commit, so the change is visible in a diff rather
     * than absorbed. A DROP is the one to look at hard: it means either a field lost its
     * `required` attribute or the detector stopped seeing a shape it used to see.
     */
    public function test_the_detected_required_field_count_is_the_measured_one(): void
    {
        [, $withMarker, $without] = $this->census();

        /* 165 -> 169 on 2026-11-26, AND IT IS TWO WORKSTREAMS, NOT ONE. The +4 is written out
           because the integration merge took `ws/p6-ops`'s side of this block and credited the
           whole move to OPS-003's "three controls", which left the arithmetic one short and the
           other workstream unnamed. Restored on 2026-11-27 and MEASURED per view rather than
           reasoned from the diffs:

             +2  `superadmin/platform_incidents/index.blade.php` [OPS-003] -- `title` and
                 `severity` on the "record an incident" form.
             +1  `superadmin/platform_incidents/show.blade.php` [OPS-003] -- `note` on the
                 add-a-note form.
             +1  `kyc/index.blade.php` [GAP-194 / KYC-002] -- the document upload form.

           THE KYC BLOCK CARRIES FOUR REQUIRED CONTROLS AND MOVES THIS COUNT BY ONE, which is the
           part worth keeping: `blocks()` splits on the field WRAPPER, so one `col-12` is one row
           however many controls sit inside it. All four labels carry the marker. A reader who saw
           `+4` attributed to three incident controls would conclude three of KYC's four were
           unmarked; measured 2026-11-27, `kyc/index.blade.php` contributes exactly 1 counted
           block and 0 unmarked ones.

           A RISE, which this docblock names as the benign direction, and all four arrive MARKED --
           the first version of the incident views had the `required` attribute and no marker beside
           the label, and this ratchet is what said so. */
        $this->assertSame(169, $withMarker + count($without), sprintf(
            'The detector now finds %d required merchant fields; it measured 169 after the '
            . 'platform incident log and kyc/index.blade.php\'s upload form landed (165 when the '
            . 'translated-copy redaction was added to isRequired()). A RISE means a required field '
            . 'was added (fine — update this number, and say which view). A DROP means one lost '
            . 'its attribute, or the detector stopped matching a shape, which is the direction that '
            . 'turns this whole ratchet vacuous.',
            $withMarker + count($without)
        ));
    }

    /**
     * THE DETECTOR MUST READ MARKUP AND NOT THE PRODUCT'S OWN COPY — both directions, so the
     * redaction cannot be loosened into "nothing is ever required" and cannot drift back either.
     *
     * Added when a merchant-facing instruction containing the word "required" broke the ratchet;
     * `isRequired()`'s docblock has the measurement and names the pre-existing false positive the
     * same change removed.
     */
    public function test_the_detector_reads_attributes_and_not_translated_prose(): void
    {
        /* NEGATIVE: the word in copy the screen prints is not a required field. */
        $this->assertFalse($this->isRequired(
            '<label for="x">{{ __(\'X\') }}</label>'
            . '<select id="x" name="x"></select>'
            . '<p>{{ __(\'v2 makes the challenge box a required field; v3 does not.\') }}</p>'
        ), 'a sentence containing the word "required" is being read as a required control');

        $this->assertFalse($this->isRequired(
            '<p>{{ __("Use the Is Checkout Required feature to prevent guest checkout.") }}</p>'
        ), 'the same, in double quotes');

        /* POSITIVE CONTROLS: every shape that really is a required control still matches, including
           one whose sibling label is a translated string — otherwise this redaction would be a way
           of switching the ratchet off. */
        $this->assertTrue($this->isRequired('<input type="text" name="x" required>'),
            'a bare required attribute is no longer detected, so the ratchet is switched off');
        $this->assertTrue($this->isRequired('<input type="text" name="x" required/>'));
        $this->assertTrue($this->isRequired('<input type="text" name="x" required="required">'));
        $this->assertTrue($this->isRequired("Form::text('x', null, ['required' => 'required'])"));
        $this->assertTrue($this->isRequired(
            '<label for="x">{{ __(\'Name\') }}</label><input id="x" name="x" required>'
        ), 'a required control beside a translated label is no longer detected');
        $this->assertTrue($this->isRequired(
            "{{ Form::text('x', null, ['required' => 'required', 'placeholder' => __('Enter a name')]) }}"
        ), 'a required attribute in the same array as a translated placeholder is no longer detected');
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

    /**
     * TRANSLATED TEXT IS NOT MARKUP, and this detector could not tell the difference.
     *
     * The second limb matches a bare `required` followed by whitespace or `>`, which is how it
     * finds `<input required>`. It matched the WORD "required" in a sentence the SCREEN PRINTS just
     * as happily — so a merchant-facing instruction containing it turned its column into a
     * "required field with no marker" and failed this test. CLAUDE.md §3 records this family eight
     * times for COMMENTS, in three syntaxes; this is the same fault in a fourth, and the one a
     * comment-stripping census does not cover, because the string is not a comment — it is the
     * product's own copy.
     *
     * ONE OF THE TWO BLOCKS THIS STOPS MATCHING IS NOT NEW, which is what settles that this is a
     * detector fix rather than an accommodation for the sentence that exposed it.
     * `AppSetting/index.blade.php:732` prints
     * `__('Use the Is Checkout Required feature to prevent guest checkout, …')` — a NOTE, not a
     * control — and this detector has been counting it as a required field all along. It passed
     * only because that block carries a marker for a different reason, so the ratchet was green
     * for the wrong reason on that row and would have stayed that way. The other is
     * `setting/recaptcha_setting.blade.php`, whose GAP-060 WS5 instruction says "v2 makes the
     * challenge box a required field".
     *
     * MEASURED IN BOTH DIRECTIONS: 167 blocks detected before, 165 after, and the two that change
     * are exactly those two files. Nothing real is lost — a genuine `'required' => 'required'` or a
     * bare `required` attribute is never inside a `__()` call — and the premise above still holds
     * 165 against its floor of 100.
     *
     * The REDACTION IS LOCAL TO THIS PREDICATE, not applied to the census's source, so the marker
     * match and the `__('…')` label-name extraction in the failure message still see the real text.
     *
     * A REMAINING GAP, STATED RATHER THAN IMPLIED: this redacts `__()` only. Copy printed through
     * `@lang()` or `trans()`, or raw English text with no translation call at all, would still read
     * as markup to the second limb. Nothing in the merchant tree currently trips that — the count
     * ratchet below is 165 either way — but it is a narrower fix than "the detector no longer reads
     * prose", and the next person to hit it should widen this rather than reword their copy.
     */
    private function isRequired(string $block): bool
    {
        $withoutCopy = preg_replace(
            ['/__\(\s*\'(?:[^\'\\\\]|\\\\.)*\'/s', '/__\(\s*"(?:[^"\\\\]|\\\\.)*"/s'],
            ["__('…'", '__("…"'],
            $block
        );

        return (bool) preg_match(
            '/[\'"]required[\'"]\s*=>\s*[\'"]required[\'"]'
            . '|(?<![\w-])required(?:\s*=\s*["\']required["\'])?\s*(?=[\/>\s])/i',
            (string) $withoutCopy);
    }

    private function hasLabel(string $block): bool
    {
        return (bool) preg_match('/\{\{\s*Form::label|\{!!\s*Form::label|<label\b/', $block);
    }
}

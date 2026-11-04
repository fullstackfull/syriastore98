<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 header-triggers-nameless-below-992 — the account trigger and the store switcher took their
 * accessible name ONLY from a span the theme hides on a phone.
 * =================================================================================================
 *
 * MEASURED. Both triggers in `resources/views/partision/header.blade.php` named themselves from a
 * visible `span.hide-mob` — the greeting inside the account trigger, the store name inside the
 * switcher — and `public/assets/css/style.css:17193` sets `.dash-header .dash-h-item .hide-mob` to
 * `display: none` inside `@media (max-width: 991px)`. The same rule is mirrored once each in
 * `style-dark.css`, `style-rtl.css` and `rtl-style-dark.css`, so it applies in all four
 * theme/direction combinations. Neither anchor carried `aria-label`, `aria-labelledby` or `title`.
 *
 * THE TWO HALVES FAILED DIFFERENTLY, which is why this file asserts the name EQUALS a string
 * rather than asserting it is non-empty:
 *
 *   ACCOUNT TRIGGER — the greeting span and the chevron both carry `hide-mob`, and the inline SVG
 *   has no `role` and no `<title>` child, so below 992px the accessible name was EMPTY and a
 *   screen reader announced bare "link".
 *
 *   STORE SWITCHER — its chevron carries NO `hide-mob` and had no `aria-hidden`, and
 *   `public/assets/fonts/tabler-icons.min.css` gives `.ti-chevron-down:before` the content
 *   `"\ea5f"`. Generated content is part of AccName step 2F, so the whole accessible name was the
 *   single Private-Use codepoint U+EA5F: silence on VoiceOver, garbage on a braille display. A
 *   test asserting "the name is empty" would have PASSED for this control while it was broken.
 *
 * The switcher also carried `data-bs-placement="bottom" data-bs-original-title="Select Store"`,
 * which named nothing: `data-bs-original-title` is not in the accessible-name computation, and the
 * only code that ever reads it is Bootstrap's own Tooltip — which is constructed solely for
 * `[data-bs-toggle="tooltip"]` (`dash.js:561`, `side-dash.js:620`), while this anchor's
 * `data-bs-toggle` is `dropdown`. A dead attribute that reads like a label.
 *
 * `.visually-hidden` SIBLING, NOT `aria-label` ON THE ANCHOR. An `aria-label` would override the
 * visible text at 992px and up, so the store name would leave the switcher's accessible name and
 * the account trigger would stop announcing the merchant — a NEW WCAG 2.5.3 Label-in-Name failure
 * traded for the old one. This project has already written that rule down and enforces it:
 * `tests/Unit/Ui/PageActionRegionContractTest.php` — "a control whose own VISIBLE TEXT names it
 * needs no aria-label, and should not have one". The additive span matches the precedent already in
 * this partial on the notification bell (`<i aria-hidden="true">` plus `<span
 * class="visually-hidden">`). Result: at 992px+ the switcher reads "Acme Store Select store", which
 * still contains its visible text, so 2.5.3 holds; below 992px it reads "Select store".
 *
 * AND IT NEEDED A CSS CHANGE IN BOTH CUSTOM SHEETS, which is the part that would have made the
 * markup fix silently useless at the exact width the row is about. `public/css/custom.css` and
 * `public/css/rtl-custom.css` both set `.dash-header .dash-head-link .text-store, .dash-header
 * .dash-head-link.dropdown-toggle span { display: none }` inside `@media (max-width: 575px)`. The
 * ACCOUNT anchor carries `dropdown-toggle`, and Bootstrap's `.visually-hidden`
 * (`style.css:8066-8077`) declares no `display` at all — it is `position`, `width`, `height`,
 * `padding`, `margin`, `overflow`, `clip`, `white-space` and `border`, every one `!important`, and
 * no `display` for a `display: none` to lose to. So at 390px the new span was `display: none` and
 * the repair would have failed on a phone. The second selector is now
 * `span:not(.visually-hidden)`. `layouts/app.blade.php` loads `rtl-custom.css` INSTEAD of
 * `custom.css` for Arabic (lines 158/166 against 162/170, in light and dark both), so editing one
 * sheet reaches only half the merchants — hence the assertion that both carry it, identically.
 */
class HeaderDropdownTriggerNamesTest extends TestCase
{
    /** The breakpoint below which `.hide-mob` is `display: none`. */
    private const MOBILE_MAX = 991;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function header(): string
    {
        $path = $this->root() . '/resources/views/partision/header.blade.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The opening tag plus inner HTML of one of the two triggers.
     *
     * @return array{attrs:string, inner:string}
     */
    private function trigger(string $which): array
    {
        $markup = $this->header();

        $pattern = $which === 'switcher'
            ? '/<a\b([^>]*megamenu-btn[^>]*)>(.*?)<\/a>/s'
            : '/<a\b([^>]*dropdown-toggle arrow-none me-0"[^>]*)>(.*?)<\/a>/s';

        $this->assertSame(1, preg_match($pattern, $markup, $m),
            "premise: the {$which} trigger was not found in the shared header, so every rule "
            . 'below this point is vacuous');

        return ['attrs' => $m[1], 'inner' => $m[2]];
    }

    /** The codepoint `.ti-chevron-down:before` injects, read from the font stylesheet itself. */
    private function chevronGlyph(): string
    {
        $css = (string) file_get_contents($this->root() . '/public/assets/fonts/tabler-icons.min.css');

        $this->assertSame(1,
            preg_match('/\.ti-chevron-down:before\s*\{\s*content:\s*"\\\\([0-9a-f]{4})"/i', $css, $m),
            'premise: .ti-chevron-down no longer injects generated content, so the glyph-leak half '
            . 'of this row no longer has a mechanism — re-measure before trusting this file');

        return mb_chr((int) hexdec($m[1]), 'UTF-8');
    }

    /**
     * The accessible name a screen reader computes for one of these triggers, modelled from the
     * markup and from the one CSS rule that matters at this breakpoint.
     *
     * Deliberately NOT "is the name non-empty": the switcher's broken name was U+EA5F, which is
     * not empty. Only comparing against the expected string catches both halves of this row.
     *
     * @param  bool  $below992  apply `.hide-mob { display: none }`, i.e. a phone viewport
     */
    private function accessibleName(string $inner, bool $below992, bool $dropAriaHidden = false): string
    {
        $inner = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $inner);

        if ($dropAriaHidden) {
            $inner = (string) preg_replace('/\s+aria-hidden="true"/', '', $inner);
        }

        $name = '';

        foreach ($this->topLevelChildren($inner) as $child) {
            preg_match('/^<[a-z]+\b([^>]*)>/i', $child, $open);
            $attrs = $open[1] ?? '';

            // AccName step 2A: an aria-hidden subtree contributes nothing.
            if (preg_match('/aria-hidden\s*=\s*"true"/', $attrs) === 1) {
                continue;
            }

            // display: none contributes nothing either (style.css:17193, <= 991px).
            if ($below992 && preg_match('/class\s*=\s*"[^"]*\bhide-mob\b/', $attrs) === 1) {
                continue;
            }

            // AccName step 2F: CSS generated content IS part of the name.
            if (preg_match('/class\s*=\s*"[^"]*\bti-chevron-down\b/', $attrs) === 1) {
                $name .= $this->chevronGlyph();

                continue;
            }

            // Everything else contributes its text. A Blade `__()` echo renders its key when the
            // string is untranslated, so the key is the right stand-in for the rendered text.
            $text = (string) preg_replace('/<[^>]+>/', ' ', $child);
            $text = (string) preg_replace('/\{\{\s*__\(\s*\'([^\']*)\'\s*\)\s*\}\}/', '$1', $text);
            $name .= ' ' . $text . ' ';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Split inner HTML into its top-level element children, depth-aware so an `<svg>`'s `<path>`
     * children are not mistaken for siblings.
     *
     * @return list<string>
     */
    private function topLevelChildren(string $inner): array
    {
        $children = [];
        $depth = 0;
        $buffer = '';

        preg_match_all('/<\/?([a-z]+)\b[^>]*?(\/?)>|[^<]+/i', $inner, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            $raw = $token[0];

            if ($raw[0] !== '<') {
                if ($depth === 0) {
                    $children[] = $raw;
                } else {
                    $buffer .= $raw;
                }

                continue;
            }

            $closing = str_starts_with($raw, '</');
            $selfClosing = ($token[2] ?? '') === '/';

            if ($closing) {
                $depth--;
                $buffer .= $raw;
                if ($depth === 0) {
                    $children[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            if ($selfClosing) {
                if ($depth === 0) {
                    $children[] = $raw;
                } else {
                    $buffer .= $raw;
                }

                continue;
            }

            $depth++;
            $buffer .= $raw;
        }

        if ($buffer !== '') {
            $children[] = $buffer;
        }

        return $children;
    }

    // ---------------------------------------------------------------------------------------
    // PREMISES — without these the rules below can pass for the wrong reason.
    // ---------------------------------------------------------------------------------------

    public function test_the_breakpoint_that_causes_this_is_still_real_in_all_four_theme_sheets(): void
    {
        /*
         * NEGATIVE CONTROL. The wrong repair is to delete or weaken this rule, which would make
         * the greeting and the store name visible again inside 40x40 icon chrome on a phone —
         * a layout change, not an accessible-name repair. All four copies must survive.
         */
        $sheets = [
            'public/assets/css/style.css',
            'public/assets/css/style-dark.css',
            'public/assets/css/style-rtl.css',
            'public/assets/css/rtl-style-dark.css',
        ];

        foreach ($sheets as $sheet) {
            $css = (string) file_get_contents($this->root() . '/' . $sheet);

            $this->assertSame(1,
                preg_match('/@media\s*\(max-width:\s*' . self::MOBILE_MAX . 'px\)\s*\{(?:[^{}]|\{[^{}]*\})*'
                    . '\.dash-header\s+\.dash-h-item\s+\.hide-mob\s*\{\s*display:\s*none/s', $css),
                "{$sheet} no longer hides .hide-mob below " . (self::MOBILE_MAX + 1) . 'px — either '
                . 'the theme changed (re-measure this row) or a repair weakened the rule instead of '
                . 'adding a name that survives it');
        }
    }

    public function test_the_name_simulator_can_still_see_the_glyph_leak(): void
    {
        /*
         * THE CONTROL THAT PROVES THIS TEST CAN SEE THE DEFECT. Take the repaired switcher, strip
         * the `aria-hidden` back off its chevron, and the computed name must pick the Private-Use
         * codepoint back up. If this stops failing, the simulator has gone blind and the rules
         * below are decoration.
         */
        $glyph = $this->chevronGlyph();
        $this->assertSame('ea5f', strtolower(dechex(mb_ord($glyph, 'UTF-8'))),
            'the chevron codepoint changed; this row was measured against U+EA5F');

        $switcher = $this->trigger('switcher');

        $withAriaHidden = $this->accessibleName($switcher['inner'], true);
        $withoutAriaHidden = $this->accessibleName($switcher['inner'], true, dropAriaHidden: true);

        $this->assertStringNotContainsString($glyph, $withAriaHidden,
            'the repaired switcher still leaks the chevron glyph into its accessible name');
        $this->assertStringContainsString($glyph, $withoutAriaHidden,
            'the simulator no longer notices the glyph leak, so it cannot see this defect any more');
    }

    // ---------------------------------------------------------------------------------------
    // THE RULE
    // ---------------------------------------------------------------------------------------

    public function test_the_account_trigger_is_named_on_a_phone(): void
    {
        $name = $this->accessibleName($this->trigger('account')['inner'], below992: true);

        $this->assertSame('Your account', $name, sprintf(
            'below %dpx the account trigger computes the accessible name %s. Its greeting span and '
            . 'its chevron both carry .hide-mob, and its inline SVG has no role and no <title> '
            . 'child, so a screen reader announces bare "link" on every merchant screen.',
            self::MOBILE_MAX + 1, var_export($name, true)));
    }

    public function test_the_store_switcher_is_named_on_a_phone(): void
    {
        $name = $this->accessibleName($this->trigger('switcher')['inner'], below992: true);

        $this->assertSame('Select store', $name, sprintf(
            'below %dpx the store switcher computes the accessible name %s. Its store-name span '
            . 'carries .hide-mob, and its chevron carries neither .hide-mob nor aria-hidden, so '
            . 'the name was the Private-Use codepoint U+EA5F — silence on VoiceOver, garbage on '
            . 'braille, and NOT empty, which is why a non-empty assertion would have passed.',
            self::MOBILE_MAX + 1, var_export($name, true)));
    }

    public function test_the_visible_text_still_names_both_triggers_on_a_desktop(): void
    {
        /*
         * NEGATIVE CONTROL — WCAG 2.5.3 Label in Name, and the reason this uses a
         * `.visually-hidden` sibling rather than `aria-label` on the anchor. At 992px and up the
         * visible text must still be IN the accessible name: the sighted merchant's only
         * indication of which store they are in is that store name.
         */
        foreach (['account' => 'Your account', 'switcher' => 'Select store'] as $which => $hidden) {
            $trigger = $this->trigger($which);

            $this->assertSame(0, preg_match('/aria-label|aria-labelledby/', $trigger['attrs']),
                "the {$which} trigger carries an aria-label, which OVERRIDES its visible text at "
                . '992px and up — the store name / the merchant name leaves the accessible name '
                . 'and this becomes a new WCAG 2.5.3 Label-in-Name failure. Use an additive '
                . '.visually-hidden sibling, as PageActionRegionContractTest already requires.');

            $desktop = $this->accessibleName($trigger['inner'], below992: false);

            $this->assertStringContainsString($hidden, $desktop,
                "the {$which} trigger's hidden name is missing at desktop width");
            $this->assertNotSame($hidden, $desktop,
                "at 992px and up the {$which} trigger's visible text has left its accessible name, "
                . 'so the hidden span replaced the visible one instead of supplementing it');
        }

        // The visible text nodes themselves, named rather than merely counted.
        $markup = $this->header();
        $this->assertStringContainsString('<span class="hide-mob">', $markup,
            'the greeting span is gone, so the merchant name no longer shows on a desktop');
        $this->assertMatchesRegularExpression(
            '/<span class="hide-mob">\{\{ ucfirst\(\$store->name \?\? \x27\x27\) \}\}<\/span>/', $markup,
            'the visible store name is gone from the switcher');
    }

    // ---------------------------------------------------------------------------------------
    // THE CSS HALF — and it is half the repair, not a detail.
    // ---------------------------------------------------------------------------------------

    public function test_both_custom_sheets_exempt_the_hidden_label_at_575px(): void
    {
        $blocks = [];

        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents($this->root() . '/' . $sheet);

            $this->assertSame(1,
                preg_match('/\.dash-header \.dash-head-link \.text-store,\s*'
                    . '\.dash-header \.dash-head-link\.dropdown-toggle span([^{]*)\{\s*display:\s*none;\s*\}/', $css, $m),
                "{$sheet} no longer carries the 575px span-hiding rule in the shape this row "
                . 'measured — re-read it before trusting the exemption below');

            $this->assertStringContainsString(':not(.visually-hidden)', $m[1], sprintf(
                '%s still hides EVERY span inside .dash-head-link.dropdown-toggle below 576px, '
                . "and Bootstrap's .visually-hidden (style.css:8066) declares no `display` for a "
                . '`display: none` to lose to — so the account trigger\'s hidden label is '
                . 'display:none on a 390px phone and the markup half of this repair silently does '
                . 'nothing at the width the row is about.', $sheet));

            // The rule must keep hiding what it hides today: Quick Add's .text-store, and the
            // visible spans. Only .visually-hidden is exempted.
            $this->assertSame('span:not(.visually-hidden)', trim('span' . $m[1]), sprintf(
                '%s exempts more than .visually-hidden from the 575px rule, which makes real text '
                . 'reappear inside 40x40 icon chrome', $sheet));

            $blocks[$sheet] = $m[0];
        }

        /*
         * THE MIRROR. `layouts/app.blade.php` loads rtl-custom.css INSTEAD of custom.css for
         * Arabic (158/166 vs 162/170, light and dark both), so a change in one sheet reaches
         * exactly half the merchants. And a .visually-hidden span whose utility rule is overridden
         * does not vanish quietly — it becomes ordinary VISIBLE text, so an Arabic phone merchant
         * would get a literal "اختر المتجر" sitting in the icon chrome. store-syria-ds.css:20-21
         * records exactly that failure mode.
         */
        $this->assertSame(
            $blocks['public/css/custom.css'],
            $blocks['public/css/rtl-custom.css'],
            'the LTR and RTL custom sheets disagree about the 575px rule, so this repair reaches '
            . 'only one text direction');
    }

    // ---------------------------------------------------------------------------------------
    // THE REST OF THE NEGATIVE CONTROLS
    // ---------------------------------------------------------------------------------------

    public function test_the_two_keys_exist_in_both_language_files(): void
    {
        $en = json_decode((string) file_get_contents($this->root() . '/resources/lang/en.json'), true);
        $ar = json_decode((string) file_get_contents($this->root() . '/resources/lang/ar.json'), true);

        foreach (['Your account', 'Select store'] as $key) {
            // en AND ar, both. ar-only raises TranslationFileIntegrityTest's orphan count past
            // its measured ceiling of 31; en-only fails ArabicUiCoverageTest, whose SURFACES
            // include resources/views/partision/*.blade.php.
            $this->assertArrayHasKey($key, $en, "resources/lang/en.json is missing \"{$key}\"");
            $this->assertArrayHasKey($key, $ar, "resources/lang/ar.json is missing \"{$key}\"");
            $this->assertNotSame('', trim((string) $ar[$key]));
            $this->assertNotSame($key, $ar[$key],
                "\"{$key}\" is not actually translated into Arabic, so an Arabic screen-reader "
                . 'user gets an English control name');
        }

        $orphans = array_diff(array_keys($ar), array_keys($en));
        $this->assertLessThanOrEqual(31, count($orphans),
            'adding these keys raised ar.json\'s orphan count past the ceiling '
            . 'TranslationFileIntegrityTest pins — they went into ar.json without en.json');
    }

    public function test_the_dead_attribute_is_gone_and_nothing_was_reading_it(): void
    {
        $switcher = $this->trigger('switcher');

        $this->assertStringNotContainsString('data-bs-original-title', $switcher['attrs'],
            'the switcher still carries data-bs-original-title, which names nothing and reads '
            . 'like a label');

        // Prove the negative: deleting it removed no working tooltip. Bootstrap only ever touches
        // data-bs-original-title from inside Tooltip, and a Tooltip is constructed only for
        // [data-bs-toggle="tooltip"] — this anchor's toggle is `dropdown`.
        $this->assertStringContainsString('data-bs-toggle="dropdown"', $switcher['attrs'],
            'the switcher is no longer a dropdown trigger');
        $this->assertSame(0, preg_match('/data-bs-toggle\s*=\s*"tooltip"/', $switcher['attrs']),
            'the switcher became a tooltip control, which changes what _fixTitle() does to it');

        foreach (['public/assets/js/dash.js', 'public/assets/js/side-dash.js'] as $script) {
            $js = (string) file_get_contents($this->root() . '/' . $script);
            $this->assertStringContainsString('[data-bs-toggle="tooltip"]', $js,
                "{$script} no longer selects tooltip triggers by that attribute — re-measure which "
                . 'elements get a Tooltip before trusting the deletion above');
            $this->assertStringNotContainsString('data-bs-original-title', $js,
                "{$script} now reads data-bs-original-title, so deleting it from the switcher may "
                . 'have taken something working away');
        }
    }

    public function test_the_gate_the_form_and_the_greeting_structure_are_untouched(): void
    {
        $markup = $this->header();

        // The switcher is owner-only: a string comparison on users.type, and staff users.type is
        // their ROLE NAME, so this block is unreachable by employees. Not this row's to move.
        $this->assertSame(2, substr_count($markup, "@if (auth()->user() && auth()->user()->type == 'admin')"),
            'the owner-only gates around the storefront controls and the store switcher changed');

        // P2-SEC-F12: switching the tenant pointer is a POST with a CSRF token.
        $this->assertMatchesRegularExpression(
            '/<form method="POST" action="\{\{ route\(\x27change\.store\x27, \$store->id\) \}\}" class="m-0">\s*@csrf/',
            $markup, 'the store-switch POST form or its CSRF token changed');

        // A-03: the merchant name stays bidi-isolated and the `!` stays outside the isolate.
        $this->assertStringContainsString('<bdi>{{ !empty(Auth::user()) ? Auth::user()->name : \'\' }}</bdi>!', $markup,
            'the bidi isolation around the merchant name was restructured');

        // Span, not <p>; and no aria-live anywhere in this partial.
        $this->assertSame(0, preg_match_all('/<p[\s>]/', $markup),
            'the shared header now contains a paragraph, which F158 forces to #808191 in dark mode');
        $this->assertStringNotContainsString('aria-live', $markup,
            'announcing the store name through a live region is the over-reach '
            . 'TransientFeedbackContractTest forbids in this partial');

        // The hidden labels are Bootstrap's .visually-hidden, not .ss-visually-hidden: the former
        // is !important on every property in all four theme sheets, the latter only on `position`.
        $this->assertStringNotContainsString('ss-visually-hidden', $markup,
            'ss-visually-hidden is !important only on `position`, so a theme rule can still make '
            . 'this text visible — use Bootstrap\'s .visually-hidden here');
    }
}

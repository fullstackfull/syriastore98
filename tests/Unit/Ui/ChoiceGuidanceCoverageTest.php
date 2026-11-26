<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-060 / P02-I4 — SHORT TASK-ORIENTED INSTRUCTIONS WHEREVER A MERCHANT MUST CHOOSE.
 * =================================================================================================
 *
 * P02-I4 was delivered on the PRODUCT EDITOR only. The traceability row's gap sentence is
 * "Choice groups on orders, settings, shipping, staff and the rest carry no task-oriented
 * instruction", and `ChoiceGroupGuidanceTest` pins exactly two groups, both on `product/create`
 * and `product/edit`. This file is the coverage half: the same two established patterns, carried
 * onto the orders, shipping, role-permission and coupon screens, and ratcheted so a later edit
 * cannot quietly drop one.
 *
 * TWO PATTERNS, NOT ONE, AND THE DISTINCTION IS THE PRODUCT EDITOR'S OWN:
 *
 *   - `x-ss.choice-group` is a `<fieldset>`/`<legend>` plus an instruction bound to the FIELDSET.
 *     It is for a SET of controls -- radios, a block of checkboxes -- because the thing being
 *     described is the set, and a description hung off one radio is announced only when a reader
 *     happens to land on that radio. `components/ss/choice-group.blade.php` says so in its own
 *     docblock, and its CSS already resets the fieldset to nothing, so adopting it on a new
 *     screen adds a legend and a sentence and changes no layout.
 *
 *   - `x-ss.field-help` + `aria-describedby` is for ONE control. A `<select>` is one control in
 *     the accessibility tree however many options it holds, so wrapping a lone select in a
 *     fieldset would be the wrong association, not a stricter one.
 *
 * SO THE CASES BELOW ARE SPLIT THE SAME WAY, and each is scoped to the control's own declaration
 * rather than to the file. THAT SCOPING IS LOAD-BEARING: an assertion that merely asks whether
 * `aria-describedby="payment_type-help"` appears ANYWHERE in a 900-line view passes while the
 * attribute sits on some other field, which is the mutation this ratchet most needs to catch.
 *
 * AND THE SOURCE IS READ WITH ITS COMMENTS REMOVED. CLAUDE.md records eight occasions on which a
 * census in this repository was satisfied -- or broken -- by prose ABOUT the thing it measures;
 * every string this file looks for is also written out in the view comments that explain it.
 */
class ChoiceGuidanceCoverageTest extends TestCase
{
    use StripsSourceComments;

    /**
     * SINGLE-CONTROL CASES: [view, anchor, terminator, help id].
     *
     * The block asserted on runs from `anchor` to the first `terminator` after it -- for a raw
     * `<select>` that is the end of the open tag, for a `Form::` call the end of the Blade echo.
     * Every anchor is unique within its file, which `test_every_anchor_is_unique` proves rather
     * than assumes: two matches would silently make the window the WRONG control's.
     */
    private const DESCRIBED = [
        'order create — payment type' => [
            'resources/views/order/createOrder.blade.php',
            '<select name="payment_type"', '>', 'payment_type-help',
        ],
        'order create — payment status' => [
            'resources/views/order/createOrder.blade.php',
            '<select name="payment_status"', '>', 'payment_status-help',
        ],
        'order detail — note type' => [
            'resources/views/order/view.blade.php',
            "Form::select('note_type'", '!!}', 'note_type-help',
        ],
        'order refund — refund the delivery charge' => [
            'resources/views/order/refund_details.blade.php',
            'Form::checkbox("trending"', '!!}', 'refund_shipping-help',
        ],
        'shipping method — calculation type' => [
            'resources/views/shipping_method/edit.blade.php',
            "Form::select('calculation_type'", '}}', 'calculation_type-help',
        ],
        'shipping method — free shipping requires' => [
            'resources/views/shipping_method/free_shipping.blade.php',
            "Form::select('shipping_requires'", '!!}', 'FreeShipping-help',
        ],
        'shipping zone create — methods' => [
            'resources/views/shippingzone/create.blade.php',
            "Form::select('shipping_method[]'", '!!}', 'shipping_method-help',
        ],
        'shipping zone edit — methods' => [
            'resources/views/shippingzone/edit.blade.php',
            "Form::select('shipping_method[]'", '!!}', 'shipping_method-help',
        ],
    ];

    /**
     * FIELDSET CASES: [view, group name, a control that must be INSIDE the group].
     *
     * The containment check is the scoping here, and it is stronger than a string search: the
     * instruction is bound to the fieldset, so a fieldset that does not actually enclose the
     * radios describes nothing.
     */
    private const GROUPED = [
        'coupon create — how the code is set' => [
            'resources/views/coupon/create.blade.php', 'coupon_code_mode', 'id="manual_code"',
        ],
        'coupon edit — how the code is set' => [
            'resources/views/coupon/edit.blade.php', 'coupon_code_mode', 'id="manual_code"',
        ],
        'role create — permissions' => [
            'resources/views/roles/create.blade.php', 'permissions', "Form::checkbox('permissions[]'",
        ],
        'role edit — permissions' => [
            'resources/views/roles/edit.blade.php', 'permissions', "Form::checkbox('permissions[]'",
        ],
    ];

    /**
     * EVERY INSTRUCTION THIS ROW ADDS, as the literal passed to `__()`.
     *
     * Listed once, here, so the translation check cannot drift from the views: a string that is
     * reworded in a view and not here fails `test_every_instruction_is_on_its_screen`.
     */
    private const INSTRUCTIONS = [
        'Record how the customer is paying. Set the payment status below once the money has reached you.',
        'Choose Paid only if the money has already reached you. You can change this later on the order page.',
        'A private note is for your team only. To customer shows the note to the customer on their order page.',
        'Tick this to add the delivery charge to the refund. The refund total is saved as soon as you tick it.',
        'Per class charges for each shipping class in the basket. Per order charges once, for the most expensive class.',
        'Choose what a customer must do to earn free shipping. Some choices also ask for a minimum order amount.',
        'Pick every shipping method a customer in this zone may choose at checkout. You can pick more than one.',
        'Choose Manual to type your own coupon code, or Auto Generate to have one created for you.',
        'Tick only what this role needs. Anyone you give this role can do everything ticked here.',
    ];

    /** Every view this row touches, for the file-wide both-ways checks. */
    private function views(): array
    {
        $views = [];

        foreach (self::DESCRIBED as $case) {
            $views[$case[0]] = true;
        }

        foreach (self::GROUPED as $case) {
            $views[$case[0]] = true;
        }

        return array_keys($views);
    }

    /**
     * The control's own declaration, from `anchor` to the first `terminator` after it.
     */
    private function block(string $source, string $anchor, string $terminator, string $label): string
    {
        $start = strpos($source, $anchor);
        $this->assertNotFalse($start, $label . ': the control `' . $anchor . '` is not in this '
            . 'view any more, so every assertion about it would be vacuous');

        $end = strpos($source, $terminator, $start);
        $this->assertNotFalse($end, $label . ': the declaration beginning `' . $anchor . '` is '
            . 'never closed by `' . $terminator . '`');

        return substr($source, $start, $end - $start + strlen($terminator));
    }

    /**
     * PREMISE. Each anchor must match exactly once, or the window above is cut around whichever
     * copy happens to come first and the test measures the wrong control while reading as a pass.
     */
    public function test_every_anchor_is_unique_in_its_view(): void
    {
        foreach (self::DESCRIBED as $label => [$view, $anchor, , ]) {
            $source = $this->sourceWithoutComments($view);

            $this->assertSame(1, substr_count($source, $anchor),
                $label . ': `' . $anchor . '` occurs ' . substr_count($source, $anchor)
                . ' times in ' . $view . '. The scoped window below is only meaningful when it '
                . 'occurs once.');
        }
    }

    /**
     * EACH SINGLE CONTROL CARRIES ITS OWN INSTRUCTION'S id -- measured inside that control's
     * declaration, not anywhere in the file.
     *
     * @depends test_every_anchor_is_unique_in_its_view
     */
    public function test_every_described_control_points_at_its_instruction(): void
    {
        foreach (self::DESCRIBED as $label => [$view, $anchor, $terminator, $help]) {
            $source = $this->sourceWithoutComments($view);
            $block = $this->block($source, $anchor, $terminator, $label);

            $this->assertStringContainsString($help, $block,
                $label . ": this control carries no `aria-describedby` naming `{$help}`, so the "
                . "instruction beside it is decoration rather than guidance.\nControl as "
                . "declared:\n" . $block);

            $this->assertMatchesRegularExpression(
                '~aria-describedby[\'"]?\s*(?:=>\s*)?[\'"=]\s*[\'"]?[^\'"]*' . preg_quote($help, '~') . '~',
                $block,
                $label . ': the help id appears in the control\'s declaration but not as the '
                . 'value of `aria-describedby`'
            );
        }
    }

    /**
     * AND THE INSTRUCTION ELEMENT ITSELF EXISTS, rendered by the design system's own component so
     * the id, the class and the dark-mode contrast defence come from one place.
     */
    public function test_every_instruction_element_is_rendered_by_the_design_system_component(): void
    {
        foreach (self::DESCRIBED as $label => [$view, , , $help]) {
            $source = $this->sourceWithoutComments($view);
            $for = substr($help, 0, -strlen('-help'));

            $this->assertMatchesRegularExpression(
                '~<x-ss\.field-help\s+for="' . preg_quote($for, '~') . '"~',
                $source,
                $label . ": no `<x-ss.field-help for=\"{$for}\">` in {$view}, so `{$help}` is on "
                . 'no element and a screen reader announces nothing for it'
            );
        }
    }

    /**
     * NO ORPHAN AND NO DANGLING REFERENCE, per view.
     *
     * Both directions, because they fail differently and both read as working markup: an
     * instruction nothing references is decoration, and a reference pointing at nothing is worse
     * than silence, since a reader announces neither.
     */
    public function test_no_instruction_is_an_orphan_and_no_reference_dangles(): void
    {
        foreach ($this->views() as $view) {
            $source = $this->sourceWithoutComments($view);

            preg_match_all('~<x-ss\.field-help\s+for="([^"]+)"~', $source, $declared);

            foreach ($declared[1] as $for) {
                $this->assertStringContainsString($for . '-help', $source,
                    $view . ': `' . $for . '-help` is declared and referenced nowhere');
            }

            preg_match_all('~aria-describedby[^\'"]*[\'"]([a-zA-Z0-9_\-]+)-help[\'"]~', $source, $refs);

            foreach (array_unique($refs[1]) as $for) {
                $this->assertMatchesRegularExpression(
                    '~<x-ss\.field-help\s+for="' . preg_quote($for, '~') . '"~',
                    $source,
                    $view . ': something references `' . $for . '-help`, which no '
                    . '`x-ss.field-help` on this screen emits'
                );
            }
        }
    }

    /**
     * EACH CHECKBOX / RADIO SET IS A REAL GROUP: the design-system fieldset, a legend that names
     * it, an instruction bound to the fieldset, and the controls genuinely INSIDE it.
     */
    public function test_every_choice_set_is_a_named_group_that_encloses_its_controls(): void
    {
        foreach (self::GROUPED as $label => [$view, $name, $inner]) {
            $source = $this->sourceWithoutComments($view);

            $open = strpos($source, '<x-ss.choice-group');
            $this->assertNotFalse($open,
                $label . ': ' . $view . ' has no `x-ss.choice-group`, so its options are a bare '
                . 'block of controls with no group name and no instruction');

            $close = strpos($source, '</x-ss.choice-group>', $open);
            $this->assertNotFalse($close, $label . ': the choice group is never closed');

            $group = substr($source, $open, $close - $open);

            $this->assertMatchesRegularExpression('~name="' . preg_quote($name, '~') . '"~', $group,
                $label . ': the group is not named `' . $name . '`, so its instruction id is not '
                . 'the one this ratchet and the component agree on');

            $this->assertMatchesRegularExpression('~:legend="__\(~', $group,
                $label . ': the group has no translated legend, so its name reaches the screen '
                . 'visually and not programmatically');

            $this->assertMatchesRegularExpression('~:hint="__\(~', $group,
                $label . ': the group has no translated instruction, which is the whole of this '
                . 'requirement');

            $this->assertStringContainsString($inner, $group,
                $label . ': `' . $inner . '` is NOT inside the fieldset. The instruction is bound '
                . 'to the group, so a group that does not enclose its controls describes nothing — '
                . 'and this is the one defect a whole-file string search cannot see.');
        }
    }

    /**
     * EVERY INSTRUCTION IS REALLY ON A SCREEN. A string listed here and used nowhere would make
     * the translation check below assert about copy that no merchant can read.
     */
    public function test_every_instruction_is_on_its_screen(): void
    {
        $all = '';
        foreach ($this->views() as $view) {
            $all .= $this->sourceWithoutComments($view);
        }

        foreach (self::INSTRUCTIONS as $instruction) {
            $this->assertStringContainsString($instruction, $all,
                'this instruction is declared by the test and appears in none of the views it '
                . 'covers: ' . $instruction);
        }
    }

    /**
     * TRANSLATED, asserted by COMPARING THE TWO LOCALES rather than by looking for `__()`.
     *
     * A missing `resources/lang/ar.json` entry makes `__()` return the English key from a
     * perfectly written call, so a call-site check passes while an Arabic merchant reads English.
     * `ChoiceGroupGuidanceTest` makes the same comparison for the product editor's two hints.
     */
    public function test_every_instruction_has_a_real_arabic_translation(): void
    {
        foreach (self::INSTRUCTIONS as $instruction) {
            $en = trans($instruction, [], 'en');
            $ar = trans($instruction, [], 'ar');

            $this->assertSame($instruction, $en,
                'the English entry is missing, so en.json is not the matched pair of ar.json: '
                . $instruction);

            $this->assertNotSame($en, $ar,
                'the Arabic instruction is identical to the English one, so the translation is '
                . 'missing and __() fell back to the key: ' . $instruction);

            $this->assertMatchesRegularExpression('~\p{Arabic}~u', $ar,
                'the Arabic instruction contains no Arabic script: ' . $instruction);
        }
    }

    /**
     * SHORT, which is the requirement's own word. An instruction nobody reads is not guidance,
     * and a paragraph in a form is how that happens.
     */
    public function test_every_instruction_is_short_enough_to_be_read(): void
    {
        foreach (self::INSTRUCTIONS as $instruction) {
            $this->assertGreaterThan(40, mb_strlen($instruction),
                'too short to say what the choice does: ' . $instruction);

            $this->assertLessThan(160, mb_strlen($instruction),
                '"Short task-oriented instructions" is the requirement. This is a paragraph: '
                . $instruction);

            $ar = trans($instruction, [], 'ar');
            $this->assertLessThan(200, mb_strlen($ar),
                'the Arabic instruction is far longer than the English one, which usually means '
                . 'it explains rather than instructs: ' . $instruction);
        }
    }

    /**
     * THE WHITE DARK-MODAL SURFACE, WHICH IS WHERE THIS ROW NEARLY SHIPPED AN INVISIBLE
     * INSTRUCTION.
     *
     * MEASURED IN CHROMIUM WITH THE FULL ADMIN CASCADE, before this rule existed:
     *
     *     .ss-choicegroup__legend   #e8eaed on #ffffff    1.21:1
     *     .ss-field__hint           #a7abb2 on #ffffff    2.30:1
     *
     * `style-dark.css` sets `--bs-modal-bg: #ffffff`, so the host's modal keeps a WHITE surface in
     * dark mode -- `css/custom.css`'s own F126 note documents that at length and names `label`,
     * `.form-label`, `.ss-label` and `h1`-`h6` for it. It does NOT name these two, and the
     * secondary-text rule excludes every `ss-`-prefixed paragraph on purpose, which hands
     * `.ss-field__hint` back to the design system's `--ss-fg-muted !important` -- the DARK-PAGE
     * muted value, correct on a dark card and 2.30:1 here.
     *
     * P02-I4 IS WHAT MADE IT REACHABLE. Contextual help had only ever been on PAGE screens, where
     * the same colour measures 6.18:1 and is right. Six of this row's screens are modal partials.
     *
     * BOTH FILES, ALWAYS. `custom.css` serves LTR and `rtl-custom.css` serves Arabic and they are
     * mirrored duplicates, so a rule in one reaches half the merchants -- the discipline
     * `SwitchAccessibleNameTest` states and this row has to obey in the other direction, by adding
     * to both rather than to neither.
     *
     * THE ASSERTION IS SCOPED TO THE DECLARATION, not to the file. A 5,000-line stylesheet
     * contains the words `.ss-field__hint` in several places including this rule's own comment, so
     * a whole-file search would answer "does this string exist anywhere" -- and the comments are
     * stripped first, because prose about a rule is not the rule.
     */
    public function test_both_mirrored_sheets_put_the_instruction_and_legend_on_the_white_dark_modal(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(base_path($sheet)));

            $this->assertNotSame('', trim($css), $sheet . ': stripping removed the whole file');

            foreach ([
                '--ss-grey-900' => ['legend', '.ss-choicegroup__legend'],
                '--ss-grey-600' => ['.ss-field__hint'],
            ] as $token => $selectors) {
                /* The declaration block this row joins, located by its own colour token. */
                $at = strpos($css, 'color: var(' . $token . ',');
                $this->assertNotFalse($at,
                    $sheet . ': the dark-modal declaration using ' . $token . ' is gone, so these '
                    . 'selectors have no rule to belong to');

                $from = strrpos(substr($css, 0, $at), '}');
                $block = substr($css, $from === false ? 0 : $from, $at - ($from === false ? 0 : $from));

                $this->assertStringContainsString('data-ss-theme="dark"', $block,
                    $sheet . ': the ' . $token . ' declaration is no longer dark-scoped, which '
                    . 'would put a light-surface colour on every modal in both themes');

                foreach ($selectors as $selector) {
                    /* `[,{]` because a selector is either followed by the next one or by the
                       declaration, and which one it is depends on the order they were written in. */
                    $this->assertMatchesRegularExpression(
                        '~html\[data-ss-theme="dark"\] body \.modal-content '
                        . preg_quote($selector, '~') . '\s*[,{]~',
                        $block,
                        $sheet . ': `' . $selector . '` is not in the ' . $token . ' selector list, '
                        . 'so on the white dark-modal surface it renders at 1.21:1 (legend) or '
                        . '2.30:1 (hint). Both measured in Chromium with the full cascade.'
                    );
                }
            }
        }
    }

    /**
     * AND NOTHING WAS GIVEN A *LIGHT*-MODE OVERRIDE, which would be the way this fix breaks the
     * theme it is not for: the instruction already clears AA on every light surface measured
     * (6.14:1 in a light modal, 6.42:1 on a light card), so an unscoped rule would be a
     * regression dressed as a fix.
     */
    public function test_the_fix_is_dark_scoped_only(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(base_path($sheet)));

            preg_match_all('~([^{}]*\.ss-choicegroup__legend[^{}]*)\{~', $css, $rules);

            $this->assertNotEmpty($rules[1], $sheet . ': no rule mentions the group legend at all');

            foreach ($rules[1] as $selector) {
                $this->assertStringContainsString('data-ss-theme="dark"', $selector,
                    $sheet . ' styles the group legend outside a dark scope: ' . trim($selector));
            }
        }
    }

    /**
     * THE RENDERED PROOF, on the one screen in this set that renders with no fixtures.
     *
     * Everything above reads SOURCE, which cannot see what Blade does with it -- and this
     * repository has three measured 500s whose cause was the compiler rather than the markup.
     * `coupon/create.blade.php` is a modal partial whose only data is two option lists, so it can
     * be rendered here and the association checked in the DOM that actually reaches a merchant:
     * a real `<fieldset>`, a real `<legend>`, `aria-describedby` on the FIELDSET, the id on an
     * element that exists, and both radios inside.
     */
    public function test_the_coupon_code_group_reaches_the_dom_as_a_described_fieldset(): void
    {
        $html = (string) view('coupon.create', ['product' => [], 'category' => []])->render();

        $this->assertMatchesRegularExpression(
            '~<fieldset[^>]*data-ss-choicegroup="coupon_code_mode"[^>]*aria-describedby="coupon_code_mode-group-help"~',
            $html,
            'the rendered fieldset does not carry `aria-describedby` for its own instruction'
        );

        $this->assertStringContainsString('id="coupon_code_mode-group-help"', $html,
            'the instruction the fieldset points at is on no element in the rendered page');

        $start = strpos($html, 'data-ss-choicegroup="coupon_code_mode"');
        $this->assertNotFalse($start, 'premise: the rendered group is missing');

        $end = strpos($html, '</fieldset>', $start);
        $this->assertNotFalse($end, 'premise: the rendered fieldset is unclosed');

        $group = substr($html, $start, $end - $start);

        $this->assertStringContainsString('<legend', $group,
            'the group has no legend in the rendered page, so its name is not in the '
            . 'accessibility tree');

        foreach (['id="manual_code"', 'id="auto_code"'] as $radio) {
            $this->assertStringContainsString($radio, $group,
                $radio . ' is outside the fieldset in the rendered page, so the group describes '
                . 'controls it does not contain');
        }

        /* Every option label still names a real control. The product editor's equivalent row
           found `for="   "` -- three spaces, which reads as an attribute that is present. */
        preg_match_all('~<label[^>]*\sfor="([^"]*)"~', $group, $labels);
        $this->assertNotEmpty($labels[1], 'the rendered group has no labelled options');

        foreach ($labels[1] as $for) {
            $this->assertNotSame('', trim($for), 'an option label has an empty or whitespace `for`');
            $this->assertStringContainsString('id="' . $for . '"', $html,
                'an option label points at "' . $for . '", which is on no element');
        }
    }

    /**
     * AND THE INSTRUCTION'S TEXT IS IN THE RENDERED PAGE, in the group, in both locales.
     *
     * This is the half a source check cannot do: it proves `__()` resolved, which is the step
     * that silently returns English when `ar.json` is missing an entry.
     */
    public function test_the_rendered_coupon_instruction_is_localised_in_both_directions(): void
    {
        $seen = [];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $html = (string) view('coupon.create', ['product' => [], 'category' => []])->render();

            $start = strpos($html, 'id="coupon_code_mode-group-help"');
            $this->assertNotFalse($start, $locale . ': the rendered instruction is missing');

            $text = trim(strip_tags(substr($html, $start, 600)));

            $this->assertGreaterThan(40, mb_strlen($text),
                $locale . ': the rendered instruction is too short to explain the choice');

            $seen[$locale] = $text;
        }

        app()->setLocale('en');

        $this->assertMatchesRegularExpression('~\p{Arabic}~u', $seen['ar'],
            'under `ar` the group instruction renders with no Arabic script in it, so `__()` fell '
            . 'back to the English key');

        $this->assertNotSame($seen['en'], $seen['ar'],
            'the group instruction renders identically in both locales');
    }
}

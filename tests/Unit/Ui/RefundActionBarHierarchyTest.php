<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 refund-four-identical-buttons — rank the refund actions, and confirm the irreversible one.
 * =================================================================================================
 *
 * MEASURED DEFECT, two halves.
 *
 * (1) NO HIERARCHY. The four controls in the action bar of
 * `resources/views/order/refund_details.blade.php` carried byte-identical class lists —
 * `btn btn-primary mt-2 btn-submit`, one of them with Bootstrap 4's `btn-block`, one with a
 * trailing space. No stylesheet can rank them: the host fill at `public/assets/css/style.css:3529`,
 * the recolour at `public/css/custom-color.css:68` and the Phase 02 text override at
 * `public/css/custom.css:4937-4941` (mirrored in `rtl-custom.css:4929-4933`) all select the four
 * equally. `btn-block` is dead (zero hits in any loaded stylesheet) and `btn-submit` is bound and
 * styled by nothing (zero hits across every first-party .js/.css/.scss). So "Accept Request",
 * "Cancel Request", "Manage Stock" and "Refund Amount" shouted equally, including the one that
 * pays money out.
 *
 * REPLACE, NEVER APPEND. `body .btn-primary` is specificity (0,0,1,1) and beats
 * `.ss-btn--secondary` at (0,0,1,0) regardless of load order, so keeping `btn btn-primary`
 * alongside a DS variant renders "Manage Stock" as near-white text on a white surface. That is
 * why this file asserts the Bootstrap tokens are GONE rather than that the DS tokens are present.
 *
 * (2) NO CONFIRMATION ON THE MONEY ACTION. `grep -i confirm` on the view returned nothing.
 * `#refund-amount` posted straight to `RefundAmonut`, which commits inside a transaction and is
 * terminal by design — `LEGAL_TRANSITIONS['Refunded']` is empty, so the terminal guard refuses any
 * later change and a stray click is not undoable through the UI. For an offline/COD order
 * `resolveRefundStatus()` really does return 'Refunded' and the write rewrites the order's totals,
 * which is the payment type this market uses most.
 *
 * (3) AND THE FLASH, WHOSE OBVIOUS FIX IS A REGRESSION. `setTimeout(() => {...})` received no
 * delay and the `500` was a stray second argument to `.ready()` that jQuery discards, so the
 * status branch ran one macrotask after DOMContentLoaded and all four buttons painted first.
 * Deleting the `setTimeout` alone is WORSE than the flash: in jQuery 3.6 every `ready` handler
 * runs in its own `setTimeout` macrotask, so the deferred inner callback was accidentally the LAST
 * visibility writer and overrode two earlier localStorage-driven ones. Remove it and those two
 * become authoritative — and because `refundedClicked` is a browser-GLOBAL key rather than
 * per-request, one completed refund would hide "Refund Amount" on every later refund request in
 * that browser, forever, with no way back but clearing site data. So the two competing writers go
 * too, leaving the database-derived `refund_status` as the single authoritative pass. That is
 * behaviour-preserving, because the deferred branch already overrode them.
 */
class RefundActionBarHierarchyTest extends TestCase
{
    private const VIEW = 'resources/views/order/refund_details.blade.php';

    /** The four controls, and the one DS variant each must carry. */
    private const BUTTONS = [
        'accept-button'  => 'ss-btn--primary',
        'cancel-button'  => 'ss-btn--danger-quiet',
        'manage-button'  => 'ss-btn--secondary',
        'refund-amount'  => 'ss-btn--primary',
    ];

    /**
     * Tokens read out of public/css/store-syria-ds.css, with the line each came from. Each is
     * asserted to still be in that stylesheet below, so a recolour cannot silently invalidate the
     * contrast figures computed from them.
     */
    private const TOKENS = [
        'light' => [
            'brand'         => '#335da8',   // :104 --ss-blue-600
            'on-brand'      => '#ffffff',   // :270 --ss-on-brand (= --ss-grey-0, :166)
            'surface'       => '#ffffff',   // :238 --ss-surface  = --ss-grey-0
            'bg'            => '#f6f7f8',   // :237 --ss-bg       = --ss-grey-50, :168
            'fg'            => '#1a1d23',   // :243 --ss-fg       = --ss-grey-900, :182
            'danger'        => '#b3261e',   // :298 --ss-danger   = --ss-red-700, :194
            'border-strong' => '#888c93',   // :284 --ss-border-strong = --ss-grey-400, :177
        ],
        'dark' => [
            'brand'         => '#2ac3e5',   // :115 --ss-cyan-500
            'on-brand'      => '#04222a',   // :415
            'surface'       => '#262b33',   // :397
            'bg'            => '#1b1f25',   // :396
            'fg'            => '#e8eaed',   // :401
            'danger'        => '#f3a9a4',   // :428
            'border-strong' => '#7d828a',   // :420
        ],
    ];

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::VIEW);
    }

    /** The markup of the action bar: the `div.text-end` that holds the four controls. */
    private function actionBar(string $source): string
    {
        $open = strpos($source, '<div class="text-end">');
        $this->assertNotFalse($open, 'the refund action bar is no longer where this contract reads it');

        $end = strpos($source, '@endsection', $open);
        $this->assertNotFalse($end);

        return substr($source, $open, $end - $open);
    }

    /** The opening tag of one control in the action bar, keyed by its id or class. */
    private function buttonTag(string $bar, string $handle): string
    {
        $this->assertTrue(
            (bool) preg_match('/<button[^>]*\b' . preg_quote($handle, '/') . '\b[^>]*>/', $bar, $m),
            'the "' . $handle . '" control is not in the refund action bar'
        );

        return $m[0];
    }

    /** The body of a jQuery handler bound in the view, by balanced braces from its binding. */
    private function handlerBody(string $source, string $binding): string
    {
        $hit = strpos($source, $binding);
        $this->assertNotFalse($hit, 'the handler bound by `' . $binding . '` is no longer in the view');

        $open = strpos($source, '{', $hit);
        $this->assertNotFalse($open);

        $depth = 0;
        for ($j = $open, $len = strlen($source); $j < $len; $j++) {
            if ($source[$j] === '{') {
                $depth++;
            } elseif ($source[$j] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $j - $open + 1);
                }
            }
        }

        $this->fail('unbalanced handler body for `' . $binding . '`');
    }

    /** The view with its JS comments removed, so prose cannot satisfy a structural rule. */
    private function withoutJsComments(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    /** WCAG 2.1 relative luminance. */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $v = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** WCAG 2.1 contrast ratio. */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array<string,mixed> */
    private function lang(string $locale): array
    {
        return (array) json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/' . $locale . '.json'),
            true
        );
    }

    // -------------------------------------------------------------------------------------------
    // Premise.
    // -------------------------------------------------------------------------------------------

    public function test_the_scan_sees_all_four_refund_controls(): void
    {
        $bar = $this->actionBar($this->source());

        foreach (array_keys(self::BUTTONS) as $handle) {
            $this->assertNotEmpty($this->buttonTag($bar, $handle));
        }

        $this->assertSame(4, substr_count($bar, '<button'),
            'the action bar no longer holds exactly the four controls this contract ranks');
    }

    // -------------------------------------------------------------------------------------------
    // (1) Hierarchy.
    // -------------------------------------------------------------------------------------------

    public function test_the_four_controls_no_longer_share_one_class_list(): void
    {
        $bar = $this->actionBar($this->source());
        $classLists = [];

        foreach (array_keys(self::BUTTONS) as $handle) {
            preg_match('/class="([^"]*)"/', $this->buttonTag($bar, $handle), $m);
            $classLists[$handle] = trim(preg_replace('/\s+/', ' ', (string) ($m[1] ?? '')));
        }

        $this->assertCount(4, array_unique($classLists),
            "two or more of the four refund controls still carry the same class list, so no "
            . "stylesheet can rank them and the button that pays money out shouts exactly as loud "
            . "as the one that views stock:\n  " . json_encode($classLists, JSON_PRETTY_PRINT));
    }

    public function test_each_control_carries_its_design_system_variant(): void
    {
        $bar = $this->actionBar($this->source());

        foreach (self::BUTTONS as $handle => $variant) {
            $tag = $this->buttonTag($bar, $handle);

            $this->assertStringContainsString('ss-btn ', $tag, $handle . ' is not a design-system button');
            $this->assertStringContainsString($variant, $tag, $handle . ' does not carry ' . $variant);
            $this->assertStringContainsString('ss-btn--sm', $tag, $handle . ' is not the small size');
            $this->assertStringContainsString('type="button"', $tag,
                $handle . ' must declare type="button": a <button> with no type defaults to submit, '
                . 'and declaring submit with no form owner is misleading');
        }
    }

    /**
     * THE NEGATIVE CONTROL THAT MATTERS MOST. `body .btn-primary` (0,0,1,1) outranks
     * `.ss-btn--secondary` (0,0,1,0) whatever the load order, so an APPENDED DS class leaves
     * "Manage Stock" rendering near-white text on a white surface — a worse result than the
     * undifferentiated bar being replaced.
     */
    public function test_no_bootstrap_button_token_survives_on_the_four_controls(): void
    {
        $bar = $this->actionBar($this->source());
        $offenders = [];

        foreach (array_keys(self::BUTTONS) as $handle) {
            preg_match('/class="([^"]*)"/', $this->buttonTag($bar, $handle), $m);
            $classes = preg_split('/\s+/', trim((string) ($m[1] ?? '')));

            foreach (['btn', 'btn-primary', 'btn-block', 'btn-submit'] as $dead) {
                if (in_array($dead, $classes, true)) {
                    $offenders[] = $handle . ': ' . $dead;
                }
            }
        }

        $this->assertSame([], $offenders,
            "a Bootstrap button token survives beside the design-system variant. `body .btn-primary` "
            . "in public/css/custom.css:4937 has specificity (0,0,1,1) and beats `.ss-btn--secondary` "
            . "(0,0,1,0) regardless of load order, so the label is forced to --ss-onbrand white on a "
            . "white surface:\n  " . implode("\n  ", $offenders));
    }

    /** `mt-2` is a margin utility, orthogonal to the button reset, and is deliberately kept. */
    public function test_the_layout_utility_is_kept(): void
    {
        $bar = $this->actionBar($this->source());

        foreach (array_keys(self::BUTTONS) as $handle) {
            $this->assertStringContainsString('mt-2', $this->buttonTag($bar, $handle),
                $handle . ' lost its margin utility, so the bar collapses onto the card above it');
        }
    }

    /** Two controls take --primary, and the status branch guarantees they are never co-visible. */
    public function test_only_one_primary_control_is_ever_visible_at_once(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            "/initialStatus === 'Processing'\)\s*\{[\s\S]{0,400}?\\\$\('#refund-amount'\)\.hide\(\);/",
            $source,
            'a Processing request must not show "Refund Amount" beside "Accept Request": both are '
            . '--primary and two primaries in one bar rank nothing'
        );
        $this->assertMatchesRegularExpression(
            "/initialStatus === 'Accept'\)\s*\{[\s\S]{0,400}?\\\$\('\.accept-button'\)\.hide\(\);/",
            $source,
            'an Accept request must not show "Accept Request" beside "Refund Amount"'
        );
    }

    // -------------------------------------------------------------------------------------------
    // (2) Confirmation on the two consequential actions.
    // -------------------------------------------------------------------------------------------

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function consequentialHandlers(): iterable
    {
        yield 'refund amount (terminal for an offline order)' => ["$(document).on('click', '#refund-amount'"];
        yield 'cancel request (terminal: LEGAL_TRANSITIONS[Cancel] is empty)' => ["$('.cancel-button').click("];
    }

    /**
     * @dataProvider consequentialHandlers
     */
    public function test_the_consequential_actions_open_a_confirmation_before_posting(string $binding): void
    {
        $body = $this->handlerBody($this->source(), $binding);

        $this->assertStringContainsString('Swal.mixin(', $body,
            'this action commits a terminal, UI-irreversible change and must ask first');

        $prevent = strpos($body, 'e.preventDefault();');
        $swal = strpos($body, 'Swal.mixin(');
        $ajax = strpos($body, '$.ajax(');
        $confirmed = strpos($body, '!result.isConfirmed');

        $this->assertNotFalse($prevent, 'the handler does not cancel the click it intercepts');
        $this->assertNotFalse($confirmed, 'nothing reads the dialog\'s answer');
        $this->assertNotFalse($ajax);

        $this->assertLessThan($swal, $prevent,
            'preventDefault() must run BEFORE the dialog. SweetAlert is asynchronous, so a '
            . 'preventDefault inside .then() runs after the default action has already happened '
            . 'and reads as a fix without being one (F204).');
        $this->assertLessThan($ajax, $confirmed,
            'the POST must sit INSIDE the confirmed branch. A dialog that opens while the money '
            . 'posts regardless is a picture of a question that has already been answered.');
    }

    /**
     * THE REGRESSION THE POSITION CHECK ABOVE DOES NOT SEE, and which this repair introduced once
     * before it was caught. Moving the DOM reads INSIDE `.then()` rebinds `this`: in a SweetAlert
     * callback it is no longer the clicked button, so `$(this).data('refund-id')` returns
     * undefined and the cancel POSTs a null id. The reads must run in the handler's own scope,
     * before the dialog, while the page is still untouched.
     */
    public function test_the_dom_reads_happen_before_the_dialog_not_inside_its_callback(): void
    {
        $cancel = $this->handlerBody($this->source(), "$('.cancel-button').click(");

        $read = strpos($cancel, "$(this).data('refund-id')");
        $swal = strpos($cancel, 'Swal.mixin(');

        $this->assertNotFalse($read, 'the cancel handler no longer reads the refund id from its own button');
        $this->assertLessThan($swal, $read,
            '`this` is the clicked button only in the handler\'s own scope. Inside the SweetAlert '
            . '`.then()` callback it is not, so the id reads undefined and the cancel posts a null '
            . 'refund_id -- a silent break of the action the dialog was added to protect.');

        $amount = $this->handlerBody($this->source(), "$(document).on('click', '#refund-amount'");
        $this->assertLessThan(
            strpos($amount, 'Swal.mixin('),
            strpos($amount, "var refundId = $('.refund_id').val();"),
            'the refund amount handler must also read the page before the dialog covers it'
        );
    }

    /** The dialog must be the shared SweetAlert, not `.show_confirm` and not a raw browser dialog. */
    public function test_the_confirmation_is_not_the_delegated_class_and_not_a_raw_dialog(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('show_confirm', $source,
            'custom.js:162 ends its `.show_confirm` branch in form.submit() on closest("form"), and '
            . 'this view has no form; worse, its preventDefault at :126 does not stop this view\'s '
            . 'own separately-bound handlers, so the dialog would open while the money posts anyway '
            . '(the F204 failure mode recorded at order/view.blade.php:769-782)');

        $this->assertSame(0, preg_match_all('/(?<![\w.$-])confirm\s*\(/', $source),
            'ConfirmationDialogContractTest freezes the raw browser dialog count at 4 across two '
            . 'views and strips only BLADE comments, not JS ones, so even the word in a comment '
            . 'fails that gate');
    }

    /** NO OVER-REACH: the two reversible actions must stay one click. */
    public function test_the_reversible_actions_are_not_given_a_dialog(): void
    {
        $source = $this->source();

        foreach ([
            "$('.accept-button').click(" => "Processing -> Accept is reversible: LEGAL_TRANSITIONS['Accept'] contains 'Cancel'",
            "$(document).on('click', '#manage-button'" => 'RefundStock is idempotent by lock+note',
        ] as $binding => $why) {
            $this->assertStringNotContainsString('Swal.mixin(', $this->handlerBody($source, $binding),
                'this action does not need confirming — ' . $why . ' — and a dialog on every click '
                . 'is the ceremony that teaches merchants to dismiss dialogs unread');
        }
    }

    /** The cancel handler had no event parameter, so `e.preventDefault()` would have thrown. */
    public function test_the_cancel_handler_takes_the_event_it_cancels(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$\('\.cancel-button'\)\.click\(function \(e\) \{/",
            $this->source(),
            'the cancel handler was `function() {` with no parameter; calling e.preventDefault() '
            . 'inside it would throw a ReferenceError and the button would do nothing at all'
        );
    }

    /** Written unconditionally before, so it recorded a cancellation the merchant declined. */
    public function test_the_cancel_flag_is_only_written_once_the_merchant_confirms(): void
    {
        $body = $this->handlerBody($this->source(), "$('.cancel-button').click(");

        $flag = strpos($body, "localStorage.setItem('actionPerformed', 'Cancel')");
        $confirmed = strpos($body, '!result.isConfirmed');

        $this->assertNotFalse($flag);
        $this->assertNotFalse($confirmed);
        $this->assertLessThan($flag, $confirmed,
            "'actionPerformed' => 'Cancel' was written whether or not the POST ran, and now "
            . 'whether or not the merchant confirmed');
    }

    // -------------------------------------------------------------------------------------------
    // (3) One authoritative visibility pass.
    // -------------------------------------------------------------------------------------------

    public function test_the_initial_visibility_pass_is_not_deferred(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('setTimeout', $source,
            'the status branch ran one macrotask after DOMContentLoaded, so all four buttons '
            . 'painted first and then rearranged in front of the merchant');
        $this->assertStringNotContainsString('}, 500);', $source,
            'the stray 500 was a second argument to .ready(), which jQuery discards; it must not '
            . 'be "corrected" into a real half-second delay');
    }

    /**
     * THE REGRESSION A NAIVE "DELETE THE setTimeout" INTRODUCES. Both keys are browser-global,
     * not per-refund-request. Once the deferred branch stops being the last writer, one completed
     * refund hides "Refund Amount" on every later refund request in that browser.
     */
    public function test_no_browser_global_localstorage_key_decides_what_the_merchant_can_reach(): void
    {
        $source = $this->source();

        foreach (['manageButtonClicked', 'refundedClicked'] as $key) {
            $this->assertStringNotContainsString("localStorage.getItem('" . $key . "')", $source,
                "'" . $key . "' is a browser-global key with no refund-request in it. Reading it to "
                . 'decide initial visibility means one completed refund removes the control on every '
                . 'OTHER refund request for that browser, permanently. The database-derived '
                . 'refund_status is the only authoritative source.');
        }
    }

    public function test_an_unrecognised_refund_status_leaves_no_action_live(): void
    {
        $this->assertMatchesRegularExpression(
            "/else \{\s*\\\$\('\.accept-button'\)\.hide\(\);\s*\\\$\('\.cancel-button'\)\.hide\(\);"
            . "\s*\\\$\('#manage-button'\)\.hide\(\);\s*\\\$\('#refund-amount'\)\.hide\(\);\s*\}/",
            $this->withoutJsComments($this->source()),
            'refund_status is a plain string column with no database constraint, so an unexpected '
            . 'value must not leave all four actions live on a money path'
        );
    }

    /** All four must stay in the DOM: Accept reveals two of them with no page reload. */
    public function test_the_controls_are_not_rendered_behind_a_status_condition(): void
    {
        $bar = $this->actionBar($this->source());

        $this->assertSame(1, substr_count($bar, '@if'),
            'the only condition in this bar is the store\'s manage_stock setting. Putting the '
            . 'controls behind a refund_status @if makes the .show() calls at the Accept success '
            . 'handler a no-op on an empty set and breaks accept->refund within one page life');
        $this->assertStringNotContainsString('refund_status', $bar);
    }

    // -------------------------------------------------------------------------------------------
    // Contrast, computed here with the WCAG 2.1 formula rather than quoted.
    // -------------------------------------------------------------------------------------------

    public function test_the_tokens_the_contrast_figures_come_from_are_still_in_the_stylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/store-syria-ds.css');

        foreach (self::TOKENS as $theme => $tokens) {
            foreach ($tokens as $name => $hex) {
                $this->assertStringContainsString($hex, $css,
                    $theme . ' ' . $name . ' (' . $hex . ') is no longer in the design system, so '
                    . 'the ratios measured below describe colours that are not on screen');
            }
        }
    }

    public function test_every_ranked_variant_meets_its_contrast_floor_in_both_themes(): void
    {
        foreach (self::TOKENS as $theme => $t) {
            // --primary: the label sits on the brand fill.
            $primary = $this->contrast($t['on-brand'], $t['brand']);
            // --secondary: label on the surface fill.
            $secondary = $this->contrast($t['fg'], $t['surface']);
            // --danger-quiet: transparent background, so the label sits on the page.
            // The action bar is OUTSIDE any .ss-card, directly inside the page row.
            $dangerQuiet = $this->contrast($t['danger'], $t['bg']);
            // --secondary's only separation from the page is its 1px border: a non-text element.
            $border = $this->contrast($t['border-strong'], $t['surface']);

            $this->assertGreaterThanOrEqual(4.5, $primary, $theme . ' --primary label');
            $this->assertGreaterThanOrEqual(4.5, $secondary, $theme . ' --secondary label');
            $this->assertGreaterThanOrEqual(4.5, $dangerQuiet, $theme . ' --danger-quiet label');
            $this->assertGreaterThanOrEqual(3.0, $border, $theme . ' --secondary border');

            // The measured figures, so a token change that still clears the floor is still seen.
            $measured = [
                'light' => ['primary' => 6.42, 'secondary' => 16.88, 'dangerQuiet' => 6.09, 'border' => 3.38],
                'dark'  => ['primary' => 7.92, 'secondary' => 11.81, 'dangerQuiet' => 8.68, 'border' => 3.68],
            ][$theme];

            $this->assertSame($measured['primary'], round($primary, 2), $theme . ' --primary');
            $this->assertSame($measured['secondary'], round($secondary, 2), $theme . ' --secondary');
            $this->assertSame($measured['dangerQuiet'], round($dangerQuiet, 2), $theme . ' --danger-quiet');
            $this->assertSame($measured['border'], round($border, 2), $theme . ' --secondary border');
        }
    }

    /** The formula itself, against the two reference pairs WCAG defines exactly. */
    public function test_the_contrast_formula_reproduces_its_own_reference_values(): void
    {
        $this->assertSame(21.0, round($this->contrast('#000000', '#ffffff'), 2));
        $this->assertSame(1.0, round($this->contrast('#777777', '#777777'), 2));
    }

    // -------------------------------------------------------------------------------------------
    // NEGATIVE CONTROLS on the rest of the screen.
    // -------------------------------------------------------------------------------------------

    public function test_the_ajax_contract_is_unchanged(): void
    {
        $source = $this->source();

        foreach ([
            "route('update-refund-status')",
            "route('cancel.refund.status')",
            "route('refund.stock')",
            'route(\'refund.amount\', $order[\'id\'])',
            'route(\'updateFinalPrice\', $order[\'id\'])',
            'refund_id: refundId,',
            'product_refund_qty: product_refund_qty,',
            'subtotal: subtotal,',
            'refundId: refundId,',
            'refund_amount: refund_amount,',
            'isTrendingChecked: isTrendingChecked,',
            "if (data.status === 'Cancel') {",
            "if (response.status === 'Refunded') {",
            "if (data.status === 'Accept') {",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source,
                'the shipped request/response contract of this screen must not change: '
                . 'refundDenied() answers response()->json([...], 403|404|422), so converting any '
                . 'of these to a form post changes the shape the handlers read');
        }
    }

    public function test_the_view_introduces_no_untranslated_string(): void
    {
        preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'\)/", $this->source(), $m);
        $strings = array_unique(array_map(
            static fn (string $s): string => str_replace("\\'", "'", $s),
            $m[1]
        ));

        $this->assertNotEmpty($strings);

        $ar = $this->lang('ar');
        $en = $this->lang('en');
        $missing = [];

        foreach ($strings as $string) {
            foreach (['ar' => $ar, 'en' => $en] as $locale => $table) {
                if (! array_key_exists($string, $table)) {
                    $missing[] = $locale . ': ' . $string;
                }
            }
        }

        $this->assertSame([], $missing,
            "the dialog must reuse the four keys that already exist in both files; a new literal "
            . "fails ArabicUiCoverageTest, and adding it to ar.json alone then fails "
            . "TranslationFileIntegrityTest:\n  " . implode("\n  ", $missing));
    }

    public function test_the_dialog_reuses_the_four_existing_translation_keys(): void
    {
        $source = $this->source();

        foreach ([
            'Are You Sure?',
            'This action can not be undone. Do you want to continue?',
            'Yes',
            'No',
        ] as $key) {
            $this->assertStringContainsString("__('" . $key . "')", $source);
            $this->assertArrayHasKey($key, $this->lang('ar'));
            $this->assertArrayHasKey($key, $this->lang('en'));
        }
    }

    /**
     * NEGATIVE CONTROL ON THE DETECTORS. The two rules that do the real work are run here against
     * the markup the view actually had, so a green run above cannot be a detector that stopped
     * looking.
     */
    public function test_the_detectors_still_recognise_the_markup_as_it_was(): void
    {
        $before = <<<'HTML'
<div class="text-end">
    <button class="btn btn-primary mt-2 btn-submit accept-button" data-refund-id="1">Accept Request</button>
    <button class="btn btn-primary btn-block mt-2 btn-submit cancel-button" data-refund-id="1" type="submit">Cancel Request</button>
        @if ($RefundStatus['manage_stock'] ?? '')
            <button class="btn btn-primary mt-2 btn-submit" id="manage-button">Manage Stock</button>
        @endif
    <button class="btn btn-primary mt-2 btn-submit " id="refund-amount"> Refund Amount</button>
</div>
@endsection
HTML;

        $bar = $this->actionBar($before);
        $lists = [];
        $bootstrap = [];

        foreach (array_keys(self::BUTTONS) as $handle) {
            preg_match('/class="([^"]*)"/', $this->buttonTag($bar, $handle), $m);
            $lists[$handle] = trim(preg_replace('/\s+/', ' ', (string) $m[1]));

            foreach (preg_split('/\s+/', $lists[$handle]) as $class) {
                if (in_array($class, ['btn', 'btn-primary', 'btn-block', 'btn-submit'], true)) {
                    $bootstrap[] = $handle . ': ' . $class;
                }
            }
        }

        // Three of the four class lists were byte-identical; the fourth differed only by a dead
        // Bootstrap 4 token. The "distinct class lists" rule must be able to see that.
        $this->assertLessThan(4, count(array_unique($lists)),
            'the hierarchy detector reports four distinct class lists for markup in which three '
            . 'are identical, so a green run above would prove nothing');
        $this->assertNotEmpty($bootstrap,
            'the cascade detector finds no Bootstrap token in markup that is nothing but '
            . 'Bootstrap tokens');
    }
}

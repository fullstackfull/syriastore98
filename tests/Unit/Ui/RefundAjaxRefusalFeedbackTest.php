<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 refund-silent-ajax-failures — a refused money action must say so.
 * =================================================================================================
 *
 * MEASURED DEFECT. `resources/views/order/refund_details.blade.php` makes five `$.ajax` calls.
 * Four of them declared only a `success` handler, so jQuery discarded every non-2xx answer; the
 * fifth declared an `error` handler that threw the server's message away and showed a hardcoded,
 * untranslated English sentence. `OrderRefundController` refuses with real status codes through
 * `refundDenied()` — 403 without `Manage Order Refund Request`, 404 for a refund outside the
 * tenant, 422 for an illegal state transition, 500 on a failed write — and Laravel answers 419 on
 * a stale CSRF token. There is no `ajaxError` subscriber anywhere in the app's own JS, so on the
 * two buttons that restock goods and pay money out a refusal produced NOTHING: no toast, no inline
 * message. The merchant could not tell a legitimate refusal from a dead button. The live route in
 * is a permission split: the page needs only `Show Order`, both buttons render unconditionally,
 * and the handlers require `Manage Order Refund Request`.
 *
 * WHY THIS IS A SOURCE SCAN. Every sibling UI contract in this directory reads the Blade file
 * rather than the rendered page, because rendering this view needs an `$order` array, a refund
 * model, `$productRefundData`, `$RefundStatus` and `$plan`, and a fixture that elaborate becomes
 * the thing under test. The behaviour here is entirely static JS inside `@push('custom-script')`,
 * so the file IS the artefact.
 *
 * STATUS-FIRST, NOT BODY-FIRST — this is the assertion that matters most, and it is the one a
 * naive repair gets wrong. A stale CSRF token answers 419 with a JSON body of its own
 * (`{"message":"CSRF token mismatch.", ...}`) and an unhandled 500 answers "Server Error", or the
 * raw exception text when debug is on. A handler that prefers `xhr.responseJSON.message` whenever
 * it is non-empty therefore shows the merchant framework English on a money path and leaves its
 * own translated strings permanently unreachable. The body is trusted only for the statuses this
 * controller itself authors.
 */
class RefundAjaxRefusalFeedbackTest extends TestCase
{
    private const VIEW = 'resources/views/order/refund_details.blade.php';

    /** The five calls, each pinned by a marker unique to its own options object. */
    private const CALLS = [
        'update-refund-status' => "route('update-refund-status')",
        'cancel.refund.status' => "route('cancel.refund.status')",
        'refund.stock'         => "route('refund.stock')",
        'updateFinalPrice'     => "route('updateFinalPrice'",
        'refund.amount'        => "route('refund.amount'",
    ];

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::VIEW);
    }

    /**
     * The text of every `$.ajax({ ... })` options object in the view, keyed by the call it belongs
     * to. Balanced-delimiter walk rather than a regex, because each options object contains nested
     * objects, nested functions and Blade `{{ }}` pairs — all of which are themselves balanced,
     * which is why counting works on the raw template.
     *
     * @return array<string,string>
     */
    private function ajaxOptionBlocks(string $source): array
    {
        $blocks = [];
        $offset = 0;

        while (($hit = strpos($source, '$.ajax(', $offset)) !== false) {
            $i = $hit + strlen('$.ajax(') - 1;   // sits on the opening paren
            $depth = 0;
            $end = null;

            for ($j = $i, $len = strlen($source); $j < $len; $j++) {
                $ch = $source[$j];
                if ($ch === '(' || $ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === '}' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            $this->assertNotNull($end, 'an unbalanced $.ajax( call — the view no longer parses');

            $block = substr($source, $hit, $end - $hit + 1);

            foreach (self::CALLS as $name => $marker) {
                if (str_contains($block, $marker)) {
                    $blocks[$name] = $block;
                }
            }

            $offset = $end;
        }

        return $blocks;
    }

    /** The body of a named JS function declared in the view, by balanced braces. */
    private function functionBody(string $source, string $declaration): ?string
    {
        $hit = strpos($source, $declaration);
        if ($hit === false) {
            return null;
        }

        $open = strpos($source, '{', $hit);
        if ($open === false) {
            return null;
        }

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

        return null;
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
    // Premise. Without this the rules below pass vacuously if the view is restructured.
    // -------------------------------------------------------------------------------------------

    public function test_the_scan_finds_all_five_refund_ajax_calls(): void
    {
        $blocks = $this->ajaxOptionBlocks($this->source());

        $this->assertSame(
            array_keys(self::CALLS),
            array_keys($blocks),
            'the scan no longer sees all five $.ajax calls on the refund screen, so every rule '
            . 'in this file is asserting against the wrong text'
        );
    }

    // -------------------------------------------------------------------------------------------
    // The repair.
    // -------------------------------------------------------------------------------------------

    public function test_every_refund_ajax_call_declares_an_error_handler(): void
    {
        $silent = [];

        foreach ($this->ajaxOptionBlocks($this->source()) as $name => $block) {
            if (! preg_match('/\berror\s*:/', $block)) {
                $silent[] = $name;
            }
        }

        $this->assertSame([], $silent,
            "these refund AJAX calls declare no `error:` handler, so jQuery discards every refusal "
            . "the controller sends — 403 without `Manage Order Refund Request`, 404 outside the "
            . "tenant, 422 on an illegal transition, 419 on a stale CSRF token — and the button "
            . "that restocks goods or pays money out looks dead rather than refused:\n  "
            . implode("\n  ", $silent));
    }

    public function test_every_refund_ajax_call_routes_its_refusal_through_the_one_shared_helper(): void
    {
        $notWired = [];

        foreach ($this->ajaxOptionBlocks($this->source()) as $name => $block) {
            if (! str_contains($block, 'refundAjaxRefusal(xhr)')) {
                $notWired[] = $name;
            }
        }

        $this->assertSame([], $notWired,
            'these calls do not hand their refusal to the shared helper, so the five handlers can '
            . 'drift apart — which is how one of them ended up showing an untranslated hardcoded '
            . "sentence instead of the server's message:\n  " . implode("\n  ", $notWired));
    }

    public function test_the_shared_refusal_helper_is_declared_exactly_once(): void
    {
        $this->assertSame(1, substr_count($this->source(), 'function refundAjaxRefusal('),
            'the refusal helper is declared more than once (or not at all); five call sites must '
            . 'share one body');
    }

    /**
     * THE BRANCH A NAIVE REPAIR GETS WRONG. Body-first selection makes the translated strings
     * unreachable, because 419 and an unhandled 500 both carry a framework-authored body.
     */
    public function test_the_refusal_helper_trusts_the_body_only_for_statuses_the_controller_authors(): void
    {
        $body = $this->functionBody($this->source(), 'function refundAjaxRefusal(');
        $this->assertNotNull($body, 'the refusal helper is not in the view');

        $this->assertTrue(
            (bool) preg_match('/var\s+authored\s*=\s*\(([^;]+)\);/', $body, $m),
            'the helper no longer declares which statuses it will trust the response body for, so '
            . 'nothing here can tell body-first from status-first'
        );

        preg_match_all('/xhr\.status\s*===\s*(\d{3})/', $m[1], $codes);
        $authored = array_map('intval', $codes[1]);

        sort($authored);
        $this->assertSame([403, 404, 409, 422], $authored,
            'the statuses whose body the helper trusts must be exactly the ones refundDenied() '
            . 'writes itself. 419 is authored by Laravel ("CSRF token mismatch.") and 500 by the '
            . 'framework ("Server Error", or the raw exception text with debug on): trusting '
            . 'either shows the merchant framework English on a money path and leaves the '
            . 'translated strings below permanently dead');

        // And the statuses it does NOT trust must each still reach a translated string.
        $this->assertMatchesRegularExpression(
            '/xhr\.status\s*===\s*419[\s\S]{0,200}Your session has expired\. Please reload the page\./',
            $body,
            'a stale CSRF token (419) must produce the translated session message, not Laravel\'s '
            . 'own "CSRF token mismatch." body'
        );
        $this->assertStringContainsString('Something went wrong. Please try again.', $body,
            'an unhandled status (500) must still produce a translated fallback');
        $this->assertStringContainsString('You are not allowed to do this.', $body,
            'a 403 with no body must still say the merchant is not allowed');
    }

    public function test_the_discarded_untranslated_final_price_message_is_gone(): void
    {
        $this->assertStringNotContainsString('Error updating final price.', $this->source(),
            'the one existing error handler threw the server\'s own 403/404 message away and showed '
            . 'this hardcoded English sentence — unwrapped, so ArabicUiCoverageTest cannot see it, '
            . 'and present in neither lang file');
    }

    // -------------------------------------------------------------------------------------------
    // NEGATIVE CONTROLS.
    // -------------------------------------------------------------------------------------------

    /**
     * THE OVER-REACH THAT DOES REAL DAMAGE. `manageButtonClicked` and `refundedClicked` are read
     * on every later page load. Writing either from a refusal handler — or hiding the control —
     * permanently removes the button for a merchant who was refused once before a permission was
     * granted, with no way back but clearing site data.
     */
    public function test_the_refusal_helper_neither_hides_a_control_nor_writes_the_localstorage_gates(): void
    {
        $body = $this->functionBody($this->source(), 'function refundAjaxRefusal(');
        $this->assertNotNull($body, 'the refusal helper is not in the view');

        $this->assertStringNotContainsString('localStorage', $body,
            'a refusal must not write the visibility gates: those keys are read on every later '
            . 'page load, so a merchant refused once could never reach the button again');
        $this->assertStringNotContainsString('.hide(', $body,
            'a refusal must leave the control usable once the permission is granted');
        $this->assertStringNotContainsString('#loader', $body,
            'public/js/loader.js:14 only ever fades the loader IN for input[type="submit"], and '
            . 'these triggers are <button>, so $(\'#loader\').fadeOut() is a measured no-op here; '
            . 'repeating it in five new places invites the next reader to believe a spinner exists');
    }

    /** A test that drives only a refusal cannot see an `error:` block that ate the success path. */
    public function test_the_success_paths_are_untouched(): void
    {
        $blocks = $this->ajaxOptionBlocks($this->source());

        $stock = $blocks['refund.stock'];
        $this->assertStringContainsString("$('#manage-button').hide();", $stock);
        $this->assertStringContainsString("localStorage.setItem('manageButtonClicked', 'true');", $stock);
        $this->assertStringContainsString("show_toastr('Success', response.message, 'success');", $stock);

        $amount = $blocks['refund.amount'];
        $this->assertStringContainsString("if (response.status === 'Refunded') {", $amount);
        $this->assertStringContainsString("localStorage.setItem('refundedClicked', 'true');", $amount);

        // The in-band else at the tail of `success` is RefundAmonut's documented 200 shape
        // ({status, amount, capped, message}) for a status that is not 'Refunded'. A repair phrased
        // as "a failure shows a toast" can be satisfied by moving this into `error:`, which would
        // stop a successful-but-not-Refunded answer from re-showing the button.
        $this->assertMatchesRegularExpression(
            '/\}\s*else\s*\{\s*\$\(\'#refund-amount\'\)\.show\(\);\s*show_toastr\(\'Error\', response\.message, \'error\'\);/',
            $amount,
            "the 200-with-non-'Refunded' branch must stay inside success:, not migrate into error:"
        );

        $finalPrice = $blocks['updateFinalPrice'];
        $this->assertStringContainsString("$('.refunded_amount').html('<b class=\"abc\">' + response.refund_amount + '</b>');", $finalPrice);
    }

    /** The shipped request/response contract these handlers read. */
    public function test_the_request_payloads_are_unchanged(): void
    {
        $source = $this->source();
        $blocks = $this->ajaxOptionBlocks($source);

        // The two money-path calls build their body in a `data` object declared just above the
        // call, so the keys are asserted against the view rather than the options block.
        foreach ([
            'refundId: refundId,',
            'product_refund_qty: product_refund_qty,',
            'refund_amount: refund_amount,',
            'isTrendingChecked: isTrendingChecked,',
        ] as $key) {
            $this->assertStringContainsString($key, $source,
                'the shipped request body of the restock / refund calls must not change');
        }
        $this->assertStringContainsString('data: data,', $blocks['refund.stock']);
        $this->assertStringContainsString('data: data,', $blocks['refund.amount']);

        // CSRF travels as the X-CSRF-TOKEN header from $.ajaxSetup (public/js/custom.js:1-4) on
        // these two; neither sent a `_token` field before and adding one is not a fix.
        $this->assertStringNotContainsString('_token', $blocks['refund.stock']);
        $this->assertStringNotContainsString('_token', $blocks['refund.amount']);
        // The other three always did send one; that must not be stripped.
        $this->assertStringContainsString('_token', $blocks['update-refund-status']);
        $this->assertStringContainsString('_token', $blocks['cancel.refund.status']);
        $this->assertStringContainsString('_token', $blocks['updateFinalPrice']);
    }

    public function test_every_string_the_refusal_helper_shows_is_translated_in_both_languages(): void
    {
        $body = $this->functionBody($this->source(), 'function refundAjaxRefusal(');
        $this->assertNotNull($body, 'the refusal helper is not in the view');

        preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'\)/", $body, $m);
        $strings = array_unique($m[1]);

        $this->assertNotEmpty($strings, 'the helper shows no translated string at all');

        $ar = $this->lang('ar');
        $en = $this->lang('en');
        $missing = [];

        foreach ($strings as $string) {
            $key = str_replace("\\'", "'", $string);
            foreach (['ar' => $ar, 'en' => $en] as $locale => $table) {
                if (! array_key_exists($key, $table)) {
                    $missing[] = $locale . ': ' . $key;
                }
            }
        }

        $this->assertSame([], $missing,
            "a refusal message with no translation is an English toast for an Arabic merchant on a "
            . "money path; adding it to ar.json alone then fails TranslationFileIntegrityTest:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * NEGATIVE CONTROL ON THE DETECTOR ITSELF. The rule above is only worth anything if it can
     * still recognise the shape it was written against, so it is run here over the shape the view
     * actually had before the repair.
     */
    public function test_the_detector_still_recognises_a_call_with_no_error_callback(): void
    {
        $before = <<<'JS'
            $.ajax({
                url: '{{ route('refund.stock') }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(response) {
                    $('#manage-button').hide();
                    show_toastr('Success', response.message, 'success');
                }
            });
JS;

        $blocks = $this->ajaxOptionBlocks($before);

        $this->assertArrayHasKey('refund.stock', $blocks,
            'the balanced-delimiter walk can no longer find a call it is meant to judge');
        $this->assertDoesNotMatchRegularExpression('/\berror\s*:/', $blocks['refund.stock'],
            'the detector reports an `error:` handler in a block that has none, so a green run '
            . 'above would prove nothing');
        $this->assertStringNotContainsString('refundAjaxRefusal(xhr)', $blocks['refund.stock']);
    }
}

<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 toast-errors-autodismiss — an error message must not be destroyed on a 4s timer.
 * =================================================================================================
 *
 * THE DEFECT, MEASURED. `show_toastr()` in `public/js/custom.js` passed the literal `4000` as the
 * fifth (timeout) argument on BOTH branches. `public/assets/js/plugins/notifier.js` ends its
 * `show(title,msg,type,icon,timeout)` in `timeout > 0 && setTimeout(function(){hide(ntfId)},
 * timeout)`, and `hide()` strips the ` shown` class and then `removeChild`s the node 600ms later —
 * so an error toast is DESTROYED, not merely faded out. The text is unrecoverable rather than
 * re-readable, because `resources/views/layouts/app.blade.php` runs `Session::forget('error')` in
 * the same render that emitted the toast. A merchant who looks away for five seconds loses the
 * reason their save failed, for good, and a reload will not bring it back.
 *
 * WCAG 2.2.1 exempts a time limit only above 20 hours or when the user can adjust it. 4000ms is
 * neither.
 *
 * WHY THE PERSISTENCE IS GATED, which is the whole subtlety of this slice. `public/js/custom.js`
 * is NOT an admin-only asset. It is pulled in directly, with no local `show_toastr` of its own, by
 * live shopper and public surfaces: `resources/views/payment/whatsapp.blade.php`,
 * `payment/telegram.blade.php`, `payment/khalti.blade.php`, `DPO/pay.blade.php` and the vendored
 * `LandingPage` public-website layout. An unqualified "errors never dismiss" would therefore
 * change customer checkout and the public website — Phase 03 and payment surfaces that Phase 2.5
 * does not authorise. So persistence is switched on only where the merchant/employee admin chrome
 * is present, and this test asserts that gate from both sides.
 *
 * AND THE STACK IS CAPPED, which is not optional once errors persist.
 * `public/assets/css/plugins/notifier.css` gives `.notifier-container` no `max-height` and no
 * `overflow` (`z-index:9999;position:fixed;top:4px;right:4px;padding:4px;width:350px;
 * max-width:98%` is the complete rule), and each `.notifier` is `min-height:62px;
 * margin-bottom:12px`. A merchant retrying a failing save a dozen times would otherwise build a
 * fixed column taller than the viewport whose lowest toasts cannot be reached — and the only
 * dismiss control is a 16x16 `.notifier-close` whose entire content is `innerHTML = "&times;"`.
 * The cap removes the oldest nodes SYNCHRONOUSLY rather than through `notifier.hide()`: the plugin
 * only assigns the node's id 100ms after creation, so an id-based hide silently no-ops on
 * rapid-fire, and `hide()` sets a 600ms `removeChild` timer that would throw against a null
 * `parentNode` if a user close-click had already removed the node.
 */
class ToastErrorPersistenceContractTest extends TestCase
{
    /**
     * The host notifier plugin as shipped. Pinned because the repair depends on TWO of its
     * behaviours (0 means "no timeout"; nothing in it announces to assistive technology) and
     * because this slice must not edit it.
     */
    private const NOTIFIER_SHA256 = '7e1cc666ccaec57a30ddf396fb7a7fd4a8247af612df49b9e507749ff3b9d863';

    /** Loaders of public/js/custom.js that are NOT the merchant admin and must keep the 4s timer. */
    private const OUT_OF_SCOPE_LOADERS = [
        'resources/views/payment/whatsapp.blade.php',
        'resources/views/payment/telegram.blade.php',
        'resources/views/payment/khalti.blade.php',
        'resources/views/DPO/pay.blade.php',
        'packages/workdo/LandingPage/src/Resources/views/layouts/landingpage.blade.php',
    ];

    private function repo(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $path = $this->repo($relative);

        $this->assertFileExists($path, "premise: {$relative} is missing");

        return (string) file_get_contents($path);
    }

    /**
     * Comments blanked before asserting — block comments included. A source assertion that can be
     * satisfied by prose ABOUT the code is a test that cannot fail, and this project has measured
     * that twice. Identical to `TransientFeedbackContractTest::withoutComments()` on purpose.
     */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~^[ \t]*//.*$~m', '', $source);
    }

    private function customJs(): string
    {
        return $this->withoutComments($this->read('public/js/custom.js'));
    }

    /** The `notifier.show(...)` call on the given branch of show_toastr, code only. */
    private function toastCall(string $branch): string
    {
        $js = $this->customJs();

        $start = strpos($js, 'function show_toastr');
        $this->assertNotFalse($start, 'premise: show_toastr was renamed or removed from custom.js');

        $body = substr($js, (int) $start, 1200);

        $needle = "notifier.show('" . $branch . "'";
        $at = strpos($body, $needle);
        $this->assertNotFalse($at, "premise: the {$branch} branch of show_toastr no longer calls notifier.show");

        $end = strpos($body, ");\n", (int) $at);
        $this->assertNotFalse($end, "premise: the {$branch} branch's notifier.show call is unterminated");

        return substr($body, (int) $at, (int) $end - (int) $at + 1);
    }

    /**
     * PREMISE. 0 is the plugin's OWN sentinel for "no timeout" — `"number" != typeof timeout &&
     * (timeout = 0)` plus the `timeout > 0` guard — which is why the repair needs no plugin edit.
     * If an upgrade changes that, passing 0 could mean "dismiss immediately" and the repair would
     * invert into a worse defect than the one it fixed.
     */
    public function test_premise_the_notifier_plugin_treats_zero_as_no_timeout(): void
    {
        $plugin = $this->read('public/assets/js/plugins/notifier.js');

        $this->assertStringContainsString('"number" != typeof timeout && (timeout = 0)', $plugin,
            'the notifier plugin no longer coerces a non-number timeout to 0, so 0 may no longer '
            . 'mean "never auto-dismiss" — re-read the toast-errors-autodismiss slice');
        $this->assertMatchesRegularExpression('~timeout\s*>\s*0\s*&&\s*setTimeout~', $plugin,
            'the notifier plugin no longer guards its auto-dismiss timer on timeout > 0, so a '
            . 'timeout of 0 would now schedule an immediate hide instead of none at all');
    }

    /**
     * NEGATIVE CONTROL. The host plugin is untouched by this slice, byte for byte. It is also
     * pinned by `TransientFeedbackContractTest::test_premise_the_notifier_plugin_announces_nothing_itself()`
     * at zero `aria-*` matches — so the tempting follow-on fix (an accessible name on the 16x16
     * close button, which a persistent toast makes mandatory to use) does NOT belong in this file
     * or this change.
     */
    public function test_the_host_notifier_plugin_is_byte_identical(): void
    {
        $this->assertSame(self::NOTIFIER_SHA256, hash('sha256', $this->read('public/assets/js/plugins/notifier.js')),
            'public/assets/js/plugins/notifier.js changed. The toast-errors-autodismiss repair is '
            . 'confined to public/js/custom.js; if the plugin genuinely needed editing, re-read '
            . 'the slice and this pin rather than updating the hash to make the test quiet');
    }

    /**
     * THE REPAIR. The error branch no longer hands the plugin a literal 4000: its timeout is
     * chosen at call time, and 0 is reachable.
     */
    public function test_an_error_toast_can_be_left_on_screen_instead_of_destroyed_on_a_timer(): void
    {
        $call = $this->toastCall('Error');

        $this->assertNoLiteralFourSecondTimeout($call);

        $this->assertMatchesRegularExpression('~\?\s*0\s*:\s*4000~', $call,
            'the error branch does not choose between 0 and 4000, so either errors are still '
            . 'destroyed on a 4s timer everywhere, or they now persist on shopper checkout and '
            . 'the public website too — both are wrong: ' . trim($call));
    }

    private function assertNoLiteralFourSecondTimeout(string $call): void
    {
        $this->assertDoesNotMatchRegularExpression('~,\s*4000\s*\)~', $call,
            'the error branch still ends in a literal 4000 timeout, so notifier.js still runs '
            . 'hide() after 4s and removeChild 600ms later — and layouts/app.blade.php has '
            . 'already run Session::forget(\'error\'), so the text is gone for good: ' . trim($call));
    }

    /**
     * NEGATIVE CONTROL, and the half that must NOT change. A success toast still auto-dismisses at
     * 4000ms. Without this, every saved product leaves permanent litter on the screen and the
     * repair trades one defect for a worse one.
     */
    public function test_a_success_toast_still_auto_dismisses_after_four_seconds(): void
    {
        $call = $this->toastCall('Success');

        $this->assertMatchesRegularExpression('~,\s*4000\s*\)~', $call,
            'the success branch no longer passes the literal 4000, so successful saves now leave '
            . 'permanent toasts stacking up on the screen: ' . trim($call));
        $this->assertStringNotContainsString('?', $call,
            'the success branch grew a conditional timeout; only the ERROR branch is in scope for '
            . 'this slice: ' . trim($call));
    }

    /**
     * THE GATE, from the inside. Persistence is decided by an explicit flag when one is set, and
     * otherwise by the presence of the merchant/employee admin chrome — `#commanModelOver`, which
     * `resources/views/layouts/app.blade.php` declares and which no other loader of custom.js has.
     */
    public function test_the_persistence_is_decided_by_the_admin_chrome_not_unconditionally(): void
    {
        $js = $this->customJs();

        $this->assertStringContainsString('window.ss_persist_errors', $js,
            'custom.js does not honour an explicit ss_persist_errors flag, so the admin layout '
            . 'cannot opt in or out of persistent errors');
        $this->assertStringContainsString("getElementById('commanModelOver')", $js,
            'custom.js has no admin-chrome signal to fall back on, so persistence is either off '
            . 'everywhere or on everywhere — including shopper checkout');

        $marker = $this->read('resources/views/layouts/app.blade.php');
        $this->assertStringContainsString('id="commanModelOver"', $marker,
            'premise: layouts/app.blade.php no longer declares #commanModelOver, so the gate that '
            . 'keeps persistent errors inside the merchant admin now matches nothing and every '
            . 'error toast silently reverts to the 4s timer');
    }

    /**
     * THE GATE, from the outside — the assertion that actually keeps shopper checkout and the
     * public website on 4 seconds. These five views load public/js/custom.js directly, define no
     * show_toastr of their own, and carry neither the flag nor the admin chrome. Payment pages and
     * the public website are Phase 03 / vendored scope.
     */
    public function test_shopper_checkout_and_the_public_website_keep_the_four_second_timer(): void
    {
        foreach (self::OUT_OF_SCOPE_LOADERS as $view) {
            $markup = $this->read($view);

            $this->assertStringNotContainsString('function show_toastr', $markup,
                "premise: {$view} now defines its own show_toastr, so it no longer shares the one "
                . 'in custom.js and this slice\'s reasoning about it is stale');
            $this->assertStringNotContainsString('ss_persist_errors', $markup,
                "{$view} now switches persistent errors ON. It is a shopper/public surface: an "
                . 'error that never dismisses there is out of Phase 2.5 scope');
            $this->assertStringNotContainsString('commanModelOver', $markup,
                "{$view} now carries the admin-chrome marker, so the gate would let persistent "
                . 'errors leak onto a shopper or public-website page');
        }
    }

    /**
     * THE CAP. Once errors persist, the unbounded fixed-position container is a real trap, so the
     * oldest error nodes are removed — synchronously, and only on the gated path.
     */
    public function test_the_persistent_error_stack_is_capped(): void
    {
        $js = $this->customJs();

        $this->assertStringContainsString('.notifier-container .notifier.danger', $js,
            'nothing counts the error toasts already on screen, so a merchant retrying a failing '
            . 'save builds a fixed column taller than the viewport whose lowest toasts cannot be '
            . 'clicked (notifier.css gives .notifier-container no max-height and no overflow)');
        $this->assertStringContainsString('parentNode.removeChild', $js,
            'the cap does not remove the node itself');
        $this->assertStringNotContainsString('notifier.hide(', $js,
            'the cap goes through notifier.hide(), which is unsafe here twice over: the plugin '
            . 'only sets the node id 100ms after creation so an id-based hide no-ops on '
            . 'rapid-fire, and hide() queues a removeChild 600ms later that throws against a null '
            . 'parentNode if the user already clicked close');

        $call = $this->toastCall('Error');
        $this->assertStringContainsString('persist', $call,
            'the error branch does not read the same gate the cap does, so the cap and the '
            . 'persistence can disagree');
    }

    /**
     * NEGATIVE CONTROL for the shipped accessibility half, which this slice must not disturb.
     * `TransientFeedbackContractTest` matches `ss_announce_transient(message, type != 'success')`
     * by regex INSIDE a 1200-byte window opened at `function show_toastr`, so the call must keep
     * its exact shape AND stay inside the budget. Measured before this repair: the match ended
     * 494 bytes into the window, 706 free. This asserts the budget explicitly so the next person
     * to add code inside show_toastr finds out here rather than in an unrelated test.
     */
    public function test_the_assistive_announcement_keeps_its_shape_and_stays_inside_the_byte_window(): void
    {
        $js = $this->customJs();

        $start = strpos($js, 'function show_toastr');
        $this->assertNotFalse($start, 'premise: show_toastr was renamed or removed');

        $body = substr($js, (int) $start, 1200);

        $matched = preg_match(
            "~ss_announce_transient\(\s*message\s*,\s*type\s*!=\s*'success'\s*\)~",
            $body,
            $m,
            PREG_OFFSET_CAPTURE
        );

        $this->assertSame(1, $matched,
            'the assistive announcement no longer matches inside the 1200-byte window from '
            . '`function show_toastr`. Either its shape changed or the gate/cap code pushed it out '
            . 'of the window — TransientFeedbackContractTest::test_an_error_is_announced_assertively '
            . 'and ::test_the_toast_announces_to_assistive_technology both fail on this');

        $end = $m[0][1] + strlen($m[0][0]);
        $this->assertLessThan(1200, $end,
            'the announcement call now ends beyond the 1200-byte window');
        $this->assertGreaterThan(0, 1200 - $end,
            'no window headroom left at all');
    }

    /**
     * NEGATIVE CONTROL. ZERO stylesheets change. A `.ss-toast` component is the over-reach here:
     * it would need `public/css/custom.css` AND `public/css/rtl-custom.css` plus a dark variant,
     * and it is a separate sequencing item in
     * `docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md`. Note what is deliberately NOT fixed:
     * `.notifier-container` is hardcoded `right:4px` with no RTL mirror, so a persistent error
     * sits top-right in Arabic too. Pre-existing, and mirroring it here is exactly the
     * two-stylesheet trap this project keeps falling into.
     */
    public function test_no_stylesheet_is_touched_and_no_new_toast_component_appears(): void
    {
        foreach (['public/css/store-syria-ds.css', 'public/css/custom.css', 'public/css/rtl-custom.css'] as $css) {
            /* DECLARATIONS, NOT BYTES (P2.5-WAVE-23). This counted raw occurrences and reddened on
               a COMMENT -- the phone-overflow block records, in prose, why `.notifier-container`
               was investigated as a cause of page overflow and then deliberately left alone: it
               is `position: fixed`, `width: 400px; max-width: 98%`, empty, and hiding it changed
               scrollWidth by nothing on all five affected screens. A comment cannot style
               anything, so stripping comments does not weaken this guarantee -- it makes the
               assertion say what its own name says. FOURTH time a test in this project has failed
               on its own explanation; the others were ModalFooterButtonContrastTest,
               ModalFooterDividerAlignmentTest and FocusIndicatorRestorationTest. */
            $sheet = (string) preg_replace('#/\*.*?\*/#s', '', $this->read($css));

            $this->assertSame(0, preg_match_all('~notifier~i', $sheet),
                $css . ' now styles the notifier. This slice changes no CSS: a toast restyle is a '
                . 'separate sequencing item and would need the custom.css / rtl-custom.css pair '
                . 'plus a dark variant, which is how half the merchants get missed');
            $this->assertSame(0, preg_match_all('~ss-toast~i', $sheet),
                $css . ' introduces an .ss-toast component — that is the over-reach, not this slice');
        }

        $this->assertSame(0, preg_match_all('~ss-toast~i', $this->read('public/js/custom.js')),
            'custom.js now builds an .ss-toast; the repair is a timeout argument and a cap, not a '
            . 'replacement toast component');
    }

    /**
     * NEGATIVE CONTROL. The storefront is Phase 03 and has its OWN show_toastr, with its own
     * 4000ms on both branches. It must still be there, and still be 4000 — if a later change
     * deletes it, the storefront would start inheriting custom.js's gated copy.
     */
    public function test_the_storefront_keeps_its_own_four_second_dismissal(): void
    {
        $storefront = $this->withoutComments($this->read('resources/views/front_end/layouts/app.blade.php'));

        $at = strpos($storefront, 'function show_toastr');
        $this->assertNotFalse($at,
            'premise: the storefront layout no longer defines its own show_toastr, so it would '
            . 'now fall through to the gated copy in public/js/custom.js — re-read the scope of '
            . 'the toast-errors-autodismiss slice before accepting that');

        $body = substr($storefront, (int) $at, 900);

        $this->assertSame(2, preg_match_all('~,\s*4000\s*\)~', $body),
            'the storefront show_toastr no longer passes 4000 on both branches; shopper toasts '
            . 'are Phase 03 scope and this slice does not change them');
    }
}

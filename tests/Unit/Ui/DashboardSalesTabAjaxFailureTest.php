<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * bysales-ajax-loader-stuck — A FAILED SALES-TAB FETCH MUST RELEASE THE FULL-VIEWPORT SCRIM.
 * =========================================================================================
 *
 * `resources/views/dashboard.blade.php` raises the global blocking overlay on every sales-tab
 * change (`$('#loader').fadeIn()`, twice) and released it from ONE place only: the `success`
 * callback of `fetchOrderDetails()`. The `error` callback held `console.error(error)` and nothing
 * else, and the `$.ajax` object has no `complete:` key.
 *
 * `#loader` (`resources/views/layouts/app.blade.php`) is styled by `public/css/loader.css` as
 * `position: fixed; top/left/right: 0; width/height: 100%; background: #0000008c;
 * z-index: 9999999` with NO `pointer-events` escape and NO dismiss control. `public/js/loader.js`
 * only ever fades it on navigation, submit and export. So any non-2xx or network failure on
 * `POST /top-brand-category-chart` left the merchant looking at a dark scrim over a dashboard they
 * could not click, with only a reload to recover — and the failure was announced nowhere, because
 * `#loader` carries no `role`, `aria-live` or `aria-busy` and the console is the one place a
 * merchant never looks.
 *
 * The route is genuinely reachable in that state: `routes/web.php` declares it inside the
 * `['auth', 'xss', 'setlocate', 'verified']` group, and `ValidateCsrfToken` runs before `auth`, so
 * a dashboard left open past the session lifetime answers 419 rather than redirecting.
 *
 * WHY THIS IS A SOURCE TEST. The failure state only exists while a jQuery `error` callback is
 * running. A Feature test renders markup; it does not execute the page's JavaScript, so it cannot
 * observe the overlay being released or the alert being injected. What a source ratchet CAN prove,
 * and what no browser check easily can, is the ABSENCE of a regression: that nobody later deletes
 * the release, swaps the computed selector for a literal, or reintroduces the untranslated string.
 *
 * THE TRAP THIS TEST EXISTS TO HOLD SHUT. The obvious repair writes a bespoke English sentence
 * through `__()`. That is F259 reproduced by the fix that cites F259: `__()` falls back to the key,
 * so an Arabic merchant would be shown English inside a `dir="rtl"` document — and `role="alert"`
 * carries implicit `aria-live="assertive"`, so the one new accessibility affordance would ANNOUNCE
 * that English to an Arabic screen-reader user. Nothing would have caught it:
 * `tests/Unit/Merchant/ArabicUiCoverageTest.php`'s SURFACES globs
 * `resources/views/dashboard/*.blade.php` — the DIRECTORY, which resolves to
 * `dashboard/overview.blade.php` only — so `resources/views/dashboard.blade.php` is scanned by no
 * pattern. Hence `test_every_message_the_failure_path_can_show_is_translated_into_arabic`, which
 * resolves the keys out of the callback and asserts each one against BOTH language files.
 */
class DashboardSalesTabAjaxFailureTest extends TestCase
{
    private const VIEW = 'resources/views/dashboard.blade.php';

    /** The view's source with Blade comments stripped, so a `{{-- --}}` note cannot satisfy a rule. */
    private function code(): string
    {
        $source = (string) file_get_contents(base_path(self::VIEW));

        $this->assertNotSame('', $source, self::VIEW . ' could not be read');

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /**
     * The body of the `error:` callback belonging to `fetchOrderDetails()`'s `$.ajax` call,
     * found by brace matching rather than by line number, which drifts.
     */
    private function errorCallback(): string
    {
        $code = $this->code();

        $fn = strpos($code, 'function fetchOrderDetails(');
        $this->assertNotFalse($fn, 'fetchOrderDetails() is gone from ' . self::VIEW);

        $start = strpos($code, 'error: function', $fn);
        $this->assertNotFalse($start, 'the $.ajax call in fetchOrderDetails() has no error: callback');

        $open = strpos($code, '{', $start);
        $this->assertNotFalse($open, 'the error: callback has no body');

        $depth = 0;

        for ($i = $open; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        $this->fail('the error: callback body is unbalanced; brace matching ran off the end');
    }

    /** The body of the `success:` callback, by the same method — used as a negative control. */
    private function successCallback(): string
    {
        $code = $this->code();

        $fn = strpos($code, 'function fetchOrderDetails(');
        $start = strpos($code, 'success: function', (int) $fn);
        $this->assertNotFalse($start, 'the $.ajax call in fetchOrderDetails() has no success: callback');

        $open = (int) strpos($code, '{', $start);
        $depth = 0;

        for ($i = $open; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        $this->fail('the success: callback body is unbalanced');
    }

    /**
     * THE DEFECT ITSELF. Without this the scrim stays up for the rest of the session.
     */
    public function test_a_failed_sales_tab_fetch_releases_the_blocking_overlay(): void
    {
        $error = $this->errorCallback();

        $this->assertStringContainsString("$('#loader').fadeOut()", $error,
            'the error: callback of fetchOrderDetails() does not release #loader, so a non-2xx or '
            . 'network failure on POST /top-brand-category-chart strands a position:fixed '
            . 'z-index:9999999 scrim over the whole dashboard with no dismiss control — only a '
            . 'reload recovers');
    }

    /**
     * THE FAILURE MUST BE ANNOUNCED, NOT ONLY LOGGED. `role="alert"` carries implicit
     * `aria-live="assertive"`; `#loader` deliberately gains no live region (see
     * `test_the_shared_loader_overlay_did_not_gain_a_live_region`).
     */
    public function test_a_failed_sales_tab_fetch_tells_the_merchant_in_the_card(): void
    {
        $error = $this->errorCallback();

        $this->assertStringContainsString('ss-alert ss-alert--danger', $error,
            'the failure renders no design-system alert, so the merchant is told nothing');
        $this->assertStringContainsString('role: \'alert\'', $error,
            'the injected alert carries no role="alert", so a screen-reader user is told nothing '
            . '(SC 4.1.3)');
        $this->assertStringContainsString('ss-alert__body', $error,
            'the message text is not in .ss-alert__body, which is what exempts it from the host '
            . 'dark sheets\' p:not([class^="ss-"]) { color: #808191 !important } greying');
    }

    /**
     * `.ss-alert` is `display: flex; align-items: flex-start`, and `.ss-alert__content` carries
     * `min-width: 0` precisely so a long Arabic sentence cannot overflow the flex line. A bare
     * `<p>` as a direct flex child has `min-width: auto`. The only other usage in the repository
     * (`resources/views/setting/payment_partials/confirm_secret_removal.blade.php`) wraps it.
     */
    public function test_the_alert_body_is_wrapped_in_the_min_width_zero_content_element(): void
    {
        $error = $this->errorCallback();

        $body = strpos($error, 'ss-alert__body');
        $content = strpos($error, 'ss-alert__content');

        $this->assertNotFalse($content,
            'the <p class="ss-alert__body"> is a direct child of the display:flex .ss-alert, '
            . 'skipping .ss-alert__content { min-width: 0 }, so a long message overflows');
        $this->assertLessThan($body, $content,
            '.ss-alert__content must WRAP .ss-alert__body, not follow it');
    }

    /**
     * EVERY STRING THE FAILURE PATH CAN SHOW MUST ALREADY BE TRANSLATED — F259, inverted.
     *
     * This is the assertion that makes the repair safe rather than the repair that reintroduces
     * F259. `resources/views/dashboard.blade.php` is scanned by NO ArabicUiCoverageTest::SURFACES
     * glob, so without this nothing in CI would notice an English-only sentence here.
     */
    public function test_every_message_the_failure_path_can_show_is_translated_into_arabic(): void
    {
        $error = $this->errorCallback();

        preg_match_all('/@json\(__\(\'((?:[^\'\\\\]|\\\\.)*)\'\)\)/', $error, $m);

        $keys = $m[1];

        $this->assertNotEmpty($keys,
            'the error: callback shows no message built through @json(__()), so either the merchant '
            . 'is told nothing or the text is a hardcoded JS literal the translator cannot reach');

        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($en);
        $this->assertIsArray($ar);

        foreach ($keys as $key) {
            $key = stripslashes($key);

            $this->assertArrayHasKey($key, $en,
                "the failure message \"$key\" is not declared in resources/lang/en.json");
            $this->assertArrayHasKey($key, $ar,
                "the failure message \"$key\" has no Arabic translation, so __() falls back to the "
                . 'English key and role="alert" announces English to an Arabic screen-reader user '
                . 'inside a dir="rtl" document — F259 reproduced');
            $this->assertNotSame($key, $ar[$key],
                "resources/lang/ar.json maps \"$key\" to itself, which is an English string wearing "
                . 'an Arabic key');
        }
    }

    /**
     * A 419 AND A 403 ARE NOT "something went wrong". The session-expiry wording matters because
     * session expiry is the likeliest way a merchant reaches this callback at all, and "please try
     * again" is dishonest for it — retrying fails identically. Matches the house precedent in
     * `resources/views/order/index.blade.php`.
     */
    public function test_the_message_distinguishes_a_refusal_and_a_dead_session_from_a_generic_failure(): void
    {
        $error = $this->errorCallback();

        $this->assertStringContainsString('xhr.status === 403', $error,
            'a 403 refusal is reported as a generic failure');
        $this->assertStringContainsString('xhr.status === 419', $error,
            'an expired CSRF token / dead session is reported as "please try again", which is '
            . 'dishonest — retrying fails identically');
        $this->assertStringContainsString('xhr.status === 401', $error,
            'auth on this POST renders JSON 401 rather than a redirect because jQuery sends '
            . 'Accept: */*, so 401 is the other shape a dead session takes on this route');
    }

    /**
     * THE ABORT CASE — a regression the release itself can introduce.
     *
     * `public/js/loader.js` fades the overlay IN on `beforeunload`. A request still in flight when
     * the merchant navigates away is aborted by the browser and jQuery calls `error` with
     * `textStatus === 'abort'`; releasing the overlay there would tear down the navigation scrim
     * loader.js deliberately raised, and park a false red alert on a page that is leaving.
     */
    public function test_an_aborted_request_does_not_tear_down_the_navigation_overlay(): void
    {
        $error = $this->errorCallback();

        $this->assertMatchesRegularExpression('/status === \'abort\'/', $error,
            'the error: callback does not distinguish an abort, so navigating away mid-request '
            . 'fades out the overlay loader.js raised on beforeunload');

        $guard = strpos($error, "status === 'abort'");
        $release = strpos($error, "$('#loader').fadeOut()");

        $this->assertNotFalse($release);
        $this->assertLessThan($release, $guard,
            'the abort guard must come BEFORE the release, or it guards nothing');
    }

    // ---------------------------------------------------------------------------------------
    // NEGATIVE CONTROLS
    // ---------------------------------------------------------------------------------------

    /**
     * NEGATIVE CONTROL — THE PROXY-LITERAL TRAP.
     *
     * `DashboardFigureHonestyTest` asserts `substr_count($body, 'category-tab-data') === 0` for a
     * staff member without Manage Order. The containers are inside
     * `@if (MerchantDashboardAbilities::mayManageOrders())`, but this script push is UNGATED, so a
     * literal `$('#category-tab-data')` in the error handler would put the string on an unentitled
     * principal's page and flip that assertion. The selector must stay computed.
     */
    public function test_the_failure_path_adds_no_literal_container_id_to_an_ungated_script(): void
    {
        $error = $this->errorCallback();

        $this->assertStringNotContainsString('category-tab-data', $error,
            'a literal container id in the ungated @push(\'custom-script\') block puts the string '
            . 'on the page of a staff member without Manage Order');
        $this->assertStringNotContainsString('brand-tab-data', $error);
        $this->assertStringContainsString("'#' + type + '-tab-data'", $error,
            'the failure path must address the container through the computed selector, the same '
            . 'way the success path does');
    }

    /**
     * NEGATIVE CONTROL — the two cards fire against one shared overlay but are distinct
     * containers. A failing `brand` request must not blank a successfully rendered `category`
     * card, which is exactly what writing into a shared container or emptying `.tab-content`
     * would do.
     */
    public function test_a_failure_in_one_card_cannot_blank_the_other(): void
    {
        $error = $this->errorCallback();

        $this->assertStringNotContainsString('.tab-content', $error,
            'the failure path reaches for the shared tab container, so one card failing blanks '
            . 'the other');
        $this->assertStringNotContainsString('dash-category-tab', $error);
        $this->assertStringNotContainsString('dash-brand-tab', $error);
    }

    /**
     * NEGATIVE CONTROL — THE SUCCESS PATH MUST BE UNTOUCHED. A regression here would look like
     * the fix (no stuck loader) while silently blanking the card.
     */
    public function test_the_success_path_still_renders_the_figures_and_releases_the_overlay(): void
    {
        $success = $this->successCallback();

        $this->assertStringContainsString("$('#' + type + '-tab-data').html(response.html)", $success,
            'the success path no longer injects the rendered partial');
        $this->assertStringContainsString("$('#loader').fadeOut()", $success,
            'the success path no longer releases the overlay');
    }

    /**
     * NEGATIVE CONTROL — NO GLOBAL AJAX ERROR HANDLER. `$(document).ajaxError` or
     * `$.ajaxSetup({ error: ... })` would fire for the `data-ajax-popup` modal loads whose
     * `error:` handlers deliberately do nothing, and would undermine the contract
     * `tests/Feature/Security/VariantDataPermissionTest.php` reasons out — whose premise is that
     * those callers have no error handler, so the server must answer 200 rather than 403.
     */
    public function test_no_global_ajax_error_handler_was_introduced(): void
    {
        $code = $this->code();

        $this->assertStringNotContainsString('ajaxError', $code,
            'a global ajaxError handler fires for the deliberate no-op modal loaders and breaks '
            . 'the documented 200-not-403 contract');
        $this->assertStringNotContainsString('ajaxSetup', $code,
            'a global $.ajaxSetup({error:}) has the same reach; keep the change inside '
            . 'fetchOrderDetails');
        $this->assertStringNotContainsString('ajaxComplete', $code);
    }

    /**
     * NEGATIVE CONTROL — NO DEVELOPER TEXT ON THE MERCHANT'S DASHBOARD. The house precedent feeds
     * `xhr.responseJSON.message` into a TRANSIENT toast. This alert PERSISTS in the card, and on
     * 419 Laravel's body is `{"message":"CSRF token mismatch."}` — untranslated developer text
     * parked on a merchant's screen.
     */
    public function test_no_untranslated_server_message_is_passed_through_to_the_card(): void
    {
        $error = $this->errorCallback();

        $this->assertStringNotContainsString('responseJSON', $error,
            'the persistent alert passes the server\'s raw message through, so a 419 shows '
            . '"CSRF token mismatch." to the merchant');
        $this->assertStringNotContainsString('responseText', $error);
    }

    /**
     * NEGATIVE CONTROL — THE SHARED OVERLAY GAINED NO LIVE REGION. `public/js/loader.js` fades
     * `#loader` IN on `beforeunload`, so a live region there would announce "Loading . . ." on
     * every navigation from every admin page, and the overlay is duplicated across six layouts.
     * The injected `role="alert"` is what announces the failure.
     */
    public function test_the_shared_loader_overlay_did_not_gain_a_live_region(): void
    {
        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

        $at = strpos($layout, 'id="loader"');
        $this->assertNotFalse($at, '#loader is gone from the merchant layout');

        $tag = substr($layout, $at, (int) strpos($layout, '>', $at) - $at);

        $this->assertStringNotContainsString('aria-live', $tag,
            'a live region on #loader announces on every navigation from every admin page');
        $this->assertStringNotContainsString('role=', $tag);
    }

    /**
     * NEGATIVE CONTROL — NO STYLESHEET EDIT, SO THE custom.css / rtl-custom.css TWIN PROBLEM
     * NEVER ARISES. `public/css/custom.css` serves LTR and `public/css/rtl-custom.css` serves
     * Arabic, and the layout loads one INSTEAD of the other, so a rule added to one reaches half
     * the merchants. The alert classes are already global in `public/css/store-syria-ds.css`,
     * which is loaded unconditionally.
     */
    public function test_the_alert_classes_are_already_global_and_no_directional_stylesheet_was_touched(): void
    {
        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        foreach (['.ss-alert {', '.ss-alert--danger', '.ss-alert__content', '.ss-alert__body'] as $rule) {
            $this->assertStringContainsString($rule, $ds,
                "$rule is not defined in the design system, so the injected alert is unstyled");
        }

        $this->assertStringContainsString('border-inline-start', $ds,
            'the alert accent bar must be direction-agnostic so it flips under Arabic/RTL');

        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $this->assertStringNotContainsString('ss-alert', (string) file_get_contents(base_path($sheet)),
                "$sheet gained an .ss-alert rule; the directional sheets load one INSTEAD of the "
                . 'other, so a rule in one reaches half the merchants');
        }

        $this->assertStringNotContainsString('alert-danger', $this->errorCallback(),
            'a Bootstrap alert-danger <p> is greyed to #808191 by the host dark sheets\' '
            . '!important p:not([class^="ss-"]) rule');
        $this->assertStringNotContainsString('style:', $this->errorCallback(),
            'an inline colour defeats the dark-mode token swap');
    }

    /**
     * CONTRAST, COMPUTED HERE RATHER THAN ASSERTED FROM MEMORY.
     *
     * `.ss-alert__body` inherits `.ss-alert--danger`'s `color: var(--ss-danger-fg)` on
     * `background: var(--ss-danger-bg)`, and `.ss-alert__body { color: inherit !important }` is
     * what beats the host dark sheets. Measured with the WCAG 2.x relative-luminance formula:
     *
     *   light  #8c1d17 on #fdeeee = 8.09:1
     *   dark   #f3a9a4 on #2c1413 = 9.06:1
     *
     * Both clear AA (4.5:1) and AAA (7:1) for body text, in both directions — the tokens are
     * direction-independent, only theme-dependent.
     */
    public function test_the_failure_alert_clears_wcag_aa_in_both_themes(): void
    {
        $this->assertSame(8.09, round($this->contrast('#8c1d17', '#fdeeee'), 2),
            'the measured light-mode ratio for --ss-danger-fg on --ss-danger-bg has changed');
        $this->assertSame(9.06, round($this->contrast('#f3a9a4', '#2c1413'), 2),
            'the measured dark-mode ratio for --ss-danger-fg on --ss-danger-bg has changed');

        $this->assertGreaterThan(4.5, $this->contrast('#8c1d17', '#fdeeee'));
        $this->assertGreaterThan(4.5, $this->contrast('#f3a9a4', '#2c1413'));

        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        foreach (['--ss-red-800:   #8c1d17', '--ss-red-50:    #fdeeee', '--ss-danger-fg:  #f3a9a4'] as $token) {
            $this->assertStringContainsString($token, $ds,
                "the token this test measured ($token) is no longer what the sheet declares, so "
                . 'the ratios above are stale');
        }

        $this->assertStringContainsString('.ss-alert__body        { color: inherit !important; }', $ds,
            'without color: inherit !important the host dark sheets grey the alert text to #808191');
    }

    /** WCAG 2.x relative luminance / contrast ratio. */
    private function contrast(string $fg, string $bg): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $out = 0.0;

            foreach ([[0, 0.2126], [2, 0.7152], [4, 0.0722]] as [$offset, $weight]) {
                $v = hexdec(substr($hex, $offset, 2)) / 255;
                $out += $weight * ($v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4));
            }

            return $out;
        };

        $a = $lum($fg);
        $b = $lum($bg);

        return $a > $b ? ($a + 0.05) / ($b + 0.05) : ($b + 0.05) / ($a + 0.05);
    }
}

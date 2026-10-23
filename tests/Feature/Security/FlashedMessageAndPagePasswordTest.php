<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\FlashedMessageSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * P1-SEC-22 (b) and (c) -- what a failed checkout and a public business card hand to a visitor.
 * ====================================================================================================
 *
 * (b) SIX VENDORED SERVICES end a failed checkout with `->with('error', $e)` -- the exception OBJECT --
 *     and the three layouts that render a flashed message interpolated it UNESCAPED into a JavaScript
 *     string literal. So the shopper was shown the message, the file, the line and the stack trace,
 *     with the arguments of every frame when `zend.exception_ignore_args` is off, and it was written
 *     into `sessions.payload`, which is not encrypted. The HOST app's own count is zero -- P1-SEC-22's
 *     first pass replaced seven host sites with App\Services\PaymentFailure -- so what remained was the
 *     vendored six, which cannot be edited, and the sinks, which can.
 *
 * (c) THE MARKETING PAGE PASSWORD was printed into a public page. Traced in full in
 *     `withoutPageExposedCredentials()`'s docblock: a strength-validated password a merchant chose,
 *     read out of `settings` under the name `password`, printed into a JS variable by two vendored
 *     views, on `GET /cards/{slug}` -- which is declared with no `auth` and no `PlanModuleCheck` -- and
 *     printed ONLY when `!Auth::check()`, which is why the merchant never saw it on their own card.
 */
class FlashedMessageAndPagePasswordTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const NASTY = "it's </script><script>alert(1)</script>";

    // =================================================================================================
    //  (b) THE FLASHED EXCEPTION OBJECT
    // =================================================================================================

    public function test_the_safety_middleware_runs_on_every_web_route_and_after_the_session_starts(): void
    {
        $group = $this->app['router']->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(FlashedMessageSafety::class, $group,
            'the middleware is not on the web group, so the six vendored exception flashes are unguarded');

        $this->assertGreaterThan(
            array_search(\Illuminate\Session\Middleware\StartSession::class, $group, true),
            array_search(FlashedMessageSafety::class, $group, true),
            'it must sit AFTER StartSession in the group, or the payload is written before it runs.'
        );
    }

    /** The vendored shape, driven: a handler that flashes the exception object itself. */
    public function test_a_flashed_exception_object_never_reaches_the_session_payload(): void
    {
        Log::spy();

        Route::middleware('web')->get('/p1-sec-22/flash-exception', function () {
            return redirect('/')->with('error', new \RuntimeException('SENTINEL-EXCEPTION-MESSAGE'));
        });

        $this->get('/p1-sec-22/flash-exception');

        $flashed = session('error');

        $this->assertIsString($flashed, 'an exception OBJECT is still sitting in the session');
        $this->assertSame(__(FlashedMessageSafety::SAFE_MESSAGE), $flashed);
        $this->assertStringNotContainsString('SENTINEL-EXCEPTION-MESSAGE', serialize(session()->all()));

        // the reason is not lost, it is moved
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'an exception object was flashed'))
            ->atLeast()->once();
    }

    /**
     * KEYED ON THE TYPE, NOT THE KEY NAME. The obvious shape is "sanitise `error` and `success`", and
     * this project's recorded census failures are all cases of keying on the spelling somebody looked at.
     */
    public function test_an_exception_flashed_under_any_other_key_is_replaced_too(): void
    {
        Route::middleware('web')->get('/p1-sec-22/flash-elsewhere', function () {
            return redirect('/')
                ->with('status', new \LogicException('SENTINEL-UNDER-STATUS'))
                ->with('message', new \ErrorException('SENTINEL-UNDER-MESSAGE'));
        });

        $this->get('/p1-sec-22/flash-elsewhere');

        $this->assertStringNotContainsString('SENTINEL-UNDER-STATUS', serialize(session()->all()));
        $this->assertStringNotContainsString('SENTINEL-UNDER-MESSAGE', serialize(session()->all()));
    }

    /** POSITIVE CONTROL: an ordinary flashed string, and the arrays the framework owns, are untouched. */
    public function test_an_ordinary_flash_and_the_framework_arrays_are_untouched(): void
    {
        Route::middleware('web')->post('/p1-sec-22/flash-ordinary', function () {
            return redirect('/')->with('error', 'A PERFECTLY ORDINARY MESSAGE')->withInput();
        });

        $this->post('/p1-sec-22/flash-ordinary', ['keep_me' => 'KEPT-FIXTURE']);

        $this->assertSame('A PERFECTLY ORDINARY MESSAGE', session('error'),
            'the middleware rewrote a message that was already a string');
        $this->assertSame('KEPT-FIXTURE', session('_old_input')['keep_me'] ?? null,
            '_old_input is an array and belongs to FlashedCredentialScrubber; this one must not touch it');
    }

    /**
     * THE SINKS. All six interpolations in the three layouts now go through `Js::from()`, which emits a
     * JSON literal including its own quotes -- so the surrounding single quotes are gone, and an
     * apostrophe or a closing script tag in the message cannot end the literal or the block.
     */
    public function test_no_layout_interpolates_a_flashed_message_unescaped(): void
    {
        $layouts = [
            'resources/views/layouts/app.blade.php',
            'resources/views/layouts/theme_layout.blade.php',
            'resources/views/front_end/layouts/app.blade.php',
        ];

        $jsFrom = 0;

        foreach ($layouts as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            // Blade comments are stripped before echos are compiled, so a comment cannot be the sink --
            // but it CAN satisfy a grep, which is this project's recorded trap. Strip them first.
            $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? '';

            foreach (["{!! \$message !!}", "{!! Session::get('error') !!}", "{!! Session::get('success') !!}"] as $raw) {
                $this->assertStringNotContainsString($raw, $stripped,
                    "{$relative} interpolates a flashed message unescaped into a JavaScript string literal again.");
            }

            $jsFrom += preg_match_all('/Js::from\(/', $stripped);
        }

        $this->assertSame(6, $jsFrom,
            'the six flashed-message interpolations across the three layouts must each go through Js::from(); '
            . 'a different number means one was added or removed, so look at it.');
    }

    /** And driven: a hostile string really does come out as a safe literal on a rendered page. */
    public function test_a_hostile_flashed_message_is_rendered_as_a_safe_javascript_literal(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())
            ->withSession(['error' => self::NASTY])
            ->get('/dashboard');

        $this->assertNotSame(404, $response->getStatusCode(), 'the admin layout must be reachable, or this proves nothing');

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString("'" . self::NASTY . "'", $body,
            'the message is still interpolated raw inside single quotes');
        $this->assertStringNotContainsString('</script><script>alert(1)', $body,
            'a closing script tag in a flashed message ended the block');
        // Js::from() uses JSON_HEX_TAG|JSON_HEX_APOS, so `<` becomes \u003C and `'` becomes \u0027 --
        // measured, not assumed: the literal is `'it\u0027s \u003C\/script\u003E...'`.
        $this->assertStringContainsString('\u003C\/script', $body,
            'Premise: the JSON literal really is in the page, so the two assertions above are about THIS message');
        $this->assertStringContainsString('it\u0027s', $body,
            'and the apostrophe that used to end the JavaScript string literal is escaped too');
    }

    /*
     * THE CENSUS FOR THIS PROPERTY LIVES IN ONE PLACE, and it is not here.
     * StorefrontRelayAbuseTest::test_no_handler_flashes_a_caught_exception_object already ratchets "no
     * first-party handler flashes an exception object" across app/ and LynomiaChat, and it was extended
     * under this package to strip comments and to cover the `__($e)` shape as well. A second copy here
     * would be the twin-logger mistake this row's own finding is about.
     */

    // =================================================================================================
    //  (c) THE PAGE PASSWORD PRINTED INTO A PUBLIC CARD
    // =================================================================================================

    public function test_the_settings_bag_never_carries_the_page_password(): void
    {
        [$merchant, $store] = $this->makeTenant();

        \App\Models\Setting::forceCreate([
            'name' => 'password', 'value' => 'PAGE-PASSWORD-SENTINEL',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
        \App\Models\Setting::forceCreate([
            'name' => 'stripe_secret_key', 'value' => 'SK-SENTINEL-STILL-NEEDED',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        // all three branches of getAdminAllSetting(): a named user, a named store, and neither
        $this->actingAs($merchant->fresh());
        $asUser = getAdminAllSetting($merchant->id, $store->id);
        $asStore = getAdminAllSetting(null, $store->id);

        foreach (['with a user' => $asUser, 'with a store only' => $asStore] as $how => $bag) {
            $this->assertArrayNotHasKey('password', $bag, "the page password survived {$how}");
            $this->assertStringNotContainsString('PAGE-PASSWORD-SENTINEL', serialize($bag));
        }

        /*
         * POSITIVE CONTROL, and it is the reason the strip is by EXACT NAME rather than by this project's
         * credential-name predicate: `getAdminAllSetting()` is how PaymentService reads gateway
         * credentials at thirty call sites, and guest checkout is anonymous. A predicate here would empty
         * those and break the storefront.
         */
        $this->assertSame('SK-SENTINEL-STILL-NEEDED', $asUser['stripe_secret_key'] ?? null,
            'a gateway secret a payment path needs was stripped too -- that breaks guest checkout');
    }

    /**
     * And the key is read from `settings` by nobody but the two vendored views, which is what makes the
     * strip complete rather than merely local. Keyed on the index expression.
     */
    public function test_the_only_readers_of_that_settings_key_are_the_two_vendored_views(): void
    {
        $readers = [];

        foreach ([base_path('app'), base_path('packages'), base_path('resources'), base_path('themes')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || ! in_array($entry->getExtension(), ['php'], true)) {
                    continue;
                }

                $relative = ltrim(str_replace(base_path(), '', $entry->getPathname()), '/');

                // Comment-stripped: `withoutPageExposedCredentials()`'s docblock traces the defect and
                // therefore quotes the construct, which is this project's recorded trap for a source census.
                $source = '';

                foreach (token_get_all((string) file_get_contents($entry->getPathname())) as $token) {
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    $source .= is_array($token) ? $token[1] : $token;
                }

                // the card views that PRINT it, and the Marketing helper that fetches it
                if (preg_match('/Marketing\(\s*["\']password["\']\s*\)|\$company_setting\s*\[\s*["\']password["\']\s*\]/', $source) === 1) {
                    $readers[] = $relative;
                }
            }
        }

        sort($readers);

        $this->assertSame([
            'packages/workdo/Marketing/src/resources/views/card/carddata.blade.php',
            'packages/workdo/Marketing/src/resources/views/card/theme2/index.blade.php',
            'packages/workdo/Marketing/src/resources/views/layouts/theme_script.blade.php',
            'packages/workdo/Marketing/src/resources/views/store/index.blade.php',
        ], $readers,
            'The set of files reading the Marketing page password from the settings bag changed. A new '
            . 'HOST-APP reader would not be covered by the strip being a vendored-view workaround.');
    }

    /**
     * PREMISE for the whole of (c): the card route really is anonymous. If it were behind `auth`, the
     * `!Auth::check()` guard in the view would make the print dead code and there would be nothing to fix.
     */
    public function test_premise_the_public_card_route_carries_no_auth_middleware(): void
    {
        $routes = (string) file_get_contents(base_path('packages/workdo/Marketing/src/routes/web.php'));

        $this->assertMatchesRegularExpression("/Route::middleware\(\['web'\]\)->group\(/", $routes,
            'the Marketing module no longer declares an unauthenticated route group; re-read the trace in '
            . 'withoutPageExposedCredentials() before relaxing anything.');
        $this->assertMatchesRegularExpression("/Route::get\('cards\/\{slug\}'/", $routes);
    }
}

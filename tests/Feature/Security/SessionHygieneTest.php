<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * P1-SEC-01 / issue #15 — session hygiene. Five acceptance criteria, each driven before anything
 * was changed, and each then adversarially attacked. TWO OF THE FIVE WERE MIS-FILED, and the
 * corrections are the substance of this file.
 *
 * ============================================================================================
 *  ITEM D — THE ONE THAT MATTERED. `POST /login` WAS CSRF-EXEMPT.
 * ============================================================================================
 *
 * `bootstrap/app.php`'s CSRF `except:` list carried the bare entry `'/login'`. `/login` is the
 * MERCHANT AND OPERATOR login, so that is LOGIN CSRF: an attacker can log a victim into the
 * ATTACKER'S account and then observe or capture whatever the victim does there. The leading slash
 * did not make the entry dead — `inExceptArray()` does `trim($except, '/')` first.
 *
 * MEASURED BOTH WAYS by two independent agents on a patched tree: with the entry present,
 * `POST /login` with no token succeeds; with it deleted, the full real flow still works — GET
 * /login, scrape the 40-char `_token`, POST → 302 /dashboard, GET /dashboard → 200 rendering the
 * user's name. `resources/views/auth/login.blade.php:38` already emits `@csrf`, so the token was
 * always being sent and always being ignored. Nothing depended on the exemption, and the fix is
 * one deleted line.
 *
 * THE CRITERION AS FILED ASKED THE WRONG QUESTION. It asked that the exemption "not be satisfied
 * by a prefixed or localised path". That half was ALREADY TRUE and is asserted below: `Str::is`
 * honours only `*`, so `ar/login` and `{storeSlug}/login` never matched. The defect was the bare
 * path itself.
 *
 * WHY THIS IS TESTED THROUGH THE PREDICATE AND NOT THROUGH HTTP, which is a real bound on what
 * this file can prove: `VerifyCsrfToken::handle()` short-circuits on `runningUnitTests()`
 * (vendor/…/VerifyCsrfToken.php:84, :114-116), so CSRF NEVER EXECUTES in this suite. A test that
 * drove `POST /login` without a token would pass whatever the except list said. The predicate is
 * therefore invoked directly, and the premise test below asserts the short-circuit exists so a
 * reader knows why.
 *
 * ============================================================================================
 *  ITEM A — `invalidate()` WOULD HAVE BEEN A REGRESSION
 * ============================================================================================
 *
 * The criterion asked for logout to be "symmetric with the web guard", i.e. `invalidate()`.
 * Symmetry is the wrong goal when the two guards share a cookie: `config/session.php:130` derives
 * ONE static cookie name from APP_NAME with no per-store override and `:146` sets the path to `/`,
 * so a single cookie holds `login_web_*` and `login_customers_*` at once — and a merchant
 * previewing their own storefront is exactly the person this logout button is rendered to
 * (`front_end/common/account-tab.blade.php:75`). MEASURED: `invalidate()` there destroys that
 * merchant's ADMIN session. The route carries no auth middleware, so a guest POST reaches it too.
 *
 * What was actually wrong: `SessionGuard::logout()` removes `login_customers_*` and nothing else.
 * Measured on `DatabaseSessionHandler` — the production driver — the old `sessions` row SURVIVED
 * and still carried `request_data = {"email": …, "address": …}`, the whole checkout POST body
 * (`PaymentService:653` stores `$request->except('attachment','payment_receipt')`), and the CSRF
 * token was not rotated. Not an authentication bypass: the login key really is removed.
 *
 * So the fix is `regenerate(true)` — migrate-with-destroy plus a token rotation — and it is GATED,
 * because a GUEST cart row is keyed to `session()->getId()` (`Cart.php:154`) and rotating the id
 * for a caller who never had a shopper session orphans that caller's basket (measured: one row
 * became two). A logged-in shopper has nothing to lose; their row carries
 * `cookie_session_id = NULL`.
 *
 * ============================================================================================
 *  ITEM B — THE CRITERION WAS ALREADY SATISFIED, AND THE REAL GAP IS ONE METHOD AWAY
 * ============================================================================================
 *
 * "Session id regenerated on customer login" is ALREADY TRUE and needed no change:
 * `SessionGuard::login()` → `updateSession()` → `migrate(true)`, which the framework does for
 * free. Asserted below, because "we added a regenerate to fix fixation" would have been a
 * redundant second migrate.
 *
 * The privilege boundary that rotated NOTHING is `verifyPasswordOtp()`. The three flags it writes
 * are, on their own, a standing authorisation to change that customer's password — the reset
 * handler reads nothing else, not even `password_otp`. An attacker who could plant the pre-OTP
 * session id could replay it after the victim verified and take the account over.
 *
 * SEQUENCING, which is the part that matters: today that takeover is held shut only by a BROKEN
 * COLUMN. `customers.password_otp` is INTEGER while the web reset writes a 60-char bcrypt hash,
 * and the prepared-and-unapplied migration in `database/migrations/_sec13_pending/` WIDENS it.
 * Applying SEC-13 without this rotation arms the takeover.
 *
 * ============================================================================================
 *  ITEM C — `lax` IS CORRECT AND NOT SAFE HERE. OWNER DECISION.
 * ============================================================================================
 *
 * `same_site` defaults to `null`, and the stock Laravel comment above it claimed "By default, we
 * will set this value to 'lax'" — false of this application, and removed.
 *
 * Setting `'lax'` explicitly is the obvious hardening and it breaks money. A top-level cross-site
 * POST does not carry a Lax cookie, and NINE CSRF-exempt routes are POSTed to by third-party
 * gateways and then read their state out of the session — six take money before activating a
 * plan, and one writes a paid storefront order. Measured by driving each with no session cookie:
 * seven answered `302 → /login` with `AuthenticationException`, and
 * `{storeSlug?}/get-payment-easebuzz` answered the checkout refusal "your payment has not been
 * applied to an order", because P01-COMMERCE-SEC-01 deliberately made the session the sole source
 * of checkout state. The list is in docs/security/P01-SESSION-HYGIENE.md.
 *
 * So the VALUE is unchanged and pinned here, with the reason, so a future change is deliberate.
 *
 * ============================================================================================
 *  ITEM E — RETIRED. The MFA ordering is already correct.
 * ============================================================================================
 *
 * `GoogleAuthentication` and `Duo2FA` are the only MFA mechanisms and both dispatch at
 * `AuthenticatedSessionController.php:120-125`, after `$request->authenticate()` (:76) and
 * `$request->session()->regenerate()` (:77). Measured by comparing the session id at the `Login`
 * event with the id the response carries: they differ, so regeneration follows credential
 * verification and the MFA redirect follows both. Asserted below so the retirement is pinned
 * rather than asserted in prose. A separate finding the review turned up — account existence and
 * state disclosed BEFORE `authenticate()` at `:68-74` — is its own item, not this one.
 */
class SessionHygieneTest extends SecurityTestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM D — the CSRF exemption
     * ---------------------------------------------------------------------------------------- */

    /**
     * THE CONFIGURED EXCEPT LIST, read from the accessor that actually holds it.
     *
     * `$middleware->validateCsrfTokens(except: [...])` calls `ValidateCsrfToken::except()`, which
     * appends to a STATIC `$neverVerify` (VerifyCsrfToken.php:49, :224-228) -- NOT to the instance
     * property `$except` (`:42`), which stays `[]` forever. `getExcludedPaths()` (`:123-126`) is
     * what merges the two.
     *
     * MY FIRST VERSION OF THIS FILE REFLECTED ON `$except` AND GOT `[]`, SO THREE ASSERTIONS
     * PASSED VACUOUSLY -- `assertNotContains('/login', [])` is trivially true. The premise test
     * below is what caught it, and it is the reason it exists.
     *
     * @return list<string>
     */
    private function configuredExceptList(): array
    {
        return $this->app->make(VerifyCsrfToken::class)->getExcludedPaths();
    }

    /**
     * The predicate itself, seeded with an explicit list so its SEMANTICS can be driven even
     * though the configured static is flushed.
     *
     * @param  list<string>  $except
     */
    private function isExemptUnder(array $except, string $path): bool
    {
        $middleware = $this->app->make(VerifyCsrfToken::class);

        $class = new \ReflectionClass($middleware);
        $property = $class->getProperty('except');
        $property->setAccessible(true);
        $property->setValue($middleware, $except);

        $predicate = $class->getMethod('inExceptArray');
        $predicate->setAccessible(true);

        return (bool) $predicate->invoke($middleware, Request::create($path, 'POST'));
    }

    /**
     * THE BOUND ON THIS WHOLE SECTION, asserted rather than left for a reader to discover: CSRF is
     * short-circuited in this suite, so nothing here can be proven through an HTTP probe.
     */
    public function test_premise_csrf_never_executes_in_this_suite_so_the_predicate_is_driven_directly(): void
    {
        $source = file_get_contents(
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/VerifyCsrfToken.php')
        );

        $this->assertStringContainsString('$this->runningUnitTests() ||', $source,
            'VerifyCsrfToken no longer short-circuits under tests. If that is true, these '
            . 'assertions should be rewritten as real HTTP probes, which would be stronger.');

        // THE TRAP THAT MADE THREE ASSERTIONS VACUOUS ON THE FIRST RUN, pinned: the INSTANCE
        // property is empty and the accessor is not. Read the wrong one and every "not in the
        // list" assertion is trivially true.
        $class = new \ReflectionClass(VerifyCsrfToken::class);
        $property = $class->getProperty('except');
        $property->setAccessible(true);

        $this->assertSame([], $property->getValue($this->app->make(VerifyCsrfToken::class)),
            'The instance property now carries entries too. Harmless, but the comment on '
            . 'configuredExceptList() should be corrected.');

        $this->assertNotEmpty($this->configuredExceptList(),
            'getExcludedPaths() is empty, so every assertion below would be answered by an '
            . 'empty list rather than by the real configuration.');
        $this->assertContains('/duologin', $this->configuredExceptList(),
            'A known-good entry must be present, or the list is not the configured one.');
    }

    /** THE HEADLINE. */
    public function test_the_merchant_login_path_is_no_longer_csrf_exempt(): void
    {
        $except = $this->configuredExceptList();

        $this->assertNotContains('/login', $except,
            "'/login' is back in the CSRF except list. That is login CSRF on the merchant and "
            . 'operator login: an attacker can log a victim into the attacker\'s account.');
        $this->assertNotContains('login', $except);

        $this->assertFalse($this->isExemptUnder($except, '/login'),
            'POST /login must be CSRF-protected under the configured list.');
    }

    /**
     * THE CONTROL. The deletion must not have widened anything: every payment callback that was
     * exempt is still exempt. Without this, the assertion above is satisfied by an empty list.
     */
    public function test_control_the_payment_callbacks_are_still_exempt(): void
    {
        $except = $this->configuredExceptList();

        foreach ([
            '/plan-get-phonepe-status',
            '/plan-easebuzz-payment-notify',
            '/duologin',
            '/some-store/get-payment-easebuzz',
            '/delivery-syria/status',
        ] as $path) {
            $this->assertTrue($this->isExemptUnder($except, $path),
                "{$path} must still be CSRF-exempt -- it is a third-party callback. If this is "
                . 'false the deletion took more than one line with it.');
        }
    }

    /**
     * The half of the criterion that was ALREADY TRUE, pinned so nobody "fixes" it by widening the
     * pattern with a leading wildcard, which would exempt every storefront and localised login too.
     */
    public function test_a_prefixed_or_localised_login_path_was_never_exempt(): void
    {
        // Seeded with the entry AS IT USED TO BE, so this drives the predicate's semantics rather
        // than the absence of the entry -- it would pass trivially against the current list.
        foreach (['/ar/login', '/en/login', '/some-store/login', '/a/b/login'] as $path) {
            $this->assertFalse($this->isExemptUnder(['/login'], $path),
                "{$path} must not be CSRF-exempt. `Str::is` honours only `*`, so a bare pattern "
                . 'never matched a prefixed path -- do not add a wildcard to make it.');
        }
    }

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM A — customer logout
     * ---------------------------------------------------------------------------------------- */

    /** @return array{0: Store, 1: Customer} */
    private function shopper(): array
    {
        [$owner, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Shop',
            'last_name' => 'Per',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => Hash::make('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $owner->id,
            'billable_type' => \App\Models\User::class,
        ]);

        return [$store, $customer];
    }

    /**
     * ITEM A IS ASSERTED AT THE SOURCE AND BY DIRECT INVOCATION, NOT THROUGH `$this->post()`, AND
     * THE REASON IS A MEASUREMENT RATHER THAN A PREFERENCE.
     *
     * My first version drove the real route and asserted the session id changed. It passed. Then
     * the removal check showed it ALSO passed with the rotation deleted entirely, and a probe
     * showed why: `phpunit.xml` runs the `array` session driver, which persists nothing between
     * requests, so `StartSession` mints a NEW ID ON EVERY REQUEST whatever the handler does.
     * Measured, guest and shopper alike, with a prior request to establish the cookie:
     *
     *     GUEST   : before=9wrPZ7Cc after=6UaPDzAk changed=YES
     *     SHOPPER : before=i1GVX5Fj after=towxk6Jr changed=YES
     *
     * So an HTTP-driven session-id assertion in this suite is vacuous BY CONSTRUCTION -- the same
     * engine-hiding class as SQLite masking a MySQL defect, one layer up. Three of my tests were
     * deleted rather than relaxed.
     */
    public function test_the_logout_rotates_the_session_and_is_gated_on_having_had_one(): void
    {
        $logout = $this->methodSource('logout');

        $this->assertStringContainsString('$session->regenerate(true);', $logout,
            'The logout no longer rotates the session. As shipped, `SessionGuard::logout()` '
            . 'removes only `login_customers_*`: measured on the production DatabaseSessionHandler, '
            . 'the old `sessions` row survived still carrying the checkout POST body.');

        $this->assertStringNotContainsString('->invalidate()', $logout,
            'invalidate() is back. It DESTROYS a merchant\'s admin session, because '
            . 'config/session.php:130 derives one static cookie name and :146 sets the path to '
            . '`/`, so one cookie holds both guards\' keys -- and this route has no auth '
            . 'middleware, so a guest POST reaches it. Symmetry with the web guard is the wrong '
            . 'goal when the two guards share a cookie.');
        $this->assertStringNotContainsString('->flush()', $logout);

        // THE GATE, and its shape matters: the test must be on the SESSION KEY, not on
        // `Auth::guard('customers')->check()`, which does a provider lookup and is subject to
        // SessionGuard's `$loggedOut` memoisation.
        $this->assertStringContainsString(
            "\$hadCustomerSession = \$session->has(Auth::guard('customers')->getName());",
            $logout,
            'The rotation must be gated on whether this cookie really held a shopper session. A '
            . "GUEST cart row is keyed to session()->getId() (Cart.php:154), so rotating a "
            . "guest's id orphans their basket -- measured: one row became two.");
        $this->assertStringContainsString('if ($hadCustomerSession) {', $logout);
        $this->assertStringNotContainsString("guard('customers')->check()", $logout,
            'Do not gate on check(): it made a probe report "never rotates" when it always did.');

        // ORDER: the key must be read BEFORE logout() removes it, or the gate is always false.
        $readAt = strpos($logout, '$hadCustomerSession = ');
        $logoutAt = strpos($logout, "Auth::guard('customers')->logout();");
        $rotateAt = strpos($logout, '$session->regenerate(true);');

        $this->assertIsInt($readAt);
        $this->assertIsInt($logoutAt);
        $this->assertIsInt($rotateAt);
        $this->assertLessThan($logoutAt, $readAt,
            'The session key is read AFTER logout() removed it, so the gate is always false and '
            . 'the rotation never runs. This is the one ordering that makes the fix inert.');
        $this->assertLessThan($rotateAt, $logoutAt,
            'The rotation must follow the logout.');
    }

    /**
     * THE PRIMITIVES THE FIX RELIES ON, driven directly -- the half an HTTP probe cannot reach.
     *
     * These are assertions about Laravel, not about this application, and they are here because
     * the fix is only correct if they hold: `logout()` must NOT rotate (otherwise the fix is
     * redundant) and `regenerate(true)` must rotate AND preserve the data (otherwise it is
     * `invalidate()` by another name and would take the merchant's admin session with it).
     */
    public function test_the_session_primitives_behave_as_the_fix_assumes(): void
    {
        [, $customer] = $this->shopper();

        $session = $this->app['session']->driver();
        $session->start();

        \Auth::guard('customers')->login($customer);
        $session->put('request_data', ['address' => '1 Real St']);

        $key = \Auth::guard('customers')->getName();
        $this->assertTrue($session->has($key), 'login() must write the guard key.');

        $before = $session->getId();
        $tokenBefore = $session->token();

        \Auth::guard('customers')->logout();

        $this->assertFalse($session->has($key), 'logout() must remove the guard key.');
        $this->assertSame($before, $session->getId(),
            'SessionGuard::logout() now rotates the id by itself, which would make this fix '
            . 'redundant -- re-measure before keeping it.');
        $this->assertSame($tokenBefore, $session->token(),
            'logout() now rotates the CSRF token by itself.');

        $session->regenerate(true);

        $this->assertNotSame($before, $session->getId(),
            'regenerate(true) must change the id -- that is the whole point.');
        $this->assertNotSame($tokenBefore, $session->token(),
            'regenerate(true) must rotate the CSRF token.');
        $this->assertSame(['address' => '1 Real St'], $session->get('request_data'),
            'regenerate(true) must PRESERVE the data under the new id. If it drops it, it is '
            . 'behaving like invalidate() and would destroy a merchant\'s admin session too.');
    }

    public function test_the_logout_route_still_refuses_an_unknown_store(): void
    {
        $response = $this->post('/no-such-store-' . uniqid() . '/customer-logout');

        $response->assertRedirect();
        $this->assertNotSame(500, $response->getStatusCode(),
            'The unknown-store branch must still answer before the session code runs.');
    }

    /**
     * One method's CODE out of CustomerLoginController, with comments stripped.
     *
     * STRIPPING IS NOT COSMETIC. The fix's own docblock explains why `invalidate()` is wrong and
     * why the gate does not use `check()` -- so a `assertStringNotContainsString` against the raw
     * text matches the EXPLANATION and fails on correct code. That happened: the `check()`
     * assertion went red against a tree where the gate was correct. An assertion that can be
     * tripped by a comment is worse than no assertion.
     */
    private function methodSource(string $method): string
    {
        $source = file_get_contents(base_path(
            'app/Http/Controllers/Customer/Auth/CustomerLoginController.php'
        ));

        $at = strpos($source, "function {$method}(");
        $this->assertIsInt($at, "{$method}() was not found.");

        $next = strpos($source, "\n    public function ", $at + 10);
        $body = $next === false ? substr($source, $at) : substr($source, $at, $next - $at);

        // Drop `//` and `#` line comments and `/* ... */` blocks, leaving executable text.
        $body = preg_replace('#/\*.*?\*/#s', '', $body);
        $body = preg_replace('#^\s*(//|\#).*$#m', '', $body);

        return $body;
    }

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM B — the login boundary was already correct; the OTP boundary was not
     * ---------------------------------------------------------------------------------------- */

    /**
     * ALREADY CORRECT, and asserted so nobody adds a redundant second migrate "to fix fixation".
     */
    public function test_the_framework_regenerates_the_session_on_a_customer_login(): void
    {
        $source = file_get_contents(
            base_path('vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php')
        );

        $this->assertStringContainsString('$this->session->migrate(true);', $source,
            'SessionGuard::updateSession() no longer migrates with destroy. If that is true, '
            . 'every login path in this application needs an explicit rotation.');

        [, $customer] = $this->shopper();

        session()->put('PRE_LOGIN', 'x');
        session()->save();
        $before = session()->getId();

        \Auth::guard('customers')->login($customer);

        $this->assertNotSame($before, session()->getId(),
            'The framework did not rotate the id at the login privilege boundary.');
    }

    public function test_the_otp_verify_boundary_rotates_the_session(): void
    {
        $source = file_get_contents(base_path(
            'app/Http/Controllers/Customer/Auth/CustomerLoginController.php'
        ));

        $verify = substr($source, strpos($source, 'function verifyPasswordOtp'));
        $verify = substr($verify, 0, strpos($verify, "\n    public function "));

        $this->assertStringContainsString('session()->regenerate(true);', $verify,
            'verifyPasswordOtp() no longer rotates the session. The three flags it writes are a '
            . "standing authorisation to change that customer's password, so a planted pre-OTP "
            . 'session id would survive the victim verifying.');

        $flagsAt = strpos($verify, "'password_otp_verified' => true");
        $rotateAt = strpos($verify, 'session()->regenerate(true);');

        $this->assertIsInt($flagsAt);
        $this->assertIsInt($rotateAt);
        $this->assertLessThan($flagsAt, $rotateAt,
            'The rotation must come BEFORE the authorisation flags are written, or the flags land '
            . 'in the session that is about to be replaced.');
    }

    /**
     * THE SEQUENCING RECORD. The takeover is held shut today only by a broken column, and the
     * prepared migration widens it. Pinned so the two cannot be separated by accident.
     */
    public function test_the_sec13_migration_is_still_unapplied_and_records_the_dependency(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('customers', 'password_otp')
            && DB::getSchemaBuilder()->getColumnType('customers', 'password_otp') === 'string',
            'customers.password_otp is now a string column. SEC-13 has been applied, so the '
            . 'OTP rotation above is load-bearing rather than defence in depth -- which is fine, '
            . 'and is exactly why it landed first.'
        );

        $readme = base_path('database/migrations/_sec13_pending/README.md');
        $this->assertFileExists($readme);
        $this->assertStringContainsString('P1-SEC-01', file_get_contents($readme),
            'The pending-migration README must record that applying it arms the OTP session '
            . 'takeover unless the rotation is in place.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM C — the same_site posture, pinned rather than changed
     * ---------------------------------------------------------------------------------------- */

    public function test_same_site_is_deliberately_null_and_the_misleading_comment_is_gone(): void
    {
        $this->assertNull(config('session.same_site'),
            'session.same_site has been given a value. If that value is `lax`, read '
            . 'docs/security/P01-SESSION-HYGIENE.md first: nine CSRF-exempt gateway callbacks '
            . 'read their state out of the session, six of them before activating a paid plan, '
            . 'and a top-level cross-site POST does not carry a Lax cookie.');

        $source = file_get_contents(base_path('config/session.php'));

        $this->assertStringNotContainsString('will set this value to "lax" to permit', $source,
            'The stock Laravel comment claiming a `lax` default is back. It is false of this '
            . 'application -- the default below it is null.');
        $this->assertStringContainsString('OWNER DECISION', $source,
            'The reason the value is null must stay next to the value.');
    }

    /* ---------------------------------------------------------------------------------------- *
     *  ITEM E — retired, and pinned so the retirement is evidenced
     * ---------------------------------------------------------------------------------------- */

    public function test_mfa_is_dispatched_after_authentication_and_regeneration(): void
    {
        $source = file_get_contents(base_path(
            'app/Http/Controllers/Auth/AuthenticatedSessionController.php'
        ));

        $authAt = strpos($source, '$request->authenticate()');
        $regenAt = strpos($source, '$request->session()->regenerate()');

        $this->assertIsInt($authAt, 'authenticate() was not found.');
        $this->assertIsInt($regenAt, 'session()->regenerate() was not found.');
        $this->assertLessThan($regenAt, $authAt,
            'Credential verification must precede session regeneration.');

        // The MFA redirects both sit after both, which is the criterion.
        foreach (['2fa-verify', 'duo'] as $needle) {
            $at = stripos($source, $needle);
            if ($at === false) {
                continue;
            }
            $this->assertGreaterThan($regenAt, $at,
                "An MFA dispatch ({$needle}) appears before the session regeneration.");
        }
    }
}

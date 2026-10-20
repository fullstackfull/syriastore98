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
 * so a single cookie holds `login_web_*` and `login_customers_*` at once. MEASURED:
 * `invalidate()` there destroys the merchant's ADMIN session, and the route carries no auth
 * middleware, so a guest or a merchant holding only a `web` session POSTs to it directly. (An
 * earlier draft said the logout BUTTON is rendered to a merchant previewing their storefront. It
 * is not: the form at `front_end/common/account-tab.blade.php:75` sits inside an `@if` at `:73`
 * requiring `auth('customers')->user()`. The route is the surface, not the button.)
 *
 * The fix is not data minimisation either: `regenerate(true)` MIGRATES `request_data` to the new
 * id rather than dropping it. What it takes away is addressability — the cookie the browser holds
 * points at a destroyed record — plus the token. Accepted cost, disclosed: rotating `_token` 419s
 * any admin form the same cookie's owner had already rendered in another tab.
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
     * The predicate itself, driven against the SEEDED LIST AND NOTHING ELSE.
     *
     * THE STATIC IS CLEARED FOR THE DURATION, AND THAT IS A CORRECTION. My first version set only
     * the instance property and claimed the seeded list was what the predicate saw. It is not:
     * `getExcludedPaths()` is `array_merge($this->except, static::$neverVerify)`
     * (VerifyCsrfToken.php:123-126), so seeding is a UNION with the live configuration, not a
     * replacement. The prefixed-path test below reads as "a bare pattern never matched `/ar/login`"
     * while actually asserting "no configured entry matches `/ar/login` either" -- a true but
     * different claim, and one that would go red for the wrong reason the day an unrelated
     * wildcard callback was added.
     *
     * So `$neverVerify` is emptied, the predicate is invoked, and the real value is restored in a
     * `finally` -- it is static and shared, so a leak would corrupt every later test in the
     * process.
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

        $never = new \ReflectionProperty(VerifyCsrfToken::class, 'neverVerify');
        $never->setAccessible(true);
        $configured = $never->getValue();

        $predicate = $class->getMethod('inExceptArray');
        $predicate->setAccessible(true);

        try {
            $never->setValue(null, []);

            return (bool) $predicate->invoke($middleware, Request::create($path, 'POST'));
        } finally {
            $never->setValue(null, $configured);
        }
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

        // AND THE SECOND HALF OF THE SAME TRAP: seeding the instance property does not REPLACE
        // the configured list, it is UNIONED WITH IT. So `isExemptUnder()` has to empty the
        // static to drive a seeded list in isolation, and this pins the merge that makes that
        // necessary. If the framework ever stops merging, the clearing can go.
        $source = file_get_contents(
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/VerifyCsrfToken.php')
        );

        $this->assertStringContainsString(
            'return array_merge($this->except, static::$neverVerify);',
            $source,
            'getExcludedPaths() no longer merges the instance property with the static. Re-read '
            . 'isExemptUnder(): it clears the static precisely because of this merge.'
        );

        // The clearing must not leak. Driving the predicate must leave the configured list intact.
        $this->isExemptUnder(['/seeded-only'], '/seeded-only');
        $this->assertContains('/duologin', $this->configuredExceptList(),
            'isExemptUnder() leaked -- it emptied the static and did not restore it, so every '
            . 'later test in this process now sees an empty except list.');
    }

    /**
     * THE PREMISE FOR `methodSource()`, which was returning four methods at once.
     */
    public function test_premise_the_method_window_stops_at_the_method(): void
    {
        $verify = $this->methodSource('verifyPasswordOtp');

        $this->assertStringContainsString('password_otp_verified_customer_id', $verify,
            'The window does not reach the end of verifyPasswordOtp().');

        // The three methods that used to be swept in with it, because they are declared at
        // column 0 and the old needle wanted four spaces.
        foreach ([
            'function resetPasswordOtpForm',
            'function resetPasswordWithOtp',
            'function normalizePhone',
            'function showLoginForm',
        ] as $beyond) {
            $this->assertStringNotContainsString($beyond, $verify,
                "methodSource('verifyPasswordOtp') ran on into {$beyond}. Every "
                . 'assertStringNotContainsString against this window is then weaker than it '
                . 'reads, and every ordering assertion can be answered by another method.');
        }

        // And the comment stripping, which the OTP test used to bypass entirely.
        $this->assertStringNotContainsString('P1-SEC-01 item B', $verify,
            'methodSource() is no longer stripping comments, so an assertion can be satisfied '
            . 'or tripped by prose. That has happened once already.');
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

    /* ---------------------------------------------------------------------------------------- *
     *  THE TWO FIXES, EXECUTED
     *
     *  THE REVIEW'S TOP FINDING, AND IT WAS RIGHT: nothing above runs either changed line. The
     *  source assertions read the file, the primitives test drives Laravel rather than this
     *  application, and the only HTTP test posts to an UNKNOWN STORE -- so it takes
     *  `logout()`'s early return and would pass with the whole fix deleted.
     *
     *  WHAT I GOT WRONG, narrower than the conclusion I drew from it: my array-driver measurement
     *  bars HTTP session-id assertions, because `StartSession` mints a new id per request whatever
     *  the handler does. It does NOT bar DIRECT INVOCATION, which runs in one process against one
     *  `Store` instance and is fully deterministic -- the id only changes if the handler changes
     *  it. So the handler is called directly, with the request bound so `request()->session()`
     *  resolves, and these are the tests that bite.
     * ---------------------------------------------------------------------------------------- */

    /**
     * Bind a started session and a matching request, and return the session.
     *
     * ONE INSTANCE ON BOTH SIDES is the whole point: `SessionGuard` is constructed with
     * `$app['session.store']` (AuthManager::createSessionDriver), which is the default driver,
     * and `logout()` reads `request()->session()`. Hand the request a DIFFERENT store and the
     * gate reads a session the guard never wrote to, and the test proves nothing.
     */
    private function bindRequest(string $uri, string $method = 'POST', array $parameters = []): \Illuminate\Session\Store
    {
        $session = $this->app['session']->driver();
        $session->start();

        $request = Request::create($uri, $method, $parameters);
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return $session;
    }

    /** ITEM A, EXECUTED: a shopper's logout rotates the id and the token, and keeps the data. */
    public function test_invoking_the_logout_rotates_a_shopper_session(): void
    {
        [$store, $customer] = $this->shopper();

        $session = $this->bindRequest('/' . $store->slug . '/customer-logout');

        \Auth::guard('customers')->login($customer);
        $session->put('request_data', ['email' => 'victim@example.test', 'address' => '1 Real St']);

        // AFTER the login, because `SessionGuard::login()` migrates for free -- capturing before
        // it would let the framework's own rotation answer this test.
        $before = $session->getId();
        $tokenBefore = $session->token();
        $guardKey = \Auth::guard('customers')->getName();

        $response = (new \App\Http\Controllers\Customer\Auth\CustomerLoginController)
            ->logout($store->slug);

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response,
            'The logout no longer redirects, so this test may not have reached the fix at all.');

        $this->assertNotSame($before, $session->getId(),
            'THE FIX IS INERT. `logout()` did not rotate the session id for a caller who held a '
            . 'shopper session. As shipped, `SessionGuard::logout()` removes only '
            . '`login_customers_*`: on the production DatabaseSessionHandler the old `sessions` '
            . 'row survives, still addressed by the cookie the browser holds, still carrying the '
            . 'checkout POST body.');

        $this->assertNotSame($tokenBefore, $session->token(),
            'The CSRF token was not rotated. `regenerate(true)` does both; `migrate(true)` alone '
            . 'would not, and that is why the fix is not migrate().');

        $this->assertFalse($session->has($guardKey),
            'The shopper guard key survived the logout, which would be an authentication '
            . 'failure rather than a hygiene one.');

        $this->assertSame(
            ['email' => 'victim@example.test', 'address' => '1 Real St'],
            $session->get('request_data'),
            'The data did not survive the rotation, so this is behaving like `invalidate()` -- '
            . 'which destroys a merchant\'s admin session, because one static cookie name at '
            . 'config/session.php:130 and path `/` at :146 means one cookie holds both guards.'
        );
    }

    /**
     * ITEM A'S GATE, EXECUTED -- and this is the half that makes the fix safe rather than the half
     * that makes it work. A guest cart row is keyed to `session()->getId()` (`Cart.php:154`), so
     * rotating a caller who never had a shopper session orphans their basket.
     */
    public function test_invoking_the_logout_does_not_rotate_a_guest_session(): void
    {
        [$store] = $this->shopper();

        $session = $this->bindRequest('/' . $store->slug . '/customer-logout');

        // A guest with a basket: no shopper guard key, but a session id their cart row is keyed to.
        $session->put('cart_marker', 'basket');
        $before = $session->getId();

        (new \App\Http\Controllers\Customer\Auth\CustomerLoginController)->logout($store->slug);

        $this->assertSame($before, $session->getId(),
            'THE GATE IS GONE. A guest POST to this route -- it carries no auth middleware, so a '
            . 'guest and a merchant both reach it -- just had their session id rotated, which '
            . 'orphans the `carts` row keyed to the old id. Measured when the gate was absent: '
            . 'one row became two.');
        $this->assertSame('basket', $session->get('cart_marker'));
    }

    /**
     * ITEM A'S OTHER GATE, EXECUTED: the unknown-store branch must answer before any session work.
     * The HTTP test above asserts the status; this asserts that nothing was rotated.
     */
    public function test_invoking_the_logout_on_an_unknown_store_touches_no_session(): void
    {
        $session = $this->bindRequest('/no-such-store/customer-logout');

        $before = $session->getId();

        (new \App\Http\Controllers\Customer\Auth\CustomerLoginController)
            ->logout('no-such-store-' . uniqid());

        $this->assertSame($before, $session->getId());
    }

    /**
     * ITEM B, EXECUTED, down the REAL SUCCESS PATH of `verifyPasswordOtp()`.
     *
     * THIS PATH IS REACHABLE IN THIS SUITE ONLY BECAUSE OF SQLITE, AND THAT HAS TO BE SAID OUT
     * LOUD. `customers.password_otp` is an INTEGER column; on MySQL a 60-char bcrypt hash cannot
     * round-trip through it, which is P01-SEC-13 and is why this whole flow is inert in
     * production. SQLite's type affinity stores the unconvertible string AS TEXT, so `Hash::check`
     * succeeds here and the handler runs to completion. That is engine-hiding in the direction
     * that HELPS the test: it lets the rotation be driven today, before SEC-13 is applied. The
     * dependency runs the other way -- applying SEC-13 without this rotation arms the takeover --
     * and `test_the_sec13_migration_is_still_unapplied_and_records_the_dependency` pins it.
     */
    public function test_invoking_the_otp_verify_rotates_the_session_before_writing_the_flags(): void
    {
        [$store, $customer] = $this->shopper();

        $customer->password_otp = Hash::make('123456');
        $customer->password_otp_datetime = now();
        $customer->save();

        $this->assertTrue(
            Hash::check('123456', $customer->fresh()->password_otp),
            'The OTP hash did not round-trip through customers.password_otp, so the success path '
            . 'below is unreachable and this test would prove nothing. On MySQL this is expected '
            . '(P01-SEC-13); under SQLite it means the column changed.'
        );

        $session = $this->bindRequest(
            '/' . $store->slug . '/forgot-password/otp/verify',
            'POST',
            ['otp' => '123456']
        );

        $session->put([
            'password_otp_customer_id' => $customer->id,
            'password_otp_store_id' => $store->id,
            'password_otp_store_slug' => $store->slug,
            'password_otp_attempts_' . $customer->id => 2,
        ]);

        $planted = $session->getId();
        $tokenBefore = $session->token();

        $response = (new \App\Http\Controllers\Customer\Auth\CustomerLoginController)
            ->verifyPasswordOtp($this->app['request'], $store->slug);

        // THE ORACLE FIRST. Without it every assertion below is satisfied by an early return --
        // a wrong store, an expired code, a missing binding -- and the test passes vacuously.
        $this->assertTrue($session->get('password_otp_verified') === true,
            'The success path was NOT reached: `password_otp_verified` was not written, so the '
            . 'handler took one of its early returns and nothing below is being tested. '
            . 'Redirect was: ' . (method_exists($response, 'getTargetUrl') ? $response->getTargetUrl() : '?'));
        $this->assertSame($customer->id, $session->get('password_otp_verified_customer_id'));
        $this->assertSame($store->id, $session->get('password_otp_verified_store_id'));

        // THE FIX.
        $this->assertNotSame($planted, $session->getId(),
            'THE FIX IS INERT. The OTP verify boundary did not rotate the session id. The three '
            . 'flags just asserted are, on their own, a standing authorisation to change this '
            . "customer's password -- the reset handler reads nothing else, not even "
            . '`password_otp`. An attacker who planted the pre-OTP id replays it after the victim '
            . 'verifies and takes the account over.');

        $this->assertNotSame($tokenBefore, $session->token(),
            'The CSRF token was not rotated at the privilege boundary, which is the half that '
            . 'matches the house pattern at AuthenticatedSessionController.php:77.');

        // AND THE ORDER, driven rather than read off the source: the flags must land in the NEW
        // session. `regenerate(true)` migrates the data, so a rotation placed AFTER the writes
        // would still carry them -- what it would NOT do is leave them unreachable by the
        // planted id, which is the whole control. This asserts the surviving state is addressed
        // by the new id and the attempt counter stayed cleared.
        $this->assertNull($session->get('password_otp_attempts_' . $customer->id),
            'The attempt counter was not cleared, so a rotation was placed before the forget() '
            . 'and the counter migrated with the data.');
    }

    /**
     * One method's CODE out of CustomerLoginController, with comments stripped.
     *
     * STRIPPING IS NOT COSMETIC. The fix's own docblock explains why `invalidate()` is wrong and
     * why the gate does not use `check()` -- so a `assertStringNotContainsString` against the raw
     * text matches the EXPLANATION and fails on correct code. That happened: the `check()`
     * assertion went red against a tree where the gate was correct. An assertion that can be
     * tripped by a comment is worse than no assertion.
     *
     * THE END-OF-METHOD NEEDLE IS A REGEX AND NOT `"\n    public function "`, WHICH IS A SECOND
     * DEFECT THE REVIEW FOUND. SIX methods in this controller are declared at COLUMN 0 --
     * `sendPasswordOtp`, `passwordOtpForm`, `verifyPasswordOtp`, `resetPasswordOtpForm`,
     * `resetPasswordWithOtp` and `normalizePhone` -- so a four-space needle skipped straight past
     * them. Asking for `verifyPasswordOtp` returned a window spanning FOUR methods, running on to
     * `showLoginForm`, which makes every `assertStringNotContainsString` in it weaker than it
     * reads and every ordering assertion answerable by a different method's code.
     *
     * NAMES AND NOT LINE NUMBERS, deliberately: four of the six numbers I first wrote here had
     * drifted by the next edit to that file, which is the same citation rot the review found in
     * three other places. `test_premise_the_method_window_stops_at_the_method` is the authority.
     */
    private function methodSource(string $method): string
    {
        $source = file_get_contents(base_path(
            'app/Http/Controllers/Customer/Auth/CustomerLoginController.php'
        ));

        $at = strpos($source, "function {$method}(");
        $this->assertIsInt($at, "{$method}() was not found.");

        // Any visibility, any indentation, at the start of a line.
        $found = preg_match(
            '/\n[ \t]*(?:public|protected|private)(?:\s+static)?\s+function\s/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE,
            $at + 10
        );

        $body = $found === 1 ? substr($source, $at, $m[0][1] - $at) : substr($source, $at);

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

    /**
     * THE WINDOW THIS READS IS ITSELF A FINDING. My first version sliced the source by hand with
     * `strpos($source, "\n    public function ")`, which bypassed `methodSource()`'s comment
     * stripping AND landed on `showLoginForm` at `:636` -- four methods past the end of
     * `verifyPasswordOtp`. The ordering assertion below was therefore being answered against
     * `resetPasswordWithOtp`'s code as readily as against this method's. It goes through
     * `methodSource()` now, and `test_premise_the_method_window_stops_at_the_method` pins it.
     */
    public function test_the_otp_verify_boundary_rotates_the_session(): void
    {
        $verify = $this->methodSource('verifyPasswordOtp');

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
        //
        // THE NEEDLES ARE THE REAL ROUTE NAMES AND THEY ARE ASSERTED PRESENT. My first version
        // looked for `'2fa-verify'`, WHICH APPEARS NOWHERE IN THE FILE, and `continue`d on
        // `false` -- so half this test silently did nothing and would have kept passing if the
        // GoogleAuthentication dispatch moved above the regeneration. The routes are
        // `2fa.dashboard` (`:121`) and `duo2fa.form` (`:124`).
        foreach (['2fa.dashboard', 'duo2fa.form'] as $needle) {
            $at = strpos($source, $needle);

            $this->assertIsInt($at,
                "The MFA dispatch `{$needle}` was not found. Either the route was renamed -- in "
                . 'which case update the needle, do not let it skip -- or the dispatch is gone '
                . 'and item E needs re-deriving.');
            $this->assertGreaterThan($regenAt, $at,
                "An MFA dispatch ({$needle}) appears before the session regeneration.");
        }
    }
}

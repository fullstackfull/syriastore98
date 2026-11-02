<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F241 / F230 — THE THREE FACTS THE PAYMENT-RETURN DIAGNOSIS RESTS ON.
 * ====================================================================
 *
 * `F241-PAYMENT-RETURN-ARCHITECTURE.md` concludes that F241 is **Paytm-specific**: its plan return
 * is POST-only, so `SameSite=Lax` withholds the session cookie on the gateway's cross-site POST
 * and CSRF rejects it before `auth` is reached — a total outage of that purchase path. The other
 * three returns are reachable only because they are GET, which `VerifyCsrfToken::isReading()`
 * skips and which Lax permits as a top-level navigation.
 *
 * That diagnosis is worth exactly as much as the facts under it, so the facts are pinned here
 * rather than left in prose that can rot. Three of them:
 *
 *   1. which verbs each return accepts;
 *   2. that **none** of the four is CSRF-exempt — tested through the framework's own
 *      `inExceptArray()` semantics rather than by eye;
 *   3. that all four sit behind `auth`.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THIS FILE CANNOT TEST, AND WHY IT SAYS SO
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The 419 itself. `VerifyCsrfToken` short-circuits under `runningUnitTests()`, so no test in this
 * suite can observe a token mismatch — which is also why F241 survived unnoticed: the suite is
 * structurally blind to it. Nor can a test observe `SameSite` cookie behaviour, which is the
 * browser's decision and not the server's.
 *
 * So this pins the **server-side preconditions** of the diagnosis and nothing more. The 419 was
 * established by reading the middleware's own logic against the measured route table, and three
 * independent verifications of that reasoning agreed.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * AND ONE CASE IS A STANDING GUARD RATHER THAN A MEASUREMENT
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `test_no_plan_return_is_csrf_exempt` fails if anyone adds an exemption for these paths. The
 * owner's instruction was explicit — *"Do not add blanket CSRF exemptions or remove authentication
 * to make a callback work"* — and the tempting fix for F241 is exactly that one line in
 * `bootstrap/app.php`. It would make Paytm's return reachable and would prove nothing about the
 * payment, because a session establishes who is browsing and CSRF establishes where the form came
 * from; neither says money moved. The architecture's answer is a callback authenticated by
 * **provider evidence**, outside the session entirely.
 */
class PaymentReturnReachabilityTest extends TestCase
{
    /**
     * The four plan returns, as `[route name, path, the verb the gateway actually uses]`.
     *
     * Paytm's is the one under test; the other three are the control group that shows the
     * difference is the verb rather than anything about the providers.
     */
    private const PLAN_RETURNS = [
        'plan.paytm' => ['plan-paytm-payment/{plan_id}', 'POST'],
        'plan.mollie' => ['plan-mollie-payment/{plan}', 'GET'],
        'benefit.call_back' => ['plan-Benefit-payment', 'GET'],
        'cashfreePayment.success' => ['cashfree/payments/success', 'GET'],
    ];

    private function route(string $name)
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $name);

        $this->assertNotNull($route, 'the route `' . $name . '` no longer exists; re-read F241');

        return $route;
    }

    /**
     * FACT 1 — PAYTM'S RETURN IS POST-ONLY AND THE OTHER THREE ACCEPT GET.
     *
     * This is the whole asymmetry. A gateway redirect that is a GET keeps its cookie under Lax and
     * skips CSRF as a read; a POST loses the cookie and fails the token. If Paytm's route ever
     * accepts GET, F241 dissolves — and this test will say so rather than leaving the finding in
     * place.
     */
    public function test_paytm_accepts_no_safe_verb_while_the_other_three_do(): void
    {
        $paytm = $this->route('plan.paytm')->methods();

        $this->assertContains('POST', $paytm);
        $this->assertNotContains(
            'GET',
            $paytm,
            'Paytm\'s plan return now accepts GET. F241 was precisely that it did NOT: a POST-only '
            . 'return loses its session cookie to SameSite=Lax and fails CSRF, so the gateway\'s '
            . 'redirect never reaches the handler. If this changed deliberately, re-read '
            . 'F241-PAYMENT-RETURN-ARCHITECTURE.md -- reachability is not the same as safety, and '
            . 'a reachable GET return is reachable by a forged cross-site GET too.'
        );

        foreach (['plan.mollie', 'benefit.call_back', 'cashfreePayment.success'] as $name) {
            $this->assertContains(
                'GET',
                $this->route($name)->methods(),
                $name . ' no longer accepts GET, so it has joined Paytm in being unreachable by '
                . 'its own gateway. That is a new outage, not a fix.'
            );
        }
    }

    /**
     * FACT 2 — NONE OF THE FOUR IS CSRF-EXEMPT, and this is the standing guard.
     *
     * Tested through the framework's own matching rather than by reading the list: `inExceptArray()`
     * trims a leading slash from each pattern and uses `Str::is`, whose `*` spans `/`. Comparing
     * strings by eye is how an exemption like `*<slash>get-payment-paytm` (written with the slash spelled out, because the literal sequence would close this comment) gets mistaken for covering
     * `plan-paytm-payment`, which it does not.
     */
    public function test_no_plan_return_is_csrf_exempt(): void
    {
        $except = $this->csrfExceptPatterns();
        $this->assertContains('*/get-payment-paytm', $except,
            'the known Paytm STORE-payment exemption is missing from the parsed list, so the '
            . 'parser is reading the wrong block and this guard is measuring nothing');

        $this->assertGreaterThan(
            10,
            count($except),
            'the CSRF exception list came back nearly empty, so this test would pass against any '
            . 'exemption at all. Found: ' . count($except)
        );

        foreach (self::PLAN_RETURNS as $name => [$path, $verb]) {
            foreach ($except as $pattern) {
                $this->assertFalse(
                    Str::is(trim($pattern, '/'), $path),
                    'THE PLAN RETURN `' . $path . '` IS NOW CSRF-EXEMPT, via the pattern `'
                    . $pattern . "`.\n\nThe owner's instruction forbids this: \"Do not add blanket "
                    . 'CSRF exemptions or remove authentication to make a callback work." It would '
                    . 'make the return reachable and would prove NOTHING about the payment -- a '
                    . 'session says who is browsing, a token says where the form came from, and '
                    . 'neither says money moved. The architecture\'s answer is a callback '
                    . 'authenticated by provider evidence, outside the session entirely. See '
                    . 'F241-PAYMENT-RETURN-ARCHITECTURE.md §2.2.'
                );
            }
        }
    }

    /**
     * FACT 3 — ALL FOUR SIT BEHIND `auth`, which is why a provider has no way to reach them
     * without borrowing a buyer's browser.
     *
     * Also the reason the architecture puts the future callback OUTSIDE this group rather than
     * exempting it: a provider has no session, and the owner forbids removing authentication to
     * make one work. A callback authenticated by provider evidence does not need the session it
     * cannot have.
     */
    public function test_every_plan_return_requires_an_authenticated_session_today(): void
    {
        foreach (self::PLAN_RETURNS as $name => [$path, $verb]) {
            $middleware = $this->route($name)->gatherMiddleware();

            $this->assertContains('auth', $middleware, $path . ' no longer requires authentication');
            $this->assertContains('verified', $middleware, $path . ' no longer requires a verified email');
        }
    }

    /**
     * AND THE DIAGNOSIS'S BLIND SPOT IS ASSERTED, so nobody mistakes a green suite for safety.
     *
     * `VerifyCsrfToken` skips itself entirely when running tests. That is why F241 — a total
     * outage of the Paytm plan purchase path — sat undetected behind a suite of 7158 passing
     * Feature tests. The blind spot is the finding's own explanation, and pinning it means the
     * next person who wonders "why didn't a test catch this?" has the answer in the file.
     */
    public function test_premise_the_suite_is_structurally_blind_to_csrf(): void
    {
        $this->assertTrue(
            app()->runningUnitTests(),
            'premise broken: if this is false, VerifyCsrfToken is active in tests and the blind '
            . 'spot F241 hid in may have closed'
        );
    }

    /** @return array<int,string> */
    private function csrfExceptPatterns(): array
    {
        /*
         * READ FROM `bootstrap/app.php`, and the first version of this read the middleware
         * instance by reflection instead -- which came back with **zero** patterns and so would
         * have passed against any exemption at all. (The anti-vacuity assertion above is what
         * caught it; without that floor the test would have been silently useless, which is the
         * failure mode a guard like this is most prone to.)
         *
         * Laravel 11 assembles the list through `validateCsrfTokens(except: [...])` at boot, and
         * it does not survive on the resolved middleware in a form reflection finds. The
         * declaration site is also the RIGHT source for this test's purpose: the thing being
         * guarded against is somebody ADDING a pattern there, and that is exactly where they
         * would add it.
         */
        $source = file_get_contents(base_path('bootstrap/app.php'));
        $at = strpos($source, 'validateCsrfTokens(');

        $this->assertNotFalse($at, 'bootstrap/app.php no longer calls validateCsrfTokens()');

        $open = strpos($source, '[', $at);
        $close = strpos($source, ']', $open);
        $block = substr($source, $open, $close - $open);

        preg_match_all("/'([^']+)'/", $block, $matches);

        return $matches[1];
    }
}

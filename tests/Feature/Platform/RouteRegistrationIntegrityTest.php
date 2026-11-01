<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * THIS SUITE IS EXPECTED TO FAIL, AND THE FAILURE IS THE FINDING.
 *
 * It exists because two of the thirteen red scaffold tests -- ProfileTest's
 * test_profile_information_can_be_updated and
 * test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged --
 * fail with `Route [profile.edit] not defined`, and the obvious reading of that ("Breeze
 * asserted a profile screen this product never built, so retire the tests") is WRONG.
 * The route IS written. It is silently discarded at registration time. Those two tests
 * are telling the truth about a live defect and must not be deleted.
 *
 * THE MECHANISM, verified against the booted router rather than inferred from grep.
 * Illuminate\Routing\RouteCollection indexes routes by method + domain + URI, and
 * refreshNameLookups() rebuilds the name table from THAT index. So when two declarations
 * share a method and a URI, the later one replaces the earlier one outright and the
 * earlier one's NAME disappears -- with no error, no warning and no startup failure.
 *
 * MEASURED against the booted router: 1357 routes registered, 1253 named; 508 distinct
 * ->name(...) literals are declared across routes/*.php; two of those names are not in
 * the registry at all.
 *
 *   profile.edit   routes/web.php:417
 *                    Route::get('/profile', [ProfileController::class, 'edit'])
 *                  shadowed by routes/web.php:865
 *                    Route::get('profile', [UserController::class, 'profile'])->name('profile')
 *                  Both sit in the same Route::middleware(['auth','xss','setlocate',
 *                  'verified']) group opened at routes/web.php:411, with no prefix, so
 *                  both normalise to GET `profile`. Confirmed from the registry: the only
 *                  GET route on that URI is UserController@profile named `profile`.
 *                  ProfileController@edit is therefore UNREACHABLE DEAD CODE.
 *
 *                  LIVE IMPACT, three call sites that can only 500:
 *                    app/Http/Controllers/ProfileController.php:37
 *                      Redirect::route('profile.edit') -- reached by PATCH /profile,
 *                      which IS registered (as auth.profile.update), so every successful
 *                      merchant profile save throws RouteNotFoundException AFTER writing.
 *                      Measured: the name and email persist, then the response is 500.
 *                    resources/views/layouts/navigation.blade.php:37 and :83
 *                      route('profile.edit') in the authenticated navigation partial.
 *
 *   contact.us     routes/customer.php:57 and :68 declare the SAME method and URI
 *                    GET {storeSlug?}/contact-us -> HomeController@contactUs
 *                  under two different names; :68's `page.contact_us` wins and
 *                  `contact.us` is lost. Lower severity than profile.edit: no code calls
 *                  route('contact.us') today, so this is a latent trap rather than a live
 *                  break. It is reported because it is the same defect class and because
 *                  the next person to use that name would get a 500.
 *
 * NOT A FINDING, and the reason this test strips comments before matching: routes/web.php:396
 * carries a COMMENTED-OUT declaration of the name `landing`
 *   //Route::get('/', [HomeController::class, 'Landing'])->name('landing')...
 * A naive regex over the raw file reports `landing` as a third lost name. It is not one.
 * This is the same trap .github/scripts/guard-test-isolation.sh documents for phpunit.xml,
 * so the scan below tokenises each file and discards T_COMMENT / T_DOC_COMMENT first.
 *
 * FIXING IT IS NOT THIS AGENT'S CALL: routes/ is owned by another agent in this phase.
 * Recorded as a cross-agent dependency. The one-line fix for the severe case is to rename
 * one of the two colliding GET `profile` declarations -- NOT to delete either, since both
 * controller actions are referenced.
 */
class RouteRegistrationIntegrityTest extends TestCase
{
    /**
     * Every ->name() literal written in routes/ must survive registration. A name that
     * does not is a route someone believes exists; route() on it is a guaranteed 500.
     */
    public function test_every_route_name_declared_in_the_route_files_is_actually_registered(): void
    {
        $declared = $this->declaredRouteNames();

        $this->assertNotEmpty(
            $declared,
            'No ->name() literals were extracted from routes/. The scanner is broken, so '
            . 'this test would otherwise pass vacuously.'
        );

        $lost = [];
        foreach ($declared as $name => $sites) {
            if (array_key_exists($name, self::GATED_ROUTES)) {
                continue;   // asserted below, against its gate's ACTUAL state
            }

            if (! Route::has($name)) {
                $lost[$name] = implode(', ', $sites);
            }
        }

        $this->assertSame(
            [],
            $lost,
            "A route name is declared in routes/ but is absent from the registry, which "
            . "means a later declaration sharing its method and URI silently replaced it. "
            . "route() on any of these throws RouteNotFoundException:\n"
            . print_r($lost, true)
        );
    }

    /**
     * ROUTES DECLARED INSIDE A CONFIG GATE, each mapped to the gate that controls it.
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * WHY THIS IS HERE TWICE, AND WHY THAT IS THE POINT
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * `tests/Feature/Security/RouteShadowingTest` carries the same map and the same paired
     * assertion. **The duplication is deliberate and documented in both files** — that suite
     * belongs to another workstream and the repairs it guards live in `routes/`, so the guard has
     * to sit beside the thing it guards as well.
     *
     * [2026-11-01] AND THE DUPLICATION IS EXACTLY WHAT CAUGHT ME OUT. F241 added
     * `payment.callback.paytm.plan` inside `if (config('payments.paytm_callback_enabled'))`, which
     * ships shut — so the name is declared in `routes/api.php` and legitimately absent from the
     * registry. I taught `RouteShadowingTest` about that and **did not touch this file**, so the
     * full known-outcome Feature gate came back with exactly one undeclared failure: this test.
     * One of two identical guards being updated is the precise failure mode having two is for.
     *
     * A name listed here is NOT exempt. `test_every_gated_route_follows_its_gate()` asserts it
     * against the gate's real state in BOTH directions, so a gated route that vanishes while its
     * gate is OPEN still fails, and one that appears while its gate is SHUT fails too.
     *
     * Keep this map tiny. A route belongs here only when a config key decides whether it exists at
     * all, and the reason has to be written down beside it.
     *
     * - `payment.callback.paytm.plan` (F241, `routes/api.php`): the provider-authenticated Paytm
     *   plan callback. It ships CLOSED because no LIVE Paytm transaction has been verified from
     *   this container — there are no Paytm credentials here and no network path to
     *   `securegw.paytm.in` — and the owner's condition is that no callback is exposed until
     *   authenticity, intent binding, replay resistance and concurrency safety are all proven. See
     *   `config/payments.php`.
     */
    private const GATED_ROUTES = [
        'payment.callback.paytm.plan' => 'payments.paytm_callback_enabled',
    ];

    /**
     * A GATED ROUTE EXISTS EXACTLY WHEN ITS GATE SAYS SO — the other half of the exemption above.
     *
     * This is what stops `GATED_ROUTES` from becoming a place to park a route that really did get
     * lost. The route files are read at BOOT, so the gate cannot be flipped and re-measured inside
     * one test; what is measured is agreement between the registry and the gate as this process
     * loaded them, plus the fact that the declaration really is wrapped in that gate.
     */
    public function test_every_gated_route_follows_its_gate(): void
    {
        $this->assertNotEmpty(self::GATED_ROUTES, 'premise: the map is empty, so this is vacuous');

        foreach (self::GATED_ROUTES as $name => $configKey) {
            $open = (bool) config($configKey);

            $this->assertSame($open, Route::has($name),
                $open
                    ? "config('{$configKey}') is TRUE but route '{$name}' is not registered, so the "
                      . 'gate is open and the route is genuinely lost'
                    : "config('{$configKey}') is FALSE but route '{$name}' IS registered, so the "
                      . 'gate is not actually gating anything');

            $sources = '';

            foreach (glob(base_path('routes') . '/*.php') ?: [] as $path) {
                $sources .= (string) file_get_contents($path);
            }

            $this->assertStringContainsString("config('{$configKey}')", $sources,
                "'{$name}' is listed as gated by {$configKey}, but no route file consults that key");
        }
    }

    /**
     * The severe instance, pinned on its own so it cannot be lost in the aggregate above
     * and so the aggregate test's eventual fix cannot accidentally leave this one broken.
     */
    public function test_the_profile_edit_route_that_profilecontroller_redirects_to_exists(): void
    {
        $this->assertTrue(
            Route::has('profile.edit'),
            'ProfileController::update() ends in Redirect::route(\'profile.edit\') '
            . '(app/Http/Controllers/ProfileController.php:37) and '
            . 'resources/views/layouts/navigation.blade.php:37,83 call route(\'profile.edit\'), '
            . 'but no such route name is registered. routes/web.php:417 declares it on '
            . 'GET /profile and routes/web.php:865 re-declares GET profile as name '
            . '\'profile\', which replaces it in RouteCollection\'s method+URI index and '
            . 'drops the name. Every successful PATCH /profile therefore writes the record '
            . 'and then returns 500.'
        );
    }

    /**
     * Guards the fix as well as the defect: whatever name GET /profile ends up with, the
     * PATCH and DELETE verbs on that URI must keep reaching ProfileController, or the
     * profile screen loses its write paths.
     */
    public function test_the_profile_uri_keeps_its_write_verbs_on_profilecontroller(): void
    {
        $byMethod = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'profile') {
                continue;
            }
            foreach ($route->methods() as $method) {
                $byMethod[$method] = $route->getActionName();
            }
        }

        $this->assertArrayHasKey('PATCH', $byMethod, 'PATCH /profile is not registered.');
        $this->assertArrayHasKey('DELETE', $byMethod, 'DELETE /profile is not registered.');

        $this->assertStringContainsString(
            'ProfileController@update',
            $byMethod['PATCH'],
            'PATCH /profile no longer reaches ProfileController@update.'
        );
        $this->assertStringContainsString(
            'ProfileController@destroy',
            $byMethod['DELETE'],
            'DELETE /profile no longer reaches ProfileController@destroy.'
        );
    }

    /**
     * Extract every ->name('literal') from routes/, with comments removed first.
     *
     * @return array<string, list<string>> name => ["file:line", ...]
     */
    private function declaredRouteNames(): array
    {
        $names = [];

        foreach (glob(base_path('routes') . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            // Rebuild the file with comment tokens blanked out, preserving line numbers
            // so the reported file:line stays accurate. A commented-out ->name() must not
            // register as a declaration -- routes/web.php:396 is exactly that case.
            $stripped = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        // Keep only the newlines, so line numbers do not shift.
                        $stripped .= str_repeat("\n", substr_count($token[1], "\n"));
                        continue;
                    }
                    $stripped .= $token[1];
                    continue;
                }
                $stripped .= $token;
            }

            foreach (explode("\n", $stripped) as $i => $line) {
                if (preg_match_all('/->name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $m)) {
                    foreach ($m[1] as $name) {
                        $names[$name][] = basename($path) . ':' . ($i + 1);
                    }
                }
            }
        }

        return $names;
    }
}

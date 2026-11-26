<?php

namespace Tests\Unit\Routing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * THE FOUR MYFATOORAH ROUTES THAT 500, ONE OF WHICH IS A PAYMENT WEBHOOK (GAP-268's money half).
 * =================================================================================================
 *
 * `EveryRouteActionExistsTest` measured 73 controller actions a route names and no class defines.
 * Four of them are MyFatoorah's, and the register singles out
 *
 *     POST myfatoorah/webhook  ->  App\Http\Controllers\MyFatoorahController::webhook()
 *
 * because a provider posting a capture notification got an HTTP 500.
 *
 * WHERE THE FOUR ROUTES ACTUALLY COME FROM, which changes the whole shape of the fix and is not
 * what the register assumed. They are NOT in `routes/`. MEASURED:
 * `grep -rn MyFatoorahController routes/` returns three declarations, and all three name
 * `planPayWithmyfatoorah` or `paymentCallback` -- the two methods that DO exist. The four broken
 * ones are declared by a vendored composer package, in
 * `vendor/myfatoorah/laravel-package/src/MyFatoorahServiceProvider::register()`, which the empty
 * `extra.laravel.dont-discover` in composer.json lets Laravel auto-discover. The package publishes
 * its own sample controller to `app_path('Http/Controllers/MyFatoorahController.php')`; WorkDo
 * overwrote that file with their own two-method version, so the package's four routes were
 * orphaned by the overwrite.
 *
 * WHY THE PROVIDER CANNOT SIMPLY BE UN-DISCOVERED. Its `register()` also does
 *
 *     defined('MYFATOORAH_LARAVEL_PACKAGE_VERSION') or define(..., '2.2.4');
 *
 * and THREE live payment call sites read that constant (pinned below). Adding the provider to
 * `dont-discover` would turn every one of them into an undefined-constant fatal -- a real
 * regression on a money path, to fix a latent one. So the routes are withdrawn where a host
 * declaration can reach them: `register()` runs before `routes/web.php` is loaded, and
 * `RouteCollection` keys on method+domain+uri, so a host declaration of the same verb and URI
 * REPLACES the package's (CLAUDE.md's "later route declarations replace earlier ones" read in the
 * one direction where it helps instead of hurting).
 *
 * WHAT THE REPLACEMENT DOES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It answers 404. It records nothing. **No payment record is created or changed and no money
 * moves**, and that is a deliberate stopping point, not an oversight -- see the next paragraph.
 *
 * 404 IS NOT AN INVENTED ANSWER; IT IS THE VENDORED INTEGRATION'S OWN ANSWER FOR THIS
 * CONFIGURATION. The package's sample `webhook()` opens with
 *
 *     $secretKey = config('myfatoorah.webhook_secret_key');
 *     if (empty($secretKey)) { return response(null, 404); }
 *
 * and `config/myfatoorah.php` ships `'webhook_secret_key' => ''` with no `env()` behind it, so the
 * webhook was never enabled on the MyFatoorah account. Had the method existed, it would have
 * answered 404 today. The 500 was therefore never a capture being dropped in flight; it was a trap
 * for whoever pastes a secret key in later. Two further measurements say the same thing: the
 * package's `changeTransactionStatus()` is a stub whose four steps are COMMENTS
 * ("//4. Update order transaction status on your system") and which writes nothing, so the package
 * never shipped capture recording either; and this product books its own captures synchronously in
 * `paymentCallback()`, which creates the `PlanOrder` and calls `assignPlan()`.
 *
 * THE PAYMENT DECISION THAT IS LEFT OPEN, stated here so it is not mistaken for finished work:
 * whether Store Syria should consume MyFatoorah webhooks AT ALL. It currently books a plan purchase
 * only on the browser callback, so a payer who closes the tab after paying is charged and never
 * activated. Closing that needs a capture handler that writes a payment record, which is a change
 * to payment behaviour and an owner decision -- not something to slip into a route triage.
 */
class MyFatoorahPackageRoutesWithdrawnTest extends TestCase
{
    use StripsSourceComments;

    /** verb => uri, exactly as MyFatoorahServiceProvider::register() declares them. */
    private const PACKAGE_ROUTES = [
        'myfatoorah' => ['GET', 'myfatoorah'],
        'myfatoorah.callback' => ['GET', 'myfatoorah/callback'],
        'myfatoorah.cardView' => ['GET', 'myfatoorah/checkout'],
        'myfatoorah.webhook' => ['POST', 'myfatoorah/webhook'],
    ];

    private function matched(string $verb, string $uri): \Illuminate\Routing\Route
    {
        return Route::getRoutes()->match(Request::create('/' . ltrim($uri, '/'), $verb));
    }

    /**
     * PREMISE 1. The provider really is registered, so these routes really are in the table and
     * this file is not measuring an empty set.
     */
    #[Test]
    public function test_premise_the_vendored_provider_is_registered(): void
    {
        $this->assertArrayHasKey(
            \MyFatoorah\LaravelPackage\MyFatoorahServiceProvider::class,
            $this->app->getLoadedProviders(),
            'PREMISE: the MyFatoorah package provider is not loaded, so the four routes it declares '
            . 'are not in the table and every assertion below is vacuous.'
        );
    }

    /**
     * PREMISE 2. `dont-discover` IS NOT AN OPTION, because three live payment call sites read a
     * constant that only the provider defines.
     *
     * Comment-stripped: the constant's name appears in this file's own docblock and in the
     * routes-file comment that withdraws the routes, and a census that counted those would pass
     * with the real call sites deleted.
     */
    #[Test]
    public function test_premise_three_payment_call_sites_depend_on_the_providers_constant(): void
    {
        $this->assertTrue(
            defined('MYFATOORAH_LARAVEL_PACKAGE_VERSION'),
            'PREMISE: the constant is undefined, so the provider did not register.'
        );

        $callSites = [
            'app/Services/PaymentService.php',
            'app/Http/Controllers/MyFatoorahController.php',
            'packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php',
        ];

        foreach ($callSites as $file) {
            $this->assertStringContainsString(
                'MYFATOORAH_LARAVEL_PACKAGE_VERSION',
                $this->sourceWithoutComments($file),
                $file . ' no longer reads the provider\'s constant. If all three have stopped, '
                . 'un-discovering the provider becomes available and the route override in '
                . 'routes/web.php can be replaced by it -- re-measure before assuming that.'
            );
        }
    }

    /**
     * THE DEFECT. None of the four may resolve to a method `MyFatoorahController` does not define.
     *
     * THE EXAMINED COUNT IS ASSERTED, and that is not ceremony. The first version of this case
     * wrapped its assertion in `if (str_contains($action, '@'))`, so once the fix turned all four
     * into closures the loop asserted NOTHING and PHPUnit reported the case `risky` rather than
     * failing -- the same shape as the `__call` skip that made the first GAP-268 ratchet examine 0
     * of 1,500 routes and pass. A per-route verdict is recorded either way, and the tally is
     * checked, so an empty run cannot read as success.
     */
    #[Test]
    public function test_none_of_the_four_package_routes_resolves_to_a_missing_controller_method(): void
    {
        $verdicts = [];

        foreach (self::PACKAGE_ROUTES as $name => [$verb, $uri]) {
            $action = $this->matched($verb, $uri)->getActionName();

            $serveable = ! str_contains($action, '@')
                || method_exists(...explode('@', $action, 2));

            $verdicts[$name] = $serveable
                ? 'serveable: ' . $action
                : 'HTTP 500: ' . $action . ' DOES NOT EXIST';
        }

        $this->assertCount(
            4,
            $verdicts,
            'fewer than four routes were examined, so this case is measuring an incomplete set.'
        );

        $this->assertSame(
            [
                'myfatoorah' => 'serveable: Closure',
                'myfatoorah.callback' => 'serveable: Closure',
                'myfatoorah.cardView' => 'serveable: Closure',
                'myfatoorah.webhook' => 'serveable: Closure',
            ],
            $verdicts,
            'a MyFatoorah route either still points at a method that does not exist (HTTP 500 for '
            . 'any request that reaches it), or has been re-pointed at a controller method. The '
            . 'second is not forbidden -- but implementing `webhook()` is a PAYMENT BEHAVIOUR '
            . 'change (it would record a capture) and needs an owner decision, so update this '
            . 'expectation deliberately and say what the handler now books.'
        );
    }

    /**
     * The withdrawal must not have cost the four their names: `route()` on a name nothing declares
     * throws at RENDER time, which turns one broken click into a whole blank page.
     */
    #[Test]
    public function test_the_four_route_names_still_resolve(): void
    {
        foreach (self::PACKAGE_ROUTES as $name => [, $uri]) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                'the route name ' . $name . ' no longer exists, so route(\'' . $name . '\') throws.'
            );
        }
    }

    /**
     * THE MONEY ROUTE, DRIVEN. A tokenless POST -- which is what a provider sends -- must answer
     * 404 and not 500.
     *
     * 419 WOULD ALSO BE WRONG, and is the trap this case exists to catch: `routes/web.php` is
     * wrapped in the `web` group, `myfatoorah/webhook` is not in `bootstrap/app.php`'s
     * `validateCsrfTokens(except:)` list, and CSRF runs BEFORE the action. So a naive override
     * answers 419 TokenMismatch and the declared 404 is never reached -- a route table that says
     * one thing and does another. The declaration strips the group to reproduce the package's own
     * (empty) middleware.
     */
    #[Test]
    public function test_the_webhook_answers_404_to_a_tokenless_post(): void
    {
        $response = $this->post('/myfatoorah/webhook', ['anything' => 'at all']);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'POST myfatoorah/webhook answered ' . $response->getStatusCode() . ', not 404.'
            . "\n  419 — the web group's CSRF middleware answered first, so the declared 404 is a"
            . " fiction: `withoutMiddleware('web')` has been dropped."
            . "\n  500 — EITHER the route points at `MyFatoorahController::webhook()` again, which"
            . ' does not exist (that is the original defect, and the shape of the pre-fix'
            . ' measurement, because the package declares these routes with NO middleware at all),'
            . ' OR the route has been put back inside the `web` group and one of its ~34 middleware'
            . ' failed before CSRF could answer — this case runs without `RefreshDatabase`, so any'
            . ' group middleware that queries the database throws here. Both readings are real'
            . ' failures; read the response body to tell them apart, and check'
            . ' `test_the_withdrawn_routes_run_outside_the_web_group_and_the_live_one_does_not`,'
            . ' which distinguishes them directly.'
        );
    }

    /**
     * THE WITHDRAWN FOUR CARRY NO MIDDLEWARE, AND THE LIVE ONE STILL CARRIES THE WEB GROUP.
     *
     * `withoutMiddleware('web')` resolves the group to its members and excludes each at GATHER
     * time, so `$route->middleware()` still reports `['web']` and only
     * `Router::gatherRouteMiddleware()` shows the truth — a snapshot of declared middleware reads
     * as though the group were still attached. MEASURED here instead: 0 for each withdrawn route,
     * which reproduces the package's own declarations exactly (they carried none), and non-zero
     * for the live callback so this case also fails if the `web` group is ever stripped off the
     * real integration.
     *
     * This is an invariant worth asserting rather than a detail: the routes answer `abort(404)` and
     * touch nothing, so running them outside the group is safe — but only while that stays true.
     */
    #[Test]
    public function test_the_withdrawn_routes_run_outside_the_web_group_and_the_live_one_does_not(): void
    {
        $router = app('router');
        $counts = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! array_key_exists($name, self::PACKAGE_ROUTES) && $name !== 'myfatoorah.call_back') {
                continue;
            }

            $counts[$name] = count($router->gatherRouteMiddleware($route)) === 0
                ? 'no middleware'
                : 'inside the web group';
        }

        ksort($counts);

        $this->assertSame(
            [
                'myfatoorah' => 'no middleware',
                'myfatoorah.call_back' => 'inside the web group',
                'myfatoorah.callback' => 'no middleware',
                'myfatoorah.cardView' => 'no middleware',
                'myfatoorah.webhook' => 'no middleware',
            ],
            $counts,
            'If a WITHDRAWN route is now "inside the web group", its `withoutMiddleware(\'web\')` '
            . 'was dropped and a provider\'s tokenless POST gets 419 from CSRF instead of the '
            . 'declared 404. If `myfatoorah.call_back` is now "no middleware", the LIVE plan '
            . 'payment callback has lost authentication, `xss` and CSRF — that is a real hole, not '
            . 'a tidy-up.'
        );
    }

    /**
     * THE LIVE INTEGRATION IS UNTOUCHED. The two methods that exist must still be routed, or the
     * withdrawal has broken the plan purchase it was meant to protect.
     */
    #[Test]
    public function test_the_working_myfatoorah_routes_are_untouched(): void
    {
        foreach ([
            'plan.pay.with.myfatoorah' => 'planPayWithmyfatoorah',
            'myfatoorah.call_back' => 'paymentCallback',
            'store.myfatoorah.call_back' => 'paymentCallback',
        ] as $name => $method) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name . ' is gone.');
            $this->assertSame(
                \App\Http\Controllers\MyFatoorahController::class . '@' . $method,
                $route->getActionName(),
                $name . ' no longer points at the method that exists.'
            );
        }
    }
}

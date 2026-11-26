<?php

namespace Tests\Unit\Routing;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EVERY ROUTE'S CONTROLLER METHOD EXISTS. NOTHING CHECKED THIS, AND ONE ROUTE'S DID NOT.
 *
 * Found 2026-11-26 by the `P01-G2` customer-journey exercise, not by any test:
 * `routes/customer.php:193` declared
 *
 *     Route::post('{storeSlug?}/place-order', [OrderController::class, 'place_order'])->name('place.order')
 *
 * and `App\Http\Controllers\OrderController` has no `place_order` method — the only one under
 * `app/Http/Controllers` is `Api\ApiController::place_order`. Any POST reaching that URL was a 500
 * rather than a checkout.
 *
 * WHY NOTHING CAUGHT IT, which is the part worth keeping:
 *
 *   - **`route:cache` does not catch this.** Laravel resolves the controller METHOD lazily, at
 *     dispatch. Only a missing CLASS fails at cache time, so a deploy that caches routes
 *     successfully proves nothing about the methods.
 *   - It was orphaned rather than broken-in-use — the shipped storefront checkout posts to
 *     `payment.process` — so no page was visibly broken and no user ever reported it.
 *   - Nothing referenced the route name either: `grep -rn "place\.order"` over `app/`,
 *     `resources/`, `routes/`, `packages/workdo/` and `themes/` returned the declaration and
 *     nothing else, and the Flutter endpoint manifest has zero `place-order` entries.
 *
 * So the defect was invisible from every direction at once, which is exactly the shape that
 * deserves a ratchet rather than a one-line deletion.
 *
 * WHAT THIS DELIBERATELY DOES NOT COVER
 * =====================================
 *
 * **MODULE ROUTES.** CLAUDE.md records that no WorkDo module loads in any test run unless the test
 * inserts an `add_on_managers` row, and that loading one in-process pollutes every later test by
 * adding PSR-4 prefixes to the process-global Composer ClassLoader. So the routes visible here are
 * the HOST's. `tests/Support/Security/bin/route-census.php` measures the module half out of
 * process and reports 1,464 module routes against 1,594 host ones — if this count collapses toward
 * zero, the premise test below fails rather than the census silently passing on an empty set.
 *
 * Closures, invokable controllers and framework-registered actions are skipped explicitly, each
 * for a stated reason, rather than being swept up by a `try`.
 */
class EveryRouteActionExistsTest extends TestCase
{

    /**
     * THE MEASURED BACKLOG — 73 controller actions a route names and no class defines.
     *
     * Every one of these is an HTTP 500 for any request that reaches it, and they are PRE-EXISTING:
     * this list is what the first run of this test found, not breakage it allows. It is asserted in
     * BOTH directions — a new entry fails, and an entry that starts existing fails until it is
     * deleted from here — so the set can shrink and cannot quietly grow.
     *
     * FOUR OF THEM ARE A PAYMENT INTEGRATION, and one is worse than the rest:
     *
     *     POST myfatoorah/webhook  ->  MyFatoorahController::webhook()
     *
     * `MyFatoorahController` defines exactly two methods, `planPayWithmyfatoorah` and
     * `paymentCallback`; `index`, `callback`, `checkout` and `webhook` are all absent. A provider
     * posting a capture notification to that webhook gets a 500, so the capture is never recorded —
     * money taken and not booked. Carried as `GAP-268`; the fix is a decision (finish the
     * integration, or withdraw the routes) rather than a repair, which is why it is listed here
     * instead of being patched in the change that found it.
     *
     * HOW THIS WENT UNNOTICED. `route:cache` resolves the controller METHOD lazily at dispatch, so
     * only a missing CLASS fails at cache time — a deploy can cache these routes successfully. The
     * one route found by hand (`place.order`, via the `P01-G2` journey) was additionally orphaned:
     * nothing referenced its name, so no page was visibly broken.
     *
     * @var list<string>
     */
    private const KNOWN_BROKEN = [
        'App\Http\Controllers\AccountProfileController@destroy',
        'App\Http\Controllers\AccountProfileController@edit',
        'App\Http\Controllers\AccountProfileController@show',
        'App\Http\Controllers\AccountProfileController@update',
        'App\Http\Controllers\AddonController@edit',
        'App\Http\Controllers\AddonController@show',
        'App\Http\Controllers\AddonController@store',
        'App\Http\Controllers\AddonController@update',
        'App\Http\Controllers\Api\ApiController@random_product',
        'App\Http\Controllers\AppSettingController@destroy',
        'App\Http\Controllers\AppSettingController@edit',
        'App\Http\Controllers\AppSettingController@image_delete',
        'App\Http\Controllers\AppSettingController@show',
        'App\Http\Controllers\AppSettingController@update',
        'App\Http\Controllers\BlogController@show',
        'App\Http\Controllers\ModuleController@AddOnSessionSave',
        'App\Http\Controllers\ModuleController@AddOnSessioncreate',
        'App\Http\Controllers\MyFatoorahController@callback',
        'App\Http\Controllers\MyFatoorahController@checkout',
        'App\Http\Controllers\MyFatoorahController@index',
        'App\Http\Controllers\MyFatoorahController@webhook',
        'App\Http\Controllers\OrderController@allOrders',
        'App\Http\Controllers\OrderController@edit',
        'App\Http\Controllers\OrderController@place_order',
        'App\Http\Controllers\OrderController@update',
        'App\Http\Controllers\OrderNoteController@create',
        'App\Http\Controllers\OrderNoteController@edit',
        'App\Http\Controllers\OrderNoteController@index',
        'App\Http\Controllers\OrderNoteController@show',
        'App\Http\Controllers\OrderNoteController@update',
        'App\Http\Controllers\OrderRefundController@create',
        'App\Http\Controllers\OrderRefundController@destroy',
        'App\Http\Controllers\OrderRefundController@edit',
        'App\Http\Controllers\OrderRefundController@store',
        'App\Http\Controllers\OrderRefundController@update',
        'App\Http\Controllers\PlanRequestController@create',
        'App\Http\Controllers\PlanRequestController@destroy',
        'App\Http\Controllers\PlanRequestController@edit',
        'App\Http\Controllers\PlanRequestController@show',
        'App\Http\Controllers\PlanRequestController@store',
        'App\Http\Controllers\PlanRequestController@update',
        'App\Http\Controllers\PosController@destroy',
        'App\Http\Controllers\PosController@edit',
        'App\Http\Controllers\PosController@show',
        'App\Http\Controllers\PosController@update',
        'App\Http\Controllers\ReportController@create',
        'App\Http\Controllers\ReportController@destroy',
        'App\Http\Controllers\ReportController@edit',
        'App\Http\Controllers\ReportController@show',
        'App\Http\Controllers\ReportController@store',
        'App\Http\Controllers\ReportController@update',
        'App\Http\Controllers\ShopifyCategoryController@create',
        'App\Http\Controllers\ShopifyCategoryController@store',
        'App\Http\Controllers\ShopifyProductController@create',
        'App\Http\Controllers\ShopifyProductController@destroy',
        'App\Http\Controllers\ShopifyProductController@store',
        'App\Http\Controllers\ShopifyProductController@update',
        'App\Http\Controllers\SupportTicketController@create',
        'App\Http\Controllers\SupportTicketController@show',
        'App\Http\Controllers\SupportTicketController@store',
        'App\Http\Controllers\SupportTicketController@update',
        'App\Http\Controllers\WoocomCategoryController@destroy',
        'App\Http\Controllers\WoocomCategoryController@update',
        'App\Http\Controllers\WoocomProductController@create',
        'App\Http\Controllers\WoocomProductController@store',
        'Workdo\LandingPage\Http\Controllers\BuiltTechSectionController@store',
        'Workdo\LandingPage\Http\Controllers\DedicatedSectionController@store',
        'Workdo\LandingPage\Http\Controllers\MarketPlaceController@pricing_plan_create',
        'Workdo\LandingPage\Http\Controllers\MarketPlaceController@pricing_plan_delete',
        'Workdo\LandingPage\Http\Controllers\MarketPlaceController@pricing_plan_edit',
        'Workdo\LandingPage\Http\Controllers\MarketPlaceController@pricing_plan_store',
        'Workdo\LandingPage\Http\Controllers\MarketPlaceController@pricing_plan_update',
        'Workdo\LandingPage\Http\Controllers\PackageDetailsController@store',
    ];
    /**
     * @return list<array{name: string, uri: string, action: string}>
     */
    private function controllerRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // A closure route has no method to look up.
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            $out[] = [
                'name' => (string) ($route->getName() ?? '(unnamed)'),
                'uri' => $route->uri(),
                'class' => $class,
                'method' => $method,
                'verbs' => implode('|', $route->methods()),
            ];
        }

        return $out;
    }

    /** Guards the assertion below against a silently empty route table. */
    #[Test]
    public function test_the_premise_the_route_table_was_actually_read(): void
    {
        $routes = $this->controllerRoutes();

        $this->assertGreaterThan(
            500,
            count($routes),
            'PREMISE: fewer than 500 controller routes were read. The host route table did not '
            . 'load, and the assertion below would pass against nothing.'
        );
    }

    /**
     * THE DEFECT ITSELF.
     */
    #[Test]
    public function test_every_controller_route_points_at_a_method_that_exists(): void
    {
        $broken = [];
        $brokenActions = [];

        foreach ($this->controllerRoutes() as $route) {
            $class = $route['class'];
            $method = $route['method'];

            // A class that does not exist is a different (louder) defect and is reported as such
            // rather than silently skipped.
            if (! class_exists($class)) {
                $broken[] = sprintf(
                    '%s %s -> %s@%s  (THE CLASS does not exist)',
                    $route['verbs'],
                    $route['uri'],
                    $class,
                    $method
                );
                $brokenActions[] = $class . '@' . $method;

                continue;
            }

            /*
             * `__call` MAKES A METHOD REACHABLE WITHOUT BEING DECLARED -- BUT ONLY IF THE CLASS
             * MEANS IT, AND THE FIRST VERSION OF THIS TEST GOT THAT EXACTLY BACKWARDS.
             *
             * It skipped any class where `method_exists($class, '__call')`, which reads as a
             * careful exemption and is in fact a blanket one: `Illuminate\Routing\Controller`
             * declares `__call()`, EVERY controller in this application inherits it, and the
             * assertion below therefore ran against nothing. Measured: 1,500 routes in the table,
             * 0 examined, test green -- including green on the very route it was written for.
             * The premise test passed throughout, because the route table really was read; it was
             * the filter underneath that emptied it.
             *
             * The irony is the point: Laravel's base `__call()` exists SPECIFICALLY to throw
             * `BadMethodCallException` on a missing controller method. Treating its presence as
             * "this class answers anything" inverts its meaning.
             *
             * So the skip now asks who DECLARES `__call`, not who has one. A class with its own
             * handler is genuinely unanswerable from here and is skipped; one that merely inherits
             * the framework's thrower is checked.
             */
            if (method_exists($class, '__call')) {
                $declaredBy = (new \ReflectionMethod($class, '__call'))->getDeclaringClass()->getName();

                if ($declaredBy !== \Illuminate\Routing\Controller::class) {
                    continue;
                }
            }

            if (! method_exists($class, $method)) {
                $broken[] = sprintf(
                    '%s %s  (name: %s)  ->  %s::%s()  DOES NOT EXIST',
                    $route['verbs'],
                    $route['uri'],
                    $route['name'],
                    $class,
                    $method
                );
                $brokenActions[] = $class . '@' . $method;
            }
        }

        $observed = array_values(array_unique($brokenActions));
        sort($observed);

        $declared = self::KNOWN_BROKEN;
        sort($declared);

        $new = array_values(array_diff($observed, $declared));
        $fixed = array_values(array_diff($declared, $observed));

        $this->assertSame([], $new, sprintf(
            "%d route action(s) point at a method that does not exist and are NOT in the declared "
            . "set, so any request reaching them is an HTTP 500:\n  %s\n\n"
            . 'Route caching does NOT catch this — Laravel resolves the method lazily at dispatch, '
            . 'so only a missing CLASS fails at cache time, and a deploy that caches routes '
            . 'successfully proves nothing about the methods. Do not add it to KNOWN_BROKEN to get '
            . 'green: that list is the measured backlog, not a place to put new breakage.',
            count($new),
            implode("\n  ", $new)
        ));

        $this->assertSame([], $fixed, sprintf(
            "%d action(s) in KNOWN_BROKEN now EXIST. Delete them from the list:\n  %s\n\n"
            . 'The set is asserted in both directions on purpose. A baseline that only ever grows '
            . 'stops being a measurement, and a fixed entry left in it hides the next regression '
            . 'at that exact route.',
            count($fixed),
            implode("\n  ", $fixed)
        ));
    }
}

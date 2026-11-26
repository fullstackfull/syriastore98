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
     * THE MEASURED BACKLOG. 73 at the first run of this test; 9 now, and the remaining 9 are not
     * "the ones nobody got to" -- each is here for a stated reason that a test asserts.
     *
     * 64 WERE WITHDRAWN, in four batches, every one measured before it was touched:
     *
     *   -4   the MyFatoorah payment package. Declared by a VENDORED provider's `register()`, not
     *        by `routes/`, so they are replaced rather than removed; they answer 404, which is the
     *        package's own answer while `webhook_secret_key` is empty, as it ships. No capture is
     *        recorded and no payment record changes -- `MyFatoorahPackageRoutesWithdrawnTest`.
     *   -55  `Route::resource()` actions the controller never implemented. Laravel declares all
     *        seven whatever the class defines. Route-table diff: 1504 -> 1448, 56 rows removed,
     *        0 added, and all 55 actions verified missing by `method_exists()`, so no working
     *        route was removed. One of the 55 needed a SECOND route deleted too
     *        (`support_ticket.view`, which routed `@show` a second time).
     *   -4   dead hand-written routes -- `DeadControllerRoutesWithdrawnTest`. `order/all` was not
     *        merely dead but UNREACHABLE: `order/{order}` is declared above it.
     *   -1   `GAP-267`'s web `place.order` -- `WebPlaceOrderRouteWithdrawnTest`.
     *
     * THE 9 THAT REMAIN, AND WHY EACH IS NOT A DELETION:
     *
     *   `AppSettingController@image_delete` (1) -- THE ONLY ONE BROKEN IN USE rather than
     *   orphaned. `resources/views/AppSetting/mobile_content.blade.php` calls
     *   `route('product.image.delete')` inside a rendered view, so deleting the route throws
     *   `RouteNotFoundException` at render time and 500s the whole Mobile Screen Content page --
     *   worse than the defect. It needs the METHOD implemented, and an unlink keyed on
     *   `$request->image` is the P1-SEC-71 shape (see `WebSettingsSecretsTest`), so it is not a
     *   routing fix. All three halves of that reasoning are asserted by
     *   `DeadControllerRoutesWithdrawnTest::test_the_product_image_delete_route_is_deliberately_kept`,
     *   so the entry can be retired only by actually fixing it.
     *
     *   `Workdo\LandingPage\...` (8) -- declared in `packages/workdo/LandingPage/src/Routes/web.php`,
     *   which is VENDORED and must not be edited (CLAUDE.md; enforced by
     *   `guard-no-workdo-changes.sh` in CI). Five are `MarketPlaceController@pricing_plan_*`; the
     *   other three are `Route::resource()` `store` actions whose controllers define
     *   `dedicated_store` / `buildtech_store` / `packagedetails_store` and are separately routed at
     *   `POST dedicated/store` etc. -- so the module itself already serves the intent, and the
     *   resource's `store` slot is upstream's leftover. A host route declaration cannot withdraw
     *   them either, and the usual reason given for that is the wrong one here: `LandingPage` is
     *   loaded STATICALLY by `PackageServiceProvider::register()`, not from the `app->booted()`
     *   path that `loadDynamicAddons()` uses. It makes no difference, because `loadRoutesFrom`
     *   runs in the module provider's `boot()`. MEASURED by registration index in the booted
     *   collection: `routes/web.php`'s last declaration is #1129, `dedicated.store` is #1274 and
     *   `pricing_plan_create` is #1316 -- so the module's declaration lands LAST and, since
     *   `RouteCollection` keys on method+domain+uri, would replace a host declaration of the same
     *   URI rather than be replaced by it. (That is the same mechanism the MyFatoorah withdrawal
     *   USES, in the one direction where it helps: that package declares its routes in
     *   `register()`, at index #4, so `routes/web.php` is later and wins.) The fix for these eight
     *   belongs upstream or in a patch record.
     *
     * Note that these 9 are measured against the HOST route table, which is what this file can
     * see. The LandingPage eight are visible because that module's provider is reachable without
     * an `add_on_managers` row; the other 69 WorkDo modules are not, and their route files are not
     * covered here -- see WHAT THIS DELIBERATELY DOES NOT COVER above.
     *
     * Every one of these is an HTTP 500 for any request that reaches it, and they are PRE-EXISTING:
     * the original list was what the first run found, not breakage it allows. It is asserted in
     * BOTH directions — a new entry fails, and an entry that stops being routed to a missing method
     * fails until it is deleted from here — so the set can shrink and cannot quietly grow.
     *
     * THE PAYMENT INTEGRATION IS OUT (−4, `MyFatoorahController@index|callback|checkout|webhook`),
     * and the reason the register gave for leaving it in turned out to be the wrong reason. Those
     * four routes are NOT declared in `routes/`: they come from
     * `vendor/myfatoorah/laravel-package/src/MyFatoorahServiceProvider::register()`, whose sample
     * controller WorkDo overwrote with their own two-method version. `register()` runs before
     * `routes/web.php`, so a host declaration of the same verb and URI replaces them; they now
     * answer 404, which is the vendored integration's OWN answer while
     * `config('myfatoorah.webhook_secret_key')` is empty — as it ships. **No capture is recorded
     * and no payment record is created or changed**; whether this product should consume MyFatoorah
     * webhooks at all is an open owner decision. Full derivation, and the three live call sites that
     * rule out `dont-discover`, in `MyFatoorahPackageRoutesWithdrawnTest`.
     *
     * HOW THIS WENT UNNOTICED. `route:cache` resolves the controller METHOD lazily at dispatch, so
     * only a missing CLASS fails at cache time — a deploy can cache these routes successfully. The
     * one route found by hand (`place.order`, via the `P01-G2` journey) was additionally orphaned:
     * nothing referenced its name, so no page was visibly broken.
     *
     * @var list<string>
     */
    private const KNOWN_BROKEN = [
        'App\Http\Controllers\AppSettingController@image_delete',
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

<?php

namespace Tests\Unit\Routing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-267. THE WEB `place.order` ROUTE IS DELETED RATHER THAN REPOINTED, AND WHY.
 * =================================================================================================
 *
 * `routes/customer.php` declared
 *
 *     Route::post('{storeSlug?}/place-order', [OrderController::class, 'place_order'])
 *         ->name('place.order');
 *
 * and `App\Http\Controllers\OrderController` has no `place_order` -- the only one under
 * `app/Http/Controllers` is `Api\ApiController::place_order:3402`. Every POST reaching it was an
 * HTTP 500 rather than a checkout. Found by the `P01-G2` journey exercise; it is also one of the 73
 * in `GAP-268`.
 *
 * The register left the choice open -- "delete the route, or point it at the method that exists" --
 * and noted the choice was worth a moment's thought rather than a drive-by edit. It is DELETED, on
 * four measurements.
 *
 *   1. NOTHING CALLS IT. `place.order` is referenced nowhere outside its own declaration, across
 *      app/, resources/, routes/, packages/, themes/, config/, database/ and bootstrap/. The
 *      shipped storefront checkout posts to `payment.process`
 *      (resources/views/front_end/common/checkout/checkout_form.blade.php), which is pinned below
 *      comment-stripped and scoped to that one file.
 *
 *   2. REPOINTING WOULD MOUNT A JSON API HANDLER ON A BROWSER FORM POST.
 *      `Api\ApiController::place_order` answers through `ApiResponser` -- its first branch is
 *      `return $this->error(['message' => __('Store not found.')], ...)`, i.e. JSON with HTTP 200
 *      -- which is not an answer a `<form method="POST">` submission can use.
 *
 *   3. AND IT WOULD MOUNT IT WITHOUT THE AUTHENTICATION IT HAS ALWAYS HAD. Measured middleware:
 *          POST api/{slug}/place-order      [api, custom.auth, APILog]
 *          POST {storeSlug?}/place-order    [web, web, ActiveTheme, themelanguage]
 *      The handler resolves its subject with `resolveStoreCustomer($store)` and refuses when that
 *      returns null, so it DEPENDS on a principal that `custom.auth` logs into the `customers`
 *      guard. The web route establishes no principal at all. Repointing would hand an
 *      order-creation handler a guard state nothing on that route sets up -- and this is the
 *      `auth('customers')`-fallback family that produced 31 measured defects, so it is the last
 *      family to extend to a new, unauthenticated surface for no caller.
 *
 *   4. THERE IS ALREADY A WORKING WEB CHECKOUT. A second order-creation path is a second place for
 *      stock, pricing and payment to diverge.
 *
 * WHY THIS IS DELETED OUTRIGHT WHILE THE FOUR MYFATOORAH ROUTES WERE REPLACED WITH A 404 CLOSURE
 * (`MyFatoorahPackageRoutesWithdrawnTest`) -- the two look inconsistent and are not. Those four are
 * declared by a vendored package's `register()`, so they CANNOT be removed from the table, only
 * replaced; keeping their names cost nothing and removed a `RouteNotFoundException` class. This one
 * is declared here, nothing references its name, so a real deletion is available and is better: the
 * route table shrinks and the 404 is the router's, not a declared `abort()`.
 *
 * NOT TOUCHED: `POST api/{slug}/place-order` and `api/{slug}/place-order-guest`. Both point at the
 * method that exists, both are in the Flutter contract, and the `-guest` suffix does NOT mean
 * public -- `custom.auth` refuses a missing bearer token before the handler runs. Pinned below so
 * this deletion cannot creep into them.
 */
class WebPlaceOrderRouteWithdrawnTest extends TestCase
{
    use StripsSourceComments;

    /** THE DEFECT: no route may answer the web place-order URI with a method that does not exist. */
    #[Test]
    public function test_the_web_place_order_route_is_gone(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('place.order'),
            'the route name `place.order` is declared again. `OrderController::place_order` does '
            . 'not exist, so any POST reaching it is an HTTP 500; and repointing it at '
            . '`Api\ApiController::place_order` would mount a JSON API order-creation handler on '
            . 'an unauthenticated browser POST. Read this class\'s docblock before re-adding it.'
        );

        try {
            $action = Route::getRoutes()
                ->match(Request::create('/some-store/place-order', 'POST'))
                ->getActionName();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());

            return;
        }

        $this->fail(
            'POST {storeSlug}/place-order still resolves, to ' . $action . '. It must 404.'
        );
    }

    /**
     * PREMISE: the shipped checkout posts somewhere else, which is why nothing noticed the 500 and
     * why deleting the route breaks no screen.
     *
     * Comment-stripped and SCOPED TO THE CHECKOUT FORM, not to all of `resources/`: an assertion
     * over the whole tree answers "does this string exist anywhere", which is how a census passes
     * for the wrong reason.
     */
    #[Test]
    public function test_premise_the_shipped_checkout_posts_to_payment_process(): void
    {
        $form = $this->sourceWithoutComments(
            'resources/views/front_end/common/checkout/checkout_form.blade.php'
        );

        $this->assertStringContainsString(
            "'payment.process'",
            $form,
            'the shipped checkout no longer posts to `payment.process`, so the premise that the '
            . 'deleted `place.order` route had no caller needs re-measuring.'
        );

        $this->assertStringNotContainsString(
            "'place.order'",
            $form,
            'the checkout now references `place.order`, a route this change DELETED. That is a '
            . 'RouteNotFoundException at render time -- the whole checkout page, not one button.'
        );
    }

    /** The API place-order endpoints are untouched, authentication included. */
    #[Test]
    public function test_the_api_place_order_endpoints_are_untouched(): void
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'place-order')) {
                continue;
            }

            $middleware = array_map(
                static fn ($m) => is_string($m) ? $m : '(closure)',
                $route->middleware()
            );

            $seen[implode('|', $route->methods()) . ' ' . $route->uri()] = [
                'action' => $route->getActionName(),
                'authenticates' => in_array('custom.auth', $middleware, true),
            ];
        }

        $this->assertSame(
            [
                'POST api/{slug}/place-order' => [
                    'action' => \App\Http\Controllers\Api\ApiController::class . '@place_order',
                    'authenticates' => true,
                ],
                'POST api/{slug}/place-order-guest' => [
                    'action' => \App\Http\Controllers\Api\ApiController::class . '@place_order',
                    'authenticates' => true,
                ],
            ],
            $seen,
            'the set of place-order routes changed. A new one, a lost `custom.auth` (the `-guest` '
            . 'suffix does NOT make a route public -- that middleware is what refuses a missing '
            . 'bearer token), or the web route back again are all failures here.'
        );
    }
}

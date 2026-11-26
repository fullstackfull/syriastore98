<?php

namespace Tests\Unit\Routing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-268's EXPLICITLY-DECLARED REMAINDER, AND THE ONE THAT MUST NOT BE DELETED.
 * =================================================================================================
 *
 * `EveryRouteActionExistsTest` measured 73 actions a route names and no class defines. 4 were a
 * vendored payment package's and 55 were `Route::resource()` filling in actions a controller never
 * implemented. This file covers what was left in `routes/` after those two batches: four routes
 * written out by hand, three of which are dead and one of which is NOT, and the difference between
 * those two groups is the whole content of this file.
 *
 * WITHDRAWN -- dead, measured, and now 404:
 *
 *   GET  add-on/session/create   ModuleController@AddOnSessioncreate   zero references
 *   POST add-on/session/save     ModuleController@AddOnSessionSave     zero references
 *   POST api/{slug}/random-product  Api\ApiController@random_product    zero references, unnamed,
 *                                   and absent from the Flutter manifest
 *   GET  order/all               OrderController@allOrders             UNREACHABLE, see below
 *
 * `order/all` IS THE INTERESTING ONE, because it was never a 500 in practice -- it was never
 * anything. `Route::resource('order', ...)` declares `GET order/{order}` on the line ABOVE it, and
 * Laravel matches in declaration order, so `GET /order/all` bound `{order} = 'all'` and dispatched
 * `OrderController@show`. Pinned below, because the deletion only looks safe once that is measured:
 * had the two declarations been the other way round, deleting it would have taken a live screen
 * with it.
 *
 * NOT WITHDRAWN, AND THE REASON IS PINNED HERE SO NOBODY "FINISHES THE JOB":
 *
 *   POST product-image-delete    AppSettingController@image_delete     name product.image.delete
 *
 * This is the only one of the 73 that is BROKEN IN USE rather than orphaned.
 * `resources/views/AppSetting/mobile_content.blade.php` calls
 * `route('product.image.delete')` inside a `<script>` block, and that view is rendered by
 * `AppSettingController::MobileScreenContent()`. `route()` on an undeclared name throws
 * `RouteNotFoundException` at RENDER time, so deleting this route would turn one broken AJAX click
 * into an HTTP 500 for the whole Mobile Screen Content page -- strictly worse than the defect.
 *
 * It therefore needs `image_delete()` IMPLEMENTED on `AppSettingController`, which is neither a
 * route change nor a small one: a handler that unlinks a path named by `$request->image` is the
 * exact shape of P1-SEC-71 and of what `WebSettingsSecretsTest` guards -- an arbitrary-path delete
 * reachable across tenants. It stays in `EveryRouteActionExistsTest::KNOWN_BROKEN` with that
 * reason, and this file asserts BOTH halves of why, so the entry cannot be retired by deleting the
 * route and cannot be forgotten either.
 */
class DeadControllerRoutesWithdrawnTest extends TestCase
{
    use StripsSourceComments;

    private function actionFor(string $verb, string $uri): string
    {
        return Route::getRoutes()->match(Request::create('/' . ltrim($uri, '/'), $verb))->getActionName();
    }

    /**
     * PREMISE for the `order/all` deletion: it was shadowed, so it could never run. Asserted
     * rather than asserted-away, because this is the fact that makes the deletion a no-op.
     */
    #[Test]
    public function test_premise_order_all_is_shadowed_by_the_resource_show_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/order/all', 'GET'));

        $this->assertSame(
            'order/{order}',
            $route->uri(),
            'GET /order/all no longer matches the resource `show` route. The deleted '
            . '`Route::get(\'order/all\', ...)` was unreachable BECAUSE `order/{order}` is '
            . 'declared above it; if that ordering has changed, re-measure before trusting this '
            . 'file\'s account of the deletion.'
        );

        $this->assertSame(
            \App\Http\Controllers\OrderController::class . '@show',
            $route->getActionName(),
            'GET /order/all dispatches something other than OrderController@show.'
        );
    }

    /**
     * THE DEFECT. None of the four hand-written routes may dispatch a method that does not exist.
     *
     * A per-URI verdict is recorded and the tally asserted, so a loop that examines nothing cannot
     * read as success -- the failure mode that made the first GAP-268 ratchet pass against 0 of
     * 1,500 routes.
     */
    #[Test]
    public function test_no_withdrawn_route_still_dispatches_a_missing_method(): void
    {
        $verdicts = [];

        foreach ([
            'GET add-on/session/create' => ['GET', 'add-on/session/create'],
            'POST add-on/session/save' => ['POST', 'add-on/session/save'],
            'POST api/{slug}/random-product' => ['POST', 'api/any-store/random-product'],
            'GET order/all' => ['GET', 'order/all'],
        ] as $label => [$verb, $uri]) {
            try {
                $action = $this->actionFor($verb, $uri);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $verdicts[$label] = 'no route (' . $e->getStatusCode() . ')';

                continue;
            }

            $verdicts[$label] = str_contains($action, '@') && ! method_exists(...explode('@', $action, 2))
                ? 'HTTP 500: ' . $action . ' DOES NOT EXIST'
                : 'serveable: ' . $action;
        }

        $this->assertCount(4, $verdicts, 'fewer than four routes were examined.');

        $this->assertSame(
            [
                /* 404 -- the declaration is gone and nothing else answers these URIs. */
                'GET add-on/session/create' => 'no route (404)',
                'POST add-on/session/save' => 'no route (404)',
                'POST api/{slug}/random-product' => 'no route (404)',
                /* NOT 404: `order/{order}` still answers this URI, as it always did. */
                'GET order/all' => 'serveable: ' . \App\Http\Controllers\OrderController::class . '@show',
            ],
            $verdicts,
            'a withdrawn route is back, or is dispatching a method that does not exist.'
        );
    }

    /**
     * THE ONE THAT STAYED, AND ALL THREE HALVES OF WHY -- NOW WITH THE THIRD ONE INVERTED.
     *
     * This case was written to require that the entry could be retired only by FIXING it, and it
     * has been: `AppSettingController::image_delete()` exists, so the third assertion flips from
     * `assertFalse(method_exists(...))` to `assertTrue(...)`. That inversion is the test doing its
     * job, not the test being edited to agree with the code -- the alternative it was guarding
     * against was somebody deleting the route to clear the entry, which the first two assertions
     * still forbid.
     *
     * Half one: the route is still declared -- deleting it breaks a whole page at render time,
     * because `route()` on an undeclared name throws while the view is RENDERING.
     * Half two: a rendered view really does call `route()` on its name. Comment-stripped, because
     * the name is spelled out in this file's docblock and in the routes-file comment, and a census
     * that counted prose would keep passing after the real call was deleted.
     * Half three: the method exists. The handler's own five layers are driven and mutated by
     * `Tests\Feature\Security\AppSettingImageDeleteDoorTest`; this one only asserts that the
     * route and the method still meet.
     */
    #[Test]
    public function test_the_product_image_delete_route_is_deliberately_kept(): void
    {
        $route = Route::getRoutes()->getByName('product.image.delete');

        $this->assertNotNull(
            $route,
            'product.image.delete has been DELETED. `resources/views/AppSetting/mobile_content.'
            . 'blade.php` calls route() on that name inside a rendered view, so removing it turns '
            . 'one broken AJAX click into an HTTP 500 for the whole Mobile Screen Content page. '
            . 'Implement AppSettingController::image_delete() instead -- and read '
            . 'WebSettingsSecretsTest first, because an unlink keyed on request input is the '
            . 'P1-SEC-71 shape.'
        );

        $this->assertStringContainsString(
            "route('product.image.delete')",
            $this->sourceWithoutComments('resources/views/AppSetting/mobile_content.blade.php'),
            'nothing calls route(\'product.image.delete\') any more, so the reason this route '
            . 'could not be deleted has gone with it. Re-measure: if the caller is really gone, '
            . 'the route can be withdrawn like the other three and its KNOWN_BROKEN entry deleted.'
        );

        $this->assertTrue(
            method_exists(\App\Http\Controllers\AppSettingController::class, 'image_delete'),
            'AppSettingController::image_delete() has GONE AGAIN, so `product.image.delete` is '
            . 'back to raising BadMethodCallException on every request -- a guaranteed HTTP 500 '
            . 'behind a button in a rendered view. Restore the method; do not delete the route to '
            . 'make this green, which is what the two assertions above forbid.'
        );
    }
}

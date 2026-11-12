<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * P2.5-PKG-C (F-C-02) — `GET /order/{order}` refused EVERY caller, the owner included.
 * ================================================================================================
 *
 * `OrderDocumentAccessGuard` is appended to the `web` group (`bootstrap/app.php:101`), so it runs
 * AFTER `SubstituteBindings`. `order/{order}` is one of its twenty targets, and its handler was
 * declared `show(Order $order, $id)` — a typed Eloquent parameter whose name matches the route
 * segment, so binding replaced the value with an `Order` INSTANCE. `resolveOrderId()` then hit
 * `if (! is_string($raw)) return null;` (`OrderDocumentAccessGuard.php:405`) and the guard
 * `abort(403)`d for everyone.
 *
 * MEASURED: 403, with no permission middleware on the route at all.
 *
 * THE DIRECTION WAS SAFE — it refused rather than admitted — so this was never an exposure. The
 * surface was simply dead.
 *
 * WHY THE FIX IS IN THE CONTROLLER AND NOT THE GUARD, deliberately. The obvious change is to let
 * `resolveOrderId()` read a bound model's key. I did not do that: it loosens an input check in a
 * security middleware to accommodate one caller, and the bound parameter turned out to be DEAD
 * WEIGHT — `show()` overwrites `$order` on its very next statement
 * (`$order = Order::order_detail($id);`) and never reads the bound instance. Removing an unused
 * parameter is a smaller change than widening a guard, and it leaves the guard strict.
 *
 * AND `order/{order}` IS THE ONLY MODEL-BOUND TARGET of the twenty — every other one's segment is
 * a plain `{id}` (or `{refund_request}`, untyped in its controller). So the last test here is a
 * ratchet: if a future target is model-bound, it would silently 403 exactly as this one did, and
 * fail-closed-but-invisible is how this defect survived. It now fails CI instead.
 */
class OrderDocumentGuardBoundParameterTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_a_merchant_reaches_the_order_resource_show_route_for_their_own_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $order = $this->makeOrder($store);

        $this->actingAs($merchant)
            ->get('/order/' . \Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    /**
     * THE POSITIVE CONTROL'S COUNTERPART, and the reason this fix cannot be a quiet widening: the
     * guard must STILL refuse another tenant's order on the same route. If dropping the bound
     * parameter had opened the route, this is what would say so.
     */
    public function test_the_guard_still_refuses_another_tenants_order_on_that_route(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Order');

        $foreign = $this->makeOrder($victimStore);

        $this->actingAs($attacker)
            ->get('/order/' . \Crypt::encrypt($foreign->id))
            ->assertStatus(403);
    }

    /** The sibling route that already worked must keep working. */
    public function test_the_order_view_modal_route_still_works(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $order = $this->makeOrder($store);

        $this->actingAs($merchant)
            ->get('/orders/order_view/' . \Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    /**
     * THE RATCHET. No `OrderDocumentAccessGuard` target may be route-model-bound on the parameter
     * the guard reads, because `resolveOrderId()` requires a string and a bound model silently
     * becomes a 403 for every caller.
     *
     * It asserts a floor on how many targets it examined, because a reflection walk that resolves
     * nothing would pass while proving nothing — the failure mode three censuses in this project
     * have already had.
     *
     * TWO LIMITATIONS, both empty today and neither KEPT empty by this test. Raised by the
     * tenant-authz review, and stated here rather than left for the next reader to discover:
     *
     *   1. NO WORKDO MODULE LOADS in a test run (CLAUDE.md §1), so the nine module-declared guard
     *      targets are never examined here. The reviewer read all nine handlers directly --
     *      LotteryPrizes/LotteryOrderController:101,174,201;
     *      AuctionProduct/AuctionOrdersController:119,270,289;
     *      AutomaticOrderPrinting/…Controller:95,228,312 -- and every one takes an untyped `$id`.
     *      So "order/{order} was the only model-bound GET target" holds across the modules too,
     *      but by READING, not by this assertion.
     *   2. IT DETECTS IMPLICIT BINDING ONLY, via a type hint. An explicit
     *      `Route::model('order', Order::class)` would reproduce the identical silent 403 and pass
     *      this test. There is no `Route::model(` anywhere in the repository today -- the only
     *      `Route::bind()` is for `storeSlug`, a parameter no target reads -- so it is theoretical,
     *      not a gap left open.
     */
    public function test_no_guard_target_is_route_model_bound_on_the_parameter_it_reads(): void
    {
        $targets = (new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class))
            ->getConstant('TARGETS');

        $this->assertNotEmpty($targets, 'TARGETS came back empty, so this test proves nothing.');

        $examined = 0;
        $bound = [];

        foreach (Route::getRoutes() as $route) {
            $spec = $targets[$route->uri()] ?? null;

            if ($spec === null || ($spec['param'] ?? null) === null) {
                continue;
            }

            // GET ONLY, MIRRORING THE GUARD'S OWN CONDITION at
            // `OrderDocumentAccessGuard::handle()`: `if ($target === null || ! in_array('GET',
            // $route->methods(), true)) { return $next($request); }`. The guard is INERT on every
            // other verb, so a bound model there cannot produce the 403 this test is about.
            //
            // Without this the first run of this ratchet reported `DELETE order/{order}` ->
            // `OrderController::destroy(Order $order)` as an offender. That binding is correct and
            // deliberate -- `destroy()` genuinely uses the model -- and "fixing" it would have been
            // work done for no reason, on the authority of a test that was asserting something
            // wider than the defect. Narrowed to what the guard actually inspects.
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;
            }

            $examined++;

            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                if ($parameter->getName() !== $spec['param']) {
                    continue;
                }

                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType
                    && ! $type->isBuiltin()
                    && class_exists($type->getName())
                    && is_subclass_of($type->getName(), Model::class)) {
                    $bound[] = sprintf(
                        '%s (%s) -- %s::%s($%s) is typed %s',
                        $route->uri(),
                        implode('|', $route->methods()),
                        class_basename($class),
                        $method,
                        $parameter->getName(),
                        class_basename($type->getName())
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            5,
            $examined,
            'Fewer guard targets were examined than the measured floor, so this test proved '
            .'nothing. Either the route table did not load or TARGETS stopped matching route uris.'
        );

        $this->assertSame(
            [],
            $bound,
            "An OrderDocumentAccessGuard target is route-model-bound on the parameter the guard "
            ."reads. `SubstituteBindings` runs BEFORE the guard, so the guard receives a MODEL and "
            .'`resolveOrderId()` rejects it at the `! is_string($raw)` check -- the route then '
            ."answers 403 to EVERY caller, the legitimate owner included. Fail-closed, so not an "
            ."exposure, but the surface is dead and nothing says so. Either drop the type hint "
            ."(the parameter is usually unused) or give the guard an explicit way to read a bound "
            ."key. Offenders:\n  - " . implode("\n  - ", $bound)
        );
    }
}

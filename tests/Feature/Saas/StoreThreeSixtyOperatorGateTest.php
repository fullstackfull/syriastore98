<?php

namespace Tests\Feature\Saas;

use App\Http\Controllers\StoreThreeSixtyController;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ADM-002 / GAP-232 — WHO MAY OPEN STORE 360°, ASSERTED AT BOTH LAYERS.
 * =================================================================================================
 *
 * TWO LAYERS, AND THE SECOND IS THE WHOLE POINT OF THIS FILE. CLAUDE.md:
 *
 *   > A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST. The
 *   > middleware answers 403 and the handler is never reached, so the route returns 403 whether or
 *   > not the handler checks anything — `PlanController::planDisable` ADMITTED a `Customer` typed
 *   > `super admin` (200, plan disabled) behind a gate whose HTTP tests were green (GAP-307).
 *   > Call the handler directly.
 *
 * So every refusal here is asserted TWICE: once by driving the uri (which proves the route carries
 * the middleware) and once by calling `index()`/`show()` on a fresh controller with the principal
 * signed in (which proves the handler refuses on its own, with the middleware out of the picture).
 * A green HTTP block alone would say nothing about the second check.
 *
 * EXACTLY 403, NEVER `assertNotSame(200, ...)`. `PlatformOperatorOnlyPrincipalTest` records that
 * the weaker assertion is satisfied by a 404 from `SubstituteBindings` — which runs in the `web`
 * group and therefore BEFORE a route's own middleware — so a test could be green against a route
 * whose guard was never reached. Both routes here are driven with a store that EXISTS, for that
 * reason.
 *
 * AND 403 RATHER THAN 401 IS A PRODUCT CONSTRAINT, not a preference: the shipped Flutter app's
 * `lib/network/network.dart` maps any 401 to a forced logout that clears stored preferences.
 */
final class StoreThreeSixtyOperatorGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        [, $this->store] = $this->makeTenant();

        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    /** Both uris, with a store that exists so a 404 can never stand in for a 403. */
    private function uris(): array
    {
        return [
            'roster' => '/platform/stores',
            'one store' => '/platform/stores/' . $this->store->id,
        ];
    }

    #[Test]
    public function test_control_the_platform_operator_reaches_both_screens(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach ($this->uris() as $name => $uri) {
            $this->actingAs(User::find($operator->id))
                ->get($uri)
                ->assertOk();
        }

        $this->assertSame(['roster', 'one store'], array_keys($this->uris()));
    }

    #[Test]
    public function test_an_ordinary_merchant_is_refused_at_the_route(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        foreach ($this->uris() as $name => $uri) {
            $this->actingAs(User::find($merchant->id))
                ->get($uri)
                ->assertStatus(403, 'a merchant reached ' . $name);
        }
    }

    /**
     * STAFF INSIDE A TENANT, which is the principal a permission-shaped gate would have admitted:
     * a merchant creates their own roles, so any permission name is substantially self-assignable.
     * This one is granted `Manage User` — the permission the operator's own `Users` sidebar entry
     * is gated on — precisely to show that holding it buys nothing here.
     */
    #[Test]
    public function test_tenant_staff_holding_a_real_permission_are_refused(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Manage User');

        $staff = User::find($staff->id);
        $this->assertTrue($staff->isAbleTo('Manage User'),
            'the premise failed: the fixture does not actually hold the permission, so the '
            . 'refusal below would prove nothing about permissions.');

        foreach ($this->uris() as $name => $uri) {
            $this->actingAs($staff)->get($uri)->assertStatus(403, 'staff reached ' . $name);
        }
    }

    /**
     * THE `Customer` TYPED `super admin` — GAP-307's principal.
     *
     * `customers` has a `type` column with `'type'` in `Customer::$fillable`, and
     * `UserPolicy::isOperator()` takes an UNTYPED `$actor` and reads `->type`. So the refusal has
     * to come from the `instanceof User` clause, in the middleware AND in the handler.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused_at_the_route(): void
    {
        $impostor = $this->impostor();

        $this->assertSame('super admin', $impostor->type,
            'the premise failed: the fixture is not actually typed as the operator.');

        foreach ($this->uris() as $name => $uri) {
            $this->actingAs($impostor, 'customers')
                ->get($uri)
                ->assertStatus(403, 'a customer typed `super admin` reached ' . $name);
        }
    }

    /**
     * THE HANDLER ON ITS OWN. No middleware in the stack: the controller is constructed and its
     * methods are called, so what refuses here is `refuseNonOperator()` and nothing else.
     *
     * `abort(403)` raises `HttpException`, so the assertion is on the thrown status rather than on
     * a response — and it is keyed on the REASON as well, because CLAUDE.md records that a handler's
     * own "not found" branch can produce the same status as its gate and mean the opposite.
     */
    #[Test]
    public function test_each_handler_refuses_every_non_operator_on_its_own(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);
        $staff = $this->makeStaff($merchant);

        $principals = [
            'merchant' => [User::find($merchant->id), 'web'],
            'staff' => [User::find($staff->id), 'web'],
            'customer typed super admin' => [$this->impostor(), 'customers'],
        ];

        foreach ($principals as $label => [$principal, $guard]) {
            foreach (['index', 'show'] as $method) {
                $this->actingAs($principal, $guard);

                $thrown = null;

                try {
                    $controller = new StoreThreeSixtyController();
                    $request = Request::create('/platform/stores', 'GET');

                    $method === 'index'
                        ? $controller->index($request)
                        : $controller->show($request, $this->store);
                } catch (HttpException $e) {
                    $thrown = $e;
                }

                $this->assertNotNull($thrown,
                    sprintf('%s::%s() did not refuse a %s when called directly — the middleware '
                        . 'was hiding a handler that checks nothing.', 'StoreThreeSixtyController',
                        $method, $label));

                $this->assertSame(403, $thrown->getStatusCode(),
                    sprintf('%s() refused a %s with the wrong status.', $method, $label));

                $this->assertSame('Permission denied.', $thrown->getMessage(),
                    sprintf('%s() refused a %s for some reason other than the gate — a 403 from '
                        . 'elsewhere would satisfy the status assertion above and mean something '
                        . 'different.', $method, $label));
            }
        }
    }

    /** CONTROL for the direct-call block: the operator is refused by neither handler. */
    #[Test]
    public function test_control_each_handler_admits_the_operator_when_called_directly(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->actingAs(User::find($operator->id));

        $controller = new StoreThreeSixtyController();
        $request = Request::create('/platform/stores', 'GET');

        $this->assertNotNull($controller->index($request));
        $this->assertNotNull($controller->show($request, $this->store));
    }

    /**
     * THE ANONYMOUS CASE BELONGS TO `auth`, WHICH RUNS FIRST, so the answer is a sign-in redirect
     * and NOT a 403. Asserted because the opposite would be a regression in repo B: a 401 forces a
     * logout there, and a 403 on a signed-out caller would also mean the gate had taken over a
     * decision `auth` owns.
     */
    #[Test]
    public function test_an_anonymous_caller_is_sent_to_sign_in_rather_than_refused(): void
    {
        foreach ($this->uris() as $name => $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }

    /**
     * PREMISE: both routes really carry the gate. Without this, every refusal above could be
     * coming from somewhere else entirely.
     */
    #[Test]
    public function test_premise_both_routes_carry_the_platform_operator_gate(): void
    {
        foreach (['platform.stores.index', 'platform.stores.show'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name . ' is not registered');

            $carries = false;

            foreach (array_filter($route->gatherMiddleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            $this->assertTrue($carries, $name . ' does not carry PlatformOperatorOnly');
        }
    }

    /**
     * PREMISE, AND IT IS THE MEASUREMENT THAT DECIDED WHERE THESE ROUTES LIVE. `stores.show` is
     * the obvious home for a per-store operator screen and it is an EMPTY STUB behind no gate:
     * `GET /stores/{id}` answers 200 with a zero-length body for a merchant as well as for the
     * operator. Pinned here so that if somebody ever fills it, this test says so and the question
     * of its gate is asked before a cross-tenant read ships behind `verified` alone.
     */
    #[Test]
    public function test_premise_stores_show_is_still_an_ungated_empty_stub(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        $response = $this->actingAs(User::find($merchant->id))->get('/stores/' . $this->store->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getContent(),
            '`StoreController::show()` now returns content. It is reached by any verified user '
            . 'with no operator check and no permission, and `stores/{store}` is declared twice '
            . 'in routes/web.php. Decide its gate before it reads another tenant.');
    }

    private function impostor(): Customer
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        return Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'type' => 'super admin',
        ]);
    }
}

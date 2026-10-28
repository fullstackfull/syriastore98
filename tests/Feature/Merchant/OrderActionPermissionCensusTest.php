<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-10 / WS E7 — every order action is HIDDEN and REFUSED. Hiding alone is not the requirement.
 * =================================================================================================
 *
 * The ledger's own note on this row is why it exists: the enforcement is *already* in the code, via
 * `OrderPolicy` and `OrderController::mayUseOrderScreen()` — and **no test asserted it**. An
 * unasserted control is one refactor away from being an absent one, and this file is the assertion.
 *
 * THE CENSUS IS EXHAUSTIVE, which is the only version of this test worth having. A list of the
 * actions someone remembered is how `order_grid_view` ended up gated on the wrong permission for
 * two years. `ACTIONS` below is built from `routes/web.php`, and
 * `test_the_census_covers_every_order_route` fails when a route is added to the application and not
 * to this list — so a new order action cannot land ungated and unnoticed.
 *
 * WHAT THE CENSUS FOUND — three defects, and the two worst were not the one it was looking for.
 *
 * 1. `order_grid_view`, a view whose only content is ORDERS, was gated on
 *    `isAbleTo('Manage Products')`. A staff member holding `Manage Products` and no order
 *    permission saw every order in the store — while being REFUSED the order list itself, so the
 *    same principal could not open the list and could open a grid of the same rows. A staff member
 *    holding `Manage Order` was refused the Grid View button that `order/index.blade.php:18`
 *    renders for them. And `isAbleTo()` alone refuses the platform operator. Intra-tenant: the
 *    store predicate is real, so what breaks is privilege separation inside one store.
 *
 * 2. `create()` had NO gate at all, and the gate being ABSENT rather than mis-named is the point:
 *    a staff user with no role and no permission whatsoever got 200. The form it renders lists
 *    every active customer with their EMAIL ADDRESS and every product with its PRICE.
 *
 * 3. `store()` — the same, on a WRITE. It inserts an order carrying `final_price` and
 *    `payment_status`. Measured on the database: 0 rows to 1, driven by a principal with no role.
 *
 * All three survived an independent attempt to refute them: four reviewers, 0 of 4 refuted, two
 * reproducing the write in isolated checkouts. The corrections they returned made the claims worse
 * rather than weaker, and are folded in above.
 *
 * TWO HALVES, BOTH ASSERTED, because the acceptance says so explicitly:
 *
 *   * REFUSED — the handler denies an unpermitted principal, and does not merely render less.
 *   * HIDDEN — the surface that links to it does not offer it. On this screen that follows from
 *     the first: every link lives inside `order.index`, which itself requires `Manage Order`, so
 *     once each action agrees with `index()` about the permission the link cannot be offered to
 *     someone the action would refuse. `test_the_grid_link_is_only_offered_to_someone_who_may_open_it`
 *     is that chain, driven rather than argued.
 */
class OrderActionPermissionCensusTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every order action a merchant can reach, with the permission that opens it.
     *
     * `method`, `uri` (with `{id}` substituted), `permission`, and `wrong` — a permission that
     * must NOT open it, which is what catches a gate wired to the wrong name rather than to no
     * name at all.
     */
    private const ACTIONS = [
        // method, uri, required permission, a permission that must NOT open it, id encoding.
        //
        // THE ENCODING IS NOT A DETAIL. `order_view` calls `Crypt::decrypt($id)` while
        // `order_preview` calls `filter_var($id, FILTER_VALIDATE_INT)` and 404s on anything else
        // -- BEFORE its permission check runs. The first draft of this census passed a Crypt
        // string to both, so every `order-preview` case 404'd for every principal and asserted
        // NOTHING: refused, served and operator-locked-out all looked identical. Caught by
        // probing the statuses rather than by the suite going red, which is exactly how the other
        // false readings in this phase were caught.
        ['get',  'order',                  'Manage Order',  'Manage Products',         'none'],
        ['get',  'order-grid',             'Manage Order',  'Manage Products',         'none'],
        ['get',  'order/export',           'Manage Order',  'Manage Products',         'none'],
        ['get',  'order-view/{id}',        'Show Order',    'Manage Products',         'crypt'],
        ['get',  'order-preview/{id}',     'Show Order',    'Manage Products',         'raw'],
        // Manual order entry. A write-intent form, so it takes `index()`'s own permission rather
        // than the read-only `Show Order`.
        ['get',  'order/create',           'Manage Order',  'Manage Product Category', 'none'],
    ];

    private User $merchant;
    private Store $store;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $this->order = $this->makeOrder($this->store);
    }

    private function uriFor(string $uri, string $encoding): string
    {
        $id = match ($encoding) {
            'crypt' => \Illuminate\Support\Facades\Crypt::encrypt($this->order->id),
            'raw' => (string) $this->order->id,
            default => '',
        };

        return str_replace('{id}', $id, $uri);
    }

    /**
     * PREMISE FOR EVERY CASE BELOW: each censused URI must reach its handler's PERMISSION check
     * for a permitted principal, rather than 404ing on a malformed id first. Without this the
     * refuse/serve/operator cases can all pass while measuring nothing -- which is what the first
     * draft did for both `{id}` rows.
     */
    public function test_every_censused_uri_reaches_its_handler(): void
    {
        foreach (self::ACTIONS as [$method, $uri, $permission, , $encoding]) {
            $staff = $this->staffWith($permission);

            $status = $this->actingAs($staff)->{$method}($this->uriFor($uri, $encoding))->getStatusCode();

            $this->assertNotSame(404, $status,
                strtoupper($method) . ' ' . $uri . ' answered 404 for a holder of `' . $permission
                . '`, so every permission case on this row is vacuous -- check the id encoding '
                . 'against what the handler actually parses');
        }
    }

    private function staffWith(?string $permission): User
    {
        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $this->store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** PREMISE: the census names routes that really exist. Otherwise every case below is vacuous. */
    public function test_every_censused_route_exists(): void
    {
        foreach (self::ACTIONS as [$method, $uri, , , $encoding]) {
            $this->assertTrue(
                collect(\Illuminate\Support\Facades\Route::getRoutes())
                    ->contains(fn ($r) => $r->uri() === $uri && in_array(strtoupper($method), $r->methods(), true)),
                strtoupper($method) . ' ' . $uri . ' is censused and does not exist'
            );
        }
    }

    /** REFUSED: a staff member with no order permission is denied every one of them. */
    public function test_every_order_action_refuses_a_principal_without_its_permission(): void
    {
        $staff = $this->staffWith('Manage Product Category');

        foreach (self::ACTIONS as [$method, $uri, $permission, , $encoding]) {
            $response = $this->actingAs($staff)->{$method}($this->uriFor($uri, $encoding));

            $this->assertTrue(
                in_array($response->getStatusCode(), [302, 403], true),
                strtoupper($method) . ' ' . $uri . ' served a principal who does not hold `'
                . $permission . '` (status ' . $response->getStatusCode() . ')'
            );
        }
    }

    /**
     * AND NOT BY THE WRONG PERMISSION, which is what found the real defect: a gate wired to a name
     * from another domain refuses nobody who happens to hold that name.
     */
    public function test_no_order_action_is_opened_by_an_unrelated_permission(): void
    {
        foreach (self::ACTIONS as [$method, $uri, $permission, $wrong, $encoding]) {
            $staff = $this->staffWith($wrong);

            $response = $this->actingAs($staff)->{$method}($this->uriFor($uri, $encoding));

            $this->assertTrue(
                in_array($response->getStatusCode(), [302, 403], true),
                strtoupper($method) . ' ' . $uri . ' was opened by `' . $wrong . '`, which is not '
                . 'an order permission -- it should require `' . $permission . '` '
                . '(status ' . $response->getStatusCode() . ')'
            );
        }
    }

    /** THE COUNTERPART: a principal who holds the permission is served. */
    public function test_every_order_action_serves_a_principal_who_holds_its_permission(): void
    {
        foreach (self::ACTIONS as [$method, $uri, $permission, , $encoding]) {
            $staff = $this->staffWith($permission);

            $response = $this->actingAs($staff)->{$method}($this->uriFor($uri, $encoding));

            $this->assertNotSame(403, $response->getStatusCode(),
                strtoupper($method) . ' ' . $uri . ' refused a principal holding `' . $permission . '`');

            if ($response->getStatusCode() === 302) {
                $this->assertStringNotContainsString('Permission denied',
                    (string) session('error'),
                    strtoupper($method) . ' ' . $uri . ' redirected a holder of `' . $permission
                    . '` with a permission error');
            }
        }
    }

    /**
     * THE PLATFORM OPERATOR, because `isAbleTo()` REFUSES them. A gate written as a bare
     * `isAbleTo()` locks the operator out of their own platform, and this codebase records that
     * rule in three places. `order_grid_view` was exactly that shape.
     */
    public function test_no_order_action_locks_out_the_platform_operator(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);

        foreach (self::ACTIONS as [$method, $uri, , , $encoding]) {
            $response = $this->actingAs($operator)->{$method}($this->uriFor($uri, $encoding));

            $this->assertNotSame(403, $response->getStatusCode(),
                strtoupper($method) . ' ' . $uri . ' refused the platform operator');

            if ($response->getStatusCode() === 302) {
                $this->assertStringNotContainsString('Permission denied', (string) session('error'),
                    strtoupper($method) . ' ' . $uri . ' locked out the platform operator -- a bare '
                    . 'isAbleTo() without the operator-first check');
            }
        }
    }

    /**
     * HIDDEN, and it is a CHAIN rather than a separate gate: every order action is linked from
     * `order.index`, which itself requires `Manage Order`. So an action that agrees with `index()`
     * cannot be offered to someone it would refuse. Driven rather than argued.
     */
    public function test_the_grid_link_is_only_offered_to_someone_who_may_open_it(): void
    {
        $withOrders = $this->staffWith('Manage Order');

        $index = $this->actingAs($withOrders)->get('order');

        $this->assertSame(200, $index->getStatusCode(),
            'premise: a holder of Manage Order reaches the order list');
        $this->assertStringContainsString('order-grid', (string) $index->getContent(),
            'the Grid View link is not offered to someone who may use it');

        // And the same principal may actually open it -- the half that was broken.
        $this->assertNotSame(403,
            $this->actingAs($withOrders)->get('order-grid')->getStatusCode());

        // While a principal who cannot reach the list is never shown the link at all.
        $withoutOrders = $this->staffWith('Manage Product Category');

        $refusedIndex = $this->actingAs($withoutOrders)->get('order');

        $this->assertNotSame(200, $refusedIndex->getStatusCode(),
            'a principal without the order permission reached the order list');
    }

    // ---------------------------------------------------------------------------------------
    //  THE WRITE. Measured on the DATABASE, not on a status code.
    // ---------------------------------------------------------------------------------------

    /**
     * MANUAL ORDER ENTRY HAD NO PERMISSION GATE, and this is the worst of the three defects this
     * census found: `OrderController::store()` validates its input, opens a transaction and
     * INSERTS AN ORDER, with nothing asking whether the principal may.
     *
     * DEMONSTRATED RATHER THAN INFERRED: driven with a staff member holding only
     * `Manage Product Category`, the orders table went from 0 rows to 1. A status code would not
     * have shown it — the handler redirects either way, so `assertStatus` could not tell an
     * accepted write from a rejected one. The row count can.
     */
    public function test_an_order_write_is_refused_and_does_not_land(): void
    {
        $staff = $this->staffWith('Manage Product Category');

        $before = Order::withoutGlobalScopes()->count();

        $this->actingAs($staff)->post('order', $this->validOrderPayload());

        $this->assertSame($before, Order::withoutGlobalScopes()->count(),
            'a principal with no order permission CREATED an order. Counted through '
            . 'withoutGlobalScopes() because Order is not in config/tenancy.php\'s scoped_models, '
            . 'so a plain count() would answer against whichever tenant happens to be resolved.');
    }

    /** THE COUNTERPART: a principal who may create orders still can. */
    public function test_a_permitted_principal_can_still_create_an_order(): void
    {
        $staff = $this->staffWith('Manage Order');

        $before = Order::withoutGlobalScopes()->count();

        $this->actingAs($staff)->post('order', $this->validOrderPayload());

        $this->assertSame($before + 1, Order::withoutGlobalScopes()->count(),
            'the gate refused a principal who holds Manage Order, which breaks manual order entry');
    }

    /** And the platform operator, who holds no merchant role at all. */
    public function test_the_operator_can_still_create_an_order(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->store->id;
        $operator->save();

        $before = Order::withoutGlobalScopes()->count();

        $this->actingAs(User::find($operator->id))->post('order', $this->validOrderPayload());

        $this->assertSame($before + 1, Order::withoutGlobalScopes()->count(),
            'the platform operator was locked out of manual order entry -- a bare isAbleTo()');
    }

    /** @return array<string, mixed> */
    private function validOrderPayload(): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'census', 'slug' => 'census-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 9, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
        ]);

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Census', 'last_name' => 'Buyer',
            'email' => 'census' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
        ]);

        return [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'final_price' => 10,
            'first_name' => 'Census', 'last_name' => 'Buyer',
            'email' => 'census@example.test', 'telephone' => '1',
            'address' => 'x', 'city' => 'y', 'state' => 'z', 'country' => 'q',
        ];
    }

    /**
     * THE RATCHET. A new order route must be censused, or this fails. Without it the file is a
     * snapshot of what someone remembered rather than a statement about the application.
     */
    public function test_the_census_covers_every_order_route(): void
    {
        $censused = array_map(fn ($a) => strtoupper($a[0]) . ' ' . $a[1], self::ACTIONS);

        // The write is censused by its own three tests above rather than by the ACTIONS table,
        // because a status code cannot tell an accepted write from a rejected one here -- the
        // handler redirects either way -- so those tests count rows instead.
        $censused[] = 'POST order';

        // Routes whose guard is elsewhere, each with the reason. Not a list of exceptions to the
        // requirement -- a list of where the requirement is met by a different mechanism.
        $guardedElsewhere = [
            // OrderDocumentAccessGuard (bootstrap/app.php:101) owns the order documents, by
            // route-uri table: :185, :232, :243.
            'GET shippinglabel/pdf/{id}',
            'GET order-receipt/{id}',
            // Writes, each carrying authorize() on an OrderPolicy ability.
            'POST order-assign',
            'POST order-status-change/{id}',
            'POST order-payment-status',
            'POST update-order-status/{id}',
            'POST order-return',
            'POST order-return-request',
            // Storefront/customer surfaces, not merchant order actions. These are the SHOPPER's
            // checkout and order-tracking pages, reached under the store slug by a customer or a
            // guest -- a different principal, a different guard family (the `customers` session
            // guard and ApiController::resolveStoreCustomer), and Phase 03's subject rather than
            // this row's. Listed rather than filtered by pattern so that a NEW storefront route
            // still has to be looked at once by a person.
            'GET orders/order_view/{id}',
            'GET order/all',
            'POST {storeSlug?}/place-order',
            'POST {storeSlug?}/applycoupon',
            'GET {storeSlug?}/paymentlist',
            'GET {storeSlug?}/additionalnote',
            'POST {storeSlug?}/status-cancel',
            'GET {storeSlug?}/order/{id}',
            'GET {storeSlug?}/order-complete',
            // `Route::resource('order')` declares the full seven. `edit` and `update` have NO
            // METHOD on the controller, so they answer with a framework error rather than
            // reaching any code -- there is nothing to gate. `store` DOES exist (line 68) and is
            // censused above by `test_an_order_write_is_refused_and_does_not_land`; a first pass
            // of this census reported it absent because the grep pattern assumed four-space
            // indentation and `store()` is declared at column 1.
            'GET order/{order}/edit',
            'PUT order/{order}',
            'PATCH order/{order}',
            // `destroy` carries authorize('delete') on an OrderPolicy ability.
            'DELETE order/{order}',
            // `show` is a SECOND URI on an already-guarded handler: OrderDocumentAccessGuard's URI
            // table carries `order/{order}` explicitly (:280, param 'order'), so row ownership is
            // enforced in middleware. Measured: an unpermitted staff member gets 403.
            'GET order/{order}',
        ];

        $found = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            // EXACT CLASS, not a substring. `str_contains($action, 'OrderController@')` also matched
            // `DeliverySyriaOrderController@updateStatus` -- a delivery-provider webhook in another
            // controller entirely -- so the first run of this census reported a route that is not
            // this controller's at all. A census whose matcher is loose reports work that does not
            // exist, which costs exactly as much attention as missing work that does.
            if (! str_starts_with($action, \App\Http\Controllers\OrderController::class . '@')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $found[] = $method . ' ' . $route->uri();
            }
        }

        $missing = array_values(array_diff(array_unique($found), $censused, $guardedElsewhere));

        $this->assertSame([], $missing,
            "These OrderController routes are neither censused above nor listed as guarded "
            . "elsewhere, so nothing asserts they are hidden AND refused:\n - "
            . implode("\n - ", $missing));
    }
}

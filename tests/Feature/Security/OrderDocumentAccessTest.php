<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P01-SEC-2 -- the regression matrix for App\Http\Middleware\OrderDocumentAccessGuard.
 *
 * WHY THESE TESTS HAD TO ENABLE THE ADDON, and why no existing test could have caught the
 * defect. packages/workdo/AutomaticOrderPrinting is not in PackageServiceProvider's
 * $staticAddons, so its routes are registered only from $this->app->booted() after reading
 * `add_on_managers` -- a table RefreshDatabase has not migrated when that callback fires, and a
 * row no seeder in this repository ever creates. So in the entire existing suite the module's
 * four routes DO NOT EXIST (nor do AuctionProduct's or LotteryPrizes' six, nor
 * PartialPayments'), Route::getRoutes() holds only the authenticated routes/web.php:1178
 * copy, and tests/Feature/Security/UnauthenticatedRouteBoundaryTest passes today and would pass
 * after any fix, proving nothing. Every test below therefore enables the addon through the
 * production loader first, the way tests/Feature/Tenancy/BulkDeleteAuthorizationTest does, and
 * test_the_premise_... asserts the routes really appeared so none of the denials can pass by 404.
 */
class OrderDocumentAccessTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Exactly what production does: the row, then the provider's own loader. */
    // =============================================================================================
    // WHY THE is_guest SELECT IS CONDITIONAL -- the mutation that survived until this test existed
    // =============================================================================================

    /**
     * The guard's TARGETS table names two tables: `orders`, and the AuctionProduct module's
     * `auction_product_orders`. Only the first has `is_guest`, and the second does not exist at
     * all in a test run -- so a mutation that made the `is_guest` select UNCONDITIONAL passed
     * every test in this file. It would still have been a live regression: the select would throw
     * on that table, `handle()`'s `catch (\Throwable)` turns a throw into `deny()`, and every
     * auction order view would fail closed. Fail-closed is not a security hole, but it is a
     * module surface going dark for a reason no log explains.
     *
     * This test is what makes that mutation fail. It pins the two halves of the guard's decision
     * directly, because the module path itself cannot be driven here.
     */
    public function test_the_guest_flag_is_only_selected_from_tables_that_have_it(): void
    {
        $method = new \ReflectionMethod(\App\Http\Middleware\OrderDocumentAccessGuard::class, 'tableHasGuestFlag');
        $method->setAccessible(true);

        $columnsFor = new \ReflectionMethod(\App\Http\Middleware\OrderDocumentAccessGuard::class, 'columnsFor');
        $columnsFor->setAccessible(true);

        // THE DECISION ITSELF, which is what a mutation making the select unconditional changes.
        $this->assertSame(['store_id', 'customer_id', 'is_guest'], $columnsFor->invoke(null, 'orders'),
            'the guard stopped reading `is_guest` from `orders`, so its shopper limb has nothing '
            . 'to refuse an adopted guest order with.');

        $this->assertTrue($method->invoke(null, 'orders'),
            'premise failed: `orders` must carry `is_guest`, or the whole fix is inert.');

        \Illuminate\Support\Facades\Schema::create('ss_guardless_orders', function ($table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('customer_id')->default(0);
        });

        try {
            $this->assertFalse($method->invoke(null, 'ss_guardless_orders'),
                'a table with no `is_guest` column was reported as having one, so the guard would '
                . 'add it to the select and the query would throw.');

            /*
             * AND THE CONSEQUENCE, MEASURED ON THIS DRIVER -- which is not what I first asserted.
             *
             * I wrote this test expecting the select to THROW, because that is what MySQL does
             * (error 1054) and it is why the production path would fail closed. On SQLite it does
             * not throw at all: the double-quoted-identifier fallback treats the unknown
             * `"is_guest"` as a STRING LITERAL, and the row comes back with a property named
             * `"is_guest"` -- quotes included -- whose value is the string `is_guest`.
             *
             * That makes the divergence the point of this assertion rather than a footnote. An
             * unconditional select would break MySQL and pass every test in this suite, so the
             * conditional cannot be justified by anything this suite can observe directly. What
             * IS observable here is the fallback, and that it does not produce a usable
             * `is_guest` property -- which is exactly why `shopperOwns()` asks
             * `property_exists($row, 'is_guest')` instead of reading a value that would have
             * cast to 0 and admitted silently.
             */
            \Illuminate\Support\Facades\DB::table('ss_guardless_orders')->insert(['customer_id' => 7]);

            $row = \Illuminate\Support\Facades\DB::table('ss_guardless_orders')
                ->where('id', 1)
                ->first(['customer_id', 'is_guest']);

            $this->assertNotNull($row, 'premise failed: the probe row was not inserted.');
            $this->assertFalse(property_exists($row, 'is_guest'),
                'the absent column came back as a usable `is_guest` property. If a driver ever '
                . 'does that, `shopperOwns()` would read its value -- and under SQLite\'s string '
                . 'fallback that value casts to 0, which ADMITS a guest order.');

            $this->assertFalse($method->invoke(null, 'a_table_that_does_not_exist_at_all'),
                'a missing table must answer false rather than propagate, or the guard denies '
                . 'every request for a module that is not installed.');

            $this->assertSame(['store_id', 'customer_id'], $columnsFor->invoke(null, 'ss_guardless_orders'),
                'the guard would select `is_guest` from a table that lacks it. On MySQL that is '
                . 'error 1054, which `handle()`\'s catch turns into deny() -- so every auction '
                . 'order view would go dark. This assertion exists because the behavioural '
                . 'version of it cannot fail on SQLite.');

            $this->assertSame(['store_id', 'customer_id'], $columnsFor->invoke(null, 'a_table_that_does_not_exist_at_all'),
                'an uninstalled module\'s table must not gain the column either.');
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('ss_guardless_orders');
        }
    }

    private function enableAddon(): void
    {
        AddOnManager::create([
            'module' => 'AutomaticOrderPrinting',
            'name' => 'Automatic Order Printing',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'automatic-order-printing',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AutomaticOrderPrinting']);
    }

    private function makeCustomer(int $storeId, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Shopper',
            'last_name' => 'Test',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('secret-not-a-real-password'),
            'store_id' => $storeId,
            'status' => 1,
        ], $attributes));
    }

    // ---------------------------------------------------------------------------------------
    // THE PREMISE. Without this the whole file is vacuous.
    // ---------------------------------------------------------------------------------------

    public function test_the_premise_the_addon_routes_do_not_exist_until_the_addon_is_enabled(): void
    {
        $this->assertFalse(Route::has('invoice.shippinglabel.pdf'), 'The addon route exists before the addon was enabled, so this suite would not be testing the real surface.');

        $this->enableAddon();

        $this->assertTrue(Route::has('invoice.shippinglabel.pdf'), 'Enabling the addon did not register its routes, so every denial below could pass by 404.');
        $this->assertTrue(Route::has('invoice.shippinglabel.printing'));
        $this->assertTrue(Route::has('invoice.preview'));
    }

    /**
     * ANTI-LOCKOUT. Laratrust has no super-admin bypass and a permission name no seeder creates
     * can never be granted, so gating on an unseeded name would 403 everyone for ever.
     */
    public function test_the_permission_the_guard_requires_is_really_seeded(): void
    {
        Utility::addNewData();

        $this->assertNotNull(
            Permission::where('name', \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION)->first(),
            'The guard requires a permission that Utility::addNewData() does not create.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // ANONYMOUS
    // ---------------------------------------------------------------------------------------

    public function test_an_anonymous_caller_cannot_fetch_a_shipping_label(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/shippinglabel/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_fetch_a_print_label(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/printlabel/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_fetch_the_host_invoice_route(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        // This is the shadowed route: with the addon enabled it loses `Authenticate` entirely.
        $this->get('shippinglabel/pdf/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    /**
     * The second uri pattern also matches /shippinglabel/pdf/<id>, so guarding only the first
     * would have left a live path to the same document.
     */
    public function test_the_overlapping_uri_pattern_is_also_guarded(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/shippinglabel/pdf/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_fetch_the_invoice_preview(): void
    {
        $this->enableAddon();

        $this->get('/invoices/preview/template1/blue')->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------
    // CROSS-TENANT -- the half that is live even with the addon absent
    // ---------------------------------------------------------------------------------------

    public function test_merchant_a_cannot_fetch_merchant_bs_shipping_label(): void
    {
        $this->enableAddon();
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $this->grantPermission($merchantA, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($merchantA)
            ->get('/shippinglabel/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    /**
     * §5.8 -- an identifier that decrypts perfectly but references another tenant.
     */
    public function test_a_validly_encrypted_id_for_another_tenant_is_denied(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $this->grantPermission($merchantA, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        // No addon: this is the host declaration, authenticated but with no ownership check of
        // its own. The guard is the only thing standing between merchant A and store B's label.
        $this->actingAs($merchantA)
            ->get('shippinglabel/pdf/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    public function test_a_merchant_can_fetch_their_own_orders_shipping_label(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->grantPermission($merchant, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $response = $this->actingAs($merchant)->get('/shippinglabel/' . Crypt::encrypt($order->id));

        $this->assertNotSame(403, $response->getStatusCode(), 'The guard denied a merchant their own order document, which would break legitimate printing.');
    }

    // ---------------------------------------------------------------------------------------
    // PERMISSION
    // ---------------------------------------------------------------------------------------

    public function test_staff_without_the_permission_are_denied(): void
    {
        $this->enableAddon();
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        $this->actingAs($staff->fresh())
            ->get('/shippinglabel/' . Crypt::encrypt($order->id))
            ->assertForbidden();
    }

    public function test_staff_with_the_permission_are_admitted(): void
    {
        $this->enableAddon();
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();
        $staff = $staff->fresh();

        $this->grantPermission($staff, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $response = $this->actingAs($staff->fresh())->get('/shippinglabel/' . Crypt::encrypt($order->id));

        $this->assertNotSame(403, $response->getStatusCode(), 'Staff holding the permission on their own store were denied.');
    }

    /**
     * §5.9 -- the operator path is explicit and intentional, not an accident of Laratrust.
     */
    public function test_the_platform_operator_is_admitted_without_a_seeded_order_permission(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $operator = $this->makePlatformAdmin();

        $this->assertFalse(
            $operator->isAbleTo(\App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION),
            'The operator holds the permission through a role, so this test would not be proving the explicit bypass.'
        );

        $response = $this->actingAs($operator)->get('/shippinglabel/' . Crypt::encrypt($order->id));

        $this->assertNotSame(403, $response->getStatusCode(), 'The platform operator was locked out -- the explicit bypass is not working.');
    }

    // ---------------------------------------------------------------------------------------
    // MALFORMED INPUT AND MISSING CONTEXT -- both must fail CLOSED
    // ---------------------------------------------------------------------------------------

    public function test_a_malformed_identifier_fails_closed(): void
    {
        $this->enableAddon();
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        foreach (['not-encrypted', 'pdf', '7', ''] as $bad) {
            $this->actingAs($merchant)
                ->get('/shippinglabel/' . $bad)
                ->assertForbidden();
        }
    }

    public function test_an_encrypted_id_for_an_order_that_does_not_exist_fails_closed(): void
    {
        $this->enableAddon();
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($merchant)
            ->get('/shippinglabel/' . Crypt::encrypt(987654321))
            ->assertForbidden();
    }

    /**
     * §5.12 -- no store context must fail closed, not fall through to "no predicate".
     */
    public function test_a_merchant_with_no_store_context_fails_closed(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $stray = $this->makeMerchant();
        $stray->current_store = null;
        $stray->save();
        $stray = $stray->fresh();

        $this->grantPermission($stray, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($stray)
            ->get('/shippinglabel/' . Crypt::encrypt($order->id))
            ->assertForbidden();
    }

    public function test_an_order_with_a_null_store_id_is_never_readable(): void
    {
        $this->enableAddon();
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store, ['store_id' => null]);

        $this->grantPermission($merchant, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($merchant)
            ->get('/shippinglabel/' . Crypt::encrypt($order->id))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------
    // THE SHOPPER LIMB -- §5.11
    // ---------------------------------------------------------------------------------------

    public function test_a_signed_in_shopper_can_fetch_their_own_orders_document(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 0]);

        $response = $this->actingAs($customer, 'customers')
            ->get('/shippinglabel/' . Crypt::encrypt($order->id));

        $this->assertNotSame(403, $response->getStatusCode(), 'A signed-in shopper was denied their own order document.');
    }

    /**
     * THE HOST'S OWN ORDER DOCUMENT.  P1-ORD-09 (#73).
     *
     * `{storeSlug?}/order/{id}` (routes/customer.php:175 -> OrderController::orderDetails) decrypts
     * the id and renders the order with NO ownership test, in a route group that carries no
     * customer authentication. `APP_KEY` is in git history and is not being rotated (owner decision
     * D7), so the ciphertext is an encoding rather than a control and the uri is forgeable.
     *
     * It needed no new mechanism -- this guard already resolves the row, reads its `store_id` and
     * compares it against the caller's tenant -- so it was added to TARGETS rather than given a
     * parallel control.
     */
    public function test_an_anonymous_caller_cannot_fetch_the_host_order_document(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/' . $store->slug . '/order/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    /** And a forged ciphertext for another tenant's order is refused even when signed in. */
    public function test_a_shopper_cannot_fetch_another_stores_host_order_document(): void
    {
        $this->enableAddon();
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $customer = $this->makeCustomer($storeA->id);
        $orderB = $this->makeOrder($storeB);

        $this->actingAs($customer, 'customers')
            ->get('/' . $storeA->slug . '/order/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    /** PRESERVE FIRST: the shopper's own order document still opens. */
    public function test_a_shopper_can_still_fetch_their_own_host_order_document(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 0]);

        $response = $this->actingAs($customer, 'customers')
            ->get('/' . $store->slug . '/order/' . Crypt::encrypt($order->id));

        $this->assertNotSame(403, $response->getStatusCode(),
            'a signed-in shopper was denied their own order document.');
    }

    public function test_a_shopper_cannot_fetch_another_shoppers_order_document(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $mine = $this->makeCustomer($store->id);
        $theirs = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $theirs->id, 'is_guest' => 0]);

        $this->actingAs($mine, 'customers')
            ->get('/shippinglabel/' . Crypt::encrypt($order->id))
            ->assertForbidden();
    }

    public function test_a_shopper_cannot_fetch_an_order_from_another_store(): void
    {
        $this->enableAddon();
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $customer = $this->makeCustomer($storeA->id);
        $orderB = $this->makeOrder($storeB, ['customer_id' => $customer->id, 'is_guest' => 0]);

        $this->actingAs($customer, 'customers')
            ->get('/shippinglabel/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    /**
     * An ORDINARY guest order -- `customer_id = 0` -- cannot be self-served.
     *
     * THIS TEST DID NOT PIN ITS OWN CLAIM, and the correction matters more than the test.
     * Its docblock used to say "a guest order (customer_id defaults to 0) cannot be self-served",
     * stating the rule via a mechanism that is false for a whole class of guest orders. The
     * fixture below is refused by the `$customerId <= 0` limb of
     * `OrderDocumentAccessGuard::shopperOwns()`'s `$customerId <= 0` limb, so it passes IDENTICALLY whether or not
     * an `is_guest` predicate exists -- a refusal test whose fixture is also refused by a second
     * predicate, which is the vacuity shape this project keeps re-learning.
     *
     * The case it could not see is the next test.
     */
    public function test_an_ordinary_guest_order_cannot_be_self_served(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomer($store->id);
        $guestOrder = $this->makeOrder($store, ['customer_id' => 0, 'is_guest' => 1]);

        $this->actingAs($customer, 'customers')
            ->get('/shippinglabel/' . Crypt::encrypt($guestOrder->id))
            ->assertForbidden();
    }

    /**
     * THE SHAPE THE INTAKE ACTUALLY PRODUCES, and the one the test above cannot reach: a real
     * `customer_id` with `is_guest = 1`.
     *
     * `app/Services/PaymentService.php:3019-3033` writes exactly that when a guest checkout's
     * billing e-mail matches an existing customer of the store. `shopperOwns()` asks
     * `orders.customer_id`, and before this fix the guard's DB read did not even SELECT `is_guest`,
     * so the matched customer satisfies every limb and the document opens for them.
     *
     * The guard's own prose stated the falsified premise twice -- in the class docblock ("A GUEST
     * order cannot be self-served at all, because orders.customer_id defaults to 0") and above
     * `shopperOwns()` ("customer_id defaults to 0 on a guest order, so 0 can never match") -- so
     * the rule was INTENDED and only its mechanism was wrong, exactly as at
     * `OrderController::status_cancel`. Both are corrected; cited by name rather than by line,
     * because a line citation in a test is stale the next time the file is edited -- which it was,
     * by this very change.
     */
    public function test_an_adopted_guest_order_cannot_be_self_served_either(): void
    {
        $this->enableAddon();
        [, $store] = $this->makeTenant();
        $customer = $this->makeCustomer($store->id);
        $adopted = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 1]);

        $row = \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $adopted->id)->first(['customer_id', 'is_guest']);
        $this->assertSame(1, (int) $row->is_guest, 'premise failed: not a guest order.');
        $this->assertSame((int) $customer->id, (int) $row->customer_id,
            'premise failed: this is an ordinary guest order, refused by a different limb.');

        $this->actingAs($customer, 'customers')
            ->get('/shippinglabel/' . Crypt::encrypt($adopted->id))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------
    // THE HOST ORDER-DETAIL AND RECEIPT ROUTES.
    //
    // These need NO module and are live on the server exactly as it stands, which makes them the
    // half of this defect that is reachable today. All three are inside the host's
    // ['auth','xss','setlocate','verified'] group, so `Authenticate` IS present -- authentication
    // was never the gap here. The gap is that the handlers then load the order by decrypted id
    // with no store predicate:
    //   OrderController::order_view():499      isAbleTo('Show Order'), but NO store check
    //   OrderController::show():490            no permission check and no store check
    //   OrderController::order_receipt():630   no permission check and no store check
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostOrderDocumentRoutes(): array
    {
        return [
            'order detail (order.view)' => ['/order-view/'],
            'order modal (order.order_view)' => ['/orders/order_view/'],
            'order receipt (order.receipt)' => ['/order-receipt/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostOrderDocumentRoutes')]
    public function test_a_merchant_cannot_read_another_tenants_order_document($prefix): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        $orderA = $this->makeOrder($storeA);

        [$ownerB] = $this->makeTenant();
        $this->grantPermission($ownerB, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($ownerB)
            ->get($prefix . Crypt::encrypt($orderA->id))
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostOrderDocumentRoutes')]
    public function test_a_merchant_is_still_admitted_to_their_own_order_document($prefix): void
    {
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);
        $this->grantPermission($owner, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        // NOT assertOk. The point of this test is that the GUARD let the request through to its
        // handler; what those handlers then render pulls in theme, settings and view data that
        // belong to other workstreams and is not this package's claim. 403 would mean the guard
        // refused the owner of the order, which is the regression worth catching.
        $this->actingAs($owner)
            ->get($prefix . Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    /**
     * THE PERMISSION HALF, on the one host route that checks no permission at all. A role with
     * 'Manage Order' (which is all the order LIST needs, OrderController::index():483) but
     * without 'Show Order' can today read any order through this uri, because
     * resources/views/order/action.blade.php renders its Show button unconditionally and
     * OrderController::show() checks nothing.
     */
    public function test_a_role_without_show_order_cannot_use_the_unchecked_order_modal(): void
    {
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $staff = $this->makeStaff($owner, ['current_store' => $store->id]);
        $this->grantPermission($staff, 'Manage Order');

        $this->actingAs($staff)
            ->get('/orders/order_view/' . Crypt::encrypt($order->id))
            ->assertForbidden();
    }

    public function test_a_platform_operator_can_still_read_a_host_order_document(): void
    {
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->actingAs($this->makePlatformAdmin(['current_store' => $store->id]))
            ->get('/order-receipt/' . Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------------------------------
    // THE ROUTES AN ADVERSARIAL AUDIT FOUND AFTER THE FIRST COMMIT.
    //
    // All four were reachable with the guard installed, because the guard matches on uri and the
    // first uri census matched on uri WORDS -- none of these four spells anything it looked for.
    // Three are full order documents on the storefront or the refund screen; the fourth is a
    // second uri on a handler that was already guarded under its other uri.
    //
    //   {storeSlug?}/customerorder/{id}   AccountProfileController@customerorder  -- shopper gate
    //                                     only: any signed-in shopper, any tenant
    //   {storeSlug?}/order-refund/{id}    AccountProfileController@order_refund   -- same gate,
    //                                     and ?refund=true reads a RAW, enumerable integer id
    //   refund-request/{refund_request}   OrderRefundController@show              -- authenticated,
    //                                     no permission and no store check on the order
    //   order/{order}                     OrderController@show (Route::resource) -- same action as
    //                                     the already-guarded orders/order_view/{id}
    // ---------------------------------------------------------------------------------------

    public function test_a_shopper_cannot_read_another_tenants_order_through_the_storefront_order_page(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $shopperA = $this->makeCustomer($storeA->id);

        $this->actingAs($shopperA, 'customers')
            ->get('/' . $storeA->slug . '/customerorder/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    public function test_a_shopper_is_still_admitted_to_their_own_storefront_order_page(): void
    {
        [$owner, $store] = $this->makeTenant();
        $shopper = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $shopper->id, 'is_guest' => 0]);

        $this->actingAs($shopper, 'customers')
            ->get('/' . $store->slug . '/customerorder/' . Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    /**
     * THE RAW-ID ROUTE. order_refund() with ?refund=true passes the route parameter VERBATIM into
     * Order::order_detail(), so the id arrives unencrypted and enumerable. That is why
     * resolveOrderId() accepts the raw form -- a decrypt-only resolver would have denied this
     * route's legitimate callers while leaving the leak open.
     */
    public function test_a_shopper_cannot_read_another_tenants_order_through_the_raw_id_refund_route(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $shopperA = $this->makeCustomer($storeA->id);

        // The RAW integer, exactly as the handler would have consumed it.
        $this->actingAs($shopperA, 'customers')
            ->get('/' . $storeA->slug . '/order-refund/' . $orderB->id . '?refund=true')
            ->assertForbidden();
    }

    public function test_a_shopper_is_still_admitted_to_their_own_raw_id_refund_route(): void
    {
        [$owner, $store] = $this->makeTenant();
        $shopper = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $shopper->id, 'is_guest' => 0]);

        $this->actingAs($shopper, 'customers')
            ->get('/' . $store->slug . '/order-refund/' . $order->id . '?refund=true')
            ->assertStatus(200);
    }

    /**
     * ACCEPTING THE RAW FORM WEAKENS NOTHING -- asserted, not argued. The same cross-tenant
     * request is refused whether the id arrives encrypted or raw, on a target whose handler
     * expects the encrypted form.
     */
    public function test_a_raw_unencrypted_id_is_refused_exactly_like_its_encrypted_form(): void
    {
        $this->enableAddon();
        [$ownerA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $this->grantPermission($ownerA, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($ownerA)
            ->get('/shippinglabel/pdf/' . $orderB->id)
            ->assertForbidden();

        $this->actingAs($ownerA)
            ->get('/shippinglabel/pdf/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    public function test_a_merchant_cannot_read_another_tenants_order_through_the_refund_request_screen(): void
    {
        [$ownerA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $this->grantPermission($ownerA, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($ownerA)
            ->get('/refund-request/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    /**
     * The second uri on an already-guarded handler. Guarding by uri means guarding one uri does
     * not guard another that reaches the same action, so this is the regression test for that.
     *
     * UPDATED BY P2.5-PKG-C (F-C-02), AND THE REASON IS WORTH READING. Both forms still REFUSE;
     * what changed is which component refuses, which is exactly what the comment on the second
     * assertion below said it existed to surface. It surfaced it.
     *
     * WHAT THIS TEST USED TO SAY, and why it was half right:
     *   raw id        -> 403. "A RAW id binds successfully and reaches the handler, so the raw
     *                    form is the path that was actually reachable -- and it is the one the
     *                    guard has to refuse."
     *   encrypted id  -> 404, refused by SubstituteBindings before the guard was consulted.
     *
     * THE RAW-ID ASSERTION WAS PASSING FOR THE WRONG REASON. The handler was declared
     * `show(Order $order, $id)`, so a raw id did bind -- and the guard then received an **Order
     * instance**, not a string. `resolveOrderId()` opens with `if (! is_string($raw))` and
     * returned null, so the guard denied on a PARSE failure rather than on the ownership
     * comparison. The 403 was real but it was not the control this test believed it was measuring:
     * the owner's OWN order answered 403 too, and nothing here could tell the two apart. That is
     * the same blind spot the Package C census had, in a test written to prevent it.
     *
     * NOW: the unused bound parameter is gone (`OrderController::show($id)`), so both forms reach
     * the guard as strings and both are refused by the ownership comparison at
     * `OrderDocumentAccessGuard:468`. `OrderDocumentGuardBoundParameterTest` supplies the control
     * that was missing -- the owner's own order answers 200 -- so a 403 here now means "not
     * yours" and can no longer mean "unparseable".
     */
    public function test_a_merchant_cannot_read_another_tenants_order_through_the_resource_show_uri(): void
    {
        [$ownerA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();
        $orderB = $this->makeOrder($storeB);

        $this->grantPermission($ownerA, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        // The reachable form: implicit binding resolves it, so the guard must refuse it.
        $this->actingAs($ownerA)
            ->get('/order/' . $orderB->id)
            ->assertForbidden();

        // The encrypted form, which is what every sibling order surface links. It USED to answer
        // 404 because SubstituteBindings could find no row keyed by the ciphertext; with the bound
        // parameter gone it now reaches the guard and is refused on ownership like the raw form.
        // Still a refusal, by a better mechanism -- and kept as a separate assertion because the
        // two inputs travel different paths to get here.
        $this->actingAs($ownerA)
            ->get('/order/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------
    // THE SIBLING MODULES' ORDER-VIEW AND RECEIPT ROUTES.
    //
    // LotteryPrizes' two document routes sit in the same `['web']`-only group shape as
    // AutomaticOrderPrinting's (its routes/web.php:32), so they are ANONYMOUS once that module
    // is enabled, and they read the CORE `orders` table -- which means, unlike AuctionProduct's,
    // they can be exercised end to end here. See
    // test_the_auction_targets_are_covered_by_uri_not_by_execution for the one that cannot.
    // ---------------------------------------------------------------------------------------

    private function enableLotteryPrizes(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);
    }

    public function test_the_premise_the_lottery_document_routes_are_anonymous_without_the_guard(): void
    {
        $this->assertFalse(Route::has('lottery.order.receipt'));

        $this->enableLotteryPrizes();

        $this->assertTrue(Route::has('lottery.order.receipt'), 'Enabling LotteryPrizes did not register its routes, so the denials below could pass by 404.');

        $route = Route::getRoutes()->getByName('lottery.order.receipt');
        $resolved = app('router')->gatherRouteMiddleware($route);

        $this->assertNotContains(
            \Illuminate\Auth\Middleware\Authenticate::class,
            $resolved,
            'lottery.order.receipt has acquired Authenticate upstream. Good news, but this test '
            . 'documents the opposite, so re-read the guard\'s TARGETS before changing it.'
        );

        $this->assertContains(
            \App\Http\Middleware\OrderDocumentAccessGuard::class,
            $resolved,
            'The guard is not in the resolved stack of an anonymous module document route.'
        );
    }

    public function test_an_anonymous_caller_cannot_fetch_a_lottery_order_receipt(): void
    {
        $this->enableLotteryPrizes();
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/lottery-order-receipt/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_fetch_a_lottery_order_view(): void
    {
        $this->enableLotteryPrizes();
        [$owner, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $this->get('/lottery-order-view/' . Crypt::encrypt($order->id))->assertForbidden();
    }

    public function test_a_merchant_cannot_fetch_another_tenants_lottery_order_receipt(): void
    {
        $this->enableLotteryPrizes();
        [$ownerA, $storeA] = $this->makeTenant();
        $orderA = $this->makeOrder($storeA);

        [$ownerB] = $this->makeTenant();
        $this->grantPermission($ownerB, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        $this->actingAs($ownerB)
            ->get('/lottery-order-receipt/' . Crypt::encrypt($orderA->id))
            ->assertForbidden();
    }

    /**
     * HONEST LIMIT, asserted rather than written in a report where it could go stale.
     *
     * AuctionProduct's three document uris ARE guard targets and the census ratchet in
     * OrderDocumentRouteResolutionTest proves they stay targets, but they cannot be exercised
     * end to end from here: `auction_product_orders` is declared only by that module's own
     * migration, which does not run until the module is enabled, so it is absent from the test
     * schema. This test states exactly that, so the gap is visible in the suite instead of only
     * in prose -- and it also pins the guard's fail-closed behaviour when a target table is
     * missing, which is the shape the server would see if a module were half-installed.
     */
    public function test_the_auction_targets_are_covered_by_uri_not_by_execution(): void
    {
        $targets = (new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class))
            ->getConstant('TARGETS');

        foreach ([
            'auction/shippinglabel/pdf/{id}',
            'auction-order-view/{id}',
            'auction-order-receipt/{id}',
        ] as $uri) {
            $this->assertArrayHasKey($uri, $targets, "The guard no longer targets {$uri}.");
            $this->assertSame('auction_product_orders', $targets[$uri]['table']);
        }

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('auction_product_orders'),
            'auction_product_orders now EXISTS in the test schema, so the three auction targets '
            . 'can and should be exercised end to end the way the lottery ones above are. '
            . 'Delete this test and write them.'
        );
    }

    /**
     * MIXED SESSIONS -- a FALSE DENIAL, found by the P01-SEC-2 adversarial audit.
     *
     * config/session.php sets the cookie path to '/' and the storefront sits inside the same
     * `web` group as the admin, so one browser can legitimately hold BOTH a live admin session
     * and a shopper session. The merchant limb used to return its own verdict, so such a caller
     * was refused their OWN order document: the web limb ran first, failed the permission or the
     * store comparison, and the shopper limb never got to speak.
     *
     * Not a leak -- a usability defect that reads as a security bug to whoever hits it. The limbs
     * now fall through, and the shopper limb is no weaker for it: it still demands a non-zero
     * customer_id match and the same store.
     */
    public function test_a_shopper_who_also_holds_an_admin_session_still_gets_their_own_document(): void
    {
        // THE STOREFRONT ORDER PAGE, not a module label route, and the choice is load-bearing:
        // the AutomaticOrderPrinting handler reads `automatic_order_printings`, whose migration
        // only runs when that module is enabled, so admitting the request there dies on a missing
        // table and the 200 could never be asserted. This target's handler runs in the test
        // schema, so a 200 here really does mean the guard let the caller through.
        [$foreignMerchant] = $this->makeTenant();

        [$owner, $store] = $this->makeTenant();
        $shopper = $this->makeCustomer($store->id);
        $order = $this->makeOrder($store, ['customer_id' => $shopper->id, 'is_guest' => 0]);

        // The admin identity belongs to a DIFFERENT tenant and holds no order permission, so the
        // merchant limb must fail and only the shopper limb can carry this request.
        $this->actingAs($foreignMerchant)
            ->actingAs($shopper, 'customers')
            ->get('/' . $store->slug . '/customerorder/' . Crypt::encrypt($order->id))
            ->assertStatus(200);
    }

    /**
     * And the fall-through must not have become a bypass: the same doubled principal must still
     * be refused an order that NEITHER identity owns.
     */
    public function test_a_doubled_principal_is_still_refused_an_order_neither_identity_owns(): void
    {
        [$foreignMerchant] = $this->makeTenant();
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $shopperA = $this->makeCustomer($storeA->id);
        $orderB = $this->makeOrder($storeB, ['product_order_id' => 'NEITHER-OWNS']);

        $this->actingAs($foreignMerchant)
            ->actingAs($shopperA, 'customers')
            ->get('/' . $storeA->slug . '/customerorder/' . Crypt::encrypt($orderB->id))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------
    // WIRING -- §5.13 and §5.14
    // ---------------------------------------------------------------------------------------

    /**
     * §5.13. The guard is on the `web` GROUP, not on a route. That is what makes it survive
     * `route:cache`: the cache stores declared middleware names unexpanded and the group is
     * resolved per request from bootstrap/app.php, which every web request re-executes while
     * route files are skipped.
     */
    public function test_the_guard_is_registered_on_the_web_group_so_it_survives_route_caching(): void
    {
        $groups = app('router')->getMiddlewareGroups();

        $this->assertContains(
            \App\Http\Middleware\OrderDocumentAccessGuard::class,
            $groups['web'],
            'The guard is not on the web group. Registered on a route instead, it would be lost '
            . 'under route:cache and lost again when the addon re-declares the route after '
            . 'routes/web.php.'
        );
    }

    public function test_the_guard_is_in_the_resolved_stack_of_every_target_route(): void
    {
        $this->enableAddon();

        foreach (['shippinglabel.pdf', 'invoice.shippinglabel.pdf', 'invoice.shippinglabel.printing', 'invoice.preview'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} is not registered.");

            $this->assertContains(
                \App\Http\Middleware\OrderDocumentAccessGuard::class,
                app('router')->gatherRouteMiddleware($route),
                "Route {$name} does not carry the guard."
            );
        }
    }

    /** The guard must be inert everywhere else. */
    public function test_the_guard_does_not_arm_on_an_unrelated_route(): void
    {
        $this->enableAddon();

        // A public route must still be public -- the guard must not turn it into a 403.
        $this->get('/login')->assertStatus(200);

        // The addon's own settings routes share the same controller CLASS as two of the targets,
        // so a controller-prefix match (the shape BulkDeleteTenantGuard uses) would have armed
        // this guard on all of them. They must still be refused by their own `auth`, i.e. a
        // redirect to login, NOT a 403 from this guard.
        $route = Route::getRoutes()->getByName('automatic-order.print.setting');
        $this->assertNotNull($route, 'The addon settings route is not registered.');
        $this->assertStringContainsString(
            'AutomaticOrderPrintingController',
            $route->getActionName(),
            'This route no longer shares the controller class, so it no longer tests the point.'
        );

        $response = $this->get('/automatic-print-setting');
        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'The guard armed on an authenticated settings route that shares the controller class.'
        );
    }
}

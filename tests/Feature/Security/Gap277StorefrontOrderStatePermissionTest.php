<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine as SM;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * THE STOREFRONT ORDER DOORS ASK NO PERMISSION OF A `User`.  GAP-277.
 * =================================================================================================
 *
 * `POST api/{slug}/order-status-change` and `POST api/{slug}/product-return` resolve WHOSE order it
 * is (`resolveMutableOrder()`), and since GAP-135 which word a SHOPPER may say -- and never whether
 * a `User` may change an order at all. The admitting limb resolves a `type='staff'` row to its
 * owning admin through `UserPolicy::merchantTenantRoot()`, so **every user in the tenant is
 * admitted whatever they hold**.
 *
 * THE PRINCIPAL IS REAL AND SELF-SERVICEABLE. `POST /api/admin/adminlogin` issues a never-expiring
 * Sanctum token to any active staff row with no reference to roles or permissions
 * (`test_the_premise_a_roleless_staff_member_really_gets_a_token_from_the_shipped_login_door`
 * drives that door rather than minting the token here). With it, a staff member holding **no role
 * at all** could:
 *
 *   - `order_status=cancel` -- the writer stamps `cancel_date` and `delivered_status = 2`,
 *     `OrderEntitlementReversal` walks the merchant's wallet credit back, and the handler then
 *     RESTORES THE STOCK;
 *   - `order_status=delivered` -- the state the return and refund workflows are gated on, and after
 *     owner decision H2 a state nothing can move an order out of;
 *   - `product-return` -- which CREDITS `products.product_stock` and REDUCES `orders.final_price`.
 *
 * BOTH SIBLING ADMIN DOORS ALREADY ASK THE QUESTION. `Api\DashboardController::statusChange`
 * (`api/admin/status-change`) and `orderCancel` (`api/{slug}/order-cancel`) both call
 * `callerMayChangeOrderState()`, whose own docblock states the distinction this row is about:
 * *"`resolveChangeableOrder()` answers a DIFFERENT question: whose order is it ... it never asks
 * whether the actor may change an order."* The Flutter integer door (`updateStatus`) requires the
 * `updateStatus` ability outright. These two storefront doors were the omission.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE PINS
 * -------------------------------------------------------------------------------------------------
 *
 * THE GATE IS ON THE `User` BRANCH ONLY, and three cases exist to keep it there:
 * the assigned DRIVER is not gated (a `DeliveryBoy` is not a Laratrust subject --
 * `MerchantPermissions::names()` answers `[]` for anything that is not a `User` -- and carrying the
 * parcel IS the authorization, recorded in `orders.deliveryboy_id`); the SHOPPER is not gated (they
 * are authorised by owning the order, and GAP-135 constrains what they may say); and the PLATFORM
 * OPERATOR is admitted FIRST, because `isAbleTo()` REFUSES them -- there is no super-admin bypass
 * in this codebase.
 *
 * THE STORE'S OWNER IS ADMITTED WITHOUT HOLDING ANYTHING, and that is not a bypass: the owner is
 * the principal who creates the roles and grants the permissions in their own tenant, so refusing
 * them is a live breakage with no security gain. `callerMayChangeOrderState()` records two earlier
 * tests that failed for exactly this reason ("The predicate is too tight -- that is a defect, not a
 * fix"), and `test_the_owning_merchant_holding_no_role_is_still_admitted` is this file's copy of
 * that line.
 *
 * READ RAW. `Order` and `Product` are `Cachable`, so every state assertion goes through
 * `DB::table()`; the money and stock claims are read from `orders`, `products` and
 * `store_order_financials` rather than from a model.
 *
 * AND THE REFUSAL CARRIES ITS OWN REASON. Every denial on these doors is HTTP 200 with `status: 0`
 * (`ApiResponser::error()`; a 401 force-logs-out the shipped Flutter app), so "the row did not
 * change" is equally satisfied by the ownership refusal, by a validator, or by a swallowed crash.
 * Each case asserts the exact message, and `Order not found.` -- the ownership refusal -- is
 * asserted NOT to be the answer, because a permission refusal that reads as "no such order" would
 * mean the gate never ran.
 */
class Gap277StorefrontOrderStatePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The refusal the sibling admin doors already give; translated in `en.json` and `ar.json`. */
    private const REFUSED = 'You do not have permission to perform this action.';

    /** The ownership refusal, which must NOT be what a permission failure answers. */
    private const NOT_YOURS = 'Order not found.';

    /**
     * A fixture password, not a credential: it exists only inside this test's database, and the one
     * case that uses it drives the real login door so that "a roleless staff member can get a
     * token" is executed rather than asserted. `FlutterCompatibilityTest` does the same.
     */
    private const FIXTURE_PASSWORD = 'gap277-fixture-password-not-a-credential';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    private function productIn(Store $store, User $owner, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'gap277-' . uniqid(), 'slug' => 'gap277-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 50, 'sale_price' => 50, 'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);
    }

    /**
     * A PAID, PENDING order of the merchant's store carrying one returnable line.
     *
     * `payment_status = 'Paid'` is load-bearing: `OrderObserver::created` writes a
     * `store_order_financials` row through `OrderFinancialService`, which records `paid` and credits
     * the store's wallet -- so the entitlement reversal has something to reverse and this file can
     * measure it. Without it `OrderEntitlementReversal` logs "found no financial record" and the
     * money half of the claim would be unobservable.
     */
    private function paidOrderWithLine(Store $store, Product $product, int $qty = 2): Order
    {
        return $this->makeOrder($store, [
            'customer_id' => 0,
            'is_guest' => 1,
            'payment_status' => 'Paid',
            'delivered_status' => SM::PENDING,
            'product_price' => 100,
            'final_price' => 100,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => $qty, 'final_price' => 50,
            ]]),
        ]);
    }

    private function changeStatus(Store $store, $orderId, $status, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/order-status-change', [
                'order_id' => $orderId,
                'order_status' => $status,
            ]);
    }

    private function returnProduct(Store $store, $orderId, $productId, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/product-return', [
                'order_id' => $orderId,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);
    }

    private function row(Order $order): object
    {
        return DB::table('orders')->where('id', $order->id)->first();
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /** `store_order_financials.payment_status` -- `paid` until an entitlement reversal runs. */
    private function financialStateOf(Order $order): ?string
    {
        $value = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->value('payment_status');

        return $value === null ? null : (string) $value;
    }

    private function assertRefusedByThePermissionGate($response, string $because): void
    {
        $this->assertNotSame(404, $response->getStatusCode(),
            $because . ' -- the route answered 404, so nothing below is evidence about the handler.');
        $this->assertSame(200, $response->getStatusCode(),
            $because . ' -- the door answered ' . $response->getStatusCode() . '. Its established '
            . 'refusal shape is HTTP 200 with status 0; a 401 would force a logout in the shipped '
            . 'Flutter app and a 500 is a crash, not a refusal.');
        $this->assertSame(0, (int) $response->json('status'), $because . ' -- the request was accepted.');
        $this->assertNotSame(self::NOT_YOURS, (string) $response->json('message'),
            $because . ' -- the answer was the OWNERSHIP refusal, so this principal never reached '
            . 'the permission gate and the case is measuring the wrong control.');
        $this->assertSame(self::REFUSED, (string) $response->json('message'),
            $because . ' -- expected ' . self::REFUSED . ' (the string both sibling admin doors '
            . 'already answer) and got ' . var_export($response->json('message'), true) . '.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE PREMISES
    // ---------------------------------------------------------------------------------------------

    /**
     * THE PRINCIPAL IS OBTAINABLE FROM THE SHIPPED LOGIN DOOR, holding nothing.
     *
     * Driven rather than minted: `createToken()` in a test proves a token works, not that a roleless
     * staff member can GET one. `/api/admin/adminlogin` validates the e-mail, calls `Auth::attempt`,
     * checks the account and owner switches, and issues a token with no reference to roles.
     */
    public function test_the_premise_a_roleless_staff_member_really_gets_a_token_from_the_shipped_login_door(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staff->forceFill(['password' => Hash::make(self::FIXTURE_PASSWORD)])->save();

        $this->assertSame([], $staff->allPermissions()->pluck('name')->all(),
            'Premise: this staff member must hold NOTHING, or the hostile cases below would be '
            . 'measuring a permitted principal.');

        $login = $this->postJson('/api/admin/adminlogin', [
            'email' => $staff->email,
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $login->assertStatus(200);
        $this->assertSame(1, (int) $login->json('status'),
            'The shipped login door refused a roleless staff member: '
            . var_export($login->json('message'), true) . '. If this ever becomes the refusal, this '
            . 'row\'s principal is no longer reachable and the cases below need re-grounding.');

        $token = (string) ($login->json('data.token') ?? $login->json('token') ?? '');

        $this->assertNotSame('', $token,
            'The login answered success but this test could not find the token in the body: '
            . json_encode($login->json()));

        /* And the token really reaches the door under test, rather than being refused as a credential. */
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));
        $response = $this->changeStatus($store, $order->id, SM::CANCEL, $token);

        $this->assertNotSame(401, $response->getStatusCode(),
            'custom.auth refused the token the shipped login door just issued.');
        $this->assertNotSame(self::NOT_YOURS, (string) $response->json('message'),
            'The staff token was refused by the OWNERSHIP limb, so the permission gate is not what '
            . 'this file is measuring. resolveViewableOrder() resolves a staff row to its owning '
            . 'admin through UserPolicy::merchantTenantRoot(), so it should be admitted.');
    }

    /**
     * AND THE WRITER WOULD HAVE ACCEPTED THE TRANSITION, so a refusal below is the gate and not the
     * state machine. Driven with the store OWNER's token on an identical order.
     */
    public function test_the_premise_the_same_cancel_lands_for_the_store_owner(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, $merchant, 5);
        $order = $this->paidOrderWithLine($store, $product);

        $response = $this->changeStatus($store, $order->id, SM::CANCEL,
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'),
            'The store owner was refused: ' . var_export($response->json('message'), true));
        $this->assertSame(SM::STATE_CANCELLED, (int) $this->row($order)->delivered_status,
            'Premise: the cancel did not land even for the owner, so every refusal in this file '
            . 'would hold whether or not a gate exists.');
        $this->assertSame(7, $this->stockOf($product),
            'Premise: the owner\'s cancel did not restore the stock, so the stock assertions below '
            . 'cannot distinguish a refusal from a no-op.');
    }

    // ---------------------------------------------------------------------------------------------
    //  CLAIM 1 -- the staff cancel: entitlement reversed and stock restored
    // ---------------------------------------------------------------------------------------------

    /**
     * A staff member holding NO ROLE cancels their tenant's order: money and goods both move.
     */
    public function test_a_roleless_staff_member_cannot_cancel_an_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $product = $this->productIn($store, $merchant, 5);
        $order = $this->paidOrderWithLine($store, $product);

        $this->assertSame('paid', $this->financialStateOf($order),
            'Premise: store_order_financials must record `paid` for this order, or the entitlement '
            . 'reversal has nothing to reverse and the money half of this case is unobservable.');

        $because = 'A staff member holding NO ROLE cancelled their tenant\'s order through '
            . 'api/{slug}/order-status-change. The writer stamps cancel_date and delivered_status '
            . '= 2, OrderEntitlementReversal walks the merchant\'s wallet credit back, and the '
            . 'handler restores the stock';

        $this->assertRefusedByThePermissionGate(
            $this->changeStatus($store, $order->id, SM::CANCEL, $staff->createToken('t')->plainTextToken),
            $because
        );

        $fresh = $this->row($order);

        $this->assertSame(SM::PENDING, (int) $fresh->delivered_status, $because . ' -- the row moved.');
        $this->assertNull($fresh->cancel_date, $because . ' -- cancel_date was stamped.');
        $this->assertSame('paid', $this->financialStateOf($order),
            $because . ' -- store_order_financials.payment_status is now '
            . var_export($this->financialStateOf($order), true) . ', so the merchant\'s entitlement '
            . 'was reversed by a principal holding nothing.');
        $this->assertSame(5, $this->stockOf($product),
            $because . ' -- products.product_stock moved, so the goods went back on the shelf.');
    }

    /**
     * AND `delivered` LANDS THE SAME WAY -- the state the refund and return workflows are gated on,
     * and after owner decision H2 one nothing can move an order out of.
     */
    public function test_a_roleless_staff_member_cannot_mark_an_order_delivered(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));

        $because = 'A staff member holding NO ROLE marked their tenant\'s order DELIVERED';

        $this->assertRefusedByThePermissionGate(
            $this->changeStatus($store, $order->id, SM::DELIVERED, $staff->createToken('t')->plainTextToken),
            $because
        );

        $fresh = $this->row($order);

        $this->assertSame(SM::PENDING, (int) $fresh->delivered_status, $because . ' -- the row moved.');
        $this->assertNull($fresh->delivery_date, $because . ' -- delivery_date was stamped.');
    }

    // ---------------------------------------------------------------------------------------------
    //  CLAIM 2 -- the loose `==`: a boolean cancels the order and leaves the goods off the shelf
    // ---------------------------------------------------------------------------------------------

    /**
     * `{"order_status": true}` -- the writer branches on `==` and the restock gate compares `===`.
     *
     * `true == 'cancel'` is TRUE under PHP 8 (the string is cast to bool), so the writer's cancel arm
     * runs: `cancel_date`, `delivered_status = 2`, entitlement reversed. The handler's own restock
     * gate then asks `strtolower(trim((string) $x)) === 'cancel'`, and `(string) true` is `'1'`, so
     * the stock is NOT restored. One request, a cancelled and reversed order, and the goods left off
     * the shelf -- a divergence between two spellings of the same comparison, which no test saw
     * because every test posted the literal word.
     *
     * The sibling WEB door is immune for a structural reason rather than a careful one: it hands the
     * writer the literal `'cancel'` and never the request's value.
     */
    public function test_a_boolean_status_cannot_cancel_an_order_at_all(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, $merchant, 5);
        $order = $this->paidOrderWithLine($store, $product);

        $response = $this->changeStatus($store, $order->id, true,
            $merchant->createToken('t')->plainTextToken);

        $this->assertNotSame(404, $response->getStatusCode(), 'The route answered 404.');
        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());

        $fresh = $this->row($order);

        $this->assertSame(SM::PENDING, (int) $fresh->delivered_status,
            'order_status=true (a JSON boolean) cancelled the order: the writer compares '
            . '`$order_status == \'cancel\'` and `true == \'cancel\'` is TRUE under PHP 8. '
            . 'delivered_status reads ' . var_export($fresh->delivered_status, true) . '.');
        $this->assertNull($fresh->cancel_date, 'order_status=true stamped cancel_date.');
        $this->assertSame(5, $this->stockOf($product),
            'order_status=true cancelled the order and the stock did NOT come back, because the '
            . 'restock gate compares `(string) true` === \'cancel\' -- \'1\' !== \'cancel\'. The '
            . 'order is cancelled and reversed with the goods off the shelf.');
        $this->assertSame('paid', $this->financialStateOf($order),
            'order_status=true reversed the merchant\'s entitlement.');
        $this->assertSame(0, (int) $response->json('status'),
            'order_status=true was accepted. A value that is not a string is not one of the six '
            . 'status words, and the writer\'s own "that order status is not recognised" arm is the '
            . 'right answer for it.');
    }

    // ---------------------------------------------------------------------------------------------
    //  CLAIM 3 -- the twin door, which writes stock and money directly
    // ---------------------------------------------------------------------------------------------

    /**
     * `product-return` has the identical gap and is the more expensive one: `Order::product_return()`
     * CREDITS `products.product_stock` and SUBTRACTS the returned line from `orders.final_price`.
     */
    public function test_a_roleless_staff_member_cannot_mark_a_product_returned(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $product = $this->productIn($store, $merchant, 5);
        $order = $this->paidOrderWithLine($store, $product);

        /* product_return() refuses unless the order is DELIVERED, so the premise is set directly. */
        $order->delivered_status = SM::STATE_DELIVERED;
        $order->save();

        $because = 'A staff member holding NO ROLE marked a line returned through '
            . 'api/{slug}/product-return, which credits products.product_stock and reduces '
            . 'orders.final_price';

        $this->assertRefusedByThePermissionGate(
            $this->returnProduct($store, $order->id, $product->id, $staff->createToken('t')->plainTextToken),
            $because
        );

        $this->assertSame(5, $this->stockOf($product),
            $because . ' -- products.product_stock moved.');
        $this->assertEqualsWithDelta(100.0, (float) $this->row($order)->final_price, 0.005,
            $because . ' -- orders.final_price moved.');

        $decoded = json_decode((string) $this->row($order)->product_json, true);

        $this->assertArrayNotHasKey('return', $decoded[0] ?? [], $because . ' -- the line was stamped.');
        $this->assertArrayNotHasKey(Order::LINE_STOCK_RESTORED, $decoded[0] ?? [],
            $because . ' -- the line was stamped with the stock-restored marker.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE PRINCIPALS THE GATE MUST NOT TOUCH
    // ---------------------------------------------------------------------------------------------

    /**
     * THE STORE'S OWNER, HOLDING NOTHING. `callerMayChangeOrderState()` records two earlier packages
     * breaking exactly this, with a test whose message reads *"The predicate is too tight -- that is
     * a defect, not a fix."* A merchant does not implicitly hold `Manage Order`.
     */
    public function test_the_owning_merchant_holding_no_role_is_still_admitted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));

        $this->assertSame([], $merchant->allPermissions()->pluck('name')->all(),
            'Premise: this merchant must hold NOTHING, or the case cannot tell the owner limb from '
            . 'the permission limb.');

        $response = $this->changeStatus($store, $order->id, SM::CONFIRMED,
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(1, (int) $response->json('status'),
            'The store\'s own owner was refused: ' . var_export($response->json('message'), true)
            . '. The owner creates the roles and grants the permissions in their own tenant, so a '
            . 'permission-only gate is a live breakage with no security gain.');
        $this->assertSame(SM::STATE_CONFIRMED, (int) $this->row($order)->delivered_status);
    }

    /** A staff member who HOLDS `Manage Order` is admitted -- the gate is a permission, not a ban. */
    public function test_a_staff_member_holding_manage_order_is_admitted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, 'Manage Order');
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));

        $response = $this->changeStatus($store, $order->id, SM::CONFIRMED,
            $staff->createToken('t')->plainTextToken);

        $this->assertSame(1, (int) $response->json('status'),
            'A staff member holding `Manage Order` was refused: '
            . var_export($response->json('message'), true) . '. The gate is the same permission the '
            . 'sibling admin doors ask for, so granting it must be enough.');
        $this->assertSame(SM::STATE_CONFIRMED, (int) $this->row($order)->delivered_status);
    }

    /**
     * AND THE GRANT IS EVALUATED AGAINST THE ORDER'S OWN STORE, not the caller's `current_store`.
     *
     * `roles.store_id` makes a role per store, and `MerchantPermissions` exists because Laratrust's
     * `allPermissions()` unions every role with no store predicate -- so a staff member granted
     * `Manage Order` in store A would otherwise carry it into store B of the same merchant. The
     * ownership limb does NOT stop that: `resolveViewableOrder()` admits any store in the caller's
     * tenant by design. This case is the one that distinguishes `$order->store_id` from
     * `current_store`, and the two are the same number in every other case in this file.
     */
    public function test_a_grant_in_one_store_does_not_reach_another_store_of_the_same_merchant(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $staff = $this->makeStaff($merchant);               // current_store = storeA
        $this->grantPermission($staff, 'Manage Order');     // the role is created in storeA

        $orderInB = $this->paidOrderWithLine($storeB, $this->productIn($storeB, $merchant, 5));

        $this->assertNotSame((int) $storeA->id, (int) $storeB->id, 'Premise: two distinct stores.');
        $this->assertSame((int) $storeA->id, (int) $staff->current_store,
            'Premise: the staff member\'s grant must live in the OTHER store, or this case cannot '
            . 'tell $order->store_id from current_store.');

        $this->assertRefusedByThePermissionGate(
            $this->changeStatus($storeB, $orderInB->id, SM::CANCEL, $staff->createToken('t')->plainTextToken),
            'A staff member granted `Manage Order` in store A cancelled an order in store B of the '
            . 'same merchant -- the grant was read without a store predicate'
        );

        $this->assertSame(SM::PENDING, (int) $this->row($orderInB)->delivered_status);
    }

    /**
     * THE ASSIGNED DRIVER IS NOT GATED. A `DeliveryBoy` is not a Laratrust subject --
     * `MerchantPermissions::names()` answers `[]` for anything that is not a `User` and `isAbleTo()`
     * on one is a fatal -- and carrying the parcel IS the authorization.
     */
    public function test_the_assigned_driver_is_not_gated(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gap277-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));
        $order->deliveryboy_id = $driver->id;
        $order->save();

        $response = $this->changeStatus($store, $order->id, SM::DELIVERED,
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(1, (int) $response->json('status'),
            'The assigned driver was refused: ' . var_export($response->json('message'), true)
            . '. A permission gate that reached the driver branch would refuse every driver on the '
            . 'platform.');
        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status);
    }

    /**
     * THE PLATFORM OPERATOR IS ADMITTED, and `isAbleTo()` REFUSES them -- there is no super-admin
     * bypass here, so this is the case that fails if the gate is written permission-first.
     */
    public function test_the_platform_operator_is_still_admitted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $order = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));

        $response = $this->changeStatus($store, $order->id, SM::CONFIRMED,
            $operator->createToken('t')->plainTextToken);

        $this->assertSame(1, (int) $response->json('status'),
            'The platform operator was refused: ' . var_export($response->json('message'), true)
            . '. MerchantPermissions::allowsAny() answers the wildcard for them precisely because '
            . 'isAbleTo() does not.');
        $this->assertSame(SM::STATE_CONFIRMED, (int) $this->row($order)->delivered_status);
    }

    /**
     * THE SHOPPER IS NOT GATED EITHER -- they are authorised by owning the order, and GAP-135
     * constrains WHICH word they may say. A permission gate that reached the customer branch would
     * end self-service cancellation for every shopper on the platform.
     */
    public function test_the_owning_shopper_is_not_gated(): void
    {
        [, $store] = $this->makeTenant();
        $customer = Customer::forceCreate([
            'first_name' => 'S', 'last_name' => 'G',
            'email' => 'gap277-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('shopper-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id, 'is_guest' => 0,
            'payment_status' => 'Paid', 'delivered_status' => SM::PENDING,
        ]);

        $response = $this->changeStatus($store, $order->id, SM::CANCEL,
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(1, (int) $response->json('status'),
            'A shopper could not cancel their own order: ' . var_export($response->json('message'), true)
            . '. The permission gate belongs on the User branch only.');
        $this->assertSame(SM::STATE_CANCELLED, (int) $this->row($order)->delivered_status);
    }

    /**
     * THE FOUR DOORS NOW GIVE ONE ANSWER, and this case is what makes "one definition" a
     * measurement rather than a claim about file layout.
     *
     * `api/{slug}/order-status-change` is `Api\ApiController`; `api/{slug}/order-cancel` is
     * `Api\DashboardController`, whose `callerMayChangeOrderState()` now delegates to the same
     * predicate. The same principal is driven at both: refused when they hold nothing, admitted
     * when they own the store. **The two transports differ on purpose** -- owner decision H3 keeps
     * each door's established shape, so the storefront answers HTTP 200 `status: 0` and the admin
     * door answers 403 -- and that difference is asserted rather than smoothed over, because a
     * reader finding two codes should find this case and not conclude one door is wrong.
     */
    public function test_the_storefront_and_the_admin_door_answer_the_same_principal_alike(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staffToken = $staff->createToken('t')->plainTextToken;

        $storefront = $this->changeStatus(
            $store,
            $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5))->id,
            SM::CANCEL,
            $staffToken
        );

        $adminDoorOrder = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));
        $adminDoor = $this->withHeaders(['Authorization' => 'Bearer ' . $staffToken])
            ->postJson('/api/' . $store->slug . '/order-cancel', ['order_id' => $adminDoorOrder->id]);

        $this->assertSame(0, (int) $storefront->json('status'),
            'The storefront door admitted a principal the admin door refuses.');
        $this->assertSame(403, $adminDoor->getStatusCode(),
            'The admin door no longer answers 403 for this principal, so the two doors have '
            . 'drifted: ' . $adminDoor->getContent());
        $this->assertSame(SM::PENDING, (int) $this->row($adminDoorOrder)->delivered_status,
            'The admin door cancelled the order for a principal holding nothing.');

        /* And the owner is admitted at both, so the agreement is not "both refuse everybody". */
        $ownerToken = $merchant->createToken('t')->plainTextToken;
        $ownerStorefrontOrder = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));
        $ownerAdminOrder = $this->paidOrderWithLine($store, $this->productIn($store, $merchant, 5));

        $this->assertSame(1, (int) $this->changeStatus($store, $ownerStorefrontOrder->id, SM::CANCEL, $ownerToken)->json('status'),
            'The store owner was refused at the storefront door.');

        $ownerAdmin = $this->withHeaders(['Authorization' => 'Bearer ' . $ownerToken])
            ->postJson('/api/' . $store->slug . '/order-cancel', ['order_id' => $ownerAdminOrder->id]);

        $this->assertNotSame(403, $ownerAdmin->getStatusCode(),
            'The store owner was refused at the admin door: ' . $ownerAdmin->getContent());
        $this->assertSame(SM::STATE_CANCELLED, (int) $this->row($ownerAdminOrder)->delivered_status,
            'The admin door did not cancel the order for its own store\'s owner.');
    }

    /**
     * AND A USER FROM ANOTHER TENANT STILL GETS THE OWNERSHIP REFUSAL, not the permission one. The
     * two controls are different questions and the order of the answers is part of the contract:
     * a caller with no claim on the order must not learn that only a permission was missing.
     */
    public function test_a_user_of_another_tenant_still_gets_the_ownership_refusal(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();
        $order = $this->paidOrderWithLine($storeA, $this->productIn($storeA, $merchantA, 5));

        $response = $this->changeStatus($storeA, $order->id, SM::CANCEL,
            $merchantB->createToken('t')->plainTextToken);

        $this->assertSame(0, (int) $response->json('status'), 'A foreign merchant was accepted.');
        $this->assertSame(self::NOT_YOURS, (string) $response->json('message'),
            'A caller with no claim on this order was told a PERMISSION was missing, which confirms '
            . 'the order exists. The ownership refusal must answer first.');
        $this->assertSame(SM::PENDING, (int) $this->row($order)->delivered_status);
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ReturnInspection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RET-002 — THE DOOR THAT RECORDS A RETURNED ITEM'S CONDITION.
 *
 * `OrderController::order_return_inspection()` is the production caller of
 * `App\Services\ReturnInspection::record()`. Without it the gate the three restock doors consult
 * would be the **F237 shape this repository keeps re-finding** — a mechanism recorded as DONE whose
 * production callers never existed, so a guard that read as working was inert for every real order.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * DRIVEN THROUGH THE HANDLER, NOT THE ROUTE, AND THAT IS NOT A SHORTCUT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * CLAUDE.md: *a handler-level check behind an `abort()`ing middleware is invisible to an HTTP
 * test* — the middleware answers 403 and the handler is never reached, so the route returns 403
 * whether or not the handler checks anything. `PlanController::planDisable` ADMITTED a `Customer`
 * typed `super admin` behind a gate whose HTTP tests were green (`GAP-307`). The authorization on
 * this door is `$this->authorize('updateStatus', $order)` **inside** the handler, so the handler is
 * what these cases call, and the refusals are keyed on the REASON: an `AuthorizationException`
 * (only the policy raises it) is a different fact from a 422 (only the handler's own validation
 * does) and from a 404 (only the missing row does).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * A REAL MERCHANT, NOT THE OPERATOR, FOR THE OWNERSHIP CASE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `OrderPolicy::allow()` tests `isPlatformAdmin` FIRST and returns true, so a `super admin`
 * principal short-circuits the gate before it asks about ownership — a suite that drove only the
 * operator would prove nothing about `sameStore()`. The merchant here is `type = admin` and holds
 * `Manage Order` through the shared global `admin` role, which is what
 * `Utility::addNewData()` really grants; the permission is GRANTED rather than bypassed so the
 * cross-store refusal is the ownership limb and not the permission limb. The operator is driven
 * separately, because CLAUDE.md's standing fact is that `isAbleTo()` alone REFUSES the platform
 * operator and an operator-last ordering would lock them out of the support workflow.
 */
class ReturnConditionInspectionDoorTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 11;
    private const STORE_B = 22;

    private const MERCHANT_A = 7;
    private const MERCHANT_B = 8;
    private const OPERATOR = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->seedPrincipals();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        parent::tearDown();
    }

    public function test_the_owning_merchant_records_a_verdict(): void
    {
        $this->actAs(self::MERCHANT_A);
        [$order, $product] = $this->deliveredOrder(self::STORE_A);

        $response = $this->callDoor($order, $product->id, 0, ReturnInspection::VERDICT_DAMAGED);

        $this->assertSame(200, $response->status());
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertFalse(
            $response->getData(true)['resellable'],
            'the door echoes the DERIVED answer, so the merchant is told what their verdict means'
        );

        $row = DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->first();
        $this->assertNotNull($row, 'a verdict must be stored');
        $this->assertSame(ReturnInspection::VERDICT_DAMAGED, $row->verdict);
        $this->assertSame(0, (int) $row->resellable);
        $this->assertSame(self::MERCHANT_A, (int) $row->inspected_by, 'the actor is recorded');
    }

    public function test_the_verdict_is_filed_under_the_orders_store_and_not_the_actors(): void
    {
        /*
         * THE P1-SEC-71 SHAPE, PINNED. The platform operator's own `current_store` is store A while
         * the order belongs to store B. A door that took the store from `getCurrentStore()` — or
         * worse, from request input, which CLAUDE.md records as having produced four separate
         * defects because it only compares the caller's own two values — would file this verdict
         * under a store the goods are not in, and the gate (keyed on the ORDER's store) would then
         * never read it. The verdict would exist and block nothing.
         */
        $this->actAs(self::OPERATOR);
        [$order, $product] = $this->deliveredOrder(self::STORE_B);

        $this->assertSame(
            self::STORE_A,
            (int) getCurrentStore(),
            'the premise: the operator\'s own current store is NOT the order\'s store'
        );

        $this->callDoor($order, $product->id, 0, ReturnInspection::VERDICT_DAMAGED);

        $row = DB::table(ReturnInspection::TABLE)->where('order_id', $order->id)->first();
        $this->assertSame(
            self::STORE_B,
            (int) $row->store_id,
            'the verdict must be filed under the ORDER\'s store, which is where the goods are'
        );

        $this->assertTrue(
            app(ReturnInspection::class)->blocksRestock(self::STORE_B, $order->id, $product->id, 0),
            'and the gate, which reads the order\'s store, must find it'
        );
    }

    public function test_the_platform_operator_is_admitted(): void
    {
        /*
         * CLAUDE.md: `isAbleTo()` alone REFUSES the platform operator, so an authorization check
         * that did not test operator status FIRST would lock them out of the support workflow.
         * `OrderPolicy::allow()` does, and this is the case that would catch a reordering.
         */
        $this->actAs(self::OPERATOR);
        [$order, $product] = $this->deliveredOrder(self::STORE_A);

        $this->assertSame(
            200,
            $this->callDoor($order, $product->id, 0, ReturnInspection::VERDICT_RESELLABLE)->status()
        );
    }

    public function test_another_stores_merchant_is_refused_by_the_policy(): void
    {
        $this->actAs(self::MERCHANT_A);
        [$order, $product] = $this->deliveredOrder(self::STORE_A);

        /* The permission limb is satisfied for merchant B too — see the class docblock — so the
           refusal below is the OWNERSHIP limb and nothing else. */
        $this->actAs(self::MERCHANT_B);
        $this->assertTrue(
            User::withoutGlobalScopes()->find(self::MERCHANT_B)->isAbleTo('Manage Order'),
            'the premise: merchant B HOLDS the permission, so a refusal can only be about ownership'
        );

        try {
            $this->callDoor($order, $product->id, 0, ReturnInspection::VERDICT_RESELLABLE);
            $this->fail('another store\'s merchant recorded a verdict against this order');
        } catch (AuthorizationException $refusedByThePolicy) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            0,
            DB::table(ReturnInspection::TABLE)->count(),
            'and nothing was written'
        );
    }

    public function test_an_unrecognised_verdict_is_refused_with_its_own_status(): void
    {
        $this->actAs(self::MERCHANT_A);
        [$order, $product] = $this->deliveredOrder(self::STORE_A);

        $response = $this->callDoor($order, $product->id, 0, 'slightly-dented');

        $this->assertSame(422, $response->status(), 'the handler\'s own validation, not the policy');
        $this->assertSame(0, DB::table(ReturnInspection::TABLE)->count());
    }

    public function test_a_product_that_is_not_on_the_order_is_refused(): void
    {
        $this->actAs(self::MERCHANT_A);
        [$order] = $this->deliveredOrder(self::STORE_A);
        $strangerProduct = $this->makeProduct(self::STORE_B);

        $response = $this->callDoor($order, $strangerProduct->id, 0, ReturnInspection::VERDICT_DAMAGED);

        $this->assertSame(422, $response->status());
        $this->assertSame(
            0,
            DB::table(ReturnInspection::TABLE)->count(),
            'a verdict against a line the order does not carry would be junk filed under a tenant'
        );
    }

    public function test_a_variant_line_is_told_apart_from_the_products_bare_line(): void
    {
        $this->actAs(self::MERCHANT_A);
        $product = $this->makeProduct(self::STORE_A);
        $order = $this->makeOrder(self::STORE_A, [[
            'product_id' => $product->id, 'variant_id' => 404, 'qty' => 1,
            'price' => 10, 'final_price' => 10, 'name' => 'Variant line',
        ]]);

        /* The order carries the product only AS a variant line, so a verdict against
           `variant_id = 0` names a line that is not there. */
        $this->assertSame(
            422,
            $this->callDoor($order, $product->id, 0, ReturnInspection::VERDICT_DAMAGED)->status()
        );

        $this->assertSame(
            200,
            $this->callDoor($order, $product->id, 404, ReturnInspection::VERDICT_DAMAGED)->status()
        );
    }

    public function test_a_missing_order_is_a_not_found_and_not_a_refusal(): void
    {
        $this->actAs(self::MERCHANT_A);

        /*
         * KEYED ON THE REASON. CLAUDE.md: *a gateless handler's own "not found" branch is also a
         * 302, so a 302 from a missing row and a 302 from a gate are the same status and opposite
         * facts.* Here the two are deliberately different codes, and this case is what keeps them
         * that way — a 403 here would mean the policy had been handed a null order.
         */
        $request = Request::create('/order-return-inspection', 'POST', [
            'id' => 999999, 'product_id' => 1, 'variant_id' => 0,
            'verdict' => ReturnInspection::VERDICT_DAMAGED,
        ]);
        $request->setLaravelSession(app('session.store'));

        $this->assertSame(404, (new OrderController())->order_return_inspection($request)->status());
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function callDoor(Order $order, $productId, $variantId, string $verdict)
    {
        $request = Request::create('/order-return-inspection', 'POST', [
            'id' => $order->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'verdict' => $verdict,
            'notes' => 'inspected at the counter',
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->order_return_inspection($request);
    }

    private function actAs(int $userId): void
    {
        Auth::logout();
        Auth::login(User::withoutGlobalScopes()->find($userId));
        (new User())->flushCache();
    }

    /**
     * Two merchants and one operator, plus the shared global `admin` role that really carries
     * `Manage Order`.
     *
     * The role is `store_id => null` and global, which is how `Utility::addNewData()` seeds it:
     * BOTH merchants hold it, so the cross-store case measures ownership rather than permission.
     */
    private function seedPrincipals(): void
    {
        DB::table('users')->insert([
            [
                'id' => self::MERCHANT_A, 'name' => 'Merchant A', 'email' => 'a@example.test',
                'type' => 'admin', 'password' => bcrypt('secret'), 'current_store' => self::STORE_A,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => self::MERCHANT_B, 'name' => 'Merchant B', 'email' => 'b@example.test',
                'type' => 'admin', 'password' => bcrypt('secret'), 'current_store' => self::STORE_B,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => self::OPERATOR, 'name' => 'Operator', 'email' => 'op@example.test',
                'type' => \App\Policies\UserPolicy::PLATFORM_ADMIN, 'password' => bcrypt('secret'),
                'current_store' => self::STORE_A, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        DB::table('roles')->insert([
            'id' => 3, 'name' => 'admin', 'display_name' => 'admin', 'guard_name' => 'web',
            'store_id' => null, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permissions')->insert([
            'id' => 4, 'name' => 'Manage Order', 'display_name' => 'Manage Order',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permission_role')->insert(['permission_id' => 4, 'role_id' => 3]);
        DB::table('role_user')->insert([
            ['role_id' => 3, 'user_id' => self::MERCHANT_A, 'user_type' => User::class],
            ['role_id' => 3, 'user_id' => self::MERCHANT_B, 'user_type' => User::class],
        ]);

        DB::table('customers')->insert([
            'id' => 1, 'first_name' => 'Return', 'last_name' => 'Buyer',
            'email' => 'buyer@example.test', 'store_id' => self::STORE_A,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: Order, 1: Product} */
    private function deliveredOrder(int $storeId): array
    {
        $product = $this->makeProduct($storeId);
        $order = $this->makeOrder($storeId, [[
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2,
            'price' => 10, 'final_price' => 20, 'name' => 'Line',
        ]]);

        return [$order, $product];
    }

    private function makeProduct(int $storeId): Product
    {
        $product = new Product();
        $product->forceFill([
            'name' => 'Product ' . uniqid('', false), 'track_stock' => 1,
            'stock_order_status' => 'not_allow', 'price' => 10, 'product_stock' => 4,
            'variant_product' => 0, 'store_id' => $storeId, 'created_by' => self::MERCHANT_A,
        ]);
        $product->save();

        return $product;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function makeOrder(int $storeId, array $lines): Order
    {
        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'ORD-' . uniqid('', false), 'order_date' => now(),
            'customer_id' => 1, 'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'final_price' => 20, 'product_price' => 20, 'delivery_price' => 0,
            'tax_price' => 0, 'coupon_price' => 0, 'payment_type' => 'cod',
            'payment_status' => 'Paid', 'delivered_status' => 1, 'store_id' => $storeId,
        ]);
        $order->save();

        return $order;
    }
}

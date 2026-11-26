<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\StockMovement;
use App\Support\Orders\FulfilmentStateMachine as SM;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\StripsSourceComments;

/**
 * THE MACHINE AND THE WRITER DISAGREED ABOUT WHAT A STATUS WORD IS.  GAP-135, the `==` clause.
 * =================================================================================================
 *
 * WHAT WAS WRONG, AND IT IS ONE CHARACTER OF PHP.
 *
 * `App\Support\Orders\FulfilmentStateMachine::governs()` decides whether the machine has anything
 * to say about a status word, and it compares STRICTLY -- `in_array($word, [...], true)`. So for a
 * non-string the machine answers "I do not govern this", no transition rule is applied, and the
 * value falls through to `Order::order_status_change()`'s own branch chain.
 *
 * That branch chain compares LOOSELY: `if ($order_status == 'cancel')`. Under PHP 8 a string is
 * converted to BOOL when compared with a bool, so `true == 'cancel'` is **TRUE** -- measured, not
 * reasoned:
 *
 *     value     writer's `== 'cancel'`   machine's `governs()`   door's restock gate
 *     'cancel'  MATCHES                  governed               restores
 *     true      MATCHES                  UNGOVERNED             DOES NOT RESTORE
 *     false     no                       UNGOVERNED             --
 *     1         no                       UNGOVERNED             --      (`1 == 'cancel'` is false
 *     0         no                       UNGOVERNED             --       in PHP 8: 'cancel' is
 *     null      no                       UNGOVERNED             --       non-numeric, so both
 *     ''        no                       UNGOVERNED             --       sides compare as strings)
 *     ['a']     no                       UNGOVERNED             --
 *     1.0       no                       UNGOVERNED             --
 *     Stringable('cancel')  MATCHES      UNGOVERNED             DOES NOT RESTORE
 *
 * TWO OF NINE SHAPES, AND THE FIRST IS THE WORST ONE. `true` ran the CANCEL arm: `cancel_date`
 * stamped,
 * `delivered_status` 0 -> 2, `$order->save()`, and then
 * `OrderEntitlementReversal::reverseOrLog()` -- the merchant's entitlement walked back. And every
 * door's stock restore is gated on the STRING, `strtolower(trim((string) $x)) === 'cancel'`, which
 * for `true` is `'1'`. So the money reversed and **the goods stayed off the shelf**: the exact
 * inverse of P4-F034, produced by the same value in one request.
 *
 * THE SECOND SHAPE IS WHY THE FIX IS `is_string()` AND NOT A `(string)` CAST. An object with
 * `__toString()` returning `'cancel'` diverges identically -- measured: `$obj == 'cancel'` is TRUE
 * because PHP 8 converts the object, `in_array($obj, [...], true)` is FALSE. It cannot arrive from
 * a request body, since JSON yields no objects, but the writer is a public static method the whole
 * application and every vendored module can call, so it is LATENT rather than absent. A cast would
 * have produced the literal `'cancel'` and kept this one open while closing the boolean, and would
 * have been a fatal on the array shape.
 *
 * -------------------------------------------------------------------------------------------------
 * WHICH DOORS CARRIED IT, measured against the three first-party callers of the shared writer
 * -------------------------------------------------------------------------------------------------
 *
 *   `Api\DashboardController::statusChange`  POST api/admin/status-change   passed `$request->
 *       delivered` RAW. THE LIVE ONE: no CSRF (the `api` group), a never-expiring Sanctum bearer
 *       token, and `OrderStateChangeAuthorization::allows()` admits the ASSIGNED DRIVER with no
 *       permission at all -- so a courier, who is a third party to the merchant, could cancel the
 *       merchant's order, reverse the merchant's entitlement and keep the goods off the shelf.
 *   `OrderController::order_status_change`   POST order-status-change/{id}  passed it RAW too. Its
 *       own emptiness guard is `trim((string) $request->input('delivered', '')) === ''`, and
 *       `(string) true` is `'1'`, so the guard waves a boolean through. Reachable on a JSON body;
 *       CSRF means the merchant's own session rather than a third party, so it is the lesser leg
 *       and is still a leg.
 *   `Api\ApiController::order_status_change` POST api/{slug}/order-status-change was ALREADY
 *       hardened, under GAP-277, with `is_string($request->order_status) ? ... : ''` -- and that
 *       fix's own docblock states the rule this file generalises: *"only a string reaches
 *       either"*. One door had it; the writer the other four share did not.
 *
 * The vendored `LotteryPrizes` door passes `$request->delivered` raw as well and
 * `packages/workdo/` must not be edited -- which is itself the argument for fixing the WRITER
 * rather than each caller: the vendored door inherits the fix and cannot be given one.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE FIX IS IN THE WRITER AND ASKS THE MACHINE
 * -------------------------------------------------------------------------------------------------
 *
 * The defect is not "a door forgot to validate". It is that TWO definitions of "the word `cancel`"
 * existed and disagreed, which is the hazard `FulfilmentStateMachine`'s own class docblock was
 * written against. So the machine now owns the answer --
 * {@see \App\Support\Orders\FulfilmentStateMachine::wordOf()} -- and the writer asks it once,
 * BEFORE `refuseIllegalTransition()`, so the value the machine judged is character-for-character
 * the value its branch chain acts on. All five sharers of the writer inherit it, the vendored one
 * included.
 *
 * NOTHING THE PRODUCT DOES CHANGES. `wordOf()` returns a string unchanged, so all six governed
 * words behave exactly as before; a non-string becomes `''`, which no arm matches, so the answer is
 * the writer's own established *"That order status is not recognised."* (F81) -- the same answer
 * `''`, `null`, `0` and `['a']` already got. The only behaviours that change are `true` and the
 * `Stringable` shape above, and no client sends either -- measured in `StoreSyria_app98` itself
 * rather than inferred from the server: the status picker is `const statusIds = [0, 1, 2, 3, 4, 5, 6]`
 * (`payment_status_bottom_sheet.dart:367`), the app's ONLY status endpoint is
 * `/orders/update-status` (`base_api.dart:143`) -- the INTEGER door, which writes
 * `delivered_status` itself and never reaches this writer -- and a grep for a `delivered` request
 * key across the whole app finds only translation strings, so the shipped app does not call the
 * word door at all. The two web controls post a `<select>` value.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW THIS FILE AVOIDS THE FOUR WAYS A TEST HERE PASSES FOR THE WRONG REASON
 * -------------------------------------------------------------------------------------------------
 *
 *  1. THE STATE, NOT THE STATUS CODE. `ApiResponser::error()` answers HTTP 200 with `{status: 0}`,
 *     so a 200 proves nothing. Every case asserts `orders.delivered_status`, `orders.cancel_date`
 *     and `products.product_stock`.
 *  2. A PREMISE PER PRINCIPAL. `isAbleTo()` has no super-admin bypass and `Manage Order` is seeded
 *     by nothing on a from-source database, so an unprivileged fixture is refused BEFORE the
 *     handler and "the order stayed Pending" is then vacuously true. Each principal's refusal case
 *     is preceded by that same principal landing the real word `'cancel'` on a byte-identical
 *     fixture.
 *  3. THE CACHE. `Order` and `Product` are `Cachable` and the suite runs `CACHE_STORE=array`. The
 *     order row is read through `DB::table()` (the raw table is the arbiter of "it did not move"),
 *     and the stock through Eloquent (the production restore path flushes, a raw read would not see
 *     it) -- with a premise case asserting the two agree, so neither can make this file lie.
 *  4. BOTH DIRECTIONS. `test_every_governed_word_still_lands_*` drives all six real words, so a
 *     guard that refused everything -- which would pass every hostile case here -- fails.
 *
 * WHICH CASES ACTUALLY FAIL ON A REVERT, stated so that 28 green is not read as 28 defect cases.
 * **EIGHT**, measured by deleting the writer's call outright: the three admin-API principals, the
 * web door, the writer's `boolean true` and `stringable object` data sets, the established-sentence
 * case, and the source ratchet (the revert removes the `$date` half too). The other twenty are
 * premises (five) and over-reach guards (fifteen: the seven non-string shapes that were ALREADY
 * refused, the six governed words, the customer-token case and the padded/miscased case) -- green
 * before and after the fix on purpose, because their job is to fail if the fix goes too FAR rather
 * than not far enough.
 *
 * TWO FURTHER MUTATIONS, both caught, and one DISCLOSURE. Removing the guard and the `: string`
 * return type together reddens the same six behavioural cases; removing only the `$date` half
 * reddens only the ratchet, which is correct, because nothing in this suite can reach the module
 * arm behaviourally and that test's own docblock says why. **Removing the `is_string()` guard
 * ALONE reddens only two** -- `null` and `array`, which raise a TypeError -- because PHP's coercive
 * return typing on `: string` turns `true` into `'1'` by itself. So the return type is part of the
 * control rather than decoration, and that is recorded here rather than left to look like
 * belt-and-braces.
 *
 * @see \App\Models\Order::order_status_change()
 * @see \App\Support\Orders\FulfilmentStateMachine::wordOf()
 * @see \App\Http\Controllers\Api\DashboardController::statusChange()
 * @see \App\Http\Controllers\OrderController::order_status_change()
 */
class OrderStatusWordIsNotABooleanTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');

        $merchant = $this->makeMerchant();
        $this->store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $this->store->id;
        $merchant->save();

        /* A real Laratrust grant. `permissions_as_gates` is false here, so `$user->can()` is
           always false and every order gate reaches `isAbleTo()`; without this the owner is
           refused at the gate and no stock assertion below measures the writer. */
        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        $this->merchant = User::find($merchant->id);
    }

    // =============================================================================================
    //  FIXTURES
    // =============================================================================================

    private function productIn(int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'boolword-' . uniqid('', false),
            'slug' => 'boolword-' . uniqid('', false),
            'price' => 100,
            'sale_price' => 100,
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->store->created_by,
        ]);
    }

    /**
     * A PAID, PENDING order with one readable line whose out-movement is on record.
     *
     * Every one of those four is load-bearing: `product_json` the restore loop cannot read is
     * skipped with a log line, and a line with no `stock_movements` OUT row reads UNKNOWN while a
     * row for a different line reads NOT_MOVED -- both are correct behaviour and both would make a
     * stock figure measure the fixture instead of the door.
     */
    private function pendingOrderWith(Product $product, int $qty, array $overrides = []): Order
    {
        $order = $this->makeOrder($this->store, array_merge([
            'product_order_id' => 'BOOLWORD-' . strtoupper(uniqid('', false)),
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => $qty,
                'price' => 100,
                'variant_id' => 0,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => 100,
            'final_price' => 100,
            'payment_status' => 'Paid',
            'delivered_status' => SM::PENDING,
        ], $overrides));

        StockMovement::record([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'direction' => StockMovement::OUT,
            'quantity' => $qty,
            'applied' => true,
            'reason' => StockMovement::REASON_ORDER_PLACE,
        ]);

        return $order;
    }

    private function driver(): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid('', false),
            'email' => 'boolword-drv-' . uniqid('', false) . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $this->merchant->id,
            'store_id' => $this->store->id,
        ]);
    }

    private function shopperIn(): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Boolword',
            'last_name' => 'Shopper',
            'email' => 'boolword-cust-' . uniqid('', false) . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('shopper-password-not-real'),
            'store_id' => $this->store->id,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    private function token($principal): string
    {
        return $principal->createToken('auth_token')->plainTextToken;
    }

    /** `POST api/admin/status-change` -- the admin/driver API door. */
    private function adminApiStatusChange($orderId, $delivered, string $bearer)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson('/api/admin/status-change', [
                'id' => $orderId,
                'delivered' => $delivered,
            ]);
    }

    /** The raw table is the arbiter of "the row did not move": `Order` is `Cachable`. */
    private function row(Order $order): object
    {
        $fresh = DB::table('orders')->where('id', $order->id)->first();
        $this->assertNotNull($fresh, 'the order row vanished');

        return $fresh;
    }

    /** Through Eloquent: the production restore path flushes the model cache, a raw read would not. */
    private function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    /**
     * NOTHING MOVED: not the state, not the settlement stamp, not the goods.
     *
     * The three together are what make this a measurement of the defect rather than of the HTTP
     * envelope. `cancel_date` is named separately because the cancel arm stamps it BEFORE the
     * save, so a half-run arm shows up here even if some later write were rolled back.
     */
    private function assertOrderUntouched(Order $order, Product $product, int $expectedStock, string $because): void
    {
        $fresh = $this->row($order);

        $this->assertSame(SM::PENDING, (int) $fresh->delivered_status,
            $because . ' -- orders.delivered_status reads '
            . var_export($fresh->delivered_status, true) . '. 2 means the writer\'s cancel arm ran '
            . 'on a value the state machine never governed.');
        $this->assertNull($fresh->cancel_date,
            $because . ' -- orders.cancel_date was stamped, so the cancel arm ran.');
        $this->assertSame($expectedStock, $this->stockOf($product),
            $because . ' -- products.product_stock moved. The cancel arm reverses the merchant\'s '
            . 'entitlement and the caller\'s restore is gated on the STRING \'cancel\', so a '
            . 'boolean took the money back and left the goods off the shelf.');
    }

    // =============================================================================================
    //  PREMISES -- without these every refusal below holds for the wrong reason
    // =============================================================================================

    /**
     * THE MACHINE NEVER GOVERNED A BOOLEAN, so no transition rule was ever applied to one and the
     * writer's arms were the only thing deciding. This is the disagreement itself, asserted.
     */
    public function test_premise_the_state_machine_does_not_govern_a_non_string_word(): void
    {
        foreach ([true, false, 1, 0, null, '', ['a']] as $value) {
            $this->assertFalse(SM::governs($value),
                'FulfilmentStateMachine::governs() answered TRUE for '
                . var_export($value, true) . '. Its comparison is strict on purpose; if it ever '
                . 'governs a non-string, the writer\'s normalisation below is judging a different '
                . 'value from the machine and this whole file is measuring the wrong thing.');
        }

        foreach ([SM::CANCEL, SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED, SM::DELIVERED, SM::RETURNED] as $word) {
            $this->assertTrue(SM::governs($word),
                'FulfilmentStateMachine::governs() no longer governs ' . $word
                . ', so the machine has stopped applying rules to a real word.');
        }
    }

    /**
     * PHP's OWN BEHAVIOUR, pinned, because the whole defect rests on it and a reader should not
     * have to take it on trust. If a future PHP changes this, the fix stops being load-bearing and
     * this case says so rather than leaving the file looking like belt-and-braces.
     */
    public function test_premise_php_compares_true_equal_to_the_literal_cancel(): void
    {
        $this->assertTrue(true == SM::CANCEL, // phpcs:ignore -- the comparison IS the measurement
            'PHP no longer converts the string to bool here, so the loose comparison in '
            . 'Order::order_status_change() is no longer reachable by a boolean.');

        foreach ([false, 1, 0, null, ''] as $value) {
            $this->assertFalse($value == SM::CANCEL, // phpcs:ignore -- ditto
                var_export($value, true) . ' now compares equal to \'cancel\', which widens this '
                . 'defect beyond the one value this file measured.');
        }
    }

    /**
     * THE RAW READ AND THE ELOQUENT READ AGREE. Both models here are `Cachable` under
     * `CACHE_STORE=array`; three measured false readings in this codebase came from mixing them.
     */
    public function test_premise_the_reads_in_this_file_are_not_stale(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $this->assertSame(
            (int) $this->row($order)->delivered_status,
            (int) Order::withoutGlobalScopes()->find($order->id)->delivered_status,
            'the raw row and the Eloquent model disagree about delivered_status, so a stale cached '
            . 'read could make any assertion in this file pass or fail for the wrong reason'
        );
        $this->assertSame(4, $this->stockOf($product));
    }

    /**
     * THE REAL WORD LANDS FOR THE ASSIGNED DRIVER, through the live door, on the identical
     * fixture -- state, settlement stamp and goods all move. This is the case that makes the
     * driver refusal below a measurement: `OrderStateChangeAuthorization` admits the assigned
     * driver with no permission, and if it did not, "the order stayed Pending" would be the
     * authorization refusing rather than the word being rejected.
     */
    public function test_premise_the_assigned_driver_can_cancel_with_the_real_word(): void
    {
        $product = $this->productIn(4);
        $driver = $this->driver();
        $order = $this->pendingOrderWith($product, 3, ['deliveryboy_id' => $driver->id]);

        $response = $this->adminApiStatusChange($order->id, SM::CANCEL, $this->token($driver));

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(SM::STATE_CANCELLED, (int) $this->row($order)->delivered_status,
            'the assigned driver could not cancel with the literal \'cancel\'. Body: '
            . $response->getContent());
        $this->assertNotNull($this->row($order)->cancel_date, 'the cancel arm did not run');
        $this->assertSame(7, $this->stockOf($product),
            'the real word credited the 3 units back, so the restore path works on this fixture '
            . 'and a boolean leaving the stock at 4 is the door losing the goods.');
    }

    /** The same, for the store owner, so the owner refusal below is not the gate refusing. */
    public function test_premise_the_store_owner_can_cancel_with_the_real_word(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $response = $this->adminApiStatusChange($order->id, SM::CANCEL, $this->token($this->merchant));

        $this->assertSame(SM::STATE_CANCELLED, (int) $this->row($order)->delivered_status,
            'the store owner could not cancel with the literal \'cancel\'. Body: '
            . $response->getContent());
        $this->assertSame(7, $this->stockOf($product));
    }

    // =============================================================================================
    //  THE DEFECT, PER PRINCIPAL
    // =============================================================================================

    /**
     * THE LIVE LEG. A courier is a THIRD PARTY to the merchant, holds a never-expiring Sanctum
     * token, and reaches this door with no permission because carrying the parcel is the
     * authorization. One boolean reversed the merchant's entitlement and kept the goods.
     */
    public function test_a_boolean_cannot_cancel_through_the_admin_api_door_as_the_assigned_driver(): void
    {
        $product = $this->productIn(4);
        $driver = $this->driver();
        $order = $this->pendingOrderWith($product, 3, ['deliveryboy_id' => $driver->id]);

        $this->adminApiStatusChange($order->id, true, $this->token($driver));

        $this->assertOrderUntouched($order, $product, 4,
            'POST api/admin/status-change with {"delivered": true} from the ASSIGNED DRIVER');
    }

    public function test_a_boolean_cannot_cancel_through_the_admin_api_door_as_the_store_owner(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $this->adminApiStatusChange($order->id, true, $this->token($this->merchant));

        $this->assertOrderUntouched($order, $product, 4,
            'POST api/admin/status-change with {"delivered": true} from the STORE OWNER');
    }

    /**
     * TENANT STAFF HOLDING `Manage Order`. A separate principal from the owner: the owner is
     * admitted by `OrderStateChangeAuthorization`'s ownership limb without holding anything, staff
     * by the permission limb, so a fix that only covered one would show here.
     */
    public function test_a_boolean_cannot_cancel_through_the_admin_api_door_as_tenant_staff(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Order');

        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $this->adminApiStatusChange($order->id, true, $this->token(User::find($staff->id)));

        $this->assertOrderUntouched($order, $product, 4,
            'POST api/admin/status-change with {"delivered": true} from TENANT STAFF holding '
            . '"Manage Order"');
    }

    /**
     * THE THIRD PRINCIPAL CLASS, driven because CLAUDE.md's rule is that a probe of one proves
     * nothing: `CustomAuth` logs a token's owner into whichever guard matches its TOKENABLE class,
     * so a `Customer` token passes the middleware with `auth('web')` and `auth('deliveryboy')` both
     * null. This door is closed to them a limb EARLIER than the word rule --
     * `resolveChangeableOrder()` carries a delivery-boy branch and a `User` branch and no customer
     * branch at all -- so this case asserts the ownership refusal rather than the word refusal, and
     * says which.
     *
     * BOTH VALUES, which is the point: a shopper gets the same answer for the real word as for the
     * boolean, so this measures the door being shut to them and not the fix. Driven on THEIR OWN
     * order (`customer_id` set, `is_guest = 0`), the most favourable fixture for the attacker.
     */
    public function test_a_customer_token_reaches_this_door_at_all_with_neither_value(): void
    {
        $product = $this->productIn(4);
        $shopper = $this->shopperIn();
        $order = $this->pendingOrderWith($product, 3, [
            'customer_id' => $shopper->id,
            'is_guest' => 0,
        ]);

        foreach ([true, SM::CANCEL] as $value) {
            $this->adminApiStatusChange($order->id, $value, $this->token($shopper));

            $this->assertOrderUntouched($order, $product, 4,
                'POST api/admin/status-change with ' . var_export($value, true) . ' from a '
                . 'CUSTOMER token on their OWN order. `resolveChangeableOrder()` has no customer '
                . 'branch, so this door should refuse them before any word is judged; if this '
                . 'reddens, a customer branch has appeared and the shopper vocabulary rule '
                . '(`FulfilmentStateMachine::refusalForShopper()`, which this door does NOT ask) '
                . 'would have to be asked here too.');
        }
    }

    /**
     * THE MERCHANT WEB DOOR. Its own emptiness guard is `trim((string) $request->input(...)) ===
     * ''`, and `(string) true` is `'1'`, so the guard passes a boolean through to the same writer.
     * CSRF bounds this to the merchant's own session, which makes it the lesser leg and not a
     * non-leg: the entitlement reversal and the lost stock are identical.
     */
    public function test_a_boolean_cannot_cancel_through_the_merchant_web_door(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $this->actingAs($this->merchant)->postJson(
            route('order.status.change', $order->id),
            ['delivered' => true]
        );

        $this->assertOrderUntouched($order, $product, 4,
            'POST order-status-change/{id} with {"delivered": true} from the OWNING MERCHANT');
    }

    // =============================================================================================
    //  THE WRITER ITSELF -- the one place all five doors meet
    // =============================================================================================

    /**
     * ASKED OF THE WRITER DIRECTLY, over every non-string shape, so the vendored `LotteryPrizes`
     * door -- which passes `$request->delivered` raw and cannot be edited -- is covered by the
     * same assertion as the two first-party ones.
     *
     * @dataProvider nonStringStatusWords
     */
    public function test_the_shared_writer_refuses_a_non_string_status_word($value, string $label): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => $value,
        ]);

        $this->assertNotSame('success', $answer['status'] ?? null,
            'Order::order_status_change() reported success for a status word of ' . $label
            . '. The machine does not govern it, so no transition rule was applied and whatever '
            . 'the arms did was unguarded.');
        $this->assertOrderUntouched($order, $product, 4,
            'Order::order_status_change() called directly with a status word of ' . $label);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function nonStringStatusWords(): array
    {
        return [
            'boolean true' => [true, 'boolean true'],
            'boolean false' => [false, 'boolean false'],
            'integer 1' => [1, 'integer 1'],
            'integer 0' => [0, 'integer 0'],
            'null' => [null, 'null'],
            'empty string' => ['', 'the empty string'],
            'array' => [['cancel'], 'an array'],
            'float' => [1.0, 'the float 1.0'],
            /*
             * A SECOND DIVERGING SHAPE, and the reason this fix is `is_string()` rather than a
             * `(string)` cast. Measured: `$obj == 'cancel'` is TRUE because PHP 8 converts the
             * object, while `in_array($obj, [...], true)` is FALSE -- the same disagreement as the
             * boolean. It cannot arrive from a request body (JSON yields no objects), but the
             * writer is a public static method the whole application and every vendored module can
             * call, so it is latent rather than absent. A `(string)` cast would have produced the
             * literal `'cancel'` and KEPT this one open while closing the boolean.
             */
            'stringable object' => [new OrderStatusWordIsNotABooleanStringable(), 'a Stringable returning "cancel"'],
        ];
    }

    /**
     * THE WRITER'S ESTABLISHED ANSWER IS UNCHANGED. A boolean must now be refused the way `''`,
     * `null` and `0` already were -- with F81's own sentence -- and not with a new string, because
     * five doors put this value on the wire and two of them render it to a merchant.
     */
    public function test_the_refusal_is_the_writers_own_established_sentence(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        $forEmpty = Order::order_status_change(['order_id' => $order->id, 'order_status' => '']);
        $forBoolean = Order::order_status_change(['order_id' => $order->id, 'order_status' => true]);

        $this->assertSame($forEmpty, $forBoolean,
            'a boolean is answered differently from the empty string. Both are values the writer '
            . 'does not recognise, and inventing a second sentence for one of them would be a '
            . 'user-visible string change on a path the merchant web screen renders.');

        /* AND THE SENTENCE ITSELF, not merely "the same as the other one" -- an equality
           assertion alone would be satisfied by a mutant that reworded BOTH. This is F81's
           established string, a key in `resources/lang/en.json` and `ar.json`. */
        $this->assertSame('error', $forBoolean['status'] ?? null);
        $this->assertSame('That order status is not recognised.', $forBoolean['message'] ?? null,
            'the refusal is no longer F81\'s own sentence. Five doors put this value on the wire '
            . 'and two render it to a merchant, so a new string here is a client-visible change.');
    }

    // =============================================================================================
    //  BOTH DIRECTIONS -- a guard that refused everything would pass every case above
    // =============================================================================================

    /**
     * ALL SIX GOVERNED WORDS STILL LAND through the live door, each from a state
     * `FulfilmentStateMachine::PERMITTED` admits it from. Without this, deleting the writer's
     * entire branch chain would make this file green.
     *
     * @dataProvider governedWords
     */
    public function test_every_governed_word_still_lands_through_the_admin_api_door(
        string $word,
        int $from,
        int $expected
    ): void {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3, ['delivered_status' => $from]);

        $response = $this->adminApiStatusChange($order->id, $word, $this->token($this->merchant));

        $this->assertSame($expected, (int) $this->row($order)->delivered_status,
            'the governed word "' . $word . '" no longer lands out of delivered_status ' . $from
            . ' -- FulfilmentStateMachine::PERMITTED says it may. The normalisation must pass a '
            . 'real string through untouched. Body: ' . $response->getContent());
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    public static function governedWords(): array
    {
        return [
            /* cancel is permitted out of PENDING only (MESSAGE_CANCEL_REFUSED). */
            'cancel from pending' => [SM::CANCEL, SM::PENDING, SM::STATE_CANCELLED],
            'confirmed from pending' => [SM::CONFIRMED, SM::PENDING, SM::STATE_CONFIRMED],
            'pickedup from pending' => [SM::PICKED_UP, SM::PENDING, SM::STATE_PICKED_UP],
            'shipped from pending' => [SM::SHIPPED, SM::PENDING, SM::STATE_SHIPPED],
            'delivered from pending' => [SM::DELIVERED, SM::PENDING, SM::STATE_DELIVERED],
            /* return is refused out of PENDING (MESSAGE_RETURN_REFUSED), permitted out of SHIPPED. */
            'return from shipped' => [SM::RETURNED, SM::STATE_SHIPPED, SM::STATE_RETURN],
        ];
    }

    /**
     * AND THE WORD IS NOT TRIMMED OR CASE-FOLDED INTO EXISTENCE. A normalisation that did more
     * than refuse a non-string would start admitting words the writer then refuses, which is a
     * worse message for the same refusal -- the exact reasoning
     * `FulfilmentStateMachine::refusalForShopper()` records for not trimming.
     */
    public function test_a_padded_or_miscased_word_is_still_not_a_word(): void
    {
        $product = $this->productIn(4);
        $order = $this->pendingOrderWith($product, 3);

        /*
         * NOT through the HTTP door: Laravel's `TrimStrings` middleware would have trimmed
         * `' cancel '` before the handler saw it, so a route-driven case here could not fail and
         * would be measuring the middleware. Asked of the writer, where the value arrives as the
         * caller built it.
         */
        foreach ([' cancel ', 'CANCEL', 'Cancel'] as $padded) {
            $answer = Order::order_status_change([
                'order_id' => $order->id,
                'order_status' => $padded,
            ]);

            $this->assertNotSame('success', $answer['status'] ?? null,
                var_export($padded, true) . ' was accepted as the word "cancel". The writer\'s own '
                . 'branch chain matches the six literals and nothing else; a normalisation that '
                . 'folded case or trimmed would be a vocabulary change, not a type fix.');
        }

        $this->assertOrderUntouched($order, $product, 4,
            'a padded or miscased status word reached an arm');
    }

    /**
     * THE WRITER MUST NORMALISE ITS `$date` ARRAY AND NOT ONLY ITS LOCAL -- AND THAT IS NOT
     * TIDINESS. Found by this change's own self-review, and it is the one mutation this file
     * cannot reach behaviourally.
     *
     * `Order::order_status_change()` ends by handing `$date` -- the ARRAY, not the local
     * `$order_status` -- to two vendored module arms, and
     * `PartialPaymentsController::ManageOrderStatus():708-711` reads `$data['order_status']` and
     * compares it with its own LOOSE `== 'partial_paid'`, then writes `delivered_status = 7` and
     * fires `OrderPaymentStatus`. So a fix that normalised only the local would have MOVED the
     * defect rather than closed it: `true` would skip the cancel arm (the local being `''`), fall
     * to `if (module_is_active('PartialPayments'))`, and `true == 'partial_paid'` is TRUE -- a
     * boolean setting Partially Paid instead of Cancelled.
     *
     * A SOURCE RATCHET, SAID PLAINLY, BECAUSE NO BEHAVIOURAL TEST CAN REACH IT. No WorkDo module
     * loads in a test run without an `add_on_managers` row, `Module::find()` is
     * `Cache::rememberForever` so a row inserted in a test body changes nothing anything reads,
     * and `module_is_active()` resolves its principal from `Auth::check()` first and answers false
     * from a test body with nobody signed in. That arm is therefore structurally unreachable here.
     * CLAUDE.md's rule for a control covered by a ratchet rather than by behaviour is to label it
     * correctly, which is what this docblock does.
     *
     * COMMENTS ARE STRIPPED. CLAUDE.md records eight censuses satisfied -- or broken -- by prose
     * about the thing they measure, and the line this one reads carries a 15-line trailing comment
     * that quotes `$order_status`, `$date` and `'cancel'`. Without the stripper this assertion
     * would pass on the comment alone.
     */
    public function test_the_writer_normalises_the_array_it_hands_the_module_arms(): void
    {
        $source = $this->sourceWithoutComments('app/Models/Order.php');

        $this->assertStringContainsString(
            "\$order_status = \$date['order_status'] = ",
            $source,
            'Order::order_status_change() no longer normalises $date[\'order_status\'] in the same '
            . 'statement as its local. The two module arms at the end of that method are handed '
            . '$date, and PartialPaymentsController::ManageOrderStatus() compares it with its own '
            . 'loose == \'partial_paid\' before writing delivered_status = 7 -- so normalising '
            . 'only the local moves a boolean from the cancel arm to that one instead of refusing '
            . 'it. No test in this suite can reach that arm (no WorkDo module loads in a test '
            . 'run), which is why this is asserted over source.'
        );

        $this->assertStringContainsString(
            'FulfilmentStateMachine::wordOf(',
            $source,
            'the writer no longer asks the machine what a status word is, so its loose branch '
            . 'chain and the machine\'s strict governs() can disagree again.'
        );
    }
}

/**
 * A `Stringable` whose string value IS the writer's cancel literal. Declared here rather than
 * anonymously because a data provider is static and runs before the test class is instantiated.
 */
class OrderStatusWordIsNotABooleanStringable
{
    public function __toString(): string
    {
        return 'cancel';
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OrderController::status_cancel() -- THE RESTOCK ARITHMETIC.
 *
 * WHY THIS FILE EXISTS. `status_cancel` was already covered, but only for
 * AUTHORIZATION: tests/Feature/Security/OrderCancelAuthorizationTest and
 * UnauthenticatedRouteBoundaryTest assert that an anonymous or cross-tenant
 * caller is refused. Nothing asserted what the restock loop actually computes,
 * and those files belong to another agent this phase.
 *
 * So when this phase changed that loop -- from
 * `$model->stock += $qty; $model->save();` to ProductStockService's
 * `increment()`, inside a transaction -- the change had no test behind it. A
 * green suite would have proved only that the authorization tests still passed.
 * This file closes that gap, because an unverified edit to a working stock path
 * is exactly the kind of thing that must not reach a release branch.
 *
 * WHAT CHANGED AND WHY
 * --------------------
 *  1. ATOMICITY. The `+=`/`save()` pair is a read-modify-write: two concurrent
 *     cancellations both read the same stock and both write `stock + qty`, so
 *     the second overwrites the first and a cancelled order's goods never come
 *     back. ProductStockService issues an `increment()`, which the database
 *     applies to the row's current value.
 *
 *  2. THE TRANSACTION COVERS THE LOOP ONLY. It deliberately does NOT extend
 *     over the `Order::order_status_change()` call above it, because that method
 *     fires `event(new OrderPaymentStatus($order))` (app/Models/Order.php:554)
 *     whose listeners send notifications. An external call must never sit
 *     inside a transaction, and a GATE F wallet listener must not be rolled
 *     back by a stock failure.
 *
 *  3. The store predicate and the qty validation are new, matching the controls
 *     already proven necessary in OrderRefundController::RefundStock.
 *
 * WHAT IS NOT CHANGED: the replay guard. The loop already ran only inside
 * `if ($responce['status'] == 'success')`, and a second cancellation is refused
 * by `order_status_change()` on `delivered_status != 0`, so it never reaches the
 * loop. That property is asserted here too, because a refactor of this loop
 * could easily have moved it back out of the success branch -- which is the
 * precise defect that was fixed on this endpoint last phase.
 *
 * Builds its own minimal schema, like the rest of tests/Feature/Commerce, and
 * refuses to run anywhere but sqlite :memory:.
 *
 * @see \App\Http\Controllers\OrderController::status_cancel()
 */
class OrderCancelRestockTest extends TestCase
{
    private const STORE_A = 41;
    private const STORE_B = 42;
    private const SLUG_A = 'store-a';

    /** `delivered_status` 2 is cancelled, 0 is placed (app/Models/Order.php:540-552). */
    private const DELIVERED_PLACED = 0;
    private const DELIVERED_CANCELLED = 2;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->createCancelSchema();
        $this->loginCustomerOfStoreA();
    }

    protected function tearDown(): void
    {
        Auth::guard('customers')->logout();
        Cache::flush();

        foreach ([
            'stock_movements',
            'order_coupon_details',
            'order_tax_details',
            'order_billing_details',
            'orders',
            'product_variants',
            'products',
            'customers',
            'add_on_managers',
            'shippings',
            'stores',
            'settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The legitimate workflow
    |--------------------------------------------------------------------------
    */

    /** Cancelling an order gives its stock back and marks it cancelled. */
    public function test_cancelling_an_order_restores_product_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $response = $this->cancel($order);

        $this->assertSame('success', $this->payload($response)['status']);
        $this->assertSame(7, $this->stockOf($product), 'the cancelled quantity must come back');
        $this->assertSame(
            self::DELIVERED_CANCELLED,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'the order must be recorded cancelled'
        );
    }

    /** The variant branch restores too. */
    public function test_cancelling_an_order_restores_variant_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 5);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2],
        ]);

        $this->cancel($order);

        $this->assertSame(7, $this->variantStockOf($variant));
    }

    /** Several lines in one order all come back. */
    public function test_every_line_of_a_multi_line_order_is_restored(): void
    {
        $first = $this->makeProduct(self::STORE_A, ['product_stock' => 1]);
        $second = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $order = $this->makeOrder([
            ['product_id' => $first->id, 'variant_id' => 0, 'qty' => 2],
            ['product_id' => $second->id, 'variant_id' => 0, 'qty' => 5],
        ]);

        $this->cancel($order);

        $this->assertSame(3, $this->stockOf($first));
        $this->assertSame(15, $this->stockOf($second));
    }

    /*
    |--------------------------------------------------------------------------
    | The replay guard that must not regress
    |--------------------------------------------------------------------------
    */

    /**
     * A second cancellation adds nothing.
     *
     * `order_status_change()` refuses it on `delivered_status != 0`, so the
     * restock loop -- which sits inside the success branch -- is never reached.
     * Before that loop was moved inside the branch, one guessed order id was an
     * unbounded inventory-inflation primitive; this asserts it has stayed there.
     */
    public function test_replaying_a_cancellation_does_not_restock_again(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->cancel($order);
        $this->assertSame(7, $this->stockOf($product), 'the first cancellation restocks');

        $second = $this->cancel($order);

        $this->assertSame(
            'error',
            $this->payload($second)['status'],
            'a second cancellation must be refused'
        );
        $this->assertSame(
            7,
            $this->stockOf($product),
            'a refused cancellation must add no stock at all'
        );
    }

    /** Held down, it is still a no-op. */
    public function test_a_held_down_cancellation_cannot_inflate_inventory(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->cancel($order);
        }

        $this->assertSame(
            7,
            $this->stockOf($product),
            'six cancellations must restock the quantity once, not six times'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Hardening added with the atomicity change
    |--------------------------------------------------------------------------
    */

    /** A product tagged to another store is not written to. */
    public function test_a_product_in_another_store_is_not_restocked(): void
    {
        $foreign = $this->makeProduct(self::STORE_B, ['product_stock' => 9]);
        $order = $this->makeOrder([
            ['product_id' => $foreign->id, 'variant_id' => 0, 'qty' => 2],
        ]);

        $this->cancel($order);

        $this->assertSame(
            9,
            $this->stockOf($foreign),
            "a product row tagged to another store must not be written to"
        );
    }

    /** A variant hanging off a different product is not credited. */
    public function test_a_variant_of_another_product_is_not_restocked(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $other = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $foreignVariant = $this->makeVariant($other->id, self::STORE_A, 9);

        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => $foreignVariant->id, 'qty' => 2],
        ]);

        $this->cancel($order);

        $this->assertSame(
            9,
            $this->variantStockOf($foreignVariant),
            'a variant hanging off a different product must not be credited'
        );
    }

    /**
     * A negative `qty` cannot SUBTRACT stock through the cancellation path.
     *
     * As in ReturnApprovalStockTest, the load-bearing control is
     * ProductStockService::restoreProductStock()'s own `$quantity <= 0` refusal
     * (app/Services/ProductStockService.php:233-235); the controller check adds
     * a log line. The property is covered either way, which is the point.
     */
    public function test_a_negative_quantity_cannot_subtract_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => -5],
        ]);

        $this->cancel($order);

        $this->assertSame(10, $this->stockOf($product));
    }

    /*
    |--------------------------------------------------------------------------
    | Atomicity, which a sequential test cannot see
    |--------------------------------------------------------------------------
    */

    /**
     * The cancel path goes through ProductStockService, not `+=` and `save()`.
     *
     * STATED PLAINLY: the behavioural tests above CANNOT tell the two apart.
     * Run sequentially, `$model->stock += $qty; $model->save();` and
     * `increment()` produce identical numbers, so every assertion in this file
     * would stay green if the lost update were put back. The race needs two
     * concurrent writers, and this suite runs on sqlite :memory: in one process.
     *
     * So the atomicity claim rests on two things, neither of which is this
     * file's behavioural assertions:
     *
     *   1. ProductStockService's own atomicity, proven directly against the
     *      database in OrderStockConcurrencyTest.
     *   2. This structural assertion, that status_cancel actually ROUTES through
     *      that service -- which is the part a refactor could silently undo.
     *
     * Reading source to assert a property is weaker than exercising it, and it
     * is used here only because the stronger form is not available in this
     * environment. That is a limitation, recorded rather than papered over.
     */
    public function test_the_cancel_path_routes_stock_through_the_atomic_service(): void
    {
        $source = $this->executableSourceOfStatusCancel();

        $this->assertStringContainsString(
            'restoreProductStock(',
            $source,
            'status_cancel must restore product stock through ProductStockService'
        );
        $this->assertStringContainsString(
            'restoreVariantStock(',
            $source,
            'status_cancel must restore variant stock through ProductStockService'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(product_stock|->stock)\s*\+=/',
            $source,
            'status_cancel has a read-modify-write stock increment again. Two concurrent '
                . 'cancellations both write `stock + qty` and the second overwrites the '
                . 'first, so a cancelled order\'s goods never come back. Use '
                . 'ProductStockService, which issues an increment() the database applies '
                . 'to the row\'s current value.'
        );

        $this->assertStringContainsString(
            'DB::transaction(',
            $source,
            'the restock loop must commit as one unit'
        );
    }

    /**
     * status_cancel's body with comments stripped, so a comment that merely
     * mentions `+=` cannot satisfy or break the assertions above.
     */
    private function executableSourceOfStatusCancel(): string
    {
        $method = new \ReflectionMethod(OrderController::class, 'status_cancel');

        $lines = file($method->getFileName());
        $body = implode(
            '',
            array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $code = '';

        foreach (token_get_all('<?php ' . $body) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | E13 slice 3 — the restore must consult the evidence, not the order's status
    |--------------------------------------------------------------------------
    |
    | D-15, restated by D-30: *"Do not infer stock movement from order status, payment status, a
    | guessed flag or the existence of an order."* Until this slice the restore loop inferred
    | exactly that: it gave stock back for every line of every successfully cancelled order, on
    | the assumption that placing the order had taken it. That assumption is wrong whenever the
    | original never reached a decrement — a refused shortfall, an untracked product, a rolled-back
    | transaction — and then the cancellation INFLATES inventory rather than restoring it.
    |
    | The three states of `StockMovement::movedOutFor()` map onto three behaviours:
    |
    |   MOVED      restore, and record the restore so the next reader sees a net of zero
    |   NOT_MOVED  SKIP — there is nothing out to give back; restoring would invent stock
    |   UNKNOWN    restore, and log that it was done without evidence
    |
    | UNKNOWN RESTORES ON PURPOSE, AND THAT IS THE WHOLE COMPATIBILITY STORY. The movements table
    | is development-and-test-only, so in production `available()` is false and every line is
    | UNKNOWN — identical behaviour to before this slice, with a log line. The protection switches
    | on when, and only when, the record exists. A guard that refused on UNKNOWN would stop
    | restoring stock for every real order on the platform.
    */

    /** @param array<string,mixed> $overrides */
    private function recordMovement(Order $order, array $overrides = []): void
    {
        DB::table('stock_movements')->insert(array_merge([
            'store_id' => self::STORE_A,
            'order_id' => $order->id,
            'product_id' => null,
            'variant_id' => null,
            'direction' => 'out',
            'quantity' => 1,
            'applied' => true,
            'outcome' => 'applied',
            'reason' => 'order_place',
            'correlation_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * NOT_MOVED — the order was observed and this line never took stock out. Do not give any back.
     *
     * The record holds a movement for this order against a DIFFERENT product, so the order is
     * observed (which is what separates NOT_MOVED from UNKNOWN) while this line is not.
     */
    public function test_a_line_that_never_took_stock_out_is_not_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $other = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        // Observed, but on another line.
        $this->recordMovement($order, ['product_id' => $other->id]);

        $response = $this->cancel($order);

        $this->assertSame(
            7,
            $this->stockOf($product),
            'A cancellation gave stock back for a line the record says never took any out. That '
            . 'INVENTS inventory: the arithmetic is right and the premise is false.'
        );

        $this->assertSame('success', $this->payload($response)['status'] ?? null,
            'and the cancellation itself must still succeed — skipping a restore is not a refusal');
    }

    /** MOVED — the record says this line took stock out, so give it back. */
    public function test_a_line_the_record_says_took_stock_out_is_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->recordMovement($order, ['product_id' => $product->id, 'quantity' => 3]);

        $this->cancel($order);

        $this->assertSame(
            10,
            $this->stockOf($product),
            'A line the record says took 3 out was not given back. The consult must not refuse '
            . 'the case it exists to permit.'
        );
    }

    /** The same, for a variant line — the record keys on the variant, not its product. */
    public function test_a_variant_line_the_record_says_took_stock_out_is_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 4);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2],
        ]);

        $this->recordMovement($order, [
            'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2,
        ]);

        $this->cancel($order);

        $this->assertSame(6, $this->variantStockOf($variant),
            'a variant line with recorded evidence was not restored');
    }

    /** And a variant line the record does not know about is skipped. */
    public function test_a_variant_line_that_never_took_stock_out_is_not_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 4);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2],
        ]);

        // Observed against the product line, not the variant.
        $this->recordMovement($order, ['product_id' => $product->id]);

        $this->cancel($order);

        $this->assertSame(4, $this->variantStockOf($variant),
            'a variant line the record says never moved was credited anyway');
    }

    /**
     * UNKNOWN — no rows for this order at all, which is every order placed before the record
     * existed. Restore, because refusing would strand real inventory.
     */
    public function test_a_line_with_no_evidence_at_all_is_still_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        // Deliberately no movement rows.
        $this->cancel($order);

        $this->assertSame(
            10,
            $this->stockOf($product),
            'An order the record cannot speak for was not restored. UNKNOWN must behave exactly as '
            . 'this path did before the consult existed — every order on a live platform is '
            . 'UNKNOWN until the movements table is authorised.'
        );
    }

    /**
     * AND THE PRODUCTION SHAPE: no table at all. Identical behaviour, no throw.
     *
     * `database/migrations/2026_10_31_000100_create_stock_movements_table.php` is development and
     * test only, so this is what every live installation does today.
     */
    public function test_the_cancel_path_still_restores_when_the_movement_record_is_absent(): void
    {
        Schema::dropIfExists('stock_movements');

        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $response = $this->cancel($order);

        $this->assertSame(10, $this->stockOf($product),
            'with no movements table the cancellation must restore exactly as it always did');
        $this->assertSame('success', $this->payload($response)['status'] ?? null);
    }

    /**
     * THE RESTORE IS ITSELF RECORDED, which is what makes restore-twice impossible once the
     * record exists.
     *
     * `movedOutFor()` nets applied outs against applied ins for the line, so after a cancellation
     * the same line reads NOT_MOVED — and the first case above proves NOT_MOVED skips. The two
     * compose into the protection; neither states it alone.
     */
    public function test_the_restore_is_recorded_so_the_line_reads_as_no_longer_out(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 7]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->recordMovement($order, ['product_id' => $product->id, 'quantity' => 3]);

        $this->assertSame(
            \App\Services\StockMovement::MOVED,
            \App\Services\StockMovement::movedOutFor(self::STORE_A, $order->id, $product->id),
            'premise: the line reads MOVED before the cancellation'
        );

        $this->cancel($order);

        $this->assertSame(
            \App\Services\StockMovement::NOT_MOVED,
            \App\Services\StockMovement::movedOutFor(self::STORE_A, $order->id, $product->id),
            'The restore was not recorded, so a second restore would read MOVED and give the same '
            . 'stock back twice. The consult is only a protection if the restore feeds it.'
        );

        $this->assertSame(
            1,
            (int) DB::table('stock_movements')
                ->where('order_id', $order->id)
                ->where('direction', 'in')
                ->where('reason', 'order_cancel')
                ->count(),
            'the restore must be recorded as one IN movement with the cancellation reason'
        );
    }

    /**
     * THE RESTORE LOOP MUST NOT RUN FOR ANY STATUS BUT CANCELLATION.
     *
     * FOUND BY INDEPENDENT REVIEW OF THE EVIDENCE-CONSULT CHANGE, as a live defect in the same
     * handler that the change narrowly mitigated rather than closed.
     *
     * `status_cancel` passes `$request->order_status` to `Order::order_status_change()` with NO
     * validation, and gates the restore loop on `$responce['status'] == 'success'`. Only the
     * `cancel` arm of that method carries an idempotency guard (`delivered_status != 0`,
     * app/Models/Order.php:637-641). The `confirmed`, `pickedup` and `shipped` arms each set a
     * date, save, and return `success` UNCONDITIONALLY — so a customer posting
     * `order_status=confirmed` to a route named `status-cancel` ran the restore loop every time,
     * for as long as they cared to.
     *
     * That is the SAME unbounded inventory-inflation primitive this handler's own docblock records
     * as fixed. Moving the loop inside the success branch closed it for `cancel` and left it open
     * for the four arms that always succeed — which no test saw, because `cancel()` above only
     * ever posts `'cancel'`.
     *
     * @dataProvider nonCancellationStatuses
     */
    public function test_only_a_cancellation_runs_the_restore_loop(string $status): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
        ]);

        $response = $this->changeStatus($order, $status);

        $this->assertSame(
            5,
            $this->stockOf($product),
            'Posting order_status=' . $status . ' to status-cancel restored stock. Three arms of '
            . 'order_status_change() return success with no idempotency guard, so this is an '
            . 'unbounded inventory-inflation primitive on an authenticated customer\'s own order.'
        );

        $this->assertNotSame(
            'success',
            $this->payload($response)['status'] ?? null,
            'and the endpoint must refuse the status outright rather than performing it quietly'
        );
    }

    /** The three arms with no idempotency guard, plus two that would change the order's lifecycle. */
    public static function nonCancellationStatuses(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'pickedup' => ['pickedup'],
            'shipped' => ['shipped'],
            'delivered' => ['delivered'],
            'return' => ['return'],
        ];
    }

    /** HELD DOWN: the unguarded arms must not accumulate stock across repeated requests either. */
    public function test_repeating_a_non_cancellation_status_cannot_accumulate_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->changeStatus($order, 'confirmed');
        }

        $this->assertSame(
            5,
            $this->stockOf($product),
            'Five requests added ' . ($this->stockOf($product) - 5) . ' units of stock that nobody '
            . 'returned. The restore loop ran once per request because `confirmed` returns success '
            . 'every time.'
        );
    }

    /** And a cancellation still works, so the refusal above is not simply refusing everything. */
    public function test_the_cancellation_status_itself_is_still_accepted(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
        ]);

        $response = $this->cancel($order);

        $this->assertSame('success', $this->payload($response)['status'] ?? null,
            'the legitimate cancellation must still succeed');
        $this->assertSame(7, $this->stockOf($product), 'and still restore');
    }

    /**
     * A CANCELLED LINE MUST CARRY THE SAME "ALREADY BACK IN" MARKER THE RETURN PATH WRITES.
     *
     * FOUND BY INDEPENDENT REVIEW. `Order::product_return()` stamps
     * `Order::LINE_STOCK_RESTORED` on each line it credits (app/Models/Order.php:1063, :1094) and
     * the merchant return loop reads it before crediting (`OrderController:1353-1360`). The
     * cancellation loop did neither — so a cancellation credited the goods and left no mark, and
     * a merchant who then approved a return on the same order credited them a SECOND time.
     *
     * `movedOutFor()` does not catch that one: the return path threads no evidence, so its restore
     * is invisible to the record, and on production the record does not exist at all. The marker
     * is the control that already spans both doors, so the cancellation writes it.
     */
    public function test_a_cancelled_line_is_marked_as_already_restored(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $variantProduct = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $variant = $this->makeVariant($variantProduct->id, self::STORE_A, 4);
        $order = $this->makeOrder([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
            ['product_id' => $variantProduct->id, 'variant_id' => $variant->id, 'qty' => 1],
        ]);

        $this->cancel($order);

        $lines = json_decode(
            (string) DB::table('orders')->where('id', $order->id)->value('product_json'),
            true
        ) ?: [];

        $this->assertCount(2, $lines, 'premise: both lines survive the cancellation');

        foreach ($lines as $index => $line) {
            $this->assertTrue(
                Order::lineStockAlreadyRestored($line),
                'Line ' . $index . ' had its stock restored by the cancellation and carries no '
                . 'LINE_STOCK_RESTORED marker, so a merchant approving a return on this order '
                . 'would credit the same goods twice.'
            );
        }
    }

    /** AND A LINE THAT WAS SKIPPED MUST NOT BE MARKED — the marker means "already given back". */
    public function test_a_line_the_record_says_never_moved_is_not_marked_as_restored(): void
    {
        /*
         * TWO LINES, ONE RESTORED AND ONE SKIPPED — and the second line is load-bearing. With a
         * single skipped line nothing is restored at all, so the stamping helper is never called
         * and the test passes however the filter is written. A bite proved exactly that: removing
         * the `$wanted` check failed nothing. Found by biting my own test.
         */
        $skipped = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $restored = $this->makeProduct(self::STORE_A, ['product_stock' => 5]);
        $order = $this->makeOrder([
            ['product_id' => $skipped->id, 'variant_id' => 0, 'qty' => 2],
            ['product_id' => $restored->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        // Observed for the order; the FIRST line has no movement of its own, so it reads
        // NOT_MOVED and is skipped. The second has one, so it is restored.
        $this->recordMovement($order, ['product_id' => $restored->id, 'quantity' => 3]);

        $this->cancel($order);

        $lines = json_decode(
            (string) DB::table('orders')->where('id', $order->id)->value('product_json'),
            true
        ) ?: [];

        $this->assertSame(5, $this->stockOf($skipped), 'premise: the first line really was skipped');
        $this->assertSame(8, $this->stockOf($restored), 'premise: the second line really was restored');

        $this->assertFalse(
            Order::lineStockAlreadyRestored($lines[0] ?? []),
            'A line whose restore was SKIPPED was marked as already restored. The marker would '
            . 'then stop a legitimate return credit for goods that never came back.'
        );

        $this->assertTrue(
            Order::lineStockAlreadyRestored($lines[1] ?? []),
            'and the line that WAS restored must carry the marker'
        );
    }

    private function changeStatus(Order $order, string $status)
    {
        $request = Request::create('/' . self::SLUG_A . '/status-cancel', 'POST', [
            'order_id' => $order->id,
            'order_status' => $status,
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->status_cancel($request, self::SLUG_A);
    }

    private function cancel(Order $order)
    {
        $request = Request::create('/' . self::SLUG_A . '/status-cancel', 'POST', [
            'order_id' => $order->id,
            'order_status' => 'cancel',
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->status_cancel($request, self::SLUG_A);
    }

    /** @return array<string, mixed> */
    private function payload($response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function variantStockOf(ProductVariant $variant): int
    {
        return (int) DB::table('product_variants')->where('id', $variant->id)->value('stock');
    }

    private function loginCustomerOfStoreA(): void
    {
        DB::table('stores')->insert([
            'id' => self::STORE_A,
            'name' => 'Store A',
            'slug' => self::SLUG_A,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stores')->insert([
            'id' => self::STORE_B,
            'name' => 'Store B',
            'slug' => 'store-b',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'id' => 501,
            'name' => 'Customer A',
            'email' => 'customer-a@example.test',
            'password' => bcrypt('secret'),
            'store_id' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::withoutGlobalScopes()->find(501);

        Auth::guard('customers')->login($this->customer);

        $this->assertTrue(
            Auth::guard('customers')->check(),
            'the fixture must authenticate a customer, or status_cancel aborts 403 and '
                . 'every assertion here is vacuous'
        );
    }

    private function makeProduct(int $storeId, array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'name' => 'Product ' . uniqid('', false),
            'track_stock' => 1,
            'stock_order_status' => 'not_allow',
            'price' => 10,
            'product_stock' => 0,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => 1,
        ], $attributes));
        $product->save();

        return $product;
    }

    private function makeVariant(int $productId, int $storeId, int $stock): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId,
            'name' => 'Variant ' . uniqid('', false),
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'store_id' => $storeId,
        ]);
        $variant->save();

        return $variant;
    }

    /**
     * A placed, uncancelled order owned by store A and the logged-in customer,
     * which is the only shape status_cancel accepts.
     *
     * @param array<int, array<string, mixed>> $lines product_json lines
     */
    private function makeOrder(array $lines): Order
    {
        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'ORD-' . uniqid('', false),
            'order_date' => now(),
            'customer_id' => 501,
            'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'final_price' => 100,
            'product_price' => 100,
            'delivery_price' => 0,
            'tax_price' => 0,
            'coupon_price' => 0,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'delivered_status' => self::DELIVERED_PLACED,
            'store_id' => self::STORE_A,
        ]);
        $order->save();

        return $order;
    }

    /** Only the tables status_cancel reads or writes. */
    private function createCancelSchema(): void
    {
        foreach ([
            'stock_movements',
            'order_coupon_details',
            'order_tax_details',
            'order_billing_details',
            'orders',
            'product_variants',
            'products',
            'customers',
            'add_on_managers',
            'shippings',
            'stores',
            'settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        /*
         * E13 slice 3. The cancellation path now CONSULTS the stock-movement record before giving
         * stock back, so this suite has to be able to put the record into each of its three
         * states. Mirrors database/migrations/2026_10_31_000100_create_stock_movements_table.php,
         * which is DEVELOPMENT AND TEST ONLY -- see test_the_cancel_path_still_restores_when_the
         * _movement_record_is_absent for the production shape, where the table does not exist.
         */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('direction', 3);
            $table->integer('quantity')->default(0);
            $table->boolean('applied')->default(false);
            $table->string('outcome', 24)->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('correlation_token', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('type')->default('admin');
            $table->string('password');
            $table->unsignedBigInteger('current_store')->nullable()->index();
            $table->string('is_active')->default('1');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Order::booted()'s `creating` hook resolves the store's currency via
        // Utility::GetValueByName (app/Models/Order.php:100-113), which reads
        // `settings`.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('value')->nullable();
            $table->string('created_by');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        // getStore($slug) resolves the tenant from `stores.slug`
        // (app/Helper/helper.php:2332-2335).
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->index();
            $table->string('is_active')->default('1');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        /*
         * Order::order_detail() assembles a full order view, so it reads the
         * three satellite tables as well. They stay EMPTY: this suite asserts
         * stock arithmetic, and an order with no billing, tax or coupon rows is
         * a perfectly ordinary order. Only `order_coupon_details` declares a
         * foreign key to `orders`, so it is dropped first.
         */
        Schema::create('order_billing_details', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->default(0);
            $table->string('product_order_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone')->nullable();
            $table->string('company_name')->nullable();
            $table->text('address')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_postcode')->nullable();
            $table->timestamps();
        });

        Schema::create('order_tax_details', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->default(0);
            $table->string('product_order_id')->nullable();
            $table->integer('tax_id')->default(0);
            $table->string('tax_name')->nullable();
            $table->float('tax_discount_amount')->default(0)->nullable();
            $table->float('tax_final_amount')->default(0)->nullable();
            $table->timestamps();
        });

        Schema::create('order_coupon_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('product_order_id')->nullable();
            $table->integer('coupon_id')->default(0);
            $table->string('coupon_name')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->float('coupon_discount_number')->default(0);
            $table->float('coupon_discount_amount')->default(0);
            $table->float('coupon_final_amount')->default(0);
            $table->timestamps();
        });

        // Order::order_detail() reaches module_is_active('AdditionalCustomFields'),
        // which queries `add_on_managers`. Left EMPTY on purpose: an empty table
        // is the real "module not installed" state, which is what this suite
        // wants -- it is testing core stock behaviour, not an addon.
        Schema::create('add_on_managers', function (Blueprint $table) {
            $table->id();
            $table->string('module')->nullable();
            $table->string('name')->nullable();
            $table->string('monthly_price')->nullable();
            $table->string('yearly_price')->nullable();
            $table->text('image')->nullable();
            $table->boolean('is_enable')->default(0);
            $table->string('package_name')->nullable();
            $table->boolean('is_display')->default(1);
            $table->timestamps();
        });

        // Order has a `delivery_date` accessor that does
        // Shipping::find($this->delivery_id) (app/Models/Order.php:214), and
        // Order::order_detail() reads it. Without this table every cancellation
        // dies on "no such table: shippings" -- nothing to do with the restock.
        Schema::create('shippings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock');
            $table->string('stock_order_status')->nullable();
            $table->float('price')->default(0);
            $table->float('sale_price')->default(0);
            $table->integer('product_stock')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->string('stock_status')->default(0);
            $table->boolean('variant_product')->default(0);
            $table->double('average_rating', 8, 2)->default(0);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('name')->nullable();
            $table->integer('stock')->default(0);
            $table->string('stock_order_status')->nullable();
            $table->string('variation_option')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('product_order_id')->nullable();
            $table->dateTime('order_date')->nullable();
            $table->integer('customer_id')->default(0);
            $table->text('product_json')->nullable();
            $table->string('product_id')->default(0);
            $table->float('product_price')->nullable()->default(0);
            $table->float('coupon_price')->nullable()->default(0);
            $table->float('delivery_price')->nullable()->default(0);
            $table->float('tax_price')->nullable()->default(0);
            $table->float('final_price')->nullable()->default(0);
            $table->string('payment_type')->nullable();
            $table->string('payment_status')->nullable();
            $table->integer('delivered_status')->default(0);
            $table->integer('return_status')->default(0);
            $table->float('return_price')->nullable()->default(0);
            $table->dateTime('return_date')->nullable();
            $table->dateTime('cancel_date')->nullable();
            // Read by Order::order_detail() (app/Models/Order.php:339-343).
            $table->string('return_status_message')->nullable();
            $table->string('payment_comment')->nullable();
            $table->string('additional_note')->nullable();
            $table->unsignedBigInteger('delivery_id')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * RUNS AGAINST THE MIGRATED SCHEMA (`RefreshDatabase`), and it used to build its own.
 * ------------------------------------------------------------------------------------
 * The hand-built shape declared THIRTEEN tables and THREE columns the product does not
 * have, so this suite's restock arithmetic was measured against a database that does not
 * ship. Converted 2026-11-26; what the conversion measured, before anything was changed:
 *
 *  1. `orders.return_status_message` DOES NOT EXIST, and NO MIGRATION ANYWHERE ADDS IT.
 *     The hand-built column carried the comment "Read by Order::order_detail()", which is
 *     true -- `Order::order_detail()` writes `$order->return_status_message` straight into
 *     the order payload, and `Api\DashboardController` reads it a second time -- so the
 *     product reads a column it never created. Eloquent answers null for an unknown
 *     attribute rather than throwing, so the field is PERMANENTLY null in the API payload
 *     (the Flutter contract fixtures in packages/workdo/Api/src/documentation/admin.json
 *     record it as exactly that), and this suite's invented column was the only thing in
 *     the repository making it look otherwise. Dropped here and REPORTED; it is a product
 *     finding, not a test one, and it is not this slice's to fix.
 *
 *  2. `customers.name` DOES NOT EXIST. The real columns are `first_name` and `last_name`
 *     (`email` is NOT NULL). This matters beyond the label: `Order::getUserNameAttribute()`
 *     prefers first/last and falls back to `$customer->name`, so the hand-built schema put
 *     every case here on the LEGACY FALLBACK BRANCH and the production branch was never
 *     exercised. The one value is split across the two real columns.
 *
 *  3. `product_variants.name` DOES NOT EXIST; the real columns are `variant` and `sku`
 *     (both NOT NULL) and `price` (NOT NULL). The label goes into `variant`.
 *
 *  4. `stores` is NOT NULL on `email`, `theme_id`, `slug` and `created_by`, and
 *     `created_by` is a FOREIGN KEY to `users.id` -- none of which the hand-built table
 *     declared. `DB_FOREIGN_KEYS` is unset so sqlite enforces the key, so the store's owner
 *     must now exist: see ownerTheStoresBelongTo(). The hand-built shape let a store be
 *     created owned by a user that was not there.
 *
 *  5. `orders.return_date` and `orders.cancel_date` are DATE in the migration and the
 *     hand-built table declared both `dateTime`. `status_cancel` writes `now()` into
 *     `cancel_date`, so MySQL TRUNCATES THE TIME while the hand-built column preserved it.
 *     Inert on sqlite (both carry NUMERIC affinity) and nothing here reads either column.
 *
 *  6. `orders.delivery_id` is NOT NULL default 0 in the migration and was nullable with no
 *     default -- so the hand-built shape could reach a NULL `delivery_id` that production
 *     refuses. Inert here: the fixture never sets it, so it takes the migration's 0.
 *     `orders.payment_comment` is TEXT in the migration and was varchar(255);
 *     `products.variant_product` defaults to 1 and defaulted to 0; `stores.is_active` is
 *     INTEGER and was varchar. All inert on this engine or always supplied.
 *
 * `stock_movements` IS NOW A REAL MIGRATED TABLE and the one case that needs it ABSENT
 * drops it in its own body (test_the_cancel_path_still_restores_when_the_movement_record_is
 * _absent). sqlite's DDL is transactional, so RefreshDatabase rolls the drop back.
 *
 * Still refuses to run anywhere but sqlite :memory:.
 *
 * @see \App\Http\Controllers\OrderController::status_cancel()
 */
class OrderCancelRestockTest extends TestCase
{
    use RefreshDatabase;

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

        /*
         * THE PREMISES OF DIVERGENCES 1-3, ASSERTED RATHER THAN ASSUMED, so that a later
         * migration adding any of the three invented columns fails HERE -- naming the column
         * and this docblock -- instead of quietly making the paragraphs above wrong.
         */
        foreach ([
            ['orders', 'return_status_message'],
            ['customers', 'name'],
            ['product_variants', 'name'],
            ['order_billing_details', 'store_id'],
        ] as [$table, $column]) {
            $this->assertFalse(
                Schema::hasColumn($table, $column),
                "{$table}.{$column} does not exist in the migrated schema, and this suite "
                    . 'invented it until 2026-11-26. If it exists now, re-read the class '
                    . 'docblock: for orders.return_status_message a product finding is resolved.'
            );
        }

        foreach (['variant', 'sku', 'price', 'stock'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_variants', $column),
                "product_variants.{$column} must exist in the migrated schema "
                    . '(2024_01_01_104858_create_product_variants_table.php)'
            );
        }

        $this->loginCustomerOfStoreA();
    }

    protected function tearDown(): void
    {
        Auth::guard('customers')->logout();
        Cache::flush();

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
        /*
         * FOLLOWS THE LOOP TO ITS NEW HOME (P4-4). The restore loop was inline in
         * `status_cancel()` when this assertion was written; it now lives in
         * `App\Http\Controllers\Concerns\RestoresCancelledOrderStock::restoreCancelledOrderStock()`,
         * because five other doors that cancel an order were restoring nothing at all.
         *
         * THE PROPERTY IS UNCHANGED and so are the four assertions -- they are simply read against
         * the method that now holds the loop, plus a fifth asserting `status_cancel()` still calls
         * it. Re-pointing the source rather than deleting the test matters: this is the ONLY guard
         * on the lost-update defect, because `$model->stock += $qty` and `increment()` produce
         * identical numbers in a single-process sqlite run, so every behavioural assertion in this
         * file would stay green if the read-modify-write were put back.
         */
        $this->assertStringContainsString(
            'restoreCancelledOrderStock(',
            $this->executableSourceOfStatusCancel(),
            'status_cancel no longer calls the shared restore at all, so a cancelled order\'s goods '
                . 'never come back through this door'
        );

        $source = $this->executableSourceOfSharedRestore();

        $this->assertStringContainsString(
            'restoreProductStock(',
            $source,
            'the shared restore must restore product stock through ProductStockService'
        );
        $this->assertStringContainsString(
            'restoreVariantStock(',
            $source,
            'the shared restore must restore variant stock through ProductStockService'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/(product_stock|->stock)\s*\+=/',
            $source,
            'the shared restore has a read-modify-write stock increment again. Two concurrent '
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
    /** The shared restore's body with comments stripped, same treatment as status_cancel's. */
    private function executableSourceOfSharedRestore(): string
    {
        return $this->executableSourceOf(
            \App\Http\Controllers\Concerns\RestoresCancelledOrderStock::class,
            'restoreCancelledOrderStock'
        );
    }

    private function executableSourceOfStatusCancel(): string
    {
        return $this->executableSourceOf(OrderController::class, 'status_cancel');
    }

    private function executableSourceOf(string $class, string $methodName): string
    {
        $method = new \ReflectionMethod($class, $methodName);

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

    /**
     * `users` row 1, which both stores below name as their `created_by`.
     *
     * DIVERGENCE 4. The migrated `stores` carries a foreign key from `created_by` to
     * `users.id` and sqlite enforces it, so a store cannot be created owned by a user that
     * does not exist. The hand-built `stores` declared no key and this suite created no user
     * at all, so both stores were owned by nobody -- a row production could not hold.
     */
    private function ownerTheStoresBelongTo(): void
    {
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Merchant Of Both Stores',
            'email' => 'merchant@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loginCustomerOfStoreA(): void
    {
        $this->ownerTheStoresBelongTo();

        /*
         * DIVERGENCE 4 again: `email` and `theme_id` are NOT NULL with no default in the
         * migrated `stores`, so they are supplied rather than omitted. `theme_id` is the
         * value config/theme.php's `active` names, so these stores render the same views
         * a store with no explicit theme would.
         */
        DB::table('stores')->insert([
            'id' => self::STORE_A,
            'name' => 'Store A',
            'email' => 'store-a@example.test',
            'theme_id' => config('theme.active'),
            'slug' => self::SLUG_A,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stores')->insert([
            'id' => self::STORE_B,
            'name' => 'Store B',
            'email' => 'store-b@example.test',
            'theme_id' => config('theme.active'),
            'slug' => 'store-b',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * DIVERGENCE 2. `customers` has `first_name` and `last_name`, not `name`, so the one
         * label is split across the two real columns. This also moves every case here onto
         * `Order::getUserNameAttribute()`'s PRODUCTION branch: that accessor prefers
         * first/last and falls back to `$customer->name`, and the hand-built schema -- `name`
         * present, first/last absent -- put the whole suite on the fallback.
         */
        DB::table('customers')->insert([
            'id' => 501,
            'first_name' => 'Customer',
            'last_name' => 'A',
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

    /**
     * DIVERGENCE 3. The label goes into `variant`, the column the migration declares for it;
     * `sku` and `price` are NOT NULL with no default, so they are supplied rather than
     * omitted. The label's value is unchanged from the hand-built fixture's.
     */
    private function makeVariant(int $productId, int $storeId, int $stock): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId,
            'variant' => 'Variant ' . uniqid('', false),
            'sku' => 'sku-' . uniqid('', false),
            'price' => 10,
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
}

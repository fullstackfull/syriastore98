<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OrderController::order_return_request() -- REPLAY, LOST UPDATES AND THE
 * MISSING TRANSACTION BOUNDARY.
 *
 * THE DEFECT, in three parts, all on the same restock loop.
 *
 *  1. REPLAY ADDED STOCK AGAIN, WITHOUT LIMIT. The loop ran on every call
 *     carrying `$status == 2`, with no reference to the return state the order
 *     was already in. `return_status` is not single-assignment -- this same
 *     method writes it, and nothing refuses a second POST -- so an
 *     authenticated merchant replaying one approval inflated inventory
 *     without bound.
 *
 *     This is the SAME primitive that was closed on `status.cancel` in the
 *     previous phase, by moving that loop inside its success branch. It was
 *     left open here because the two fixes were made for two different
 *     endpoints and nothing tied them together. The guard is now the order's
 *     own `return_status` read BEFORE anything is written -- 2 is "Return"
 *     (app/Models/Order.php:145) -- so an order already at 2 has already had
 *     its goods counted back in.
 *
 *  2. EVERY LINE WAS A READ-MODIFY-WRITE. `$model->stock += $qty;
 *     $model->save();` is the lost update App\Services\ProductStockService
 *     exists to remove: two concurrent approvals both read the same stock and
 *     both write `stock + qty`, so the second overwrites the first and the
 *     returned goods never come back.
 *
 *  3. THERE WAS NO TRANSACTION. The restock loop and `$order->save()` were
 *     separate autocommitted writes, so a throw part-way left some lines
 *     restocked with `return_status` never written -- and because the new
 *     guard reads `return_status`, that state would restock the survivors
 *     again on the next attempt. The two now commit together.
 *
 * Two hardening changes come with them, each matching a control already proven
 * necessary in OrderRefundController::RefundStock: the `qty` read out of the
 * product_json blob is validated (a negative value would have SUBTRACTED stock
 * through a restore path) and the product lookup is scoped to the ORDER's store
 * (the bare `Product::find()` would write product_stock onto a product in any
 * store).
 *
 * WHY THE STORE COMES FROM THE ORDER AND NOT getCurrentStore(): OrderPolicy
 * checks isPlatformAdmin BEFORE isAbleTo (app/Policies/OrderPolicy.php:56), so
 * a platform operator may legitimately approve a return on a store that is not
 * their current_store. Scoping to getCurrentStore() would silently restore
 * nothing for exactly that supported workflow.
 *
 * Builds its own minimal schema, like the rest of tests/Feature/Commerce, and
 * refuses to run anywhere but sqlite :memory:.
 *
 * @see \App\Http\Controllers\OrderController::order_return_request()
 * @see \App\Services\ProductStockService
 */
class ReturnApprovalStockTest extends TestCase
{
    private const STORE_A = 31;
    private const STORE_B = 32;

    /** `return_status` values, read off app/Models/Order.php:144-146. */
    private const RETURN_REQUESTED = 1;
    private const RETURN_APPROVED = 2;
    private const RETURN_REJECTED = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->createReturnSchema();
        $this->loginPlatformOperator();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        foreach ([
            'orders',
            'product_variants',
            'products',
            'settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | The legitimate workflow -- a control that broke this would be a defect
    |--------------------------------------------------------------------------
    */

    /** Approving a return gives the stock back, and records the approval. */
    public function test_the_first_return_approval_gives_the_stock_back(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->approveReturn($order);

        $this->assertSame(7, $this->stockOf($product), 'the returned quantity must come back');
        $this->assertSame(
            self::RETURN_APPROVED,
            $this->returnStatusOf($order),
            'the approval must be recorded on the order'
        );
    }

    /** The variant branch works too. */
    public function test_the_first_return_approval_restocks_a_variant(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 5);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2],
        ]);

        $this->approveReturn($order);

        $this->assertSame(7, $this->variantStockOf($variant));
    }

    /**
     * A rejection that FOLLOWS an approval is still recorded.
     *
     * The replay guard must gate the stock only, never the state write, or the
     * merchant could no longer correct a mistaken approval.
     */
    public function test_a_rejection_after_an_approval_is_still_recorded(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->approveReturn($order);
        $this->rejectReturn($order);

        $this->assertSame(
            self::RETURN_REJECTED,
            $this->returnStatusOf($order),
            'the merchant must still be able to record a later rejection'
        );
    }

    /**
     * Approving a return that was previously REJECTED restocks once.
     *
     * 3 -> 2 is a real transition, and the guard keys on "was not already 2",
     * not on "has never been 2", so this must still restock.
     */
    public function test_approving_a_previously_rejected_return_restocks_once(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ], self::RETURN_REJECTED);

        $this->approveReturn($order);

        $this->assertSame(
            7,
            $this->stockOf($product),
            'a rejected-then-approved return must still restock, exactly once'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 1 -- replay
    |--------------------------------------------------------------------------
    */

    /** The second approval of the same return adds nothing. */
    public function test_replaying_a_return_approval_does_not_restock_again(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->approveReturn($order);
        $this->assertSame(7, $this->stockOf($product), 'the first approval restocks');

        $this->approveReturn($order);

        $this->assertSame(
            7,
            $this->stockOf($product),
            'a replayed approval must be a no-op, not a second restock'
        );
    }

    /**
     * Held down, it is still a no-op -- this is the unbounded-inflation shape.
     *
     * Five extra replays of one approval: before the guard this was
     * 4 + 6 * 3 = 22.
     */
    public function test_a_held_down_return_approval_cannot_inflate_inventory(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->approveReturn($order);
        }

        $this->assertSame(
            7,
            $this->stockOf($product),
            'six approvals must restock the quantity once, not six times'
        );
    }

    /** The variant branch is guarded too, not just the product branch. */
    public function test_replaying_a_variant_return_approval_does_not_restock_again(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 5);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2],
        ]);

        $this->approveReturn($order);
        $this->approveReturn($order);
        $this->approveReturn($order);

        $this->assertSame(7, $this->variantStockOf($variant));
    }

    /** A rejection restocks nothing at all. */
    public function test_a_rejected_return_restocks_nothing(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        $this->rejectReturn($order);

        $this->assertSame(4, $this->stockOf($product), 'a rejection returns no goods');
        $this->assertSame(self::RETURN_REJECTED, $this->returnStatusOf($order));
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 3 -- the transaction boundary
    |--------------------------------------------------------------------------
    */

    /**
     * The stock restore and the `return_status` write COMMIT TOGETHER.
     *
     * The order row is made to fail on update, which is the second half of the
     * boundary. If the restore were still autocommitted ahead of it, the stock
     * would have moved while `return_status` stayed un-approved -- and the next
     * attempt would then restock again, because the guard reads
     * `return_status`. Both must be unchanged.
     */
    public function test_a_failure_writing_the_order_rolls_the_stock_back(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        Event::listen('eloquent.updating: ' . Order::class, function () {
            throw new \RuntimeException('forced failure writing the order row');
        });

        try {
            $this->approveReturn($order);
            $this->fail('the forced order-write failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure writing the order row', $e->getMessage());
        }

        $this->assertSame(
            4,
            $this->persistedStockOf($product),
            'the stock restore must roll back with the order write'
        );
        $this->assertSame(
            self::RETURN_REQUESTED,
            $this->returnStatusOf($order),
            'the order must be left exactly as it was'
        );
    }

    /**
     * A SEPARATE, PRE-EXISTING DEFECT, measured here rather than hidden by it.
     *
     * The transaction above rolls the database back correctly -- the assertions
     * in that test read the table and prove it. But Order carries
     * GeneaLabs\LaravelModelCaching\Traits\Cachable (app/Models/Order.php:5,
     * :13), and reading the same row back through Eloquent afterwards returns
     * the value the ROLLED-BACK transaction tried to write. So a failed write
     * stays visible to every later `Order::find()` in the process.
     *
     * This test asserts the divergence as it actually is, so that nobody reads
     * the green suite above as evidence that the cache is coherent. It is
     * `NEW-model-caching-risk` in docs/STABILIZATION-REGISTER.md, and the fix is
     * NOT in this agent's files -- model caching is configured through
     * config/ and the trait is applied in app/Models/, both owned elsewhere
     * this phase.
     *
     * IF THIS TEST STARTS FAILING, that is good news: it means the cache became
     * coherent. Re-point it at the fix rather than deleting it.
     */
    public function test_a_rolled_back_order_write_still_reads_back_stale_through_the_model_cache(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);

        Event::listen('eloquent.updating: ' . Order::class, function () {
            throw new \RuntimeException('forced failure writing the order row');
        });

        try {
            $this->approveReturn($order);
        } catch (\RuntimeException $e) {
            // expected -- see the test above
        }

        $this->assertSame(
            self::RETURN_REQUESTED,
            (int) DB::table('orders')->where('id', $order->id)->value('return_status'),
            'the TABLE must hold the pre-transaction value'
        );

        $this->assertSame(
            self::RETURN_APPROVED,
            (int) Order::withoutGlobalScopes()->find($order->id)->return_status,
            'measured, not desired: the model cache still serves the rolled-back write. '
                . 'See this test\'s docblock -- NEW-model-caching-risk.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Hardening -- each control already proven necessary in RefundStock
    |--------------------------------------------------------------------------
    */

    /**
     * A negative `qty` cannot SUBTRACT stock through a restore path.
     *
     * WHERE THE CONTROL ACTUALLY IS, stated because it is not where this
     * method's own guard suggests. Removing the controller-level validation
     * alone does NOT make this test fail: ProductStockService::
     * restoreProductStock() refuses `$quantity <= 0` itself
     * (app/Services/ProductStockService.php:233-235), and that is the
     * load-bearing control. Measured by removing BOTH -- the controller check
     * and the service guard -- which turns this assertion red with
     * "Failed asserting that 5 is identical to 10", i.e. a RESTORE path
     * subtracting 5 units.
     *
     * The controller-level check is therefore defence in depth plus a log line,
     * not the thing that stops this. The property is covered either way, which
     * is what this test is for.
     */
    public function test_a_negative_quantity_cannot_subtract_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => -5],
        ]);

        $this->approveReturn($order);

        $this->assertSame(
            10,
            $this->stockOf($product),
            'a negative quantity must be skipped, never applied'
        );
    }

    /**
     * A non-numeric `qty` is skipped LOUDLY, not coerced to zero in silence.
     *
     * Asserting only that the stock did not move would be VACUOUS here, and was
     * measured to be: `(int) 'three'` is 0 and `increment(..., 0)` is a no-op,
     * so that assertion stays green even with every guard removed. The property
     * worth holding is therefore the one that is NOT free -- that the dropped
     * line is reported rather than silently swallowed, because a silently
     * skipped restock is an inventory discrepancy nobody can trace.
     *
     * So this asserts the log record, which only the controller-level check
     * produces.
     */
    public function test_a_non_numeric_quantity_is_skipped_and_logged(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 'three'],
        ]);

        // A SPY, not a strict mock. `Log::shouldReceive()` replaces the whole
        // facade, so an unmet expectation surfaces as a Mockery ERROR plus a
        // "did not remove its own error handlers" risky-test warning instead of
        // a clean assertion failure -- measured while proving these teeth. A spy
        // records and passes the call through, so the assertion below fails
        // clean when the control is removed.
        Log::spy();

        $this->approveReturn($order);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context = null) use ($product) {
                return $message === 'Return approval skipped a line with an unusable product id or quantity'
                    && (int) ($context['product_id'] ?? 0) === (int) $product->id;
            });

        $this->assertSame(10, $this->stockOf($product), 'and nothing may move');
    }

    /** A product tagged to another store is not written to. */
    public function test_a_product_in_another_store_is_not_restocked(): void
    {
        $foreign = $this->makeProduct(self::STORE_B, ['product_stock' => 9]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $foreign->id, 'variant_id' => 0, 'qty' => 2],
        ]);

        $this->approveReturn($order);

        $this->assertSame(
            9,
            $this->stockOf($foreign),
            "a product row tagged to another store must not be written to"
        );
    }

    /**
     * A variant belonging to a DIFFERENT product is not credited.
     *
     * This is the wrong-row write that was fixed in RefundStock, asserted on
     * this path too: the lookup keeps BOTH predicates, so a `variant_id` that
     * exists but hangs off another product resolves to nothing.
     */
    public function test_a_variant_of_another_product_is_not_restocked(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $other = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $foreignVariant = $this->makeVariant($other->id, self::STORE_A, 9);

        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $foreignVariant->id, 'qty' => 2],
        ]);

        $this->approveReturn($order);

        $this->assertSame(
            9,
            $this->variantStockOf($foreignVariant),
            'a variant hanging off a different product must not be credited'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function approveReturn(Order $order)
    {
        return $this->callReturnRequest($order->id, self::RETURN_APPROVED);
    }

    private function rejectReturn(Order $order)
    {
        return $this->callReturnRequest($order->id, self::RETURN_REJECTED);
    }

    private function callReturnRequest($orderId, int $status)
    {
        $request = Request::create('/order-return-request', 'POST', [
            'id' => $orderId,
            'status' => $status,
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->order_return_request($request);
    }

    private function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private function variantStockOf(ProductVariant $variant): int
    {
        return (int) ProductVariant::withoutGlobalScopes()->find($variant->id)->stock;
    }

    /**
     * The PERSISTED `return_status`, read straight off the table.
     *
     * Deliberately NOT `Order::find()`. Order carries
     * GeneaLabs\LaravelModelCaching\Traits\Cachable (app/Models/Order.php:5,
     * :13), and that cache serves a value which a rolled-back transaction has
     * already discarded -- measured in
     * {@see test_a_rolled_back_order_write_still_reads_back_stale_through_the_model_cache}.
     * The database is the system of record, so a rollback assertion has to read
     * the database.
     */
    private function returnStatusOf(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('return_status');
    }

    /** The PERSISTED product stock. Same reasoning as returnStatusOf(). */
    private function persistedStockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function loginPlatformOperator(): void
    {
        DB::table('users')->insert([
            'id' => 7,
            'name' => 'Operator',
            'email' => 'operator@example.test',
            // 'super admin' so OrderPolicy::allow() passes via its
            // isPlatformAdmin limb (app/Policies/OrderPolicy.php:56), which is
            // seed-independent: the 'Manage Order' permission row the isAbleTo
            // limb needs is created by Utility::addNewData() and that seeder is
            // not run here.
            'type' => \App\Policies\OrderPolicy::PLATFORM_ADMIN,
            'password' => bcrypt('secret'),
            'current_store' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::login(User::withoutGlobalScopes()->find(7));

        $this->assertSame(
            self::STORE_A,
            (int) getCurrentStore(),
            'the fixture must resolve to store A, or every ownership assertion is vacuous'
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
            'created_by' => 7,
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
     * An order sitting at `return_status = 1` (return requested), which is the
     * state `order_return_request` is called against.
     *
     * @param array<int, array<string, mixed>> $lines product_json lines
     */
    private function makeOrder(
        int $storeId,
        array $lines,
        int $returnStatus = self::RETURN_REQUESTED
    ): Order {
        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'ORD-' . uniqid('', false),
            'order_date' => now(),
            'customer_id' => 1,
            'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'final_price' => 100,
            'product_price' => 100,
            'delivery_price' => 0,
            'tax_price' => 0,
            'coupon_price' => 0,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            // delivered_status 3 is 'return', the state the return workflow
            // reaches before a merchant approves or rejects
            // (app/Models/Order.php:181-187).
            'delivered_status' => 3,
            'return_status' => $returnStatus,
            'store_id' => $storeId,
        ]);
        $order->save();

        return $order;
    }

    /** Only the tables order_return_request reads or writes. */
    private function createReturnSchema(): void
    {
        foreach ([
            'orders',
            'product_variants',
            'products',
            'settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
        // `settings`. Without this table every makeOrder() dies with
        // "no such table: settings" -- nothing to do with what is under test.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('value')->nullable();
            $table->string('created_by');
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
            // Written by Order::booted()'s creating hook; added by
            // 2026_10_12_000102_add_currency_and_created_by_to_orders_table.php.
            $table->string('currency_code', 3)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OrderRefundController::RefundStock() -- REPLAY AND WRONG-ROW WRITES.
 *
 * TWO DEFECTS.
 *
 *  1. REPLAY ADDED STOCK AGAIN, every time. Nothing recorded that a restock had
 *     happened, so one authenticated POST held down inflated inventory without
 *     bound. `order_refunds` has no column to record it
 *     (database/migrations/2024_02_07_071800_create_order_refunds_table.php),
 *     and adding one is a migration that belongs to another agent this phase, so
 *     the marker is a durable `order_notes` row written inside the same
 *     transaction as the restores -- plus a lock, because the check would
 *     otherwise be a check-then-act that two concurrent requests both pass.
 *
 *  2. THE VARIANT WAS LOOKED UP BY PRODUCT ID.
 *     `ProductVariant::find($product->id)` -- different key spaces, so stock
 *     went to whichever variant happened to share the product's id, or to none.
 *     The previous phase reported this as uncorrectable because the refund
 *     payload records no variant id. The payload does not (
 *     AccountProfileController.php:971-981 writes only product_refund_id,
 *     return_price, quantity) -- but the ORDER does, on every product_json line
 *     (OrderController.php:213, PaymentService.php:2321), and that is the same
 *     place the refund feature already resolves lines from
 *     (AccountProfileController.php:947 matches by product_id alone). So the
 *     variant is resolved from the order, and where that is genuinely ambiguous
 *     -- two lines, same product, different variants -- the line is SKIPPED
 *     rather than guessed.
 *
 * Builds its own minimal schema, like the rest of tests/Feature/Commerce, and
 * refuses to run anywhere but sqlite :memory:.
 */
class RefundStockIdempotencyTest extends TestCase
{
    private const STORE_A = 11;
    private const STORE_B = 22;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->createRefundSchema();
        $this->loginMerchantOfStoreA();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        foreach ([
            'order_notes',
            'order_refunds',
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
    | DEFECT 1 -- replay
    |--------------------------------------------------------------------------
    */

    /**
     * The FIRST restock works -- this is the legitimate workflow, and a control
     * that broke it would be a defect, not a fix.
     */
    public function test_the_first_restock_gives_the_stock_back(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 3, 'return_price' => 30],
        ]);

        $response = $this->callRefundStock($refund->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            7,
            $this->stockOf($product),
            'the returned quantity must come back into stock'
        );
    }

    /**
     * THE REPLAY. A second POST must change nothing.
     *
     * Before the fix the stock went 4 -> 7 -> 10 -> 13 ... without bound.
     */
    public function test_a_replayed_restock_does_not_add_stock_a_second_time(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 3, 'return_price' => 30],
        ]);

        $this->callRefundStock($refund->id);
        $this->assertSame(7, $this->stockOf($product));

        $second = $this->callRefundStock($refund->id);

        $this->assertSame(
            7,
            $this->stockOf($product),
            'the replay must not add the returned quantity again'
        );
        $this->assertSame(422, $second->getStatusCode());
        $this->assertStringContainsString(
            'already been restored',
            $second->getContent()
        );
    }

    /** Ten replays, to show the inflation really is bounded now. */
    public function test_repeated_replays_cannot_inflate_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 0]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 5],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 5, 'return_price' => 50],
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->callRefundStock($refund->id);
        }

        $this->assertSame(
            5,
            $this->stockOf($product),
            'ten POSTs must restore the returned quantity exactly once'
        );
    }

    /** Exactly one marker note is written, and it names the refund. */
    public function test_the_restock_is_recorded_once_as_a_durable_marker(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 0]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2, 'return_price' => 20],
        ]);

        $this->callRefundStock($refund->id);
        $this->callRefundStock($refund->id);

        $this->assertSame(
            1,
            OrderNote::withoutGlobalScopes()
                ->where('note_type', OrderRefundController::stockRestoredNoteType($refund->id))
                ->count(),
            'the marker must be written exactly once'
        );
    }

    /**
     * A DIFFERENT refund on the SAME order is not blocked by the first one's
     * marker. The marker is per refund request, not per order.
     */
    public function test_a_second_refund_on_the_same_order_can_still_be_restocked(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 0]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 4],
        ]);

        $first = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 1, 'return_price' => 10],
        ]);
        $second = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2, 'return_price' => 20],
        ]);

        $this->callRefundStock($first->id);
        $this->callRefundStock($second->id);

        $this->assertSame(
            3,
            $this->stockOf($product),
            'two distinct refund requests must each restore their own quantity'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 2 -- the variant row
    |--------------------------------------------------------------------------
    */

    /**
     * Stock goes back to the variant the ORDER says was bought -- not to the
     * variant whose id happens to equal the product's id.
     *
     * The fixture is built so the two are different rows AND so a
     * product-id-keyed lookup would hit a real but WRONG variant: the product's
     * id is 1 and there is a variant with id 1 belonging to another product.
     */
    public function test_stock_returns_to_the_variant_the_order_records(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);

        /*
         * A DECOY VARIANT WHOSE ID EQUALS THE PRODUCT'S ID.
         *
         * This is the whole point of the fixture: it is the row the old
         * `ProductVariant::find($product->id)` would have written to. It belongs
         * to a different product, so writing to it is a wrong-row write. Padding
         * variants are created first so the real variant's id cannot coincide
         * with the product's by accident.
         */
        $decoy = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);

        $decoyVariant = null;

        while ($decoyVariant === null || $decoyVariant->id < $product->id) {
            $decoyVariant = $this->makeVariant($decoy->id, self::STORE_A, 100);
        }

        $this->assertSame(
            (int) $product->id,
            (int) $decoyVariant->id,
            'the decoy variant must share the product id, or this test proves nothing'
        );

        $realVariant = $this->makeVariant($product->id, self::STORE_A, 2);

        $this->assertNotSame(
            (int) $realVariant->id,
            (int) $product->id,
            'the fixture must make the real variant id and the product id differ, '
                . 'or this test proves nothing'
        );

        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $realVariant->id, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 3, 'return_price' => 30],
        ]);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            5,
            $this->variantStockOf($realVariant),
            'the variant the order records must receive the stock'
        );
        $this->assertSame(
            100,
            $this->variantStockOf($decoyVariant),
            'the variant that merely shares the product\'s id must NOT be touched'
        );
    }

    /**
     * An AMBIGUOUS order -- two lines, same product, different variants -- is
     * skipped rather than guessed, and no stock moves.
     */
    public function test_an_ambiguous_variant_is_skipped_rather_than_guessed(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $small = $this->makeVariant($product->id, self::STORE_A, 5);
        $large = $this->makeVariant($product->id, self::STORE_A, 5);

        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $small->id, 'qty' => 1],
            ['product_id' => $product->id, 'variant_id' => $large->id, 'qty' => 1],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2, 'return_price' => 20],
        ]);

        $this->callRefundStock($refund->id);

        $this->assertSame(5, $this->variantStockOf($small), 'nothing may be guessed');
        $this->assertSame(5, $this->variantStockOf($large), 'nothing may be guessed');
    }

    /** A variant row belonging to another store is not written to. */
    public function test_a_variant_in_another_store_is_not_restocked(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['variant_product' => 1]);
        $foreign = $this->makeVariant($product->id, self::STORE_B, 9);

        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => $foreign->id, 'qty' => 2],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 2, 'return_price' => 20],
        ]);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            9,
            $this->variantStockOf($foreign),
            "a variant row tagged to another store must not be written to"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Guards that must keep holding
    |--------------------------------------------------------------------------
    */

    /** A refund that has not been accepted still cannot be restocked. */
    public function test_a_processing_refund_is_still_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => 3, 'return_price' => 30],
        ], 'Processing');

        $response = $this->callRefundStock($refund->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(4, $this->stockOf($product), 'nothing may be restored yet');
    }

    /** Another store's refund request is not found, and nothing is restored. */
    public function test_another_stores_refund_is_not_restockable(): void
    {
        $product = $this->makeProduct(self::STORE_B, ['product_stock' => 4]);
        $order = $this->makeOrder(self::STORE_B, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_B, [
            ['product_refund_id' => $product->id, 'quantity' => 3, 'return_price' => 30],
        ]);

        $response = $this->callRefundStock($refund->id);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(4, $this->stockOf($product));
    }

    /** A negative refund quantity cannot SUBTRACT stock through this endpoint. */
    public function test_a_negative_refund_quantity_cannot_subtract_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $order = $this->makeOrder(self::STORE_A, [
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 3],
        ]);
        $refund = $this->makeRefund($order, self::STORE_A, [
            ['product_refund_id' => $product->id, 'quantity' => -5, 'return_price' => 30],
        ]);

        $this->callRefundStock($refund->id);

        $this->assertSame(
            10,
            $this->stockOf($product),
            'a negative quantity must be skipped, never applied'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function callRefundStock($refundId)
    {
        $request = Request::create('/refund-stock', 'POST', ['refundId' => $refundId]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderRefundController())->RefundStock($request);
    }

    private function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private function variantStockOf(ProductVariant $variant): int
    {
        return (int) ProductVariant::withoutGlobalScopes()->find($variant->id)->stock;
    }

    private function loginMerchantOfStoreA(): void
    {
        DB::table('users')->insert([
            'id' => 7,
            'name' => 'Merchant A',
            'email' => 'merchant-a@example.test',
            // 'super admin' so canManageRefunds() passes via its platform-admin
            // limb, which is seed-independent -- the permission row this suite
            // would otherwise need is created by Utility::addNewData() and that
            // seeder is not run here.
            'type' => \App\Policies\UserPolicy::PLATFORM_ADMIN,
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

    /** @param array<int, array<string, mixed>> $lines product_json lines */
    private function makeOrder(int $storeId, array $lines): Order
    {
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
            'store_id' => $storeId,
        ]);
        $order->save();

        return $order;
    }

    private function makeRefund(
        Order $order,
        int $storeId,
        array $refundLines,
        string $status = 'Accept'
    ): OrderRefund {
        $refund = new OrderRefund();
        $refund->forceFill([
            'order_id' => $order->id,
            'refund_status' => $status,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode($refundLines),
            'product_refund_price' => 0,
            'refund_amount' => 0,
            'store_id' => $storeId,
        ]);
        $refund->save();

        return $refund;
    }

    /**
     * Only the tables RefundStock reads or writes.
     *
     * NOTE ON `order_refunds.product_refund_id`: the real migration declares it
     * `$table->string(...)`, i.e. varchar(255), and it stores a JSON ARRAY of
     * refund lines. Three or four lines overflow 255 characters and the blob is
     * truncated or rejected depending on the driver's strict mode. That is a
     * real data-integrity defect in the schema, it is reported rather than
     * worked around, and it is NOT reproduced here as a 255-char column, because
     * doing so would make this suite fail for a reason that has nothing to do
     * with what it tests. The fixtures use one or two short lines, well inside
     * 255 either way.
     */
    private function createRefundSchema(): void
    {
        foreach ([
            'order_notes',
            'order_refunds',
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
            // Written by Order::booted()'s creating hook; added by
            // 2026_10_12_000102_add_currency_and_created_by_to_orders_table.php.
            $table->string('currency_code', 3)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('refund_status');
            $table->longText('refund_reason');
            $table->string('custom_refund_reason')->nullable();
            $table->longText('attachments')->nullable();
            // See the note in this method's docblock about the real column type.
            $table->text('product_refund_id')->nullable();
            $table->string('product_refund_price')->default(0);
            $table->float('refund_amount')->default(0);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('notes')->nullable();
            $table->string('note_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }
}

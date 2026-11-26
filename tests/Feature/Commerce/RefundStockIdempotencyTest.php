<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * RUNS AGAINST THE MIGRATED SCHEMA (`RefreshDatabase`), and it used to build its own.
 * ------------------------------------------------------------------------------------
 * The hand-built shape declared SEVEN tables and one column the product does not have, so
 * every figure here was measured against a database that does not ship. Converted 2026-11-26;
 * what the conversion measured, against a fresh in-memory migrate, in both directions:
 *
 *  1. `product_variants.name` DOES NOT EXIST. The real migration
 *     (2024_01_01_104858_create_product_variants_table.php) declares `variant` and `sku`, both
 *     NOT NULL, plus `price` NOT NULL -- three columns the hand-built table omitted entirely.
 *     So the variant label now goes into `variant`, which is the column that holds it, and the
 *     two other NOT NULL columns are supplied. The same invented `name` was in four suites;
 *     it is one copied scaffold error, not four findings.
 *
 *  2. THE MIGRATED SCHEMA ENFORCES FOREIGN KEYS THE HAND-BUILT ONE DECLARED NONE OF, and
 *     `DB_FOREIGN_KEYS` is unset so sqlite enforces them in this suite:
 *     `order_notes.customer_id -> customers.id`, `order_notes.order_id -> orders.id`,
 *     `order_refunds.order_id -> orders.id`, `product_variants.product_id -> products.id`.
 *     The idempotency marker is an `order_notes` row carrying `$order->customer_id`
 *     (OrderRefundController::markRefundedLinesRestored()), so the order's customer must now
 *     really exist -- see customerTheOrdersBelongTo(). Under the hand-built shape the marker
 *     was written with a customer id that named nobody.
 *
 *  3. `order_refunds.product_refund_id` is `varchar(255)` in the migration and the hand-built
 *     table widened it to TEXT. Both carry TEXT affinity in sqlite and sqlite enforces no
 *     length at all, so the widening bought nothing even on its own terms; on MySQL a refund
 *     of three or four lines overflows 255 and is refused under strict mode. The fixtures use
 *     one or two short lines either way. REPORTED, not worked around.
 *
 *  4. `products.variant_product` defaults to 1 in the migration and the hand-built table
 *     defaulted it to 0. Inert here only because makeProduct() always supplies it.
 *
 * Still refuses to run anywhere but sqlite :memory:.
 */
class RefundStockIdempotencyTest extends TestCase
{
    use RefreshDatabase;

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

        /*
         * THE PREMISE OF DIVERGENCE 1, ASSERTED RATHER THAN ASSUMED. If `variant` is ever
         * renamed, makeVariant() throws a QueryException from inside a fixture and the
         * failure reads as a defect in the feature; this names the column instead.
         */
        foreach (['variant', 'sku', 'price', 'stock'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_variants', $column),
                "product_variants.{$column} must exist in the migrated schema "
                    . '(2024_01_01_104858_create_product_variants_table.php); `name` never did'
            );
        }

        $this->loginMerchantOfStoreA();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

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

        $this->customerTheOrdersBelongTo();
    }

    /**
     * `customers` row 1, which every makeOrder() here names as its `customer_id`.
     *
     * DIVERGENCE 2. The migrated `order_notes` carries a foreign key on `customer_id` to
     * `customers.id` (2024_02_07_071700_create_order_notes_table.php) and sqlite enforces it,
     * so the idempotency marker cannot be written for an order whose customer does not exist.
     * The hand-built schema created no `customers` table at all and declared no foreign key,
     * so the marker was written against a customer id that named nobody -- a row production
     * could not have.
     *
     * Raw insert, not the model: `Customer` is tenant-scoped AND soft-deleting, and this row
     * is a referential premise rather than anything the suite reads back.
     */
    private function customerTheOrdersBelongTo(): void
    {
        DB::table('customers')->insert([
            'id' => 1,
            'first_name' => 'Refund',
            'last_name' => 'Buyer',
            'email' => 'refund-buyer@example.test',
            'store_id' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * DIVERGENCE 1. The label goes into `variant`, which is the column the migration declares
     * for it; `sku` and `price` are NOT NULL with no default, so they are supplied rather than
     * omitted. Nothing here reads any of the three -- every assertion in this file is about
     * `stock` -- so the label's value is unchanged from what the hand-built fixture used.
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
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE PRE-FLIGHT STOCK CHECK: refusing an unfulfillable cart at the only point
 * in the checkout where refusing is safe.
 *
 * THE DEFECT THIS FINISHES
 * ------------------------
 * `PaymentController::decrementStockOrFail()` takes
 * `bool $rejectOnShortfall = true`, and all NINE production call sites pass
 * FALSE -- PaymentController:1721, :1746, :1803, :2739, :2764, :2821 and
 * PaymentService:2322, :2347, :2404 (line numbers as of the parent commit).
 * That is correct: every one of those sites sits inside a product loop that has
 * already deleted the cart rows of the lines before it, has not yet reached
 * `new Order()`, and on the payment-return paths runs AFTER the gateway captured
 * the money. Refusing there abandoned a PAID checkout with no order row, a
 * partly destroyed cart and nothing refunded.
 *
 * The consequence was that the rejecting behaviour had no caller at all, so
 * nothing anywhere stopped an oversell. This suite covers the replacement: a
 * read-only check over the WHOLE cart that runs BEFORE the destructive loop and
 * BEFORE the shopper is handed to a gateway.
 *
 * WHAT IS PROVEN HERE AND WHAT IS NOT
 * -----------------------------------
 * PROVEN behaviourally, against real rows in the in-memory database: which
 * carts are refused, which are allowed, that nothing is written when a cart is
 * refused, and which row a variant line is judged against.
 *
 * NOT PROVEN: the full `processOrder()` HTTP request. Driving it needs a store,
 * theme, plan, customer, cart, billing and payment-settings fixture this schema
 * does not have. The CALL from processOrder() is asserted structurally instead,
 * against the method's own source, and that limit is stated rather than implied.
 */
class CheckoutPreflightStockTest extends TestCase
{
    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }

    private function preflight($products, $store = null, ?array $settings = null)
    {
        $m = new \ReflectionMethod(PaymentController::class, 'assertCartAvailable');

        return $m->invokeArgs($this->controller(), [$products, $store, $settings]);
    }

    /** Stock management on, supplied directly so no settings table is needed. */
    private const ON = ['stock_management' => 'on'];

    // -----------------------------------------------------------------
    // The check refuses what it must.
    // -----------------------------------------------------------------

    /**
     * THE CENTRAL CASE. A cart whose second line cannot be fulfilled is refused,
     * and -- the part that matters -- the first line's stock is untouched.
     *
     * This is the exact scenario the write path cannot handle: there, line 1 is
     * already decremented and its cart row already deleted by the time line 2 is
     * found to be short.
     */
    public function test_a_cart_with_a_short_line_is_refused_and_nothing_is_written(): void
    {
        $this->createStockSchema();

        $plenty = $this->makeProduct(['product_stock' => 10, 'stock_order_status' => 'not_allow']);
        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $refusal = $this->preflight([
            $this->line($plenty->id, 0, 4),
            $this->line($short->id, 0, 5),
        ], null, self::ON);

        $this->assertIsArray($refusal, 'a cart containing an unfulfillable line must be refused');
        $this->assertArrayHasKey('message', $refusal);
        $this->assertSame($short->id, $refusal['product_id'],
            'the refusal must name the line that could not be fulfilled');

        // THE DECISIVE ASSERTION. Not one unit of inventory moved.
        $this->assertSame(10, $this->stockOf($plenty->id),
            'the pre-flight must not consume stock from the lines it got through');
        $this->assertSame(1, $this->stockOf($short->id),
            'nor from the line it refused');

        $this->dropStockSchema();
    }

    /** A fulfillable cart passes, and is still untouched -- this check only reads. */
    public function test_a_fulfillable_cart_passes_without_writing_anything(): void
    {
        $this->createStockSchema();

        $a = $this->makeProduct(['product_stock' => 5, 'stock_order_status' => 'not_allow']);
        $b = $this->makeProduct(['product_stock' => 5, 'stock_order_status' => 'not_allow']);

        $this->assertNull($this->preflight([
            $this->line($a->id, 0, 5),
            $this->line($b->id, 0, 1),
        ], null, self::ON));

        $this->assertSame(5, $this->stockOf($a->id), 'the pre-flight is read-only');
        $this->assertSame(5, $this->stockOf($b->id));

        $this->dropStockSchema();
    }

    /** Exactly-enough stock is enough: the boundary is >=, not >. */
    public function test_a_line_asking_for_exactly_the_available_stock_is_allowed(): void
    {
        $this->createStockSchema();

        $p = $this->makeProduct(['product_stock' => 3, 'stock_order_status' => 'not_allow']);

        $this->assertNull($this->preflight([$this->line($p->id, 0, 3)], null, self::ON));
        $this->assertIsArray($this->preflight([$this->line($p->id, 0, 4)], null, self::ON));

        $this->dropStockSchema();
    }

    // -----------------------------------------------------------------
    // ...and allows what it must. These are the false-refusal guards: a
    // pre-flight that rejects a legitimate cart breaks a live shop.
    // -----------------------------------------------------------------

    /**
     * BACKORDERS ARE PRESERVED. Only `stock_order_status === 'not_allow'` forbids
     * ordering below stock -- the same test App\Models\Cart, ApiController and
     * ProductStockService::mustNotOversell() already use. A backorder product is
     * orderable at any quantity and must stay so; tightening it here would
     * silently remove a merchant-facing feature.
     */
    public function test_a_backorder_product_is_never_refused(): void
    {
        $this->createStockSchema();

        $notify = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'notify_customer']);
        $unset = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => null]);

        $this->assertNull($this->preflight([$this->line($notify->id, 0, 99)], null, self::ON),
            'notify_customer is a backorder product and must remain orderable');
        $this->assertNull($this->preflight([$this->line($unset->id, 0, 99)], null, self::ON),
            'no stock_order_status at all is also a backorder product');

        $this->dropStockSchema();
    }

    /**
     * A store that does not track stock is never refused.
     *
     * This is what makes the pre-flight a STRICT SUBSET of the destructive
     * loop's behaviour, which is what makes it safe to add to a live checkout.
     */
    public function test_nothing_is_refused_when_stock_management_is_off(): void
    {
        $this->createStockSchema();

        $short = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);

        $this->assertNull(
            $this->preflight([$this->line($short->id, 0, 10)], null, ['stock_management' => 'off']),
            'a merchant who does not track stock must never be refused by this check'
        );

        $this->assertNull(
            $this->preflight([$this->line($short->id, 0, 10)], null, []),
            'and neither must one with no such setting at all'
        );

        $this->dropStockSchema();
    }

    /**
     * A missing product row is NOT the pre-flight's error to raise.
     *
     * The destructive loop answers 'Product not found .' for this, and that
     * shopper-visible response stays the loop's. A pre-flight that invented its
     * own answer here would change an existing response -- which is exactly what
     * an additive guard must not do.
     */
    public function test_a_missing_or_malformed_line_is_left_to_the_existing_loop(): void
    {
        $this->createStockSchema();

        $this->assertNull($this->preflight([$this->line(999999, 0, 1)], null, self::ON),
            'a nonexistent product is the loop\'s error to report, not this check\'s');

        $p = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);

        $this->assertNull($this->preflight([$this->line($p->id, 0, 0)], null, self::ON),
            'a zero quantity decrements nothing, so there is nothing to refuse');
        $this->assertNull($this->preflight([$this->line($p->id, 0, 'abc')], null, self::ON),
            'a non-numeric quantity is the loop\'s validation, not this check\'s');
        $this->assertNull($this->preflight([$this->line(null, 0, 3)], null, self::ON),
            'a line with no product id is the loop\'s validation too');

        $this->assertNull($this->preflight([], null, self::ON), 'an empty cart has nothing to check');
        $this->assertNull($this->preflight(null, null, self::ON));

        $this->dropStockSchema();
    }

    // -----------------------------------------------------------------
    // Variant lines are judged against the SAME row the loop decrements.
    // -----------------------------------------------------------------

    /**
     * With 'manage_stock' among the variation options the VARIANT's own stock is
     * what the destructive loop decrements, so it is what the pre-flight must
     * check. Checking the other row would make the guard disagree with the loop
     * it guards -- allowing a cart the loop then oversells, or refusing one it
     * would have filled.
     */
    public function test_a_manage_stock_variant_is_judged_on_the_variant_row(): void
    {
        $this->createStockSchema();

        // Parent has plenty; the variant is short. Refusal must follow the variant.
        $product = $this->makeProduct(['product_stock' => 100, 'stock_order_status' => 'not_allow']);
        $variantId = $this->makeVariant($product->id, [
            'stock' => 2, 'stock_order_status' => 'not_allow', 'variation_option' => 'manage_stock,color',
        ]);

        $refusal = $this->preflight([$this->line($product->id, $variantId, 3)], null, self::ON);

        $this->assertIsArray($refusal,
            'a manage_stock variant short of the requested quantity must be refused '
            . 'even though its parent product has stock');

        $this->assertNull($this->preflight([$this->line($product->id, $variantId, 2)], null, self::ON),
            'and allowed when the variant itself has enough');

        $this->dropStockSchema();
    }

    /**
     * WITHOUT 'manage_stock' the loop decrements the PARENT product instead, so
     * the pre-flight must look there -- and must not refuse on a variant stock
     * figure that the loop never reads.
     */
    public function test_a_variant_without_manage_stock_is_judged_on_the_parent_product(): void
    {
        $this->createStockSchema();

        // Parent is short; the variant row has plenty but is not the stock of record.
        $product = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);
        $variantId = $this->makeVariant($product->id, [
            'stock' => 500, 'stock_order_status' => 'not_allow', 'variation_option' => 'color,size',
        ]);

        $this->assertIsArray(
            $this->preflight([$this->line($product->id, $variantId, 5)], null, self::ON),
            'without manage_stock the parent product is the stock of record, and it is short'
        );

        $this->dropStockSchema();
    }

    /** A variant id that does not belong to the product is left to the loop. */
    public function test_a_mismatched_variant_is_left_to_the_existing_loop(): void
    {
        $this->createStockSchema();

        $product = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);
        $other = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);
        $variantId = $this->makeVariant($other->id, [
            'stock' => 0, 'stock_order_status' => 'not_allow', 'variation_option' => 'manage_stock',
        ]);

        $this->assertNull(
            $this->preflight([$this->line($product->id, $variantId, 1)], null, self::ON),
            'a variant that is not this product\'s is the loop\'s error to report'
        );

        $this->dropStockSchema();
    }

    /** Array-shaped cart lines work too: the guest and customer cart paths differ. */
    public function test_array_shaped_cart_lines_are_read_the_same_as_objects(): void
    {
        $this->createStockSchema();

        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $refusal = $this->preflight(
            [['product_id' => $short->id, 'variant_id' => 0, 'qty' => 9]],
            null,
            self::ON
        );

        $this->assertIsArray($refusal, 'an array line must be read like an object line');

        $this->dropStockSchema();
    }

    // -----------------------------------------------------------------
    // The setting is read from the database when the caller has not got it.
    // -----------------------------------------------------------------

    /** processOrder() has no loaded settings, so the setting is read per store. */
    public function test_the_stock_management_setting_is_read_from_the_store_when_not_supplied(): void
    {
        $this->createStockSchema();

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('value')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        $short = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);

        // Store 1 tracks stock; store 2 has no setting row at all. Two stores
        // rather than one store mutated in place, because App\Models\Setting
        // uses the Cachable trait: a query issued before the insert caches its
        // own empty result, so re-querying the SAME store after inserting
        // returns the cached miss. That caching is pre-existing behaviour on
        // every Setting read in the checkout (PaymentController:1693 and :2707
        // both read Setting through the same cached model), so the fixture works
        // with it rather than pretending it is not there.
        DB::table('settings')->insert([
            'name' => 'stock_management', 'value' => 'on', 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->flushSettingCache();

        $this->assertIsArray(
            $this->preflight([$this->line($short->id, 0, 3)], (object) ['id' => 1]),
            'with stock_management = on for this store the check applies'
        );

        // The setting is per store: store 2 has no row, so the guard stands down.
        // This is also the tenancy assertion -- store 1 turning stock management
        // on must not turn it on for store 2.
        $this->assertNull(
            $this->preflight([$this->line($short->id, 0, 3)], (object) ['id' => 2]),
            'the setting is per store and must not leak across tenants'
        );

        // And a store object with no id is not a store.
        $this->assertNull(
            $this->preflight([$this->line($short->id, 0, 3)], (object) ['id' => null])
        );

        Schema::dropIfExists('settings');
        $this->dropStockSchema();
    }

    // -----------------------------------------------------------------
    // The check is actually WIRED, at both the pre-gateway and the
    // no-gateway order-writing entry point.
    // -----------------------------------------------------------------

    /**
     * STRUCTURAL, and labelled as such. A correct guard that nothing calls is
     * worth nothing -- that is precisely how the rejecting limb of
     * decrementStockOrFail() ended up with no caller. The HTTP request cannot be
     * driven here (see the class docblock), so the wiring is asserted against
     * the real source of the methods that must do the calling.
     */
    public function test_the_pre_gateway_entry_point_calls_the_pre_flight(): void
    {
        $source = $this->sourceOf('processOrder');

        $this->assertStringContainsString('assertCartAvailable', $source,
            'processOrder must pre-flight the cart before handing the shopper to a gateway');

        // Ordering is the whole point: the refusal must precede processPayment().
        // Both needles are the real CALL expressions, not the bare method names,
        // because the names also occur in the explanatory comments around them
        // and a comment would satisfy a name-only search.
        $this->assertLessThan(
            strpos($source, '$this->processPayment('),
            strpos($source, '$this->assertCartAvailable('),
            'the pre-flight must run BEFORE processPayment sends the shopper to a gateway'
        );
    }

    /**
     * The WhatsApp order writer gets its own pre-flight: it writes an order but
     * no gateway has captured anything (WhatsApp is in
     * PaymentVerificationService::OFFLINE_PAYMENT_TYPES), and the shopper posts
     * to it separately after processOrder() rendered the view, so stock can have
     * moved in between.
     */
    public function test_the_whatsapp_order_writer_pre_flights_before_its_destructive_loop(): void
    {
        $source = $this->sourceOf('whatsapp');

        $this->assertStringContainsString('assertCartAvailable', $source,
            'the WhatsApp order writer must pre-flight; no money has moved, so refusing is safe');

        // Real call expressions, for the same reason as above.
        $this->assertLessThan(
            strpos($source, '$this->decrementStockOrFail('),
            strpos($source, '$this->assertCartAvailable('),
            'the pre-flight must run BEFORE the destructive loop, or it is pointless'
        );
    }

    /**
     * AND THE POST-CAPTURE PATH IS LEFT ALONE. This is the assertion that stops
     * a later change from "finishing the job" by making the payment-return path
     * refuse as well, which would abandon paid checkouts with no order row.
     *
     * getProductStatus() is the payment-return writer: the gateway has already
     * taken the money by the time it runs. It must keep overselling and
     * reporting, never refusing.
     */
    public function test_the_post_capture_writer_still_does_not_refuse(): void
    {
        $source = $this->sourceOf('getProductStatus');

        $this->assertStringNotContainsString('assertCartAvailable', $source,
            'the payment-return writer must NOT refuse: the money is already captured, '
            . 'and abandoning the order there is the defect, not the fix');

        // Every decrement on this path must still pass rejectOnShortfall = false.
        $this->assertSame(
            substr_count($source, 'decrementStockOrFail('),
            substr_count($source, '$qtyy, false)'),
            'every post-capture decrement must still be non-refusing'
        );
    }

    /** Drop the Setting model cache, tolerating any package difference. */
    private function flushSettingCache(): void
    {
        $setting = new \App\Models\Setting();

        if (method_exists($setting, 'flushCache')) {
            $setting->flushCache();
        }
    }

    /** The EXECUTABLE source of a PaymentController method. */
    private function sourceOf(string $method): string
    {
        return $this->executableSourceOf(PaymentController::class, $method);
    }

    /**
     * A method's EXECUTABLE source, with comments removed.
     *
     * Plain source matching is not good enough for a structural assertion in
     * this codebase, in both directions: an explanatory comment that quotes the
     * defect it replaced will satisfy a "must contain" check, and -- the case
     * that actually bit here -- a comment that quotes the OLD read-modify-write
     * will fail a "must not contain" check even though no code does it any more.
     * Stripping comments makes these assertions mean what they say: that the
     * CODE does or does not do the thing.
     */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
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

    // -----------------------------------------------------------------
    // Fixture. Minimal schema rather than RefreshDatabase, following
    // WhatsappUrlStockTest: the full migration set is heavy and none of it
    // is needed to establish these properties.
    // -----------------------------------------------------------------

    private function line($productId, $variantId, $qty): object
    {
        return (object) [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
        ];
    }

    private function createStockSchema(): void
    {
        // tests/bootstrap.php already aborts on a non-memory database; this is a
        // second, local assertion because the helpers below DROP tables.
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->dropStockSchema();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock')->default(1);
            $table->string('stock_order_status')->nullable();
            $table->integer('product_stock')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->float('price')->default(0);
            $table->float('sale_price')->default(0);
            $table->boolean('variant_product')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('created_by')->default(1);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0);
            $table->string('stock_order_status')->nullable();
            // The column the loop reads to decide WHICH row holds the stock.
            $table->string('variation_option')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });
    }

    private function dropStockSchema(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_variants');
    }

    private function makeProduct(array $attributes = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge([
            'name' => 'fixture', 'track_stock' => 1, 'stock_order_status' => null,
            'product_stock' => 0, 'low_stock_threshold' => 0, 'price' => 0,
            'sale_price' => 0, 'variant_product' => 0, 'store_id' => 1,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));

        return Product::findOrFail($id);
    }

    private function makeVariant($productId, array $attributes = [])
    {
        return DB::table('product_variants')->insertGetId(array_merge([
            'product_id' => $productId, 'stock' => 0, 'stock_order_status' => null,
            'variation_option' => null, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /** Straight from the table: the models cache, and a cached read would lie. */
    private function stockOf($id): int
    {
        return (int) DB::table('products')->where('id', $id)->value('product_stock');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');
        $this->dropStockSchema();

        parent::tearDown();
    }
}

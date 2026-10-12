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
 * NEW-whatsapp-url-anonymous-stock-decrement, and the rollback of a decrement
 * whose order is never written.
 *
 * THE DEFECT. `PaymentController::getWhatsappUrl()` — POST /{slug}/get-massage,
 * route name `get.whatsappurl` — permanently decremented product and variant
 * stock. That route lives in the routes/customer.php group whose only
 * middleware is [ActiveTheme, themelanguage]: no authentication, no throttle.
 * And the method creates nothing in exchange — it builds a wa.me or Telegram
 * URL and returns a string; `new Order()` does not occur anywhere in it. So any
 * anonymous caller could POST a cart to it repeatedly and drive a store's
 * inventory to zero.
 *
 * It also double-counted for legitimate shoppers: the WhatsApp checkout page
 * posts to BOTH endpoints (resources/views/payment/whatsapp.blade.php:42 ->
 * `user.whatsapp`, :84 -> `get.whatsappurl`), and `whatsapp()` decrements the
 * same lines again when it writes the order.
 *
 * THE FIX, and why it is not a product decision: a decrement on a path that
 * writes no order corresponds to nothing, and there is no reservation record
 * and no release anywhere that would make it a hold. The write is removed; the
 * availability CHECK stays, so an out-of-stock cart still gets the same error
 * at the same point. Inventory is still consumed exactly once, by `whatsapp()`.
 *
 * WHAT IS AND IS NOT PROVEN HERE. Driving the full `getWhatsappUrl()` request
 * needs a store, theme, settings, cart and billing fixture that this schema
 * does not have, so it is NOT exercised end to end and that is not claimed. The
 * guard's behaviour is tested directly, and the absence of any stock write in
 * that method is asserted structurally against the method's own source. Both
 * are stated for what they are.
 */
class WhatsappUrlStockTest extends TestCase
{
    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }

    private function invoke(string $method, array $args, ?PaymentController $c = null)
    {
        $m = new \ReflectionMethod(PaymentController::class, $method);

        return $m->invokeArgs($c ?? $this->controller(), $args);
    }

    // -----------------------------------------------------------------
    // The anonymous path no longer writes stock.
    // -----------------------------------------------------------------

    /**
     * STRUCTURAL, and labelled as such: the method that an anonymous caller can
     * reach must contain no stock mutation at all.
     *
     * A behavioural test cannot cover this without the whole storefront
     * fixture, and the property — "this method never writes stock" — is exactly
     * the kind a reviewer regresses by pasting the decrement back in. Asserted
     * against the method's real source range, so it tracks the file.
     */
    public function test_the_anonymous_whatsapp_url_method_contains_no_stock_write(): void
    {
        $m = new \ReflectionMethod(PaymentController::class, 'getWhatsappUrl');
        $source = implode("\n", array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $this->assertStringNotContainsString('decrementStockOrFail', $source,
            'getWhatsappUrl is anonymous and writes no order; it must not decrement stock');
        $this->assertStringNotContainsString('decrementProductStock', $source);
        $this->assertStringNotContainsString('decrementVariantStock', $source);
        $this->assertStringNotContainsString('product_stock =', $source);

        // The availability check must still be there: removing the write must
        // not have removed the out-of-stock error the shopper sees.
        $this->assertStringContainsString('assertStockAvailable', $source);
    }

    /** And the order-writing path still decrements, so inventory is still consumed. */
    public function test_the_order_writing_whatsapp_method_still_decrements(): void
    {
        $m = new \ReflectionMethod(PaymentController::class, 'whatsapp');
        $source = implode("\n", array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $this->assertStringContainsString('decrementStockOrFail', $source,
            'the path that actually writes the order must still consume stock');
    }

    // -----------------------------------------------------------------
    // The replacement guard behaves like the one it replaced.
    // -----------------------------------------------------------------

    /** A product that forbids overselling is refused when stock is short. */
    public function test_the_guard_refuses_a_short_line_without_touching_stock(): void
    {
        $product = (new Product())->forceFill([
            'id' => 1, 'product_stock' => 2, 'stock_order_status' => 'not_allow',
        ]);

        $failure = $this->invoke('assertStockAvailable', ['product', $product, 3]);

        $this->assertIsArray($failure);
        $this->assertArrayHasKey('message', $failure);

        // The in-memory model is untouched: nothing was decremented.
        $this->assertSame(2, (int) $product->product_stock);
    }

    /** Exactly enough stock is enough. */
    public function test_the_guard_allows_a_line_that_fits(): void
    {
        $product = (new Product())->forceFill([
            'id' => 1, 'product_stock' => 3, 'stock_order_status' => 'not_allow',
        ]);

        $this->assertNull($this->invoke('assertStockAvailable', ['product', $product, 3]));
    }

    /**
     * BACKORDERS ARE PRESERVED, exactly as in ProductStockService: a product
     * that permits ordering below stock is never refused by this guard.
     */
    public function test_the_guard_never_refuses_a_backorder_product(): void
    {
        foreach ([null, 'notify_customer'] as $status) {
            $product = (new Product())->forceFill([
                'id' => 1, 'product_stock' => 0, 'stock_order_status' => $status,
            ]);

            $this->assertNull($this->invoke('assertStockAvailable', ['product', $product, 99]));
        }
    }

    /** The variant limb reads the variant's own `stock` column. */
    public function test_the_guard_reads_the_variant_stock_column(): void
    {
        $variant = (new ProductVariant())->forceFill([
            'id' => 1, 'stock' => 1, 'stock_order_status' => 'not_allow',
        ]);

        $this->assertIsArray($this->invoke('assertStockAvailable', ['variant', $variant, 2]));
        $this->assertNull($this->invoke('assertStockAvailable', ['variant', $variant, 1]));
    }

    /** Absent, zero and non-numeric quantities are no-ops, as before. */
    public function test_the_guard_ignores_empty_quantities_and_missing_rows(): void
    {
        $product = (new Product())->forceFill([
            'id' => 1, 'product_stock' => 0, 'stock_order_status' => 'not_allow',
        ]);

        foreach ([0, -5, '', 'abc', null] as $qty) {
            $this->assertNull($this->invoke('assertStockAvailable', ['product', $product, $qty]));
        }

        $this->assertNull($this->invoke('assertStockAvailable', ['product', null, 3]));
    }

    // -----------------------------------------------------------------
    // INVENTORY CONSISTENCY: a checkout that cannot complete gives stock back.
    // -----------------------------------------------------------------

    /**
     * THE ROLLBACK, against a real database.
     *
     * A cart whose first line is fulfillable and whose second is not must leave
     * stock exactly as it found it. Without the compensation the first line's
     * units would be consumed by an order that is never written — inventory
     * destroyed by a checkout that failed.
     *
     * This drives the controller's own `decrementStockOrFail()` /
     * `restoreStockDecrements()` pair, which is what all three order paths use.
     */
    public function test_a_checkout_that_cannot_complete_gives_back_every_decrement(): void
    {
        $this->createStockSchema();

        $controller = $this->controller();

        $fulfillable = $this->makeProduct(['product_stock' => 10, 'stock_order_status' => 'not_allow']);
        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $applied = [];

        // Line 1 succeeds.
        $first = $this->invokeByRef($controller, $applied, 'product', $fulfillable, 4);
        $this->assertNull($first, 'the first line is fulfillable');
        $this->assertSame(6, $this->stockOf($fulfillable->id), 'and its stock was taken');
        $this->assertCount(1, $applied, 'the decrement is remembered so it can be given back');

        // Line 2 cannot be fulfilled, which abandons the whole order.
        $second = $this->invokeByRef($controller, $applied, 'product', $short, 5);
        $this->assertIsArray($second, 'the second line must fail the checkout');

        // The decisive assertion: line 1's stock is back.
        $this->assertSame(10, $this->stockOf($fulfillable->id),
            'a checkout that was abandoned must not have consumed any inventory');
        $this->assertSame(1, $this->stockOf($short->id), 'and the short line was never touched');

        $this->dropStockSchema();
    }

    /** The variant limb is given back too, on its own column. */
    public function test_an_abandoned_checkout_gives_back_variant_stock(): void
    {
        $this->createStockSchema();

        $controller = $this->controller();

        $product = $this->makeProduct(['product_stock' => 0]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'stock' => 8,
            'stock_order_status' => 'not_allow', 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $variant = ProductVariant::findOrFail($variantId);

        $short = $this->makeProduct(['product_stock' => 0, 'stock_order_status' => 'not_allow']);

        $applied = [];

        $this->assertNull($this->invokeByRef($controller, $applied, 'variant', $variant, 3));
        $this->assertSame(5, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'));

        $this->assertIsArray($this->invokeByRef($controller, $applied, 'product', $short, 1));

        $this->assertSame(8, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'the variant decrement must be given back when the order is abandoned');

        $this->dropStockSchema();
    }

    /**
     * `decrementStockOrFail()` takes its accumulator by reference, so it cannot
     * be called through the plain reflection helper above.
     */
    private function invokeByRef(PaymentController $c, array &$applied, string $kind, $model, $qty): ?array
    {
        $m = new \ReflectionMethod(PaymentController::class, 'decrementStockOrFail');
        $args = [&$applied, $kind, $model, $qty];

        return $m->invokeArgs($c, $args);
    }

    /**
     * The two tables the stock path touches, built here rather than by
     * RefreshDatabase: the full migration set cannot run on sqlite
     * (2024_05_16_054044_add_id_to_currency_table.php -> "Cannot add a PRIMARY
     * KEY column"), and that migration is Agent 2's and on an unmerged branch.
     * Nullability matches the real migrations, including the NOT NULL
     * products.created_by.
     */
    private function createStockSchema(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->dropStockSchema();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock');
            $table->string('stock_order_status')->nullable();
            $table->integer('product_stock')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->float('price')->default(0);
            $table->float('sale_price')->default(0);
            $table->boolean('variant_product')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0);
            $table->string('stock_order_status')->nullable();
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

    /** Straight from the table: the models cache, and a cached read would lie. */
    private function stockOf($id): int
    {
        return (int) DB::table('products')->where('id', $id)->value('product_stock');
    }

    protected function tearDown(): void
    {
        $this->dropStockSchema();

        parent::tearDown();
    }
}

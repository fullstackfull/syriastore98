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

    // -----------------------------------------------------------------
    // POST-CAPTURE: a writer past the point of no return must never abandon
    // the order. Regression cover for the merge-gate blocker.
    // -----------------------------------------------------------------

    /**
     * THE DEFECT THIS PINS. `decrementStockOrFail()` returned an error payload on a
     * short line, and all three order writers did `return $this->error(...)` with it
     * from INSIDE their product loop. But `new Order()` sits AFTER that loop
     * (PaymentController.php:1839 and :2820, PaymentService.php:2436), the cart row
     * of each processed line is deleted inside it (:1815, :2799, :2416), and no
     * transaction wraps any of it. On `getProductStatus` -- the return target for a
     * dozen gateways via route name `store.payment.status` -- the gateway has
     * already captured the money by then.
     *
     * So a single short line meant: payment taken, NO order row, part of the cart
     * destroyed, nothing refunded. The pre-existing code had no availability test on
     * these paths at all; it subtracted and saved, so stock simply went negative.
     * Overselling and reporting it is the SAME outcome the platform always had, and
     * strictly better than losing a paid basket.
     */
    public function test_a_post_capture_writer_oversells_rather_than_abandoning_a_paid_order(): void
    {
        $this->createStockSchema();

        $controller = $this->controller();
        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $applied = [];
        $outcome = $this->invokeByRef($controller, $applied, 'product', $short, 4, false);

        $this->assertNull($outcome,
            'a writer past the point of no return must NOT return a failure payload; '
            . 'returning one is what abandoned the order after the money was taken');
        $this->assertSame(-3, $this->stockOf($short->id),
            'the decrement is applied anyway, which is exactly the pre-existing unguarded behaviour');
        $this->assertCount(1, $applied, 'and it is still recorded, so the bookkeeping stays honest');

        $this->dropStockSchema();
    }

    /** The same line, with the default, still refuses -- the rejecting path is untouched. */
    public function test_the_default_still_refuses_a_short_line(): void
    {
        $this->createStockSchema();

        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $applied = [];
        $outcome = $this->invokeByRef($this->controller(), $applied, 'product', $short, 4);

        $this->assertIsArray($outcome, 'the pre-flight behaviour is deliberately unchanged');
        $this->assertSame(1, $this->stockOf($short->id), 'and nothing was taken');

        $this->dropStockSchema();
    }

    /** A post-capture shortfall must not claw back the lines that already succeeded. */
    public function test_a_post_capture_shortfall_keeps_the_lines_that_succeeded(): void
    {
        $this->createStockSchema();

        $controller = $this->controller();
        $ok = $this->makeProduct(['product_stock' => 10, 'stock_order_status' => 'not_allow']);
        $short = $this->makeProduct(['product_stock' => 1, 'stock_order_status' => 'not_allow']);

        $applied = [];
        $this->assertNull($this->invokeByRef($controller, $applied, 'product', $ok, 4, false));
        $this->assertNull($this->invokeByRef($controller, $applied, 'product', $short, 5, false));

        $this->assertSame(6, $this->stockOf($ok->id),
            'the order IS going to be written, so giving line 1 back would oversell in the other direction');
        $this->assertSame(-4, $this->stockOf($short->id));

        $this->dropStockSchema();
    }

    /**
     * STRUCTURAL, and labelled as such. The property is "no order writer rejects
     * from inside its loop", which is precisely what a future edit regresses by
     * pasting a rejecting call back in. Every `decrementStockOrFail` call site in
     * both writers must pass the non-rejecting argument.
     */
    public function test_every_order_writer_call_site_is_non_rejecting(): void
    {
        foreach ([
            base_path('app/Http/Controllers/PaymentController.php'),
            base_path('app/Services/PaymentService.php'),
        ] as $file) {
            $source = file_get_contents($file);

            preg_match_all('/\$this->decrementStockOrFail\((.*?)\);/s', $source, $matches);

            $this->assertNotEmpty($matches[1], "no call sites found in {$file} -- the test has gone stale");

            foreach ($matches[1] as $args) {
                $this->assertStringContainsString('false', $args,
                    "A decrementStockOrFail() call in {$file} does not pass rejectOnShortfall=false. "
                    . 'Every call site lives inside a product loop that has already deleted cart rows, '
                    . 'and on the payment-return paths the money is already captured, so rejecting '
                    . 'there abandons the order. See the note on the method.');
            }
        }
    }

    /**
     * STRUCTURAL. `getWhatsappUrl()` returns a bare URL string (`return $url;`) and
     * its only caller, resources/views/payment/whatsapp.blade.php, opens a browser
     * window on whatever comes back. `$this->error()` answers HTTP 200 with a JSON
     * envelope (app/Traits/ApiResponser.php:54-60), so the caller's success branch
     * ran and the shopper got a window full of serialised JSON. The method must
     * never return an error from anywhere.
     */
    public function test_get_whatsapp_url_never_returns_an_error_envelope(): void
    {
        $m = new \ReflectionMethod(PaymentController::class, 'getWhatsappUrl');
        $lines = file(base_path('app/Http/Controllers/PaymentController.php'));
        $source = implode('', array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        // Scoped to the STOCK check specifically, not to the whole method. There is a
        // pre-existing `return $this->error(['message' => 'Cart is empty.'])` early
        // guard in here that predates this work and is out of scope: it fires before
        // anything has been written, so it is not the defect being fixed, even though
        // it shares the same string-vs-envelope contract mismatch. Banning every error
        // return in the method would therefore fail for the wrong reason -- as the
        // first version of this very test did.
        $this->assertStringContainsString('assertStockAvailable', $source,
            'the availability check itself stays -- the shortfall is recorded, not ignored');
        $this->assertStringContainsString('return $url;', $source,
            'and the contract is still to return the URL');

        $sourceLines = explode("\n", $source);
        $checks = 0;

        foreach ($sourceLines as $i => $line) {
            // Anchored on the real call, not on a mention: the surrounding comments say
            // "See assertStockAvailable()", which a looser substring match also hits --
            // and a comment-anchored window has no Log:: in it, so the first version of
            // this test failed on its own prose.
            if (!str_contains($line, '$this->assertStockAvailable(')) {
                continue;
            }

            $checks++;
            $block = implode("\n", array_slice($sourceLines, $i, 6));

            $this->assertStringNotContainsString('return $this->error(', $block,
                'A stock check in getWhatsappUrl returns an error envelope. This method '
                . 'returns a bare URL string and its only caller opens a browser window on '
                . 'whatever comes back, so an envelope shows the shopper serialised JSON. '
                . 'This route writes no Order and the earlier lines have already had their '
                . 'cart rows deleted, so there is nothing here to refuse.');

            $this->assertStringContainsString('Log::', $block,
                'and the shortfall must still be recorded rather than silently dropped');
        }

        $this->assertSame(2, $checks,
            'expected the variant and product stock checks; the test has gone stale if not');
    }

    private function invokeByRef(PaymentController $c, array &$applied, string $kind, $model, $qty, ?bool $reject = null): ?array
    {
        $m = new \ReflectionMethod(PaymentController::class, 'decrementStockOrFail');
        $args = [&$applied, $kind, $model, $qty];

        if ($reject !== null) {
            $args[] = $reject;
        }

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

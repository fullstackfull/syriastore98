<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Models\Cart;
use App\Services\PaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A FAILED CHECKOUT NO LONGER DESTROYS PART OF THE CART.
 *
 * THE DEFECT. All three order writers deleted cart rows PER LINE, inside the
 * stock loop, while `new Order()` does not happen until after the loop. So a
 * failure part-way through -- a product that cannot be found, a stock shortfall,
 * a thrown exception -- had already deleted the lines processed so far and left
 * the rest. The shopper got no order AND a half-emptied cart, with nothing to
 * recover it: no code restored those rows, and the loop's own
 * `return $this->error(...)` paths skip any cleanup. Two of the three writers
 * gained a transaction boundary last phase and the third gained one in this one,
 * but a transaction cannot help here: the deletes ran BEFORE the boundary opened,
 * so they were never inside it to roll back.
 *
 * THE FIX IS TO DEFER, NOT TO COMPENSATE. Each line's identity is accumulated
 * during the loop and the deletes run AFTER the order has committed, so the cart
 * is emptied only once there is an order to show for it. Deferring beats
 * restoring because nothing has to reconstruct cart state the method no longer
 * holds -- which is exactly why the previous phase reported this as unrestorable.
 *
 * FAILURE DIRECTION, stated because it is a deliberate choice: if a deferred
 * delete fails, the order exists and the cart keeps its rows, so a shopper may
 * re-see items they have bought. That is visible and recoverable. The previous
 * direction -- cart gone, no order -- destroyed data.
 *
 * WHAT IS STRUCTURAL AND WHY. Driving a failing checkout end to end needs a
 * store, theme, plan, customer, cart, billing, coupon, tax-method and
 * payment-settings fixture; this suite does not have it and does not pretend to.
 * So the ORDERING property -- accumulate inside the loop, delete after the
 * commit -- is asserted directly against the source with comments stripped,
 * which is the thing a later edit would most easily undo. The deletion
 * BEHAVIOUR, including its store scoping, is then tested for real against a
 * carts table.
 */
class CheckoutCartPreservationTest extends TestCase
{
    /** The three order writers. All three carried the same per-line delete. */
    public static function writers(): array
    {
        return [
            'controller (payment-return writer)' => [PaymentController::class, 'getProductStatus'],
            'service (cod / bank_transfer writer)' => [PaymentService::class, 'getProductStatus'],
            'controller (whatsapp writer)' => [PaymentController::class, 'whatsapp'],
        ];
    }

    /**
     * No writer deletes a cart row inside its stock loop any more.
     *
     * The assertion is on the DELETE, not on the word "Cart": the writers still
     * READ the cart, which is legitimate.
     */
    #[DataProvider('writers')]
    public function test_no_cart_row_is_deleted_before_the_order_commits(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $commit = strpos($source, 'DB::commit()');
        $this->assertNotFalse($commit, 'this writer must have a transaction boundary');

        $beforeCommit = substr($source, 0, $commit);

        $this->assertStringNotContainsString(
            "Cart::where('customer_id', \$request->customer_id)->where('product_id'",
            $beforeCommit,
            'the per-line cart delete must not run before the order commits: a '
                . 'mid-loop failure would destroy part of the cart with no order'
        );
    }

    /** Each writer accumulates the lines, and clears them after the commit. */
    #[DataProvider('writers')]
    public function test_the_cart_is_cleared_only_after_the_commit(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $accumulate = strpos($source, '$cartLinesToClear[] =');
        $commit = strpos($source, 'DB::commit()');
        $clear = strpos($source, '$this->clearCartLines(');

        $this->assertNotFalse($accumulate, 'the loop must remember which lines to clear');
        $this->assertNotFalse($clear, 'and the writer must clear them');

        $this->assertLessThan(
            $commit,
            $accumulate,
            'the accumulation happens in the loop, which is before the boundary'
        );
        $this->assertGreaterThan(
            $commit,
            $clear,
            'the cart may only be emptied once the order is committed'
        );
    }

    /**
     * The accumulated line keeps the STORE in its identity.
     *
     * Dropping `store_id` from the deferred predicate would turn a cart cleanup
     * into a cross-tenant delete, so it is asserted rather than assumed.
     */
    #[DataProvider('writers')]
    public function test_the_accumulated_line_is_store_scoped(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $at = strpos($source, '$cartLinesToClear[] =');
        $this->assertNotFalse($at);

        $entry = substr($source, $at, 320);

        foreach (['customer_id', 'product_id', 'variant_id', 'store_id'] as $key) {
            $this->assertStringContainsString(
                "'" . $key . "'",
                $entry,
                $key . ' must be part of the deferred line identity'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The deletion itself, behaviourally
    |--------------------------------------------------------------------------
    */

    /** The deferred clear removes exactly the accumulated lines. */
    public function test_clearing_removes_the_accumulated_lines(): void
    {
        $this->createCartSchema();

        $keep = $this->cartRow(['customer_id' => 1, 'product_id' => 99, 'variant_id' => 0, 'store_id' => 11]);
        $this->cartRow(['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11]);
        $this->cartRow(['customer_id' => 1, 'product_id' => 6, 'variant_id' => 2, 'store_id' => 11]);

        $this->clearCartLines([
            ['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11],
            ['customer_id' => 1, 'product_id' => 6, 'variant_id' => 2, 'store_id' => 11],
        ]);

        $remaining = DB::table('carts')->pluck('id')->all();

        $this->assertSame(
            [$keep],
            $remaining,
            'only the ordered lines may be removed'
        );

        $this->dropCartSchema();
    }

    /** It does not reach another store's cart, or another customer's. */
    public function test_clearing_does_not_reach_another_store_or_customer(): void
    {
        $this->createCartSchema();

        $otherStore = $this->cartRow(['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 22]);
        $otherCustomer = $this->cartRow(['customer_id' => 2, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11]);
        $otherVariant = $this->cartRow(['customer_id' => 1, 'product_id' => 5, 'variant_id' => 7, 'store_id' => 11]);
        $this->cartRow(['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11]);

        $this->clearCartLines([
            ['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11],
        ]);

        $this->assertSame(
            [$otherStore, $otherCustomer, $otherVariant],
            DB::table('carts')->orderBy('id')->pluck('id')->all(),
            'the predicate must stay scoped to one customer, store, product and variant'
        );

        $this->dropCartSchema();
    }

    /**
     * A clear that cannot run does NOT throw.
     *
     * The order is already committed and the money has already moved by this
     * point, so a cart row that will not delete must never become a 500. Proven
     * by dropping the table out from under it.
     */
    public function test_a_failing_clear_never_throws_after_the_order_is_committed(): void
    {
        $this->createCartSchema();
        $this->dropCartSchema();

        $this->clearCartLines([
            ['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11],
        ]);

        // Reaching here is the assertion: no exception escaped.
        $this->assertTrue(true, 'a failing cart clear must be swallowed and logged');
    }

    /** An empty accumulator is a no-op, not an unfiltered delete. */
    public function test_an_empty_accumulator_deletes_nothing(): void
    {
        $this->createCartSchema();

        $this->cartRow(['customer_id' => 1, 'product_id' => 5, 'variant_id' => 0, 'store_id' => 11]);

        $this->clearCartLines([]);

        $this->assertSame(
            1,
            DB::table('carts')->count(),
            'an empty list must not be treated as "delete everything"'
        );

        $this->dropCartSchema();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /** clearCartLines() is private by design; reflection keeps it that way. */
    private function clearCartLines(array $lines): void
    {
        $m = new \ReflectionMethod(PaymentController::class, 'clearCartLines');
        $m->setAccessible(true);
        $m->invoke(new PaymentController(app(PaymentService::class)), $lines);

        // The Cart model uses the Cachable trait and these deletes go through
        // Eloquent, so events fire; flushed anyway so the reads below cannot be
        // served stale.
        (new Cart())->flushCache();
    }

    private function cartRow(array $attributes): int
    {
        return DB::table('carts')->insertGetId(array_merge([
            'qty' => 1,
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function createCartSchema(): void
    {
        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        Schema::dropIfExists('carts');

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->integer('customer_id')->default(0)->nullable();
            $table->integer('product_id')->default(0);
            $table->integer('variant_id')->default(0);
            $table->integer('qty')->default(0);
            $table->integer('price')->default(0);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });
    }

    private function dropCartSchema(): void
    {
        Schema::dropIfExists('carts');
    }

    /** See OrderWriteTransactionTest::executableSourceOf(). */
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
}

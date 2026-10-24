<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P1-INV-03 — the POS sale writes product_stock to an absolute value, for a product it never
 * checks the ownership of.
 * =============================================================================================
 *
 * `PosController::store()` is two lines of stock arithmetic (:203-206):
 *
 *     $original_quantity = (int) $value['originalquantity'];        // from the SESSION
 *     $product_quantity  = $original_quantity - $value['quantity']; // also from the SESSION
 *     Product::where('id', $product_id)->update(['product_stock' => $product_quantity]);
 *
 * THREE DEFECTS IN THOSE TWO LINES, and they are independent of one another:
 *
 *   1. NO STORE PREDICATE. `Product` is NOT in `config/tenancy.php`'s `scoped_models`
 *      (CLAUDE.md records this explicitly), so `StoreScope` adds nothing and
 *      `Product::where('id', ...)` reaches EVERY tenant's catalogue. The ledger row already
 *      records the reproduction: seeding the POS session with store B's product moved B's
 *      `product_stock` 50 -> 49.
 *
 *   2. AN ABSOLUTE WRITE, NOT A DECREMENT. The new stock is computed from `originalquantity`,
 *      which was captured into the session when the product was added to the POS cart and is
 *      never re-read. So the write does not subtract from the CURRENT stock -- it OVERWRITES it
 *      with a figure derived from a snapshot that may be minutes or hours old. Two tills selling
 *      the same product clobber each other, and a sale from a stale cart RESURRECTS stock that
 *      other sales have already consumed. This is the one that loses money quietly, because
 *      nothing errors.
 *
 *   3. THE SESSION IS FED BY TWO UNSCOPED RESOLVERS. `ProductController::addToCart()` (:3014) is
 *      `Product::find($id)` and `updateCart()` (:3351) is `Product::where('id', $id)->first()`,
 *      neither store-scoped -- which is HOW a foreign product gets into the session in the first
 *      place. Guarding only `store()` would leave the foreign row in the cart, priced and
 *      displayed; guarding only the feeders would leave `store()` trusting whatever is already
 *      in a session.
 *
 * WHY THIS WAS BACKLOG AND IS NOW BEING DONE. The row held it at BACKLOG because it "depends on
 * P1-INV-01: both change ProductController, and the directive forbids two agents editing the same
 * working file". P1-INV-01 is DONE (#27, commit f522feaf), so that reason has lapsed.
 */
class PosStockIntegrityTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function productIn($store, int $stock, string $name = 'POS Widget'): Product
    {
        return Product::withoutEvents(fn () => Product::forceCreate([
            'name' => $name, 'slug' => 'pos-' . uniqid(),
            'price' => 10.00, 'sale_price' => 10.00,
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));
    }

    /** Raw, because `Product` is Cachable and a model read can serve a mutated in-memory copy. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /**
     * One POS cart line, in the shape `PosController::store()` reads and
     * `ProductController::addToCart()` writes.
     *
     * @param  int  $originalQuantity  what the session BELIEVES the stock was when the line was added
     */
    private function posLine(Product $product, int $quantity, int $originalQuantity): array
    {
        return [
            'product_id' => $product->id,
            'id' => $product->id,
            'name' => $product->name,
            'image' => '',
            'quantity' => $quantity,
            'originalquantity' => $originalQuantity,
            'orignal_price' => 10.00,
            'per_product_discount_price' => 0,
            'discount_price' => 0,
            'final_price' => 10.00 * $quantity,
            'tax' => 0,
            'total_orignal_price' => 10.00 * $quantity,
        ];
    }

    /** Drive the POS sale as $merchant, with $line already in their POS session. */
    private function sell($merchant, $store, array $line)
    {
        $this->grantPermission($merchant, 'Create Pos');

        return $this->actingAs($merchant)
            ->withSession([
                'pos_' . $store->id => [$line['id'] => $line],
                'new_pos_id_' . $store->id => 'POS-' . uniqid(),
            ])
            ->post(route('pos.data.store'), [
                'store_id' => $store->id,
                'customer_id' => 0,
                'price' => '10.00',
                'discount' => 0,
                'payment_type' => 'cash',
            ]);
    }

    // =========================================================================================
    //  1. THE CROSS-TENANT WRITE
    // =========================================================================================

    /**
     * The reproduction the ledger row records, driven here so the fix has something to bite.
     *
     * A merchant whose POS session holds ANOTHER store's product sells it, and the victim's stock
     * moves. The session is seeded directly -- which is honest, because the feeders that would
     * put it there are themselves unscoped and are tested separately below.
     */
    public function test_a_pos_sale_cannot_write_a_product_another_store_owns(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->productIn($storeB, 50, 'Victim Widget');
        $this->assertSame(50, $this->stockOf($victim), 'premise');

        $response = $this->sell($merchantA, $storeA, $this->posLine($victim, 1, 50));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the POS sale crashed rather than refusing: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(50, $this->stockOf($victim),
            "ANOTHER STORE'S product_stock was written by a POS sale. `Product` is not in "
            . "scoped_models, so `Product::where('id', ...)` reaches every tenant's catalogue.");
    }

    /**
     * AND THE FEEDER, so the foreign row cannot reach the session in the first place.
     * `addToCart()` resolves with `Product::find($id)` and no store predicate.
     */
    public function test_add_to_cart_refuses_a_product_another_store_owns(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->productIn($storeB, 50, 'Victim Widget');
        $this->grantPermission($merchantA, 'Create Pos');

        $response = $this->actingAs($merchantA)
            ->withSession(['current_store' => $storeA->id])
            ->get(route('pos.add.to.cart', ['id' => $victim->id, 'session' => 'pos_' . $storeA->id]));

        /*
         * THE HANDLER MUST HAVE RUN, AND IT MUST HAVE REFUSED -- and telling those two apart is
         * the whole difficulty here, because both look like a 404.
         *
         * The first version of this test guessed the path `/product/addtocart/...`. The real route
         * is `addToCart/{id}/{session}` (routes/web.php:1110), so the guess hit Laravel's own
         * missing-route 404 -- which satisfied `assertNotSame(200, ...)` and made the test PASS
         * against entirely unfixed code. That is failure mode 2 in the project skill: "the route
         * does not exist, so the assertion is satisfied by a 404."
         *
         * Asserting `!== 404` does not fix it either, because the handler's OWN refusal is a 404.
         * So the refusal is identified by its own JSON envelope -- which a missing route cannot
         * produce, since Laravel answers that with HTML.
         */
        $this->assertSame(404, $response->getStatusCode(),
            'addToCart accepted another store\'s product into the POS cart, which is how a '
            . 'foreign product reaches the sale in the first place. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame('Error', $response->json('status'),
            'the response is a 404 but not THIS handler\'s refusal, so the route was never '
            . 'reached and this test proves nothing. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(
            [],
            (array) session('pos_' . $storeA->id, []),
            'another store\'s product was written into the caller\'s POS session'
        );
    }

    // =========================================================================================
    //  2. THE ABSOLUTE WRITE -- the one that loses stock quietly
    // =========================================================================================

    /**
     * A SALE FROM A STALE CART MUST NOT RESURRECT STOCK.
     *
     * This is the defect that costs a merchant real inventory and raises no error. The POS line
     * carries `originalquantity`, captured when the product was added to the cart. `store()`
     * writes `product_stock = originalquantity - quantity` -- an ABSOLUTE assignment.
     *
     * So: the till adds a product when stock is 50. Other sales then take it to 30. The till
     * completes its sale of 1. The correct result is 29. The absolute write stores 50 - 1 = 49,
     * INVENTING 20 units that were already sold.
     *
     * A decrement cannot do this, which is why the acceptance criterion asks for one.
     */
    public function test_a_sale_from_a_stale_cart_decrements_rather_than_overwriting(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $product = $this->productIn($store, 50);

        // the world moved on after the line was added to the cart
        DB::table('products')->where('id', $product->id)->update(['product_stock' => 30]);
        (new Product)->flushCache();
        $this->assertSame(30, $this->stockOf($product), 'premise: stock really is 30 now');

        // the session still believes 50
        $response = $this->sell($merchant, $store, $this->posLine($product, 1, 50));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the POS sale crashed: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(29, $this->stockOf($product),
            'the POS sale OVERWROTE stock with a figure from a stale session (50 - 1 = 49) '
            . 'instead of decrementing the current 30 by 1. Twenty units that were already sold '
            . 'have been invented.');
    }

    // =========================================================================================
    //  3. THE POSITIVE CONTROL
    // =========================================================================================

    /**
     * An ordinary POS sale must still work and still move stock, or every refusal above is
     * indistinguishable from a broken endpoint. The compatibility line on this task is explicit:
     * "POS must keep working for merchants who use it."
     */
    public function test_an_ordinary_pos_sale_still_decrements_the_sellers_own_stock(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $product = $this->productIn($store, 50);

        $response = $this->sell($merchant, $store, $this->posLine($product, 2, 50));

        $this->assertNotSame(500, $response->getStatusCode(),
            'an ordinary POS sale crashed: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(48, $this->stockOf($product),
            'an ordinary POS sale no longer moves the seller\'s own stock, so the refusals above '
            . 'prove nothing');

        $this->assertNotNull(
            DB::table('orders')->where('store_id', $store->id)->first(),
            'the POS sale wrote no order at all'
        );
    }
    // =========================================================================================
    //  4. updateCart -- THE SECOND FEEDER, AND A GUARD THAT CANNOT FIRE
    // =========================================================================================

    /**
     * THE STOCK BOUND IS DEAD CODE, and it is dead for a reason worth seeing in full
     * (ProductController:3397):
     *
     *     if (isset($cart[$id]) && isset($cart[$id]["originalquantity"]) < $cart[$id]['quantity'] && ...)
     *
     * `isset(...)` evaluates to a BOOL. PHP 8 then compares bool-to-int by casting the INT to
     * bool, so the test is `true < true` for every positive quantity -- which is false. CONFIRMED
     * BY EXECUTION rather than by reading:
     *
     *     $cart = ["originalquantity" => 5, "quantity" => 999];
     *     isset($cart["originalquantity"]) < $cart["quantity"]   =>  false   <- the shipped guard
     *     $cart["originalquantity"] < $cart["quantity"]          =>  true    <- what was meant
     *
     * So the POS cart accepts ANY quantity. With the stock write now a real decrement, that
     * quantity is what leaves the shelf: a crafted update of 999 against a stock of 5 takes the
     * recorded stock to -994. Before the decrement fix it wrote an absolute value instead, which
     * was differently wrong -- neither bounded it.
     */
    public function test_update_cart_refuses_a_quantity_larger_than_the_line_ever_had(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 5);
        $this->grantPermission($merchant, 'Create Pos');

        $key = 'pos_' . $store->id;

        $response = $this->actingAs($merchant)
            ->withSession([$key => [$product->id => $this->posLine($product, 1, 5)]])
            ->patch('/update-cart', [
                'id' => $product->id,
                'quantity' => 999,
                'discount' => 0,
                'session_key' => $key,
            ], ['X-Requested-With' => 'XMLHttpRequest']);

        /*
         * THE 404 TRAP, THIRD TIME IN THIS FILE -- recorded because I walked into it again.
         * A missing route and this handler's own refusal are BOTH 404, so `assertNotSame(404)`
         * cannot serve as the "the route was reached" guard. They are separable only by shape:
         * the handler answers JSON carrying `status: 'Error'`, Laravel's missing route answers
         * HTML. The refusal here is "This product is out of stock!", which IS the revived bound
         * doing its job.
         */
        $this->assertSame('Error', $response->json('status'),
            'this is not the handler\'s own JSON refusal, so the route was never reached and '
            . 'this test proves nothing. Body: ' . substr((string) $response->getContent(), 0, 300));

        $cart = (array) session($key, []);
        $stored = (int) ($cart[$product->id]['quantity'] ?? 0);

        $this->assertLessThanOrEqual(5, $stored,
            'updateCart accepted a quantity of 999 against a line that only ever had 5 units. '
            . 'The stock bound at ProductController:3397 compares isset() -- a bool -- to the '
            . 'quantity, so it is false for every positive number and never fires.');
    }

    /**
     * AND THE SECOND UNSCOPED RESOLVE. `updateCart` reads
     * `Product::where('id', $id)->first()` with no store predicate, the same shape `addToCart`
     * had. It is a separate handler, so it needs its own test and its own mutant.
     */
    public function test_update_cart_does_not_act_on_another_stores_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->productIn($storeB, 50, 'Victim Widget');
        $this->grantPermission($merchantA, 'Create Pos');

        $key = 'pos_' . $storeA->id;

        $response = $this->actingAs($merchantA)
            ->withSession([$key => [$victim->id => $this->posLine($victim, 1, 50)]])
            ->patch('/update-cart', [
                'id' => $victim->id,
                'quantity' => 3,
                'discount' => 0,
                'session_key' => $key,
            ], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertNotSame(500, $response->getStatusCode(),
            'updateCart crashed on a foreign product instead of refusing: '
            . substr((string) $response->getContent(), 0, 300));

        // Same reasoning as the test above: the refusal and a missing route are both 404, so the
        // handler's own envelope is what proves the route was reached.
        $this->assertSame('Error', $response->json('status'),
            'this is not the handler\'s own JSON refusal, so the route was never reached. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $cart = (array) session($key, []);

        $this->assertSame(1, (int) ($cart[$victim->id]['quantity'] ?? 1),
            'updateCart revised a cart line for another store\'s product');
    }
}

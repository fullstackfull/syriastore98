<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F269 — a completed till sale left its basket in the session, so the next press re-sold it.
 * =================================================================================================
 *
 * `PosController::store()` read the basket from `session('pos_' . getCurrentStore())`, decremented
 * stock, wrote an `Order` and its `PurchasedProducts` — and the line that clears the basket was
 * COMMENTED OUT (`PosController.php:431` before this change). The only code that cleared it lived
 * in `printView()`, a DIFFERENT request on a GET route, fired by a second jQuery handler bound to
 * the same button, whose response the sale's own handler never sees.
 *
 * So after a completed sale the basket could still be there, `$('#carthtml').load(...)` redrew it
 * fully populated, and the next press of Cash Payment sold it again: a second order, and stock
 * decremented a second time.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE CLEAR ALONE IS NOT THE FIX, which is the half this file exists to measure.
 *
 * The POS screen fires BOTH requests from ONE click — `pos/index.blade.php:829` posts the sale,
 * `:866` gets the receipt — and nothing orders them. Sessions are read-modify-write with no lock:
 * each request loads the session when it starts and saves it when it ends, so the last writer
 * wins. Two presses in flight together both read the basket before either writes, both pass any
 * `isset($sales)` test, and the save of whichever finishes last puts the basket BACK for a third.
 *
 * `store()` is the slower of the two by a wide margin — it writes stock, an order, purchased
 * products, and makes an OUTBOUND HTTP webhook call — so it is the likely last writer.
 *
 * The control therefore cannot live in the session. It is an atomic claim in the cache, taken
 * before any stock moves: `Cache::add()` is a single put-if-absent, an INSERT against a unique key
 * on the production `database` store, so exactly one of two concurrent requests can take it. Same
 * primitive F226 uses for the admin API's order intake — one idempotency mechanism in this
 * codebase, not two.
 *
 * `test_two_presses_that_both_read_the_basket_still_sell_it_once` is the one that separates the two
 * fixes: it re-seeds the basket on the second request, which is exactly what a lost session race
 * produces, and a bare `session()->forget()` cannot pass it.
 * -------------------------------------------------------------------------------------------------
 *
 * WHAT THIS FILE CANNOT SHOW, stated plainly rather than left to be assumed — the same bound
 * `verify-gateway-return-claim-on-mysql.php` records for F226's claim:
 *
 *   - `phpunit.xml` sets `CACHE_STORE=array`, and `Illuminate\Cache\ArrayStore` has NO `add()`
 *     of its own, so `Cache::add()` here falls through to `Repository::add()` — a `get()` then a
 *     `put()`, with a gap between them. Production runs `CACHE_STORE=database`, where
 *     `DatabaseStore::add()` ends in `insertOrIgnore` against the `cache` table's PRIMARY KEY.
 *   - PHPUnit is single-threaded, so nothing here is ever actually raced.
 *
 * So these tests prove the control's LOGIC: which fingerprint is built, which branch each press
 * takes, what it writes and what it answers. The one property they structurally cannot prove —
 * that under real concurrency the second caller loses, and loses because the DATABASE said so —
 * is measured by `tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php`.
 *
 * AND THE RECEIPT IS A CONTRACT. `printView()` builds the printed invoice from the same session
 * key, so consuming the basket in `store()` would have broken the invoice for whichever of the two
 * racing requests lost. The basket is therefore MOVED into a single-use, timestamped receipt copy
 * that `printView()` falls back to. `test_the_printed_receipt_survives_the_basket_being_consumed`
 * holds that, and it failed against the first version of this change.
 */
class PosSaleIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function productIn($store, int $stock, string $name = 'Till Widget'): Product
    {
        return Product::withoutEvents(fn () => Product::forceCreate([
            'name' => $name, 'slug' => 'till-' . uniqid(),
            'price' => 10.00, 'sale_price' => 10.00,
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));
    }

    /** Raw: `Product` is Cachable, so an Eloquent read can serve a mutated in-memory copy. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function ordersIn($store): int
    {
        return (int) DB::table('orders')->where('store_id', $store->id)->count();
    }

    /** One POS cart line, in the shape `store()` reads and `addToCart()` writes. */
    private function posLine(Product $product, int $quantity, ?int $originalQuantity = null): array
    {
        return [
            'product_id' => $product->id,
            'id' => $product->id,
            'name' => $product->name,
            'image' => '',
            'quantity' => $quantity,
            'originalquantity' => $originalQuantity ?? (int) $product->product_stock,
            'orignal_price' => 10.00,
            'per_product_discount_price' => 0,
            'discount_price' => 0,
            'final_price' => 10.00 * $quantity,
            'tax' => 0,
            'total_orignal_price' => 10.00 * $quantity,
        ];
    }

    /**
     * THE PRESS ID, AND WHY THE SAME ONE GOES INTO BOTH REQUESTS OF A RACE.
     *
     * `create()` writes `new_pos_id_<store>` each time the payment modal is opened, and the claim
     * now carries it so that two separate customers with identical baskets are not confused for
     * one double-click (the hole independent review found in the first version of this change).
     *
     * A LOST SESSION RACE THEREFORE HOLDS ONE PRESS ID IN BOTH REQUESTS: `store()` forgets the key
     * as it works, but neither request has SAVED that forget when the other reads. Seeding a fresh
     * id per request — which the first version of this harness did, with `uniqid()` — would model
     * two different modals and would make the concurrent case pass by not being a duplicate at
     * all. `test_a_new_payment_modal_with_the_same_basket_is_a_real_second_sale` drives the other
     * side on purpose.
     */
    private const ONE_PRESS = 'POS-1762000000';

    private function basket(array $lines): array
    {
        $cart = [];
        foreach ($lines as $line) {
            $cart[$line['id']] = $line;
        }

        return $cart;
    }

    /**
     * Press PAY.
     *
     * @param  bool  $reseedBasket  true models two requests that BOTH read the basket before
     *                              either wrote — i.e. the concurrent case. false lets the sale
     *                              see whatever the previous request left behind — the sequential
     *                              case, and the one the owner's directive names.
     */
    private function press(
        $merchant,
        $store,
        array $cart,
        string $price = '20.00',
        bool $reseedBasket = true,
        ?string $pressId = self::ONE_PRESS
    ) {
        $request = $this->actingAs($merchant);

        if ($reseedBasket) {
            $request = $request->withSession([
                'pos_' . $store->id => $cart,
                'new_pos_id_' . $store->id => $pressId,
            ]);
        }

        return $request->post(route('pos.data.store'), [
            'store_id' => $store->id,
            'customer_id' => 0,
            'price' => $price,
            'discount' => 0,
            'payment_type' => 'cash',
        ]);
    }

    private function tillReady(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Pos');

        return [$merchant, $store];
    }

    // =============================================================================================
    //  1. THE POSITIVE CONTROL FIRST — every refusal below is worthless without it
    // =============================================================================================

    public function test_an_ordinary_till_sale_still_completes_and_moves_stock(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);

        $response = $this->press($merchant, $store, $this->basket([$this->posLine($product, 2)]));

        $this->assertSame(200, $response->getStatusCode(),
            'an ordinary till sale no longer completes: ' . substr((string) $response->getContent(), 0, 400));
        $this->assertSame(200, (int) $response->json('code'));
        $this->assertSame('success', $response->json('status'));
        $this->assertNotEmpty($response->json('order_id'), 'the sale returned no order id');

        $this->assertSame(1, $this->ordersIn($store), 'the sale wrote no order');
        $this->assertSame(48, $this->stockOf($product), 'the sale did not move stock');
    }

    // =============================================================================================
    //  2. THE DEFECT THE DIRECTIVE NAMES — the sequential replay
    // =============================================================================================

    /**
     * The basket is CONSUMED by a completed sale, so a second press has nothing to sell.
     *
     * `$reseedBasket: false` on the second press is the whole point: the second request sees the
     * session exactly as the first left it, which is what a cashier pressing Cash Payment twice
     * really produces.
     */
    public function test_a_second_press_after_a_completed_sale_sells_nothing(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 2)]);

        $first = $this->press($merchant, $store, $cart);
        $this->assertSame(200, (int) $first->json('code'), 'premise: the first sale did not complete');
        $this->assertSame(48, $this->stockOf($product), 'premise: the first sale did not move stock');

        $second = $this->press($merchant, $store, $cart, reseedBasket: false);

        $this->assertSame(1, $this->ordersIn($store),
            'a second press of Cash Payment wrote a SECOND order for the same basket. The basket '
            . 'is not being consumed by a completed sale.');
        $this->assertSame(48, $this->stockOf($product),
            'a second press of Cash Payment decremented stock AGAIN for a basket that was '
            . 'already sold.');
        $this->assertSame(404, (int) $second->json('code'),
            'the second press did not report an empty basket, so something other than the '
            . 'consumed basket is what stopped it');
    }

    public function test_the_basket_is_gone_from_the_session_after_a_completed_sale(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);

        $this->press($merchant, $store, $this->basket([$this->posLine($product, 1)]));

        $this->assertEmpty((array) session('pos_' . $store->id, []),
            'the basket is still in the session after a completed sale, so the POS screen will '
            . 'redraw it fully populated and invite the cashier to sell it again');
    }

    // =============================================================================================
    //  3. THE HALF A BARE `forget` CANNOT CLOSE — two presses in flight together
    // =============================================================================================

    /**
     * THE TEST THAT SEPARATES THE TWO FIXES.
     *
     * Both presses are given the pre-sale basket, which is exactly what a lost session race
     * produces: each request loaded the session before the other saved it. A `session()->forget()`
     * in `store()` cannot refuse the second one — it never sees the forget, because it is holding
     * its own older copy. Only a claim outside the session can.
     */
    public function test_two_presses_that_both_read_the_basket_still_sell_it_once(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 2)]);

        $first = $this->press($merchant, $store, $cart);
        $this->assertSame(200, (int) $first->json('code'), 'premise: the first sale did not complete');

        $second = $this->press($merchant, $store, $cart);

        $this->assertSame(1, $this->ordersIn($store),
            'TWO orders exist for one basket. The second press was holding its own pre-sale copy '
            . 'of the session, so clearing the basket in the first request could not stop it — '
            . 'this is the concurrent half of F269 and it needs a claim outside the session.');
        $this->assertSame(48, $this->stockOf($product),
            'stock was decremented twice for one basket');

        /*
         * AND THE ANSWER IS THE FIRST SALE'S, NOT A NEW ONE. The first version of this case
         * asserted the second press was NOT answered with a 200, and it failed — correctly. In a
         * single-threaded harness the first request has fully completed by the time the second
         * starts, so the claim already carries the finished sale's order id and the honest answer
         * is that sale's success, not an error. What must never happen is a 200 carrying a
         * DIFFERENT id, which would mean a second order.
         */
        $this->assertSame(
            Crypt::decrypt($first->json('order_id')),
            Crypt::decrypt($second->json('order_id')),
            'the duplicate press was answered with a different order id, so it wrote a second order'
        );
    }

    /**
     * THE IN-FLIGHT BRANCH, modelled the only way a single-threaded harness can.
     *
     * The case above cannot reach it: PHPUnit runs the first request to completion, so the claim
     * it finds already carries an order id. The branch that matters under real concurrency is the
     * other one — a claim held by a sibling that has NOT finished writing — and there is no way to
     * produce a genuinely unfinished sibling request in-process.
     *
     * So the claim is placed directly, and THE HANDLER'S OWN FINGERPRINT IS USED TO PLACE IT.
     * Reflecting into `posSaleFingerprint()` rather than recomputing the hash here is deliberate:
     * a copy of the hashing in the test would agree with itself whatever the handler did, which
     * is the way this project's tests have passed for the wrong reason before. What is measured
     * is the handler's behaviour on that branch — it must write nothing, and it must refuse
     * VISIBLY.
     */
    public function test_a_press_arriving_while_a_sibling_is_still_writing_is_refused(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 2)]);

        $fingerprint = $this->fingerprintFor($cart, (int) $store->id, (int) $merchant->id, 20.00, 0, self::ONE_PRESS);
        Cache::put($fingerprint, 'writing', now()->addSeconds(120));

        $response = $this->press($merchant, $store, $cart);

        $this->assertSame(0, $this->ordersIn($store),
            'a press arriving while a sibling was still writing rang up its own sale');
        $this->assertSame(50, $this->stockOf($product),
            'a press arriving while a sibling was still writing moved stock');

        /*
         * HTTP 409, AND THAT STATUS IS THE WHOLE POINT. The POS screen's `success:` callback only
         * reads `data.code == 200` and `== 404`; anything else is swallowed without a word to the
         * cashier, who would press again believing the till is dead. A 4xx routes into the
         * screen's existing `error:` callback, which already shows `data.error` as a toast
         * (`resources/views/pos/index.blade.php:857-861`) — so the refusal is visible with no
         * front-end change at all.
         */
        $this->assertSame(409, $response->getStatusCode(),
            'the refusal does not carry a status the POS screen shows the cashier, so a refused '
            . 'press is silent. Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertNotEmpty($response->json('error'),
            'the refusal carries no `error` message, which is the key the POS toast reads');
    }

    /** The handler's own fingerprint, not a copy of it. */
    private function fingerprintFor(array $cart, int $storeId, int $userId, $price, $discount, $pressId = null): string
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\PosController::class, 'posSaleFingerprint');
        $method->setAccessible(true);

        return $method->invoke(
            new \App\Http\Controllers\PosController,
            $cart,
            $storeId,
            $userId,
            $price,
            $discount,
            $pressId
        );
    }

    /**
     * AND THE REPLAY GETS THE FIRST SALE'S ANSWER, not an error — when the first has finished.
     *
     * This is what makes the mechanism idempotent rather than merely obstructive: the cashier's
     * second press hands back the SAME order id, so the receipt they open is the receipt for the
     * sale that really happened.
     */
    public function test_a_replay_of_a_finished_sale_returns_the_same_order_id(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 2)]);

        $first = $this->press($merchant, $store, $cart);
        $firstId = Crypt::decrypt($first->json('order_id'));

        /* The basket is back — a lost session race, or the cashier re-adding the same two units. */
        $replay = $this->press($merchant, $store, $cart);

        if ((int) $replay->json('code') === 200) {
            $this->assertSame($firstId, Crypt::decrypt($replay->json('order_id')),
                'the replay reported success carrying a DIFFERENT order id, which means a second '
                . 'order was written');
        }

        $this->assertSame(1, $this->ordersIn($store));
        $this->assertSame(
            1,
            (int) DB::table('orders')->where('store_id', $store->id)->where('id', $firstId)->count(),
            'the one surviving order is not the one the first press reported'
        );
    }

    // =============================================================================================
    //  4. THE CLAIM IS NOT A BLANKET REFUSAL — it must not block real trade
    // =============================================================================================

    /**
     * A GENUINELY DIFFERENT BASKET AT THE SAME TILL IS NOT A DUPLICATE. A claim that refused this
     * would stop a shop trading, which is worse than the defect it fixes.
     */
    public function test_a_different_basket_at_the_same_till_is_not_refused(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);

        $this->press($merchant, $store, $this->basket([$this->posLine($product, 2)]));
        $this->assertSame(48, $this->stockOf($product), 'premise');

        /* Next customer, three of the same item. */
        $second = $this->press($merchant, $store, $this->basket([$this->posLine($product, 3)]), '30.00');

        $this->assertSame(200, (int) $second->json('code'),
            'the till refused a different basket as a duplicate, which stops the shop trading: '
            . substr((string) $second->getContent(), 0, 300));
        $this->assertSame(2, $this->ordersIn($store));
        $this->assertSame(45, $this->stockOf($product));
    }

    /**
     * AND A SECOND SHOP SELLING THE SAME THING IS NOT A DUPLICATE EITHER. The fingerprint carries
     * the SERVER-RESOLVED store and the authenticated cashier, so one tenant cannot evict or
     * collide with another's claim — the standing rule in this codebase is that a predicate built
     * from caller input is not a control.
     */
    public function test_another_stores_identical_basket_is_not_refused(): void
    {
        [$merchantA, $storeA] = $this->tillReady();
        [$merchantB, $storeB] = $this->tillReady();

        $productA = $this->productIn($storeA, 50);
        $productB = $this->productIn($storeB, 50);

        $a = $this->press($merchantA, $storeA, $this->basket([$this->posLine($productA, 2)]));
        $b = $this->press($merchantB, $storeB, $this->basket([$this->posLine($productB, 2)]));

        $this->assertSame(200, (int) $a->json('code'), 'store A could not sell');
        $this->assertSame(200, (int) $b->json('code'),
            'store B was refused as a duplicate of store A\'s sale, so one tenant\'s till is '
            . 'blocking another\'s: ' . substr((string) $b->getContent(), 0, 300));

        $this->assertSame(1, $this->ordersIn($storeA));
        $this->assertSame(1, $this->ordersIn($storeB));
        $this->assertSame(48, $this->stockOf($productA));
        $this->assertSame(48, $this->stockOf($productB));

        /*
         * AND THE SEPARATION IS THE STORE AND THE CASHIER, NOT THE DIFFERENT PRODUCT IDS — which
         * is what the two sales above actually differ by, as independent review pointed out. The
         * assertions above would stay green with `store` and `cashier` deleted from the
         * fingerprint entirely. One identical basket through the handler's OWN fingerprint is the
         * only way to measure the claim this test's name makes.
         */
        $sharedCart = $this->basket([$this->posLine($productA, 2)]);

        $this->assertNotSame(
            $this->fingerprintFor($sharedCart, (int) $storeA->id, (int) $merchantA->id, 20.00, 0, self::ONE_PRESS),
            $this->fingerprintFor($sharedCart, (int) $storeB->id, (int) $merchantB->id, 20.00, 0, self::ONE_PRESS),
            'one basket fingerprints identically for two different stores and cashiers, so one '
            . 'tenant\'s till really could block another\'s'
        );
    }

    /**
     * THE OTHER SIDE OF THE PRESS ID, and the hole the first version of this change left open.
     *
     * Two separate walk-ins each buy one unit of the same SKU at the same till. The POS screen
     * posts byte-identical fields, so keyed on basket content alone the second sale hashed the
     * same as the first and was answered as a replay: cash taken, NO order row, NO stock
     * decrement, and — because the receipt GET fires from the same click — most likely a printed
     * invoice for the first sale. That loses a sale silently, which is worse than the duplicate
     * this change exists to prevent.
     *
     * A new payment modal writes a new `new_pos_id_<store>`, so it is a new claim.
     */
    public function test_a_new_payment_modal_with_the_same_basket_is_a_real_second_sale(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 1)]);

        $first = $this->press($merchant, $store, $cart, '10.00', true, 'POS-1762000000');
        $this->assertSame(200, (int) $first->json('code'), 'premise: the first customer could not pay');

        /* The cashier reopens the payment modal for the next customer: a new press id, the same
           one item, the same price, the same till, within the claim window. */
        $second = $this->press($merchant, $store, $cart, '10.00', true, 'POS-1762000090');

        $this->assertSame(200, (int) $second->json('code'),
            'the second customer\'s identical purchase was refused or swallowed as a replay: '
            . substr((string) $second->getContent(), 0, 300));
        $this->assertSame(2, $this->ordersIn($store),
            'the second customer paid and no order was written for them. Cash is in the till with '
            . 'nothing recorded against it — the opposite failure from the one this guard exists '
            . 'to stop.');
        $this->assertSame(48, $this->stockOf($product),
            'the second sale took no stock, so the shelf and the record now disagree');
        $this->assertNotSame(
            Crypt::decrypt($first->json('order_id')),
            Crypt::decrypt($second->json('order_id')),
            'the second customer was handed the FIRST customer\'s order id, so their receipt is '
            . 'somebody else\'s invoice'
        );
    }

    // =============================================================================================
    //  4b. THE CLAIM IS RELEASED WHEN NOTHING WAS RECORDED — and it was released nowhere at first
    // =============================================================================================

    /**
     * A SALE THAT DIED BEFORE THE ORDER EXISTED MUST NOT LEAVE THE TILL DEAD FOR TWO MINUTES.
     *
     * Independent review found that the first version of this change released the claim nowhere,
     * while the docblock above `posSaleFingerprint()` said it was released on a path that wrote
     * nothing. So a throw between the claim and `$pos->save()` left the basket unsellable until
     * the TTL lapsed, with the cashier getting "This sale is already being processed" over and
     * over for a sale that had not been recorded at all.
     *
     * DRIVEN WITH AN `Order::creating` LISTENER, because that is the only way to make the order
     * insert fail at the right moment in-process. The listener is removed before the retry, which
     * is the half that proves the release happened: a held claim would refuse the retry with a
     * 409 rather than selling.
     */
    public function test_a_sale_that_died_before_the_order_existed_can_be_retried(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);
        $cart = $this->basket([$this->posLine($product, 2)]);

        Order::creating(function () {
            throw new \RuntimeException('simulated failure between the claim and the order insert');
        });

        try {
            $this->press($merchant, $store, $cart);
        } catch (\Throwable $e) {
            // Laravel's handler may or may not convert it; either way the sale did not complete.
        }

        $this->assertSame(0, $this->ordersIn($store),
            'premise: the simulated failure did not actually stop the order being written');

        /* The listener is the only thing that was wrong; a real retry happens without it. */
        Order::flushEventListeners();
        Order::boot();

        $retry = $this->press($merchant, $store, $cart);

        $this->assertSame(200, (int) $retry->json('code'),
            'the retry of a sale that recorded NOTHING was refused, so the claim is being held on '
            . 'a path that wrote no order and the till is dead until the TTL lapses. Body: '
            . substr((string) $retry->getContent(), 0, 300));
        $this->assertSame(1, $this->ordersIn($store), 'the retry wrote no order');
    }

    // =============================================================================================
    //  5. THE RECEIPT — the contract the directive names, and the one the first fix broke
    // =============================================================================================

    /**
     * The invoice must still render after the sale consumed the basket.
     *
     * This is the case a bare `session()->forget()` in `store()` breaks: `printView()` builds the
     * invoice from the same key, the two requests race, and the loser renders "Cart is empty!"
     * for a sale that completed perfectly.
     */
    public function test_the_printed_receipt_survives_the_basket_being_consumed(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50, 'Receipt Widget');

        $sale = $this->press($merchant, $store, $this->basket([$this->posLine($product, 2)]));
        $this->assertSame(200, (int) $sale->json('code'), 'premise: the sale did not complete');
        $this->assertEmpty((array) session('pos_' . $store->id, []), 'premise: the basket was not consumed');

        $receipt = $this->actingAs($merchant)->get(route('pos.printview'));

        $this->assertSame(200, $receipt->getStatusCode(),
            'the receipt request failed: ' . substr((string) $receipt->getContent(), 0, 300));
        $this->assertTrue((bool) $receipt->json('success'),
            'the invoice reported an empty cart for a sale that completed. Consuming the basket '
            . 'broke the receipt, which the directive names as a contract to preserve. Body: '
            . substr((string) $receipt->getContent(), 0, 300));
        $this->assertStringContainsString('Receipt Widget', (string) $receipt->json('html'),
            'the invoice rendered without the line item it is an invoice for');
    }

    /**
     * AND THE RECEIPT COPY IS SINGLE USE. A second receipt request after the copy is spent must
     * report an empty cart rather than reprinting — otherwise a later, unrelated receipt request
     * could print a previous sale's invoice.
     */
    public function test_the_receipt_copy_does_not_survive_being_printed(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50, 'Receipt Widget');

        $this->press($merchant, $store, $this->basket([$this->posLine($product, 2)]));

        /* THE PREMISE. Without a receipt copy at all, "the second print reports an empty cart"
           is satisfied for free — which is exactly what it did against unfixed code. */
        $printed = $this->actingAs($merchant)->get(route('pos.printview'));
        $this->assertTrue((bool) $printed->json('success'),
            'the first print did not render, so there was never a receipt copy to outlive the '
            . 'sale and this test measures nothing');

        $again = $this->actingAs($merchant)->get(route('pos.printview'));

        $this->assertFalse((bool) $again->json('success'),
            'the receipt copy reprinted after it was already used, so it can outlive the sale it '
            . 'belongs to and surface as a later sale\'s invoice');
        $this->assertEmpty((array) session('pos_receipt_' . $store->id, []),
            'the receipt copy is still in the session after being printed');
    }

    // =============================================================================================
    //  6. THE GATE IS STILL THE GATE
    // =============================================================================================

    public function test_a_principal_without_create_pos_sells_nothing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 50);

        $response = $this->press($merchant, $store, $this->basket([$this->posLine($product, 2)]));

        $this->assertSame(0, $this->ordersIn($store),
            'a principal without `Create Pos` rang up a sale');
        $this->assertSame(50, $this->stockOf($product));
        $this->assertSame(404, (int) $response->json('code'));
    }

    /**
     * AND A PRINCIPAL WHO RESOLVES NO STORE IS REFUSED RATHER THAN CRASHING.
     *
     * `store()` dereferences `$store->id` unconditionally, so a null store was a 500; and a
     * fingerprint keyed on an empty store id would have put every such principal onto ONE shared
     * claim, where any of them could block the others.
     *
     * THE PRINCIPAL HAS TO BE STAFF, and the first version of this case used the merchant with
     * `current_store` nulled — which proves nothing. `getCurrentStore()` (helper.php:758-781)
     * falls back to `Store::where('created_by', $user->id)->first()`, so a merchant who OWNS a
     * store resolves it anyway and the handler never reaches the branch. Staff own no store, so
     * for them the fallback finds nothing and `getCurrentStore()` really does return null.
     */
    public function test_a_principal_who_resolves_no_store_is_refused_not_crashed(): void
    {
        [$merchant, $store] = $this->tillReady();
        $product = $this->productIn($store, 50);

        $staff = $this->makeStaff($merchant, ['current_store' => null]);
        $this->grantPermission(\App\Models\User::find($staff->id), 'Create Pos');
        $staff = \App\Models\User::find($staff->id);

        $this->assertNull(getCurrentStore($staff->id),
            'the premise failed: this principal still resolves a store, so the refusal below is '
            . 'not the branch under test');

        /* `'pos_'` with no suffix is literally what `'pos_' . getCurrentStore()` builds here. */
        $response = $this->actingAs($staff)
            ->withSession(['pos_' => $this->basket([$this->posLine($product, 2)])])
            ->post(route('pos.data.store'), [
                'store_id' => $store->id,
                'customer_id' => 0,
                'price' => '20.00',
                'discount' => 0,
            ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the till crashed for a principal who resolves no store rather than refusing: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(0, $this->ordersIn($store), 'a storeless principal wrote an order');
        $this->assertSame(50, $this->stockOf($product));

        /*
         * AND IT IS THE STORE REFUSAL, NOT THE PERMISSION ONE — independent review caught that
         * the three assertions above are all satisfied by the `Create Pos` denial, which also
         * answers `code: 404` at HTTP 200 (`PosController.php:586-592`). The store refusal is the
         * only one of the two that carries an `error` key, so that is what identifies it.
         */
        $this->assertSame(__('No store is selected.'), $response->json('error'),
            'this is not the store guard\'s own refusal, so the test is measuring some other '
            . 'refusal and proves nothing about the branch under test. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }
}

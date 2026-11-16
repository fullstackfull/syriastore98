<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F018 — the WEB checkout has no double-submit control, and no census can see it.
 * =================================================================================================
 *
 * `POST /{slug}/process-order` is the storefront's own checkout. `PaymentController::processOrder`
 * reads the basket, pre-flights it, and hands it to `PaymentService::process()`, whose `cod` and
 * `bank_transfer` arms both call `PaymentService::getProductStatus()` — the INLINE writer that
 * creates the order (`app/Services/PaymentService.php:2800`, `$order = new Order();`) and
 * decrements stock, synchronously, inside the same request.
 *
 * Between the cart read and that write there is no claim of any kind. Measured by reading
 * `processOrder` (`app/Http/Controllers/PaymentController.php:1841-2027`) end to end and grepping
 * the whole of `PaymentService` for `Cache::add`, `Cache::lock`, `claimGatewayReturn` and
 * `lockForUpdate`: the service has NONE of them (its only mention of `claimGatewayReturn` is prose,
 * in `stampCheckoutNonce()`'s docblock at :363). Two requests in flight together therefore both
 * read the same non-empty basket and both write an order.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS IS NOT THE `claimGatewayReturn()` GAP, which is a different question on a different
 * method. `PaymentController::getProductStatus` (:2857) is the generic gateway RETURN and does
 * claim — `storedCheckoutState()` at :2870 then `claimGatewayReturn()` at :2966. The writer this
 * file is about is the SERVICE method of the same name (`PaymentService::getProductStatus`, :2225),
 * reached on the way OUT of the checkout rather than back from a gateway. A replay guard keyed on a
 * gateway nonce cannot cover it: there is no gateway and no return.
 *
 * WHY NO CENSUS SEES IT. `Tests\Support\Commerce\OrderWriteCensus::sites()` walks
 * `base_path('app/Http/Controllers')` and nothing else (:64), so every writer under `app/Services`
 * is structurally outside its population — `PaymentService.php:2800` (unguarded, this finding) and
 * `OrderReplacement.php:476` (guarded, by `lockForUpdate()` at :411 plus the
 * `orders_replaced_order_id_unique` index its :525 comment names). `StockAttributionTest:796-797`
 * states the root limitation, but states it for STOCK EVIDENCE, not for replay. What misleads is
 * `GatewayReplayGuardCensusTest:297-301`, which asserts 16 sites and calls them
 * *"the whole first-party customer-order write population"* — a claim that is false by at least two.
 *
 * REACHABILITY IS OVERLAPPING SUBMITS, AND SAYING SO IS PART OF THE FINDING. A merely SEQUENTIAL
 * replay is stopped, incidentally, by an empty basket: `getCartData()` answers `'Cart is empty.'`
 * (`PaymentController:2338` guest, `:2365` authenticated) because `clearCartLines()` runs after
 * `DB::commit()` (`PaymentService:3091,3098`). So the window is "both requests read the cart before
 * either delete runs" — a double-tap on Pay, a mobile retry after a timeout, a double POST from a
 * back-then-resubmit. `test_the_sequential_replay_is_stopped_by_the_empty_basket_not_by_a_control`
 * below pins that, so the restore the duplication cases perform is visibly load-bearing rather than
 * decoration.
 *
 * AND *HOW* THE CART IS RESTORED IS THE WHOLE DISTINCTION — the same one
 * `StorefrontOrderIntakeIdempotencyTest` records for the API writers:
 *
 *   SAME `carts.id`  → both requests are reading the same rows. A RACE. One order.
 *   NEW  `carts.id`  → the shopper built a fresh basket. A SECOND PURCHASE. Two orders, and a
 *                      control that refused it would swallow a real sale, which is worse than the
 *                      duplication it prevents.
 *
 * Delete-and-recreate gives a NEW id and would quietly model the second case while claiming the
 * first, so the race cases here re-insert the row with its original id and flush `Cart`'s cache.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT PROVE, stated rather than implied. `phpunit.xml` sets `CACHE_STORE=array`
 * and `ArrayStore` has no native `add()`, so any `Cache::add()` fix would here be `Repository::add()`
 * — a `get()` then a `put()`. These cases can only measure a control's LOGIC. That the second of two
 * SIMULTANEOUS callers loses because the store said so is measured on real MariaDB by
 * `tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php`.
 *
 * `Product` is `Cachable` and `Cart` is one of the two models whose `StoreScope` really filters, so
 * every stock reading here goes through `DB::table('products')` and every cart write flushes.
 */
final class WebCheckoutIntakeIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 50;

    /** One unit per line, so two orders read 48 and one reads 49 — the figures cannot alias. */
    private const LINE_QTY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // The COD writer mails the customer and the store owner on success.
        Mail::fake();
    }

    // =============================================================================================
    //  1. PREMISES — four ways a case here could pass without reaching the writer
    // =============================================================================================

    /**
     * THE ROUTE EXISTS AND IS THE HANDLER THIS FILE IS ABOUT.
     *
     * A 404 satisfies "no second order was written" perfectly. `{slug}/process-order` is declared
     * in a route file this suite does not otherwise touch, and route collisions are a standing
     * hazard here — a later declaration for the same method+URI replaces an earlier one silently.
     * So the action name is asserted, not the status code.
     */
    public function test_premise_the_checkout_route_resolves_to_process_order(): void
    {
        $action = null;

        foreach (Route::getRoutes() as $route) {
            if (in_array('POST', $route->methods(), true)
                && str_ends_with($route->uri(), 'process-order')) {
                $action = $route->getActionName();

                break;
            }
        }

        $this->assertSame(
            'App\Http\Controllers\PaymentController@processOrder',
            $action,
            'the POST route ending in `process-order` is not PaymentController@processOrder, so '
            . 'every case in this file is driving something other than the writer it names'
        );
    }

    /**
     * THE WRITER IS REACHED, AND THE BASKET IS NOT REFUSED ON THE WAY IN.
     *
     * The positive control. `processOrder` refuses for a dozen unrelated reasons in a bare fixture
     * — invalid payment type, a missing billing field, an empty cart, a foreign product, a
     * non-positive quantity, an unpriceable checkout — and every one of them returns
     * `redirect()->back()->with('error', ...)`, i.e. a 302 that looks exactly like success from
     * the outside. Without this case the duplication assertions below would be satisfied by a
     * handler that refuses everything.
     */
    public function test_premise_one_web_checkout_writes_one_order_and_moves_stock_once(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        session()->forget('error');
        $response = $this->webCheckout($store, $customer, $shippingId);

        $this->assertNull(session('error'),
            'the web checkout refused an ordinary basket: ' . json_encode(session('error')));
        $this->assertNotSame(404, $response->status(), 'the checkout route 404d');
        $this->assertNotSame(500, $response->status(), 'the checkout route 500d');

        $this->assertSame(1, $this->ordersIn($store),
            'no order was written on the happy path, so nothing below is measuring duplication');
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'no stock moved on the happy path');
    }

    /**
     * THE ORDER REALLY CAME OUT OF THE INLINE COD WRITER, not some other path.
     *
     * `PaymentService::getProductStatus()` stamps `Order::SOURCE_STOREFRONT` at :2801 and is the
     * only `cod` arm of `PaymentService::process()` (:436-439). Asserting the stamp ties the row
     * this file counts to the writer this file claims is unguarded.
     */
    public function test_premise_the_order_is_written_by_the_storefront_cod_writer(): void
    {
        [$store, $customer, , $shippingId] = $this->checkout();

        $this->webCheckout($store, $customer, $shippingId);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'premise: no order row to inspect');
        $this->assertSame(\App\Models\Order::SOURCE_STOREFRONT, (string) $order->order_source,
            'the order was not written by the storefront writer, so this file is counting rows '
            . 'that some other path produced');
        $this->assertSame((int) $customer->id, (int) $order->customer_id,
            'the order was not written for the authenticated customer');
    }

    /**
     * AND THE RESTORE IS LOAD-BEARING — the reachability correction, pinned.
     *
     * A bare second post, with nothing put back, is refused by `getCartData()`'s `'Cart is empty.'`
     * because `clearCartLines()` already ran. That refusal is incidental: it is the absence of
     * rows, not a control. If this case ever goes red — a second order written with no cart — the
     * finding is WIDER than reported, because a plain sequential replay would then duplicate too.
     *
     * Keeping it green is also what stops the duplication cases below from being vacuous: it proves
     * they only reach the writer twice because the restore puts the basket back.
     */
    public function test_the_sequential_replay_is_stopped_by_the_empty_basket_not_by_a_control(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->webCheckout($store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise: the first checkout wrote no order');

        $this->assertSame(0, (int) DB::table('carts')->where('customer_id', $customer->id)->count(),
            'premise: the first checkout did not clear the cart, so the refusal measured below '
            . 'would not be the empty-basket one');

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store),
            'a bare sequential replay wrote a SECOND order with no cart rows at all. The finding '
            . 'is then wider than P4-F018 reports — not only overlapping submits.');
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'a bare sequential replay moved stock a second time');
        $this->assertSame('Cart is empty.', session('error'),
            'the bare replay was stopped by something other than the empty basket. The reported '
            . 'reachability (overlapping submits only) rests on this being the refusal.');
    }

    // =============================================================================================
    //  2. THE DEFECT
    // =============================================================================================

    /**
     * TWO POSTS OF ONE CHECKOUT MUST WRITE ONE ORDER.
     *
     * The same `carts` rows, ids included: the shape of two submits whose cart reads overlap. On a
     * phone a timed-out request has still arrived, and a shopper tapping Pay twice is the ordinary
     * case rather than the attack — made likelier here because the refusals and the success are
     * both 302s to the previous page, so the client cannot tell them apart.
     *
     * The second order is not merely a duplicate record: it decrements the merchant's stock again,
     * so the shelf and the ledger disagree, and for `cod` it is a second amount for a driver to
     * collect.
     */
    public function test_a_repeated_web_checkout_post_does_not_write_a_second_order(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $cartId = $this->cartRowIdOf($customer);

        $this->webCheckout($store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store), 'premise: the first post wrote no order');
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'premise: the first post moved no stock');

        /* The SAME rows the first request read — a race, not a rebuilt basket. */
        $this->restoreSameCartRow($store, $customer, $product, $cartId);

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId);

        /*
         * MEASURED AT d227b7c0, 2026-11-16, before any fix:
         *     orders = 2, products.product_stock = 48 (opened at 50, one unit per line),
         *     carts  = 0 (cleared a second time), session('error') = null.
         * The shopper is told the checkout succeeded, twice, and the merchant is short a unit.
         */
        /*
         * AND IT GOT AS FAR AS THE CLAIM. Without this the case is satisfiable by a second post
         * refused for ANY reason at all -- an empty basket, an unavailable line, a validation
         * failure -- and would stay green against a fix that never ran. The claim is placed after
         * every pre-flight refusal in `processOrder()`, so this particular message can only come
         * from a request that reached it. Named by an adversarial read of this file before the
         * fix landed; the identical vacuity in the API twin is what that read was looking for.
         */
        $this->assertStringContainsString(
            'already been submitted',
            (string) session('error'),
            'the second post was refused by something OTHER than the duplicate-submission claim, '
            . 'so this case is measuring an earlier guard rather than the control under test. '
            . 'session(error) held: ' . var_export(session('error'), true)
        );

        $this->assertSame(1, $this->ordersIn($store),
            'a repeated post to `{slug}/process-order` wrote a SECOND order for one checkout. '
            . 'There is no double-submit claim anywhere between the cart read in '
            . 'PaymentController::processOrder and the inline write in '
            . 'PaymentService::getProductStatus (app/Services/PaymentService.php:2800).');
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'a repeated post decremented stock a second time for one checkout, so the merchant '
            . 'shows ' . (self::OPENING_STOCK - 2 * self::LINE_QTY) . ' on the shelf for '
            . self::LINE_QTY . ' unit actually sold');
    }

    /**
     * THE GUEST DEFECT, MEASURED ON THE BRANCH NO OTHER CASE IN THIS FILE REACHES.
     *
     * A two-line guest basket, the same rows on both posts — a race, not a rebuild. Before the
     * cart ids were read from the DATABASE this failed in the direction that matters: the payload
     * handed the claim `[1, 2]`, which are some other tenant's rows or nobody's, so the second
     * post wrote a second order AND the shopper's goods were taken twice.
     *
     * The premise assertions are what stop this passing for the wrong reason: a guest checkout
     * that never wrote an order at all would satisfy "no second order" perfectly.
     */
    public function test_a_repeated_guest_checkout_post_does_not_write_a_second_order(): void
    {
        [$store, , $product, $shippingId] = $this->checkout();

        $second = Product::forceCreate([
            'name' => 'p4f026-' . uniqid(), 'slug' => 'p4f026-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $cookie = 'guest-cart-' . uniqid();
        $rows = $this->guestBasket($store, $product, $second, $cookie);

        $ssFirst = $this->guestCheckout($store, $shippingId, $cookie);

        /* THE PREMISE. Without it every assertion below is satisfied by a guest checkout that
           never reached the writer -- and the guest branch is precisely the one no other case in
           this file has ever driven, so it cannot be assumed to work. */
        $this->assertSame(1, $this->ordersIn($store),
            'the FIRST guest post wrote no order, so this case is not measuring a duplicate. '
            . 'status=' . $ssFirst->getStatusCode() . ' error=' . var_export(session('error'), true)
            . ' carts=' . DB::table('carts')->where('cookie_session_id', $cookie)->count());
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'premise: the first guest post moved no stock');

        /* The SAME rows the first request read, ids and all. */
        DB::table('carts')->where('cookie_session_id', $cookie)->delete();

        foreach ([[$rows[0], $product], [$rows[1], $second]] as [$id, $line]) {
            DB::table('carts')->insert([
                'id' => $id, 'customer_id' => 0, 'cookie_session_id' => $cookie,
                'product_id' => $line->id, 'variant_id' => 0,
                'qty' => self::LINE_QTY, 'price' => 10.00 * self::LINE_QTY,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new Cart)->flushCache();

        session()->forget('error');
        $response = $this->guestCheckout($store, $shippingId, $cookie);

        $this->assertStringContainsString(
            'already been submitted',
            (string) session('error'),
            'the second GUEST post was refused by something other than the duplicate-submission '
            . 'claim, so this case is measuring an earlier guard. session(error) held: '
            . var_export(session('error'), true)
        );

        $this->assertSame(1, $this->ordersIn($store),
            'a repeated GUEST post wrote a SECOND order. The claim read its cart ids from the '
            . 'payload, and `Cart::cart_list_cookie` puts the LOOP INDEX in `cart_id`, so the '
            . "guest branch's claim was bound to rows that are not this basket's.");

        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product),
            'a repeated GUEST post decremented stock a second time');
    }

    /**
     * P4-F096 — AN INCOMPLETE GUEST BILLING FORM IS A MESSAGE, NOT A 500.
     *
     * `handleCustomerRegistration()` returns `redirect()->back()->with('error', ...)` from two
     * validation failures, and `if (! $customer)` does not catch a `RedirectResponse` — an object
     * is truthy — so the next statement read `$customer->id` on it. Measured before the fix:
     * "Attempt to read property id on Illuminate\Http\RedirectResponse", a 500 served to an
     * anonymous visitor who simply left a required field out of the billing form.
     *
     * The assertion is on the MESSAGE rather than on the status code, because both outcomes are a
     * 302 in a browser and only the message distinguishes "we told you what was wrong" from
     * "something exploded".
     */
    public function test_a_guest_with_an_incomplete_billing_form_is_told_what_is_missing(): void
    {
        [$store, , $product, $shippingId] = $this->checkout();

        $cookie = 'guest-cart-' . uniqid();
        $this->guestBasket($store, $product, $product, $cookie);

        session()->forget('error');

        $response = $this->withCookie('cart', $cookie)
            ->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                /* Everything except `lastname`, which `handleCustomerRegistration()` requires. */
                'billing_info' => [
                    'firstname' => 'Guest',
                    'email' => 'guest-incomplete@example.test',
                    'billing_address' => 'Street',
                    'billing_country' => '101',
                ],
            ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'an incomplete guest billing form answered 500. `handleCustomerRegistration()` returns '
            . 'a RedirectResponse on a validation failure and `if (! $customer)` does not catch it, '
            . 'so `$customer->id` was read on the redirect.');

        $this->assertNotNull(session('error'),
            'the shopper was given no message at all, so they cannot tell what to correct');

        $this->assertSame(0, $this->ordersIn($store),
            'an order was written for a checkout whose billing form was refused');
    }

    /**
     * AND A GUEST WHO REBUILDS THEIR BASKET IS NOT REFUSED — the liveness half.
     *
     * New rows, new ids, a genuine second purchase. This is the property the trait's docblock
     * claims ("a rebuilt cart has NEW `carts.id` values, so it is a different claim") and which
     * was FALSE on the guest branch while the ids came from a loop index: indices restart at 0 for
     * every basket, so a guest buying the same two things again inside the window hashed
     * identically and was refused.
     */
    public function test_a_guest_who_rebuilds_the_same_basket_is_not_refused(): void
    {
        [$store, , $product, $shippingId] = $this->checkout();

        $second = Product::forceCreate([
            'name' => 'p4f026b-' . uniqid(), 'slug' => 'p4f026b-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $cookie = 'guest-cart-' . uniqid();

        $this->guestBasket($store, $product, $second, $cookie);
        $this->guestCheckout($store, $shippingId, $cookie);

        $this->assertSame(1, $this->ordersIn($store), 'premise: the first guest purchase landed');

        /* A genuinely new basket: fresh rows, fresh ids. */
        $this->guestBasket($store, $product, $second, $cookie);

        session()->forget('error');
        $this->guestCheckout($store, $shippingId, $cookie);

        $this->assertSame(2, $this->ordersIn($store),
            'a guest rebuilding their basket and buying again was refused as a duplicate. The '
            . 'claim must distinguish a race from a second purchase, and on this branch it could '
            . 'not while the ids were loop indices: 0,1 the first time and 0,1 the second.');
    }

    /**
     * AND BANK TRANSFER IS THE SAME WRITER, so the gap is not `cod`-shaped.
     *
     * `PaymentService::process()` routes `cod` (:436) and `bank_transfer` (:438) into the SAME
     * `getProductStatus()`. A fix placed in the `cod` arm alone would leave this one open, which is
     * the scope mistake this codebase has made repeatedly (an API-only guard with the web path left
     * open, four times by CLAUDE.md's count).
     *
     * `bank_transfer` additionally requires an uploaded `payment_receipt` image (:2230-2232), which
     * a test cannot supply without touching the filesystem — so this case drives the ROUTING claim
     * rather than a second duplication: it asserts the receipt validator is what refuses, which is
     * only reachable if `bank_transfer` entered the same method.
     */
    public function test_bank_transfer_reaches_the_same_inline_writer(): void
    {
        [$store, $customer, , $shippingId] = $this->checkout();

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId, ['payment_type' => 'bank_transfer']);

        $this->assertSame(0, $this->ordersIn($store),
            'bank_transfer wrote an order with no payment receipt');
        $this->assertSame('The payment receipt field is required.', session('error'),
            'bank_transfer was refused by something other than PaymentService::getProductStatus\'s '
            . 'own receipt validator (:2230), so it did not reach the shared inline writer and a '
            . 'cod-only fix could not be assumed to cover it. Error: ' . json_encode(session('error')));
    }

    // =============================================================================================
    //  3. WHAT A FIX MUST NOT BREAK — green today, and they must stay green
    // =============================================================================================

    /**
     * A SHOPPER BUYING AGAIN IS NOT A REPLAY, even with a byte-identical basket seconds later.
     *
     * A fresh basket has NEW `carts.id` values and is a different claim. A control that refused
     * this would swallow a real sale — the money is taken by whatever payment follows and nothing
     * is recorded — which is a worse defect than the duplication it prevents.
     */
    public function test_a_rebuilt_basket_is_a_real_second_purchase(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->webCheckout($store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise: the first purchase did not land');

        /* The shopper adds the same item again, immediately — a NEW cart row. */
        $this->rebuildCart($store, $customer, $product);

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            'a genuine second purchase of an identical basket was swallowed as a replay. '
            . 'Error: ' . json_encode(session('error')));
        $this->assertSame(self::OPENING_STOCK - 2 * self::LINE_QTY, $this->stockOf($product),
            'the second purchase took no stock, so the shelf and the record now disagree');
    }

    /**
     * A GENUINELY DIFFERENT BASKET IS NOT A DUPLICATE EITHER.
     *
     * Separate from the case above: there the basket was identical and only the row id differed;
     * here the quantity differs too, so a fingerprint that ignored the cart id entirely would still
     * have to let this through.
     */
    public function test_a_genuinely_different_basket_is_not_refused(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->webCheckout($store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        /* Back for three more of the same item. */
        $this->rebuildCart($store, $customer, $product, 3);

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            'a different basket was refused as a duplicate, so the store cannot take a repeat '
            . 'order. Error: ' . json_encode(session('error')));
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY - 3, $this->stockOf($product));
    }

    /**
     * AND ANOTHER SHOPPER'S IDENTICAL BASKET IS NOT A DUPLICATE.
     *
     * A claim keyed on anything the two shoppers share — the store, the basket contents, the posted
     * `customer_id` — collapses them onto one key and lets one customer's checkout block another's.
     * The standing rule in this codebase is that a predicate built from caller input is not a
     * control, and this is its liveness half.
     *
     * LIVENESS ONLY, stated so it is not over-read: the two baskets differ on `carts.id` regardless,
     * so this case cannot by itself distinguish a server-derived key from a caller-supplied one.
     * A property test on the fix's own fingerprint function is what would measure that, and it
     * cannot be written until the function exists.
     */
    public function test_another_shoppers_identical_basket_is_not_refused(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->webCheckout($store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        $second = Customer::forceCreate([
            'first_name' => 'Second', 'last_name' => 'Shopper',
            'email' => 'p4f018-b-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        Cart::forceCreate([
            'customer_id' => $second->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::LINE_QTY, 'price' => 10.00 * self::LINE_QTY, 'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();

        session()->forget('error');
        $this->webCheckout($store, $second, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            "a second shopper's identical basket was refused as a duplicate of the first "
            . "shopper's order, so one customer's checkout is blocking another's. "
            . 'Error: ' . json_encode(session('error')));
        $this->assertSame(self::OPENING_STOCK - 2 * self::LINE_QTY, $this->stockOf($product));
    }

    /**
     * A SHOPPER WHO LEFT A FIELD BLANK MUST BE ABLE TO CORRECT IT AND BUY.
     *
     * This is the defect independent review found in the API half's first attempt, and it needed no
     * attacker: a claim taken as soon as the basket resolved swept every later request-validation
     * refusal inside it, so a shopper who was refused for an empty field was then told their order
     * was already being placed — for an order that does not exist. Their own checkout, dead, by the
     * guard.
     *
     * On this path the refusals that must stay outside any claim include the payment-type check
     * (`PaymentController:1873`), `validateBillingInfo()` (:1878, eight required fields), the
     * foreign-product and non-positive-quantity pre-flights (:1950, :1997) and
     * `prepareFinalRequestData()` (:2020). `billing_address` is used here because it is on
     * `validateBillingInfo()`'s required list.
     */
    public function test_a_refused_checkout_can_be_corrected_and_completed(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId, [], ['billing_address' => '']);

        /* THE PREMISE. If this is not a refusal, the retry below measures nothing. */
        $this->assertNotNull(session('error'),
            'the incomplete checkout was not refused, so the correction below is not measuring a '
            . 'refuse-then-correct path');
        $this->assertSame(0, $this->ordersIn($store), 'the refused post wrote an order');
        $this->assertSame(self::OPENING_STOCK, $this->stockOf($product),
            'the refused post moved stock');

        /* The shopper fills the field in and tries again — same cart, same everything. */
        session()->forget('error');
        $this->webCheckout($store, $customer, $shippingId);

        $this->assertNull(session('error'),
            'the corrected checkout was refused after a post that wrote NOTHING, so a claim is '
            . 'being taken before the request is even valid: ' . json_encode(session('error')));
        $this->assertSame(1, $this->ordersIn($store), 'the corrected checkout wrote no order');
        $this->assertSame(self::OPENING_STOCK - self::LINE_QTY, $this->stockOf($product));
    }

    // ============================================================================== the fixture

    private function ordersIn($store): int
    {
        return (int) DB::table('orders')->where('store_id', $store->id)->count();
    }

    /** Read through the table, NOT the model: `Product` is `Cachable`. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /**
     * THE GUEST BRANCH, AND IT IS THE ONE THAT WAS BROKEN.
     * =============================================================================================
     *
     * Every other case in this file signs a customer in with `actingAs(..., 'customers')`, so none
     * of them ever reached `Cart::cart_list_cookie()` — the branch `processOrder` picks when
     * `auth('customers')->check()` is false. An independent review of the fix found the gap and
     * then found a live defect inside it, which is the honest order of events: the review found it,
     * not this test.
     *
     * `cart_id` IS NOT A ROW ID ON THIS BRANCH. `ApiController::cart_list` writes `$value->id`;
     * `Cart::cart_list_cookie` writes `$key`, the index of a `->get()` collection. So the first
     * version of the claim, which read the cart ids out of the payload, got `[0]` for a one-line
     * guest basket (filtered away, claim always released, no protection at all) and `[1, 2]` for a
     * two-line one — ids belonging to WHOEVER OWNS cart rows 1 and 2, which on a database with any
     * history is nobody, so the claim was kept for 120 seconds whether or not an order was written
     * and the guest's honest retry was refused.
     *
     * TWO LINES, because that is the arm where the wrong ids are non-zero and survive the filter.
     *
     * The guest's identity is the `cart` cookie, which is what `cart_list_cookie` reads and what
     * `Cart::forGuestCookie()` matches on.
     */
    private function guestCheckout($store, int $shippingId, string $cookie, array $extra = [])
    {
        $billing = [
            'firstname' => 'Guest', 'lastname' => 'Shopper', 'email' => 'guest-p4f026@example.test',
            'billing_user_telephone' => '555111333',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        /* `withCookie`, which is what every other guest-storefront suite in this repository uses
           (GatewayStockBeforeVerificationTest, NullCookieCartTest, StorefrontGetMassageBoundaryTest).
           An unencrypted cookie does not survive EncryptCookies, so `Cookie::get('cart')` came back
           null and `cart_list_cookie` answered "Cart is empty." -- measured. */
        return $this->withCookie('cart', $cookie)
            ->post('/' . $store->slug . '/process-order', array_merge([
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing, $extra));
    }

    /** A two-line GUEST basket, keyed on the cart cookie rather than a customer id. */
    private function guestBasket($store, Product $first, Product $second, string $cookie): array
    {
        DB::table('carts')->where('cookie_session_id', $cookie)->delete();

        $ids = [];

        foreach ([$first, $second] as $product) {
            $ids[] = (int) DB::table('carts')->insertGetId([
                'customer_id' => 0,
                'cookie_session_id' => $cookie,
                'product_id' => $product->id,
                'variant_id' => 0,
                'qty' => self::LINE_QTY,
                'price' => 10.00 * self::LINE_QTY,
                'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new Cart)->flushCache();

        return $ids;
    }

    private function cartRowIdOf(Customer $customer): int
    {
        return (int) DB::table('carts')->where('customer_id', $customer->id)->value('id');
    }

    /**
     * PUT THE SAME CART ROW BACK, id and all — the shape of a RACE.
     *
     * Recreating it with a new id would model a rebuilt basket instead, which is a different
     * question and is driven separately above.
     */
    private function restoreSameCartRow($store, Customer $customer, Product $product, int $cartId): void
    {
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        DB::table('carts')->insert([
            'id' => $cartId,
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::LINE_QTY, 'price' => 10.00 * self::LINE_QTY, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (new Cart)->flushCache();
    }

    /** A FRESH basket — new row id — the shape of a shopper buying again. */
    private function rebuildCart($store, Customer $customer, Product $product, int $qty = self::LINE_QTY): void
    {
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10.00 * $qty, 'store_id' => $store->id,
        ]);

        (new Cart)->flushCache();
    }

    /**
     * The storefront WEB checkout — `PaymentController::processOrder`, payment type `cod`.
     *
     * The shape is `ForeignProductCartChainTest::webCheckout()`'s, which already drives this exact
     * route and whose own positive control
     * (`test_an_ordinary_quantity_still_completes_on_every_cod_writer`) records that the `web`
     * surface writes an order end to end in this suite. The billing fields are posted BOTH nested
     * under `billing_info` and flat, because `processBillingInfo()` and `validateBillingInfo()`
     * read them from different places; the geo values are numeric ids because the web path inserts
     * a `cities` row and `'Testland'` is not one.
     */
    private function webCheckout(
        $store,
        Customer $customer,
        int $shippingId,
        array $extra = [],
        array $billingOverrides = []
    ) {
        $billing = array_merge([
            'firstname' => 'P4F018', 'lastname' => 'Shopper', 'email' => 'p4f018@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ], $billingOverrides);

        return $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/process-order', array_merge([
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing, $extra));
    }

    /**
     * A store with stock management ON, a customer, a one-unit cart and a shipping row.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: Product, 3: int}
     */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'p4f018-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture User. */
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'P4F018', 'last_name' => 'Shopper',
            'email' => 'p4f018-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'p4f018-' . uniqid(), 'slug' => 'p4f018-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::LINE_QTY, 'price' => 10.00 * self::LINE_QTY, 'store_id' => $store->id,
        ]);

        return [$store, $customer, $product, $shippingId];
    }
}

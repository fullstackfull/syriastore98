<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F276 — E14's payment half, on the two storefront writers that had no control at all.
 * =================================================================================================
 *
 * The master prompt's Workstream E rule 14 is *"no duplication of payments or inventory
 * movements"*, and ledger row P2-04-17 has carried it as PARTIAL: the stock half is closed by
 * `RefundStockIdempotencyTest`, the payment half is not.
 *
 * `GatewayReplayGuardCensusTest` — which parses the tree rather than grepping it, after the
 * previous census was found to be wrong in every number it asserted — classifies exactly three
 * customer-order writers as `client-api/no-idempotency-control`:
 *
 *     Api\ApiController::place_order              POST api/{slug}/place-order and -guest
 *     Api\ApiController::ordersave                POST api/{slug}/order-save   (no -guest route)
 *     Api\DashboardController::createOrderViaApi  POST api/admin/createOrderViaApi
 *
 * The third was closed by F226. These two were not. A repeated post writes a SECOND order and
 * decrements stock a SECOND time, and the census's own note says so: *"nothing makes a repeated
 * post idempotent."*
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A SECOND POST IS NOT HYPOTHETICAL HERE. These are the storefront API's checkout endpoints.
 * The client is a phone on a mobile network: a request that times out client-side has still
 * arrived, and a retry — automatic or by a shopper tapping Pay again — is the ordinary case, not
 * the attack. `ApiResponser::error()` returning HTTP 200 makes it worse, because a client cannot
 * tell a refusal from a success by status code alone.
 *
 * THE REPLAY IS DRIVEN WITH THE CART PUT BACK, deliberately. A successful order clears the
 * shopper's cart, so a bare second post finds nothing to sell and is stopped by that rather than by
 * any control — which proves nothing about duplication.
 *
 * AND *HOW* IT IS PUT BACK IS THE WHOLE DISTINCTION, which the first version of this file got
 * wrong. The fingerprint carries `carts.id` per line, so:
 *
 *   SAME ROW ID  → the two requests are reading the same cart rows. That is a RACE, and it must
 *                  write one order. `restoreSameCartRow()`.
 *   NEW ROW ID   → the shopper built a fresh basket. That is a SECOND PURCHASE, and refusing it
 *                  would swallow a real sale. `rebuildCart()`.
 *
 * The first version deleted and recreated the row, which gave it a new id — so it modelled a
 * rebuilt basket while claiming to model a race, and it only passed because the fingerprint did
 * not yet carry the cart id. Independent review caught the underlying hole (an identical repeat
 * basket inside the window was answered as a replay: a real purchase lost, nothing recorded) and
 * fixing it exposed the test. Both cases are now driven, on purpose, in both directions.
 * ------------------------------------------------------------------------------------------------
 *
 * THE SAME PRIMITIVE AS F226, F269 AND F270: one atomic `Cache::add()` claim, fingerprinted on the
 * SERVER-RESOLVED store and customer (`ApiController::resolveStoreCustomer()`, the shared predicate
 * CLAUDE.md names) plus a canonical form of the basket. Never on `$request->customer_id`, which is
 * caller input and which this very handler was once exploitable through.
 *
 * WHAT THE SUITE CANNOT SHOW, stated rather than implied: `phpunit.xml` sets `CACHE_STORE=array`,
 * and `ArrayStore` has no `add()`, so `Cache::add()` here is `Repository::add()` — a `get()` then a
 * `put()`. These tests prove the control's LOGIC. That the second of two SIMULTANEOUS callers loses
 * because the DATABASE said so is measured on real MariaDB by
 * `tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php`, which exercises the
 * same primitive against the same `cache` primary key.
 */
final class StorefrontOrderIntakeIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 50;

    protected function setUp(): void
    {
        parent::setUp();

        // Both writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /** @return array<string, array{0: string}> */
    public static function apiOrderWriters(): array
    {
        return [
            'place-order' => ['place-order'],
            'order-save' => ['order-save'],
        ];
    }

    // =============================================================================================
    //  1. THE POSITIVE CONTROL FIRST
    // =============================================================================================

    /**
     * @dataProvider apiOrderWriters
     */
    public function test_an_ordinary_checkout_still_writes_one_order_and_moves_stock(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $response = $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store),
            "`$path` wrote no order on the happy path, so every refusal below proves nothing. "
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "`$path` moved no stock on the happy path");
    }

    // =============================================================================================
    //  2. THE DEFECT
    // =============================================================================================

    /**
     * TWO POSTS OF ONE CHECKOUT MUST WRITE ONE ORDER.
     *
     * The cart is re-seeded before the second post because a completed order clears it — so
     * without the re-seed the second post is stopped by an empty basket rather than by a control,
     * and the case would pass against entirely unguarded code.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_repeated_checkout_post_does_not_write_a_second_order(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $cartId = $this->cartRowIdOf($customer);

        $first = $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store),
            'premise: the first post did not write an order. Body: '
            . substr((string) $first->getContent(), 0, 300));
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product), 'premise: no stock moved');

        /* THE SAME cart row, id included: two requests racing read the same rows. */
        $this->restoreSameCartRow($store, $customer, $product, $cartId);

        $second = $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store),
            "a repeated post to `$path` wrote a SECOND order for one checkout. On a mobile "
            . 'network a timed-out request has still arrived, so the retry is the ordinary case '
            . 'rather than the attack.');
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "a repeated post to `$path` decremented stock a second time for one checkout");

        /*
         * AND THE REPLAY'S BODY HAS TO BE RIGHT, which the first version of this case never
         * looked at — it asserted the order count and the stock figure and threw the response
         * away. Independent review found what that hid: `ordersave`'s replay called the STATIC
         * `Order::order_detail()` with no argument, so it ran `Order::find(0)`, took the
         * not-found arm, and answered HTTP 200 `{status: 1, message: "Order added
         * successfully.", data: {message: "Order not found."}}`. A green test over a response
         * that told the client a lie.
         */
        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertSame(1, (int) $second->json('status'),
            "the replay of `$path` did not answer with the first post's success. Body: "
            . substr((string) $second->getContent(), 0, 300));

        if ($path === 'place-order') {
            $this->assertSame((int) $order->id, (int) $second->json('data.order_id'),
                'the replay returned a different order id than the one that exists');
        } else {
            $this->assertSame((string) $order->product_order_id, (string) $second->json('data.order_id'),
                'the replay did not carry the existing order\'s product_order_id, which is what '
                . "`ordersave`'s own success envelope puts in `order_id`");
            $this->assertNotSame('Order not found.', $second->json('data.message'),
                'the replay answered "Order not found." inside a success envelope — the static '
                . '`order_detail()` is being called without the id again');
        }
    }

    /**
     * AND A SHOPPER BUYING AGAIN IS NOT A REPLAY, even with a byte-identical basket inside the
     * window. This is the half independent review found missing, and it is the same shape as the
     * worst finding against F269: a control that swallows a real sale is worse than the
     * duplication it prevents. The money is taken by whatever payment follows and nothing is
     * recorded.
     *
     * A rebuilt basket has NEW `carts.id` values, so it is a different claim.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_rebuilt_basket_is_a_real_second_purchase(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $first = $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise: the first purchase did not land. Body: '
            . substr((string) $first->getContent(), 0, 300));

        /* The shopper adds the same two units again, immediately — a NEW cart row. */
        $this->rebuildCart($store, $customer, $product);

        $second = $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            "`$path` swallowed a genuine second purchase of an identical basket as a replay. The "
            . 'shopper paid and nothing was recorded.');
        $this->assertSame(self::OPENING_STOCK - 4, $this->stockOf($product),
            'the second purchase took no stock, so the shelf and the record now disagree');
        $this->assertSame(1, (int) $second->json('status'),
            'the second purchase was refused. Body: ' . substr((string) $second->getContent(), 0, 300));
    }

    // =============================================================================================
    //  3. THE CLAIM MUST NOT BLOCK REAL TRADE
    // =============================================================================================

    /**
     * A SHOPPER BUYING AGAIN IS NOT A DUPLICATE. A control that refused this would stop a store
     * selling, which is worse than the defect it fixes.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_genuinely_different_basket_is_not_refused(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        /* The same shopper, back for three more of the same item. */
        $this->rebuildCart($store, $customer, $product, 3);

        $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            "`$path` refused a genuinely different basket as a duplicate, so the store cannot "
            . 'take a repeat order');
        $this->assertSame(self::OPENING_STOCK - 5, $this->stockOf($product));
    }

    /**
     * AND ANOTHER SHOPPER'S IDENTICAL BASKET IS NOT A DUPLICATE EITHER. The fingerprint carries the
     * SERVER-RESOLVED customer, so one shopper cannot evict or collide with another's claim — the
     * standing rule in this codebase being that a predicate built from caller input is not a
     * control. This handler was once exploitable through exactly that input.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_another_shoppers_identical_basket_is_not_refused(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        $second = Customer::forceCreate([
            'first_name' => 'Second', 'last_name' => 'Shopper',
            'email' => 'second-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        Cart::forceCreate([
            'customer_id' => $second->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        /*
         * SHOPPER B POSTS SHOPPER A'S `customer_id` WHILE AUTHENTICATED AS B, and that detail is
         * what gives this case the power to fail.
         *
         * The first version had B post B's own id, so if the fingerprint were re-keyed on
         * `$request->customer_id` every assertion here would still pass — the docblock claimed a
         * server-resolved key and the test could not tell the difference. Independent review
         * caught it. Posting A's id means a caller-input key collapses both shoppers onto ONE
         * claim and this goes red, which is the property CLAUDE.md actually cares about.
         *
         * The handler ignores the posted id (`resolveStoreCustomer()` is the subject), so the
         * order is still B's — that is the point.
         */
        $this->placeOrder($path, $store, $second, $shippingId, $customer->id);

        $this->assertSame(2, $this->ordersIn($store),
            "`$path` refused a second shopper's identical basket as a duplicate of the first "
            . "shopper's order, so one customer's checkout is blocking another's. This is a "
            . 'LIVENESS case only: it cannot detect a caller-keyed fingerprint, because the two '
            . 'baskets differ on cart id regardless. '
            . 'test_the_fingerprint_separates_two_customers_with_one_basket measures that.');
        $this->assertSame(self::OPENING_STOCK - 4, $this->stockOf($product));
    }

    // =============================================================================================
    //  3b. THE FINGERPRINT IS SERVER-DERIVED — measured on the function, not inferred from routing
    // =============================================================================================

    /**
     * TWO ROUTED CASES CANNOT SETTLE THIS, AND THE FIRST TWO ATTEMPTS BOTH FAILED TO.
     *
     * Independent review caught attempt one: shopper B posted B's own id, so a fingerprint re-keyed
     * on `$request->customer_id` would have left every assertion green. Attempt two had B post A's
     * id — and that does not bite either, measured: B's basket carries a different `carts.id`, so
     * the two keys differ on the cart component whatever the customer component does. The cart id
     * added for the rebuilt-basket case defeats the very discrimination the routed test relied on.
     *
     * So the property is measured where it lives. One identical basket, two customers, through the
     * handler's OWN fingerprint — reflected rather than reimplemented, so a change to the hashing
     * moves the test with it instead of leaving it agreeing with a stale copy.
     */
    public function test_the_fingerprint_separates_two_customers_with_one_basket(): void
    {
        $lines = [[
            'cart_id' => 77, 'product_id' => 11, 'variant_id' => 0, 'qty' => 2, 'price' => 20.00,
        ]];

        $this->assertNotSame(
            $this->fingerprintFor($lines, 5, 101, 'cod'),
            $this->fingerprintFor($lines, 5, 102, 'cod'),
            'one basket fingerprints identically for two different customers, so one shopper\'s '
            . 'checkout really could block another\'s'
        );

        $this->assertNotSame(
            $this->fingerprintFor($lines, 5, 101, 'cod'),
            $this->fingerprintFor($lines, 6, 101, 'cod'),
            'one basket fingerprints identically for two different stores'
        );

        /* AND THE SAME SUBJECT WITH THE SAME BASKET IS ONE CLAIM, or the control refuses nothing. */
        $this->assertSame(
            $this->fingerprintFor($lines, 5, 101, 'cod'),
            $this->fingerprintFor($lines, 5, 101, 'cod')
        );
    }

    /**
     * AND THE CALL SITES PASS THE DERIVED CUSTOMER, which is the half the property test above
     * cannot see: a correct fingerprint fed the posted id is exactly as broken as a bad one.
     *
     * This is the shape CLAUDE.md names as the most productive defect in this codebase, and
     * `ordersave`'s own docblock records that its else branch once took `$request->customer_id`,
     * so a merchant's or driver's token placed an order in a shopper's name.
     */
    public function test_neither_call_site_keys_the_claim_on_posted_input(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));

        /* `->` SO THE DECLARATION IS NOT COUNTED AS A CALL. Without it the split finds three
           segments — the method's own signature is the third — and the count assertion fails for
           a reason that has nothing to do with the property. Caught on the first run. */
        $calls = preg_split('/->storefrontIntakeFingerprint\s*\(/', $source);
        array_shift($calls);

        $this->assertCount(2, $calls,
            'there are no longer exactly two claim call sites, so this test is not reading what '
            . 'it thinks it is');

        foreach ($calls as $index => $tail) {
            $args = substr($tail, 0, (int) strpos($tail, ');'));

            $this->assertStringNotContainsString('$request->customer_id', $args,
                'claim call site ' . ($index + 1) . ' keys the fingerprint on the POSTED customer '
                . 'id. The handler resolves its subject with resolveStoreCustomer(); a claim on '
                . 'caller input lets one principal sit on another\'s checkout.');
            $this->assertStringNotContainsString('$request->store_id', $args,
                'claim call site ' . ($index + 1) . ' keys the fingerprint on a posted store id');
            $this->assertMatchesRegularExpression('/\$customer_?[iI]d/', $args,
                'claim call site ' . ($index + 1) . ' no longer passes the derived customer id');
        }
    }

    /** The handler's own fingerprint, not a copy of it. */
    private function fingerprintFor(array $lines, int $storeId, int $customerId, $paymentType): string
    {
        $method = new \ReflectionMethod(
            \App\Http\Controllers\Api\ApiController::class,
            'storefrontIntakeFingerprint'
        );
        $method->setAccessible(true);

        return $method->invoke(
            app(\App\Http\Controllers\Api\ApiController::class),
            $lines,
            $storeId,
            $customerId,
            $paymentType
        );
    }

    // =============================================================================================
    //  4. THE REFUSAL MUST NOT OUTLIVE WHAT IT REFUSED
    // =============================================================================================

    /**
     * A SHOPPER WHO LEFT A FIELD BLANK MUST BE ABLE TO CORRECT IT AND BUY.
     *
     * This is the defect independent review found in the first version of this change, and it
     * needed no attacker. The claim was taken as soon as the basket resolved, which swept all
     * fifteen of `ordersave`'s remaining request-validation refusals inside it — fourteen billing
     * and delivery field checks plus the request validator. None of them changes the fingerprint.
     * So: post with `billing_city` empty, get refused, fix the field, resubmit within two minutes,
     * and be told "This order is already being placed" for an order that does not exist. Their own
     * checkout, dead, by my guard.
     *
     * The claim now sits immediately before the stock loop, after every request refusal, which is
     * where `PosController::store()` puts it.
     */
    public function test_a_refused_checkout_can_be_corrected_and_completed(): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $incomplete = $this->placeOrder('order-save', $store, $customer, $shippingId, null, ['billing_city' => '']);

        /* THE PREMISE. If this is not a refusal, the retry below proves nothing. */
        $this->assertSame(0, (int) $incomplete->json('status'),
            'the incomplete checkout was not refused, so the correction below is not measuring a '
            . 'refuse-then-correct path. Body: ' . substr((string) $incomplete->getContent(), 0, 300));
        $this->assertSame(0, $this->ordersIn($store), 'the refused post wrote an order');
        $this->assertSame(self::OPENING_STOCK, $this->stockOf($product), 'the refused post moved stock');

        /* The shopper fills in the missing field and tries again, same cart, same everything. */
        $corrected = $this->placeOrder('order-save', $store, $customer, $shippingId);

        $this->assertSame(1, (int) $corrected->json('status'),
            'the corrected checkout was refused as a duplicate of a post that wrote NOTHING, so '
            . 'the claim is being taken before the request is even valid. Body: '
            . substr((string) $corrected->getContent(), 0, 300));
        $this->assertSame(1, $this->ordersIn($store), 'the corrected checkout wrote no order');
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product));
    }

    /**
     * A CHECKOUT THAT DIED BEFORE THE ORDER EXISTED MUST NOT LEAVE THE SHOPPER LOCKED OUT.
     *
     * Independent review asked for this release and I added it — and then a mutation showed the
     * suite could not see it: disabling the release changed nothing, because nothing drove a
     * throw between the claim and `$order->save()`. An untested control on a money path is the
     * shape this project keeps finding, so it is driven here rather than trusted.
     *
     * `Order::creating` is the injection point the sibling compensation test already uses, and it
     * fires after the stock loop and before the row exists — exactly the window where the claim
     * is held and nothing is recorded.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_checkout_that_died_before_the_order_existed_can_be_retried(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $cartId = $this->cartRowIdOf($customer);

        \App\Models\Order::creating(static function (): void {
            throw new \RuntimeException('simulated failure between the claim and the order write');
        });

        try {
            $this->placeOrder($path, $store, $customer, $shippingId);
        } catch (\Throwable $e) {
            // Laravel's handler may or may not convert it; either way no order was written.
        }

        $this->assertSame(0, $this->ordersIn($store),
            'premise: the injected failure did not stop the order being written, so the retry '
            . 'below is not measuring a released claim');

        /* The listener is the only thing that was wrong; the shopper retries the same checkout. */
        \App\Models\Order::flushEventListeners();
        \App\Models\Order::boot();
        $this->restoreSameCartRow($store, $customer, $product, $cartId);

        $retry = $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, (int) $retry->json('status'),
            "the retry of a `$path` checkout that recorded NOTHING was refused as a duplicate, so "
            . 'the claim is being held on a path that wrote no order and the shopper is locked '
            . 'out until the TTL lapses. Body: ' . substr((string) $retry->getContent(), 0, 300));
        $this->assertSame(1, $this->ordersIn($store), 'the retry wrote no order');
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
     * PUT THE SAME CART ROW BACK, id and all — the shape of a RACE.
     *
     * Two requests in flight together read the same `carts` rows, so they fingerprint identically
     * and must resolve to one order. Recreating the row with a NEW id would model a rebuilt
     * basket instead, which is a different question and is driven separately below.
     */
    private function restoreSameCartRow($store, Customer $customer, Product $product, int $cartId, int $qty = 2): void
    {
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        DB::table('carts')->insert([
            'id' => $cartId,
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10.00 * $qty, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new Cart)->flushCache();
    }

    /** A FRESH basket — new row id — the shape of a shopper buying again. */
    private function rebuildCart($store, Customer $customer, Product $product, int $qty = 2): void
    {
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10.00 * $qty, 'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();
    }

    private function cartRowIdOf(Customer $customer): int
    {
        return (int) DB::table('carts')->where('customer_id', $customer->id)->value('id');
    }

    /**
     * @param  int|null  $postAsCustomerId  the `customer_id` to POST, when it must differ from the
     *                                      authenticated one (the handler ignores it; the point is
     *                                      that the FINGERPRINT must ignore it too)
     * @param  array  $billingOverrides     fields to replace in `billing_info`
     */
    private function placeOrder(
        string $path,
        $store,
        Customer $customer,
        int $shippingId,
        ?int $postAsCustomerId = null,
        array $billingOverrides = []
    ) {
        $billing = array_merge([
            'firstname' => 'F276', 'lastname' => 'Shopper', 'email' => 'f276@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 'Testland', 'billing_state' => 'Teststate',
            'billing_city' => 'Testcity', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
            'delivery_city' => 'Testcity',
        ], $billingOverrides);

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f276')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, [
            'customer_id' => $postAsCustomerId ?? $customer->id,
            'billing_info' => $billing,
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ]);
    }


    /**
     * A store with stock management ON, a customer, a two-unit cart and a shipping row.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: Product, 3: int}
     */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f276-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
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
            'first_name' => 'F276', 'last_name' => 'Shopper',
            'email' => 'f276-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'f276-' . uniqid(), 'slug' => 'f276-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        return [$store, $customer, $product, $shippingId];
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;


/**
 * F226 — `POST api/admin/createOrderViaApi` IS THE SHIPPED FLUTTER APP'S ORDER WRITER AND IT HAS
 * NO IDEMPOTENCY CONTROL.
 * =============================================================================================
 *
 * Of the fifteen customer-order write sites the parsed census found, this is the one a shipped
 * client actually calls, and the one with **no accidental dedupe**. The two storefront writers
 * consume the cart, so a sequential replay finds an empty cart and refuses itself; seven gateway
 * returns go through `claimGatewayReturn()`. This one takes a payload and writes.
 *
 * SO A DOUBLE SUBMIT WRITES TWO ORDERS AND TAKES THE STOCK TWICE. A merchant double-tapping
 * "create order" in the admin app, or an app retrying a request whose response was lost on a bad
 * connection, bills the customer twice and decrements the same product twice. The retry case is
 * the one that matters: the client cannot tell a lost response from a failed write, so retrying is
 * the correct thing for it to do — and the server must make it safe.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE KEY IS A CONTENT HASH AND NOT A CALLER-SUPPLIED TOKEN
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The shipped app sends no idempotency key, so a control that requires one protects nobody who is
 * at risk today. The key is therefore derived from the authenticated principal, the server-resolved
 * store, and a canonical form of the validated payload — never from caller input alone, which this
 * project's standing rule forbids and which would let one merchant evict another's claim.
 *
 * The cost is stated rather than hidden: **two genuinely distinct but byte-identical orders inside
 * the window are treated as one.** Same customer, same products, same quantities, same prices,
 * same address, same note, within sixty seconds. That is a double submit in essentially every real
 * case, and for the case where it is not, an `Idempotency-Key` header is honoured and folded into
 * the key, so a client that wants two can ask for two. That escape hatch is tested below.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE D-21 FLUTTER CONDITION APPLIES WITH FULL FORCE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * > لا تغير رموز HTTP أو استجابات API بصورة تكسر Flutter، وخصوصاً لا تستخدم 401 لرفض تجاري عادي.
 *
 * A replay must therefore answer with the **first order's own success response**, not an error:
 * that is what makes a retry safe rather than merely refused. `test_a_replay_returns_the_first_
 * orders_response_byte_for_byte` asserts the two payloads are identical, which also catches the
 * trap in rebuilding one from the persisted row — `orders.product_price` reads back as a string
 * where the original response carried a float, and a client decoding into a typed model would
 * break on that long before anyone noticed.
 */
class AdminApiOrderIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:\App\Models\Product,1:int} */
    private function productIn($store, int $productStock, int $variantStock): array
    {
        $product = Product::forceCreate([
            'name' => 'idem-' . uniqid(), 'slug' => 'idem-' . uniqid(),
            'product_stock' => $productStock, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'idem-' . uniqid(),
            'price' => 10, 'stock' => $variantStock, 'store_id' => $store->id,
            'variation_option' => 'manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, (int) $variantId];
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Idem', 'last_name' => 'Shopper',
            'email' => 'idem-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function token($merchant, $store): string
    {
        /* The same grant note as AdminApiStockRefusalContractTest: a fixture merchant holds no
           Laratrust role, and without it this would refuse 403 for a reason orthogonal to the
           subject. A real merchant's global `admin` role carries `Manage Order`. */
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Manage Order');

        return \App\Models\User::find($merchant->id)->createToken('auth_token')->plainTextToken;
    }

    private function payload(Customer $customer, int $productId, $variantId, int $qty = 1, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'products' => [array_filter([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $qty,
                'price' => 10.00,
            ], fn ($v) => $v !== null)],
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'first_name' => 'Idem', 'last_name' => 'Buyer',
            'email' => 'buyer@example.test', 'telephone' => '555000111',
            'address' => 'Street 1',
        ], $extra);
    }

    private function submit(string $token, $store, array $payload, array $headers = [])
    {
        return $this->withHeaders(array_merge(['Authorization' => 'Bearer ' . $token], $headers))
            ->postJson('/api/admin/createOrderViaApi?store_id=' . $store->id, $payload);
    }

    /**
     * THE DEFECT. Two identical submissions write one order, not two, and take the stock once.
     */
    public function test_an_identical_resubmission_writes_one_order_and_takes_the_stock_once(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 10);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $first = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 3));
        $second = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 3));

        $this->assertSame(201, $first->getStatusCode(), 'premise broken: the first order did not succeed');

        $this->assertSame(
            1,
            Customer::withoutGlobalScopes()->find($customer->id) ? DB::table('orders')->where('customer_id', $customer->id)->count() : -1,
            'a double submit wrote TWO orders. The merchant has billed their customer twice for '
            . 'one basket, and the retry an app performs after a lost response is indistinguishable '
            . 'from this.'
        );

        $this->assertSame(
            7,
            (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'the variant stock was decremented TWICE for one order (10 - 3 - 3 = 4). Stock is the '
            . 'half of this that cannot be refunded by issuing a credit note.'
        );

        /*
         * AND THE PRODUCT ROW IS UNTOUCHED, which corrects my own first expectation of this test.
         * I asserted 47 (50 - 3) and measured 50: for a line carrying a `variant_id` the handler
         * decrements ONLY `product_variants.stock`, and `products.product_stock` is reached solely
         * by the `else` branch for lines without one. The assertion was wrong, not the code — but
         * it is worth keeping in the corrected form, because "a variant line does not also take
         * the product's own stock" is a real property and nothing else states it.
         */
        $this->assertSame(
            50,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'a variant line moved `products.product_stock` as well, so stock is taken twice for '
            . 'one line by a different mechanism than the replay this test is about'
        );
    }

    /**
     * THE SAME DEFECT ON THE NON-VARIANT PATH, which the case above cannot reach.
     *
     * `products.product_stock` is decremented only in the `else` branch, so a basket of plain
     * products goes through different code and needs its own case. Without this, the control would
     * be proved on one of the two stock paths and assumed on the other.
     */
    public function test_an_identical_resubmission_of_a_plain_product_takes_its_stock_once(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $plain = Product::forceCreate([
            'name' => 'plain-' . uniqid(), 'slug' => 'plain-' . uniqid(),
            'product_stock' => 20, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $first = $this->submit($token, $store, $this->payload($customer, $plain->id, null, 4));
        $second = $this->submit($token, $store, $this->payload($customer, $plain->id, null, 4));

        $this->assertSame(201, $first->getStatusCode(), 'premise broken: the first order did not succeed');
        $this->assertSame(201, $second->getStatusCode());

        $this->assertSame(1, DB::table('orders')->where('customer_id', $customer->id)->count(),
            'a double submit of a plain product wrote two orders');
        $this->assertSame(16, (int) DB::table('products')->where('id', $plain->id)->value('product_stock'),
            '20 - 4 = 16; the product stock was taken twice for one submission');
    }

    /**
     * A REPLAY ARRIVING WHILE THE FIRST WRITE IS STILL IN FLIGHT IS TOLD TO WAIT.
     *
     * The in-flight window cannot be produced through HTTP in a single-threaded test — the first
     * request has always finished before the second starts — so the sentinel is seeded directly.
     * That is the state a genuine concurrent double tap lands in, and the answer must be neither a
     * second order nor a success the client will believe.
     */
    public function test_a_concurrent_duplicate_is_refused_without_writing_anything(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 10);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $payload = $this->payload($customer, $product->id, $variantId, 2);

        /* Take the claim as the first request would, and leave it as `writing`. The fingerprint is
           rebuilt here through the production method so the key cannot drift from the handler's. */
        $fingerprint = (new \ReflectionMethod(\App\Http\Controllers\Api\DashboardController::class, 'orderIntakeFingerprint'));
        $fingerprint->setAccessible(true);
        $validated = $payload;
        $key = $fingerprint->invoke(
            new \App\Http\Controllers\Api\DashboardController(),
            $validated,
            (int) $store->id,
            (int) $merchant->id,
            \Illuminate\Http\Request::create('/', 'POST')
        );

        Cache::add($key, 'writing', now()->addSeconds(120));

        $response = $this->submit($token, $store, $payload);

        $this->assertSame(409, $response->getStatusCode(),
            'a concurrent duplicate was not refused. If the fingerprint here differs from the '
            . 'handler\'s, this test is measuring nothing — check the seeded key first.');
        $this->assertNotSame(401, $response->getStatusCode(),
            'A CONCURRENT DUPLICATE ANSWERED 401, which forces a logout in the shipped app.');
        $this->assertSame('error', $response->json('status'));
        $this->assertIsString($response->json('message'));

        $this->assertSame(0, DB::table('orders')->where('customer_id', $customer->id)->count(),
            'the refused duplicate wrote an order anyway');
        $this->assertSame(10, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'the refused duplicate moved stock anyway');
    }

    /**
     * AND THE REPLAY IS ANSWERED WITH THE FIRST ORDER, BYTE FOR BYTE — not with an error.
     *
     * This is the D-21 condition. A client that retries because it never saw the response must end
     * up believing exactly what it would have believed had the response arrived; anything else
     * turns a successful order into an apparent failure and provokes a third attempt.
     */
    public function test_a_replay_returns_the_first_orders_response_byte_for_byte(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 10);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $first = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 2));
        $second = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 2));

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode(), 'the replay did not answer 201');
        $this->assertNotSame(401, $second->getStatusCode(),
            'A REPLAY ANSWERED 401. lib/network/network.dart maps any 401 to a forced logout that '
            . 'clears stored preferences, so a retried order would sign the merchant out.');

        $this->assertSame(
            $first->json(),
            $second->json(),
            'the replay\'s payload differs from the first response. Rebuilding it from the '
            . 'persisted row is where this breaks: `orders.product_price` reads back as a string '
            . 'where the original carried a float, and a typed client model would fail to decode.'
        );
    }

    /**
     * A DIFFERENT PAYLOAD IS A DIFFERENT ORDER. The control must not swallow real second orders.
     */
    public function test_a_different_quantity_is_a_second_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 20);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 2));
        $second = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 5));

        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(2, DB::table('orders')->where('customer_id', $customer->id)->count(),
            'a genuinely different order was swallowed as a duplicate');
        $this->assertSame(13, (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            '20 - 2 - 5 = 13; both orders must take their own stock');
    }

    /**
     * AND AN EXPLICIT HEADER BUYS A SECOND IDENTICAL ORDER — the escape hatch, tested.
     */
    public function test_a_distinct_idempotency_key_allows_a_second_identical_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 20);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 2), ['Idempotency-Key' => 'first-basket']);
        $second = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 2), ['Idempotency-Key' => 'second-basket']);

        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame(2, DB::table('orders')->where('customer_id', $customer->id)->count(),
            'the explicit escape hatch did not work, so a merchant who really wants two identical '
            . 'orders inside the window cannot have them');
    }

    /**
     * ONE MERCHANT CANNOT CONSUME ANOTHER'S CLAIM.
     *
     * The key folds in the authenticated principal and the server-resolved store. If it were built
     * from the payload alone, a merchant who guessed another store's product and customer ids could
     * pre-claim their key and make a legitimate order vanish into a replay response naming an order
     * the victim cannot see — a denial of service with cross-tenant reach. Both halves are tested:
     * a second merchant's identical payload is a separate order, and the first merchant's own
     * replay still works afterwards.
     */
    public function test_the_claim_is_scoped_to_the_principal_and_the_store(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        [$productA, $variantA] = $this->productIn($storeA, 50, 10);
        [$productB, $variantB] = $this->productIn($storeB, 50, 10);
        $customerA = $this->customerIn($storeA);
        $customerB = $this->customerIn($storeB);

        $tokenA = $this->token($merchantA, $storeA);
        $tokenB = $this->token($merchantB, $storeB);

        $a = $this->submit($tokenA, $storeA, $this->payload($customerA, $productA->id, $variantA, 2));
        $b = $this->submit($tokenB, $storeB, $this->payload($customerB, $productB->id, $variantB, 2));

        $this->assertSame(201, $a->getStatusCode());
        $this->assertSame(201, $b->getStatusCode(), 'merchant B was refused because merchant A had ordered');

        $this->assertNotSame(
            $a->json('data.order_id'),
            $b->json('data.order_id'),
            'two different merchants in two different stores were handed the SAME order'
        );

        $this->assertSame(1, DB::table('orders')->where('customer_id', $customerB->id)->count());
    }

    /**
     * A FAILED WRITE MUST NOT BLOCK THE RETRY IT INVITES.
     *
     * A refused order — here a stock shortfall — rolls back and writes nothing. If the claim
     * survived that, the merchant's corrected second attempt would be answered as a replay of an
     * order that does not exist. This is the `Cache::add`-released-on-failure half of the Paymera
     * docblock's reasoning (`PaymentController.php:672`), and the direction that matters most:
     * a claim outliving a failed write turns a recoverable refusal into a dead end.
     */
    public function test_a_refused_order_releases_its_claim_so_the_retry_is_not_swallowed(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [$product, $variantId] = $this->productIn($store, 50, 2);
        $customer = $this->customerIn($store);
        $token = $this->token($merchant, $store);

        $refused = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 5));

        $this->assertSame(400, $refused->getStatusCode(), 'premise broken: the shortfall was not refused');
        $this->assertSame(0, DB::table('orders')->where('customer_id', $customer->id)->count());

        /* The identical request again. It must be refused on its own merits, not reported as a
           replay of something that was never written. */
        $again = $this->submit($token, $store, $this->payload($customer, $product->id, $variantId, 5));

        $this->assertSame(400, $again->getStatusCode(),
            'the retry of a REFUSED order was answered as a duplicate. The claim outlived a write '
            . 'that never happened, so the merchant can never retry this basket.');
        $this->assertStringContainsString('Insufficient stock', (string) $again->json('message'));
    }

    /**
     * AND AN UNAUTHORISED CALLER CANNOT BURN A KEY.
     *
     * The claim is taken after the tenant and permission gates, so a caller who is refused 403
     * leaves nothing behind. Were it taken first, anyone holding any token could pre-claim a
     * merchant's payload shape and have their real order answered as a replay.
     */
    public function test_a_refused_principal_leaves_no_claim_behind(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        [$product, $variantId] = $this->productIn($storeA, 50, 10);
        $customer = $this->customerIn($storeA);

        /* B holds a valid token but does not own store A. */
        $intruder = $this->token($merchantB, $storeA);
        $refused = $this->submit($intruder, $storeA, $this->payload($customer, $product->id, $variantId, 2));

        $this->assertSame(403, $refused->getStatusCode(), 'premise broken: the intruder was not refused 403');

        $owner = $this->token($merchantA, $storeA);
        $legitimate = $this->submit($owner, $storeA, $this->payload($customer, $product->id, $variantId, 2));

        $this->assertSame(201, $legitimate->getStatusCode(),
            'a refused intruder left a claim behind, so the store owner\'s own order was swallowed');
        $this->assertSame(1, DB::table('orders')->where('customer_id', $customer->id)->count());
    }

    /**
     * THE PREMISE OF EVERY CASE ABOVE: the cache store really is shared across these requests.
     *
     * `phpunit.xml` sets `CACHE_STORE=array`, which lives for the whole test process — so it does
     * persist between two `postJson()` calls in one test. If it did not, every assertion here
     * would pass for the wrong reason the moment the control was removed, because nothing would
     * ever see a second request as a replay.
     */
    public function test_premise_the_cache_persists_between_two_requests_in_one_test(): void
    {
        Cache::add('f226-premise', 'written', now()->addMinute());

        $this->assertFalse(
            Cache::add('f226-premise', 'again', now()->addMinute()),
            'Cache::add did not refuse a second write, so no claim in this suite can ever be '
            . 'observed as taken and every idempotency assertion here is vacuous.'
        );
    }
}

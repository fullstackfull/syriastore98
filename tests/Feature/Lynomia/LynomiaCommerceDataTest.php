<?php

namespace Tests\Feature\Lynomia;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\CommerceCredential;

/**
 * P3B. The customers, orders and carts the commerce API serves.
 *
 * ISOLATION IS THE POINT, and it is asserted positively and negatively on every resource: store
 * A's credential reads store A's data, and the same request with store B's credential reads
 * nothing of A's -- not a 403 that confirms the row exists, but an empty answer or a 404 that is
 * indistinguishable from "no such thing".
 *
 * WHY EVERY CASE SEEDS TWO STORES. A single-tenant fixture cannot fail a tenancy test: a query
 * with no store predicate at all passes it. Every case here seeds the same shape in both stores,
 * so a missing predicate shows up as the other tenant's row appearing in the answer.
 */
class LynomiaCommerceDataTest extends TestCase
{
    use InteractsWithLynomia;
    use RefreshDatabase;

    private const BASE = '/api/lynomia/commerce/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLynomiaHarness();
    }

    /** @return array{0: int, 1: string} the store id and a credential for it */
    private function connected(int $accountId): array
    {
        [$owner, $store] = $this->entitledTenant();
        $this->fakeLynomia(['account_id' => $accountId]);
        $link = app(AccountProvisioner::class)->provision($store, $owner)->fresh();
        $link->forceFill(['commerce_credential_version' => 1])->save();

        return [(int) $store->id, CommerceCredential::issue($link->fresh())];
    }

    private function fetch(string $token, string $path): TestResponse
    {
        return $this->getJson(self::BASE . $path, ['Authorization' => 'Bearer ' . $token]);
    }

    private function lookup(string $token, array $body): TestResponse
    {
        return $this->postJson(self::BASE . '/customers/lookup', $body, ['Authorization' => 'Bearer ' . $token]);
    }

    private function customer(int $storeId, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'first_name' => 'Omar',
            'last_name' => 'Haddad',
            'email' => 'omar@example.com',
            'mobile' => '0955111222',
            'password' => bcrypt('secret'),
            'type' => 'customer',
            'store_id' => $storeId,
        ], $attributes));
    }

    private function product(int $storeId): Product
    {
        $product = new Product();
        // created_by is NOT NULL and not fillable, so the fixture writes it directly rather than
        // through mass assignment.
        $product->forceFill(['name' => 'Oud', 'track_stock' => 0, 'created_by' => 1, 'store_id' => $storeId])->save();

        return $product;
    }

    private function order(int $storeId, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'product_order_id' => '020260101120000',
            'order_date' => '2026-01-01 12:00:00',
            'customer_id' => 0,
            'is_guest' => 0,
            'product_json' => json_encode([['name' => 'Oud', 'qty' => 2, 'price' => '750.25']]),
            'final_price' => 1500.5,
            'delivery_price' => 0,
            'payment_status' => 'success',
            'delivered_status' => 6,
            'currency_code' => 'SYP',
            'store_id' => $storeId,
        ], $attributes));
    }

    // ---------------------------------------------------------------- customers

    public function test_a_customer_is_found_by_email_in_their_own_store_only(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);
        $this->customer($storeA, ['first_name' => 'Ours']);
        $this->customer($storeB, ['first_name' => 'Theirs']);

        $mine = $this->lookup($tokenA, ['email' => 'omar@example.com']);
        $mine->assertOk();
        $mine->assertJsonCount(1, 'data');
        $mine->assertJsonPath('data.0.name', 'Ours Haddad');

        // The same identifier, the other credential: the other store's own row and nothing of A's.
        $this->lookup($tokenB, ['email' => 'omar@example.com'])->assertJsonPath('data.0.name', 'Theirs Haddad');
    }

    // Stores keep "+963 955 111 222", "0955111222" and "00963955111222" for the same person. The
    // adapter sends the national significant number and verifies the full E.164 afterwards, so
    // the match here is a suffix of the stored digits however the merchant typed them.
    public function test_a_phone_matches_whatever_separators_the_store_stored(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $this->customer($storeA, ['email' => 'a@example.com', 'mobile' => '+963 955-111 222']);

        $found = $this->lookup($tokenA, ['phone' => '955111222']);

        $found->assertOk();
        $found->assertJsonCount(1, 'data');
        $found->assertJsonPath('data.0.phones.0', '+963 955-111 222');
        // The store names the region its local numbers are written in, because it is the only
        // side that knows. Lynomia refuses to guess one and would otherwise match nobody.
        $found->assertJsonPath('data.0.phone_region', 'SY');
    }

    public function test_a_phone_search_term_too_short_to_identify_anybody_matches_nobody(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $this->customer($storeA, ['mobile' => '0955111222']);

        $this->lookup($tokenA, ['phone' => '222'])->assertJsonCount(0, 'data');
    }

    // THE ENDPOINT THAT DOES NOT EXIST. A name search is how one customer is shown another's
    // orders, so there is no way to ask for one: a lookup must carry exactly one identifier.
    public function test_a_lookup_needs_exactly_one_verified_identifier(): void
    {
        [, $token] = $this->connected(7001);

        $this->lookup($token, [])->assertStatus(422);
        $this->lookup($token, ['name' => 'Omar'])->assertStatus(422);
        $this->lookup($token, ['email' => 'a@example.com', 'phone' => '955111222'])->assertStatus(422);
    }

    public function test_a_guest_is_found_through_their_order_and_never_across_stores(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);

        foreach ([[$storeA, 'Ours'], [$storeB, 'Theirs']] as [$storeId, $name]) {
            $order = $this->order($storeId, ['is_guest' => 1]);
            OrderBillingDetail::create([
                'order_id' => $order->id, 'product_order_id' => $order->product_order_id,
                'first_name' => $name, 'last_name' => 'Guest',
                'email' => 'guest@example.com', 'telephone' => '0955999888',
            ]);
        }

        $found = $this->lookup($tokenA, ['email' => 'guest@example.com']);
        $found->assertJsonCount(1, 'data');
        $found->assertJsonPath('data.0.name', 'Ours Guest');
        $found->assertJsonPath('data.0.guest', true);

        $this->lookup($tokenB, ['email' => 'guest@example.com'])->assertJsonPath('data.0.name', 'Theirs Guest');
    }

    // A guest reference is an ORDER id, never the shopper's email: the reference travels back in
    // a URL path, and a customer's email address does not belong in this application's access
    // logs. It still resolves to every order that guest placed in this store.
    public function test_a_guest_reference_carries_no_identifier_and_still_finds_their_orders(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);

        foreach ([[$storeA, 'A-1'], [$storeA, 'A-2'], [$storeB, 'B-1']] as [$storeId, $number]) {
            $order = $this->order($storeId, ['is_guest' => 1, 'product_order_id' => $number]);
            OrderBillingDetail::create([
                'order_id' => $order->id, 'product_order_id' => $number,
                'first_name' => 'Omar', 'last_name' => 'Guest',
                'email' => 'guest@example.com', 'telephone' => '0955999888',
            ]);
        }

        $reference = $this->lookup($tokenA, ['email' => 'guest@example.com'])->json('data.0.id');

        $this->assertStringStartsWith('guest:', $reference);
        $this->assertStringNotContainsString('guest@example.com', $reference);
        $this->assertStringNotContainsString('0955999888', $reference);

        $orders = $this->fetch($tokenA, '/customers/' . rawurlencode($reference) . '/orders');
        $orders->assertOk();
        $orders->assertJsonCount(2, 'data');
        $this->assertSame(['A-2', 'A-1'], array_column($orders->json('data'), 'number'));

        // The same reference under the other store's credential resolves to nothing: the order
        // it names is not theirs to read.
        $this->fetch($tokenB, '/customers/' . rawurlencode($reference) . '/orders')->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- orders

    public function test_a_customers_orders_are_their_own_stores_orders(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);
        $mine = $this->customer($storeA);
        $this->order($storeA, ['customer_id' => $mine->id, 'product_order_id' => 'A-1']);
        // Same customer id in the other store: only the store predicate keeps these apart.
        $this->order($storeB, ['customer_id' => $mine->id, 'product_order_id' => 'B-1']);

        $ours = $this->fetch($tokenA, "/customers/{$mine->id}/orders");
        $ours->assertOk();
        $ours->assertJsonCount(1, 'data');
        $ours->assertJsonPath('data.0.number', 'A-1');

        $this->fetch($tokenB, "/customers/{$mine->id}/orders")->assertJsonPath('data.0.number', 'B-1');
    }

    public function test_an_order_carries_the_stores_own_vocabulary_and_canonical_payment_status(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $order = $this->order($storeA, ['delivered_status' => 5, 'payment_status' => 'Succeeded']);

        $response = $this->fetch($tokenA, "/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('delivered_status', 5);
        // 'Picked Up', not 'Shipped': the parcel has not left the store, and the adapter needs the
        // store's own word because both canonicalise to `processing`.
        $response->assertJsonPath('status_label', 'Picked Up');
        // Store Syria canonicalises payment status here, so the adapter never has to know that
        // 'Succeeded' and 'paid' are the same thing. 'succeeded' is the spelling ten of this
        // application's payment gateways write and the one its financial normaliser misses.
        $response->assertJsonPath('payment_status', 'paid');
        // Money is a decimal STRING end to end: the column is a PHP float and a round trip
        // through a JSON number is how rounding gets reintroduced.
        $response->assertJsonPath('total', '1500.50');
        $response->assertJsonPath('items.0.name', 'Oud');
        $response->assertJsonPath('item_count', 1);
    }

    // delivered_status is a plain integer with no enum or check constraint, so a value outside
    // the ladder is reachable. It is reported unlabelled rather than mislabelled as Pending.
    public function test_an_unknown_delivered_status_is_reported_without_inventing_a_label(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $order = $this->order($storeA, ['delivered_status' => 99]);

        $response = $this->fetch($tokenA, "/orders/{$order->id}");

        $response->assertJsonPath('delivered_status', 99);
        $response->assertJsonPath('status_label', null);
    }

    public function test_another_stores_order_is_not_found_rather_than_forbidden(): void
    {
        [$storeA, ] = $this->connected(7001);
        [, $tokenB] = $this->connected(7002);
        $order = $this->order($storeA);

        // A 403 would confirm the order exists. There is nothing here to probe for.
        $this->fetch($tokenB, "/orders/{$order->id}")->assertStatus(404);
    }

    public function test_order_search_is_by_the_number_the_store_printed_not_the_primary_key(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);
        $this->order($storeA, ['product_order_id' => 'SHARED-1', 'currency_code' => 'SYP']);
        $this->order($storeB, ['product_order_id' => 'SHARED-1', 'currency_code' => 'USD']);

        $ours = $this->fetch($tokenA, '/orders?number=SHARED-1');
        $ours->assertJsonCount(1, 'data');
        $ours->assertJsonPath('data.0.currency', 'SYP');

        $this->fetch($tokenB, '/orders?number=SHARED-1')->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_a_malformed_order_id_is_refused_at_the_boundary(): void
    {
        [, $token] = $this->connected(7001);

        $this->fetch($token, '/orders/not-a-number')->assertStatus(422);
        $this->fetch($token, '/orders/1%20OR%201=1')->assertStatus(422);
        $this->fetch($token, '/orders?number=')->assertStatus(422);
    }

    // ---------------------------------------------------------------- carts

    public function test_abandoned_carts_are_grouped_into_baskets_within_one_store(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        [$storeB, $tokenB] = $this->connected(7002);
        $shopper = $this->customer($storeA);

        $oud = $this->product($storeA);
        Cart::create(['customer_id' => $shopper->id, 'product_id' => $oud->id, 'qty' => 2, 'price' => 300, 'store_id' => $storeA]);
        Cart::create(['customer_id' => $shopper->id, 'product_id' => $oud->id, 'qty' => 1, 'price' => 100, 'store_id' => $storeA]);
        Cart::create(['customer_id' => $shopper->id, 'product_id' => $oud->id, 'qty' => 5, 'price' => 999, 'store_id' => $storeB]);

        $carts = $this->fetch($tokenA, '/carts/abandoned');

        $carts->assertOk();
        // Store Syria keeps one row per line, so two rows are one basket, and the other store's
        // row is not in it.
        $carts->assertJsonCount(1, 'data');
        $carts->assertJsonPath('data.0.total', '700.00');
        $carts->assertJsonPath('data.0.customer_id', (string) $shopper->id);

        $this->fetch($tokenB, '/carts/abandoned')->assertJsonPath('data.0.total', '4995.00');
    }

    public function test_a_cart_never_carries_a_recovery_link(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $shopper = $this->customer($storeA);
        $product = $this->product($storeA);
        Cart::create(['customer_id' => $shopper->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 100, 'store_id' => $storeA]);

        $body = $this->fetch($tokenA, '/carts/abandoned')->json('data.0');

        // Store Syria has no durable cart token, so there is no link that restores a basket; a
        // fabricated checkout URL would be a bearer credential for someone else's cart.
        $this->assertArrayNotHasKey('recovery_url', $body);
        $this->assertArrayNotHasKey('checkout_url', $body);
    }

    // ---------------------------------------------------------------- the surface as a whole

    public function test_every_commerce_route_is_anonymous_proof(): void
    {
        [$storeA, ] = $this->connected(7001);
        $order = $this->order($storeA);

        $paths = ['/store', "/customers/1/orders", "/orders/{$order->id}", '/orders?number=X', '/carts/abandoned'];

        foreach ($paths as $path) {
            $this->getJson(self::BASE . $path)->assertStatus(401);
        }

        $this->postJson(self::BASE . '/customers/lookup', ['email' => 'a@example.com'])->assertStatus(401);
    }

    // The contract deliberately exposes no addresses and no payment instrument data: an agent
    // needs to know what the customer ordered and where it is, not the customer's street.
    public function test_no_address_or_payment_instrument_data_ever_leaves_this_surface(): void
    {
        [$storeA, $tokenA] = $this->connected(7001);
        $order = $this->order($storeA, ['is_guest' => 1, 'payment_type' => 'bank_transfer']);
        OrderBillingDetail::create([
            'order_id' => $order->id, 'product_order_id' => $order->product_order_id,
            'first_name' => 'Omar', 'last_name' => 'Guest', 'email' => 'guest@example.com',
            'telephone' => '0955999888', 'address' => '12 Baghdad Street', 'city' => 'Damascus',
            'postcode' => '00963', 'delivery_address' => '12 Baghdad Street',
        ]);

        $body = $this->fetch($tokenA, "/orders/{$order->id}")->getContent();

        foreach (['Baghdad Street', 'Damascus', '00963', 'bank_transfer'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "the order payload disclosed {$leak}");
        }
    }
}

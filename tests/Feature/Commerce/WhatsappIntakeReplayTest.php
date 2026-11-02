<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F227 — WHAT THE `ss_order_intake` LOCK ACTUALLY PROTECTS ON `PaymentController::whatsapp`.
 * =========================================================================================
 *
 * This is a CHARACTERIZATION, written because the two accounts of this control in the repository
 * contradict each other and neither had been measured.
 *
 * The handoff's F227 row says the mitigation "has a hole": *"if the principal resolves empty, no
 * lock is taken at all and execution continues"*, the guest key *"is built from that
 * caller-supplied cookie, which by the project's own standing rule is not a check"*, and *"it is a
 * 30-second concurrency lock, not an idempotency control, so a later sequential replay is
 * unaffected."* The control's own docblock (`PaymentController.php:4440`) answers all three in
 * prose: a caller with no identity *"has no basket for the cart read to find"*, and the sequential
 * case *"was already handled incidentally — the cart rows are gone."*
 *
 * Prose against prose settles nothing, and both documents are this project's own. Everything below
 * is an assertion about what the code does, so whichever account is wrong is corrected by a run
 * rather than by argument.
 *
 * THE QUESTION THAT DECIDES IT is whether the lock key and the cart identity are the SAME thing in
 * each branch — because if they can ever disagree, one basket is readable under two different keys
 * and the lock is decoration. `whatsapp()` branches on `auth('customers')->user()`:
 *
 *   * guest     → `Cart::cart_list_cookie()` reads `Cookie::get('cart')`;
 *                 lock key is `guest:<that same cookie>`.
 *   * customer  → `ApiController::cart_list()` reads the customer's own rows;
 *                 lock key is `customer:<that same id>`.
 *
 * So the key is not "caller input standing in for a check" — it IS the subject, the same value the
 * cart read uses to decide what the basket contains. A caller who changes it changes which basket
 * they are checking out, which is why rotating it buys nothing. That is the claim under test, and
 * `test_a_rotated_cookie_cannot_reach_the_first_basket` is the one that would break first if it
 * were false.
 *
 * WHAT THIS DOES NOT CLAIM. The concurrent race cannot be produced by a single-threaded test — the
 * first request always finishes before the second begins — so the lock's contested state is
 * reached by seeding it, which is the honest substitute and is labelled as one. And none of this
 * speaks to the throttle F95 left as the owner's decision, nor to a DURABLE idempotency record:
 * the sequential refusal below comes from the cart being consumed, so it is a consequence of the
 * handler's shape rather than a control anybody chose, and it would stop protecting the path the
 * moment an order could be placed without emptying a cart.
 */
class WhatsappIntakeReplayTest extends SecurityTestCase
{
    use RefreshDatabase;

    private static function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    private static function stockOf(Product $product): int
    {
        /* `withoutGlobalScopes()`, per CLAUDE.md: a plain `find()` answers against whichever
           tenant happens to be resolved, which is how an "it did not change" assertion comes to
           pass whether or not the code under test behaved. */
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    /**
     * THE TENANT, WITH THE SETTINGS THE STOCK PATH ACTUALLY NEEDS.
     *
     * My first version of this file used `makeTenant()` alone, and every stock assertion in it
     * read 50 where it expected 49 — not because the replays were writing, but because
     * `stock_management` was never seeded, so the handler managed no stock at all. The order
     * counts were right and the stock figures were measuring an unconfigured store: a fixture
     * that quietly removes the mechanism under test is the inverse of the trap CLAUDE.md records,
     * and it reads as a passing test the moment the expectation is adjusted to match.
     *
     * Copied from `UnguardedOrderWriterTrustBoundaryTest::tenant()`, which drives the same handler.
     */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f227-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture `User` (CLAUDE.md). */
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
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
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant, $store];
    }

    private function productIn($store, float $price = 100.00, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'f227-' . uniqid(), 'slug' => 'f227-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestCart($store, Product $product, int $qty = 1, float $price = 100.00): string
    {
        $sid = 'f227-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => $price, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'F227', 'last_name' => 'Shopper',
            'email' => 'f227-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function state($store, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'whatsapp',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $overrides);
    }

    private function intake($store, ?string $cookie, array $state)
    {
        $request = $this->withSession(['request_data' => $state]);

        if ($cookie !== null) {
            $request = $request->withCookie('cart', $cookie);
        }

        return $request->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);
    }

    /**
     * THE POSITIVE CONTROL FIRST. Without it every refusal below is satisfied by a handler that
     * refuses everything, which is the failure mode this project has measured most often.
     */
    public function test_premise_a_legitimate_guest_intake_still_writes_one_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $cookie = $this->guestCart($store, $product);

        $response = $this->intake($store, $cookie, $this->state($store));

        $this->assertSame(1, self::orderCount(),
            'the legitimate intake wrote no order (HTTP ' . $response->getStatusCode() . '), so '
            . 'every assertion in this file would pass against a handler that does nothing. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(49, self::stockOf($product));
    }

    /**
     * CRITICISM 1, MEASURED: a caller with NO identity takes no lock — and writes no order.
     *
     * The handoff reads "no lock is taken and execution continues" as the hole. Execution does
     * continue, and it continues into the guest branch, whose cart read is
     * `Cart::cart_list_cookie()` → `Cookie::get('cart')`. With no cookie there is no basket, so
     * the handler refuses before any write. The lock is not what protects this; the absence of a
     * basket is. Which means skipping the lock here is not a gap — taking it on an empty string
     * would be worse, because every identity-less caller in the system would then share one key.
     */
    public function test_a_caller_with_no_identity_writes_nothing(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $this->guestCart($store, $product);          // a basket exists, under a cookie this caller lacks

        $response = $this->intake($store, null, $this->state($store));

        $this->assertSame(0, self::orderCount(),
            'a caller with neither a cart cookie nor an authenticated customer wrote an order. '
            . 'The lock is skipped on this path by design, so if a write is reachable here the '
            . 'route has no replay control at all.');
        $this->assertStringContainsString('Cart is empty', (string) $response->getContent());
        $this->assertSame(50, self::stockOf($product));
    }

    /**
     * CRITICISM 2, MEASURED: the "caller-supplied" cookie cannot reach another basket.
     *
     * This is the one that would expose the control as decoration if it failed. The guest lock key
     * is the cart cookie, which the caller does indeed control — but it is also the value the cart
     * read uses to decide what the basket is. Rotating it to dodge the lock therefore rotates the
     * basket too, and the first basket becomes unreachable rather than orderable a second time.
     */
    public function test_a_rotated_cookie_cannot_reach_the_first_basket(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $cookie = $this->guestCart($store, $product);

        $this->intake($store, $cookie, $this->state($store));
        $this->assertSame(1, self::orderCount(), 'premise broken: the first intake did not write');

        /* A different cookie: a different lock key, and a different basket. */
        $response = $this->intake($store, 'f227-rotated-' . uniqid(), $this->state($store));

        $this->assertSame(1, self::orderCount(),
            'rotating the cart cookie produced a SECOND order from one basket, so the lock key '
            . 'and the cart identity disagree and the control is decoration.');
        $this->assertStringContainsString('Cart is empty', (string) $response->getContent());
        $this->assertSame(49, self::stockOf($product),
            'the stock was taken twice for one basket');
    }

    /**
     * CRITICISM 3, MEASURED, GUEST BRANCH: a sequential replay writes nothing more.
     *
     * True that the lock is a 30-second mutex and expires. What refuses the replay is not the lock
     * but the cart: the rows are deleted by the first intake, so the second finds nothing. Recorded
     * as a *consequence of the handler's shape* rather than as a control, because it protects this
     * path only for as long as placing an order requires emptying a cart.
     */
    public function test_a_sequential_guest_replay_writes_no_second_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $cookie = $this->guestCart($store, $product);

        $this->intake($store, $cookie, $this->state($store));
        $this->assertSame(1, self::orderCount(), 'premise broken: the first intake did not write');

        /* Release the lock exactly as its 30-second TTL would, so this measures the replay and not
           the mutex -- the whole point of the criticism being tested. */
        Cache::flush();

        $response = $this->intake($store, $cookie, $this->state($store));

        $this->assertSame(1, self::orderCount(),
            'a sequential replay AFTER the lock expired wrote a second order from one basket. '
            . 'The incidental cart-consumption refusal the control relies on does not hold.');
        $this->assertStringContainsString('Cart is empty', (string) $response->getContent());
        $this->assertSame(49, self::stockOf($product));
    }

    /**
     * AND THE SAME ON THE AUTHENTICATED BRANCH, which is different code.
     *
     * `auth('customers')->user()` sends the handler through `ApiController::cart_list()` instead of
     * `cart_list_cookie()`, so the guest cases above say nothing about it. Both branches need their
     * own measurement; assuming one from the other is how F227's original guard claim came to be
     * false in the first place.
     */
    public function test_a_sequential_customer_replay_writes_no_second_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $customer = $this->customerIn($store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $state = $this->state($store, ['customer_id' => $customer->id]);

        $first = $this->actingAs($customer, 'customers')->intake($store, null, $state);

        $this->assertSame(1, self::orderCount(),
            'premise broken: the authenticated intake wrote no order (HTTP '
            . $first->getStatusCode() . '), so the replay assertion below is vacuous. Body: '
            . substr((string) $first->getContent(), 0, 300));

        Cache::flush();

        $this->actingAs($customer, 'customers')->intake($store, null, $state);

        $this->assertSame(1, self::orderCount(),
            'an authenticated customer replayed their own intake into a second order');
        $this->assertSame(49, self::stockOf($product));
    }

    /**
     * THE LOCK ITSELF IS REACHABLE, for both principal shapes.
     *
     * Seeded rather than raced, and said so plainly: a single-threaded test cannot hold two
     * requests in flight at once. What it can establish is that the contested state exists and is
     * answered — if the key the handler builds differed from the key seeded here, this would pass
     * for the wrong reason, so the assertion is on the REFUSAL message rather than merely on the
     * absence of an order (which an empty cart would also produce).
     *
     * @dataProvider principal
     */
    public function test_a_held_intake_lock_refuses_the_second_caller(string $shape): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        if ($shape === 'guest') {
            $cookie = $this->guestCart($store, $product);
            $key = 'ss_order_intake:' . (int) $store->id . ':guest:' . $cookie;
            $driver = fn () => $this->intake($store, $cookie, $this->state($store));
        } else {
            $customer = $this->customerIn($store);
            Cart::forceCreate([
                'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            ]);
            $key = 'ss_order_intake:' . (int) $store->id . ':customer:' . $customer->id;
            $driver = fn () => $this->actingAs($customer, 'customers')
                ->intake($store, null, $this->state($store, ['customer_id' => $customer->id]));
        }

        $held = Cache::lock($key, 30);
        $this->assertTrue($held->get(), 'premise broken: the seeded lock could not be taken');

        $response = $driver();

        $this->assertSame(0, self::orderCount(), 'the second caller wrote an order while the first held the lock');
        $this->assertStringContainsString(
            'already being processed',
            (string) $response->getContent(),
            'the refusal did not come from the intake lock. If the message is "Cart is empty" '
            . 'instead, the key this test seeds differs from the one the handler builds and the '
            . 'whole case is measuring the wrong thing.'
        );
        $this->assertSame(50, self::stockOf($product));
    }

    public static function principal(): array
    {
        return ['guest' => ['guest'], 'authenticated customer' => ['customer']];
    }
}

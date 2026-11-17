<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the six storefront READ handlers that took their subject from `customer_id` in the
 * request body: address_list, wishlist_list, user_detail, order_list, return_order_list and
 * loyality_reward.
 *
 * TWO SHAPES, ONE DEFECT.
 *
 *   Shape A -- `$customer = Customer::find($request->customer_id)` and then read with that id.
 *   `Customer::find()` is a liveness check on an id the CALLER chose, not an identity check, so
 *   any token holder read any shopper's rows. (address_list, wishlist_list, user_detail)
 *
 *   Shape B -- the guard-class trap:
 *
 *       if (! auth('customers')->user()) { $customer_id = $request->customer_id; }
 *       else                             { $customer_id = auth('customers')->user()->id; }
 *
 *   which reads as "fall back for guests" and is not. `custom.auth` logs a token's owner into the
 *   guard matching its tokenable class, so a MERCHANT's or a DRIVER's token satisfies the
 *   middleware with `auth('customers')` null and takes the attacker-controlled branch. A customer
 *   token takes the safe branch — which is why a customer-token probe proves nothing here, and
 *   why every test below drives all three principal classes. (order_list, return_order_list)
 *
 * And loyality_reward had neither shape: a bare `$request->user_id`, no principal, no validation
 * and no tenant predicate, summing an arbitrary customer's reward points across every store.
 *
 * WHY THE TENANT SCOPE IS NOT THE CONTROL. StoreScope reads the `storeSlug` route parameter;
 * these routes use `{slug}` and carry no ActiveTheme middleware, so no tenant resolves and
 * `null_tenant => 'open'` means the scope adds no predicate. DeliveryAddress, Wishlist and Order
 * are not in `scoped_models` at all. The handler is the only control.
 */
class StorefrontCustomerReadAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'read-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ], $attributes));
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'read-product-' . uniqid(),
            'slug' => 'read-product-' . uniqid(),
            'product_stock' => 1,
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function callApi($store, string $path, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /** A marked address, wishlist row and order for a victim, so a leak is identifiable. */
    private function belongingsFor($store, Customer $customer): array
    {
        $address = DeliveryAddress::forceCreate([
            'customer_id' => $customer->id,
            'full_name' => 'VICTIM-SECRET-NAME',
            'title' => 'main',
            'address' => 'VICTIM-SECRET-STREET',
            'postcode' => 90210,
            'default_address' => 1,
            'store_id' => $store->id,
        ]);

        $wishlist = Wishlist::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $this->productIn($store)->id,
            'variant_id' => 0,
            'store_id' => $store->id,
        ]);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'reward_points' => 777,
            'delivered_status' => 3,
            'product_order_id' => 'VICTIM-ORDER-REF',
        ]);

        return [$address, $wishlist, $order];
    }

    // ===================================================================================
    // SHAPE A — Customer::find($request->customer_id)
    // ===================================================================================

    public static function shapeAEndpoints(): array
    {
        return [
            'address_list'  => ['address-list'],
            'wishlist_list' => ['wishlist-list'],
            'user_detail'   => ['user-detail'],
        ];
    }

    #[DataProvider('shapeAEndpoints')]
    public function test_a_merchant_token_cannot_read_a_shoppers_rows(string $path): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1');
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-SECRET', $response->getContent(),
            "A merchant's token read a shopper's rows through $path.");
        $this->assertSame(0, $response->json('status'),
            "$path answered a merchant's token with success.");
    }

    #[DataProvider('shapeAEndpoints')]
    public function test_a_driver_token_cannot_read_a_shoppers_rows(string $path): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a2');
        $driver = $this->driverFor($merchant, $store);
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $driver->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-SECRET', $response->getContent(),
            "A driver's token read a shopper's rows through $path.");
        $this->assertSame(0, $response->json('status'),
            "$path answered a driver's token with success.");
    }

    #[DataProvider('shapeAEndpoints')]
    public function test_one_shopper_cannot_read_another_by_naming_their_id(string $path): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a3-victim');
        $attacker = $this->customerIn($store, 'a3-attacker');
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-SECRET', $response->getContent(),
            "One shopper read another's rows through $path by naming their id.");
    }

    #[DataProvider('shapeAEndpoints')]
    public function test_a_shopper_of_another_store_is_refused(string $path): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'a4-victim');
        $attacker = $this->customerIn($storeB, 'a4-attacker');
        $this->belongingsFor($storeA, $victim);

        $response = $this->callApi($storeA, $path, ['customer_id' => $victim->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-SECRET', $response->getContent(),
            "A shopper of store B read store A's data through $path.");
        $this->assertSame(0, $response->json('status'),
            "$path answered a cross-store caller with success.");
    }

    // ===================================================================================
    // SHAPE B — the guard-class trap. A customer-token probe takes the SAFE branch, so these
    // drive the merchant and the driver specifically.
    // ===================================================================================

    public static function shapeBEndpoints(): array
    {
        return [
            'order_list'        => ['order-list'],
            'return_order_list' => ['return-order-list'],
        ];
    }

    #[DataProvider('shapeBEndpoints')]
    public function test_a_merchant_token_cannot_read_a_shoppers_order_history(string $path): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'b1');
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-ORDER-REF', $response->getContent(),
            "A merchant's token read a shopper's order history through $path -- this is the "
            . 'guard-class trap: auth(\'customers\') is null for a merchant, so the handler took '
            . 'its $request->customer_id branch.');
        $this->assertSame(0, $response->json('status'));
    }

    #[DataProvider('shapeBEndpoints')]
    public function test_a_driver_token_cannot_read_a_shoppers_order_history(string $path): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'b2');
        $driver = $this->driverFor($merchant, $store);
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $driver->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-ORDER-REF', $response->getContent(),
            "A driver's token read a shopper's order history through $path.");
        $this->assertSame(0, $response->json('status'));
    }

    #[DataProvider('shapeBEndpoints')]
    public function test_a_shopper_naming_another_id_still_gets_only_their_own(string $path): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'b3-victim');
        $attacker = $this->customerIn($store, 'b3-attacker');
        $this->belongingsFor($store, $victim);
        $this->makeOrder($store, [
            'customer_id' => $attacker->id, 'is_guest' => 0,
            'delivered_status' => 3, 'product_order_id' => 'ATTACKER-OWN-REF',
        ]);

        $response = $this->callApi($store, $path, ['customer_id' => $victim->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIM-ORDER-REF', $response->getContent(),
            "A shopper read another's order history through $path by naming their id.");
        $this->assertStringContainsString('ATTACKER-OWN-REF', $response->getContent(),
            "$path stopped returning the caller's OWN orders, which is the journey it exists for.");
    }

    // ===================================================================================
    // loyality_reward — neither shape: a bare $request->user_id
    // ===================================================================================

    public function test_a_merchant_token_cannot_read_a_shoppers_reward_points(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c1');
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, 'loyality-reward', ['user_id' => $victim->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertNotSame('777.00', $response->json('data.point'),
            "A merchant's token read a shopper's reward balance.");
        $this->assertSame(0, $response->json('status'));
    }

    public function test_one_shopper_cannot_read_anothers_reward_points(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c2-victim');
        $attacker = $this->customerIn($store, 'c2-attacker');
        $this->belongingsFor($store, $victim);

        $response = $this->callApi($store, 'loyality-reward', ['user_id' => $victim->id],
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame('0.00', $response->json('data.point'),
            "One shopper read another's reward balance by naming their id.");
    }

    /**
     * The handler summed across every store on the platform. A shopper with the same account id in
     * two stores saw both balances added together, which is a cross-tenant read of their own data
     * and still a tenant-isolation failure.
     */
    public function test_reward_points_are_scoped_to_the_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $customer = $this->customerIn($storeA, 'c3');
        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'is_guest' => 0, 'reward_points' => 10]);
        $this->makeOrder($storeB, ['customer_id' => $customer->id, 'is_guest' => 0, 'reward_points' => 90]);

        $response = $this->callApi($storeA, 'loyality-reward', ['user_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame('10.00', $response->json('data.point'),
            "Store A's endpoint included reward points earned in store B.");
    }

    // ===================================================================================
    // loyality_program_json -- P1-SEC-63. The SIBLING of loyality_reward, in the same
    // handler family, fixed when that one was and missed.
    //
    // Its else limb has NO 401 and `$store` comes from the slug with no check that the
    // caller belongs to it, so the documented `custom.auth` trap applies: a merchant's or
    // a driver's token passes the middleware while auth('customers') stays null, takes the
    // ELSE branch, and is handed that store's customer_id = 0 orders with no select(), i.e.
    // all 40 columns, paginated. A CUSTOMER TOKEN CANNOT REACH THAT BRANCH AT ALL, so a
    // customer-token probe proves nothing here -- hence a merchant AND a driver below.
    // ===================================================================================

    public function test_a_merchant_token_cannot_read_another_stores_guest_orders(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->makeOrder($storeB, ['product_order_id' => 'STORE-B-GUEST-REF']);

        $response = $this->callApi($storeB, 'loyality-program-json', [],
            $merchantA->createToken('t')->plainTextToken);

        // THE REFUSAL ITSELF, NOT JUST THE ABSENCE OF THE MARKER. Asserting only
        // assertStringNotContainsString passed on a 500 too: with the guard deleted, `$customer`
        // is null, `$customer->id` raises ErrorException, and an error page contains no marker
        // either. Both mutations survived until these two lines were added.
        $response->assertStatus(401);
        $this->assertSame(0, $response->json('status'));
        $this->assertStringNotContainsString('STORE-B-GUEST-REF', $response->getContent(),
            "A merchant of another tenant read store B's guest orders through loyality-program-json.");
    }

    public function test_a_driver_token_cannot_read_another_stores_guest_orders(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $driver = $this->driverFor($merchantA, $storeA);

        $this->makeOrder($storeB, ['product_order_id' => 'STORE-B-GUEST-REF-2']);

        $response = $this->callApi($storeB, 'loyality-program-json', [],
            $driver->createToken('t')->plainTextToken);

        $response->assertStatus(401);          // see the note on the merchant case above
        $this->assertSame(0, $response->json('status'));
        $this->assertStringNotContainsString('STORE-B-GUEST-REF-2', $response->getContent(),
            "A delivery driver read another store's guest orders through loyality-program-json.");
    }

    public function test_a_shopper_still_reads_their_own_loyalty_history(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'lp1');
        $this->makeOrder($store, [
            'customer_id' => $customer->id, 'is_guest' => 0,
            'product_order_id' => 'MY-OWN-LOYALTY-REF', 'reward_points' => 12,
        ]);

        $response = $this->callApi($store, 'loyality-program-json', [],
            $customer->createToken('t')->plainTextToken);

        // The new 401 must not have caught the journey it exists to protect.
        $response->assertStatus(200);
        $this->assertStringContainsString('MY-OWN-LOYALTY-REF', $response->getContent(),
            'loyality-program-json stopped returning the shopper their own history.');
    }

    public function test_a_shopper_of_another_store_is_refused_by_the_loyalty_endpoint(): void
    {
        // The old `auth('customers')->user()` admitted ANY customer and then scoped the history by
        // the slug's store, so this returned an empty-but-successful answer. resolveStoreCustomer()
        // refuses instead, which is what the sibling handler does.
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $shopperOfA = $this->customerIn($storeA, 'lp3');
        $this->makeOrder($storeB, ['product_order_id' => 'STORE-B-GUEST-REF-3']);

        $response = $this->callApi($storeB, 'loyality-program-json', [],
            $shopperOfA->createToken('t')->plainTextToken);

        $response->assertStatus(401);
        $this->assertStringNotContainsString('STORE-B-GUEST-REF-3', $response->getContent(),
            "A shopper of store A was served store B's loyalty endpoint.");
    }

    /**
     * TRIPWIRE, NOT AN APPROVAL -- P1-SEC-63b. This pins a DEFECT on purpose.
     *
     * The `point` figure selects `CAST(SUM(reward_points) AS DECIMAL(10,2))` with **no `AS`
     * alias**, so the returned column is named after the whole expression, `$Order->reward_points`
     * is NULL, and `number_format(null, 2)` renders `0.00` however much the shopper earned.
     * Measured on MariaDB 10.11 and against the real App\Models\Order: true sum 40.00, figure
     * 0.00.
     *
     * THE TWO DEFECTS MASKED EACH OTHER, and the isolation half is now closed. That query also
     * carried **no `store_id` predicate** -- `Order` is not in config/tenancy.php's
     * `scoped_models`, so nothing supplied one -- and the missing alias was the only reason the
     * unscoped result leaked nothing. The predicate has been added (it changes no figure, since
     * the result is still discarded), so fixing the alias can no longer switch on a cross-store
     * read. The second assertion below is therefore belt-and-braces, kept deliberately.
     *
     * SO IF YOU ARE HERE BECAUSE THIS TEST WENT RED: you have just made the figure real. Settle
     * what it is CALLED before you ship it -- SUM(orders.reward_points) is lifetime points EARNED
     * and is never decremented, while the spendable balance is customers.total_club_point, which
     * the vendored module REPLACES on redemption. The two diverge permanently after any
     * redemption, so an accurate figure under the bare label "points" would be a new lie rather
     * than a fixed one. See PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md, and P1-SEC-63b.
     */
    public function test_the_loyalty_point_total_is_still_the_known_broken_zero(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $customer = $this->customerIn($storeA, 'lp2');

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'is_guest' => 0, 'reward_points' => 40]);
        $this->makeOrder($storeB, ['customer_id' => $customer->id, 'is_guest' => 0, 'reward_points' => 900]);

        $response = $this->callApi($storeA, 'loyality-program-json', [],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame('0.00', $response->json('data.point'),
            'P1-SEC-63b: the point figure changed. Read this test\'s docblock before touching it.');
        $this->assertNotSame('940.00', $response->json('data.point'),
            'The unscoped sum went live: store B\'s 900 points are in store A\'s figure.');
    }

    // ===================================================================================
    // AND THE LEGITIMATE JOURNEYS STILL WORK
    // ===================================================================================

    public function test_a_shopper_still_reads_their_own_addresses(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd1');
        $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'address-list', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertStringContainsString('VICTIM-SECRET-STREET', $response->getContent(),
            'A shopper can no longer read their own addresses.');
    }

    public function test_a_shopper_still_reads_their_own_wishlist(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd2');
        [, $wishlist] = $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'wishlist-list', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertSame((int) $wishlist->id, (int) $response->json('data.data.0.id'),
            'A shopper can no longer read their own wishlist.');
    }

    public function test_a_shopper_still_reads_their_own_profile(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd3');
        $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'user-detail', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertSame($customer->email, $response->json('data.email'),
            'A shopper can no longer read their own profile.');
        $this->assertSame('VICTIM-SECRET-STREET', $response->json('data.address'),
            'The default address stopped being returned, so the profile screen loses it.');
    }

    public function test_a_shopper_still_reads_their_own_order_history(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd4');
        $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'order-list', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertStringContainsString('VICTIM-ORDER-REF', $response->getContent(),
            'A shopper can no longer read their own order history.');
    }

    public function test_a_shopper_still_reads_their_own_returns(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd5');
        $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'return-order-list', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertStringContainsString('VICTIM-ORDER-REF', $response->getContent(),
            'A shopper can no longer read their own returnable orders.');
    }

    public function test_a_shopper_still_reads_their_own_reward_points(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd6');
        $this->belongingsFor($store, $customer);

        $response = $this->callApi($store, 'loyality-reward', ['user_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertSame('777.00', $response->json('data.point'),
            'A shopper can no longer read their own reward balance.');
    }

    /**
     * The posted id must be INERT, not absent from the contract.
     *
     * The three Shape-A handlers validate `customer_id` as required, and that rule is left in
     * place deliberately: dropping it would change the request contract, and a client that keeps
     * sending the field has to go on working either way. So the field is still required and still
     * validated -- it is simply never read. A tampered value must therefore return the CALLER's
     * own data with success, not a rejection, or a client holding a stale id breaks.
     *
     * The two Shape-B handlers validated it only inside the branch this fix deleted, so there it
     * is now genuinely optional. That is a relaxation, which cannot break a caller, and it is
     * asserted here rather than left to be rediscovered.
     */
    public function test_the_posted_id_is_ignored_but_still_accepted(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'e1');
        $other = $this->customerIn($store, 'e1-other');
        $this->belongingsFor($store, $customer);
        $this->belongingsFor($store, $other);
        $token = $customer->createToken('t')->plainTextToken;

        $ownId = (int) $customer->id;

        foreach (['address-list', 'wishlist-list', 'user-detail', 'order-list', 'return-order-list'] as $path) {
            $withOwn = $this->callApi($store, $path, ['customer_id' => $ownId], $token);
            $withOther = $this->callApi($store, $path, ['customer_id' => $other->id], $token);

            $this->assertSame(1, $withOwn->json('status'), "$path failed for its own caller.");
            $this->assertSame(1, $withOther->json('status'),
                "$path rejected a tampered customer_id instead of ignoring it, which would break "
                . 'a client that sends a stale id.');
            // Timestamps are normalised out: the same instant renders at a different UTC offset
            // between two requests in one test, because resolving the store re-applies the
            // store's timezone. That is a rendering artefact, not evidence the id was read.
            $normalise = static fn (string $body): string => (string) preg_replace(
                '/\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d+)?Z?/', '<ts>', $body
            );

            $this->assertSame(
                $normalise($withOwn->getContent()),
                $normalise($withOther->getContent()),
                "$path returned something DIFFERENT for a tampered customer_id, so the posted id "
                . 'is still being read somewhere.'
            );
        }
    }

    /** `customer_id` is still a required field on the three handlers that always required it. */
    public function test_the_required_customer_id_rule_is_unchanged(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'e2');
        $token = $customer->createToken('t')->plainTextToken;

        foreach (['address-list', 'wishlist-list', 'user-detail'] as $path) {
            $this->assertSame(0, $this->callApi($store, $path, [], $token)->json('status'),
                "$path stopped requiring customer_id; that is a contract change this fix did not "
                . 'intend to make.');
        }

        // And on the two whose only validation lived in the deleted branch, it is now optional.
        foreach (['order-list', 'return-order-list'] as $path) {
            $this->assertSame(1, $this->callApi($store, $path, [], $token)->json('status'),
                "$path should no longer need customer_id at all.");
        }
    }
}

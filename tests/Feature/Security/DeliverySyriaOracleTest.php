<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-10 -- THE DELIVERYSYRIA ORDER-STATUS ENDPOINTS WERE A PLATFORM-WIDE ORDER-EXISTENCE ORACLE.
 * ===================================================================================================
 *
 * `POST api/delivery-syria/orders/update-status` looked the order up BEFORE it compared the credential and answered 404 "Order not found." for an
 * id that exists nowhere and 403 "Invalid credentials." for one that exists in SOME tenant. Any caller with the (public) X-Platform header and any
 * non-empty bearer token could therefore enumerate `product_order_id`s -- a bare timestamp, 86,400 guesses per store-day -- behind only the
 * api group's 120/min (see the throttle test below). A VALID secret of store B gave the same oracle over store A's ids.
 * The original measurement transcript was lost (a truncated agent result), so the pair is re-established here as the RED of this file.
 *
 * The sibling `api/delivery-syria/status` (zid_orders, ZID-<id>) has the same ordering: lookup -> 404 -> credential check -> 403.
 *
 * THE FIX resolves the CREDENTIAL FIRST: the bearer token is matched (constant time) against the stored secrets, and only an authenticated
 * caller gets as far as an order lookup -- which is restricted to the stores that caller's secret owns, so a valid secret cannot probe another
 * tenant's ids either. Everything an unauthenticated caller can observe is then one answer.
 *
 * ORACLE: status + body of the pair {existing id, unknown id} for the same wrong token (must be byte-identical), and the same pair for a
 * valid secret of ANOTHER store. Positive controls: a valid secret still updates its own order; the throttle is on the route.
 */
class DeliverySyriaOracleTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ORDERS = '/api/delivery-syria/orders/update-status';
    private const ZID = '/api/delivery-syria/status';
    private const HEADERS = ['X-Platform' => 'deliverysyria'];

    private function secretFor($merchant, string $secret): void
    {
        DB::table('users')->where('id', $merchant->id)->update(['deliverysyria_secret' => $secret]);
        (new \App\Models\User())->flushCache();
    }

    private function hit(string $uri, array $body, string $token)
    {
        return $this->postJson($uri, $body, array_merge(self::HEADERS, ['Authorization' => 'Bearer ' . $token]));
    }

    private function signature($response): array
    {
        return [$response->status(), $response->getContent()];
    }

    public function test_a_wrong_token_cannot_tell_an_existing_order_from_an_unknown_one(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $order = $this->makeOrder($store);

        $exists = $this->hit(self::ORDERS, ['product_order_id' => $order->product_order_id, 'status' => 1], 'WRONG-TOKEN');
        $absent = $this->hit(self::ORDERS, ['product_order_id' => 'NO-SUCH-ORDER-1', 'status' => 1], 'WRONG-TOKEN');

        $this->assertSame(403, $exists->status(), 'Premise: a wrong token is refused.');
        $this->assertSame($this->signature($exists), $this->signature($absent), 'An existing and an unknown order id are distinguishable with a wrong token.');
    }

    public function test_a_valid_secret_of_another_store_gets_no_oracle_over_this_stores_ids(): void
    {
        [$attackerMerchant] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();
        $this->secretFor($attackerMerchant, 'ATTACKER-SECRET-FIXTURE');
        $victimOrder = $this->makeOrder($victimStore);

        $foreign = $this->hit(self::ORDERS, ['product_order_id' => $victimOrder->product_order_id, 'status' => 1], 'ATTACKER-SECRET-FIXTURE');
        $absent = $this->hit(self::ORDERS, ['product_order_id' => 'NO-SUCH-ORDER-2', 'status' => 1], 'ATTACKER-SECRET-FIXTURE');

        $this->assertSame($this->signature($foreign), $this->signature($absent), 'A valid secret of store B can tell store A\'s order ids from unknown ones.');
        $this->assertSame(0, (int) DB::table('orders')->where('id', $victimOrder->id)->value('delivered_status'), 'and must not move the order');
    }

    public function test_a_valid_secret_still_updates_its_own_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $order = $this->makeOrder($store);

        $response = $this->hit(self::ORDERS, ['product_order_id' => $order->product_order_id, 'status' => 1], 'REAL-SECRET-FIXTURE');

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'));
    }

    public function test_a_valid_secret_with_an_unknown_order_still_gets_the_informative_404(): void
    {
        [$merchant] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');

        $response = $this->hit(self::ORDERS, ['product_order_id' => 'NO-SUCH-ORDER-3', 'status' => 1], 'REAL-SECRET-FIXTURE');

        $this->assertSame(404, $response->status());
        $response->assertJson(['message' => 'Order not found.']);
    }

    public function test_no_token_and_no_secret_configured_are_refused_the_same_way(): void
    {
        [$merchant, $store] = $this->makeTenant();   // no secret configured on this merchant
        $order = $this->makeOrder($store);

        $noToken = $this->postJson(self::ORDERS, ['product_order_id' => $order->product_order_id, 'status' => 1], self::HEADERS);
        $unconfigured = $this->hit(self::ORDERS, ['product_order_id' => $order->product_order_id, 'status' => 1], 'ANYTHING');
        $unknown = $this->hit(self::ORDERS, ['product_order_id' => 'NO-SUCH', 'status' => 1], 'ANYTHING');

        $this->assertSame(403, $noToken->status());
        $this->assertSame($this->signature($unconfigured), $this->signature($unknown));
        $this->assertSame(403, $unconfigured->status(), 'a store with no configured secret never matches a token');
    }

    public function test_the_credential_still_precedes_input_validation(): void
    {
        $response = $this->hit(self::ORDERS, [], 'WRONG-TOKEN');

        $this->assertSame(403, $response->status(), 'an invalid payload with a wrong token must not be told it is invalid (422)');
    }

    /**
     * The route is bounded by the api group's throttle (bootstrap/app.php throttleApi). An earlier reading of this task said it had NO limit;
     * that was wrong -- measured: both routes resolve `ThrottleRequests:120,1` from the group, and the sibling adds `:60,1` on top, which shares
     * the group's counter (same empty prefix), so a request counts twice there. A route-level throttle added to THIS route would have halved the
     * rate the live provider can call at, for no gain once the oracle is gone, so none is added; this pins the brake that exists.
     */
    public function test_the_primary_route_stays_behind_a_request_throttle(): void
    {
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create(self::ORDERS, 'POST'));
        $throttles = array_filter(app('router')->gatherRouteMiddleware($route), fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests'));

        $this->assertNotEmpty($throttles, 'The delivery-syria order endpoint lost its request throttle.');
    }

    // -- the sibling (Zid) --------------------------------------------------------------------

    private function zidSchema(): void
    {
        foreach (['zid_orders' => fn (Blueprint $t) => [$t->id(), $t->string('zid_order_id')->nullable(), $t->unsignedBigInteger('store_id')->nullable(), $t->string('status_code')->nullable(), $t->timestamps()],
            'zid_stores' => fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('store_id')->nullable(), $t->text('authorization')->nullable(), $t->text('access_token')->nullable(), $t->timestamps()]] as $table => $definition) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        }
    }

    /**
     * A `zid_orders` row with the columns the REAL table requires.
     *
     * ADDED 2026-11-02. Five inline inserts here supplied only `zid_order_id`, `store_id` and the
     * timestamps -- enough for the reduced stand-in this class used to build for itself, whose
     * `zidSchema()` declared `zid_order_id` as a NULLABLE string and `store_id` as a nullable
     * integer. Against the MIGRATED table they all failed with
     * `NOT NULL constraint failed: zid_orders.raw`.
     *
     * That failure is the measured value of running against the real structure: production's `raw`
     * is `longtext NOT NULL` -- it holds the Zid payload a row was ingested from, so a row without
     * one is a row production cannot hold. `parcel_status` has a default and needs nothing here.
     *
     * ================================================================================================
     *  AND READ THIS BEFORE QUOTING ANY RESULT FROM THIS CLASS: `store_id` HERE IS NOT WHAT
     *  PRODUCTION PUTS IN THAT COLUMN.
     * ================================================================================================
     *
     * `zid_orders.store_id` is `varchar(191)` holding **Zid's own merchant identifier**, written by
     * `ZidProcessEvents::saveOrder()` straight from Zid's webhook payload -- which is why neither
     * Zid table has a foreign key to `stores`. `routes/web.php` says it outright: *"zid_stores.store_id
     * is Zid's own store id and the row links to a Delivery Syria merchant, not to a StoreSyria
     * tenant, so there is no column to write an ownership predicate against"*.
     *
     * These fixtures pass this platform's `stores.id`, because that is what the controller compares
     * against (`DeliverySyriaCredential::storeIdsFor()` returns `stores.id` integers). So what this
     * class proves is that the `whereIn('store_id', ...)` CONFINEMENT works and that the endpoint is
     * not an existence oracle -- not that a configured Zid merchant can move its own order. In
     * production the namespaces do not meet, so that endpoint fails closed. Recorded as a finding
     * against `DeliverySyriaStatusController` and deliberately not repaired inside a schema change;
     * `DeliverySyriaWebhookTest` carries the same note and the constraint any repair must satisfy.
     */
    private function zidOrder(string $zidOrderId, $storeId): void
    {
        DB::table('zid_orders')->insert([
            'zid_order_id' => $zidOrderId,
            'store_id'     => (string) $storeId,
            'raw'          => '{}',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function test_the_zid_sibling_does_not_tell_an_existing_order_from_an_unknown_one_either(): void
    {
        $this->zidSchema();
        [$merchant, $store] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $this->zidOrder('555111', $store->id);

        $exists = $this->hit(self::ZID, ['product_order_id' => 'ZID-555111', 'status' => 9], 'WRONG-TOKEN');
        $absent = $this->hit(self::ZID, ['product_order_id' => 'ZID-999999', 'status' => 9], 'WRONG-TOKEN');

        $this->assertSame(403, $exists->status(), 'Premise: a wrong token is refused.');
        $this->assertSame($this->signature($exists), $this->signature($absent), 'The Zid endpoint distinguishes an existing order from an unknown one with a wrong token.');
        $this->assertStringNotContainsString('999999', (string) $absent->getContent(), 'and does not echo the caller\'s id back inside a 404');
    }

    public function test_the_zid_sibling_gives_a_valid_secret_of_another_store_no_oracle(): void
    {
        $this->zidSchema();
        [$attacker] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();
        $this->secretFor($attacker, 'ATTACKER-SECRET-FIXTURE');
        $this->zidOrder('555222', $victimStore->id);

        $foreign = $this->hit(self::ZID, ['product_order_id' => 'ZID-555222', 'status' => 9], 'ATTACKER-SECRET-FIXTURE');
        $absent = $this->hit(self::ZID, ['product_order_id' => 'ZID-999998', 'status' => 9], 'ATTACKER-SECRET-FIXTURE');

        $this->assertSame($foreign->status(), $absent->status());
        $this->assertSame(404, $foreign->status());
    }

    public function test_the_zid_sibling_still_reaches_the_callers_own_order(): void
    {
        $this->zidSchema();
        [$merchant, $store] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $this->zidOrder('555333', $store->id);

        // status 9 has no Zid mapping: the handler answers 200 without any outbound call, which proves the order was found
        $response = $this->hit(self::ZID, ['product_order_id' => 'ZID-555333', 'status' => 9], 'REAL-SECRET-FIXTURE');

        $this->assertSame(200, $response->status(), $response->getContent());

        // COMPARED AS A STRING, deliberately. `zid_orders.store_id` is `varchar(191)` in the
        // measured production schema -- it holds ZID's own merchant identifier -- so the handler
        // echoes back whatever string the column held. The stand-in table this class used to build
        // declared it as an unsignedBigInteger, which is why `assertSame($store->id, ...)` used to
        // hold and now reports "'1' is identical to 1".
        //
        // That one-character difference is the whole namespace finding in miniature: code that
        // compares this column against an integer `stores.id` is comparing two different identifier
        // spaces. On MySQL such a comparison is coerced to DOUBLE, so '2abc' = 2 is TRUE while
        // SQLite says false -- see the note on zidOrder() above.
        $this->assertSame((string) $store->id, (string) $response->json('data.store_id'));
    }

    public function test_the_zid_local_status_write_touches_only_the_authenticated_stores_row(): void
    {
        $this->zidSchema();
        [$merchant, $store] = $this->makeTenant();
        [, $otherStore] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $now = ['created_at' => now(), 'updated_at' => now()];

        /*
         * ================================================================================================
         *  THE OLD PREMISE OF THIS TEST IS A STATE PRODUCTION CANNOT HOLD, and GATE F is what revealed it.
         * ================================================================================================
         *
         * It used to insert the SAME `zid_order_id` ('555444') for two tenants, with the comment
         * "the provider's id space is not ours", and then assert that the status write touched only
         * the caller's row. Against the stand-in table this class built for itself -- which had no
         * keys at all -- that was constructible.
         *
         * The measured production schema has `UNIQUE KEY zid_orders_zid_order_id_unique
         * (zid_order_id)`, and it is **GLOBAL, not per store**. So two rows sharing an order number
         * cannot exist, in any tenant combination, and the second insert is refused by the database.
         * The property the test was written to protect is therefore enforced by a KEY rather than
         * resting on the handler's predicate -- which is strictly better, and worth asserting as
         * such instead of simulating a scenario the schema forbids.
         *
         * BOTH HALVES ARE KEPT. The unique key is asserted directly, because that is the real
         * guarantee; and the confinement is still exercised, with DISTINCT order numbers, because
         * the key says nothing about which row a write lands on.
         */
        $this->zidOrder('555444', $store->id);

        try {
            $this->zidOrder('555444', $otherStore->id);
            $this->fail(
                'zid_orders accepted a second row with the same zid_order_id. Production has a '
                . 'GLOBAL unique key on that column (zid_orders_zid_order_id_unique), so this '
                . 'suite is running against a schema that is not production\'s -- check that '
                . '2026_11_02_001400_create_zid_orders_table.php has not been reverted.'
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // The guarantee, exercised rather than described.
        }

        // A DISTINCT number for the other tenant, so the confinement below is about the handler's
        // predicate and not about the key.
        $this->zidOrder('555555', $otherStore->id);

        DB::table('zid_stores')->insert(['store_id' => (string) $store->id, 'authorization' => \Illuminate\Support\Facades\Crypt::encryptString('AUTH-FIXTURE'),
            'access_token' => \Illuminate\Support\Facades\Crypt::encryptString('MANAGER-FIXTURE')] + $now);
        \Illuminate\Support\Facades\Http::fake(['api.zid.sa/*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200)]);

        $response = $this->hit(self::ZID, ['product_order_id' => 'ZID-555444', 'status' => 1], 'REAL-SECRET-FIXTURE');

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame('delivered', DB::table('zid_orders')->where('zid_order_id', '555444')->value('status_code'), 'Premise: the caller\'s own row was written.');
        $this->assertNull(DB::table('zid_orders')->where('zid_order_id', '555555')->value('status_code'), 'Another tenant\'s Zid order was written by a call that named a different order.');
    }

    public function test_an_order_number_shared_by_two_stores_resolves_to_the_authenticated_stores_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $otherStore] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');
        $foreign = $this->makeOrder($otherStore, ['product_order_id' => 'SHARED-NUMBER-1']);   // inserted first: an unrestricted first() would pick it
        $own = $this->makeOrder($store, ['product_order_id' => 'SHARED-NUMBER-1']);

        $response = $this->hit(self::ORDERS, ['product_order_id' => 'SHARED-NUMBER-1', 'status' => 1], 'REAL-SECRET-FIXTURE');

        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame(1, (int) DB::table('orders')->where('id', $own->id)->value('delivered_status'));
        $this->assertSame(0, (int) DB::table('orders')->where('id', $foreign->id)->value('delivered_status'));
    }

    public function test_an_empty_or_blank_token_never_matches_a_blank_stored_secret(): void
    {
        [$merchant] = $this->makeTenant();
        $this->secretFor($merchant, '   ');   // a whitespace-only secret trims to '' -- which '' would equal

        $this->assertSame([], \App\Services\DeliverySyriaCredential::storeIdsFor(''));
        $this->assertSame([], \App\Services\DeliverySyriaCredential::storeIdsFor('   '));
    }

    public function test_a_secret_resolves_to_exactly_the_stores_its_owner_created(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $otherStore] = $this->makeTenant();
        $this->secretFor($merchant, 'REAL-SECRET-FIXTURE');

        $this->assertSame([$store->id], \App\Services\DeliverySyriaCredential::storeIdsFor('REAL-SECRET-FIXTURE'));
        $this->assertSame([$store->id], \App\Services\DeliverySyriaCredential::storeIdsFor(' REAL-SECRET-FIXTURE '), 'the token is compared trimmed, as the stored secret is');
        $this->assertNotContains($otherStore->id, \App\Services\DeliverySyriaCredential::storeIdsFor('REAL-SECRET-FIXTURE'));
        $this->assertSame([], \App\Services\DeliverySyriaCredential::storeIdsFor('REAL-SECRET-FIXTURE-X'));
    }

    public function test_the_zid_sibling_also_checks_the_credential_before_the_payload(): void
    {
        $response = $this->hit(self::ZID, [], 'WRONG-TOKEN');

        $this->assertSame(403, $response->status(), 'a wrong token with an empty payload must not be told the payload is invalid (422)');
        $response->assertJson(['message' => 'Invalid credentials.']);
    }

    /**
     * Two merchants who saved the SAME secret value are one credential to this endpoint: it reaches the stores of both. That is what the old
     * code did too (the order's own store owner's secret only had to equal the token) and it is unreachable without already holding the secret;
     * pinned so a change to fail-closed is a decision, not an accident.
     */
    public function test_a_secret_shared_by_two_merchants_reaches_the_stores_of_both(): void
    {
        [$first, $firstStore] = $this->makeTenant();
        [$second, $secondStore] = $this->makeTenant();
        $this->secretFor($first, 'SHARED-SECRET-FIXTURE');
        $this->secretFor($second, 'SHARED-SECRET-FIXTURE');

        $ids = \App\Services\DeliverySyriaCredential::storeIdsFor('SHARED-SECRET-FIXTURE');

        $this->assertEqualsCanonicalizing([$firstStore->id, $secondStore->id], $ids);
    }
}

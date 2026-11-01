<?php

namespace Tests\Contract;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * E13 REQUIREMENT D — THE NEW COLUMN IS ADDITIVE, AND THE FLUTTER ADMIN APP'S ORDER PAYLOAD DOES
 * NOT CHANGE SHAPE.
 * ==============================================================================================
 *
 * `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` §7 requires exactly this assertion: *"the new column is
 * additive and no existing response shape changes; a Contract-suite assertion that the admin order
 * payloads are unchanged."*
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A COLUMN CAN CHANGE A PAYLOAD AT ALL, which is the whole risk being tested
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `GET api/admin/orderlist` is the shipped admin app's order list. If its handler serialises an
 * `Order` model wholesale — `toArray()`, `->get()` straight into a response, a resource with no
 * explicit field list — then adding **any** column to `orders` adds a key to the payload. A new
 * key is usually harmless, but "usually" is not a contract, and the owner's standing condition is
 * that Flutter compatibility is preserved rather than assumed.
 *
 * So this compares the payload's TOP-LEVEL ORDER KEYS before and after the column exists, by
 * driving the real route twice against the real handler. The column is dropped for the first pass
 * and restored for the second, which is the only way to observe the difference — reasoning about
 * the handler's serialisation would be an argument, not a measurement.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A FAILURE HERE MEANS
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Not "the column is wrong". It means the handler serialises the model wholesale, so the payload
 * grew a `replaced_order_id` key the app did not ask for — and the fix is to give that handler an
 * explicit field list, not to remove the column. The app tolerates unknown keys in practice, but a
 * typed Dart model built with a strict decoder does not, and the shipped app is the one client
 * that cannot be redeployed alongside the server.
 */
class AdminOrderPayloadShapeContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `currency_rates` IS A GATE F TABLE, AND THIS IS WHY ITS ABSENCE STOPS THIS TEST.
     *
     * `GET api/admin/orderlist` reads it, and no migration in this repository creates it: it is
     * one of the fifteen tables GATE F found living in production **unmanaged**, applied out of
     * band. So a fresh environment — CI, staging, this suite — does not have it, and the endpoint
     * answers a 500 (`no such table: currency_rates`) rather than a payload.
     *
     * The structure below is **copied from `docs/GATE-F-VERIFIED-SCHEMA.md` §2**, which was
     * generated from `information_schema` on a restored copy of production, not invented here.
     * That distinction is the whole point of that document: inferring these tables from the
     * repository is what produced a two-month-old claim that the financial subsystem was dead when
     * all fifteen tables existed with live data.
     *
     * Only this one table is created, and only the columns the read needs. The FK to `stores` and
     * the DECIMAL(20,8) precision are deliberately omitted — SQLite enforces neither, and
     * pretending otherwise would be the affinity trap CLAUDE.md records. Anything that depends on
     * those belongs in a MariaDB script, not here.
     *
     * **This is a test-local workaround and NOT a fix for GATE F.** The drafts are parked in
     * `database/migrations/_gate_f_pending/` awaiting the owner, and running them is a persistent
     * migration this authorisation does not cover.
     */
    private function installGateFCurrencyRates(): void
    {
        if (Schema::hasTable('currency_rates')) {
            return;
        }

        Schema::create('currency_rates', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id');
            $table->string('currency_code', 10);
            $table->string('currency_name', 100);
            $table->string('symbol', 20)->nullable();
            $table->decimal('rate_per_usd', 20, 8)->default(1);
            $table->boolean('is_active')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'currency_code'], 'uq_currency_rates_store_code');
        });
    }

    private function orderIn($store): Order
    {
        $product = Product::forceCreate([
            'name' => 'shape-' . uniqid(), 'slug' => 'shape-' . uniqid(),
            'product_stock' => 10, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100, 'sale_price' => 100, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        return Order::create([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'name' => $product->name,
                'quantity' => 1, 'price' => 100, 'variant_id' => 0,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => 100, 'coupon_price' => 0, 'delivery_price' => 0,
            'tax_price' => 0, 'final_price' => 100,
            'payment_type' => 'cod', 'payment_status' => 'paid', 'delivered_status' => 0,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function orderListFor($merchant, $store): array
    {
        $token = \App\Models\User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/orderlist?store_id=' . $store->id);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'the order list did not answer 200, so this test cannot compare its payload. Body: '
            . substr((string) $response->getContent(), 0, 300)
        );

        return (array) $response->json();
    }

    /** Every key at every level, so a nested addition is caught as well as a top-level one. */
    private static function keyShape($value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $k => $v) {
            /* Numeric keys are list positions, not shape -- a second order must not read as a
               different shape from the first. */
            $path = is_int($k) ? $prefix . '[]' : ($prefix === '' ? (string) $k : $prefix . '.' . $k);

            if (! is_int($k)) {
                $keys[] = $path;
            }

            $keys = array_merge($keys, self::keyShape($v, $path));
        }

        return array_values(array_unique($keys));
    }

    public function test_the_admin_order_payload_has_the_same_keys_with_and_without_the_new_column(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Manage Order');
        $this->installGateFCurrencyRates();
        $this->orderIn($store);

        $this->assertTrue(
            Schema::hasColumn('orders', 'replaced_order_id'),
            'premise: the column is not installed, so this test cannot measure its effect'
        );

        $withColumn = self::keyShape($this->orderListFor($merchant, $store));
        sort($withColumn);

        /*
         * THE ANTI-VACUITY GUARD, and it is the real risk in a shape comparison: two empty key
         * sets are identical, so a payload this test failed to read would pass it silently. The
         * floor is deliberately low — it asserts the payload was READ, not what is in it, because
         * pinning the exact key list here would make this test fail every time a field is
         * legitimately added and would teach the next reader to update the number rather than
         * think about it.
         */
        $this->assertGreaterThan(
            5,
            count($withColumn),
            'the order list payload yielded almost no keys, so comparing it against anything is '
            . 'vacuous. Found: ' . implode(', ', $withColumn)
        );
        $this->assertContains('data.data[].product_order_id', $withColumn,
            'the payload does not carry the order number where this test expects it, so the shape '
            . 'being compared may not be the order list at all');

        /*
         * Drop it and ask again, mirroring BOTH migrations' `down()`.
         *
         * [2026-11-01] THE UNIQUE INDEX HAD TO BE ADDED HERE, and the failure was informative:
         * migration `2026_11_01_000100` adds `orders_replaced_order_id_unique` so that
         * "one replacement per original" is a database fact (D-15 condition 8). SQLite then refuses
         * the column drop with
         *
         *     error in index orders_replaced_order_id_unique after drop column:
         *     no such column: replaced_order_id
         *
         * — an index cannot outlive the column it is on. The index must go first, and both indexes
         * are dropped defensively because which ones exist depends on how far the migrations ran.
         */
        /*
         * INTROSPECTED, NOT try/caught. A `try` around `$table->dropIndex()` catches nothing:
         * Blueprint QUEUES the statement and the builder executes it after the closure returns, so
         * a failure is raised outside the try. Both indexes happen to exist here, so a try/catch
         * version passed — and would have thrown the moment only one did, which is exactly the
         * kind of guard that works until it matters.
         */
        $present = array_map(
            static fn ($index) => $index['name'] ?? '',
            Schema::getIndexes('orders')
        );

        Schema::table('orders', function ($table) use ($present) {
            foreach (['orders_replaced_order_id_unique', 'orders_replaced_order_id_index'] as $index) {
                if (in_array($index, $present, true)) {
                    $table->dropIndex($index);
                }
            }

            $table->dropColumn('replaced_order_id');
        });

        $this->assertFalse(Schema::hasColumn('orders', 'replaced_order_id'), 'the column was not dropped');

        /*
         * ══════════════════════════════════════════════════════════════════════════════════════
         * FLUSH THE MODEL CACHE, AND THE FIRST VERSION OF THIS TEST DID NOT — so it passed while
         * measuring nothing.
         * ══════════════════════════════════════════════════════════════════════════════════════
         *
         * `Order` is one of the 79 `Cachable` models (CLAUDE.md), and under `CACHE_STORE=array`
         * the cache lives for the whole test process. So the second request was served the
         * attribute set from BEFORE the column was dropped, the two shapes came back identical,
         * and the assertion passed — on a payload that does in fact grow a key. I only caught it
         * because an anti-vacuity guard made me print the shape and `replaced_order_id` was
         * sitting in it.
         *
         * That is the exact false reading CLAUDE.md records three measured instances of, and it is
         * worth noting the mechanism: the cache did not make the test flaky, it made it
         * CONFIDENTLY WRONG in the direction I wanted.
         */
        (new Order)->flushCache();

        $withoutColumn = self::keyShape($this->orderListFor($merchant, $store));
        sort($withoutColumn);

        /*
         * ══════════════════════════════════════════════════════════════════════════════════════
         * WHAT THE CONTRACT ACTUALLY IS: nothing may be REMOVED or RENAMED. An addition is fine.
         * ══════════════════════════════════════════════════════════════════════════════════════
         *
         * The measurement, once the cache stopped lying: the handler serialises the `Order` model
         * wholesale — the payload carries `created_by`, `created_at`, `demo_field` and 45 keys in
         * all — so adding a column DOES add a key. The design's phrase *"no existing response
         * shape changes"* has to be read as what breaks a client, and a key appearing does not:
         * `json_decode` into a map ignores it, and the shipped app's models take the fields they
         * name. A key DISAPPEARING or being renamed is what breaks a typed decoder.
         *
         * So the assertion is one-directional, and the added key is named explicitly rather than
         * tolerated as "some difference" — if a second, unexpected key ever appears alongside it,
         * this fails and somebody reads why.
         */
        $removed = array_diff($withoutColumn, $withColumn);
        $added = array_diff($withColumn, $withoutColumn);

        $this->assertSame(
            [],
            array_values($removed),
            "The admin order payload LOST keys when `orders.replaced_order_id` was added:\n  "
            . implode("\n  ", $removed)
            . "\n\nThat is the direction that breaks the shipped app: a typed Dart model whose "
            . 'field is suddenly absent fails to decode, and the admin app is the one client that '
            . 'cannot be redeployed alongside the server.'
        );

        $this->assertSame(
            ['data.data[].replaced_order_id'],
            array_values($added),
            "The admin order payload grew a key other than the one this column adds:\n  "
            . implode("\n  ", $added)
            . "\n\nThe handler serialises the Order model wholesale, so EVERY column added to "
            . '`orders` lands in the shipped app\'s payload. One known additive key is accepted; '
            . 'an unexplained second one means something else changed in the same breath.'
        );
    }
}

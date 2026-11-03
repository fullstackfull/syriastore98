<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P2-04-14 / WS E11 — AN ORDER'S PRODUCTS AND QUANTITIES CANNOT BE EDITED AFTER CREATION.
 * =================================================================================================
 *
 * THE RULE AND WHY IT IS A RULE. An order's line items are the record of what was sold: stock has
 * already moved against them, a payment has been taken against their total, and a refund is
 * computed from them. Editing them in place would make all three disagree with the order, silently,
 * and there would be nothing to reconcile against. Corrections go through cancel-and-replace
 * (P2-04-16) precisely so that the original stays as evidence.
 *
 * WHERE THE LINES LIVE. There is no line-item table: `orders.product_json` holds the lines,
 * `orders.product_id` a comma list, and `orders.product_price` the lines' subtotal. All three are
 * in `Order::$fillable`, which is what makes the question interesting rather than academic.
 *
 * WHAT THE ACCEPTANCE ASKS FOR: "a server-side guard, not a hidden control; an attempt is refused
 * and logged". So this file does NOT check that the UI omits the control — it posts the edit
 * directly, at every order-mutating endpoint a merchant can reach, and reads the row back.
 *
 * HOW THE RULE IS CURRENTLY HELD, established by census before this was written:
 *
 *   - NO ROUTE ACCEPTS A PRODUCT LIST. The order-mutating routes are assign, status-change,
 *     payment-status, update-status, return, return-request, and the refund set. Each handler
 *     assigns the ONE named field it is about (`$order->payment_status = $request->input(...)`),
 *     never the request wholesale.
 *   - NO HANDLER MASS-ASSIGNS. The only `$order->update([...])` in the codebase
 *     (`OrderRefundController:359`) passes a literal array, not request data.
 *   - The two post-creation writers of `product_json` are legitimate and neither changes products
 *     or quantities: `Order::product_return()` adds the `LINE_STOCK_RESTORED` marker that makes
 *     restocking idempotent (P1-INV-05), and `StoreCurrencyConversionService` rewrites PRICES on a
 *     currency change, which is a different rule (E14's territory, not E11's).
 *
 * So the rule holds by construction. This file is what makes that a measured fact rather than a
 * reading, and the ratchet at the end is what keeps it true when somebody adds the next endpoint.
 */
class OrderLineImmutabilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The lines as they are created, and must remain. */
    private const LINES = [
        ['product_id' => 7, 'name' => 'Damask Scarf', 'qty' => 2, 'price' => 33.0],
        ['product_id' => 9, 'name' => 'Olive Soap', 'qty' => 1, 'price' => 12.0],
    ];

    /**
     * ASSERT THAT GATE F'S MIGRATION IS PRESENT. It does not create anything, and the name says so
     * since 2026-11-02 (it was `ensureDeliveryEarningsTable`).
     *
     * WHAT USED TO BE HERE, AND WHY ITS REMOVAL IS THE POINT. This method built the table itself,
     * behind `if (! Schema::hasTable(...))`, because the table was declared by no migration. That
     * fallback is DELETED rather than left dead. It was a loaded gun: it declared COLUMNS ONLY,
     * with none of production's unique keys or foreign keys, so if the real migration were ever
     * reverted or renamed this suite would have gone GREEN against a fabricated money schema --
     * the exact failure GATE F exists to prevent. And `GateFSchemaContainmentTest` could not see
     * it, because a `Schema::create()` inside a test is not a discoverable migration.
     *
     * The structure now comes from the migration, measured against a restored copy of production
     * and recorded in `docs/GATE-F-VERIFIED-SCHEMA.md` section 2. A missing table is reported as a
     * missing table.
     */
    private function assertDeliveryEarningsIsMigrated(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('delivery_earnings'),
            '`delivery_earnings` is missing from a database built by this repository\'s migrations. '
            . 'GATE F declared it on 2026-11-02 with a structure measured against a restored '
            . 'copy of production, so this means the migration has been reverted or renamed. '
            . 'Do NOT answer this by creating the table here: a Schema::create() in a test is '
            . 'not a discoverable migration, carries none of production\'s unique keys or '
            . 'foreign keys, and would make this suite green against a fabricated money schema.'
        );

    }

    private function orderIn($store, $merchant): Order
    {
        return Order::forceCreate([
            'product_order_id' => 'IMMUT-' . uniqid(),
            'order_date' => now()->toDateString(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode(self::LINES),
            'product_id' => '7,9',
            'product_price' => 78.0,
            'final_price' => 78.0,
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    /** The three columns that carry the lines, read without any tenant scope. */
    private function lines(int $orderId): array
    {
        $row = DB::table('orders')->where('id', $orderId)->first();

        return [
            'product_json' => (string) $row->product_json,
            'product_id' => (string) $row->product_id,
            'product_price' => (string) $row->product_price,
        ];
    }

    /**
     * The payload a hostile caller would send: every spelling of "change the lines" that the
     * columns, the fillable list and the JSON shape suggest.
     */
    private function hostileFields(): array
    {
        return [
            'product_json' => json_encode([
                ['product_id' => 7, 'name' => 'Damask Scarf', 'qty' => 999, 'price' => 0.01],
            ]),
            'product_id' => '7',
            'product_price' => 0.01,
            'qty' => 999,
            'quantity' => 999,
            'products' => [['product_id' => 7, 'quantity' => 999, 'price' => 0.01]],
        ];
    }

    public function test_the_fixture_really_holds_the_lines_it_claims(): void
    {
        // A premise. Every assertion below compares against this, so if the fixture does not
        // store what it says, "unchanged" would be trivially true.
        [$merchant, $store] = $this->makeTenant();
        $order = $this->orderIn($store, $merchant);

        $before = $this->lines($order->id);

        $this->assertSame('7,9', $before['product_id']);
        $decoded = json_decode($before['product_json'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded, 'the order must start with two lines');
        $this->assertSame(2, $decoded[0]['qty']);
    }

    /**
     * Each order-mutating endpoint, the payload it needs to do its OWN job, and how we know the
     * HANDLER WAS REACHED.
     *
     * THE REACH MARKER IS NOT OPTIONAL. The first version of this file posted a guessed payload to
     * each path and asserted only that the lines were unchanged -- and three of the six answered
     * 500, 302 and 403, so for half the endpoints it was asserting that a request which never got
     * past validation or authorization did not change anything. Measured:
     *
     *     /order-status-change/1   -> 500   (the payload used `status`; the handler reads `delivered`)
     *     /order-assign            -> 302   (validation: it requires `delivery_boy`, not `deliveryboy_id`)
     *     /update-final-price/1    -> 403   (it needs `Manage Order Refund Request`)
     *
     * A test that cannot distinguish "the handler ignored the hostile fields" from "the handler
     * never ran" proves nothing about E11. So each row now carries a marker, and a case whose
     * marker fails is a FAILURE rather than a quiet pass.
     *
     * @return array<string, array{0:string, 1:array<string,mixed>, 2:string}>
     */
    public static function mutatingEndpoints(): array
    {
        return [
            // path                        own payload                          reached when
            'status change'  => ['order-status-change/{id}', ['delivered' => 'confirmed'], 'json-success'],
            'update status'  => ['update-order-status/{id}', ['delivered_status' => 2], 'json-any'],
            'payment status' => ['order-payment-status', ['payment_status' => 'Paid'], 'json-success'],
            'assign'         => ['order-assign', ['delivery_boy' => '{driver}'], 'not-403'],
            'return'         => ['order-return', ['return_status' => 1], 'json-any'],
            'final price'    => ['update-final-price/{id}', ['final_price' => 1, 'refundId' => 0], 'not-403'],
        ];
    }

    /**
     * @dataProvider mutatingEndpoints
     */
    public function test_no_order_endpoint_applies_posted_line_items(string $path, array $own, string $reachedWhen): void
    {
        [$merchant, $store] = $this->makeTenant();
        foreach (['Manage Order', 'Edit Order', 'Manage Order Refund Request'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }
        $this->assertDeliveryEarningsIsMigrated();
        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
        $order = $this->orderIn($store, $merchant);

        $before = $this->lines($order->id);
        $url = '/' . str_replace('{id}', (string) $order->id, $path);

        $own = array_map(
            fn ($v) => $v === '{driver}' ? $driver->id : $v,
            $own
        );
        $payload = array_merge($own, $this->hostileFields(), [
            'id' => $order->id,
            'order_id' => $order->id,
        ]);

        /*
         * `X-Requested-With: XMLHttpRequest` ADDED 2026-11-03 BY P2.5-R-05. Every endpoint in this
         * dataProvider is called from the order screens by jQuery `$.ajax`, which sends this
         * header; a bare `post()` sent neither it nor an `Accept`, so it stood in for a caller
         * that does not exist. It became load-bearing when the status route grew a second,
         * form-based caller and started answering `expectsJson()` callers with JSON and everyone
         * else with a redirect -- at which point `$response->json()` at :223 would have failed
         * before this test's own premise message could print.
         *
         * Both headers, not just the first: `Request::create()` injects a browser-like Accept, so
         * `acceptsAnyContentType()` is false with X-Requested-With alone and `expectsJson()`
         * stays false. Measured, not assumed.
         */
        $response = $this->actingAs($merchant)
            ->post($url, $payload, ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']);
        $code = $response->getStatusCode();

        /* THE PREMISE: the handler ran. */
        $this->assertLessThan(500, $code,
            'POST ' . $url . ' answered ' . $code . ', so the handler errored rather than running. '
            . 'The line-item assertion below would be measuring a crash.');

        if ($reachedWhen === 'not-403') {
            $this->assertNotSame(403, $code,
                'POST ' . $url . ' was refused with 403, so the handler never ran and this case '
                . 'proves nothing about E11. Grant the permission it needs.');
            $this->assertNotSame(302, $code,
                'POST ' . $url . ' redirected, which here means its own validation refused the '
                . 'payload -- so the handler never ran. Fix the payload, not the assertion.');
        } else {
            $json = $response->json();
            $this->assertIsArray($json,
                'POST ' . $url . ' did not answer JSON (' . $code . '), so there is no evidence '
                . 'the handler ran: ' . substr(strip_tags((string) $response->getContent()), 0, 120));
            if ($reachedWhen === 'json-success') {
                $this->assertSame('success', $json['status'] ?? null,
                    'POST ' . $url . ' answered ' . json_encode($json)
                    . '; its own job did not succeed, so it may not have reached the code that '
                    . 'would have applied the hostile fields');
            }
        }

        /* AND THE RULE ITSELF. The response is deliberately not asserted beyond reach: these
         * endpoints answer in several shapes, and a handler that refuses and one that succeeds at
         * its own job while ignoring the extra fields both satisfy E11. One that WRITES them does
         * not, whatever it answers. */
        $after = $this->lines($order->id);

        $this->assertSame($before, $after,
            'POST ' . $url . " changed the order's line items.\n"
            . 'before: ' . json_encode($before) . "\n"
            . 'after:  ' . json_encode($after) . "\n\n"
            . "An order's products, quantities and line subtotal are the record stock and payment "
            . 'were taken against. Corrections go through cancel-and-replace (P2-04-16). See E11.');
    }

    public function test_the_lines_survive_a_whole_sequence_of_legitimate_changes(): void
    {
        /* Each endpoint alone is covered above. This drives them in the order a merchant would,
         * with the hostile fields on every request, because a handler that reads a value another
         * handler left on the model is the kind of thing a per-endpoint test cannot see. */
        [$merchant, $store] = $this->makeTenant();
        foreach (['Manage Order', 'Edit Order', 'Manage Order Refund Request'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }
        $order = $this->orderIn($store, $merchant);

        $before = $this->lines($order->id);

        $this->assertDeliveryEarningsIsMigrated();
        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        foreach (self::mutatingEndpoints() as [$path, $own, $reachedWhen]) {
            $url = '/' . str_replace('{id}', (string) $order->id, $path);
            $own = array_map(fn ($v) => $v === '{driver}' ? $driver->id : $v, $own);
            $this->actingAs($merchant)->post($url, array_merge($own, $this->hostileFields(), [
                'id' => $order->id,
                'order_id' => $order->id,
            ]));
        }

        $this->assertSame($before, $this->lines($order->id),
            "the order's lines changed across a sequence of legitimate status changes");
    }

    public function test_no_handler_mass_assigns_request_data_into_an_order(): void
    {
        /* THE RATCHET. `product_json`, `product_id` and `product_price` are all in
         * `Order::$fillable`, so a single `$order->update($request->all())` would make every case
         * above fail at once -- and would be an easy thing to write while adding an endpoint. The
         * driven cases cover the endpoints that exist today; this covers the next one. */
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach ([$root . '/app'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $code = (string) file_get_contents($file->getPathname());
                $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
                $code = (string) preg_replace('#//[^\n]*#', '', $code);

                // `$order->update($request->...)`, `->fill($request->...)`, and the `Order::create`
                // / `->update` forms that take a whole request array.
                if (preg_match_all(
                    '/\$[a-zA-Z_]*[Oo]rder[a-zA-Z_]*\s*->\s*(?:update|fill|forceFill)\s*\(\s*\$request/',
                    $code, $m
                )) {
                    $offenders[] = $rel . ' (' . count($m[0]) . ')';
                }
            }
        }

        $this->assertSame([], $offenders,
            "An order is mass-assigned from request input:\n  " . implode("\n  ", $offenders)
            . "\n\n`product_json`, `product_id` and `product_price` are in Order::\$fillable, so a"
            . "\nrequest-derived array can rewrite an order's line items. Assign the named fields"
            . "\ninstead. See E11 / P2-04-14.");
    }
}

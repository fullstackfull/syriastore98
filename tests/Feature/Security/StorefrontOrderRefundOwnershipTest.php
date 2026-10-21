<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * P1-ORD-07 -- THE STOREFRONT REFUND FAMILY HAD A LOGIN GATE AND NO OWNERSHIP CHECK.
 * =============================================================================================
 *
 * `routes/customer.php` moved `order-refund-request/{id}`, `order-refund/{id}` and
 * `order-return-list` behind `EnsureCustomerAuthenticated` to close the ANONYMOUS defect, and the
 * comment above that group calls the refund rewrite "worse than status.cancel". That gate asks one
 * question -- `auth('customers')->check()` -- so ANY registered shopper of ANY store passes it, and
 * registration is free. The authenticated residual was never re-triaged until P1-SEC-03.
 *
 * MEASURED, P1-SEC-03 round 2, on this head: a store-A shopper POSTing `order_id` = a store-B order
 * took that order's `final_price` 440 -> 140 and `product_price` 400 -> 100, status 302, read back
 * through raw `DB::table('orders')`. The refund RECORD landed in store A against the attacker's own
 * order (the handler writes `$id` from the PATH to `order_refunds.order_id`, while the money was
 * written to the BODY's `order_id`), so the victim merchant had no refund row explaining the drop.
 * "The pair that is checked is not the pair that is written" -- with nothing checked at all.
 *
 * WHAT IS AND IS NOT IN THIS FILE
 * ---------------------------------------------------------------------------------------------
 *  - `order_refund_request` (the write), `order_return_list` (no customer predicate) and the
 *    `order_refund` REFUND-VIEW branch (`OrderRefund::find($id)`, which is store-scoped and not
 *    customer-scoped, and dereferenced null for an unknown id) are driven here.
 *  - `customerorder` and the `order_refund?refund=true` branch are NOT. They are already refused
 *    by `OrderDocumentAccessGuard::TARGETS` and proven by `OrderDocumentAccessTest`. The P1-SEC-03
 *    round-2 critic called `customerorder` an unguarded third consumer of the forgeable ciphertext;
 *    that was read from the controller alone and is REFUTED by execution. A control that is
 *    already present is not a finding, and duplicating it here would only add a second place for
 *    the two to drift.
 *
 * THE FORM'S CONTRACT, which is what makes the quantity bounds safe: `refund_order.blade.php`
 * posts the PATH id and the body `order_id` as the same value, and renders its quantity input
 * `min="1" max="{{ $item['qty'] }}"`. Refusing a mismatched `order_id` and bounding a selected
 * line's quantity to [0, its remaining qty] therefore refuses nothing a real form can send.
 *
 * ORACLES ARE RAW `DB::table()` READS. `Order` and `OrderRefund` are Cachable, and this project has
 * three recorded false readings from asserting through a model after a write.
 */
class StorefrontOrderRefundOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    // =========================================================================================
    //  FIXTURES
    // =========================================================================================

    private function shopper(Store $store, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Shop',
            'last_name' => 'Per',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ], $attributes));
    }

    /**
     * One line: product 777, qty 4, line total 400 -> product_price 400, final_price 440.
     *
     * The line carries a `name` because every real order line does and `order_return_list` renders
     * `$matching_product['name']`; the first version of this fixture omitted it and the two list
     * tests failed with `Undefined array key "name"` -- RED for the wrong reason.
     */
    private function refundableOrder(Store $store, Customer $owner, array $overrides = []): Order
    {
        return $this->makeOrder($store, array_merge([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'customer_id' => $owner->id,
            'is_guest' => 0,
            'product_price' => 400,
            'delivery_price' => 20,
            'tax_price' => 20,
            'coupon_price' => 0,
            'final_price' => 440,
            'product_json' => json_encode([
                ['product_id' => 777, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 4, 'final_price' => 400],
            ]),
        ], $overrides));
    }

    /** The money columns and line items, read around every cache. */
    private function money(Order $order): array
    {
        $row = (array) DB::table('orders')->where('id', $order->id)->first();

        return [
            'final_price' => (float) $row['final_price'],
            'product_price' => (float) $row['product_price'],
            'product_json' => $row['product_json'],
        ];
    }

    private function refundRows(): int
    {
        return DB::table('order_refunds')->count();
    }

    /** What the real form posts for a partial refund of 3 of the 4 units. */
    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_id' => $order->id,
            'product_refund_id' => [777],
            'quantity' => [3],
            'return_price' => [300],
            'product_sub_total' => 300,
            'refund_reason' => 'Other',
            'custom_refund_reason' => 'changed my mind',
        ], $overrides);
    }

    /** NOT named post(): that would shadow Laravel's MakesHttpRequests::post(). */
    private function refundRequest(Customer $as, Store $slug, Order $pathOrder, array $body)
    {
        return $this->actingAs($as, 'customers')
            ->post('/' . $slug->slug . '/order-refund-request/' . $pathOrder->id, $body);
    }

    private function seedRefund(Store $store, Order $order, string $reasonMarker): int
    {
        return (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'refund_status' => 'Processing',
            'refund_reason' => 'Other',
            'custom_refund_reason' => $reasonMarker,
            'product_refund_id' => json_encode([
                ['product_refund_id' => 777, 'return_price' => 300, 'quantity' => 3],
            ]),
            'product_refund_price' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * TWO lines, so that "the position of a ticked box" and "the position of the line" are
     * different numbers. Every pre-E1 fixture had one line, where tick index 0 and line index 0 are
     * the same thing -- which is exactly why the misalignment this block pins went unseen.
     *
     * Alpha 501 qty 2 @ 200, Beta 502 qty 3 @ 300 -> product_price 500, +20 +20 = final 540.
     */
    private function twoLineOrder(Store $store, Customer $owner): Order
    {
        return $this->refundableOrder($store, $owner, [
            'product_price' => 500,
            'final_price' => 540,
            'product_json' => json_encode([
                ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200],
                ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300],
            ]),
        ]);
    }

    /** What the real form posts for a two-line order: quantity[] and return_price[] for EVERY line. */
    private function twoLinePayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'order_id' => $order->id,
            'product_refund_id' => [502],
            'quantity' => [2, 1],
            'return_price' => ['L1PRICE', 'L2PRICE'],
            'product_sub_total' => 100,
            'refund_reason' => 'Other',
            'custom_refund_reason' => 'x',
        ], $overrides);
    }

    /** Remaining qty per product id, read around every cache. */
    private function linesByProduct(Order $order): array
    {
        $lines = json_decode((string) DB::table('orders')->where('id', $order->id)->value('product_json'), true) ?: [];

        $out = [];
        foreach ($lines as $line) {
            $out[(string) $line['product_id']] = (int) $line['qty'];
        }

        return $out;
    }

    /** The recorded refund lines: product id => units, summed, as the merchant-side consumers read them. */
    private function recordedUnits(): array
    {
        $out = [];
        foreach (DB::table('order_refunds')->pluck('product_refund_id') as $json) {
            foreach ((array) json_decode((string) $json, true) as $entry) {
                $key = (string) ($entry['product_refund_id'] ?? '');
                $out[$key] = ($out[$key] ?? 0) + (int) ($entry['quantity'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * THE INVARIANT E1 BROKE: the units the merchant is told were refunded are the units that left
     * the order. `RefundStock` restocks the recorded number, so a record above the removal is
     * inventory the shopper conjured, and a record below it is stock that is never returned.
     */
    private function assertRecordEqualsWhatWasRemoved(array $linesBefore, Order $order, string $why): void
    {
        $after = $this->linesByProduct($order);
        $removed = [];
        foreach ($linesBefore as $product => $qty) {
            $delta = $qty - ($after[$product] ?? $qty);
            if ($delta !== 0) {
                $removed[$product] = $delta;
            }
        }

        $recorded = array_filter($this->recordedUnits());
        ksort($removed);
        ksort($recorded);

        $this->assertSame($removed, $recorded, $why);
    }

    // =========================================================================================
    //  PREMISES -- without these a refusal below could be a route that does not exist
    // =========================================================================================

    public function test_premise_the_three_routes_exist_behind_the_customer_gate_and_nothing_stronger(): void
    {
        foreach (['order.refund.request', 'order.refund', 'order.return.list'] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains(\App\Http\Middleware\EnsureCustomerAuthenticated::class, $middleware,
                "{$name} is no longer behind the customer gate. P1-SEC-03 round 2 pinned its severity "
                . 'FLOOR on this: an authenticated shopper is the weakest principal that reaches it.');
        }

        // THE CRITIC'S POINT, answered by derivation rather than by grep: nothing between the
        // gate and the handler checks ownership. If a future change adds such middleware, this
        // is the line that should be revisited deliberately.
        $this->assertNotContains('auth:customers', Route::getRoutes()->getByName('order.refund.request')->gatherMiddleware());
    }

    /** The positive control for the whole file, and the premise that makes the others mean something. */
    public function test_the_owner_can_request_a_partial_refund_and_the_record_matches_the_order_written(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);

        $response = $this->refundRequest($owner, $store, $order, $this->payload($order));

        $this->assertNotSame(500, $response->getStatusCode(), 'The legitimate refund request crashed.');
        $response->assertRedirect();

        // 3 of 4 units refunded: the line keeps 1 unit at 400*1/4 = 100, so product_price 100 and
        // final_price 100 + 20 delivery + 20 tax - 0 coupon = 140. This is the behaviour a fix must
        // PRESERVE; it is also what the attack below does to a stranger's order.
        $money = $this->money($order);
        $this->assertSame(140.0, $money['final_price']);
        $this->assertSame(100.0, $money['product_price']);
        $this->assertSame(1, (int) json_decode($money['product_json'], true)[0]['qty']);

        $refunds = DB::table('order_refunds')->get();
        $this->assertCount(1, $refunds);
        $this->assertSame((int) $order->id, (int) $refunds[0]->order_id,
            'The refund RECORD names a different order from the one whose totals were written.');
        $this->assertSame((int) $store->id, (int) $refunds[0]->store_id);

        // The RECORD, not just the order: the merchant restocks what it says.
        $recorded = json_decode((string) $refunds[0]->product_refund_id, true);
        $this->assertCount(1, $recorded);
        $this->assertSame(777, (int) $recorded[0]['product_refund_id']);
        $this->assertSame(3, (int) $recorded[0]['quantity']);
        $this->assertSame('300', (string) $recorded[0]['return_price']);
    }

    // =========================================================================================
    //  THE WRITE -- order_refund_request
    // =========================================================================================

    /** THE REPRODUCED DEFECT, exactly as P1-SEC-03 round 2 measured it. */
    public function test_a_shopper_cannot_rewrite_another_tenants_order_by_naming_it_in_the_body(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $attacker = $this->shopper($storeA);
        $victim = $this->shopper($storeB);

        $own = $this->refundableOrder($storeA, $attacker);
        $victimOrder = $this->refundableOrder($storeB, $victim);

        $beforeVictim = $this->money($victimOrder);
        $beforeOwn = $this->money($own);

        // PATH = the attacker's own order (so a path-only check would pass); BODY = the victim's.
        $response = $this->refundRequest($attacker, $storeA, $own, $this->payload($victimOrder));

        $this->assertNotSame(500, $response->getStatusCode(),
            'The handler crashed, which is not the same as refusing.');
        $this->assertSame($beforeVictim, $this->money($victimOrder),
            'A shopper of store A rewrote the totals of an order in store B.');
        $this->assertSame($beforeOwn, $this->money($own),
            'A request that names two different orders must write NEITHER.');
        $this->assertSame(0, $this->refundRows(), 'A refusal still recorded a refund request.');
        $response->assertNotFound();
    }

    public function test_the_path_id_cannot_name_another_tenants_order(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $attacker = $this->shopper($storeA);
        $victimOrder = $this->refundableOrder($storeB, $this->shopper($storeB));

        $before = $this->money($victimOrder);

        $response = $this->refundRequest($attacker, $storeA, $victimOrder, $this->payload($victimOrder));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($victimOrder),
            'Naming the foreign order in the PATH under the attacker\'s own slug rewrote it.');
        $this->assertSame(0, $this->refundRows());
        $response->assertNotFound();
    }

    /** The slug is caller-supplied too: pointing it at the victim's store must not help. */
    public function test_the_victims_own_slug_does_not_make_the_order_the_attackers_to_write(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $attacker = $this->shopper($storeA);
        $victimOrder = $this->refundableOrder($storeB, $this->shopper($storeB));

        $before = $this->money($victimOrder);

        $response = $this->refundRequest($attacker, $storeB, $victimOrder, $this->payload($victimOrder));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($victimOrder),
            'A shopper of store A rewrote an order by addressing store B\'s slug. The order\'s store '
            . 'matches the slug there, so only the CUSTOMER predicate can refuse this.');
        $this->assertSame(0, $this->refundRows());
        $response->assertNotFound();
    }

    /**
     * THE STORE PREDICATE'S OWN TEST. For a cross-tenant attacker it is SUBSUMED by the customer
     * predicate -- a customer belongs to exactly one store -- and a control that no test can
     * isolate is a control nobody will notice being deleted. This is the case that isolates it: a
     * shopper addressing their OWN order under ANOTHER store's slug. Without the store predicate
     * the order is found by customer alone, rewritten, and the refund RECORD is stamped with the
     * slug's store (`$order_refund->store_id = $store->id`) -- so it lands in a different merchant's
     * refund queue against an order that merchant never sold.
     */
    public function test_an_own_order_addressed_under_another_stores_slug_writes_and_records_nothing(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $shopper = $this->shopper($storeA);
        $own = $this->refundableOrder($storeA, $shopper);

        $before = $this->money($own);

        $response = $this->refundRequest($shopper, $storeB, $own, $this->payload($own));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($own),
            'An order was rewritten through a slug that is not its store\'s.');
        $this->assertSame(0, $this->refundRows(),
            'A refund request was recorded in a store that never sold the order.');
        $response->assertNotFound();
    }

    /** Same store, different shopper: a tenant predicate alone cannot catch this one. */
    public function test_a_shopper_cannot_refund_a_sibling_shoppers_order_in_the_same_store(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $sibling = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);

        $before = $this->money($order);

        $response = $this->refundRequest($sibling, $store, $order, $this->payload($order));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($order),
            'One shopper rewrote another shopper\'s order within the same store.');
        $this->assertSame(0, $this->refundRows());
        $response->assertNotFound();
    }

    /**
     * THE CRITIC'S MISSING PROBE, now driven: a session-less POST. Round 2 inferred the gate from
     * `grep`; this is the request that settles whether the floor is "authenticated shopper" or
     * "anyone on the internet".
     */
    public function test_an_anonymous_post_is_refused_and_writes_nothing(): void
    {
        [, $store] = $this->makeTenant();
        $order = $this->refundableOrder($store, $this->shopper($store));

        $before = $this->money($order);

        $response = $this->post('/' . $store->slug . '/order-refund-request/' . $order->id, $this->payload($order));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($order), 'An ANONYMOUS caller rewrote an order\'s totals.');
        $this->assertSame(0, $this->refundRows());
        $response->assertRedirect();
    }

    // -- INTEGRITY OF THE LEGITIMATE PATH -----------------------------------------------------
    //
    // These are not cross-tenant tests. They are the replay primitive P1-SEC-03's verdict recorded
    // but did not drive: the totals are recomputed from the CURRENT row on every call, so a repeat
    // shrinks them again, and the `/ $old_qty` divides by zero once a line reaches 0.

    public function test_a_quantity_above_the_lines_remaining_quantity_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);
        $before = $this->money($order);

        $response = $this->refundRequest($owner, $store, $order, $this->payload($order, ['quantity' => [5]]));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame($before, $this->money($order),
            'A refund of 5 units from a 4-unit line was applied. The form\'s own max is the line qty.');
        $this->assertSame(0, $this->refundRows());
        $response->assertSessionHas('error');
    }

    public function test_a_negative_or_non_numeric_quantity_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);
        $before = $this->money($order);

        foreach ([[-2], ['abc'], [1.5], [''], ['1e400']] as $bad) {
            $response = $this->refundRequest($owner, $store, $order, $this->payload($order, ['quantity' => $bad]));

            $this->assertNotSame(500, $response->getStatusCode(), 'quantity ' . json_encode($bad) . ' crashed.');
            $this->assertSame($before, $this->money($order),
                'quantity ' . json_encode($bad) . ' changed the order. A negative refund INFLATES the total.');
        }

        $this->assertSame(0, $this->refundRows());
    }

    /**
     * A POSITIVE CONTROL FOR THE VALIDATOR ITSELF. A number input returns exactly what was typed,
     * so a shopper entering `03` posts the string "03". `FILTER_VALIDATE_INT` rejects that, which
     * would have refused a legitimate refund; the check is is_numeric() plus a whole-number test.
     */
    public function test_a_quantity_typed_with_a_leading_zero_is_still_accepted(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);

        $response = $this->refundRequest($owner, $store, $order, $this->payload($order, ['quantity' => ['03']]));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(140.0, $this->money($order)['final_price'],
            'A valid refund of "03" units was refused or mis-applied.');
        $this->assertSame(1, $this->refundRows());
    }

    /** THE REPLAY: a full refund twice must not push the order below what it can be. */
    public function test_a_replayed_full_refund_is_refused_rather_than_shrinking_the_order_again(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);

        $first = $this->refundRequest($owner, $store, $order, $this->payload($order, ['quantity' => [4]]));
        $this->assertNotSame(500, $first->getStatusCode(), 'The first full refund crashed.');

        // The line is now empty: product_price 0, final_price 0 + 20 + 20 = 40.
        $afterFirst = $this->money($order);
        $this->assertSame(0.0, $afterFirst['product_price']);
        $this->assertSame(40.0, $afterFirst['final_price']);

        $second = $this->refundRequest($owner, $store, $order, $this->payload($order, ['quantity' => [4]]));

        $this->assertNotSame(500, $second->getStatusCode(),
            'The replay crashed -- `/ $old_qty` with a zero quantity is a DivisionByZeroError.');
        $this->assertSame($afterFirst, $this->money($order),
            'A replayed full refund changed the order again. Totals are recomputed from the CURRENT row, '
            . 'so without a ceiling each replay shrinks them further and quantities go negative.');
        $this->assertSame(1, $this->refundRows(), 'The refused replay still recorded a second request.');
    }

    public function test_a_zero_quantity_line_does_not_divide_by_zero(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner, [
            'product_json' => json_encode([
                ['product_id' => 777, 'variant_id' => 0, 'qty' => 0, 'final_price' => 0],
                ['product_id' => 888, 'variant_id' => 0, 'qty' => 2, 'final_price' => 200],
            ]),
            'product_price' => 200,
            'final_price' => 240,
        ]);

        // Refund 1 of the 2 units of product 888 while product 777's line sits at qty 0.
        $response = $this->refundRequest($owner, $store, $order, [
            'order_id' => $order->id,
            'product_refund_id' => [888],
            'quantity' => [0, 1],
            'return_price' => [0, 100],
            'product_sub_total' => 100,
            'refund_reason' => 'Other',
            'custom_refund_reason' => 'x',
        ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'A line already at qty 0 made the whole request divide by zero.');
        $response->assertRedirect();
        $response->assertSessionMissing('error');

        // Not merely "did not crash": 1 of product 888's 2 units left the order (200 -> 100), the
        // empty 777 line stayed empty, and the record names 888 -- not the zero in quantity[0].
        $this->assertSame(['777' => 0, '888' => 1], $this->linesByProduct($order));
        $this->assertSame(140.0, $this->money($order)['final_price']);
        $this->assertSame(['888' => 1], $this->recordedUnits());
    }

    // =========================================================================================
    //  THE READS -- order_return_list and the order_refund refund-view branch
    // =========================================================================================

    /** M-7 (review): a shopper with NO orders compiles `whereIn('order_id', [])`. It must be an empty list, not a crash. */
    public function test_a_shopper_with_no_orders_gets_an_empty_return_list(): void
    {
        [, $store] = $this->makeTenant();
        $newcomer = $this->shopper($store);
        $other = $this->shopper($store);
        $theirs = $this->refundableOrder($store, $other, ['product_order_id' => 'NOTYOURS5501']);
        $this->seedRefund($store, $theirs, 'NOTYOURREASON5501');

        $response = $this->actingAs($newcomer, 'customers')->get('/' . $store->slug . '/order-return-list');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('NOTYOURS5501', (string) $response->getContent());
        $this->assertStringNotContainsString('NOTYOURREASON5501', (string) $response->getContent());
    }

    /** `order_return_list` filtered by STORE and never by CUSTOMER. */
    public function test_the_return_list_shows_a_shopper_only_their_own_refund_requests(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $sibling = $this->shopper($store);

        $ownOrder = $this->refundableOrder($store, $owner, ['product_order_id' => 'OWNMARKER4821']);
        $siblingOrder = $this->refundableOrder($store, $sibling, ['product_order_id' => 'SIBMARKER9377']);

        $this->seedRefund($store, $ownOrder, 'ownreasonmarker');
        $this->seedRefund($store, $siblingOrder, 'sibreasonmarker');

        $response = $this->actingAs($owner, 'customers')->get('/' . $store->slug . '/order-return-list');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertOk();
        $html = (string) $response->json('html');

        // POSITIVE FIRST: the owner's own request is listed, or the absence below proves nothing.
        $this->assertStringContainsString('OWNMARKER4821', $html, 'The owner\'s own refund request is not listed.');
        $this->assertStringNotContainsString('SIBMARKER9377', $html,
            'A shopper can list another shopper\'s refund requests in the same store.');
        $this->assertStringNotContainsString('sibreasonmarker', $html);
    }

    public function test_the_return_list_does_not_cross_tenants(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $a = $this->shopper($storeA);
        $b = $this->shopper($storeB);

        $this->seedRefund($storeA, $this->refundableOrder($storeA, $a, ['product_order_id' => 'AMARK1111']), 'a');
        $this->seedRefund($storeB, $this->refundableOrder($storeB, $b, ['product_order_id' => 'BMARK2222']), 'b');

        $html = (string) $this->actingAs($a, 'customers')
            ->get('/' . $storeA->slug . '/order-return-list')->json('html');

        $this->assertStringContainsString('AMARK1111', $html);
        $this->assertStringNotContainsString('BMARK2222', $html);
    }

    /**
     * The refund-VIEW branch: `OrderRefund::find($id)` is store-scoped, not customer-scoped.
     *
     * THE IDS COINCIDE ON PURPOSE, and that is the attack rather than an accident of the fixture.
     * `OrderDocumentAccessGuard` resolves `{id}` for this URI as `orders.id` whether or not
     * `?refund=true` is present, so on this branch -- where `{id}` is a REFUND id -- it asks "does
     * order #<refundId> belong to me?", which is the wrong question. An attacker chooses the refund
     * id, and owns several order ids, so they simply pick a refund id equal to one of their own
     * orders. Here the owner's order is #1 and the sibling's refund is #1.
     */
    public function test_a_shopper_cannot_open_another_shoppers_refund_request_even_when_the_ids_coincide(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $sibling = $this->shopper($store);

        $ownersOrder = $this->refundableOrder($store, $owner);                                  // orders.id = 1
        $siblingOrder = $this->refundableOrder($store, $sibling, ['product_order_id' => 'SIBVIEW5512']);
        $siblingRefund = $this->seedRefund($store, $siblingOrder, 'x');                         // order_refunds.id = 1

        $this->assertSame((int) $ownersOrder->id, $siblingRefund,
            'Fixture premise: the sibling\'s REFUND id must equal an order id the attacker owns, or '
            . 'the guard denies this for its own reason and the handler is never reached.');

        $response = $this->actingAs($owner, 'customers')
            ->get('/' . $store->slug . '/order-refund/' . $siblingRefund);

        $this->assertNotSame(500, $response->getStatusCode(), 'The refund view crashed instead of refusing.');
        $this->assertStringNotContainsString('SIBVIEW5512', (string) $response->getContent(),
            'A shopper opened another shopper\'s refund request.');
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    /** POSITIVE CONTROL, with the ids arranged so the guard passes: the owner opens their own request. */
    public function test_an_owner_can_open_their_own_refund_request(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);

        $order = $this->refundableOrder($store, $owner, ['product_order_id' => 'OWNVIEW3310']);   // orders.id = 1
        $refund = $this->seedRefund($store, $order, 'mine');                                      // order_refunds.id = 1

        $this->assertSame((int) $order->id, $refund, 'Fixture premise: ids coincide so the guard admits the owner.');

        $response = $this->actingAs($owner, 'customers')->get('/' . $store->slug . '/order-refund/' . $refund);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('OWNVIEW3310', (string) $response->getContent(),
            'The owner cannot open their own refund request -- the fix refuses everyone.');
    }

    /**
     * REPAIRED (review D-1). The first version GET /order-refund/987654 and asserted `!== 500`.
     * It could not fail: `OrderDocumentAccessGuard` resolves `{id}` as `orders.id`, finds no order
     * 987654 and answers 403 before the handler runs, so reverting the null-dereference fix left the
     * test green. To REACH the handler the id must equal an order the caller owns -- here order #1
     * -- while no refund row #1 exists. Then the only thing standing between the request and
     * `$order_refunds->order_id` on null is the fix, and the answer is exactly 404.
     */
    public function test_an_unknown_refund_id_is_a_404_not_a_null_dereference(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->refundableOrder($store, $owner);     // orders.id = 1; there is NO order_refunds row

        $this->assertSame(0, $this->refundRows(), 'Fixture premise: no refund row may exist.');

        $response = $this->actingAs($owner, 'customers')
            ->get('/' . $store->slug . '/order-refund/' . $order->id);

        $this->assertSame(404, $response->getStatusCode(),
            '`OrderRefund::find($id)->order_id` on null: an owned-order id with no refund behind it must be a 404, '
            . 'and a 403 would mean the guard answered and the handler was never reached.');
    }

    // =========================================================================================
    //  THE RECORD -- E1: the quantity recorded is the quantity that was bounded and applied
    // =========================================================================================
    //
    // Review lens E, BLOCKING. The bound loop read `$quantities[$line]` (a LINE position); the
    // refund record read `$request->quantity[$tick]` (a position in the list of TICKED boxes). The
    // real form posts `quantity[]` and `return_price[]` for every line and a checkbox for only the
    // ticked ones, so with one line ticked out of two the record carried the OTHER line's quantity,
    // and a hostile client could put any number in the slot the bound never looked at.
    // `OrderRefundController::RefundStock` restocks `(int) $product_refund['quantity']`.

    public function test_the_record_carries_the_ticked_lines_quantity_not_the_first_lines(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        // Tick ONLY Beta (502). Alpha's quantity input still posts its default, 2.
        $response = $this->refundRequest($owner, $store, $order, $this->twoLinePayload($order));

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertRedirect();
        $response->assertSessionMissing('error');

        // 1 of Beta's 3 units: 300 * 2/3 = 200. Alpha untouched at 200. 400 + 20 + 20 = 440.
        $this->assertSame(['501' => 2, '502' => 2], $this->linesByProduct($order));
        $this->assertSame(440.0, $this->money($order)['final_price']);

        $record = json_decode((string) DB::table('order_refunds')->value('product_refund_id'), true);
        $this->assertCount(1, $record);
        $this->assertSame(502, (int) $record[0]['product_refund_id']);
        $this->assertSame(1, (int) $record[0]['quantity'],
            'The record carries quantity[0] -- ALPHA\'s 2 -- for a refund of one unit of BETA.');
        $this->assertSame('L2PRICE', (string) $record[0]['return_price'],
            'The record carries return_price[0] -- the other line\'s price.');
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'The recorded units are not the removed units.');
    }

    /** THE INFLATION: a number placed in the slot the bound never read. */
    public function test_a_number_in_an_unticked_lines_slot_cannot_be_recorded_as_a_refund(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        // quantity[0] belongs to Alpha, which is NOT ticked, so it is nobody's refund. The old
        // record read it for Beta (tick index 0) and stored 9999.
        $response = $this->refundRequest($owner, $store, $order,
            $this->twoLinePayload($order, ['quantity' => [9999, 1]]));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(['502' => 1], $this->recordedUnits(),
            'A quantity typed into an unticked line\'s box reached the refund record, where RefundStock restocks it.');
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'The recorded units are not the removed units.');
    }

    public function test_a_selected_product_that_is_not_on_the_order_is_refused_and_nothing_is_applied(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->money($order);

        // A product on no line of this order; and the same id ALONGSIDE a real one, where the
        // danger is a half-applied request.
        foreach ([[999999], [502, 999999]] as $ticked) {
            $response = $this->refundRequest($owner, $store, $order,
                $this->twoLinePayload($order, ['product_refund_id' => $ticked]));

            $this->assertNotSame(500, $response->getStatusCode());
            $response->assertSessionHas('error');
            $this->assertSame($before, $this->money($order),
                'A refund naming product ' . json_encode($ticked) . ' was applied. The record would carry a '
                . 'product id that is on no line of this order, and RefundStock restocks it.');
        }

        $this->assertSame(0, $this->refundRows());
    }

    public function test_a_ticked_line_with_no_quantity_is_refused_not_crashed_after_the_write(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->money($order);

        $body = $this->twoLinePayload($order);
        unset($body['quantity']);

        $response = $this->refundRequest($owner, $store, $order, $body);

        $this->assertNotSame(500, $response->getStatusCode(),
            'An omitted quantity[] crashed the handler -- AFTER $order->save() had already shrunk the order.');
        $response->assertSessionHas('error');
        $this->assertSame($before, $this->money($order));
        $this->assertSame(0, $this->refundRows());
    }

    public function test_a_request_that_refunds_no_units_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->money($order);

        $response = $this->refundRequest($owner, $store, $order, $this->twoLinePayload($order, ['quantity' => [2, 0]]));

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHas('error');
        $this->assertSame($before, $this->money($order));
        $this->assertSame(0, $this->refundRows(), 'A zero-unit "refund" was recorded for the merchant to action.');
    }

    /** POSITIVE CONTROL for the next test: two ticked lines, each recorded with its OWN figures, in line order. */
    public function test_two_ticked_lines_are_each_recorded_with_their_own_quantity_and_price(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        $response = $this->refundRequest($owner, $store, $order,
            $this->twoLinePayload($order, ['product_refund_id' => [501, 502], 'quantity' => [1, 2]]));

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionMissing('error');

        $record = json_decode((string) DB::table('order_refunds')->value('product_refund_id'), true);
        $this->assertCount(2, $record);
        $this->assertSame([501, 502], [(int) $record[0]['product_refund_id'], (int) $record[1]['product_refund_id']]);
        $this->assertSame([1, 2], [(int) $record[0]['quantity'], (int) $record[1]['quantity']]);
        $this->assertSame(['L1PRICE', 'L2PRICE'], [(string) $record[0]['return_price'], (string) $record[1]['return_price']]);
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'Two ticked lines: record and order disagree.');
    }

    /**
     * EVERY ticked line carries its own valid quantity -- not merely "the total is positive".
     * The first pass at this fix refused a request only when the SUM was not positive, which made
     * each per-line control unreachable in a one-line request (a lone -2 sums to a refusal anyway).
     * Review's removal check showed it: dropping the lower bound, the is_numeric test, or the
     * "missing entry" rule left every test green. With two ticked lines one valid quantity props the
     * sum up, so each bad one must be refused for its OWN reason. A negative one is the dangerous
     * kind: Beta 3 - (-1) = 4 units, and a larger line total.
     */
    public function test_every_ticked_line_must_carry_its_own_valid_quantity(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->money($order);

        $bad = [
            'a non-numeric second quantity' => [1, 'abc'],
            'a negative second quantity' => [2, -1],
            'a blank second quantity' => [1, ''],
            'a second quantity that is missing' => [1],
            'a second quantity above the line' => [1, 4],
            'a fractional second quantity' => [1, 1.5],
        ];

        foreach ($bad as $label => $quantity) {
            $response = $this->refundRequest($owner, $store, $order,
                $this->twoLinePayload($order, ['product_refund_id' => [501, 502], 'quantity' => $quantity]));

            $this->assertNotSame(500, $response->getStatusCode(), "{$label}: the handler crashed.");
            $this->assertSame($before, $this->money($order),
                "{$label}: the request was applied. One good quantity must not carry a bad one.");
            $this->assertSame(0, $this->refundRows(), "{$label}: a refund row was recorded.");
        }
    }

    /** The display figures are not required: omitting them used to crash AFTER the order was saved. */
    public function test_omitted_display_prices_are_recorded_blank_and_the_write_stays_consistent(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        $body = $this->twoLinePayload($order);
        unset($body['return_price'], $body['product_sub_total']);

        $response = $this->refundRequest($owner, $store, $order, $body);

        $this->assertNotSame(500, $response->getStatusCode(),
            '`$request->return_price[$index]` on null crashed AFTER $order->save() -- the order shrank with no refund row.');
        $this->assertSame(1, $this->refundRows());
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'The order shrank by a different amount than was recorded.');
    }

    /**
     * Shape hardening (review F4 / E-shape). Each of these used to raise "Array to string
     * conversion" or a TypeError, and four of them did so AFTER `$order->save()`. The assertion is
     * the ATOMIC one: whatever the status, the order changed if and only if a refund row exists.
     */
    public function test_array_shaped_fields_cannot_leave_the_order_changed_without_a_refund_row(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);

        $cases = [
            'order_id as array' => ['order_id' => [1]],
            'refund_reason as array' => ['refund_reason' => ['a', 'b']],
            'custom_refund_reason as array' => ['custom_refund_reason' => ['a']],
            'product_sub_total as array' => ['product_sub_total' => [1, 2]],
            'return_price entry as array' => ['return_price' => [['x'], ['y']]],
            'quantity as scalar' => ['quantity' => '1'],
            'product_refund_id as scalar' => ['product_refund_id' => '502'],
            'product_refund_id nested' => ['product_refund_id' => [[502]]],
        ];

        foreach ($cases as $label => $override) {
            $order = $this->twoLineOrder($store, $owner);
            $before = $this->money($order);
            $rowsBefore = $this->refundRows();

            $response = $this->refundRequest($owner, $store, $order, $this->twoLinePayload($order, $override));

            $this->assertNotSame(500, $response->getStatusCode(), "{$label}: the handler crashed.");

            $changed = $this->money($order) !== $before;
            $recorded = $this->refundRows() > $rowsBefore;
            $this->assertSame($changed, $recorded,
                "{$label}: the order " . ($changed ? 'changed' : 'did not change') . ' but ' . ($recorded ? 'a' : 'no')
                . ' refund row was written. That is the half-write: a merchant cannot cancel what was never recorded.');
        }
    }

    /** A scalar `product_refund_id=502` is a single tick, as `(array)` always meant on the first loop. */
    public function test_a_scalar_product_refund_id_is_one_tick_not_a_type_error(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        $response = $this->refundRequest($owner, $store, $order,
            $this->twoLinePayload($order, ['product_refund_id' => '502']));

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(['502' => 1], $this->recordedUnits());
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'Scalar tick: record and order disagree.');
    }

    /**
     * F3 (review) -- MEASURED, AND THE REVIEWER'S FAILURE MODE DID NOT REPRODUCE. `json_encode()`
     * returns FALSE on invalid UTF-8 rather than throwing, and the concern was that `return_price[]`
     * (shopper text) could make the record encode to false while the order, committed in the same
     * transaction, stayed shrunk. In fact `mb_substr()` SUBSTITUTES '?' for invalid bytes, so the
     * display strings reach json_encode valid and this test passes against the code as it stood. It is
     * kept as a characterization that bites if `$display` ever stops scrubbing (a byte-wise substr()
     * makes it fail), and both encodes now also carry JSON_THROW_ON_ERROR so a future failure rolls
     * the transaction back instead of storing `false`. THAT flag cannot be isolated by any request
     * today, and is recorded as such rather than claimed.
     *
     * The store is given no theme because `ActiveTheme` otherwise runs `visitor()->visit($store)`,
     * which JSON-encodes the whole request into shetabit_visits and answers ANY storefront request
     * carrying invalid UTF-8 with a 500 before the handler runs (P1-SEC-13).
     */
    public function test_invalid_utf8_in_a_display_field_cannot_corrupt_the_record(): void
    {
        [, $store] = $this->makeTenant();

        // A store with NO theme skips `ActiveTheme`'s `visitor()->visit($store)`, which JSON-encodes the
        // whole request into shetabit_visits and so answers ANY storefront request carrying invalid
        // UTF-8 with a 500 before the handler runs (recorded under P1-SEC-13). Without this the request
        // never reaches the code under test.
        Store::where('id', $store->id)->update(['theme_id' => '']);

        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->linesByProduct($order);

        $response = $this->refundRequest($owner, $store, $order,
            $this->twoLinePayload($order, ['return_price' => ['L1PRICE', "\xFF\xFE" . 'L2'], 'product_sub_total' => "\xC3\x28"]));

        $this->assertNotSame(500, $response->getStatusCode());

        $raw = DB::table('order_refunds')->value('product_refund_id');
        $record = json_decode((string) $raw, true);
        $this->assertIsArray($record,
            'The refund record was stored as ' . var_export($raw, true) . ' -- json_encode() failed on invalid UTF-8 '
            . 'and returned false, while the order itself stayed shrunk.');
        $this->assertSame(1, (int) $record[0]['quantity']);
        $this->assertRecordEqualsWhatWasRemoved($before, $order, 'Invalid UTF-8: record and order disagree.');
    }

    /**
     * F2 (review). The order is read by `ownedOrder()` BEFORE the transaction, and the bounds and
     * totals are computed from that unlocked row. Two identical requests passing together both
     * clear the bound against the same snapshot: the order is written once (last write wins) but two
     * refund rows each record the units. The write now re-reads the row `FOR UPDATE` inside the
     * transaction and refuses if `product_json` is no longer what the request was computed from.
     *
     * The concurrent writer is SIMULATED, deterministically: a query listener fires on the handler's
     * own order read and commits another refund's effect (Beta 3 -> 1) before the handler writes.
     * SQLite ignores `FOR UPDATE`, so what this pins is the comparison; on MySQL the lock makes the
     * second request WAIT and then fail that same comparison.
     */
    public function test_a_concurrent_writer_between_the_read_and_the_write_is_not_overwritten(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);

        $concurrent = json_encode([
            ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200],
            ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100],
        ]);

        $fired = false;
        DB::listen(function ($query) use (&$fired, $order, $concurrent) {
            // The handler's own three-column read (id + store + customer), not the guard's earlier one.
            if ($fired || ! str_starts_with($query->sql, 'select * from "orders" where "id" = ? and "store_id" = ? and "customer_id" = ?')) {
                return;
            }

            $fired = true;
            DB::table('orders')->where('id', $order->id)
                ->update(['product_json' => $concurrent, 'product_price' => 300, 'final_price' => 340]);
        });

        $response = $this->refundRequest($owner, $store, $order, $this->twoLinePayload($order));

        $this->assertTrue($fired, 'Fixture premise: the listener must fire on the handler\'s own ownedOrder() read.');
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHas('error');

        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame($concurrent, $row->product_json,
            'The request overwrote what a concurrent refund had just committed, computed from a snapshot that no longer existed.');
        $this->assertSame(340.0, (float) $row->final_price);
        $this->assertSame(0, $this->refundRows(), 'A refund row was recorded against a stale snapshot.');
    }

    /**
     * ATOMICITY. `$order->save()` ran first and the refund row second, with nothing joining them:
     * a failure on the second left the order shrunk and nothing for the merchant to cancel. The
     * failure here is a real one -- a database trigger that rejects the INSERT -- rather than a
     * model event, because a listener registered on a Cachable model would leak into every later
     * test in the process. SQLite DDL is transactional, so the trigger is gone with the test's own
     * rollback; the finally block drops it anyway.
     */
    public function test_a_failure_recording_the_refund_leaves_the_order_untouched(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName(),
            'This test creates a SQLite trigger. A different engine needs its own failure injection; '
            . 'do not turn this into a skip, the gate declares its skips by name.');

        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);
        $order = $this->twoLineOrder($store, $owner);
        $before = $this->money($order);

        DB::unprepared("CREATE TRIGGER reject_refund_insert BEFORE INSERT ON order_refunds "
            . "BEGIN SELECT RAISE(ABORT, 'simulated failure recording the refund'); END");

        try {
            $response = $this->refundRequest($owner, $store, $order, $this->twoLinePayload($order));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_refund_insert');
        }

        $this->assertNotSame(500, $response->getStatusCode(),
            'The failure escaped as a server error instead of a handled refusal.');
        $response->assertSessionHas('error');
        $this->assertSame($before, $this->money($order),
            'The order was shrunk and then the refund row failed: the order and its record were saved '
            . 'separately, so a failure between them leaves a reduced order with nothing to cancel.');
        $this->assertSame(0, $this->refundRows());
    }
}

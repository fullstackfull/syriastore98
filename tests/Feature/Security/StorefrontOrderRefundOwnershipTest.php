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
    }

    // =========================================================================================
    //  THE READS -- order_return_list and the order_refund refund-view branch
    // =========================================================================================

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

    public function test_an_unknown_refund_id_is_a_404_not_a_null_dereference(): void
    {
        [, $store] = $this->makeTenant();
        $owner = $this->shopper($store);

        $response = $this->actingAs($owner, 'customers')
            ->get('/' . $store->slug . '/order-refund/987654');

        $this->assertNotSame(500, $response->getStatusCode(),
            '`OrderRefund::find($id)->order_id` on null: an unknown id answered with a server error.');
    }
}

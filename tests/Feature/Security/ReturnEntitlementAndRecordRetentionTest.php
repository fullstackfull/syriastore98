<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\OrderRefundController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RET-001, RET-005, RET-006 AND RET-007 — the four `RET-*` decisions whose machinery already
 * exists, measured end to end against the code rather than against a summary.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The seven `RET-*` decisions of the plan's §04-F appear in **neither**
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` **nor** `MASTER-PLAN-GAP-REGISTER.md` — measured by
 * grep over both on 2026-11-26 — so none of them has ever been tracked. Returns and refunds are
 * nevertheless among the most heavily worked areas of this codebase, and most of what these four
 * decisions ask for is already built AND already asserted. Where that is so, this file does NOT
 * duplicate the assertion; the accompanying report names the existing test instead. What is here
 * is only what nothing else measures:
 *
 *   `RET-007` — *"الاحتفاظ بالطلب الأصلي وسجل البيع دون محوه"* (keep the original order and sales
 *               record without erasing it). **Nothing asserted this at all.** It is a negative
 *               constraint: cheap to honour, silent when violated, and a return path that deleted
 *               or rewrote the original would be live data loss against an approved decision.
 *
 *   `RET-005` — *"منع رد مبلغ أو كمية تتجاوز الاستحقاق"* (prevent refunding an amount or quantity
 *               beyond entitlement). The QUANTITY half is enforced and asserted. The AMOUNT half
 *               is capped at the ORDER TOTAL — **not at the value of the returned goods** — and
 *               that gap is an owner question about money, so the present behaviour is PINNED
 *               here rather than changed.
 *
 *   `RET-001` — *"مرتجعات كاملة وجزئية"*. The partial path is asserted elsewhere in detail; the
 *               one case here is the FULL return, end to end, because it is also what RET-007's
 *               retention claim has to survive.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT RET-007 TURNS OUT TO MEAN HERE, measured and not assumed
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * No return path deletes an order. `OrderController::destroy()` does, but that is the separate
 * `Delete Order` permission and not a return.
 *
 * The original SALES FIGURES, however, are overwritten in place:
 * `AccountProfileController::order_refund_request()` decrements each line's `qty` and prorates its
 * `final_price` down, and `OrderRefundController::RefundAmonut()` rewrites `orders.product_price`
 * and `orders.final_price` from the reduced lines on the `Refunded` transition. **Nothing stores
 * the original total.** What makes RET-007 satisfiable is that the same writer stamps two
 * per-line counters — `refunded_qty` and `refunded_value` — so the original is RECONSTRUCTABLE:
 *
 *       original qty   = qty + refunded_qty
 *       original value = final_price + refunded_value
 *
 * That is retention by reconstruction, not by retention, and the distinction matters: a change
 * that stopped stamping either counter would erase the sales record while every existing test
 * stayed green. `refunded_qty` is asserted by
 * `RefundRestoreByProductTest::test_the_storefront_request_records_what_each_line_lost_so_that_qty_plus_lost_is_the_original`;
 * **`refunded_value` appears in no assertion anywhere** — only as a hand-written fixture value in
 * three files — so the VALUE half of the reconstruction is what this file pins.
 */
class ReturnEntitlementAndRecordRetentionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | RET-007 — the original order and its sales record survive a FULL return
    |--------------------------------------------------------------------------
    */

    public function test_a_full_return_leaves_the_original_order_row_and_its_identity_intact(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $identityBefore = DB::table('orders')->where('id', $order->id)
            ->first(['product_order_id', 'order_date', 'customer_id', 'store_id', 'payment_type']);

        /* RET-001, the FULL half: every line, every unit. */
        $this->requestRefund($shopper, $store, $order, [501, 502], [3, 2])->assertStatus(302);
        $this->settle($order, $this->refundId(), '540');

        $this->assertSame(
            1,
            DB::table('orders')->where('id', $order->id)->count(),
            'RET-007: a full return must not delete the original order. Asserted RAW, because '
                . '`Order` carries no StoreScope and a scoped read would answer about whichever '
                . 'tenant happened to be resolved rather than about the table.'
        );

        $identityAfter = DB::table('orders')->where('id', $order->id)
            ->first(['product_order_id', 'order_date', 'customer_id', 'store_id', 'payment_type']);

        $this->assertEquals(
            $identityBefore,
            $identityAfter,
            'RET-007: the order number, the date it was placed, the customer it belongs to, its '
                . 'store and how it was paid are the sales record. A return must not rewrite any '
                . 'of them.'
        );
    }

    public function test_a_full_return_leaves_every_line_identity_on_the_order(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501, 502], [3, 2])->assertStatus(302);
        $this->settle($order, $this->refundId(), '540');

        $lines = $this->lines($order);

        $this->assertCount(
            2,
            $lines,
            'RET-007: a fully returned line must stay ON the order. Dropping it would erase what '
                . 'was sold, and `Order::product_return()` and the restock clamp both read these '
                . 'lines as their evidence.'
        );
        $this->assertSame([501, 502], array_map(static fn ($l) => (int) $l['product_id'], $lines));
        $this->assertSame(
            ['Alpha', 'Beta'],
            array_map(static fn ($l) => $l['name'], $lines),
            'the product names as sold are part of the record too — the catalogue can be renamed'
        );
    }

    public function test_the_original_quantity_and_value_of_every_line_remain_reconstructable(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        /* A PARTIAL return, because that is the case where the counters have to carry the
           difference — a full return leaves qty 0 and final_price 0 with nothing else to read. */
        $this->requestRefund($shopper, $store, $order, [501], [1, 0])->assertStatus(302);

        $lines = $this->lines($order);

        /*
         * THE IDENTITY RET-007 REALLY RESTS ON. The fixture sold 3 Alpha for 300.00; one unit came
         * back. Nothing stores "3" or "300.00" any more — `qty` is 2 and `final_price` is 200.00 —
         * so the only thing that keeps the sales record is the pair of counters.
         */
        $this->assertSame(2, (int) $lines[0]['qty']);
        $this->assertSame(1, (int) $lines[0]['refunded_qty']);
        $this->assertSame(
            3,
            (int) $lines[0]['qty'] + (int) $lines[0]['refunded_qty'],
            'RET-007: qty + refunded_qty must rebuild the original quantity sold'
        );

        $this->assertSame(
            300.0,
            (float) $lines[0]['final_price'] + (float) $lines[0]['refunded_value'],
            'RET-007: final_price + refunded_value must rebuild the original line value. '
                . '`refunded_value` is asserted NOWHERE else in the suite — it appears only as a '
                . 'hand-written fixture value — so without this the VALUE half of the sales record '
                . 'could stop being written while every other test stayed green.'
        );

        $this->assertSame(
            0,
            (int) ($lines[1]['refunded_qty'] ?? 0),
            'and the line nobody returned carries no counter at all, so "0" and "absent" do not '
                . 'become the same claim'
        );
    }

    public function test_the_merchant_side_return_records_the_line_without_reducing_what_was_sold(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $verdict = Order::product_return([
            'product_id' => 501,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);
        $this->assertSame('success', $verdict['status'], $verdict['message'] ?? '');

        $lines = $this->lines($order);

        /*
         * THE OTHER RETURN DOOR KEEPS THE RECORD A DIFFERENT WAY, and that is worth asserting
         * rather than assuming the storefront's shape generalises. `performProductReturn()` does
         * NOT decrement `qty` — it stamps `return = 1` and leaves the quantity and the line value
         * alone — so on this door the original sales figures are retained outright rather than
         * reconstructed.
         */
        $this->assertSame(3, (int) $lines[0]['qty'], 'RET-007: the quantity sold is left alone here');
        $this->assertSame(300.0, (float) $lines[0]['final_price'], 'and so is the line value');
        $this->assertSame(1, (int) $lines[0]['return'], 'the return is recorded as a flag on the line');

        $this->assertSame(
            1,
            DB::table('orders')->where('id', $order->id)->count(),
            'RET-007: and the order itself survives this door too'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RET-005 — the amount half of "beyond entitlement"
    |--------------------------------------------------------------------------
    */

    public function test_the_refund_cap_is_the_whole_order_and_not_the_value_of_the_returned_goods(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        /* ONE unit of Alpha comes back. The goods returned are worth 100.00 of a 540.00 order. */
        $this->requestRefund($shopper, $store, $order, [501], [1, 0])->assertStatus(302);
        $refundId = $this->refundId();

        $recordedValue = (float) DB::table('order_refunds')->where('id', $refundId)->value('product_refund_price');

        $response = $this->settle($order, $refundId, '540');
        $paid = json_decode($response->getContent(), true);

        /*
         * RET-005 GAP, PINNED AS PRESENT BEHAVIOUR. `RefundIntegrityService::cappedRefundAmount()`
         * caps the amount at `orders.final_price`, so the ceiling on a refund for ONE unit of one
         * line is the WHOLE ORDER. Nothing compares the amount against the refund request's own
         * `product_refund_price`, which is the value of what actually came back.
         *
         * This is NOT changed here. Deciding what a merchant may refund for a partial return —
         * goods only, goods plus shipping, or the whole order at their discretion — is a money
         * rule, and the standing escalation rules reserve prices and payments to the owner. The
         * `shiping_deduct` toggle one door along is reserved for exactly the same reason
         * (`RefundPolicy`'s header). So the behaviour is asserted with a control, in the shape
         * `GAP-262` uses: *the decision shows up as a failing test rather than as silence.*
         *
         * **INVERT this case when the owner answers. Do not delete it.**
         */
        $this->assertSame('Refunded', $paid['status'], 'the premise: an offline payment settles');
        $this->assertSame(
            540.0,
            (float) $paid['amount'],
            'RET-005 OWNER QUESTION: a refund of the FULL 540.00 order total was admitted for a '
                . 'return of one unit worth ' . $recordedValue . '. The cap is the order total, '
                . 'not the value of the returned goods.'
        );
        $this->assertFalse(
            (bool) $paid['capped'],
            'and it was not even reported as capped, because 540.00 is exactly the ceiling'
        );
    }

    public function test_an_amount_above_the_whole_order_is_still_reduced_to_it(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501], [1, 0])->assertStatus(302);

        $paid = json_decode($this->settle($order, $this->refundId(), '5000')->getContent(), true);

        /*
         * THE CONTROL for the case above, and the half of RET-005 that IS enforced. Without it the
         * assertion above would be satisfied by a handler that caps at nothing at all, and the two
         * facts would be indistinguishable.
         */
        $this->assertSame(540.0, (float) $paid['amount'], 'the order total is a real ceiling');
        $this->assertTrue((bool) $paid['capped'], 'and exceeding it is reported');
    }

    public function test_a_quantity_beyond_what_the_line_holds_is_refused_outright(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $before = $this->lines($order);

        /* Alpha holds 3 units. Asking for 4 is beyond entitlement. */
        $this->requestRefund($shopper, $store, $order, [501], [4, 0])->assertStatus(302);

        $this->assertEquals(
            $before,
            $this->lines($order),
            'RET-005: the QUANTITY half is a refusal rather than a cap, and it changes nothing. '
                . 'This is what makes the amount question above a question about MONEY only.'
        );
        $this->assertSame(
            0,
            DB::table('order_refunds')->where('order_id', $order->id)->count(),
            'and no refund row is created for a quantity beyond the line'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RET-006 — the bound that makes the amount question a single-row one
    |--------------------------------------------------------------------------
    */

    public function test_one_order_can_carry_only_one_refund_request(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501], [1, 0])->assertStatus(302);
        $this->requestRefund($shopper, $store, $order, [502], [0, 1])->assertStatus(302);

        /*
         * RET-006, AND THE REASON IT MATTERS TO RET-005. `order_refund_request()` refuses when the
         * order already carries a refund row, so the amount cap above is the cap on the ONLY refund
         * that order can have — cumulative over-refund across several rows is not reachable through
         * the product. Measured rather than assumed: it would otherwise be natural to report "each
         * refund is capped at the order total, so N refunds can take N times the order", and that
         * would be a false finding.
         *
         * `RefundRestoreByProductTest` asserts the same count for the same reason; it is repeated
         * here because it is the premise the RET-005 case above rests on, and a premise that lives
         * only in another file is a premise that can be deleted without anyone noticing.
         */
        $this->assertSame(
            1,
            DB::table('order_refunds')->where('order_id', $order->id)->count(),
            'RET-006: a second refund request on one order must be refused'
        );
    }

    public function test_a_settled_refund_cannot_be_settled_a_second_time(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501], [1, 0])->assertStatus(302);
        $refundId = $this->refundId();

        $first = json_decode($this->settle($order, $refundId, '100')->getContent(), true);
        $this->assertSame('Refunded', $first['status']);

        /*
         * RET-006's money half. `Refunded` is terminal in `LEGAL_TRANSITIONS`, and `RefundAmonut()`
         * refuses a terminal row before it reads the amount at all — so a held-down button cannot
         * record a second payout. 422 is the handler's own refusal; a 200 here would be a duplicate.
         *
         * THE SECOND ATTEMPT GOES STRAIGHT TO THE PAYOUT, deliberately. Routing it through
         * `settle()` would be refused by the ACCEPT step instead ("cannot move from Refunded to
         * Accept"), which is also correct but is a different control — and a refusal measured at
         * the wrong step would read as proof of a guard that was never reached. Both bars are real;
         * this case is about the payout's own.
         */
        $second = $this->payOut($order, $refundId, '100');

        $this->assertSame(422, $second->status(), 'RET-006: a settled refund is terminal');
        $this->assertStringContainsString(
            'already Refunded',
            (string) json_decode($second->getContent(), true)['message'],
            'and the refusal names the REASON: a 422 from a missing row and a 422 from the state '
                . 'machine are the same status and opposite facts'
        );
        $this->assertSame(
            100.0,
            (float) DB::table('order_refunds')->where('id', $refundId)->value('refund_amount'),
            'and the stored amount is unchanged by the second attempt'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture — the shapes RefundRestoreByProductTest uses, for the same reasons
    |--------------------------------------------------------------------------
    */

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);
    }

    /**
     * `delivered_status => 1`, because the storefront refuses a refund request for an order that
     * has not been delivered — the D3 eligibility rule — and `makeOrder()` defaults it to 0.
     *
     * 500.00 of goods + 20.00 delivery + 20.00 tax = 540.00, no coupon. A coupon is deliberately
     * left out: `GAP-261` records that a flat coupon over a returned line is its own measured
     * family with its own pinned residual (`ZeroValueReturnCouponTest`), and mixing it in here
     * would make these figures answer that question instead of this one.
     */
    private function order(Store $store, Customer $owner): Order
    {
        return $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'customer_id' => $owner->id,
            'is_guest' => 0,
            'delivered_status' => 1,
            'payment_type' => 'cod',
            'product_price' => 500, 'delivery_price' => 20, 'tax_price' => 20,
            'coupon_price' => 0, 'final_price' => 540,
            'product_json' => json_encode([
                ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300],
                ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200],
            ]),
        ]);
    }

    private function lines(Order $order): array
    {
        return json_decode(
            (string) DB::table('orders')->where('id', $order->id)->value('product_json'),
            true
        );
    }

    private function requestRefund(Customer $as, Store $store, Order $order, array $ids, array $quantities)
    {
        return $this->actingAs($as, 'customers')
            ->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
                'order_id' => $order->id, 'product_refund_id' => $ids, 'quantity' => $quantities,
                'return_price' => array_fill(0, count($quantities), 'P'),
                'product_sub_total' => 100,
                'refund_reason' => 'Other', 'custom_refund_reason' => 'x',
            ]);
    }

    private function refundId(): int
    {
        return (int) DB::table('order_refunds')->max('id');
    }

    /**
     * Settle a refund as the merchant, THROUGH THE HANDLER.
     *
     * CLAUDE.md: *a handler-level check behind an `abort()`ing middleware is invisible to an HTTP
     * test*. `RefundAmonut()`'s cap, its terminal-state refusal and its status resolution are all
     * inside the handler, so that is what is called — and the signed-in principal is the platform
     * operator, which `canManageRefunds()` admits on its operator-first limb (CLAUDE.md:
     * `isAbleTo()` alone REFUSES the platform operator).
     *
     * **IT ACCEPTS FIRST, AND THAT IS NOT FIXTURE CONVENIENCE — IT IS THE WORKFLOW.** A storefront
     * request is born `Processing`, and `LEGAL_TRANSITIONS['Processing']` is `[Accept, Cancel]`:
     * `Processing -> Refunded` is refused outright. Measured, from a first version of this helper
     * that went straight to `RefundAmonut()` and got
     * *"This refund request cannot move from Processing to Refunded."* So **no money moves on any
     * return until a human has approved it**, which is worth recording here because it is the one
     * review step the return workflow already had before `RET-002`'s condition gate existed — and
     * it gates the MONEY, never the STOCK: `RESTOCKABLE_STATES` is `[Accept, Refunded]`, so a
     * merchant who accepts a return can restock it without any settlement at all.
     */
    private function settle(Order $order, int $refundId, string $amount)
    {
        $this->actingAs($this->makePlatformAdmin(['current_store' => $order->store_id]), 'web');

        $accepted = (new OrderRefundController())->updateRefundStatus(
            Request::create('/', 'POST', ['refund_id' => $refundId])
        );
        $this->assertSame(
            200,
            $accepted->status(),
            'the premise: a Processing refund must first be ACCEPTED. ' . $accepted->getContent()
        );

        return $this->payOut($order, $refundId, $amount);
    }

    /** The payout step alone, with no accept before it. */
    private function payOut(Order $order, int $refundId, string $amount)
    {
        $this->actingAs($this->makePlatformAdmin(['current_store' => $order->store_id]), 'web');

        return (new OrderRefundController())->RefundAmonut(
            Request::create('/', 'POST', ['refundId' => $refundId, 'refund_amount' => $amount]),
            $order->id
        );
    }
}

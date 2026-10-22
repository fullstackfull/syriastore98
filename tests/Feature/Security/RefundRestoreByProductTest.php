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
 * P1-ORD-14 -- CANCELLING (OR RESTOCKING) A REFUND READ THE REFUND RECORD BY ORDER-LINE POSITION, SO A PARTIAL REFUND RESTORED THE WRONG LINE.
 * =============================================================================================================================================
 *
 * The record the shopper's request writes (`order_refunds.product_refund_id`) lists the TICKED lines only -- product id, display price,
 * quantity -- in line order. `OrderRefundController::CancelRefundStatus` walked the ORDER's lines and read `$productRefunds[$key]` with
 * `$key` the line's position, so the first ticked entry went onto the FIRST line whatever product it named. Order A (qty 3) + B (qty 2): the
 * shopper returns 1 of B, the merchant rejects the request, and A gets the unit (qty 4) while B stays at 1: an honest shopper and an honest
 * merchant end with an order that is not what it was, and the price recomputation then scales the wrong line.
 *
 * The fix matches by PRODUCT ID, and bounds what it reads. The storefront writer now keeps `refunded_qty` on each line it removes units
 * from (qty + refunded_qty is the quantity the line started with), so Cancel gives back at most what that line lost, a hand-written
 * record cannot inflate an order, and two lines of one product (variants) are told apart by which of them actually lost units.
 * RefundStock clamps its restock by the same counters. A line that predates the counter (no `refunded_qty` key) is matched by product id
 * and given the recorded quantity as before -- recorded as a residual, not claimed bounded.
 *
 * ORACLE: the order row and the stock, read raw. The end-to-end cases drive the REAL storefront writer, then the REAL merchant handler.
 */
class RefundRestoreByProductTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);
    }

    /** A: product 501 qty 3 (300); B: product 502 qty 2 (200). 500 + 20 delivery + 20 tax = 540. */
    private function order(Store $store, Customer $owner, ?array $lines = null): Order
    {
        return $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper(uniqid()), 'customer_id' => $owner->id, 'is_guest' => 0,
            'product_price' => 500, 'delivery_price' => 20, 'tax_price' => 20, 'coupon_price' => 0, 'final_price' => 540,
            'product_json' => json_encode($lines ?? [
                ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300],
                ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200],
            ]),
        ]);
    }

    private function lines(Order $order): array
    {
        return json_decode((string) DB::table('orders')->where('id', $order->id)->value('product_json'), true);
    }

    private function totals(Order $order): array
    {
        $row = DB::table('orders')->where('id', $order->id)->first();

        return [(float) $row->product_price, (float) $row->final_price];
    }

    /** What the real form posts: quantity[] and return_price[] for EVERY line, the ticked ids in product_refund_id[]. */
    private function requestRefund(Customer $as, Store $store, Order $order, array $ids, array $quantities)
    {
        return $this->actingAs($as, 'customers')->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id, 'product_refund_id' => $ids, 'quantity' => $quantities,
            'return_price' => array_fill(0, count($quantities), 'P'), 'product_sub_total' => 100,
            'refund_reason' => 'Other', 'custom_refund_reason' => 'x',
        ]);
    }

    private function refundId(): int
    {
        return (int) DB::table('order_refunds')->max('id');
    }

    private function asOperator(Store $store): void
    {
        $this->actingAs($this->makePlatformAdmin(['current_store' => $store->id]), 'web');
    }

    private function merchantCancel(int $refundId)
    {
        return (new OrderRefundController())->CancelRefundStatus(Request::create('/', 'POST', ['refund_id' => $refundId]));
    }

    private function hand(Order $order, Store $store, string $status, array $record): int
    {
        return (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id, 'refund_status' => $status, 'refund_reason' => 'Other', 'product_refund_id' => json_encode($record),
            'refund_amount' => 0, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // =========================================================================================
    //  THE WRITER KEEPS A COUNTER
    // =========================================================================================

    public function test_the_storefront_request_records_what_each_line_lost_so_that_qty_plus_lost_is_the_original(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [502], [0, 1])->assertStatus(302);

        $lines = $this->lines($order);
        $this->assertSame(3, (int) $lines[0]['qty']);
        $this->assertSame(0, (int) ($lines[0]['refunded_qty'] ?? 0), 'an untouched line is not marked');
        $this->assertSame(1, (int) $lines[1]['qty']);
        $this->assertSame(1, (int) $lines[1]['refunded_qty'], 'the line that lost a unit says so');

        // a second request on the same line accumulates
        $this->requestRefund($shopper, $store, $order, [502], [0, 1])->assertStatus(302);
        $lines = $this->lines($order);
        $this->assertSame([0, 2], [(int) $lines[1]['qty'], (int) $lines[1]['refunded_qty']]);
    }

    // =========================================================================================
    //  CancelRefundStatus: the honest path, end to end
    // =========================================================================================

    public function test_refund_the_second_line_then_reject_restores_exactly_the_original_order(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [502], [0, 1])->assertStatus(302);
        $this->assertSame([400.0, 440.0], $this->totals($order), 'Premise: the request shrank the order.');
        $refund = $this->refundId();

        $this->asOperator($store);
        $response = $this->merchantCancel($refund);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $lines = $this->lines($order);
        $this->assertSame([3, 2], [(int) $lines[0]['qty'], (int) $lines[1]['qty']], 'the unit went back to the line it came from');
        $this->assertSame([300.0, 200.0], [(float) $lines[0]['final_price'], (float) $lines[1]['final_price']]);
        $this->assertSame([500.0, 540.0], $this->totals($order), 'the order is exactly what it was');
        $this->assertSame(0, (int) ($lines[1]['refunded_qty'] ?? 0), 'what was given back is no longer counted as lost');
    }

    public function test_refund_the_first_line_then_reject_restores_the_first_line(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501], [2, 0])->assertStatus(302);
        $this->asOperator($store);
        $this->assertSame(200, $this->merchantCancel($this->refundId())->getStatusCode());

        $lines = $this->lines($order);
        $this->assertSame([3, 2], [(int) $lines[0]['qty'], (int) $lines[1]['qty']]);
        $this->assertSame([500.0, 540.0], $this->totals($order));
    }

    public function test_two_ticked_lines_each_go_back_to_their_own_line(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [501, 502], [1, 2])->assertStatus(302);
        $this->asOperator($store);
        $this->assertSame(200, $this->merchantCancel($this->refundId())->getStatusCode());

        $lines = $this->lines($order);
        $this->assertSame([3, 2], [(int) $lines[0]['qty'], (int) $lines[1]['qty']]);
        $this->assertSame([500.0, 540.0], $this->totals($order));
    }

    /** The cancel button posts the SHOPPER's display subtotal; a fully refunded line must not take its price from it. */
    public function test_a_fully_refunded_line_gets_its_own_value_back_whatever_subtotal_is_posted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);

        $this->requestRefund($shopper, $store, $order, [502], [0, 2])->assertStatus(302);
        $this->assertSame(0, (int) $this->lines($order)[1]['qty'], 'Premise: the line was refunded down to nothing.');
        $refund = $this->refundId();

        $this->asOperator($store);
        $response = (new OrderRefundController())->CancelRefundStatus(Request::create('/', 'POST', ['refund_id' => $refund, 'subtotal' => 999999]));

        $this->assertSame(200, $response->getStatusCode());
        $lines = $this->lines($order);
        $this->assertSame(200.0, (float) $lines[1]['final_price'], 'the restored price came from the posted subtotal');
        $this->assertSame([500.0, 540.0], $this->totals($order));
    }

    // =========================================================================================
    //  CancelRefundStatus: a record cannot inflate the order
    // =========================================================================================

    public function test_a_record_larger_than_what_the_line_lost_is_refused_and_changes_nothing(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper, [
            ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300],
            ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100, 'refunded_qty' => 1],
        ]);
        $before = DB::table('orders')->where('id', $order->id)->first();
        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 502, 'return_price' => 'x', 'quantity' => 50]]);

        $this->asOperator($store);
        $response = $this->merchantCancel($refund);

        $this->assertSame(422, $response->getStatusCode());
        $after = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame($before->product_json, $after->product_json, 'an inflated record grew the order');
        $this->assertSame((float) $before->final_price, (float) $after->final_price);
        $this->assertSame('Processing', DB::table('order_refunds')->where('id', $refund)->value('refund_status'), 'and the request was not marked cancelled');
    }

    public function test_a_record_naming_a_product_that_is_not_on_the_order_is_refused_and_changes_nothing(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper);
        $before = DB::table('orders')->where('id', $order->id)->first();
        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 99999, 'return_price' => 'x', 'quantity' => 1]]);

        $this->asOperator($store);

        $this->assertSame(422, $this->merchantCancel($refund)->getStatusCode());
        $this->assertSame($before->product_json, DB::table('orders')->where('id', $order->id)->value('product_json'));
    }

    public function test_a_negative_or_unusable_recorded_quantity_cannot_shrink_the_order(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);

        foreach ([-5, 0, 'abc', null, 1.5] as $bad) {
            $order = $this->order($store, $shopper);
            $before = DB::table('orders')->where('id', $order->id)->value('product_json');
            $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 502, 'return_price' => 'x', 'quantity' => $bad]]);

            $this->asOperator($store);
            $this->merchantCancel($refund);

            $this->assertSame($before, DB::table('orders')->where('id', $order->id)->value('product_json'), 'quantity ' . json_encode($bad) . ' changed the order');
        }
    }

    // =========================================================================================
    //  Variants: two lines of one product
    // =========================================================================================

    public function test_two_lines_of_one_product_are_told_apart_by_which_of_them_lost_units(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper, [
            ['product_id' => 501, 'name' => 'Shirt S', 'variant_id' => 11, 'qty' => 2, 'final_price' => 100],
            ['product_id' => 501, 'name' => 'Shirt M', 'variant_id' => 12, 'qty' => 1, 'final_price' => 50, 'refunded_qty' => 1],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['product_price' => 150, 'final_price' => 190]);
        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 501, 'return_price' => 'x', 'quantity' => 1]]);

        $this->asOperator($store);
        $this->assertSame(200, $this->merchantCancel($refund)->getStatusCode());

        $lines = $this->lines($order);
        $this->assertSame([2, 2], [(int) $lines[0]['qty'], (int) $lines[1]['qty']], 'the unit went to the variant that lost it, not the first line of the product');
    }

    // =========================================================================================
    //  Records that predate the counter
    // =========================================================================================

    public function test_a_line_without_a_counter_is_matched_by_product_and_does_not_crash(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper, [
            ['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300],
            ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['product_price' => 400, 'final_price' => 440]);
        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 502, 'return_price' => 'x', 'quantity' => 1]]);

        $this->asOperator($store);

        $this->assertSame(200, $this->merchantCancel($refund)->getStatusCode());
        $lines = $this->lines($order);
        $this->assertSame([3, 2], [(int) $lines[0]['qty'], (int) $lines[1]['qty']], 'by product id, not by the position of the ticked entry');
        $this->assertSame([500.0, 540.0], $this->totals($order));
    }

    /**
     * THE BOUND IS BYPASSABLE WHEN ONE PRODUCT SITS ON A COUNTED LINE AND A LEGACY LINE, which is an
     * ordinary order and not an exotic one.
     *
     * `restorationPlan()` makes two passes: the first takes lines that KEEP `refunded_qty` and refuses an
     * entry larger than that counter; the second takes lines that LACK the key -- with no bound at all,
     * recorded deliberately as a residual for records that predate P1-ORD-07.
     *
     * The two interact. `refunded_qty` is written per line BY THE REFUND, so a product on two lines where
     * only one has ever been refunded has exactly one counted line and one legacy line -- the normal state
     * of any order whose variants were partly returned. An entry for that product larger than the counted
     * line's counter then does not get REFUSED: it falls through to the second pass and lands on the
     * legacy sibling, unbounded. So the product HAS a counter and the bound is still skipped, which is what
     * acceptance criterion 3 exists to stop ("a recorded quantity larger than the amount the order line has
     * ever lost is not added back").
     *
     * Here: Shirt S (variant 11, qty 2, never refunded, so no counter) and Shirt M (variant 12, qty 1,
     * lost 1, counter = 1). A hand-written record claiming 50 units of product 501 exceeds M's counter of
     * 1, so pass 1 rejects M -- and pass 2 hands all 50 to S.
     */
    public function test_an_oversized_record_cannot_escape_the_counter_onto_a_legacy_sibling_line(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper, [
            ['product_id' => 501, 'name' => 'Shirt S', 'variant_id' => 11, 'qty' => 2, 'final_price' => 100],
            ['product_id' => 501, 'name' => 'Shirt M', 'variant_id' => 12, 'qty' => 1, 'final_price' => 50, 'refunded_qty' => 1],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['product_price' => 150, 'final_price' => 190]);
        $before = DB::table('orders')->where('id', $order->id)->first();

        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 501, 'return_price' => 'x', 'quantity' => 50]]);

        $this->asOperator($store);
        $response = $this->merchantCancel($refund);

        $lines = $this->lines($order);
        $this->assertSame(
            [2, 1],
            [(int) $lines[0]['qty'], (int) $lines[1]['qty']],
            'an oversized entry escaped the counted line onto its legacy sibling and inflated the order'
        );
        $this->assertSame($before->product_json, $lines !== null ? DB::table('orders')->where('id', $order->id)->value('product_json') : null);
        $this->assertSame((float) $before->final_price, (float) DB::table('orders')->where('id', $order->id)->value('final_price'));
        $this->assertSame(422, $response->getStatusCode(), 'the refusal should be the same 422 an oversized record gets on a single line');
    }

    /**
     * The converse, so the fix above is a bound and not a blanket refusal: when the product has NO counted
     * line at all, the legacy path still works. That is the residual criterion 5 permits ("they may be
     * wrong; they must not be able to inflate") and `test_a_line_without_a_counter_...` already covers the
     * single-line form; this is the two-line form, which must not be collateral damage.
     */
    public function test_a_product_whose_lines_are_all_legacy_still_restores(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $order = $this->order($store, $shopper, [
            ['product_id' => 501, 'name' => 'Shirt S', 'variant_id' => 11, 'qty' => 2, 'final_price' => 100],
            ['product_id' => 501, 'name' => 'Shirt M', 'variant_id' => 12, 'qty' => 1, 'final_price' => 50],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['product_price' => 150, 'final_price' => 190]);

        $refund = $this->hand($order, $store, 'Processing', [['product_refund_id' => 501, 'return_price' => 'x', 'quantity' => 1]]);

        $this->asOperator($store);
        $this->assertSame(200, $this->merchantCancel($refund)->getStatusCode());

        $lines = $this->lines($order);
        $this->assertSame(4, (int) $lines[0]['qty'] + (int) $lines[1]['qty'], 'one unit came back across the two legacy lines');
    }

    // =========================================================================================
    //  RefundStock
    // =========================================================================================

    private function stockFixture(Store $store, int $stock): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Beta', 'slug' => 'beta-' . uniqid(), 'product_stock' => $stock, 'variant_product' => 0, 'track_stock' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function restock(int $refundId)
    {
        return (new OrderRefundController())->RefundStock(Request::create('/', 'POST', ['refundId' => $refundId]));
    }

    public function test_restock_gives_back_what_the_line_lost(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->stockFixture($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100, 'refunded_qty' => 2],
        ]);
        $refund = $this->hand($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 2]]);

        $this->asOperator($store);

        $this->assertSame(200, $this->restock($refund)->getStatusCode());
        $this->assertSame(12, (int) DB::table('products')->where('id', $product)->value('product_stock'));
    }

    public function test_restock_cannot_give_back_more_than_the_line_lost(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->stockFixture($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100, 'refunded_qty' => 1],
        ]);
        $refund = $this->hand($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 500]]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame(11, (int) DB::table('products')->where('id', $product)->value('product_stock'), 'a recorded 500 conjured stock beyond the one unit the line lost');
    }

    public function test_two_entries_for_one_product_share_the_lines_counter(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->stockFixture($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100, 'refunded_qty' => 2],
        ]);
        $refund = $this->hand($order, $store, 'Accept', [
            ['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 2],
            ['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 2],
        ]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame(12, (int) DB::table('products')->where('id', $product)->value('product_stock'), 'the same two lost units were restocked twice');
    }

    /**
     * THE SAME GAP AS THE CANCEL PATH, reached a different way, so criterion 4 ("RefundStock bounds its
     * restock the same way") needs its own case.
     *
     * `unitsLostByProduct()` returns NULL for a product as soon as ANY of its lines predates the counter,
     * and `RefundStock` skips the clamp entirely on null. But `refunded_qty` is written PER LINE, so a
     * product on two lines where only one has ever been refunded yields null -- and the restock of an
     * oversized record goes through unbounded, even though a counter for that product exists and says 1.
     *
     * Two lines of one product, one counted (lost 1) and one never refunded. A record claiming 500 units
     * must restock 1, not 500.
     */
    public function test_restock_cannot_escape_the_counter_when_a_sibling_line_is_legacy(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->stockFixture($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Shirt S', 'variant_id' => 11, 'qty' => 2, 'final_price' => 100],
            ['product_id' => $product, 'name' => 'Shirt M', 'variant_id' => 12, 'qty' => 1, 'final_price' => 50, 'refunded_qty' => 1],
        ]);
        $refund = $this->hand($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 500]]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame(
            11,
            (int) DB::table('products')->where('id', $product)->value('product_stock'),
            'a legacy sibling line made the counter unreadable and 500 units were conjured'
        );
    }

    public function test_restock_of_a_line_without_a_counter_is_unchanged(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->stockFixture($store, 10);
        $order = $this->order($store, $shopper, [['product_id' => $product, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 1, 'final_price' => 100]]);
        $refund = $this->hand($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 3]]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame(13, (int) DB::table('products')->where('id', $product)->value('product_stock'));
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-140 / P4-F083 -- THE REFUND DOOR SAT OUTSIDE THE CROSS-DOOR STOCK PROTOCOL, SO ONE LINE'S
 * GOODS WERE CREDITED TWICE.
 * ==============================================================================================
 *
 * FOUR STOCK DOORS, ONE PROTOCOL, AND THE REFUND DOOR WAS NOT IN IT.
 *
 *   Order::product_return()                       mark ONE line returned        reads + writes the marker
 *   OrderController::restoreReturnedStock()       approve a whole return        reads + writes the marker
 *   RestoresCancelledOrderStock (6 cancel doors)  cancel an order               reads + writes the marker
 *   OrderRefundController::RefundStock()          an accepted refund's goods    NEITHER, before this change
 *
 * The marker is `Order::LINE_STOCK_RESTORED`, a per-line key in `orders.product_json` whose own
 * docblock asserts "a refund or return reads it as evidence". Measured at HEAD before this change:
 * `OrderRefundController` contained ZERO references to it (one comment mentioning `movedOutFor()`
 * and nothing else), so the refund door could not see a credit any other door had made, and no
 * other door could see one it had made.
 *
 * WHAT THAT COST, measured here rather than inferred: a merchant marks a delivered line returned
 * (goods back on the shelf, +3), the shopper then opens a refund request for that same line, the
 * merchant accepts it and presses "Manage Stock" -- and the same three units are credited AGAIN.
 * Neither actor does anything hostile and the storefront form leads them into it: it lists every
 * line of a delivered order, already-returned ones included.
 *
 * WHY `movedOutFor()` CANNOT STAND IN FOR THE MARKER, which is why this test asserts on the marker
 * and on the stock rather than on the stock record: `stock_movements` is development-and-test-only
 * (its own migration says so), so on a live installation every line reads UNKNOWN and every door
 * restores unconditionally. The same reasoning is written out in
 * `App\Http\Controllers\Concerns\RestoresCancelledOrderStock`.
 *
 * ORACLE: the `products` / `product_variants` row and the order's stored `product_json`, both read
 * RAW through `DB::table()`. Never `Product::find()` or `Order::find()`: both models are
 * `Cachable` and under `CACHE_STORE=array` a cached row outlives the write for the rest of the
 * test, which has produced three measured false readings in this repository.
 *
 * The journey is driven through the REAL doors -- the real storefront POST, the real merchant
 * controller methods -- so a change that only satisfies a hand-built fixture cannot pass.
 *
 * It extends `SecurityTestCase` (and therefore lives beside the Commerce suites while borrowing
 * their tenant fixtures) for one specific reason: that base class records the on-disk migrations
 * so `XSS`'s updater gate does not redirect a `super admin` away from the controller before it is
 * reached. Without it every assertion here would pass without exercising anything.
 */
class RefundStockCrossDoorTest extends SecurityTestCase
{
    use RefreshDatabase;

    // =============================================================================================
    //  Fixture
    // =============================================================================================

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop',
            'last_name' => 'Per',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    /** A DELIVERED order, which both the return door and the refund request door require. */
    private function order(Store $store, Customer $owner, array $lines, array $attributes = []): Order
    {
        return $this->makeOrder($store, array_merge([
            'product_order_id' => 'ORD-' . strtoupper($this->uniqueFixtureRef()),
            'customer_id' => $owner->id,
            'is_guest' => 0,
            'delivered_status' => 1,
            'product_price' => 300,
            'delivery_price' => 0,
            'tax_price' => 0,
            'coupon_price' => 0,
            'final_price' => 300,
            'product_json' => json_encode($lines),
        ], $attributes));
    }

    private function productIn(Store $store, int $stock, int $variantProduct = 0): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Widget',
            'slug' => 'widget-' . $this->uniqueFixtureRef(),
            'product_stock' => $stock,
            'variant_product' => $variantProduct,
            'track_stock' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function variantIn(Store $store, int $productId, int $stock): int
    {
        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            // The real column set: `variant`, `sku` and `price` are NOT NULL in
            // 2024_01_01_104858_create_product_variants_table.php. There is no `name` column --
            // a fixture that invented one died on the INSERT, which is a harness failure and not
            // a defect, and is recorded here so the next reader does not repeat it.
            'variant' => 'Large',
            'sku' => 'SKU-' . $this->uniqueFixtureRef(),
            'price' => 100,
            'stock' => $stock,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function variantStockOf(int $variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }

    /** The stored lines, read raw. */
    private function lines(int $orderId): array
    {
        $json = json_decode((string) DB::table('orders')->where('id', $orderId)->value('product_json'), true);

        return is_array($json) ? $json : [];
    }

    private function markerOnLine(int $orderId, int $index = 0): bool
    {
        return Order::lineStockAlreadyRestored($this->lines($orderId)[$index] ?? []);
    }

    /** Door 1: the merchant marks ONE line returned. The real model method both web doors call. */
    private function returnLine(Order $order, int $productId, int $variantId = 0): array
    {
        return Order::product_return([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'order_id' => $order->id,
        ]);
    }

    /** Door 2: the shopper's real storefront refund request, posted exactly as the form posts it. */
    private function requestRefund(Customer $as, Store $store, Order $order, array $ids, array $quantities)
    {
        return $this->actingAs($as, 'customers')->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id,
            'product_refund_id' => $ids,
            'quantity' => $quantities,
            'return_price' => array_fill(0, count($quantities), 'P'),
            'product_sub_total' => 100,
            'refund_reason' => 'Other',
            'custom_refund_reason' => 'x',
        ]);
    }

    private function refundId(): int
    {
        return (int) DB::table('order_refunds')->max('id');
    }

    /** A refund row written by hand, for the cases that are about the restock and not about the request. */
    private function handRefund(Order $order, Store $store, string $status, array $record): int
    {
        return (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id,
            'refund_status' => $status,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode($record),
            'refund_amount' => 0,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asOperator(Store $store): void
    {
        $this->actingAs($this->makePlatformAdmin(['current_store' => $store->id]), 'web');
    }

    private function accept(int $refundId)
    {
        return (new OrderRefundController())->updateRefundStatus(
            Request::create('/', 'POST', ['refund_id' => $refundId])
        );
    }

    /** Door 3: the merchant presses "Manage Stock" on an accepted refund. */
    private function restock(int $refundId)
    {
        return (new OrderRefundController())->RefundStock(
            Request::create('/', 'POST', ['refundId' => $refundId])
        );
    }

    // =============================================================================================
    //  THE DOUBLE CREDIT -- P4-F083 cases 1, 2 and 3
    // =============================================================================================

    /**
     * CASE 1 -- the non-variant line, the whole journey through the real doors.
     *
     * The two premise assertions are not decoration. Without them this test passes if door 1
     * silently did nothing, which is the first way a cross-door test passes for the wrong reason.
     */
    public function test_a_line_the_return_door_credited_is_not_credited_again_by_the_refund_door(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->assertSame(10, $this->stockOf($product), 'premise: the fixture did not store the opening stock');

        // DOOR 1 -- the merchant marks the line returned. +3.
        $returned = $this->returnLine($order, $product);

        $this->assertSame('success', $returned['status'] ?? null, 'premise: the return door refused, so nothing is being measured');
        $this->assertSame(13, $this->stockOf($product), 'premise: the return door credited nothing');
        $this->assertTrue($this->markerOnLine($order->id), 'premise: the return door left no LINE_STOCK_RESTORED marker');

        // DOOR 2 -- the shopper asks for a refund of the same line. The form offers it: the
        // storefront lists every line of a delivered order, already-returned ones included.
        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertGreaterThan(0, $refund, 'premise: no refund request was recorded');

        // DOOR 3 -- the merchant accepts and presses "Manage Stock".
        $this->asOperator($store);
        $this->assertSame(200, $this->accept($refund)->getStatusCode(), 'premise: the refund was not accepted');
        $this->restock($refund);

        $this->assertSame(
            13,
            $this->stockOf($product),
            'the same three units were credited twice: once by the return door and again by the refund door'
        );
    }

    /**
     * CASE 2 -- the variant twin, because `product_return()` stamps the marker on that branch too
     * and `RefundStock` has its own variant restore.
     */
    public function test_a_variant_line_the_return_door_credited_is_not_credited_again_by_the_refund_door(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 0, 1);
        $variant = $this->variantIn($store, $product, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => $variant, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->assertSame(10, $this->variantStockOf($variant), 'premise: the fixture did not store the opening variant stock');

        $returned = $this->returnLine($order, $product, $variant);

        $this->assertSame('success', $returned['status'] ?? null, 'premise: the return door refused');
        $this->assertSame(13, $this->variantStockOf($variant), 'premise: the return door credited no variant stock');
        $this->assertTrue($this->markerOnLine($order->id), 'premise: the variant branch left no marker');

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertGreaterThan(0, $refund, 'premise: no refund request was recorded');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(
            13,
            $this->variantStockOf($variant),
            'the variant was credited twice across the return and refund doors'
        );
    }

    /**
     * CASE 3 -- THE RATCHET IN THE OTHER DIRECTION. A fix must not buy idempotency by refusing a
     * credit that is owed.
     *
     * Refund FIRST: the request moves the three units off the line (`qty` 3 -> 0,
     * `refunded_qty` 3), so when the merchant then marks the line returned the return door has
     * nothing left to credit -- and must therefore not claim the goods are back. The units the
     * line lost are still owed, and the refund door is the one that owes them.
     *
     * Measured at HEAD: 13, correct. This case exists so that the fix for cases 1 and 2 cannot
     * reach 13 there by crediting nothing anywhere.
     */
    public function test_a_refund_after_a_zero_quantity_return_still_credits_what_the_line_lost(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        // DOOR 2 FIRST.
        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertGreaterThan(0, $refund, 'premise: no refund request was recorded');

        $lines = $this->lines($order->id);
        $this->assertSame(0, (int) ($lines[0]['qty'] ?? -1), 'premise: the request did not move the units off the line');
        $this->assertSame(3, (int) ($lines[0]['refunded_qty'] ?? 0), 'premise: the request recorded no per-line counter');

        // DOOR 1 SECOND, with nothing left on the line to credit.
        $this->returnLine($order, $product);

        $this->assertSame(10, $this->stockOf($product), 'premise: the return door credited units the line no longer holds');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(
            13,
            $this->stockOf($product),
            'the units the line lost were never credited: the refund door owed them and gave back nothing'
        );
    }

    /**
     * CASE 3, THE VARIANT TWIN. `product_return()` stamps the marker on both branches, so the
     * zero-credit rule has to hold on both or the variant arm keeps the regression the non-variant
     * arm is protected from.
     */
    public function test_a_variant_refund_after_a_zero_quantity_return_still_credits_what_the_line_lost(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 0, 1);
        $variant = $this->variantIn($store, $product, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => $variant, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertGreaterThan(0, $refund, 'premise: no refund request was recorded');
        $this->assertSame(0, (int) ($this->lines($order->id)[0]['qty'] ?? -1), 'premise: the request left units on the line');

        $this->returnLine($order, $product, $variant);

        $this->assertSame(10, $this->variantStockOf($variant), 'premise: the return door credited units the line no longer holds');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(
            13,
            $this->variantStockOf($variant),
            'the variant units the line lost were never credited: the refund door owed them and gave back nothing'
        );
    }

    /**
     * THE OTHER MARKER, AND IT IS NOT THE SAME CLAIM. `LINE_STOCK_CARRIED` says the goods never
     * left this order at all: the line was reissued by `OrderReplacement::cancelAndReplace()`,
     * whose cancel-and-reissue pair must net -N. Crediting such a line does not double a credit --
     * it invents stock that was never on the shelf, which is why the cancel loop reads it and why
     * the refund door must too. Until GAP-140 that marker had no reader outside the cancel loop.
     */
    public function test_a_line_carried_from_a_replaced_original_is_not_credited_by_the_refund_door(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            [
                'product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 0,
                'final_price' => 0, 'return' => 0, 'refunded_qty' => 3,
                Order::LINE_STOCK_CARRIED => 1,
            ],
        ]);
        $refund = $this->handRefund($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 3]]);

        $this->asOperator($store);

        $this->assertSame(200, $this->restock($refund)->getStatusCode(), 'premise: the call did not reach the clamp');
        $this->assertSame(
            10,
            $this->stockOf($product),
            'a reissued line\'s goods never left the shelf, so crediting them invents stock'
        );
    }

    /**
     * AN UNMARKED SIBLING LINE KEEPS ITS CONTRIBUTION TO THE PRODUCT'S ALLOWANCE.
     *
     * ADDED BY THE INDEPENDENT REVIEW OF THIS CHANGE, WHICH FOUND THE ONE SURVIVING MUTANT in a
     * table that reported 23 of 23 caught. The read half writes `$counted[$key] = $counted[$key] ??
     * 0` for a marked line, and the `?? 0` is what preserves a contribution an EARLIER unmarked
     * line of the same product already made. Replace it with a bare `$counted[$key] = 0;` and no
     * case in this file failed: the shape that distinguishes them is an unmarked counted line
     * FIRST and a marked line second, on the SAME product, and every marked-line case here was
     * single-line while the two-line cases used two different products or two unmarked lines.
     *
     * So the mutant silently zeroed a legitimate allowance and the suite called it caught. The
     * direction of harm is under-credit rather than an oversell, which is why it was a coverage gap
     * and not a hole -- but a mutation table is evidence only for the shapes it can see, and this
     * is the fixture that makes this one visible.
     *
     * TWO LINES, ONE PRODUCT, 5 UNITS REFUNDED BETWEEN THEM, 3 OF THEM ALREADY BACK. The refund
     * record claims 2, which is exactly what line A lost and is still owed, so the honest credit is
     * 2: 10 -> 12. With the mutant the product is bounded at 0 and nothing is credited.
     */
    public function test_an_unmarked_sibling_line_keeps_its_contribution_to_the_allowance(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);

        $order = $this->order($store, $shopper, [
            /* A: emptied by a refund, nobody has credited it -- 2 units genuinely owed. */
            [
                'product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 0,
                'final_price' => 0, 'return' => 0, 'refunded_qty' => 2,
            ],
            /* B: emptied by a refund AND already credited by a return door. Owes nothing. */
            [
                'product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 0,
                'final_price' => 0, 'return' => 0, 'refunded_qty' => 3,
                Order::LINE_STOCK_RESTORED => 1,
            ],
        ]);

        $lines = $this->lines($order->id);
        $this->assertSame(2, (int) ($lines[0]['refunded_qty'] ?? 0),
            'premise: the unmarked line really carries a counter, or it contributes nothing anyway '
            . 'and this fixture cannot tell the mutant apart');
        $this->assertTrue($this->markerOnLine($order->id, 1),
            'premise: the second line really is marked, or both lines are unmarked and the read '
            . 'half is not being exercised at all');

        $refund = $this->handRefund($order, $store, 'Accept', [
            ['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 2],
        ]);

        $this->asOperator($store);

        $this->assertSame(200, $this->restock($refund)->getStatusCode(),
            'premise: the call reached the clamp');

        $this->assertSame(
            12,
            $this->stockOf($product),
            'the unmarked sibling line\'s 2 refunded units were not credited. A marked line must '
            . 'contribute NOTHING to the allowance without ERASING what an unmarked line of the '
            . 'same product already contributed -- that is what the `?? 0` does, and zeroing it '
            . 'unconditionally refuses a credit the merchant is owed.'
        );
    }

    /**
     * THE SECOND RETURN DOOR. `OrderController::order_return_request` approves a WHOLE return and
     * restocks every line through `restoreReturnedStock()`, which reads and writes the same marker.
     * It carries no `delivered_status` requirement, so the order is still DELIVERED afterwards and
     * the storefront still offers a refund on it -- the same double credit by a different first
     * door.
     *
     * Driven through the real HTTP route, because that door's guard (`authorize('updateStatus')`)
     * and its `return_status` replay guard both sit in the controller.
     */
    public function test_a_line_the_whole_order_return_credited_is_not_credited_again_by_the_refund_door(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->asOperator($store);
        $this->post(route('order.return.request'), ['id' => $order->id, 'status' => 2])->assertOk();

        $this->assertSame(13, $this->stockOf($product), 'premise: the return approval credited nothing');
        $this->assertTrue($this->markerOnLine($order->id), 'premise: the return approval left no marker');
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'premise: the approval moved the order out of DELIVERED, so the refund door is unreachable and nothing is measured');

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertGreaterThan(0, $refund, 'premise: no refund request was recorded');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(
            13,
            $this->stockOf($product),
            'the whole-order return and the refund each credited the same three units'
        );
    }

    /**
     * THE REFUND DOOR NOW WRITES THE MARKER TOO, for the lines it leaves with nothing outstanding.
     *
     * Reading the marker closes the door-ran-first direction. Writing it closes the one that
     * remains: `CancelRefundStatus` puts a refunded line's units back onto `qty`, and a return
     * door credits `qty` -- so without a marker the units the refund door already credited become
     * creditable a second time. The stamp is honest only for a line with nothing left out, which
     * is why it is conditional on `qty` being 0 and on this restock having credited that product.
     */
    public function test_the_refund_door_stamps_a_line_it_has_fully_credited(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();

        $this->assertFalse($this->markerOnLine($order->id), 'premise: the line was already marked before the refund door ran');
        // Warm the Eloquent read the next door uses, so the assertion below can see a stale one.
        $this->assertNotNull(Order::find($order->id), 'premise: the order does not resolve through Eloquent');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(13, $this->stockOf($product),
            'the figure is asserted beside the marker: a stamp over a credit that did not happen is a false claim');
        $this->assertTrue(
            $this->markerOnLine($order->id),
            'the refund door credited the line and left no marker, so a later return or cancel door can credit it again'
        );

        // AND THE NEXT DOOR CAN SEE IT. `Order::product_return()` reads the order with
        // `Order::find()`, and `Order` is `Cachable`: a marker persisted by a raw update leaves
        // that read serving the pre-write row, so the marker would be in the table and invisible
        // to every reader that matters. The read is warmed above, before the restock, because a
        // cold cache cannot be stale.
        $this->assertTrue(
            Order::lineStockAlreadyRestored(
                (json_decode((string) Order::find($order->id)->product_json, true) ?: [])[0] ?? []
            ),
            'the marker is in the table but an Eloquent read still serves the pre-stamp lines, so the '
            . 'return door will credit these goods again'
        );
    }

    /**
     * THE STAMP MUST NOT DISCARD WHAT ANOTHER DOOR WROTE WHILE THE RESTOCK WAS RUNNING.
     *
     * `RefundStock` loads the order before it touches any stock, and the stamp lands after. Writing
     * the loaded instance's `product_json` back would persist that snapshot and silently drop
     * anything committed to the column in between -- a clobber this door could not cause before,
     * because it wrote nothing to `orders` at all.
     *
     * THE INTERLEAVE IS DETERMINISTIC, not a race: a query listener fires the other door's write
     * the moment the stock row moves, which is exactly between the order load and the stamp. The
     * injected change is the `return` flag, because that is what `Order::product_return()` really
     * writes there. SQLite serialises writers and cannot stage a true concurrent commit, which is
     * why the ordering is staged rather than raced -- the same device the storefront refund
     * request's freshness test uses.
     */
    public function test_the_stamp_does_not_discard_a_change_another_door_committed_while_it_ran(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();

        $this->asOperator($store);
        $this->accept($refund);

        $injected = false;
        DB::listen(function ($query) use ($order, &$injected) {
            if ($injected || ! str_contains(strtolower($query->sql), 'update "products"')) {
                return;
            }

            $injected = true;
            $lines = json_decode((string) DB::table('orders')->where('id', $order->id)->value('product_json'), true);
            $lines[0]['return'] = 1;
            DB::table('orders')->where('id', $order->id)->update(['product_json' => json_encode($lines)]);
        });

        $this->restock($refund);

        $this->assertTrue($injected, 'premise: the other door never got to write, so no interleave was staged');

        $lines = $this->lines($order->id);
        $this->assertSame(
            1,
            (int) ($lines[0]['return'] ?? 0),
            'the stamp wrote back a snapshot taken before the stock moved and discarded the return flag '
            . 'another door had already committed'
        );
        $this->assertTrue(Order::lineStockAlreadyRestored($lines[0]), 'premise: nothing was stamped, so there is no clobber to judge');
    }

    /**
     * AND IT DOES NOT STAMP A LINE THAT STILL HAS GOODS OUT WITH THE CUSTOMER.
     *
     * `markCancelledLinesRestored()`'s docblock states the rule this pins: a marker claimed for
     * goods that did not come back blocks a legitimate credit later, and is worse than no marker.
     * A partial refund leaves units on the line, and those units are still returnable.
     */
    public function test_the_refund_door_does_not_stamp_a_line_that_still_holds_units(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [1]);
        $refund = $this->refundId();

        $lines = $this->lines($order->id);
        $this->assertSame(2, (int) ($lines[0]['qty'] ?? -1), 'premise: the partial request did not leave units on the line');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(11, $this->stockOf($product), 'premise: the one refunded unit was not credited');
        $this->assertFalse(
            $this->markerOnLine($order->id),
            'the line still holds two units, so claiming its goods are back blocks the return door from ever crediting them'
        );
    }

    /**
     * THE RESIDUAL, PINNED WITH ITS MEASURED FIGURE SO IT CANNOT BE FORGOTTEN -- AND THIS ASSERTION
     * IS NOT THE BEHAVIOUR ANYBODY WANTS.
     *
     * A PARTIAL refund followed by a line-level return of the units that are left is the one
     * sequence the stored line cannot answer, and the fix errs here on purpose.
     *
     *   partial refund of 2 of 3, then the merchant marks the line returned:
     *       {qty: 1, refunded_qty: 2, stock_restored: 1}   -- the return credited 1
     *   the merchant marks the line returned, then a partial refund of 2 of 3:
     *       {qty: 1, refunded_qty: 2, stock_restored: 1}   -- the return credited 3
     *
     * The two stored lines are IDENTICAL and 2 units are owed in the first while 0 are owed in the
     * second. So the allowance reads a marked line as fully accounted for, which under-credits the
     * first by 2 rather than inventing 2 in the second: an understated stock figure a merchant can
     * see and correct, instead of an oversell.
     *
     * AN EARLIER DRAFT OF THIS PARAGRAPH SAID "NOTHING RECORDS WHICH HAPPENED" AND THAT WAS FALSE.
     * It is corrected rather than removed, because the premise is the whole justification for
     * accepting a regression and a wrong one would let the next reader dismiss the residual.
     * `product_return()` sums the flagged line's `final_price` AT THE TIME IT RUNS and writes
     * `orders.return_price`; a refund request reduces the line's `final_price` and writes no order
     * totals. So on this very fixture the two sequences leave `return_price` 300 and 100
     * respectively, and ARE distinguishable from stored data with no new writes.
     *
     * THE RESIDUAL STANDS ON A DIFFERENT FOOTING: that column is not a RELIABLE trace.
     * `order_return_request` overwrites it unconditionally on both approve and reject,
     * `RefundAmonut` recomputes `final_price` from the lines on settlement, and it aggregates every
     * line with any coupon folded in -- so it is ambiguous for any multi-line order, and deciding a
     * stock credit from it is the kind of inference this project has repeatedly been bitten by.
     * `StockMovement::movedOutFor()` WOULD answer it exactly -- it nets ins against outs per line --
     * but its migration is development-and-test-only, so every live line reads UNKNOWN. Found by
     * the independent review of this change, not by the author or by this file.
     *
     * MEASURED: 11 here, and 13 at HEAD before this change -- so this one sequence is a REGRESSION
     * in exchange for closing the double credit, and it is declared rather than hidden. Resolving
     * it exactly means recording the ordering where it is known, which is the storefront refund
     * request writer (`AccountProfileController`) marking the units it reclassifies off a line
     * whose stock has already been credited. That file is outside this change.
     *
     * IF A LATER CHANGE MAKES THIS 13, THAT IS THE FIX LANDING, NOT A REGRESSION: update this test
     * and the GAP-140 row together.
     */
    public function test_a_partial_refund_after_a_line_return_leaves_the_refunded_units_uncredited(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [2]);
        $refund = $this->refundId();
        $this->assertSame(1, (int) ($this->lines($order->id)[0]['qty'] ?? -1), 'premise: the partial request left the wrong number of units');

        $this->returnLine($order, $product);

        $this->assertSame(11, $this->stockOf($product), 'premise: the return door credited something other than the one remaining unit');
        $this->assertTrue($this->markerOnLine($order->id), 'premise: the return door left no marker, so the residual is not what is being measured');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(
            11,
            $this->stockOf($product),
            'the declared residual: 3 units came back and 1 was credited. 13 is physically right and '
            . 'is not reachable from the stored line, so the refund door errs towards understating '
            . 'stock rather than inventing it'
        );
    }

    /**
     * THE VARIANT ARM OF THE STAMP. `RefundStock` has a separate restore for a variant line, so
     * the write half has to be recorded on both arms or a variant order keeps the hole the
     * non-variant one no longer has.
     */
    public function test_the_refund_door_stamps_a_variant_line_it_has_fully_credited(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 0, 1);
        $variant = $this->variantIn($store, $product, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => $variant, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);

        $this->requestRefund($shopper, $store, $order, [(string) $product], [3]);
        $refund = $this->refundId();
        $this->assertFalse($this->markerOnLine($order->id), 'premise: the line was marked before the refund door ran');

        $this->asOperator($store);
        $this->accept($refund);
        $this->restock($refund);

        $this->assertSame(13, $this->variantStockOf($variant), 'premise: the variant credit did not happen, so there is no stamp to judge');
        $this->assertTrue(
            $this->markerOnLine($order->id),
            'the variant arm credited the line and left no marker, so a later return or cancel door can credit it again'
        );
    }

    /**
     * AND IT STAMPS ONLY THE PRODUCT IT CREDITED, not every emptied line on the order.
     *
     * Two products, both fully refunded off their lines, and a record that names only the first.
     * Stamping the second would claim goods are back that this refund never gave back, and the
     * only thing keeping the two apart is that the stamp is driven by what the restock loop
     * actually credited.
     */
    public function test_the_refund_door_stamps_only_the_lines_of_the_product_it_credited(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $credited = $this->productIn($store, 10);
        $untouched = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $credited, 'name' => 'A', 'variant_id' => 0, 'qty' => 0, 'final_price' => 0, 'return' => 0, 'refunded_qty' => 3],
            ['product_id' => $untouched, 'name' => 'B', 'variant_id' => 0, 'qty' => 0, 'final_price' => 0, 'return' => 0, 'refunded_qty' => 2],
        ]);
        $refund = $this->handRefund($order, $store, 'Accept', [['product_refund_id' => $credited, 'return_price' => 'x', 'quantity' => 3]]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame([13, 10], [$this->stockOf($credited), $this->stockOf($untouched)],
            'premise: the restock credited the wrong products');
        $this->assertTrue($this->markerOnLine($order->id, 0), 'the credited line was not stamped');
        $this->assertFalse(
            $this->markerOnLine($order->id, 1),
            'a line this refund never credited was stamped, so a later return door will refuse to credit it'
        );
    }

    /**
     * AND ONLY A LINE THAT REALLY LOST UNITS TO A REFUND. A sibling line of the same product that
     * predates the per-line counter has an unexplained `qty` of 0: this refund did not empty it,
     * so it cannot claim its goods are back.
     */
    public function test_the_refund_door_does_not_stamp_a_legacy_sibling_line_it_did_not_empty(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget S', 'variant_id' => 0, 'qty' => 0, 'final_price' => 0, 'return' => 0, 'refunded_qty' => 3],
            ['product_id' => $product, 'name' => 'Widget M', 'variant_id' => 0, 'qty' => 0, 'final_price' => 0, 'return' => 0],
        ]);
        $refund = $this->handRefund($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 3]]);

        $this->asOperator($store);
        $this->restock($refund);

        $this->assertSame(13, $this->stockOf($product), 'premise: the restock credited nothing, so there is no stamp to judge');
        $this->assertTrue($this->markerOnLine($order->id, 0), 'the line that lost the units was not stamped');
        $this->assertFalse(
            $this->markerOnLine($order->id, 1),
            'a legacy line that lost nothing to this refund was stamped as already back in'
        );
    }

    // =============================================================================================
    //  THE FIVE NEGATIVE TESTS THE P4-6 ACCEPTANCE CLAUSE NAMES
    //
    //  PHASE-04-EXECUTION-PLAN.md §6 P4-6, verbatim: "Acceptance: negative tests for
    //  authorization, ownership, transitions, amount limits and duplicates."
    //
    //  Each dimension already has a test for the refund mutators in general
    //  (OrderRefundAuthorizationTest, RefundStateMachineTest, RefundIntegrityTest,
    //  RefundStockIdempotencyTest, RefundRestoreByProductTest). The five below are that clause
    //  asked AT THE NEW SEAM: with a cross-door credit already on the line, so that a refusal
    //  cannot be satisfied by a journey that was never going to credit anything anyway, and so
    //  that none of the five can be bypassed by the marker logic this change adds.
    // =============================================================================================

    /** (1) AUTHORIZATION -- a signed-in user who holds no refund permission cannot credit stock. */
    public function test_negative_authorization_a_user_without_the_permission_cannot_credit_stock(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0, 'refunded_qty' => 3],
        ]);
        $refund = $this->handRefund($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 3]]);

        // A real signed-in merchant of this very store, holding no role at all.
        $this->actingAs($this->makeMerchant(['current_store' => $store->id]), 'web');

        $response = $this->restock($refund);

        $this->assertSame(403, $response->getStatusCode(), 'the permission gate did not refuse');
        $this->assertSame(10, $this->stockOf($product), 'stock moved for a caller who holds no refund permission');
        $this->assertFalse($this->markerOnLine($order->id), 'a refused call stamped the protocol marker');
    }

    /**
     * (2) OWNERSHIP -- another store's refund is not restockable, and its order is untouched.
     *
     * TWO FIXTURES, BECAUSE ONE OF THEM PROVES NOTHING ON ITS OWN. The wholly-foreign journey is
     * refused by THREE independent predicates -- the refund's, the order's and the product's -- so
     * a mutation that removes any one of them still answers 404 and a test that drives only that
     * shape reports "caught" for a control it never exercised. Measured: removing the refund's own
     * store predicate left the one-fixture version of this test green (mutation M15).
     *
     * The second fixture isolates it. A refund row carrying store B while its order belongs to
     * store A is the shape the storefront's own writer can never produce but a hand-written or
     * drifted row can, and it is the only one where the refund's predicate is the sole thing
     * standing between the caller and a credit: the order and the product are both legitimately
     * the caller's, so with that predicate gone the stock really does move.
     */
    public function test_negative_ownership_another_stores_refund_cannot_credit_this_stores_stock(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // (i) wholly foreign: the refund, its order and its product are all store B's.
        $foreignShopper = $this->shopper($storeB);
        $foreignProduct = $this->productIn($storeB, 10);
        $foreignOrder = $this->order($storeB, $foreignShopper, [
            ['product_id' => $foreignProduct, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0, 'refunded_qty' => 3],
        ]);
        $foreignRefund = $this->handRefund($foreignOrder, $storeB, 'Accept', [
            ['product_refund_id' => $foreignProduct, 'return_price' => 'x', 'quantity' => 3],
        ]);

        // (ii) the mismatched row: a store-B refund pointing at the CALLER'S OWN order and product.
        $ownShopper = $this->shopper($storeA);
        $ownProduct = $this->productIn($storeA, 10);
        $ownOrder = $this->order($storeA, $ownShopper, [
            ['product_id' => $ownProduct, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0, 'refunded_qty' => 3],
        ]);
        $mismatchedRefund = $this->handRefund($ownOrder, $storeB, 'Accept', [
            ['product_refund_id' => $ownProduct, 'return_price' => 'x', 'quantity' => 3],
        ]);

        // (iii) the mirror of (ii): the caller's OWN refund row pointing at store B's order. Only
        //       the ORDER's store predicate stands here.
        $crossOrderRefund = $this->handRefund($foreignOrder, $storeA, 'Accept', [
            ['product_refund_id' => $foreignProduct, 'return_price' => 'x', 'quantity' => 3],
        ]);

        // (iv) the caller's own refund and order, whose LINE names store B's product -- the shape
        //      the product's store predicate exists for. `product_refund_id` comes out of a JSON
        //      blob, so it is attacker-influenced input and not a validated foreign key.
        $crossProductOrder = $this->order($storeA, $ownShopper, [
            ['product_id' => $foreignProduct, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0, 'refunded_qty' => 3],
        ]);
        $crossProductRefund = $this->handRefund($crossProductOrder, $storeA, 'Accept', [
            ['product_refund_id' => $foreignProduct, 'return_price' => 'x', 'quantity' => 3],
        ]);

        // The operator, but working in store A.
        $this->asOperator($storeA);

        $this->assertSame(404, $this->restock($foreignRefund)->getStatusCode(), 'a refund outside the caller\'s tenant was resolved');
        $this->assertSame(404, $this->restock($mismatchedRefund)->getStatusCode(), 'a refund row whose store disagrees with its order was resolved');
        $this->assertSame(404, $this->restock($crossOrderRefund)->getStatusCode(), 'a refund was actioned against another store\'s order');
        $this->restock($crossProductRefund);

        $this->assertSame(
            ['foreign' => 10, 'mismatched' => 10],
            ['foreign' => $this->stockOf($foreignProduct), 'mismatched' => $this->stockOf($ownProduct)],
            'a refund belonging to another store, or naming another store\'s product, credited stock'
        );
        $this->assertFalse($this->markerOnLine($foreignOrder->id), 'another store\'s order was stamped');
        $this->assertFalse($this->markerOnLine($ownOrder->id), 'an order was stamped by a refund that does not belong to its store');
        $this->assertFalse($this->markerOnLine($crossProductOrder->id), 'a line naming another store\'s product was stamped as credited');
    }

    /** (3) TRANSITIONS -- stock may not come back before the merchant has accepted the return. */
    public function test_negative_transitions_a_processing_refund_cannot_credit_stock(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            ['product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0, 'refunded_qty' => 3],
        ]);
        $refund = $this->handRefund($order, $store, 'Processing', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 3]]);

        $this->asOperator($store);

        $response = $this->restock($refund);

        $this->assertSame(422, $response->getStatusCode(), 'a Processing refund was restocked');
        $this->assertSame(10, $this->stockOf($product), 'stock came back before the return was agreed');
        $this->assertFalse($this->markerOnLine($order->id), 'a refused call stamped the protocol marker');
    }

    /**
     * (4) AMOUNT LIMITS -- the allowance of a line whose goods are already back is ZERO, so a
     * record claiming the full quantity credits nothing. The clamp is what carries the fix: this
     * is the negative form of case 1, asked directly against the allowance.
     */
    public function test_negative_amount_limits_an_already_credited_line_allows_no_further_units(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 10);
        $order = $this->order($store, $shopper, [
            [
                'product_id' => $product, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 0,
                'final_price' => 0, 'return' => 1, 'refunded_qty' => 3,
                Order::LINE_STOCK_RESTORED => 1,
            ],
        ]);
        $refund = $this->handRefund($order, $store, 'Accept', [['product_refund_id' => $product, 'return_price' => 'x', 'quantity' => 500]]);

        $this->asOperator($store);

        $this->assertSame(200, $this->restock($refund)->getStatusCode(), 'premise: the call did not reach the clamp');
        $this->assertSame(
            10,
            $this->stockOf($product),
            'a record claiming 500 units credited a line whose goods are already on the shelf'
        );
    }

    /**
     * (5) DUPLICATES -- one line, one credit, whichever door ran first. Both orderings in one
     * test, because a guard that only closes the order it was written for is the defect this gap
     * records.
     */
    public function test_negative_duplicates_one_line_is_credited_once_whichever_door_ran_first(): void
    {
        [, $store] = $this->makeTenant();

        // ORDER A: return door first, then the refund door.
        $shopperA = $this->shopper($store);
        $productA = $this->productIn($store, 10);
        $orderA = $this->order($store, $shopperA, [
            ['product_id' => $productA, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);
        $this->returnLine($orderA, $productA);
        $this->requestRefund($shopperA, $store, $orderA, [(string) $productA], [3]);
        $refundA = $this->refundId();
        $this->asOperator($store);
        $this->accept($refundA);
        $this->restock($refundA);

        // ORDER B: refund door first, then the return door.
        $shopperB = $this->shopper($store);
        $productB = $this->productIn($store, 10);
        $orderB = $this->order($store, $shopperB, [
            ['product_id' => $productB, 'name' => 'Widget', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300, 'return' => 0],
        ]);
        $this->requestRefund($shopperB, $store, $orderB, [(string) $productB], [3]);
        $refundB = $this->refundId();
        $this->asOperator($store);
        $this->accept($refundB);
        $this->restock($refundB);
        $this->returnLine($orderB, $productB);

        $this->assertSame(
            ['return_then_refund' => 13, 'refund_then_return' => 13],
            ['return_then_refund' => $this->stockOf($productA), 'refund_then_return' => $this->stockOf($productB)],
            'three units came back once, so each product must read 13 whichever door ran first'
        );
    }
}

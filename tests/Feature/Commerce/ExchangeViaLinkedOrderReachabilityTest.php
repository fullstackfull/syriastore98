<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RET-003 AND RET-004 — "الاستبدال عبر طلب جديد مرتبط" AND "احتساب فروقات السعر والشحن والدفعات":
 * EXCHANGE VIA A NEW LINKED ORDER, AND THE PRICE / SHIPPING / PAYMENT DIFFERENCES.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE PINS A GAP, NOT A FEATURE. READ THE CASE MESSAGES BEFORE CHANGING ANYTHING HERE.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `RET-003` and `RET-004` are two of the seven `RET-*` decisions in
 * `docs/development/master-plan/DECISION-ID-REGISTER.md`. All seven appear in **neither**
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` **nor** `MASTER-PLAN-GAP-REGISTER.md` — measured by
 * grep over both on 2026-11-26.
 *
 * The *linking machinery* exists and is good: `orders.replaced_order_id`
 * (`2026_10_31_000300_add_replaced_order_id_to_orders_table.php`), a UNIQUE index on it
 * (`2026_11_01_000100`), and `OrderReplacement::cancelAndReplace()` under owner decision D-32. So
 * a reader who greps for "linked replacement" finds a built feature and could reasonably record
 * `RET-003` as satisfied.
 *
 * **IT IS NOT, AND THE REASON IS ONE EXACT, MEASURABLE FACT: the two conditions are mutually
 * exclusive.**
 *
 *   `Order::performProductReturn()`        refuses unless `delivered_status == 1`
 *                                          ("The product has not been delivered. Therefore you
 *                                           cannot return the product.")
 *   `OrderReplacement::cancelAndReplace()` refuses when `(int) $delivered_status !== 0`
 *                                          (`REFUSED_ALREADY_DISPATCHED`)
 *
 * An order that can be RETURNED has `delivered_status == 1`. An order that can be REPLACED must
 * have `delivered_status == 0`. **No order can be both**, so no returned order can be exchanged
 * through the only linked-replacement machinery this platform has. `cancelAndReplace()` serves
 * `ORD-006` — *correct the products of an order before it ships* — which is a different decision
 * that happens to share a column.
 *
 * A second, independent bar sits behind the first: `paymentEligibility()` refuses EVERY collected
 * payment, COD included, because `RefundIntegrityService::hasGatewayRefundDriver()` is a hardcoded
 * `false`. A delivered order is normally paid.
 *
 * And `RET-004` has no implementation to be blocked: D-32 narrowed replacement to *identical*
 * products, quantities and prices, and `cancelAndReplace()` copies `product_price`,
 * `coupon_price`, `delivery_price`, `tax_price` and `final_price` straight across. There is no
 * arithmetic anywhere that computes a difference between an original and its replacement, so
 * `RET-004` is blocked behind `RET-003` rather than partly present.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY ASSERT THE PRESENT BEHAVIOUR RATHER THAN BUILD THE EXCHANGE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * An exchange that accounts for price, shipping and payment differences decides what a customer is
 * charged or refunded on an uneven swap. That is a money rule, and the standing escalation rules
 * reserve prices, payments and business rules to the owner — so it is MEASURED and REPORTED, not
 * guessed at. This is the shape `GAP-262` already uses in this repository: *"Pinned meanwhile:
 * asserts the present behaviour with a control … so the decision shows up as a failing test rather
 * than as silence."*
 *
 * **WHEN AN EXCHANGE IS APPROVED, INVERT THESE CASES. DO NOT DELETE THEM.** A deletion would
 * return this decision to the silence it was in.
 */
class ExchangeViaLinkedOrderReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 11;
    private const OPERATOR = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        /*
         * THE PREMISE, ASSERTED. `cancelAndReplace()` answers `REFUSED_COLUMN_ABSENT` without this
         * column, which is a refusal for a reason that has nothing to do with these cases — every
         * one of them would pass while measuring the wrong thing.
         */
        $this->assertTrue(
            Schema::hasColumn('orders', 'replaced_order_id'),
            'orders.replaced_order_id must exist, or every refusal below is REFUSED_COLUMN_ABSENT'
        );
        $this->assertTrue(
            OrderReplacement::available(),
            'OrderReplacement must consider itself installed, or its refusals name the wrong cause'
        );

        $this->seedOperator();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | RET-003 — the mutual exclusion, driven on ONE order
    |--------------------------------------------------------------------------
    */

    public function test_an_order_that_can_be_returned_cannot_be_replaced(): void
    {
        /*
         * ONE ORDER, BOTH DOORS, so this is not two facts about two fixtures. `delivered_status`
         * is 1 — the only value at which a return is possible.
         */
        [$order] = $this->order(['delivered_status' => 1, 'payment_status' => 'Unpaid']);

        $returnVerdict = Order::product_return([
            'product_id' => $order->firstProductId,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);
        $this->assertSame(
            'success',
            $returnVerdict['status'],
            'the premise: at delivered_status 1 the return door accepts this order'
        );

        [$replacementVerdict, $replacement] = OrderReplacement::cancelAndReplace(
            Order::withoutGlobalScopes()->find($order->id),
            Auth::user()
        );

        $this->assertSame(
            OrderReplacement::REFUSED_ALREADY_DISPATCHED,
            $replacementVerdict,
            'RET-003 GAP: the one linked-replacement engine this platform has refuses every order '
                . 'that is far enough along to be RETURNED. `performProductReturn()` requires '
                . 'delivered_status == 1 and `cancelAndReplace()` refuses delivered_status != 0, so '
                . 'no returned order can be exchanged. INVERT this case when an exchange flow is '
                . 'approved; do not delete it.'
        );
        $this->assertNull($replacement, 'and no replacement order was created');
    }

    public function test_an_order_that_can_be_replaced_cannot_be_returned(): void
    {
        /* The other direction, so the exclusion is asserted from both ends rather than implied. */
        [$order] = $this->order(['delivered_status' => 0, 'payment_status' => 'Unpaid']);

        $returnVerdict = Order::product_return([
            'product_id' => $order->firstProductId,
            'variant_id' => 0,
            'order_id' => $order->id,
        ]);
        $this->assertSame(
            'error',
            $returnVerdict['status'],
            'at delivered_status 0 the return door refuses, which is the other half of the exclusion'
        );

        [$replacementVerdict] = OrderReplacement::cancelAndReplace(
            Order::withoutGlobalScopes()->find($order->id),
            Auth::user()
        );
        $this->assertSame(
            OrderReplacement::REPLACED,
            $replacementVerdict,
            'the control: at delivered_status 0 the replacement engine WORKS, so the refusal above '
                . 'is about the delivered state and not about a broken fixture'
        );
    }

    public function test_a_paid_delivered_order_is_refused_by_the_payment_bar_as_well(): void
    {
        /*
         * THE SECOND, INDEPENDENT BAR, named separately because closing the first would not reach
         * it. A delivered order is normally paid, and `paymentEligibility()` refuses every
         * collected payment — COD included, deliberately: a `Paid` COD order means the merchant
         * holds the shopper's cash, and handing it back is a real-world act this system cannot
         * observe. `RefundIntegrityService::hasGatewayRefundDriver()` is a hardcoded `false`, so
         * this covers every payment type rather than a subset.
         */
        [$order] = $this->order(['delivered_status' => 0, 'payment_status' => 'Paid', 'payment_type' => 'cod']);

        $this->assertSame(
            OrderReplacement::REFUSED_PAYMENT_COLLECTED,
            OrderReplacement::paymentEligibility(Order::withoutGlobalScopes()->find($order->id)),
            'a collected payment is refused even at delivered_status 0, so an exchange flow needs '
                . 'a settlement rule as well as a state change'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RET-004 — there is no difference arithmetic to block
    |--------------------------------------------------------------------------
    */

    public function test_a_replacement_carries_every_total_across_unchanged(): void
    {
        [$order] = $this->order([
            'delivered_status' => 0,
            'payment_status' => 'Unpaid',
            'product_price' => 80,
            'delivery_price' => 15,
            'tax_price' => 5,
            'coupon_price' => 0,
            'final_price' => 100,
        ]);

        [$verdict, $replacement] = OrderReplacement::cancelAndReplace(
            Order::withoutGlobalScopes()->find($order->id),
            Auth::user()
        );
        $this->assertSame(OrderReplacement::REPLACED, $verdict);

        $fresh = Order::withoutGlobalScopes()->find($replacement->id);

        /*
         * RET-004 GAP, STATED AS AN IDENTITY. Every money column is copied verbatim, so there is
         * nowhere in this platform that computes a price, shipping or payment DIFFERENCE between an
         * original and its replacement. That is D-32's narrowest reading (identical products,
         * quantities and prices) working exactly as approved — and it means RET-004 is blocked
         * behind RET-003 rather than partly built.
         */
        foreach (['product_price', 'delivery_price', 'tax_price', 'coupon_price', 'final_price'] as $column) {
            $this->assertSame(
                (float) $order->{$column},
                (float) $fresh->{$column},
                "RET-004 GAP: {$column} is carried across unchanged. No difference arithmetic "
                    . 'exists anywhere between an original order and its replacement. INVERT this '
                    . 'case when an uneven exchange is approved; do not delete it.'
            );
        }

        $this->assertSame(
            'Unpaid',
            (string) $fresh->payment_status,
            'the replacement starts owing the FULL amount rather than a difference — which is '
                . 'consistent only because paymentEligibility() refuses every collected original'
        );
    }

    public function test_the_lines_are_identical_so_nothing_can_be_swapped_for_something_else(): void
    {
        [$order] = $this->order(['delivered_status' => 0, 'payment_status' => 'Unpaid']);

        [$verdict, $replacement] = OrderReplacement::cancelAndReplace(
            Order::withoutGlobalScopes()->find($order->id),
            Auth::user()
        );
        $this->assertSame(OrderReplacement::REPLACED, $verdict);

        $originalLines = json_decode((string) $order->getRawOriginal('product_json'), true);
        $replacementLines = json_decode(
            (string) Order::withoutGlobalScopes()->find($replacement->id)->product_json,
            true
        );

        $this->assertCount(count($originalLines), $replacementLines);

        foreach ($originalLines as $index => $line) {
            foreach (['product_id', 'variant_id', 'qty', 'final_price'] as $field) {
                $this->assertSame(
                    $line[$field],
                    $replacementLines[$index][$field],
                    "RET-003 SCOPE: the replacement's {$field} is identical to the original's. The "
                        . 'engine cannot swap an item for a DIFFERENT one, which is what an '
                        . 'exchange is. Only `_stock_carried` is added.'
                );
            }
        }

        $this->assertSame(
            1,
            (int) ($replacementLines[0][Order::LINE_STOCK_CARRIED] ?? 0),
            'the one field the engine does add: the goods never came back, so nothing goes out again'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The link itself, which IS built and IS worth keeping
    |--------------------------------------------------------------------------
    */

    public function test_the_original_is_linked_from_the_replacement_and_survives_it(): void
    {
        [$order] = $this->order(['delivered_status' => 0, 'payment_status' => 'Unpaid']);
        $originalId = $order->id;

        [, $replacement] = OrderReplacement::cancelAndReplace(
            Order::withoutGlobalScopes()->find($originalId),
            Auth::user()
        );

        $this->assertSame(
            $originalId,
            (int) Order::withoutGlobalScopes()->find($replacement->id)->replaced_order_id,
            'the link RET-003 asks for does exist, recorded on the replacement'
        );

        /*
         * AND RET-007's HALF OF IT: *"الاحتفاظ بالطلب الأصلي وسجل البيع دون محوه"* — the original
         * order survives, cancelled rather than erased. Asserted RAW, because `Order` carries no
         * StoreScope and `withoutGlobalScopes()` is what makes this a question about the TABLE.
         */
        $this->assertSame(
            1,
            DB::table('orders')->where('id', $originalId)->count(),
            'RET-007: the original order row must still be there'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * `super admin`, because `OrderPolicy::allow()` tests `isPlatformAdmin` FIRST and that limb is
     * seed-independent — the `Manage Order` row is created by `Utility::addNewData()`, which is not
     * run here. `cancelAndReplace()` authorises through `Gate::forUser($actor)` on that policy.
     */
    private function seedOperator(): void
    {
        DB::table('users')->insert([
            'id' => self::OPERATOR, 'name' => 'Operator', 'email' => 'op@example.test',
            'type' => \App\Policies\UserPolicy::PLATFORM_ADMIN, 'password' => bcrypt('secret'),
            'current_store' => self::STORE_A, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::login(User::withoutGlobalScopes()->find(self::OPERATOR));

        DB::table('customers')->insert([
            'id' => 1, 'first_name' => 'Swap', 'last_name' => 'Buyer',
            'email' => 'swap@example.test', 'store_id' => self::STORE_A,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: Order} the order, with `firstProductId` set on it for convenience */
    private function order(array $attributes): array
    {
        $product = new Product();
        $product->forceFill([
            'name' => 'Product ' . uniqid('', false), 'track_stock' => 1,
            'stock_order_status' => 'not_allow', 'price' => 10, 'product_stock' => 9,
            'variant_product' => 0, 'store_id' => self::STORE_A, 'created_by' => self::OPERATOR,
        ]);
        $product->save();

        $lines = [[
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2,
            'price' => 40, 'final_price' => 80, 'name' => 'Line',
        ]];

        $order = new Order();
        $order->forceFill(array_merge([
            'product_order_id' => 'ORD-' . uniqid('', false), 'order_date' => now(),
            'customer_id' => 1, 'product_json' => json_encode($lines),
            'product_id' => (string) $product->id,
            'product_price' => 80, 'delivery_price' => 15, 'tax_price' => 5,
            'coupon_price' => 0, 'final_price' => 100,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'delivered_status' => 0, 'store_id' => self::STORE_A,
        ], $attributes));
        $order->save();

        $this->proveStockMovedOut($order);

        $order->firstProductId = $product->id;

        return [$order];
    }

    /**
     * The stock evidence D-15 conditions 4 and 5 require, copied in shape from
     * `OrderReplacementTest::proveStockMovedOut()` for the reason that file states.
     *
     * Without it `stockEvidence()` reads `unknown` for every line and
     * `cancelAndReplace()` answers `REFUSED_STOCK_EVIDENCE_UNKNOWN` — so the CONTROL cases above
     * would read as refusals for a reason that has nothing to do with the delivered state, and the
     * mutual exclusion this file exists to measure would be invisible behind it.
     *
     * `variant_id` is coerced to NULL rather than 0: `movedOutFor()` matches a product-only line
     * with `whereNull('variant_id')` while `record()` stores a literal 0, so a 0 here records a row
     * the lookup can never find and the fixture would look seeded while reading `unknown`.
     */
    private function proveStockMovedOut(Order $order): void
    {
        foreach ((array) json_decode((string) $order->product_json, true) as $line) {
            \App\Services\StockMovement::record([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'product_id' => $line['product_id'] ?? null,
                'variant_id' => ($line['variant_id'] ?? null) ?: null,
                'direction' => \App\Services\StockMovement::OUT,
                'quantity' => (int) ($line['qty'] ?? 1),
                'applied' => true,
                'reason' => \App\Services\StockMovement::REASON_ORDER_PLACE,
            ]);
        }
    }
}

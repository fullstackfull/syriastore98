<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GAP-261 / P04-P6-04 (FIN) — A ZERO-VALUE RETURNED LINE PLUS A FLAT COUPON RAISES THE ORDER TOTAL.
 * =================================================================================================
 *
 * `Order::product_return()`'s money half sums the `final_price` of every flagged line and then
 * subtracts the order's coupon from that sum, once:
 *
 *     $return_price += (float) ($product['final_price'] ?? 0);   // over the flagged lines
 *     ...
 *     $return_price -= $coupon_price;                            // a FLAT coupon: the whole number
 *     $final_price   = $original_total - $return_price;
 *
 * NOTHING FLOORED THAT SUBTRACTION. When the flagged lines are worth LESS than the flat coupon the
 * returned total is negative, and `$original_total - (-c)` is `$original_total + c`: the amount
 * owed goes UP because goods came back. On a `cod` order `orders.final_price` is the cash a driver
 * collects at the door, so this harms the CUSTOMER — the opposite direction from the rest of this
 * family, which is why GAP-261 is recorded on its own rather than as a footnote to GAP-140.
 *
 * HOW A FLAGGED LINE COMES TO BE WORTH ZERO, which is what makes this reachable rather than
 * theoretical. `AccountProfileController::order_refund_request` (the storefront refund request)
 * rescales the line it refunds:
 *
 *     $product['qty']         = $product['qty'] - $units[$key];
 *     $product['final_price'] = ($product['final_price'] * $product['qty']) / $old_qty;
 *     $product['refunded_qty'] = (int) ($product['refunded_qty'] ?? 0) + $units[$key];
 *
 * A line whose every unit is refunded is therefore stored as `{qty: 0, final_price: 0,
 * refunded_qty: N}` — still a line of the order, still matched by `product_return()`'s loop, so
 * the "names no line" guard (`$matchedLine`) does NOT cover it: a line DOES match. GAP-140 stops
 * that line being restocked or stamped (`creditableReturnQty()` is 0), and deliberately left the
 * money half alone — which is where this lives.
 *
 * THE DEFECT IS NOT CONFINED TO A ZERO LINE. Zero is the reachable extreme; the property that
 * fails is "the returned total is never negative", and ANY flagged set worth less than the flat
 * coupon fails it. `test_a_small_line_worth_less_than_the_flat_coupon_does_not_raise_the_total`
 * drives a 5.00 line against a 10.00 coupon for exactly that reason, and it is the case on which
 * the two candidate fixes disagree — see THE PRICING DECISION below.
 *
 * THE PRICING DECISION, MEASURED BEFORE IT WAS MADE. The gap register offers two shapes:
 *
 *   (A) CLAMP THE RETURNED TOTAL AT ZERO once the coupon has been taken off:
 *       `$return_price = max(0.0, $return_price - $coupon_price)`.
 *   (B) SKIP THE COUPON ADJUSTMENT when no line contributed value:
 *       `if ($return_price > 0) { $return_price -= $coupon_price; }`.
 *
 * They agree on the zero-line case and they DIFFER on a partial return: with lines of 5.00 and
 * 40.00 under a 10.00 flat coupon, returning only the 5.00 line gives (A) a returned total of 0.00
 * and an unchanged amount owed, and (B) a returned total of -5.00 and an amount owed 5.00 HIGHER.
 * Measured, both, by the cases below. (B) therefore closes only the extreme and leaves the family
 * open, so (A) is implemented. It is also the shape this codebase already uses for the same
 * question one door along: `OrderRefundController`'s settlement writes
 * `$order->final_price = max(0.0, $grandTotal)` and records that PRORATING a flat coupon across
 * refunded lines is a revenue-reporting decision rather than something to guess at. This change
 * guesses at nothing either: a return worth less than the coupon credits the customer NOTHING,
 * which is what the un-prorated coupon implies, and never charges them more.
 *
 * BOTH COPIES OF THE FORMULA MOVE TOGETHER. `Order::returnedAmountOf()` is documented as "the same
 * formula as the summation in product_return()" and
 * `SecondReturnTotalTest::test_the_prior_amount_helper_agrees_with_the_summation_it_copies` pins
 * the two against each other. The helper rebuilds what EARLIER returns took off, so a clamp in one
 * and not the other would make the reconstructed base disagree with the figure actually written —
 * `test_the_clamp_is_in_both_copies_of_the_formula` drives the helper directly, and
 * `test_a_second_real_line_after_a_zero_value_return_still_lands_on_the_right_total` drives the
 * sequence that depends on them agreeing.
 *
 * WHY THE "IT SETTLES" NOTE IN THE REGISTER IS NOT A REASON TO LEAVE IT. The inflated figure does
 * wash out of the NEXT call's reconstructed base, so an order that is fully returned afterwards
 * ends on the right number. Between the two calls the merchant's order screen, and a cod driver's
 * collection figure, carry `original + c` — and nothing obliges a second call ever to happen.
 *
 * DRIVEN DIRECTLY, NOT THROUGH THE ROUTES, for the reason `SecondReturnTotalTest` gives: the
 * authorization of the four callers (owning customer, assigned driver, merchant with `Manage
 * Order`, operator) is covered by `OrderReturnOwnershipTest` and
 * `StorefrontOrderMutationAuthorizationTest`, and the defect is arithmetic wholly inside this
 * method. Driving it directly is what lets the fixture hold a refund-emptied line without a full
 * storefront checkout and a refund settlement.
 *
 * GAP-331 -- THIS SUITE BUILT ITS OWN TABLES TOO, and the sentence above used to say so as though
 * it were a justification: "the schema is built here, like that suite's". It was the same scaffold
 * as `SecondReturnTotalTest`'s, copied -- the same six tables and the same invented
 * `orders.order_status`, which no migration creates. So ten more green cases were green against a
 * schema that does not ship. Converted to `RefreshDatabase` with no change to any fixture value or
 * assertion; the three divergences that scaffold carried are set out in that suite's docblock, and
 * the one that is not inert is NULLABILITY: the hand-built `final_price` was NOT NULL, so the null
 * total `GAP-294` measured in production could not occur in a suite that computes from it.
 */
class ZeroValueReturnCouponTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 37;

    /** The line a refund has already emptied, and the line that still carries value. */
    private const EMPTIED_PRODUCT = 201;
    private const LIVE_PRODUCT = 202;
    private const SMALL_PRODUCT = 203;

    private const LIVE_PRICE = 40.00;
    private const SMALL_PRICE = 5.00;
    private const TAX = 17.50;
    private const COUPON = 10.00;

    /** 0.00 (emptied) + 40.00 of goods, plus 17.50 of tax. */
    private const ORDER_TOTAL = 57.50;

    /**
     * The money columns this suite's arithmetic is about -- asserted to exist, never created.
     *
     * @var list<string>
     */
    private const MONEY_COLUMNS = [
        'product_price', 'tax_price', 'delivery_price', 'coupon_price',
        'final_price', 'return_price', 'delivered_status', 'return_status', 'product_json',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /* `RefreshDatabase` runs `migrate:fresh` on whatever connection it is given, so pointing
           this suite at a real database would drop it. Kept from the hand-built version. */
        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );
    }

    /** GAP-331's premise: the schema this suite reasons about is the one the migrations ship. */
    public function test_premise_every_money_column_this_suite_reasons_about_is_in_the_migrated_schema(): void
    {
        $this->assertTrue(Schema::hasTable('orders'), 'premise: `orders` is a migrated table');

        $missing = array_values(array_filter(
            self::MONEY_COLUMNS,
            static fn (string $column): bool => ! Schema::hasColumn('orders', $column)
        ));

        $this->assertSame(
            [],
            $missing,
            'this suite computes coupon-clamped returns from columns the migrated `orders` table '
            . 'does not have: ' . implode(', ', $missing) . '. It used to build the table itself, '
            . 'which is why a column that does not ship went unnoticed. Do not answer this by '
            . 'building the table here again.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — without these the cases below prove nothing
    |--------------------------------------------------------------------------
    */

    /**
     * THE SHAPE IS THE ONE THE REFUND REQUEST REALLY WRITES.
     *
     * The fixture hand-builds `{qty: 0, final_price: 0, refunded_qty: N}` rather than driving a
     * refund, so this pins that the writer it simulates still writes it. Lesson 8 of the project
     * skill: a simulation that drifts from the thing it simulates measures nothing.
     */
    public function test_premise_the_storefront_refund_request_rescales_a_line_to_zero(): void
    {
        $body = $this->sourceOf(
            \App\Http\Controllers\AccountProfileController::class,
            'order_refund_request'
        );

        $this->assertStringContainsString(
            "\$product['final_price'] = (\$product['final_price'] * \$product['qty']) / \$old_qty;",
            $body,
            'the storefront refund request no longer rescales a line\'s final_price, so the '
            . '{qty: 0, final_price: 0} shape this suite is built on may no longer be reachable '
            . 'that way. Re-read it before trusting these cases.'
        );

        $this->assertStringContainsString(
            "\$product['qty'] = \$product['qty'] - \$units[\$key];",
            $body,
            'the storefront refund request no longer reduces the line quantity'
        );
    }

    /**
     * AND THE RETURN WORKFLOW STILL ACCEPTS SUCH A LINE — it is not refused upstream.
     *
     * `linesAreReturnable()` requires only that `product_id` and `qty` EXIST, and the emptied line
     * carries both. If a future guard refused the line instead, this suite would be measuring that
     * refusal and not the arithmetic, so the premise is asserted rather than assumed.
     */
    public function test_premise_a_refund_emptied_line_is_still_matched_and_flagged(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();

        $result = Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::EMPTIED_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $result['status'] ?? null,
            'the emptied line was refused, so nothing here measures the money half: '
            . ($result['message'] ?? ''));

        $lines = $this->linesOf($order->id);

        $this->assertSame(1, (int) $lines[0]['return'],
            'premise: the emptied line IS matched and flagged — the "names no line" guard does '
            . 'not cover it, which is why GAP-261 is not GAP-140');
        $this->assertSame(0, (int) $lines[1]['return'], 'premise: the live line is untouched');
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT
    |--------------------------------------------------------------------------
    */

    /**
     * THE MEASUREMENT GAP-261 RECORDS.
     *
     * Before the fix: 57.50 -> 67.50. The amount owed rises by exactly the flat coupon because the
     * returned total is `0.00 - 10.00`.
     */
    public function test_returning_a_refund_emptied_line_under_a_flat_coupon_does_not_raise_the_total(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        $result = Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::EMPTIED_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $result['status'] ?? null,
            'premise: the return was accepted: ' . ($result['message'] ?? ''));

        $this->assertSame(
            self::ORDER_TOTAL,
            $this->totalOf($order->id),
            'THE AMOUNT OWED WENT UP BECAUSE GOODS CAME BACK. A line a refund has already emptied '
            . 'contributes 0.00 to the returned total, the flat coupon then makes that total '
            . '-' . number_format(self::COUPON, 2) . ', and final_price = original - (-c) adds the '
            . 'coupon to the order. On this cod order that figure is the cash collected at the '
            . 'door. Measured before the fix: ' . number_format(self::ORDER_TOTAL + self::COUPON, 2)
            . ' where ' . number_format(self::ORDER_TOTAL, 2) . ' is owed.'
        );
    }

    /**
     * THE RESIDUAL THIS ROW RECORDS, NOW PINNED INSTEAD OF ONLY WRITTEN DOWN (2026-11-26).
     * =============================================================================================
     *
     * `GAP-261`'s disposition records that `orders.final_price` CAN STILL END NEGATIVE, by a
     * different cause, and that it was "reported, not repaired" -- deliberately, because flooring
     * `final_price` here would hide the recorded-versus-configured coupon disagreement rather than
     * fix it (`orders.coupon_price` holds the coupon's CONFIGURED number while the child row holds
     * what was charged -- the P4-F134/F135 question).
     *
     * IT WAS RECORDED IN PROSE AND IN NO TEST. So the clamp's own boundary -- "it floors a NEGATIVE
     * returned total, and this total is positive" -- was not falsifiable: a later change that
     * floored `final_price` would close this silently, and a regression that widened it would be
     * equally silent. This case asserts the figure as it is. It is NOT a licence to leave it:
     * repairing it needs the coupon question settled first, and when it is, INVERT this case.
     *
     * THE ARITHMETIC, so a reviewer can check it without running anything. Goods 90.00 + 40.00,
     * `orders.coupon_price` 100.00 already taken off, so `final_price` is 30.00 at rest. The detail
     * row's `coupon_discount_number` is 0.00, so the clamp subtracts nothing.
     *
     *     first return  (90.00):  original 30.00,  returned max(0, 90-0)  =  90.00  ->  -60.00
     *     second return (40.00):  original -60.00 + 90.00 = 30.00,
     *                             returned max(0, 130-0) = 130.00         ->  -100.00
     *
     * On this `cod` order -100.00 is an instruction to hand 100.00 over at the door.
     */
    public function test_the_recorded_residual_still_drives_final_price_negative(): void
    {
        $this->productsFor([self::EMPTIED_PRODUCT => 90.00, self::LIVE_PRODUCT => self::LIVE_PRICE]);

        $order = $this->orderWithLines([
            [
                'product_id' => self::EMPTIED_PRODUCT, 'variant_id' => 0,
                'qty' => 1, 'final_price' => 90.00, 'return' => 0, 'store_id' => self::STORE,
            ],
            [
                'product_id' => self::LIVE_PRODUCT, 'variant_id' => 0,
                'qty' => 1, 'final_price' => self::LIVE_PRICE, 'return' => 0, 'store_id' => self::STORE,
            ],
        ], 130.00, [
            'coupon_price' => 100.00,
            'tax_price' => 0,
            /* The coupon is already off the payable, which is the state a real discounted order is in. */
            'final_price' => 30.00,
        ]);

        /* The detail row says the coupon took NOTHING off, which is the disagreement itself. */
        $this->attachCoupon($order, ['type' => 'flat', 'number' => 0.00]);

        foreach ([self::EMPTIED_PRODUCT, self::LIVE_PRODUCT] as $productId) {
            $result = Order::product_return([
                'order_id' => $order->id, 'product_id' => $productId, 'variant_id' => 0,
            ]);

            $this->assertSame('success', $result['status'] ?? null,
                'premise: the return was accepted: ' . ($result['message'] ?? ''));
        }

        $this->assertSame(130.00, $this->returnedOf($order->id),
            'premise: both lines were counted as returned, without which the total below is not '
            . 'the figure GAP-261 recorded');

        $this->assertSame(-100.00, $this->totalOf($order->id),
            'GAP-261\'s recorded residual has moved. It is NOT closed by the clamp -- the clamp '
            . 'floors a negative RETURNED total and this one is positive (130.00). If the '
            . 'recorded-versus-configured coupon question has been settled and this is now '
            . 'floored, INVERT this case and say which decision did it; do not delete it.');
    }

    /** AND THE RECORDED RETURNED VALUE IS NOT NEGATIVE EITHER. */
    public function test_the_returned_value_recorded_for_a_zero_line_is_never_negative(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::EMPTIED_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame(
            0.00,
            $this->returnedOf($order->id),
            'orders.return_price records "everything ever returned"; a negative figure there is '
            . 'not a rounding question. Measured before the fix: -'
            . number_format(self::COUPON, 2) . '.'
        );
    }

    /**
     * THE CASE THE TWO CANDIDATE FIXES DISAGREE ON, which is why it decides between them.
     *
     * A 5.00 line returned under a 10.00 flat coupon. "Skip the coupon when no line contributed
     * value" does not fire here — a line DID contribute — so that shape still writes
     * `5.00 - 10.00 = -5.00` and still raises the amount owed, by 5.00 instead of by 10.00.
     * Clamping the returned total at zero closes both.
     */
    public function test_a_small_line_worth_less_than_the_flat_coupon_does_not_raise_the_total(): void
    {
        $order = $this->orderWithASmallLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        $before = $this->totalOf($order->id);

        $result = Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::SMALL_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $result['status'] ?? null,
            'premise: the return was accepted: ' . ($result['message'] ?? ''));

        $this->assertLessThanOrEqual(
            $before,
            $this->totalOf($order->id),
            'returning a ' . number_format(self::SMALL_PRICE, 2) . ' line under a '
            . number_format(self::COUPON, 2) . ' flat coupon RAISED the amount owed from '
            . number_format($before, 2) . '. This is the case that rules out "skip the coupon when '
            . 'no line contributed value": a line did contribute, just less than the coupon.'
        );

        $this->assertSame(
            0.00,
            $this->returnedOf($order->id),
            'the returned total must be floored at zero, not left at '
            . number_format(self::SMALL_PRICE - self::COUPON, 2)
        );
    }

    /** NO COUPON SHAPE MAY RAISE THE AMOUNT OWED ON A RETURN. The whole property, in one case. */
    #[DataProvider('couponShapes')]
    public function test_no_return_of_any_line_under_any_coupon_raises_the_amount_owed(?array $coupon): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, $coupon);

        $before = $this->totalOf($order->id);

        foreach ([self::EMPTIED_PRODUCT, self::LIVE_PRODUCT] as $productId) {
            $result = Order::product_return([
                'order_id' => $order->id,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);

            $this->assertSame('success', $result['status'] ?? null,
                'premise: product ' . $productId . ' was accepted: ' . ($result['message'] ?? ''));

            $this->assertLessThanOrEqual(
                $before,
                $this->totalOf($order->id),
                'the amount owed rose above ' . number_format($before, 2) . ' after returning '
                . 'product ' . $productId . ' under '
                . ($coupon === null ? 'no coupon' : $coupon['type'] . ' coupon')
            );
            $this->assertGreaterThanOrEqual(
                0.0,
                $this->totalOf($order->id),
                'the amount owed went negative, which on a cod order is cash handed over at the door'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE CLAMP MUST BE IN BOTH COPIES OF THE FORMULA
    |--------------------------------------------------------------------------
    */

    /**
     * `returnedAmountOf()` rebuilds what EARLIER returns took off, using the same formula. Clamp
     * one and not the other and the reconstructed base disagrees with the figure that was written,
     * so the next return lands on the wrong total.
     */
    public function test_the_clamp_is_in_both_copies_of_the_formula(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        $helper = new ReflectionMethod(Order::class, 'returnedAmountOf');
        $helper->setAccessible(true);

        $emptiedLine = [[
            'product_id' => self::EMPTIED_PRODUCT, 'variant_id' => 0,
            'qty' => 0, 'refunded_qty' => 2, 'final_price' => 0, 'return' => 1,
        ]];

        $this->assertSame(
            0.0,
            $helper->invoke(null, $emptiedLine, $order->id),
            'returnedAmountOf() answers a NEGATIVE amount for an emptied line under a flat '
            . 'coupon. It reconstructs the base in product_return(), so this figure is added to '
            . 'the current total: a negative one understates the original order.'
        );
    }

    /**
     * AND THE SEQUENCE THAT DEPENDS ON THE TWO AGREEING: the emptied line, then the real one.
     *
     * Both lines are then returned, so what is still owed is the residual — the 17.50 of tax this
     * workflow deliberately does not recompute, plus the 10.00 of goods the shopper never paid
     * for because of the coupon. The same figure `SecondReturnTotalTest` asserts for two real
     * lines, which is the point: an emptied line must not change where the sequence lands.
     */
    public function test_a_second_real_line_after_a_zero_value_return_still_lands_on_the_right_total(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        foreach ([self::EMPTIED_PRODUCT, self::LIVE_PRODUCT] as $productId) {
            $result = Order::product_return([
                'order_id' => $order->id,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);
            $this->assertSame('success', $result['status'] ?? null,
                'premise: product ' . $productId . ' was accepted: ' . ($result['message'] ?? ''));
        }

        $this->assertSame(
            self::TAX + self::COUPON,
            $this->totalOf($order->id),
            'after both lines are returned the residual is the ' . number_format(self::TAX, 2)
            . ' of tax plus the ' . number_format(self::COUPON, 2) . ' the coupon took off the '
            . 'goods. A clamp in only one copy of the formula lands elsewhere.'
        );

        $this->assertSame(
            self::LIVE_PRICE - self::COUPON,
            $this->returnedOf($order->id),
            'return_price must record the cumulative returned value — the 40.00 live line less '
            . 'the coupon, once — with the emptied line contributing nothing'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE CONTROLS — the clamp must not change a return that was already right
    |--------------------------------------------------------------------------
    */

    /** A normal line return under a flat coupon is unchanged by the clamp. */
    public function test_control_a_line_worth_more_than_the_coupon_still_credits_the_difference(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => self::COUPON]);

        Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::LIVE_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame(
            self::LIVE_PRICE - self::COUPON,
            $this->returnedOf($order->id),
            'the clamp swallowed a positive returned value'
        );
        $this->assertSame(
            self::ORDER_TOTAL - (self::LIVE_PRICE - self::COUPON),
            $this->totalOf($order->id),
            'a 40.00 line under a 10.00 coupon credits 30.00; the clamp must not change that'
        );
    }

    /** And with no coupon at all, an emptied line still changes nothing. */
    public function test_control_an_emptied_line_with_no_coupon_changes_no_money(): void
    {
        $order = $this->orderWithAnEmptiedLineAndALiveLine();

        $before = [$this->totalOf($order->id), $this->returnedOf($order->id)];

        Order::product_return([
            'order_id' => $order->id,
            'product_id' => self::EMPTIED_PRODUCT,
            'variant_id' => 0,
        ]);

        $this->assertSame($before, [$this->totalOf($order->id), $this->returnedOf($order->id)],
            'an emptied line under no coupon moved money');
    }

    /** @return array<string, array{0: ?array}> */
    public static function couponShapes(): array
    {
        return [
            'no coupon' => [null],
            'flat coupon' => [['type' => 'flat', 'number' => self::COUPON]],
            'percentage coupon' => [['type' => 'percentage', 'number' => self::COUPON]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | FIXTURES
    |--------------------------------------------------------------------------
    */

    private function totalOf(int $orderId): float
    {
        return (float) DB::table('orders')->where('id', $orderId)->value('final_price');
    }

    private function returnedOf(int $orderId): float
    {
        return (float) DB::table('orders')->where('id', $orderId)->value('return_price');
    }

    /** @return array<int, array<string, mixed>> */
    private function linesOf(int $orderId): array
    {
        return json_decode((string) DB::table('orders')->where('id', $orderId)->value('product_json'), true);
    }

    /** The source of one method with comments stripped — a census must not be satisfied by prose. */
    private function sourceOf(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);

        $code = '';
        foreach (token_get_all((string) file_get_contents($r->getFileName())) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return implode("\n", array_slice(
            explode("\n", $code),
            $r->getStartLine() - 1,
            $r->getEndLine() - $r->getStartLine() + 1
        ));
    }

    /** @param ?array{type: string, number: float} $coupon */
    private function attachCoupon(Order $order, ?array $coupon): void
    {
        if ($coupon === null) {
            return;
        }

        /*
         * `forceFill()`, NOT `create()` — `OrderCouponDetail::$fillable` omits
         * `coupon_discount_number`, the column the return workflow reads for a flat coupon, so
         * `create()` drops it and the case measures a coupon that is not there.
         */
        (new OrderCouponDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'Ten off',
            'coupon_code' => 'TEN',
            'coupon_discount_type' => $coupon['type'],
            'coupon_discount_number' => $coupon['number'],
            'coupon_discount_amount' => $coupon['number'],
            'coupon_final_amount' => self::ORDER_TOTAL - $coupon['number'],
        ])->save();
    }

    /**
     * An order whose FIRST line a refund has already emptied and whose second still carries value.
     *
     * `track_stock => 0` on both, for the reason `SecondReturnTotalTest` gives: the restock half is
     * covered elsewhere and bringing it in here would make a stock failure read as a money defect.
     */
    private function orderWithAnEmptiedLineAndALiveLine(): Order
    {
        $this->productsFor([self::EMPTIED_PRODUCT => 45.00, self::LIVE_PRODUCT => self::LIVE_PRICE]);

        return $this->orderWithLines([
            [
                'product_id' => self::EMPTIED_PRODUCT,
                'variant_id' => 0,
                // EXACTLY what the storefront refund request leaves behind when every unit goes.
                'qty' => 0,
                'refunded_qty' => 2,
                'refunded_value' => 45.00,
                'final_price' => 0,
                'return' => 0,
                'store_id' => self::STORE,
            ],
            [
                'product_id' => self::LIVE_PRODUCT,
                'variant_id' => 0,
                'qty' => 1,
                'final_price' => self::LIVE_PRICE,
                'return' => 0,
                'store_id' => self::STORE,
            ],
        ], self::LIVE_PRICE);
    }

    /** The partial-return case: a line worth LESS than the flat coupon, and a live line. */
    private function orderWithASmallLineAndALiveLine(): Order
    {
        $this->productsFor([self::SMALL_PRODUCT => self::SMALL_PRICE, self::LIVE_PRODUCT => self::LIVE_PRICE]);

        return $this->orderWithLines([
            [
                'product_id' => self::SMALL_PRODUCT,
                'variant_id' => 0,
                'qty' => 1,
                'final_price' => self::SMALL_PRICE,
                'return' => 0,
                'store_id' => self::STORE,
            ],
            [
                'product_id' => self::LIVE_PRODUCT,
                'variant_id' => 0,
                'qty' => 1,
                'final_price' => self::LIVE_PRICE,
                'return' => 0,
                'store_id' => self::STORE,
            ],
        ], self::SMALL_PRICE + self::LIVE_PRICE);
    }

    /** @param array<int, float> $prices product id => price */
    private function productsFor(array $prices): void
    {
        foreach ($prices as $id => $price) {
            $product = new Product();
            $product->forceFill([
                'id' => $id,
                'name' => 'Line ' . $id,
                'track_stock' => 0,
                'stock_order_status' => 'not_allow',
                'price' => $price,
                'product_stock' => 0,
                'variant_product' => 0,
                'store_id' => self::STORE,
                'created_by' => 1,
            ])->save();
        }
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function orderWithLines(array $lines, float $goods, array $overrides = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'product_order_id' => 'SS-GAP261-' . uniqid(),
            'customer_id' => 1,
            'store_id' => self::STORE,
            'product_json' => json_encode($lines),
            'product_price' => $goods,
            'tax_price' => self::TAX,
            'delivery_price' => 0,
            'coupon_price' => 0,
            'final_price' => $goods + self::TAX,
            'return_price' => 0,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 1,
            'return_status' => 0,
        ], $overrides))->save();

        return $order;
    }
}

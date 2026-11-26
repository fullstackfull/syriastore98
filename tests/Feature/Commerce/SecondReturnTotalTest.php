<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P4-F082 — RETURNING A SECOND LINE SUBTRACTS THE FIRST LINE A SECOND TIME.
 * =================================================================================================
 *
 * `Order::product_return()` sums the `final_price` of EVERY line whose `return` flag is set — a
 * CUMULATIVE figure, correctly, because `orders.return_price` is meant to record everything that
 * has ever been returned. It then writes
 *
 *     $final_price = ($Order->final_price - $return_price);
 *
 * and `$Order->final_price` is the total **as the previous return already reduced it**. So the
 * second return subtracts the first line's value all over again, and the third subtracts the first
 * two again, and so on.
 *
 * WORKED, AND MEASURED BY THE CASE BELOW. Two lines, 90.00 and 40.00, on an order whose total is
 * 147.50 because it carries 17.50 of tax:
 *
 *     first return (90.00)   147.50 - 90.00  =  57.50     correct
 *     second return (40.00)   57.50 - 130.00 = -72.50     WRONG; the residual tax is 17.50
 *
 * The error is exactly the previously-returned line's value, every time.
 *
 * THE METHOD'S OWN DOCBLOCK ALREADY SAID SO. At `app/Models/Order.php:1035-1039` it records that
 * "the second pass recomputes the total from the CURRENT `final_price` rather than from an
 * immutable base, so the money half compounds" — and the guard it then added keys on ONE line's
 * return flag, so it closes the SAME-line replay and leaves the distinct-line case wide open. Nine
 * existing tests drive this endpoint; every one of them returns a single line, which is why a
 * defect named in the source survived them all.
 *
 * WHO CAN REACH IT. The owning customer (`POST api/{slug}/product-return`), a merchant holding
 * `Manage Order` (`POST order-return`), the order's assigned delivery boy and the platform
 * operator — all of whom pass `resolveViewableOrder` — plus a third caller in the vendored
 * `LotteryPrizes` module.
 *
 * GAP-331 -- THIS SUITE USED TO BUILD ITS OWN TABLES, AND THEY WERE NOT THE ONES THAT SHIP.
 * =================================================================================================
 *
 * It created `stores`, `settings`, `products`, `product_variants`, `orders` and
 * `order_coupon_details` for itself and dropped them again, so sixteen green cases were green
 * against a schema no migration produces. That is how a residual figure came to be derived
 * off-schema and then quoted (`GAP-261`). It now runs under `RefreshDatabase`.
 *
 * IT DID NOT NEED TO. Measured column by column against the migrated schema before converting:
 * every table and every column it built exists, with ONE exception -- so this was a near-copy of
 * the real thing plus an invention, not a state the migrations make unreachable. The conversion
 * changed no fixture value and no assertion; all sixteen cases pass unaltered.
 *
 * THREE DIVERGENCES, NOT THE ONE THAT WAS REPORTED:
 *
 *   1. `orders.order_status`, which NO migration creates. In this codebase `order_status` is an
 *      output alias for `delivered_status` (`app/Models/Order.php:455`) and an INPUT parameter
 *      name (`:698`) -- never a column. The fixture set it to 1 beside `delivered_status => 1`,
 *      so it was duplicating the real column; dropping it is a semantic no-op. Against the
 *      migrated schema the old fixture died with "table orders has no column named order_status".
 *
 *   2. MONEY TYPE. It declared `final_price`/`return_price` as `decimal(15, 2)`; the migration
 *      declares `$table->float()` (`2024_01_23_061635_create_orders_table.php:26-27`), which
 *      CLAUDE.md records emits a MySQL `double`. On SQLite both carry NUMERIC/REAL affinity and
 *      neither enforces a scale, which is why the figures did not move when this converted -- so
 *      this divergence was INERT HERE and would have bitten on MySQL. Stated at that strength and
 *      no higher.
 *
 *   3. NULLABILITY, and this one was NOT inert. The hand-built columns were NOT NULL; the shipped
 *      ones are nullable with a default of 0. MEASURED, both shapes on sqlite in one run: the
 *      migrated `orders` ACCEPTS a null `final_price`, the hand-built shape REFUSES it with a NOT
 *      NULL constraint violation. So the state `GAP-294` measured in production -- orders whose
 *      total is null -- was STRUCTURALLY UNREACHABLE in this suite, which is a return-arithmetic
 *      suite that computes from exactly that column. `GAP-316` is the refusal path for it.
 *
 * `test_premise_every_money_column_this_suite_reasons_about_is_in_the_migrated_schema` now asserts
 * the premise rather than manufacturing it. Adding `order_status` back to `MONEY_COLUMNS` reds it,
 * which is the finding in one line.
 *
 * WHAT THIS SUITE DOES NOT CLAIM. It drives `Order::product_return()` directly rather than through
 * the two HTTP routes. The routes' authorization is already covered by `OrderReturnOwnershipTest`
 * and `StorefrontOrderMutationAuthorizationTest`; what was uncovered is the arithmetic, which is
 * entirely inside this method, and driving it directly is what lets the case set up two returnable
 * lines without a full storefront checkout.
 */
class SecondReturnTotalTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 31;

    /** 90.00 + 40.00 of goods, plus 17.50 of tax. */
    private const LINE_A_PRICE = 90.00;
    private const LINE_B_PRICE = 40.00;
    private const TAX = 17.50;
    private const ORDER_TOTAL = 147.50;

    /**
     * The money columns this suite's arithmetic is about.
     *
     * Asserted to exist rather than created, which is the whole point of GAP-331: a figure derived
     * from a table this file built itself is a figure about nothing that ships.
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

        /* KEPT FROM THE HAND-BUILT VERSION, and still earning its place: `RefreshDatabase` runs
           `migrate:fresh` on whatever connection it is given, so pointing this suite at a real
           database would drop it. The guard is cheaper than the mistake. */
        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );
    }

    /**
     * GAP-331's OWN PREMISE: the schema this suite reasons about is the one the migrations ship.
     *
     * Under `RefreshDatabase` a missing column is already a hard error on the first insert, so this
     * is belt-and-braces -- but it names the columns, so a failure says WHICH money column moved
     * instead of surfacing as "no such column" from inside a fixture.
     */
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
            'this suite computes returns from columns the migrated `orders` table does not have: '
            . implode(', ', $missing) . '. Before GAP-331 this file CREATED its own `orders`, so a '
            . 'column that does not ship could not be noticed -- and one was not: `order_status`, '
            . 'which no migration creates. Do not answer this by building the table here again.'
        );
    }

    /**
     * THE PREMISE AND THE POSITIVE CONTROL: one return is already right.
     *
     * Without this the failing case below is satisfied by a method that is broken on the FIRST
     * return too, and "the second return is wrong" would be an unsupported reading of a figure
     * that was never right. It also pins what the fix must not change.
     */
    public function test_premise_the_first_return_reduces_the_total_by_exactly_that_line(): void
    {
        $order = $this->orderWithTwoLines();

        $result = Order::product_return([
            'order_id' => $order->id,
            'product_id' => 101,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $result['status'] ?? null,
            'the first return was refused, so nothing below measures arithmetic: '
            . ($result['message'] ?? ''));

        $this->assertSame(
            self::ORDER_TOTAL - self::LINE_A_PRICE,
            $this->totalOf($order->id),
            'the FIRST return is already wrong, so this suite cannot attribute anything to the '
            . 'second one'
        );

        $this->assertSame(
            self::LINE_A_PRICE,
            $this->returnedOf($order->id),
            'premise: return_price records the line that was returned'
        );
    }

    /**
     * THE DEFECT.
     *
     * Two DISTINCT lines, returned one after the other — not the same line twice, which the
     * existing replay guard already refuses and which every existing test drives.
     */
    public function test_a_second_distinct_line_does_not_subtract_the_first_one_again(): void
    {
        $order = $this->orderWithTwoLines();

        $first = Order::product_return([
            'order_id' => $order->id,
            'product_id' => 101,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $first['status'] ?? null,
            'premise: the first return succeeded');

        $second = Order::product_return([
            'order_id' => $order->id,
            'product_id' => 102,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $second['status'] ?? null,
            'premise: the second, DIFFERENT line was accepted — if it was refused, this case is '
            . 'measuring the same-line replay guard instead of the arithmetic: '
            . ($second['message'] ?? ''));

        /*
         * CUMULATIVE, AND CORRECT ALREADY. Asserted so the fix cannot be made by breaking the
         * summation instead of the base — `return_price` is the one figure this method gets
         * right and it must stay right.
         */
        $this->assertSame(
            self::LINE_A_PRICE + self::LINE_B_PRICE,
            $this->returnedOf($order->id),
            'return_price must record everything ever returned'
        );

        $this->assertSame(
            self::TAX,
            $this->totalOf($order->id),
            'the second return subtracted the FIRST line all over again. Both lines are returned, '
            . 'so what is still owed is the residual — here the 17.50 of tax, which this workflow '
            . 'deliberately does not recompute. Measured before the fix: -72.50, which is '
            . 'exactly 17.50 minus the 90.00 first line counted twice.'
        );
    }

    /**
     * AND IT MUST NOT GO NEGATIVE, stated as its own property.
     *
     * `orders.final_price` is the amount OWED — for a `cod` order it is the cash a driver
     * collects. A negative value there is not a rounding question; it is an instruction to hand
     * money to the customer at the door, printed on the merchant's own order screen.
     */
    public function test_returning_every_line_never_leaves_a_negative_amount_owed(): void
    {
        $order = $this->orderWithTwoLines();

        foreach ([101, 102] as $productId) {
            Order::product_return([
                'order_id' => $order->id,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);
        }

        $this->assertGreaterThanOrEqual(
            0.0,
            $this->totalOf($order->id),
            'a fully returned order owes a negative amount, which on a cod order is a driver '
            . 'handing cash over at the door'
        );
    }

    /**
     * A FLAT COUPON IS SUBTRACTED ONCE, NOT ONCE PER RETURN.
     *
     * The coupon limb runs on every call and subtracts the whole discount from the CUMULATIVE
     * returned value, which is right — one coupon, one subtraction. Pinned because a fix aimed at
     * the base could easily "tidy" this into a per-call subtraction, which would credit the
     * shopper the discount twice.
     */
    public function test_a_flat_coupon_is_applied_once_across_two_returns(): void
    {
        $order = $this->orderWithTwoLines();

        /*
         * `forceFill()`, NOT `create()`. `OrderCouponDetail::$fillable` omits
         * `coupon_discount_number` -- the very column the return workflow reads for a flat
         * coupon -- so `create()` dropped it, the discount resolved to 0.00 and this case
         * measured a coupon that was not there. Caught by the case's own expected value.
         */
        (new OrderCouponDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'Ten off',
            'coupon_code' => 'TEN',
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => 10.00,
            'coupon_discount_amount' => 10.00,
            'coupon_final_amount' => self::ORDER_TOTAL - 10.00,
        ])->save();

        foreach ([101, 102] as $productId) {
            Order::product_return([
                'order_id' => $order->id,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);
        }

        $this->assertSame(
            self::LINE_A_PRICE + self::LINE_B_PRICE - 10.00,
            $this->returnedOf($order->id),
            'the 10.00 coupon must come off the returned value exactly once across both returns'
        );

        $this->assertSame(
            self::TAX + 10.00,
            $this->totalOf($order->id),
            'with a 10.00 coupon the residual is the 17.50 tax plus the 10.00 the shopper never '
            . 'paid for the goods'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE RETURN REQUEST FLOW OVERWRITES THE COLUMN THE BASE ONCE TRUSTED
    |--------------------------------------------------------------------------
    |
    | FOUND BY THE PR #140 DELETION AUDIT'S ADVERSARIAL PASS, and no test above reaches it because
    | every fixture seeds `return_price = 0`.
    |
    | `OrderController::order_return_request` -- the whole-order return REQUEST, approve and reject
    | alike -- runs
    |
    |     $order->return_price = $order->final_price;
    |     $order->return_status = $status;
    |
    | and does NOT reduce `final_price`. The base this method rebuilt as `final_price +
    | return_price` therefore became `2 x final_price`, and the next line return wrote a total
    | HIGHER than the original: 205.00 on a 147.50 order returning a 90.00 line, where the code
    | before F082 wrote 57.50. The customer owes MORE because goods came back.
    |
    | These cases set the state exactly as that controller sets it, rather than driving the
    | controller, because the controller needs an authenticated merchant and a policy and the
    | defect is in this method's arithmetic. The first test pins that the controller really does
    | write it, so the simulation cannot drift from the thing it simulates.
    */

    /** Exactly what OrderController::order_return_request writes inside its transaction. */
    private function applyReturnRequestFlow(int $orderId, int $status): void
    {
        $order = Order::find($orderId);
        $order->return_price = $order->final_price;
        $order->return_status = $status;
        $order->save();
    }

    public function test_premise_the_return_request_flow_copies_the_whole_total_into_return_price(): void
    {
        $r = new ReflectionMethod(\App\Http\Controllers\OrderController::class, 'order_return_request');

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

        $body = implode("\n", array_slice(explode("\n", $code), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));

        $this->assertStringContainsString(
            '$order->return_price = $order->final_price;',
            $body,
            'order_return_request no longer copies final_price into return_price. If that was fixed, '
            .'the guard in Order::product_return that stops trusting the column is still correct but '
            .'this premise -- and the three cases below that simulate it -- need re-reading.'
        );
    }

    public function test_a_line_return_after_an_approved_return_request_does_not_double_the_base(): void
    {
        $order = $this->orderWithTwoLines();
        $this->applyReturnRequestFlow($order->id, 2);

        $result = Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);

        $this->assertSame('success', $result['status'] ?? null, 'premise: the return was accepted: '.($result['message'] ?? ''));
        $this->assertSame(
            self::ORDER_TOTAL - self::LINE_A_PRICE,
            $this->totalOf($order->id),
            'The amount owed went UP after a return. After an approved return request the stored '
            .'return_price equals final_price, and a base rebuilt as their sum is twice the order. '
            .'Expected '.(self::ORDER_TOTAL - self::LINE_A_PRICE).'.'
        );
    }

    public function test_a_line_return_after_a_rejected_return_request_does_not_double_the_base(): void
    {
        // REJECT sets it too -- the assignment is not conditional on the status. So a customer
        // whose whole-order request was REFUSED and who then returns one line pays more for it.
        $order = $this->orderWithTwoLines();
        $this->applyReturnRequestFlow($order->id, 3);

        Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);

        $this->assertSame(self::ORDER_TOTAL - self::LINE_A_PRICE, $this->totalOf($order->id));
    }

    public function test_a_request_after_a_partial_return_cannot_drive_the_next_return_negative(): void
    {
        // THE ORDERING THAT DEFEATS "TREAT THE COLUMN AS ZERO UNTIL A LINE IS FLAGGED". Return A
        // (return_price = 90.00, owed 57.50), THEN a request overwrites return_price with the
        // 57.50 total. A guard that trusted the column once any line was flagged would read 57.50
        // as "already taken off", rebuild a base of 115.00, subtract the 130.00 cumulative, and
        // write -15.00: an instruction to hand cash to the customer at the door.
        $order = $this->orderWithTwoLines();

        Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);
        $this->assertSame(self::ORDER_TOTAL - self::LINE_A_PRICE, $this->totalOf($order->id), 'premise: first return');

        $this->applyReturnRequestFlow($order->id, 2);
        $this->assertSame($this->totalOf($order->id), $this->returnedOf($order->id), 'premise: the request flow overwrote return_price');

        Order::product_return(['order_id' => $order->id, 'product_id' => 102, 'variant_id' => 0]);

        $this->assertSame(
            self::TAX,
            $this->totalOf($order->id),
            'Both lines are returned, so what is still owed is the 17.50 of tax. The base was '
            .'rebuilt from the overwritten column instead of from the line flags.'
        );
        $this->assertGreaterThanOrEqual(0.0, $this->totalOf($order->id), 'the amount owed went negative');
        $this->assertSame(
            self::LINE_A_PRICE + self::LINE_B_PRICE,
            $this->returnedOf($order->id),
            'return_price must be rewritten as the cumulative figure by the return itself'
        );
    }

    /** @return array<string, array{0: ?array}> no coupon, flat, percentage */
    public static function couponShapes(): array
    {
        return [
            'no coupon' => [null],
            'flat coupon' => [['type' => 'flat', 'number' => 10.00]],
            'percentage coupon' => [['type' => 'percentage', 'number' => 10.00]],
        ];
    }

    /**
     * THE TWO COPIES OF THE FORMULA STAY EQUAL.
     *
     * `returnedAmountOf()` is the summation in `product_return()` applied to an EARLIER set of
     * lines, kept as a copy because that summation is deliberately left alone. After a first
     * return the stored `return_price` IS the summation's answer for that one line, so the helper
     * must reproduce it. If anyone edits one copy, this fails.
     *
     */
    #[DataProvider('couponShapes')]
    public function test_the_prior_amount_helper_agrees_with_the_summation_it_copies(?array $coupon): void
    {
        $order = $this->orderWithTwoLines();

        $this->attachCoupon($order, $coupon);

        Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);
        $stored = $this->returnedOf($order->id);

        $helper = new ReflectionMethod(Order::class, 'returnedAmountOf');
        $helper->setAccessible(true);
        $oneLine = [['product_id' => 101, 'variant_id' => 0, 'qty' => 1, 'final_price' => self::LINE_A_PRICE, 'return' => 1]];

        $this->assertEqualsWithDelta(
            $stored,
            $helper->invoke(null, $oneLine, $order->id),
            0.005,
            'The helper and the summation disagree for '.($coupon === null ? 'no coupon' : $coupon['type'].' coupon')
            .'. After the first return, stored return_price is the summation\'s figure.'
        );
    }

    /** @param ?array{type: string, number: float} $coupon null for no coupon */
    private function attachCoupon(Order $order, ?array $coupon): void
    {
        if ($coupon === null) {
            return;
        }

        (new OrderCouponDetail())->forceFill([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'coupon_id' => 1,
            'coupon_name' => 'c',
            'coupon_code' => 'C',
            'coupon_discount_type' => $coupon['type'],
            'coupon_discount_number' => $coupon['number'],
            'coupon_discount_amount' => $coupon['number'],
            'coupon_final_amount' => self::ORDER_TOTAL - $coupon['number'],
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | A CALL THAT NAMES NO LINE MUST CHANGE NOTHING
    |--------------------------------------------------------------------------
    |
    | FOUND BY THE INDEPENDENT REVIEW OF THE AUD-2 FIX, not by any test and not by the audit.
    |
    | `product_id` and `variant_id` are REQUEST INPUT -- the owning customer, an assigned delivery boy,
    | a merchant holding `Manage Order` and the operator can all send them -- and nothing refused a pair
    | that matches no line of the order. With no line flagged, the summed `$return_price` is 0, a FLAT
    | coupon then makes it `0 - c`, and the money write subtracts that: `final_price` goes UP by the
    | coupon amount. The method before this fix had the same arithmetic ONCE (the next call's base read
    | the column it had just written, so it settled at F + c). The AUD-2 base takes "what earlier
    | returns took off" from the line FLAGS, a no-match call flags nothing, and so every repeat added
    | `c` again: F + c, F + 2c, F + 3c.
    |
    | Reachable by the shopper whose order it is, on a delivered order carrying a flat coupon, by POSTing
    | `product_id=0&variant_id=0` to `api/{slug}/product-return`. A percentage coupon and a no-coupon
    | order were unaffected (a percentage of 0 is 0), which is why the cases below run all three.
    */

    #[DataProvider('couponShapes')]
    public function test_a_call_that_names_no_line_changes_nothing_and_says_so(?array $coupon): void
    {
        $order = $this->orderWithTwoLines();
        $this->attachCoupon($order, $coupon);

        $before = [$this->totalOf($order->id), $this->returnedOf($order->id)];
        $statuses = [];
        $moneyAfterEach = [];

        foreach ([[0, 0], [999, 0], [101, 7]] as [$productId, $variantId]) {
            $result = Order::product_return(['order_id' => $order->id, 'product_id' => $productId, 'variant_id' => $variantId]);

            $statuses[] = $result['status'] ?? null;
            $moneyAfterEach[] = [$this->totalOf($order->id), $this->returnedOf($order->id)];
        }

        // MONEY FIRST, so a failure shows the inflation itself (F, F+c, F+2c) and not just a status.
        $this->assertSame(array_fill(0, 3, $before), $moneyAfterEach,
            'a call that names no line moved money. Before: '.json_encode($before));

        $this->assertSame(['error', 'error', 'error'], $statuses, 'a call that names no line was reported as a success');

        $lines = json_decode((string) DB::table('orders')->where('id', $order->id)->value('product_json'), true);
        $this->assertSame([0, 0], array_map(fn ($line) => (int) $line['return'], $lines), 'a no-match call flagged a line');
    }

    public function test_no_match_calls_after_a_real_return_leave_that_return_exactly_as_it_was(): void
    {
        $order = $this->orderWithTwoLines();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => 10.00]);

        Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);
        $afterFirst = [$this->totalOf($order->id), $this->returnedOf($order->id)];

        for ($i = 0; $i < 3; $i++) {
            Order::product_return(['order_id' => $order->id, 'product_id' => 555, 'variant_id' => 0]);
        }

        $this->assertSame($afterFirst, [$this->totalOf($order->id), $this->returnedOf($order->id)],
            'repeated no-match calls kept inflating an order that already had a return');

        // And the legitimate second return still lands on the right figure afterwards.
        Order::product_return(['order_id' => $order->id, 'product_id' => 102, 'variant_id' => 0]);

        $this->assertSame(self::TAX + 10.00, $this->totalOf($order->id),
            'both lines returned: the tax is still owed, and the flat coupon is added back exactly once');
    }

    /*
    |--------------------------------------------------------------------------
    | THE RETURN IS ONE UNIT OF WORK
    |--------------------------------------------------------------------------
    |
    | Also from that review. The line FLAG is saved first and the MONEY is written later, and the AUD-2
    | base trusts the flags. A failure between the two left a line flagged whose value was never taken
    | off: the replay guard then refuses a retry, and the next return's base counted that value as
    | already gone. (The column-based base this replaced healed it by accident.) The whole call -- the
    | restock, the flags and the money -- is now one transaction, the shape `order_return_request`
    | already uses, so there is no window to heal.
    */
    public function test_a_failure_after_the_flag_is_saved_leaves_no_flag_and_no_money_change(): void
    {
        $order = $this->orderWithTwoLines();
        $this->attachCoupon($order, ['type' => 'flat', 'number' => 10.00]);

        $before = [$this->totalOf($order->id), $this->returnedOf($order->id)];
        $linesBefore = DB::table('orders')->where('id', $order->id)->value('product_json');

        // The first thing the money half reads after the flag is saved is the coupon row. ONE-SHOT: a listener
        // cannot be unregistered, and this suite's own tearDown touches that table.
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'order_coupon_details')) {
                $armed = false;

                throw new \RuntimeException('injected failure between the flag and the money');
            }
        });

        try {
            Order::product_return(['order_id' => $order->id, 'product_id' => 101, 'variant_id' => 0]);
            $this->fail('the injected failure did not reach the method');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure between the flag and the money', $e->getMessage());
        }

        $this->assertSame($linesBefore, DB::table('orders')->where('id', $order->id)->value('product_json'),
            'the line stayed flagged although its money was never taken off');
        $this->assertSame($before, [$this->totalOf($order->id), $this->returnedOf($order->id)]);
    }

    private function totalOf(int $orderId): float
    {
        return (float) DB::table('orders')->where('id', $orderId)->value('final_price');
    }

    private function returnedOf(int $orderId): float
    {
        return (float) DB::table('orders')->where('id', $orderId)->value('return_price');
    }

    /**
     * An order this workflow accepts: delivered, with a `product_json` carrying two returnable
     * lines in the shape `linesAreReturnable()` requires.
     *
     * `track_stock => 0` on both products on purpose. The restock half of this method is already
     * covered by `ReturnApprovalStockTest` and `ReturnRestoresExactlyWhatWasTakenTest`; bringing
     * it in here would make this suite depend on the stock service and on `stock_movements`, and
     * a failure there would read as a money defect.
     */
    private function orderWithTwoLines(): Order
    {
        foreach ([101 => self::LINE_A_PRICE, 102 => self::LINE_B_PRICE] as $id => $price) {
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

        $lines = [
            [
                'product_id' => 101,
                'variant_id' => 0,
                'qty' => 1,
                'final_price' => self::LINE_A_PRICE,
                'return' => 0,
                'store_id' => self::STORE,
            ],
            [
                'product_id' => 102,
                'variant_id' => 0,
                'qty' => 1,
                'final_price' => self::LINE_B_PRICE,
                'return' => 0,
                'store_id' => self::STORE,
            ],
        ];

        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'SS-RET-1',
            'customer_id' => 1,
            'store_id' => self::STORE,
            'product_json' => json_encode($lines),
            'product_price' => self::LINE_A_PRICE + self::LINE_B_PRICE,
            'tax_price' => self::TAX,
            'delivery_price' => 0,
            'coupon_price' => 0,
            'final_price' => self::ORDER_TOTAL,
            'return_price' => 0,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 1,
            'return_status' => 0,
        ])->save();

        return $order;
    }
}

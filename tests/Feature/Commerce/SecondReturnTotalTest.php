<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * WHAT THIS SUITE DOES NOT CLAIM. It drives `Order::product_return()` directly rather than through
 * the two HTTP routes. The routes' authorization is already covered by `OrderReturnOwnershipTest`
 * and `StorefrontOrderMutationAuthorizationTest`; what was uncovered is the arithmetic, which is
 * entirely inside this method, and driving it directly is what lets the case set up two returnable
 * lines without a full storefront checkout.
 */
class SecondReturnTotalTest extends TestCase
{
    private const STORE = 31;

    /** 90.00 + 40.00 of goods, plus 17.50 of tax. */
    private const LINE_A_PRICE = 90.00;
    private const LINE_B_PRICE = 40.00;
    private const TAX = 17.50;
    private const ORDER_TOTAL = 147.50;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['order_coupon_details', 'orders', 'product_variants', 'products', 'settings', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
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
            'order_status' => 1,
            'delivered_status' => 1,
            'return_status' => 0,
        ])->save();

        return $order;
    }

    private function createSchema(): void
    {
        foreach (['order_coupon_details', 'orders', 'product_variants', 'products', 'settings', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('theme_id');
            $table->string('slug');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        /* `Order::order_status_change()` reaches Utility::GetValueByName() on the way in
           (app/Models/Order.php:174 -> Utility.php:221), which reads this table. Without it the
           whole suite erred before its first assertion. */
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('value')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock')->default(0);
            $table->string('stock_order_status')->default('not_allow');
            $table->decimal('price', 15, 2)->default(0);
            $table->integer('product_stock')->default(0);
            $table->integer('variant_product')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('stock')->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('product_order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->text('product_json')->nullable();
            $table->decimal('product_price', 15, 2)->default(0);
            $table->decimal('tax_price', 15, 2)->default(0);
            $table->decimal('delivery_price', 15, 2)->default(0);
            $table->decimal('coupon_price', 15, 2)->default(0);
            $table->decimal('final_price', 15, 2)->default(0);
            $table->decimal('return_price', 15, 2)->default(0);
            $table->string('payment_type')->nullable();
            $table->string('payment_status')->nullable();
            $table->integer('order_status')->default(0);
            $table->integer('delivered_status')->default(0);
            $table->integer('return_status')->default(0);
            /* An Order observer/booted hook stamps these on every insert, so the table needs them
               or the fixture dies before the method under test is ever called. */
            $table->string('currency_code')->nullable();
            $table->string('currency_symbol')->nullable();
            $table->timestamps();
        });

        Schema::create('order_coupon_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('product_order_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('coupon_name')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->decimal('coupon_discount_number', 15, 2)->default(0);
            $table->decimal('coupon_discount_amount', 15, 2)->default(0);
            $table->decimal('coupon_final_amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }
}

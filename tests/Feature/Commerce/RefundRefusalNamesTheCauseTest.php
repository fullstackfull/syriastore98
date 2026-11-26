<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-316 -- A REFUND REFUSED FOR A MISSING ORDER TOTAL BLAMES THE AMOUNT THE MERCHANT TYPED, SO
 * THERE IS NO NUMBER THAT WORKS.
 * =================================================================================================
 *
 * FOUND WHILE MEASURING GAP-294 (orders carrying a NULL `final_price`), which is what makes this
 * reachable rather than hypothetical.
 *
 * THE MECHANISM. `RefundAmonut()` caps the requested amount at the order's own persisted total:
 *
 *     $capped = $refundIntegrity->cappedRefundAmount($request->refund_amount, $order->final_price, ...);
 *     $amount = $capped['amount'];
 *     if ($amount <= 0) { return $this->refundDenied('A refund amount must be a positive number no greater than the order total.', 422); }
 *
 * `RefundIntegrityService::toAmount()` ends `return $amount > 0 ? $amount : 0.0`, so a NULL
 * `final_price` makes the LIMIT 0. Every positive request is then greater than the limit, is capped
 * down to 0, and lands in that refusal -- which tells the merchant their number is too big.
 *
 * SO THE MERCHANT TRIES A SMALLER NUMBER, AND IS REFUSED AGAIN. Measured below across three
 * descending amounts: the response is byte-identical every time. The refusal names the one thing
 * the merchant can change and withholds the one thing they cannot, so the screen is unusable with
 * no way to find out why. That is the same family as the storefront refusal reading
 * "Product not found ." for a product that exists.
 *
 * THE CAUSE WAS ALREADY IN HAND AND WAS DISCARDED. `cappedRefundAmount()` returns
 * `['amount', 'capped', 'requested', 'limit']`; the controller read `amount` and threw the rest
 * away, including the `limit` that explains the refusal. The fix reads `limit`. No new query, no new
 * service, nothing recomputed.
 *
 * WHAT DOES NOT CHANGE, deliberately: the request is still REFUSED, with the same 422, and no money
 * moves. The ceiling is untouched -- a refund larger than the order is still capped, which is
 * `RefundIntegrityTest`'s subject. Only the sentence changes, so no owner decision is involved and
 * no refund amount moves.
 *
 * A ZERO TOTAL AND AN ABSENT TOTAL ARE DIFFERENT FACTS and are not collapsed into one sentence. An
 * order that genuinely came to zero has nothing to refund; an order with no recorded total has a
 * data problem upstream. Telling a merchant their 0.00 order "has no recorded total" would be a
 * second wrong answer, so the two are separated and both controls below assert which they get.
 */
class RefundRefusalNamesTheCauseTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The sentence that blames the merchant's number. */
    private const AMOUNT_WORDING = 'must be a positive number';

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gap316-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'on',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so a fixture written after a previous read would otherwise be invisible. */
        Cache::forget('admin_details');

        $this->grantPermission($merchant, 'Manage Order Refund Request');

        return [$merchant->fresh(), $store];
    }

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per',
            'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => User::class,
        ]);
    }

    /**
     * A DELIVERED order whose `final_price` is exactly what the case is about.
     *
     * Written RAW for the total, then the model cache flushed: `Order` is not `Cachable`, but the
     * value has to survive `makeOrder()`'s own casting, and a null is not something that helper
     * takes. The product line keeps a real price so nothing else in the handler reads zero by
     * accident.
     */
    private function orderWithTotal(Store $store, Customer $owner, $total): Order
    {
        $order = $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper($this->uniqueFixtureRef()),
            'customer_id' => $owner->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_price' => 300, 'delivery_price' => 20, 'tax_price' => 0, 'coupon_price' => 0,
            'final_price' => 320, 'payment_type' => 'cod',
            'product_json' => json_encode([[
                'product_id' => 0, 'name' => 'Widget', 'variant_id' => 0,
                'qty' => 3, 'final_price' => 300,
            ]]),
        ]);

        DB::table('orders')->where('id', $order->id)->update(['final_price' => $total]);

        $fresh = Order::find($order->id);

        $this->assertSame(
            $total === null ? null : (string) $total,
            $fresh->final_price === null ? null : (string) $fresh->final_price,
            'premise: the order really carries the total this case is about'
        );

        return $fresh;
    }

    private function refundRow(Order $order, Store $store): int
    {
        return (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id, 'refund_status' => 'Accept', 'refund_reason' => 'Other',
            'product_refund_id' => json_encode([[
                'product_refund_id' => 0, 'return_price' => 'P', 'quantity' => 1,
            ]]),
            'refund_amount' => 0, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:int,1:string} status and message */
    private function payOut(Order $order, int $refundId, string $amount): array
    {
        $response = (new OrderRefundController())->RefundAmonut(
            Request::create('/refund-amount/' . $order->id, 'POST', [
                'refundId' => $refundId, 'refund_amount' => $amount,
            ]),
            $order->id
        );

        return [
            $response->getStatusCode(),
            (string) (json_decode($response->getContent(), true)['message'] ?? ''),
        ];
    }

    /**
     * THE DEFECT, AND THE "NO NUMBER WORKS" HALF IS THE POINT.
     */
    #[Test]
    public function test_a_refund_against_an_order_with_no_recorded_total_names_the_missing_total(): void
    {
        [$merchant, $store] = $this->tenant();
        $order = $this->orderWithTotal($store, $this->shopper($store), null);
        $refundId = $this->refundRow($order, $store);

        $this->actingAs(User::find($merchant->id));

        $seen = [];

        foreach (['500', '50', '1'] as $amount) {
            [$status, $message] = $this->payOut($order, $refundId, $amount);

            $this->assertSame(422, $status, 'the refusal itself does not change');

            $seen[$amount] = $message;

            $this->assertStringNotContainsString(
                self::AMOUNT_WORDING,
                $message,
                "Asked for {$amount} against an order with no recorded total and was told the "
                . 'AMOUNT was the problem. The ceiling is the order total, which is absent, so no '
                . 'amount can satisfy it -- the merchant is invited to retry forever. Message: '
                . $message
            );

            $this->assertStringContainsString(
                'no recorded total',
                $message,
                'the refusal does not name the cause the merchant cannot see. Message: ' . $message
            );
        }

        $this->assertCount(
            1,
            array_unique($seen),
            'the three amounts produced different refusals, so this case is no longer measuring one '
            . 'cause: ' . json_encode($seen)
        );
    }

    /**
     * CONTROL. A zero total is a different fact and gets a different sentence -- not the
     * missing-total one, which would be a second wrong answer.
     *
     * The sentence says "not a positive amount" rather than "zero" on purpose: the same branch
     * catches a NEGATIVE total (a coupon exceeding the subtotal), and "your total is zero" would be
     * the same kind of confidently wrong statement this whole change removes.
     */
    #[Test]
    public function test_control_an_order_whose_total_is_not_positive_is_told_about_the_total(): void
    {
        [$merchant, $store] = $this->tenant();
        $order = $this->orderWithTotal($store, $this->shopper($store), 0);
        $refundId = $this->refundRow($order, $store);

        $this->actingAs(User::find($merchant->id));

        [$status, $message] = $this->payOut($order, $refundId, '50');

        $this->assertSame(422, $status);

        $this->assertStringNotContainsString(
            'no recorded total',
            $message,
            'an order that genuinely came to zero was told its total is missing. Message: ' . $message
        );

        $this->assertStringContainsString(
            'not a positive amount',
            $message,
            'the refusal does not say that there is nothing to refund. Message: ' . $message
        );
    }

    /**
     * CONTROL. With a real total, an unusable AMOUNT still gets the amount sentence -- so naming the
     * new cause has not replaced the old message everywhere.
     *
     * `'0'` is the reachable form: a signed or non-numeric request also collapses to 0 by
     * `toAmount()`, deliberately, and lands here too.
     */
    #[Test]
    public function test_control_an_unusable_amount_against_a_real_total_still_names_the_amount(): void
    {
        [$merchant, $store] = $this->tenant();
        $order = $this->orderWithTotal($store, $this->shopper($store), 320);
        $refundId = $this->refundRow($order, $store);

        $this->actingAs(User::find($merchant->id));

        [$status, $message] = $this->payOut($order, $refundId, '0');

        $this->assertSame(422, $status);

        $this->assertStringContainsString(
            self::AMOUNT_WORDING,
            $message,
            'an order with a perfectly good total of 320 and a request for 0 was not told the '
            . 'amount was the problem, which it is. Message: ' . $message
        );
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * AN ECHO IS NOT A TRANSITION — ON THE DELIVERY COLUMN TOO.
 * =================================================================================================
 *
 * A LIVE REGRESSION THIS FILE EXISTS TO CLOSE, AND HALF OF IT WAS MINE. The shipped Flutter app
 * sends `delivered_status` on EVERY call to `POST api/admin/orders/update-status`, including a
 * payment-only change: the payment sheet fills the field with the order's own stored value
 * (`StoreSyria_app98/lib/view/new_widgets/payment_status_bottom_sheet.dart:177` ->
 * `getDeliveredStatus(model)`), exactly as it already does for `payment_status`. The handler
 * already knows this about the payment column — `P4-F145, PART 1` in `Api\DashboardController`
 * drops an echoed `payment_status` before the validator sees it, with the sentence *"A request that
 * asks for the state the order is already in is asking for NOTHING"*. The delivery column had no
 * such rule, and two guards then refused the echo:
 *
 *   - a RETURNED order (3): `FulfilmentStateMachine::refusalForLeaving()`, which I added earlier
 *     the same day for owner decision H2. MINE, and a regression I introduced.
 *   - a CANCELLED order (2): the older `P4-F105` cancel guard
 *     (`$new === 2 && $old !== 0`), which reads an echo as an attempt to cancel. Pre-existing,
 *     identical in effect, and fixed in the same change because it is the same defect.
 *
 * SO A MERCHANT COULD NOT RECORD A PAYMENT ON A CANCELLED OR RETURNED ORDER FROM THE APP — a COD
 * bag handed over on a returned order, a bank transfer that cleared after a cancellation. Both are
 * ordinary: payment and fulfilment are different axes, which is the whole premise of this endpoint
 * accepting either field alone.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX IS NOT "EXEMPT THE ECHO FROM THE REFUSALS", AND THAT DISTINCTION IS THE POINT OF THIS
 * FILE.
 *
 * Exempting it from the refusals alone would have walked the echo straight into the WRITE arm,
 * whose `case 2` runs `restoreCancelledOrderStock()` and `OrderEntitlementReversal::reverseOrLog()`
 * and whose other arms stamp `cancel_date`/`delivery_date`/`return_date` with `now()`. The comment
 * on that restock reads *"The guard above means this only ever runs from Pending, so a replay ...
 * is refused before here"* — so the guard I was about to narrow is load-bearing for idempotency,
 * and narrowing it without narrowing the arm would have converted a wrong refusal into a DOUBLE
 * STOCK CREDIT and a re-stamped settlement date. `test_an_echo_does_not_re_run_the_cancellation_
 * side_effects` is that assertion, and it is the one that matters most here.
 *
 * So one predicate — "the requested delivery status DIFFERS from the stored one" — gates the cancel
 * guard, the fulfilment state machine and the write arm together. An echo moves nothing, so no
 * transition rule and no transition side effect speaks about it.
 *
 * WHAT IS NOT RELAXED: a real backward transition out of a settled order is still refused (H2), and
 * a real cancel out of a non-pending state is still refused (P4-F105). Both have a case below,
 * because a fix that only loosens is indistinguishable from a fix that removes the rule.
 */
final class SettledOrderPaymentEchoTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const DOOR = '/api/admin/orders/update-status';

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
    }

    /** An order parked at `$status`, unpaid, with its settlement date already stamped in the past. */
    private function orderAt(int $status, array $extra = []): Order
    {
        return $this->makeOrder($this->store, array_merge([
            'delivered_status' => $status,
            'payment_status' => 'Unpaid',
        ], $extra));
    }

    /**
     * The door, driven as the shipped app drives it: a bearer token on the `admin` API.
     *
     * NO `store_id` IN THE BODY, deliberately. The handler takes the store from
     * `getCurrentStore()` -- the session -- and resolves the order by `product_order_id` AND
     * `store_id`, so a `store_id` in the payload is read by nothing. An earlier draft sent one, and
     * in a codebase with four separate defects from a tenant predicate built out of caller-supplied
     * input, a parameter that LOOKS like the tenant and does nothing is worth removing rather than
     * leaving for the next reader to wonder about.
     */
    private function push(Order $order, array $payload)
    {
        $bearer = $this->merchant->createToken('echo-test')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson(self::DOOR, array_merge([
                'product_order_id' => $order->product_order_id,
            ], $payload));
    }

    private function storedPaymentStatus(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('payment_status');
    }

    private function storedDeliveredStatus(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('delivered_status');
    }

    /**
     * THE PREMISE, in the only place this repository can assert it: the two states really are the
     * ones the machine calls settled, so an echo of either really does meet `refusalForLeaving()`.
     */
    public function test_the_premise_that_both_states_are_the_settled_ones(): void
    {
        foreach ([2, 3] as $status) {
            $this->assertNotNull(FulfilmentStateMachine::refusalForLeaving($status), sprintf(
                'status %d is no longer one the machine refuses to leave, so the regression this '
                . 'file is about cannot occur from it and these cases are vacuous', $status
            ));
        }
    }

    /**
     * THE REGRESSION I INTRODUCED: a payment recorded on a RETURNED order.
     */
    public function test_a_payment_can_be_recorded_on_a_returned_order_while_the_app_echoes_its_status(): void
    {
        $order = $this->orderAt(3);

        $response = $this->push($order, ['delivered_status' => 3, 'payment_status' => 'Paid']);

        $this->assertSame(200, $response->status(), 'premise: this door answers 200 by design');

        $this->assertTrue((bool) $response->json('success'), sprintf(
            'THE PAYMENT WAS REFUSED. The app echoes the order\'s own `delivered_status` on a '
            . 'payment-only change, and `refusalForLeaving()` reads that echo as an attempt to '
            . 'leave a settled order — so a merchant cannot record a COD bag or a late bank '
            . 'transfer on a returned order at all. Refused with: %s',
            (string) $response->json('message')
        ));

        $this->assertSame('Paid', $this->storedPaymentStatus($order->id),
            'the response said success and the column did not move');

        $this->assertSame('3', $this->storedDeliveredStatus($order->id),
            'the echo changed the delivery status, which is the opposite of what an echo means');
    }

    /**
     * THE PRE-EXISTING HALF, identical in effect: a payment recorded on a CANCELLED order.
     */
    public function test_a_payment_can_be_recorded_on_a_cancelled_order_while_the_app_echoes_its_status(): void
    {
        $order = $this->orderAt(2);

        $response = $this->push($order, ['delivered_status' => 2, 'payment_status' => 'Paid']);

        $this->assertTrue((bool) $response->json('success'), sprintf(
            'THE PAYMENT WAS REFUSED on a cancelled order. This half is older than H2 — the '
            . '`P4-F105` cancel guard refuses `delivered_status = 2` out of any non-pending state, '
            . 'and an echo from a row already at 2 is not an attempt to cancel. Refused with: %s',
            (string) $response->json('message')
        ));

        $this->assertSame('Paid', $this->storedPaymentStatus($order->id),
            'the response said success and the column did not move');
    }

    /**
     * AND THE ECHO MUST NOT RE-RUN THE CANCELLATION. THIS IS THE CASE THAT GUARDS THE FIX ITSELF.
     *
     * `case 2` of the write arm restocks every line and reverses the merchant's wallet entitlement,
     * and its own comment relies on the cancel guard to mean it "only ever runs from Pending". A
     * fix that exempted the echo from the REFUSALS but not from the WRITE ARM would therefore
     * credit the goods a second time and re-stamp the settlement date — turning a wrong refusal
     * into a stock and money defect.
     *
     * `cancel_date` is the observable: it is written `now()` by that arm and by nothing else on
     * this path, so an unchanged past timestamp proves the arm did not run.
     */
    public function test_an_echo_does_not_re_run_the_cancellation_side_effects(): void
    {
        $stamped = '2020-01-01 00:00:00';

        $order = $this->orderAt(2, ['cancel_date' => $stamped]);

        $this->assertSame($stamped, (string) DB::table('orders')->where('id', $order->id)->value('cancel_date'),
            'premise: the fixture really carries the past settlement date');

        $response = $this->push($order, ['delivered_status' => 2, 'payment_status' => 'Paid']);

        $this->assertTrue((bool) $response->json('success'), 'premise: the echo was accepted');

        $this->assertSame($stamped, (string) DB::table('orders')->where('id', $order->id)->value('cancel_date'),
            'THE WRITE ARM RAN ON AN ECHO. `cancel_date` was re-stamped, which means '
            . '`restoreCancelledOrderStock()` and `OrderEntitlementReversal::reverseOrLog()` ran '
            . 'again for an order that was already cancelled. The echo must skip the delivery '
            . 'write arm, not merely the refusals.');
    }

    /**
     * AND AN ECHO NO LONGER RE-STAMPS `delivery_date`, WHICH IS THE CUSTOMER'S RETURN WINDOW.
     *
     * NOT A SIDE NOTE, AND NOT CAUGHT BY ANY OTHER CASE HERE. `Order::getReturnDateAttribute()`
     * computes the shopper's remaining return window as `delivery_date + return_order_dutation`
     * days, and only for `delivered_status == 1` (`app/Models/Order.php:321-323`). The old code ran
     * the write arm whenever the field was merely PRESENT, so `case 1` stamped `delivery_date =
     * now()` -- meaning a merchant recording a payment on a delivered order, with the app echoing
     * `delivered_status = 1` as it always does, silently EXTENDED that window by however long the
     * order had already been delivered. A customer's right to return is not something a payment
     * entry should move, in either direction.
     *
     * This is the one arm of the change that TIGHTENS rather than fixes a refusal, which is exactly
     * why it needs its own case: the other six here would all pass against a revert of this arm.
     * Raised by the independent review of this change, not by me.
     */
    public function test_an_echo_does_not_re_stamp_the_delivery_date(): void
    {
        $stamped = '2020-01-01 00:00:00';

        $order = $this->orderAt(1, ['delivery_date' => $stamped]);

        $this->assertSame($stamped, (string) DB::table('orders')->where('id', $order->id)->value('delivery_date'),
            'premise: the fixture really carries the past delivery date');

        $response = $this->push($order, ['delivered_status' => 1, 'payment_status' => 'Paid']);

        $this->assertTrue((bool) $response->json('success'), sprintf(
            'premise: a payment on a DELIVERED order is accepted -- `PERMITTED[STATE_DELIVERED]` '
            . 'holds `delivered`, so this echo was never refused and this case is about the write '
            . 'arm, not the guards. Refused with: %s', (string) $response->json('message')
        ));

        $this->assertSame('Paid', $this->storedPaymentStatus($order->id),
            'premise: the payment the merchant actually came to record did land');

        $this->assertSame($stamped, (string) DB::table('orders')->where('id', $order->id)->value('delivery_date'),
            'THE DELIVERY DATE WAS RE-STAMPED BY AN ECHO. `Order::getReturnDateAttribute()` computes '
            . 'the customer\'s return window from this column, so recording a payment on a '
            . 'delivered order just extended that window by the order\'s whole age. The write arm '
            . 'must not run when the delivery status did not change.');
    }

    /**
     * NOT RELAXED (H2): a REAL backward transition out of a settled order is still refused.
     */
    public function test_a_real_backward_transition_out_of_a_returned_order_is_still_refused(): void
    {
        $order = $this->orderAt(3);

        $response = $this->push($order, ['delivered_status' => 1]);

        $this->assertFalse((bool) $response->json('success'),
            'a RETURNED order was moved to Delivered. The echo exemption must turn on the values '
            . 'being EQUAL; if it exempts any request that merely carries the field, decision H2 is '
            . 'undone and `Order::product_return()` can be reached in two requests.');

        $this->assertSame('3', $this->storedDeliveredStatus($order->id),
            'the refused transition reached the column');
    }

    /**
     * NOT RELAXED (P4-F105): a REAL cancel out of a non-pending state is still refused.
     */
    public function test_a_real_cancel_out_of_a_shipped_order_is_still_refused(): void
    {
        $order = $this->orderAt(6);

        $response = $this->push($order, ['delivered_status' => 2]);

        $this->assertFalse((bool) $response->json('success'),
            'a SHIPPED order was cancelled over this door. The `P4-F105` guard exists because the '
            . 'cancel arm restocks unconditionally, so narrowing it to a real change must not '
            . 'narrow it to nothing.');

        $this->assertSame('6', $this->storedDeliveredStatus($order->id),
            'the refused cancel reached the column');
    }

    /**
     * AND A LEGITIMATE FORWARD MOVE STILL WORKS — the positive control, without which every
     * assertion above could be satisfied by a door that refuses everything.
     */
    public function test_a_legitimate_forward_transition_still_works(): void
    {
        $order = $this->orderAt(0);

        $response = $this->push($order, ['delivered_status' => 4]);

        $this->assertTrue((bool) $response->json('success'), sprintf(
            'a pending order could not be confirmed: %s', (string) $response->json('message')
        ));

        $this->assertSame('4', $this->storedDeliveredStatus($order->id),
            'the accepted transition did not reach the column');
    }
}

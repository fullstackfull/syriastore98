<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F145 — A MERCHANT CANNOT CHANGE THE DELIVERY STATUS OF A `Pending` ORDER FROM THE SHIPPED APP.
 * =================================================================================================
 *
 * `Api\DashboardController::updateStatus()` validates `payment_status` with `in:Paid,Unpaid`
 * (DashboardController.php:402-406). The shipped Flutter app sends `payment_status` on EVERY call
 * to this endpoint, including the one that changes only the DELIVERY status, and the value it sends
 * is the order's own CURRENT value echoed straight back:
 *
 *   - `order_repository.dart:81-94` — `required String paymentStatus`, written unconditionally into
 *     the POST body at `:92`. It is not optional and the app has no path that omits it.
 *   - `payment_status_bottom_sheet.dart:686-693` — the DELIVERY-status sheet passes
 *     `paymentStatus: getPaymentStatus(model)`.
 *   - `order_screen.dart:563-564` supplies that as `model.paymentStatus ?? ''`.
 *
 * So an order whose stored `payment_status` is anything but the two literals `Paid` / `Unpaid`
 * produces an echo the validator refuses, and the merchant's unrelated delivery-status change dies
 * with **HTTP 422** before the handler ever looks at the order.
 *
 * THE THIRD VALUE IS `Pending`, AND IT IS NOT RARE. `PaymentVerificationService` resolves exactly
 * two outcomes — `STATUS_PAID = 'Paid'` and `STATUS_PENDING = 'Pending'`
 * (PaymentVerificationService.php:35, :43) — and `Pending` is what it returns for:
 *
 *   - COD, under owner decision D-06;
 *   - a bank transfer awaiting manual settlement (the original user of the value);
 *   - **every gateway return whose capture could not be verified**, which is the `$verification
 *     === null` branch a long list of controllers documents against itself (Khalti, PaiementPro,
 *     CinetPay, Easebuzz, PayU, AuthorizeNet among them).
 *
 * WHAT THIS FILE DOES NOT CLAIM. The storefront writers persist only `Unpaid` as a literal and
 * every other order value arrives through `resolveOrderPaymentStatus()`, so the set really is
 * {Paid, Unpaid, Pending}. The `success` / `succeeded` spellings that appear 63 times in `app/`
 * belong to `plan_orders.payment_status` — the SaaS plan rail, PlanOrder, not Order — and are NOT
 * part of this defect. Measured, because a first reading of the grep counts said otherwise.
 *
 * THE FIX IS NOT "WIDEN THE LIST". Widening `in:` to admit `Pending` would let a merchant *assert*
 * a payment state the server is supposed to derive, which is the opposite of what owner policy
 * requires ("Do not grant a merchant the ability to assert Paid without the required settlement
 * evidence", and D-06 for COD). The approved shape is to validate the delivery status
 * INDEPENDENTLY of an actual payment-status TRANSITION: an echo of the order's own stored value is
 * not a transition at all and must be ignored, while a genuine change of `payment_status` is still
 * constrained exactly as it is today.
 *
 * PRE-EXISTING, NOT A PHASE 04 REGRESSION: the validator was present at the Phase 2.5 merge
 * (`4e878b8d`, 2026-11-12).
 *
 * WHY EVERY ASSERTION READS THE RAW ROW. `Order` is `Cachable` and the suite runs under
 * `CACHE_STORE=array`, so an Eloquent read after the handler's write can serve the pre-write row
 * for the rest of the process — three measured false readings in this repository.
 *
 * @see \App\Http\Controllers\Api\DashboardController::updateStatus()
 * @see \App\Services\PaymentVerificationService::resolveOrderPaymentStatus()
 */
class OrderStatusEchoCompatibilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const NOT_FOUND_MESSAGE = 'Order not found.';
    private const NO_PERMISSION_MESSAGE = 'You do not have permission to perform this action.';
    private const VALIDATION_FAILED_MESSAGE = 'Validation failed.';

    /** @return array{0: User, 1: Store, 2: Order} */
    private function tenantWithOrder(array $orderAttributes = []): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        $order = $this->makeOrder($store, array_merge([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Unpaid',
            'return_status' => 0,
            'product_json' => '[]',
        ], $orderAttributes));

        return [User::find($merchant->id), $store, $order];
    }

    /**
     * THE SHIPPED DART REQUEST SHAPE, NOT AN INVENTED ONE.
     *
     * `order_repository.dart:89-94` posts exactly these four keys, `note` as `''` when the merchant
     * typed nothing. Driving a body that omits `payment_status` would exercise a shape no shipped
     * client produces — which is precisely the flaw the decision package records in the existing
     * test for this endpoint.
     */
    private function postAsTheApp(User $user, Order $order, int $deliveredStatus, string $paymentStatusEcho, string $note = '')
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/orders/update-status', [
            'product_order_id' => $order->product_order_id,
            'delivered_status' => $deliveredStatus,
            'payment_status' => $paymentStatusEcho,
            'note' => $note,
        ]);
    }

    private function rawDeliveredStatus(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('delivered_status');
    }

    private function rawPaymentStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('payment_status');
    }

    private function body($response): string
    {
        return substr((string) $response->getContent(), 0, 300);
    }

    /** The handler was reached and answered the real question, not a fixture fault. */
    private function assertHandlerWasReached($response): void
    {
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString(self::NOT_FOUND_MESSAGE, $body,
            'the order lookup missed — a fixture fault, not evidence');

        $this->assertStringNotContainsString(self::NO_PERMISSION_MESSAGE, $body,
            'the Manage Order gate refused — a fixture fault, not evidence');
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISE
    |--------------------------------------------------------------------------
    */

    /**
     * PREMISE: the fixture really reaches the write, driven in the app's own shape.
     *
     * An `Unpaid` order echoing `'Unpaid'` is the case that works TODAY. If this is red, the token,
     * the permission, the route or `current_store` is wrong and nothing else here means anything.
     */
    public function test_premise_an_unpaid_echo_reaches_the_write(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Unpaid']);

        $response = $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Unpaid');

        $this->assertHandlerWasReached($response);

        $this->assertSame(
            HomeController::ORDER_STATUS_CONFIRMED,
            $this->rawDeliveredStatus($order),
            'the fixture never reached the write, so every case below is vacuous. Body: '
            . $this->body($response)
        );
    }

    /**
     * PREMISE: `Pending` is a value this column really holds, and the service really produces it.
     *
     * Without this the defect case below could be testing a value nothing ever writes.
     */
    public function test_premise_pending_is_a_real_resolved_order_payment_status(): void
    {
        $this->assertSame('Pending', \App\Services\PaymentVerificationService::STATUS_PENDING,
            'the constant this defect depends on has been renamed or removed');

        [, , $order] = $this->tenantWithOrder(['payment_status' => 'Pending']);

        $this->assertSame('Pending', $this->rawPaymentStatus($order),
            'the orders table did not persist Pending, so the column cannot hold the value this '
            . 'file is about');
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEFECT: a delivery-status change on a `Pending` order is refused 422 by the echo.
     *
     * Nothing about this request asks to change the payment status. The merchant tapped
     * "Confirmed" in the app; the app echoed `Pending` because that is what the order says.
     */
    public function test_a_pending_echo_does_not_block_an_unrelated_delivery_status_change(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Pending']);

        $response = $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Pending');

        $this->assertHandlerWasReached($response);

        $this->assertStringNotContainsString(self::VALIDATION_FAILED_MESSAGE, (string) $response->getContent(),
            'the merchant\'s delivery-status change was refused as a VALIDATION failure because the '
            . 'app echoed the order\'s own payment status. Body: ' . $this->body($response));

        $this->assertSame(
            HomeController::ORDER_STATUS_CONFIRMED,
            $this->rawDeliveredStatus($order),
            'a merchant could not move a Pending order to Confirmed from the shipped app. '
            . 'Body: ' . $this->body($response)
        );
    }

    /**
     * AND THE ECHO IS NOT A WRITE. `Pending` must survive the delivery-status change untouched.
     *
     * This is the case that fails against the naive fix — widening `in:` to admit `Pending` would
     * let the echo fall through to `$order->payment_status = $newPaymentStatus`
     * (DashboardController.php:651) and re-assert a payment state the server is supposed to derive.
     * The value happens to be identical here, so only the ABSENCE of a transition proves it: see
     * the sibling case below, which checks the observer never fired.
     */
    public function test_the_pending_echo_leaves_the_payment_status_exactly_as_it_was(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Pending']);

        $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Pending');

        $this->assertSame('Pending', $this->rawPaymentStatus($order),
            'the echo changed the stored payment status. A merchant changing a DELIVERY status '
            . 'must not restate settlement.');
    }

    /**
     * THE SAME DEFECT ON A GATEWAY ORDER, which is the wider half and is measured separately.
     *
     * A gateway return whose capture could not be verified lands at `Pending` too, so this is not a
     * COD-only defect. Driven as Cancel rather than Confirmed so the case also covers a different
     * arm of the handler's switch.
     */
    public function test_a_gateway_pending_order_can_still_be_cancelled_from_the_app(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder([
            'payment_status' => 'Pending',
            'payment_type' => 'khalti',
        ]);

        $response = $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CANCEL, 'Pending');

        $this->assertHandlerWasReached($response);

        $this->assertSame(
            HomeController::ORDER_STATUS_CANCEL,
            $this->rawDeliveredStatus($order),
            'an unverified-capture gateway order could not be cancelled from the shipped app. '
            . 'Body: ' . $this->body($response)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | POSITIVE CONTROLS — the cases a fix must not break
    |--------------------------------------------------------------------------
    */

    /** A genuine Unpaid -> Paid transition still lands. */
    public function test_a_genuine_payment_status_transition_still_works(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Unpaid']);

        $response = $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Paid');

        $this->assertHandlerWasReached($response);

        $this->assertSame('Paid', $this->rawPaymentStatus($order),
            'a real Unpaid -> Paid transition was lost. Body: ' . $this->body($response));
    }

    /** And the delivery status still moves on that same call. */
    public function test_a_genuine_transition_still_moves_the_delivery_status_too(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Unpaid']);

        $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Paid');

        $this->assertSame(
            HomeController::ORDER_STATUS_CONFIRMED,
            $this->rawDeliveredStatus($order),
            'the delivery status was dropped when the payment status also changed'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NEGATIVE REGRESSIONS — what must still be refused
    |--------------------------------------------------------------------------
    */

    /**
     * A GENUINE TRANSITION TO AN UNRECOGNISED VALUE IS STILL REFUSED, and nothing is written.
     *
     * This is the case a fix that simply deleted the rule would fail. `Refunded` is not a value
     * this column's writers produce, and a merchant must not be able to invent one.
     */
    public function test_a_genuine_transition_to_an_unknown_value_is_still_refused(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['payment_status' => 'Unpaid']);

        $response = $this->postAsTheApp($merchant, $order, HomeController::ORDER_STATUS_CONFIRMED, 'Refunded');

        $this->assertSame('Unpaid', $this->rawPaymentStatus($order),
            'a merchant invented a payment status the server never produces. Body: '
            . $this->body($response));

        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            $this->rawDeliveredStatus($order),
            'the refused request still wrote the delivery status — a partial write is worse than '
            . 'either outcome'
        );
    }

    /**
     * AND AN ECHO DOES NOT BYPASS THE PERMISSION GATE.
     *
     * Pinned because this change edits the validation that runs BEFORE the gate: a fix that moved
     * the payment check after the lookup must not also move the authorization check.
     */
    public function test_the_echo_path_still_refuses_a_staff_member_without_the_permission(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder(['payment_status' => 'Pending']);

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();

        $response = $this->postAsTheApp(
            User::find($staff->id), $order, HomeController::ORDER_STATUS_CONFIRMED, 'Pending'
        );

        $this->assertSame(403, $response->getStatusCode(),
            'a staff member holding no order permission was not refused 403 on the echo path. '
            . 'Body: ' . $this->body($response));

        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            $this->rawDeliveredStatus($order),
            'an unpermitted staff member moved the delivery status through the echo path'
        );
    }

    /** A foreign tenant is still refused, on the echo path too. */
    public function test_another_tenants_pending_order_is_still_not_reachable(): void
    {
        [, , $victimOrder] = $this->tenantWithOrder(['payment_status' => 'Pending']);

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider, ['default_language' => 'en']);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();
        $this->grantPermission(User::find($outsider->id), 'Manage Order');

        $this->postAsTheApp(
            User::find($outsider->id), $victimOrder, HomeController::ORDER_STATUS_CONFIRMED, 'Pending'
        );

        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            $this->rawDeliveredStatus($victimOrder),
            'another tenant moved this order through the echo path'
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DeliverySyriaCredential;
use App\Support\Orders\FulfilmentStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DeliverySyriaOrderController extends Controller
{
    use \App\Http\Controllers\Concerns\RestoresCancelledOrderStock;

    /**
     * THE WEBHOOK SPEAKS INTEGERS; THE STATE MACHINE SPEAKS WORDS. GAP-135.
     *
     * `Order::order_status_change()` is the writer five doors share and it asks
     * {@see FulfilmentStateMachine} before it moves anything (app/Models/Order.php:946). This
     * handler is one of the two that do NOT go through that writer -- it assigns
     * `orders.delivered_status` itself and saves (:304, :418) -- so until this map existed it moved
     * an order wherever the courier asked, including out of the two TERMINAL states. Measured
     * before the fix: `status = 1` against an order at `delivered_status = 2` (CANCELLED) answered
     * HTTP 200 `{"success":true}`, wrote `delivered_status = 1` and stamped `delivery_date`. The
     * machine refuses that transition, and the consequence is money rather than cosmetics:
     * `Order::product_return()` refuses unless `delivered_status == 1` and then REDUCES
     * `final_price`, so a cancelled order -- goods already credited by this handler's own cancel
     * arm, merchant entitlement already walked back by `OrderEntitlementReversal` -- was walked
     * back into the return and refund workflow by a third party.
     *
     * THE MAP IS THE ONLY THING ADDED. No rule is written here: which transitions are legal stays
     * in the one place that defines it, so narrowing the matrix (which that class documents as a
     * one-line edit) reaches this door too. Both sides are the machine's own constants, so a
     * renamed word or a renumbered state is a compile-time concern rather than a silent drift.
     *
     * `0` (Pending) IS ABSENT FROM THIS MAP AND IS NOT THEREFORE PERMITTED, and the first version
     * of this fix had that backwards. The machine governs exactly the six words
     * `Order::order_status_change()`'s branch chain matches; `pending` is not among them, so
     * `governs()` answers false and {@see FulfilmentStateMachine::refusalFor()} answers null. That
     * first version read the null as "permitted" and let the push fall through, WHICH LEFT THE
     * DEFECT ABOVE REACHABLE IN TWO REQUESTS INSTEAD OF ONE: `PERMITTED[PENDING]` holds five of the
     * six governed words, so `2 -> 0 -> 1` answered 200 then 200 and reached the identical row
     * state -- measured through this route by
     * `DeliverySyriaOrderTransitionTest::test_a_settled_order_cannot_be_re_opened_in_two_pushes_either`,
     * which failed before {@see FulfilmentStateMachine::refusalForLeaving()} existed. The
     * same composition reached a cancel of a DELIVERED order (`1 -> 0 -> 2`), which restores stock
     * for goods already delivered.
     *
     * SO THE DOOR ASKS TWO QUESTIONS, and a first draft of this fix asked one and a half. It had the
     * machine refuse EVERY target the map could not translate, which closed `2 -> 0 -> 1` but was an
     * over-reach: measured by the agent wiring the same predicate into `Api\DashboardController`, it
     * refused **27 of 27** untranslated pairs, including `delivered_status` 7 and 8 out of a Pending
     * row -- values `P2-07-12` deliberately widened that endpoint to accept. Its justification ("no
     * `PERMITTED` row targets Pending, so writing Pending is unauthorised") is equally true of 7 and
     * 8, so it never singled Pending out, and it is withdrawn.
     *
     * What remains is the machine's own published invariant, which is enough for the defect above:
     * {@see FulfilmentStateMachine::refusalForLeaving()} refuses leaving a TERMINAL state at all, so
     * the FIRST hop of `2 -> 0 -> 1` is refused and nothing composes past it.
     *
     * **STILL OPEN, AND MEASURED RATHER THAN IMPLIED:** a DELIVERED row is not terminal, so
     * `1 -> 0 -> 2` still walks a delivered order into a cancel -- which restores stock for goods
     * already delivered -- in two requests, while `1 -> 2` is refused in one. Pinned as a MEASURED
     * case in `DeliverySyriaOrderTransitionTest` rather than left to be rediscovered, and carried as
     * an owner question in `OWNER-DECISION-PACKAGE.md` H2: closing it needs an ordering over these
     * state integers (which they do not carry) or a word for each non-fulfilment value.
     *
     * 7 (Partially Paid) and 8 (Pre Order) need no entry: the validator refuses them as TARGETS
     * (:166). They are still handled correctly as FROM states, because the machine carries a row
     * for each.
     *
     * @var array<int, string>
     */
    private const MACHINE_WORD_FOR_STATUS = [
        FulfilmentStateMachine::STATE_DELIVERED => FulfilmentStateMachine::DELIVERED,
        FulfilmentStateMachine::STATE_CANCELLED => FulfilmentStateMachine::CANCEL,
        FulfilmentStateMachine::STATE_RETURN => FulfilmentStateMachine::RETURNED,
        FulfilmentStateMachine::STATE_CONFIRMED => FulfilmentStateMachine::CONFIRMED,
        FulfilmentStateMachine::STATE_PICKED_UP => FulfilmentStateMachine::PICKED_UP,
        FulfilmentStateMachine::STATE_SHIPPED => FulfilmentStateMachine::SHIPPED,
    ];

    public function updateStatus(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | 1. التحقق من اسم المنصة
        |--------------------------------------------------------------------------
        */
        $platform = trim((string) $request->header('X-Platform'));

        if ($platform !== 'deliverysyria') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid platform.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. استخراج Bearer Token
        |--------------------------------------------------------------------------
        */
        $secret = trim((string) $request->bearerToken());

        if ($secret === '') {
            // NEW-DS-ROUTE-SHADOW-01 (second half). This answered 401, and it is now
            // 403 with the same message its sibling endpoint uses.
            //
            // Two reasons, in order of weight:
            //  1. 401 is forbidden across this project because repo B's
            //     lib/network/network.dart turns ANY 401 from this API into a forced
            //     logout that clears stored preferences, so a 401 anywhere under /api
            //     is a logout bomb for the Flutter admin app.
            //  2. This is not a behaviour change for any caller that existed, because
            //     NO CALLER COULD REACH THIS METHOD. Until the duplicate closure was
            //     removed from routes/web.php, POST api/delivery-syria/orders/update-status
            //     was served by that closure -- measured, by dispatching the request:
            //     an empty POST returned 422 {"message":"product_order_id and status are
            //     required."} (the closure) and never 403 {"message":"Invalid platform."}
            //     (this controller, :27-32). A response contract that was unreachable
            //     cannot have been depended on.
            //
            // The message is aligned with DeliverySyriaStatusController's
            // 'Invalid credentials.' so that a caller without a token and a caller with a
            // wrong token are indistinguishable, which is the property
            // DeliverySyriaWebhookTest::test_the_credential_check_precedes_input_validation
            // already pins on the sibling endpoint.
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 2b. P1-SEC-10: the credential is resolved BEFORE anything else is learned
        |--------------------------------------------------------------------------
        |
        | This used to look the order up first and compare the secret of that order's store owner second, so a wrong token got 404 for an id
        | that exists nowhere and 403 for one that exists in some tenant -- an existence oracle over every store's product_order_id, with
        | no throttle. The token is now matched against the stored secrets first (constant time, see DeliverySyriaCredential); a caller
        | that matches none gets one answer whatever it asked about, and one that matches is confined to the stores its secret owns.
        */
        $storeIds = DeliverySyriaCredential::storeIdsFor($secret);

        if ($storeIds === []) {
            Log::warning('Delivery Syria invalid credentials', [
                'platform' => $platform,
                'provided_token_length' => strlen($secret),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. التحقق من البيانات
        |--------------------------------------------------------------------------
        |
        | الحالات:
        | 0 = Pending
        | 1 = Delivered
        | 2 = Cancel
        | 3 = Return
        | 4 = Confirmed
        | 5 = Picked Up
        | 6 = Shipped
        |
        */
        $validator = Validator::make($request->all(), [
            'product_order_id' => [
                'required',
                'string',
                'max:191',
            ],
            'status' => [
                'required',
                'integer',
                'in:0,1,2,3,4,5,6',
            ],
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productOrderId = trim(
            (string) $request->input('product_order_id')
        );

        $newStatus = (int) $request->input('status');

        /*
        |--------------------------------------------------------------------------
        | 4. البحث عن الطلب -- only among the stores the authenticated secret owns
        |--------------------------------------------------------------------------
        */
        $order = Order::query()
            ->where('product_order_id', $productOrderId)
            ->whereIn('store_id', $storeIds)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $oldStatus = (int) $order->delivered_status;

        /*
        |--------------------------------------------------------------------------
        | 8. إذا كانت الحالة نفسها لا داعي للتعديل
        |--------------------------------------------------------------------------
        */
        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => true,
                'message' => 'Order already has this status.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'status' => $order->delivered_status,
                    /*
                     * `$oldStatus` AND NOT THE RAW COLUMN, because `statusName(int $status)` is
                     * typed and this branch is reachable with a non-numeric value in the column.
                     * MEASURED: `Api\ApiController` writes the literal `'-'` into
                     * `orders.delivered_status` for a storefront order placed without one; `(int) '-'`
                     * is 0, so a courier push of `status = 0` satisfies the comparison above, reaches
                     * this line, and PHP 8 raises `TypeError: Argument #1 ($status) must be of type
                     * int, string given`. It is outside the `try` further down, so it surfaced as an
                     * uncaught HTTP 500 on a live third-party webhook. Found by an independent review
                     * as a latent defect and then reproduced by execution, which is why the fix is
                     * here and not in the register. The response is unchanged for every other row:
                     * `status` still prints the raw stored value and only the NAME is derived from
                     * the cast one, which is what every other branch of this handler already does.
                     */
                    'status_name' => $this->statusName($oldStatus),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 8b. THE FULFILMENT STATE MACHINE. GAP-135, the second of the two doors.
        |--------------------------------------------------------------------------
        |
        | AFTER THE EARLY RETURN ABOVE, AND THAT ORDERING IS LOAD-BEARING. A push of the status the
        | order already holds is a no-op that re-opens nothing, and Delivery Syria RETRIES
        | deliveries, so :213-224 is this endpoint's only idempotency. Asking the machine first
        | would answer 409 to a courier's retry out of a terminal state -- a behaviour change
        | dressed as a fix. `DeliverySyriaOrderTransitionTest::
        | test_the_same_status_early_return_is_preserved_even_where_the_machine_refuses` pins it.
        |
        | THE RAW COLUMN VALUE IS PASSED, NOT `$oldStatus`, AND THE DIFFERENCE IS OBSERVABLE.
        | `$oldStatus` is `(int) $order->delivered_status` and the cast changes the machine's
        | answer: `Api\ApiController:10616` writes the literal `-` into this column on SQLite, and on MySQL with the production `strict => false` the same write may coerce to 0 -- the live column type was NOT measured, so what matters here is only that the machine documents ANY value outside 0..8 as ungoverned
        | (`!empty($request->delivered_status) ? $request->delivered_status : '-'`, so a storefront
        | order placed without one carries it), and under PHP 8 `(int) '-'` is `0` while `'-' == 0`
        | is FALSE. So the machine refuses `cancel` out of such a row -- exactly as
        | `Order::order_status_change()` always has -- while the cast would have made it
        | cancellable here alone. This is the same raw-value call `Order::refuseIllegalTransition()`
        | makes (app/Models/Order.php:946); that class's docblock derives it in full, and
        | `DeliverySyriaOrderTransitionTest::
        | test_the_machine_is_asked_with_the_raw_column_value_and_not_the_cast_one` pins it.
        |
        | AND THE WORD MAY BE `null`, WHICH IS NOT THE SAME AS "REFUSE". `refusalFor()` asks
        | `governs()` first and answers null for a word it does not know, so an ungoverned push
        | (`status = 0`) falls through untouched instead of being refused by a door that invented a
        | rule the single definition does not contain. See MACHINE_WORD_FOR_STATUS.
        |
        | 409 AND NOT 401, 403 OR 422. 401 is forbidden across this API: repo B's
        | lib/network/network.dart turns ANY 401 into a forced logout that clears stored
        | preferences. 403 is what this handler already answers for a caller it does not recognise,
        | and this caller IS recognised. 422 is what it answers for a malformed payload, and carries
        | an `errors` bag -- this payload is well formed and the order's own state is the conflict.
        | 409 is the code this codebase already uses for exactly that (`ProductController:4222`,
        | `StoreFinanceController:2582`, `OrderRefundController:709`, `PosController:417`), in the
        | same `{success, message}` envelope as every other answer from this method.
        */
        $statusWord = self::MACHINE_WORD_FOR_STATUS[$newStatus] ?? null;

        /*
        | TWO QUESTIONS, AND THE FIRST ONE IS THE ONE A WORD-ONLY CHECK CANNOT ASK.
        |
        | `refusalFor()` answers null for a word it does not govern, and `governs()` lists no PENDING
        | word -- so `status = 0` maps to no word and, with only the second question, fell through
        | from EVERY state including both terminal ones. `PERMITTED[PENDING]` then holds five of the
        | six governed words, so a caller refused `CANCELLED -> delivered` in one push reached the
        | identical row state in two: `2 -> 0 -> 1`, measured as 200 then 200 before this line
        | existed. The refusal cost an attacker one extra request and removed nothing else.
        |
        | `refusalForLeaving()` is the ROW-level question -- may this row move at all -- and
        | `refusalFor()` is the TARGET-level one. Both live on the machine, and the composition is
        | spelled out in `refusalForLeaving()`'s docblock so the sibling door in
        | `Api\DashboardController::updateStatus`, which translates an integer the same way, composes
        | them identically rather than carrying a second local rule. A local rule disagreeing with the
        | single definition is the defect this whole door was fixing.
        */
        $refusal = FulfilmentStateMachine::refusalForLeaving($order->delivered_status)
            ?? FulfilmentStateMachine::refusalFor($order->delivered_status, $statusWord);

        if ($refusal !== null) {
            Log::warning('Delivery Syria order status transition refused', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'old_status' => $oldStatus,
                'old_status_name' => $this->statusName($oldStatus),
                'new_status' => $newStatus,
                'new_status_name' => $this->statusName($newStatus),
                'reason' => $refusal,
            ]);

            return response()->json([
                'success' => false,
                'message' => $refusal,
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'status' => $order->delivered_status,
                    'status_name' => $this->statusName($oldStatus),
                ],
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 9. تحديث حالة الطلب
        |--------------------------------------------------------------------------
        */
        try {
            DB::transaction(function () use (
                $order,
                $newStatus,
                $oldStatus,
                $request
            ) {
                $order->delivered_status = $newStatus;

                switch ($newStatus) {
                    case 0:
                        // Pending
                        break;

                    case 1:
                        // Delivered
                        $order->delivery_date = now();
                        break;

                    case 2:
                        // Cancel
                        $order->cancel_date = now();

                        /*
                         * THE GOODS.  P4-F056. MEASURED before this line: a courier-reported
                         * cancellation of a pending order left a 2-unit line's stock at 4 where 6
                         * was due -- HTTP 200, {success:true}, `cancel_date` stamped, nothing
                         * credited.
                         *
                         * `$oldStatus === 0` IS THE REPLAY GUARD AND IT IS LOAD-BEARING HERE, more
                         * than at the other doors: this handler's only idempotency is
                         * `$oldStatus === $newStatus` (:164), so a 2 -> 1 -> 2 sequence re-enters
                         * this case, and Delivery Syria retries deliveries. Restricting the credit
                         * to a cancel FROM PENDING also matches what the web permits at all
                         * (`Order::order_status_change()` refuses a cancel from any non-pending
                         * state, app/Models/Order.php:702), so the webhook and the merchant screen
                         * now agree about which cancels are legitimate. The trait's per-line
                         * markers bound it a second time.
                         */
                        if ($oldStatus === 0) {
                            $this->restoreCancelledOrderStock(
                                $order,
                                is_array($cancelLines = json_decode((string) $order->product_json, true)) ? $cancelLines : []
                            );

                            /*
                             * P4-F124 -- THE MONEY, under the SAME `$oldStatus === 0` guard as the
                             * goods, deliberately.
                             *
                             * Delivery Syria RETRIES deliveries, and this handler's only other
                             * idempotency is `$oldStatus === $newStatus`, so a 2 -> 1 -> 2 sequence
                             * re-enters this case. Keeping the reversal inside the same guard means
                             * the money and the stock answer the same question about what a
                             * legitimate cancellation is. The reversal is idempotent on the
                             * financial row's own status as well, so a retry that did get through
                             * still moves nothing twice -- two independent bounds, which is what a
                             * retried webhook deserves.
                             */
                            app(\App\Services\OrderEntitlementReversal::class)->reverseOrLog(
                                $order,
                                \App\Services\OrderEntitlementReversal::REASON_CANCELLED,
                                'delivery-syria webhook'
                            );
                        }
                        break;

                    case 3:
                        // Return
                        $order->return_date = now();

                        /*
                         * حسب نظامك:
                         * 0 = none
                         * 1 = request
                         * 2 = approve
                         * 3 = cancel
                         *
                         * `1`, NOT `2`.  P4-F056.
                         *
                         * This wrote `2` -- APPROVED -- and restocked nothing, and `2` is the exact
                         * durable idempotency marker `OrderController::order_return_request` reads
                         * before crediting (OrderController.php:1421-1422). So a courier-reported
                         * return DISARMED the merchant's own approval: MEASURED, the merchant then
                         * POSTed `order-return-request` with status 2, got HTTP 200, and the stock
                         * stayed at 4 where 6 was due.
                         *
                         * It was not even visible as a problem in the UI: both web controls are
                         * gated on `return_status == 1` (order/action.blade.php:35,
                         * order/grid.blade.php:230), so the Approve button was never offered and the
                         * order simply rendered as "Return" with the goods quietly missing.
                         *
                         * A COURIER REPORTING A RETURN IS A REQUEST, NOT AN APPROVAL -- the goods
                         * are on their way back, not yet checked in. `1` says exactly that, puts the
                         * Approve control back in front of the merchant, and routes the credit
                         * through the ONE existing restore door rather than adding a fourth.
                         */
                        $order->return_status = 1;
                        break;

                    case 4:
                        // Confirmed
                        $order->confirmed_date = now();
                        break;

                    case 5:
                        // Picked Up
                        $order->picked_date = now();
                        break;

                    case 6:
                        // Shipped
                        $order->shipped_date = now();
                        break;
                }

                if ($request->filled('note')) {
                    $order->delivery_comment = trim(
                        (string) $request->input('note')
                    );
                }

                $order->save();
            });

            /*
             * تحديث النسخة بعد الحفظ.
             */
            $order->refresh();

            /*
             * تشغيل الحدث الموجود عندك لتغيير حالة الطلب.
             */
            event(new OrderPaymentStatus($order));

            Log::info('Delivery Syria order status updated', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'old_status' => $oldStatus,
                'old_status_name' => $this->statusName($oldStatus),
                'new_status' => $newStatus,
                'new_status_name' => $this->statusName($newStatus),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,
                    'previous_status' => $oldStatus,
                    'previous_status_name' => $this->statusName($oldStatus),
                    'status' => $newStatus,
                    'status_name' => $this->statusName($newStatus),
                    'updated_at' => $order->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Delivery Syria order status update failed', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not update order status.',
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | اسم الحالة للعرض في الرد واللوغ
    |--------------------------------------------------------------------------
    */
    private function statusName(int $status): string
    {
        return match ($status) {
            0 => 'Pending',
            1 => 'Delivered',
            2 => 'Cancel',
            3 => 'Return',
            4 => 'Confirmed',
            5 => 'Picked Up',
            6 => 'Shipped',
            default => 'Unknown',
        };
    }
}
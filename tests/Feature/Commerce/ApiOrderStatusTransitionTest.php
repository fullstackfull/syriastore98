<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F104 / P4-F105 -- `api/admin/orders/update-status` WRITES `delivered_status` WITH NO
 * TRANSITION GUARD, AND ITS RETURN ARM DISARMS THE WEB'S RESTOCK-ON-APPROVAL GUARD.
 * =================================================================================================
 *
 * ONE HANDLER, TWO FINDINGS. `Api\DashboardController::updateStatus()` validates that the
 * requested `delivered_status` is a value the COLUMN can hold -- derived from
 * `HomeController::ORDER_STATUS_*`, which is right -- and then writes it. Nothing asks whether the
 * order may MOVE from where it is to where the request wants it. The web's own mover,
 * `Order::order_status_change()`, does ask:
 *
 *   - CANCEL is refused when `delivered_status != 0`      (app/Models/Order.php:702-706)
 *   - RETURN is refused when `delivered_status == 0`      (app/Models/Order.php:757-761)
 *
 * So the gap is NOT "a delivered order can be cancelled". It is wider in one direction and
 * narrower in the other, and both halves are measured here:
 *
 *   - CANCEL: the web refuses it from ANY non-pending state, so Delivered (1), Return (3),
 *     Confirmed (4), Picked Up (5) and Shipped (6) are ALL cancellable over the API and none of
 *     them is cancellable on the web.
 *   - RETURN: the web already permits Return from Shipped / Picked Up / Confirmed / Delivered, so
 *     the API-only gap on that arm is specifically PENDING -> RETURN.
 *
 * AND THE CANCEL ARM OMITS THE MONEY -- RECORDED AS P4-F124, NOT FIXED. The web's cancel writes
 * `payment_status = 'Unpaid'` (app/Models/Order.php:712) precisely so that
 * `OrderObserver::updated()` -- which returns early unless `wasChanged('payment_status')`
 * (app/Observers/OrderObserver.php:32-34) -- reverses the wallet credit. The API's cancel arm writes
 * `cancel_date` and nothing else (DashboardController.php:544-547), so a cancelled order keeps its
 * wallet credit for ever.
 *
 * That half is a payment-semantics change on a shipped endpoint, which CLAUDE.md §2 puts in the
 * escalate-to-owner set, so `test_the_api_cancel_leaves_the_payment_status_alone_p4_f124` PINS THE
 * CURRENT BEHAVIOUR and names the decision instead of making it. Read that case's docblock before
 * changing it: it is green on purpose, and a red there is a change somebody made rather than a
 * defect somebody found. The transition halves below are different -- those are fixed.
 *
 * THE SECOND FINDING IS NOT A WRONG STATUS VALUE, IT IS LOST STOCK.
 * ----------------------------------------------------------------
 * The API's Return arm writes `return_status = 2` (DashboardController.php:549-560) with no
 * restock of any kind. `2` is the EXACT value `OrderController::order_return_request()` uses as
 * its durable restock idempotency marker:
 *
 *     $previousReturnStatus = (int) $order->return_status;                 // OrderController:1421
 *     $isNewApproval = ((int) $status === 2) && $previousReturnStatus !== 2;  //            :1422
 *
 * An app-side "Return" therefore pre-sets the marker for goods that never came back. The
 * merchant's subsequent genuine web approval finds `$isNewApproval === false`, skips
 * `restoreReturnedStock()`, still answers "Return request approve successfully"
 * (OrderController.php:1448), and the stock is never credited.
 *
 * NOT PERMANENTLY, THOUGH -- and the brief's first wording of this was too strong.
 * `order_return_request()` writes `$order->return_status = $status` UNCONDITIONALLY
 * (OrderController.php:1443), so a crafted POST of `status=3` followed by `status=2` does
 * restock. But BOTH web controls are gated on `return_status == 1`, so there is NO UI-REACHABLE
 * recovery: only a hand-crafted two-POST sequence gets the goods back.
 *
 * NOT A SECURITY FINDING. `OrderPolicy::sameStore()` holds (app/Policies/OrderPolicy.php:40-49)
 * and the gate requires `Manage Order` (:100-103), so the actor is a merchant or their own staff
 * acting on their own tenant's orders. This is a correctness and inventory-integrity finding.
 *
 * WHY EVERY ASSERTION READS THE RAW ROW
 * -------------------------------------
 * `ApiResponser::error()` answers HTTP 200 with `{status: 0}`, and this handler hand-rolls 403,
 * 404, 422 and 500 of its own, so a status code proves nothing in either direction. Every
 * assertion below reads `DB::table('orders')` / `DB::table('products')` directly. `Order` and
 * `Product` are both `Cachable`, and under `CACHE_STORE=array` a model-cache read can outlive the
 * write that should have invalidated it -- three measured false readings in this repository -- so
 * the raw row is the only honest witness.
 *
 * WHY THE FIXTURE IS THE SECURITY ONE
 * -----------------------------------
 * It extends `Tests\Feature\Security\SecurityTestCase` and reuses the exact fixture that
 * `AdminApiOrderWritePermissionTest` already proves clears the 403 gate: a type-`admin` merchant,
 * their own `Store`, `current_store` pointing at it, a `Manage Order` role, and a Sanctum token.
 * Laratrust's permission cache is disabled by that base class's `setUp()`, and its
 * `satisfyUpdaterGate()` keeps the XSS middleware's updater redirect out of the way. Without that
 * fixture every case here would pass on a 403 or a 404 and prove nothing, which is why the
 * premise tests at the top of this file assert that the handler is really reached.
 *
 * @see \App\Http\Controllers\Api\DashboardController::updateStatus()
 * @see \App\Models\Order::order_status_change()
 * @see \App\Http\Controllers\OrderController::order_return_request()
 */
class ApiOrderStatusTransitionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** `orders.return_status`: 0 none, 1 request, 2 approve, 3 cancel (app/Models/Order.php:144-146). */
    private const RETURN_NONE = 0;
    private const RETURN_REQUESTED = 1;
    private const RETURN_APPROVED = 2;

    /**
     * The handler's own premise-failure messages. A case below that "passes" while the body
     * carries one of these never reached the transition question at all: the first means the
     * `product_order_id` + `store_id` lookup missed, the second means the `Manage Order` gate
     * refused. Both are fixture faults, not evidence, and both are asserted away explicitly --
     * deliberately WITHOUT asserting a status code, because a real fix is free to answer 422, or
     * 200 with `success: false`, and a test that pinned the code would then fail on the fix.
     *
     * @see \App\Http\Controllers\Api\DashboardController::updateStatus() :448-453 and :479-485
     */
    private const NOT_FOUND_MESSAGE = 'Order not found.';
    private const NO_PERMISSION_MESSAGE = 'You do not have permission to perform this action.';

    /**
     * AND THE TWO WAYS A CRASH COULD STAND IN FOR A REFUSAL. Added after review: without these the
     * sieve above was a hole big enough to green six of this file's failing cases with a 500.
     *
     * `assertHandlerWasReached()` asserts on the MESSAGE and not the status, so that a real
     * transition guard is free to answer 422 or 200-with-`success: false`. That freedom is exactly
     * what made the sieve dangerous while it excluded only the two messages above:
     *
     *   - VALIDATION_FAILED is the handler's own 422 (DashboardController.php:414-420), returned
     *     BEFORE the lookup and before the write. An implementation that "fixed" the illegal
     *     transitions by narrowing the validator's `in:` list back down -- which is how that list
     *     read before P2-07-12 -- would refuse `delivered_status=2` as an unknown VALUE rather than
     *     as an illegal MOVE, leave the row unwritten, and green the cancel cases for a reason that
     *     is a regression: a merchant could no longer cancel a pending order through the app at all.
     *
     *   - COULD_NOT_UPDATE is the `catch (\Throwable)` 500 (DashboardController.php:635-649). The
     *     write lives inside `DB::transaction` (:520), so a throw rolls it back and leaves the row
     *     exactly as the "nothing was written" assertions below demand. An implementation that
     *     refuses an illegal transition by THROWING would therefore green those assertions while
     *     the shipped Flutter app receives an HTTP 500 and shows a generic failure -- and, per
     *     CLAUDE.md, a new non-200 on a path a client calls is a user-visible regression.
     *
     * Both are therefore fixture-or-implementation faults rather than evidence of a guard, and both
     * are excluded by name.
     */
    private const VALIDATION_FAILED_MESSAGE = 'Validation failed.';
    private const COULD_NOT_UPDATE_MESSAGE = 'Could not update the order.';

    /*
    |--------------------------------------------------------------------------
    | PREMISE -- without these four, every case below could pass for the wrong reason
    |--------------------------------------------------------------------------
    */

    /**
     * PREMISE 1: the fixture really reaches the write.
     *
     * Pending -> Confirmed is a transition BOTH surfaces permit, so it must land. If this is red,
     * the token, the permission, the route path, the `product_order_id` or `current_store` is
     * wrong and nothing else in this file means anything.
     */
    public function test_premise_the_fixture_reaches_the_handler_and_a_permitted_write_lands(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_CONFIRMED,
        ]);

        $this->assertSame(
            HomeController::ORDER_STATUS_CONFIRMED,
            $this->rawDeliveredStatus($order),
            'the fixture never reached the write. Body: ' . $this->body($response)
        );
    }

    /**
     * PREMISE 2: the web really does refuse cancel from a non-pending state.
     *
     * This is the oracle Case 1 is measured against. `order_status_change()` returns before
     * writing anything on this path (app/Models/Order.php:702-706), so running it is safe.
     */
    public function test_premise_the_web_refuses_cancel_from_delivered(): void
    {
        [, , $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
        ]);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'cancel',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            "the web's own mover accepted cancel from Delivered, so the asymmetry this file "
            . 'measures does not exist. Answer: ' . json_encode($answer));

        $this->assertSame(
            HomeController::ORDER_STATUS_DELIVERED,
            $this->rawDeliveredStatus($order),
            'the refused web cancel still wrote the row'
        );
    }

    /** PREMISE 3: the web really does refuse return from pending. The oracle for Case 2. */
    public function test_premise_the_web_refuses_return_from_pending(): void
    {
        [, , $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'return',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            "the web's own mover accepted return from Pending. Answer: " . json_encode($answer));

        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            $this->rawDeliveredStatus($order),
            'the refused web return still wrote the row'
        );
    }

    /**
     * PREMISE 4: the web's restock-on-approval really does fire in THIS fixture.
     *
     * Case 4 asserts that an app-side Return must not stop this from happening. If this premise
     * is red -- a missing `stock_movements` table, an unreadable `product_json`, a product in the
     * wrong store, a `themelanguage`/`verified` middleware refusal -- then Case 4 would be
     * measuring a restock path that never worked, and its failure would prove nothing.
     */
    public function test_premise_a_genuine_web_return_approval_credits_the_stock(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'return_status' => self::RETURN_REQUESTED,
        ]);

        $product = $this->trackedProduct($store, 4);
        $this->putOneLineOnTheOrder($order, $product, 3);

        $response = $this->approveReturnOnTheWeb($merchant, $order);

        $this->assertSame(7, $this->rawStock($product),
            'the web return approval did not credit the stock in this fixture, so Case 4 cannot '
            . 'measure anything. Status: ' . $response->getStatusCode()
            . '. Body: ' . $this->body($response));

        $this->assertSame(self::RETURN_APPROVED, $this->rawReturnStatus($order),
            'the web approval did not record itself on the order');
    }

    /*
    |--------------------------------------------------------------------------
    | CASE 1 -- Delivered -> Cancel must be refused on the API as it is on the web
    |--------------------------------------------------------------------------
    */

    /**
     * A DELIVERED order is cancelled by the app, and the web will not do it.
     *
     * The premise oracle runs INLINE, on this very order, so the case cannot pass because the
     * fixture happened to make the web permissive too.
     */
    public function test_a_delivered_order_cannot_be_cancelled_through_the_admin_api(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
        ]);

        /* INLINE ORACLE: the web refuses this exact order, and writes nothing doing so. */
        $this->assertSame(
            'error',
            Order::order_status_change(['order_id' => $order->id, 'order_status' => 'cancel'])['status'] ?? null,
            "the web's own mover accepted cancel from Delivered on this order, so there is no "
            . 'asymmetry to measure'
        );
        $this->assertSame(
            HomeController::ORDER_STATUS_DELIVERED,
            $this->rawDeliveredStatus($order),
            'the oracle itself moved the order, which would contaminate the measurement below'
        );

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
        ]);

        $this->assertHandlerWasReached($response);

        $row = $this->rawOrder($order);

        $this->assertSame(
            HomeController::ORDER_STATUS_DELIVERED,
            (int) $row->delivered_status,
            'the admin API cancelled a DELIVERED order, which the web refuses outright '
            . '(app/Models/Order.php:702). ' . $this->snapshot($row)
        );

        $this->assertNull($row->cancel_date,
            'the admin API stamped cancel_date on an order it may not cancel. '
            . $this->snapshot($row));
    }

    /**
     * THE CLAIM IS WIDER THAN "DELIVERED". The web refuses cancel from ANY non-pending state, so
     * Confirmed, Picked Up, Shipped and an already-Returned order are all cancellable over the
     * API and none of them is cancellable on the web.
     *
     * @dataProvider nonPendingStates
     */
    public function test_no_non_pending_order_can_be_cancelled_through_the_admin_api(int $from, string $name): void
    {
        [$merchant, , $order] = $this->tenantWithOrder(['delivered_status' => $from]);

        $this->assertSame(
            'error',
            Order::order_status_change(['order_id' => $order->id, 'order_status' => 'cancel'])['status'] ?? null,
            "the web accepted cancel from {$name}, so this state is not part of the asymmetry"
        );

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
        ]);

        $this->assertHandlerWasReached($response);

        $this->assertSame($from, $this->rawDeliveredStatus($order),
            "the admin API cancelled an order in state {$name} ({$from}), which the web refuses");
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function nonPendingStates(): array
    {
        return [
            'Return' => [HomeController::ORDER_STATUS_RETURN, 'Return'],
            'Confirmed' => [HomeController::ORDER_STATUS_CONFIRMED, 'Confirmed'],
            'Picked Up' => [HomeController::ORDER_STATUS_PICKED_UP, 'Picked Up'],
            'Shipped' => [HomeController::ORDER_STATUS_SHIPPED, 'Shipped'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CASE 2 -- Pending -> Return must be refused
    |--------------------------------------------------------------------------
    */

    /**
     * The API-only gap on the Return arm is specifically PENDING -> RETURN: the web permits
     * Return from every other non-pending state, and refuses exactly this one
     * (app/Models/Order.php:757-761).
     */
    public function test_a_pending_order_cannot_be_returned_through_the_admin_api(): void
    {
        [$merchant, , $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'return_status' => self::RETURN_NONE,
        ]);

        /* INLINE ORACLE. */
        $this->assertSame(
            'error',
            Order::order_status_change(['order_id' => $order->id, 'order_status' => 'return'])['status'] ?? null,
            "the web's own mover accepted return from Pending on this order"
        );
        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            $this->rawDeliveredStatus($order),
            'the oracle itself moved the order'
        );

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);

        $this->assertHandlerWasReached($response);

        $row = $this->rawOrder($order);

        $this->assertSame(
            HomeController::ORDER_STATUS_PENDING,
            (int) $row->delivered_status,
            'the admin API returned an order that was never delivered, which the web refuses '
            . '(app/Models/Order.php:757). ' . $this->snapshot($row)
        );

        $this->assertSame(
            self::RETURN_NONE,
            (int) $row->return_status,
            'the admin API wrote a return_status onto an order it may not return. 2 is the '
            . 'restock idempotency marker OrderController.php:1421-1422 reads, so this also '
            . 'pre-disarms the web approval. ' . $this->snapshot($row)
        );

        $this->assertNull($row->return_date,
            'the admin API stamped return_date on an order it may not return. '
            . $this->snapshot($row));
    }

    /*
    |--------------------------------------------------------------------------
    | The CANCEL arm's other omission -- the money
    |--------------------------------------------------------------------------
    */

    /**
     * P4-F124 — RECORDED GAP, NOT A FAILING TEST: the API cancel does not reverse the wallet credit,
     * AND THAT IS NOT FIXED HERE.
     *
     * WHAT IS MEASURED. Even on a cancel BOTH surfaces permit (Pending -> Cancel, so no transition
     * guard is involved and this is purely the money), the two doors disagree about `payment_status`:
     *
     *   - the WEB cancel writes `payment_status = 'Unpaid'` (app/Models/Order.php:712, whose own
     *     comment calls it "the missing magic line") — asserted below on a sibling order, as an
     *     oracle, so this is a measured difference and not a recollection;
     *   - the API cancel arm writes `cancel_date` and leaves `payment_status` exactly as it was
     *     (DashboardController.php:544-547) — asserted below on the order under test.
     *
     * WHY THAT MATTERS. `OrderObserver::updated()` returns early unless
     * `wasChanged('payment_status')` (app/Observers/OrderObserver.php:32-34), so the `'Unpaid'`
     * write is the ONLY thing that makes a cancellation reverse the order's wallet credit. An
     * app-side cancel of a PAID order therefore leaves the credit standing for ever, and the
     * merchant's books and the order disagree with no record of why. This is a real gap and it is
     * recorded as one.
     *
     * WHY IT IS NOT FIXED HERE, AND WHY THIS TEST NO LONGER DEMANDS THE FIX. Writing `'Unpaid'` over
     * `'Paid'` on a shipped endpoint is a payment-semantics change: it moves money through
     * `OrderObserver` -> `OrderFinancialService` on a path the shipped admin Flutter app already
     * calls, for every order a merchant has ever cancelled from the app. CLAUDE.md §2 puts
     * "material changes to prices/payments/stock/business rules or public API compatibility" in the
     * escalate-to-owner set, and this is squarely inside it. The decision also has more than one
     * defensible answer — the web's behaviour may be the correct one to copy, or the web's
     * unconditional `'Unpaid'` may itself be wrong for a COD order that was never collected — and a
     * test that demanded one of them would have made the choice by stealth, which is exactly the
     * failure mode this file was written to avoid.
     *
     * SO THIS PINS THE CURRENT BEHAVIOUR. `payment_status` is asserted to be UNCHANGED by the API
     * cancel. The pin is deliberately two-directional: it fails if somebody silently starts writing
     * `'Unpaid'` here (the unescalated fix) and it fails if somebody writes anything else. When the
     * owner decides, this case is the one to invert, and the oracle below is already in place to
     * measure the web side of whatever they decide.
     *
     * Same shape as P4-F118 (`MultiLineCouponArithmeticTest`), P4-F120 and P4-F122.
     *
     * @see \App\Observers\OrderObserver::updated()
     * @see \App\Models\Order::order_status_change()  the web arm, which does write 'Unpaid'
     */
    public function test_the_api_cancel_leaves_the_payment_status_alone_p4_f124(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Paid',
        ]);

        /*
         * ORACLE, on a SIBLING order in the same state, because the oracle's own cancel would
         * consume the order under test. It proves the web really does write 'Unpaid' here — which
         * is what makes the API's silence below a measured DIFFERENCE between two doors rather than
         * an assumption about one. It is also what keeps the recorded gap honest: if the web ever
         * stops writing 'Unpaid', this oracle reds and the gap has to be re-described rather than
         * quietly surviving as a note about behaviour nobody has.
         */
        $sibling = $this->makeOrder($store, [
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Paid',
            'return_status' => self::RETURN_NONE,
            'product_json' => '[]',
        ]);

        $this->assertSame(
            'success',
            Order::order_status_change(['order_id' => $sibling->id, 'order_status' => 'cancel'])['status'] ?? null,
            'the web refused a cancel from Pending, so the oracle is not measuring the web rule'
        );
        $this->assertSame('Unpaid', (string) $this->rawOrder($sibling)->payment_status,
            "the web's cancel did not write payment_status = 'Unpaid', so there is nothing for "
            . 'the API arm to be missing');

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
        ]);

        $this->assertHandlerWasReached($response);

        $row = $this->rawOrder($order);

        $this->assertSame(
            HomeController::ORDER_STATUS_CANCEL,
            (int) $row->delivered_status,
            'the premise failed: the API did not cancel a PENDING order, which both surfaces '
            . 'permit. ' . $this->snapshot($row)
        );

        /*
         * THE PIN. 'Paid' is the CURRENT behaviour and this records it; it is not an endorsement.
         *
         * A failure here means one of two things and both need a human:
         *
         *   - it now reads 'Unpaid' — somebody implemented the fix. The gap is real and the fix is
         *     probably right, but it is a payment-semantics change on a shipped endpoint and it
         *     belongs to the owner, not to a drive-by. Escalate it, get the decision, then invert
         *     this case and say so in the register row for P4-F124.
         *   - it reads anything else — the API cancel has started writing a payment state nobody
         *     specified, which is a defect in its own right.
         */
        $this->assertSame('Paid', (string) $row->payment_status,
            'P4-F124 is a RECORDED GAP and this is its pin, so a failure here is a CHANGE and not '
            . 'a bug report. The API cancel arm has always left payment_status untouched '
            . '(DashboardController.php:544-547) while the web arm writes \'Unpaid\' '
            . '(app/Models/Order.php:712) — and because OrderObserver::updated() returns early '
            . 'unless payment_status changed (OrderObserver.php:32-34), that write is the only '
            . 'thing that reverses a cancelled order\'s wallet credit. Making the API match is a '
            . 'payment-semantics change on a shipped endpoint and is an OWNER decision under '
            . 'CLAUDE.md §2, not something to land with a stock fix. ' . $this->snapshot($row));
    }

    /*
    |--------------------------------------------------------------------------
    | CASE 3 -- the app's own Return transition must ACCOUNT FOR THE GOODS
    |--------------------------------------------------------------------------
    */

    /**
     * Delivered -> Return IS a transition the web permits, so this case is not about refusing it.
     * It is about the goods. The app writes `return_status = 2` -- "approved" -- and credits
     * nothing, so the order says the return is settled while the shelf says the stock never came
     * back.
     *
     * THE ASSERTION DELIBERATELY DOES NOT PRE-JUDGE THE REMEDY. Either the API arm credits the
     * stock itself, or it leaves the return at `1` (requested) so the merchant's existing web
     * approval can still do it. Both are acceptable; writing `2` with no credit is the one
     * outcome that is not.
     */
    public function test_the_app_return_transition_must_account_for_the_goods(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'return_status' => self::RETURN_NONE,
        ]);

        $product = $this->trackedProduct($store, 4);
        $this->putOneLineOnTheOrder($order, $product, 3);

        $response = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);

        $this->assertHandlerWasReached($response);

        $stock = $this->rawStock($product);
        $return = $this->rawReturnStatus($order);

        $this->assertTrue($stock === 7 || $return === self::RETURN_REQUESTED,
            'the app return transition must either credit the stock or leave the return in a '
            . 'state the web UI can still approve. Measured product_stock = ' . $stock
            . ' (4 before the return of 3) and return_status = ' . $return
            . '. return_status 2 is the restock idempotency marker OrderController.php:1421-1422 '
            . 'reads, and both of the web controls are gated on return_status == 1, so this '
            . 'order has no UI-reachable route back to its stock');
    }

    /*
    |--------------------------------------------------------------------------
    | CASE 4 -- the app must not POISON the web's approval
    |--------------------------------------------------------------------------
    */

    /**
     * THE REAL-WORLD SEQUENCE. The merchant presses "Return" in the Flutter app, then opens the
     * web panel and approves the return request properly. The goods must be credited -- once.
     *
     * Today the app's write has already set the marker the web approval reads, so
     * `$isNewApproval` is false, `restoreReturnedStock()` never runs, and the response still says
     * "Return request approve successfully". Silent, and the stock is gone.
     */
    public function test_the_app_return_write_must_not_disarm_the_web_return_approval(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'return_status' => self::RETURN_NONE,
        ]);

        $product = $this->trackedProduct($store, 4);
        $this->putOneLineOnTheOrder($order, $product, 3);

        /* STEP 1 -- the app. */
        $apiResponse = $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);

        $this->assertHandlerWasReached($apiResponse);

        $afterTheApp = $this->rawReturnStatus($order);
        $stockAfterTheApp = $this->rawStock($product);

        /*
         * The two requests are one process here and two processes in production. `Order` and
         * `Product` are both Cachable and `CACHE_STORE=array` keeps a model-cache entry alive for
         * the rest of the test, so the cache is dropped between the steps: without this, a stale
         * read -- not the defect -- could be the thing being measured.
         */
        Cache::flush();

        /* STEP 2 -- the merchant's genuine web approval. */
        $webResponse = $this->approveReturnOnTheWeb($merchant, $order);

        $this->assertSame(7, $this->rawStock($product),
            "the app's Return write disarmed the merchant's web approval: the returned goods were "
            . 'never credited. Measured product_stock = ' . $this->rawStock($product)
            . ' (4 before a return of 3; expected 7). After the app POST, return_status was '
            . $afterTheApp . ' and product_stock was ' . $stockAfterTheApp
            . '. The web approval answered ' . $webResponse->getStatusCode() . ' '
            . $this->body($webResponse));
    }

    /**
     * AND EXACTLY ONCE. The counterpart to Case 4: a remedy that credits the stock in the API arm
     * AND again on the web approval would be the unbounded-inflation defect
     * `ReturnApprovalStockTest` already closed on the web side, arriving by a new door.
     */
    public function test_the_app_return_followed_by_the_web_approval_credits_the_stock_only_once(): void
    {
        [$merchant, $store, $order] = $this->tenantWithOrder([
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
            'return_status' => self::RETURN_NONE,
        ]);

        $product = $this->trackedProduct($store, 4);
        $this->putOneLineOnTheOrder($order, $product, 3);

        $this->postStatus($merchant, $order, [
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);

        Cache::flush();

        $this->approveReturnOnTheWeb($merchant, $order);
        Cache::flush();
        $this->approveReturnOnTheWeb($merchant, $order);

        $this->assertSame(7, $this->rawStock($product),
            'the returned quantity must be credited once across both doors, not twice. '
            . 'Measured product_stock = ' . $this->rawStock($product));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * The `AdminApiOrderWritePermissionTest` fixture, which is already proven to clear the 403
     * gate: a type-`admin` merchant, their own store, `current_store` pointing at it, and the
     * `Manage Order` role `OrderPolicy::updateStatus()` requires.
     *
     * @return array{0: User, 1: Store, 2: Order}
     */
    private function tenantWithOrder(array $orderAttributes = []): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        $order = $this->makeOrder($store, array_merge([
            'payment_status' => 'Unpaid',
            'return_status' => self::RETURN_NONE,
            'product_json' => '[]',
        ], $orderAttributes));

        return [User::find($merchant->id), $store, $order];
    }

    private function postStatus(User $user, Order $order, array $payload)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/orders/update-status', array_merge([
            'product_order_id' => $order->product_order_id,
        ], $payload));
    }

    /** The merchant's genuine web control: `order.return.request` with status 2 (approve). */
    private function approveReturnOnTheWeb(User $merchant, Order $order)
    {
        return $this->actingAs(User::find($merchant->id))
            ->post(route('order.return.request'), [
                'id' => $order->id,
                'status' => self::RETURN_APPROVED,
            ]);
    }

    /** A product whose stock is really tracked, in the order's own store. */
    private function trackedProduct(Store $store, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'returnable',
            'slug' => 'returnable-' . uniqid(),
            'price' => 10,
            'sale_price' => 10,
            'cost_price' => 4,
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /**
     * One line, written through Eloquent so the `Cachable` model cache is flushed. A raw
     * `DB::table('orders')->update()` here would leave `Order::find()` -- which is what both
     * handlers use -- serving the pre-write `product_json`.
     */
    private function putOneLineOnTheOrder(Order $order, Product $product, int $qty): void
    {
        $order->product_json = json_encode([[
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => $qty,
        ]]);
        $order->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Raw readers -- never the model, never the status code
    |--------------------------------------------------------------------------
    */

    private function rawOrder(Order $order): object
    {
        return DB::table('orders')->where('id', $order->id)->first();
    }

    private function rawDeliveredStatus(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('delivered_status');
    }

    private function rawReturnStatus(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('return_status');
    }

    private function rawStock(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function body($response): string
    {
        return substr((string) $response->getContent(), 0, 300);
    }

    /**
     * Every figure this file cares about, in one line, so a failure reports what was MEASURED
     * rather than only what was expected. Read off the raw row, never the model.
     */
    private function snapshot(object $row): string
    {
        return 'MEASURED ROW: delivered_status=' . var_export($row->delivered_status, true)
            . ' return_status=' . var_export($row->return_status, true)
            . ' payment_status=' . var_export($row->payment_status, true)
            . ' cancel_date=' . var_export($row->cancel_date, true)
            . ' return_date=' . var_export($row->return_date, true)
            . ' delivery_date=' . var_export($row->delivery_date, true);
    }

    /**
     * The handler was reached, it asked the transition question, and it answered it deliberately:
     * this is not a 404 lookup miss, not a permission refusal, not a value-level validation
     * rejection and not a crash.
     *
     * Asserted on the MESSAGE, not the status, because a real transition guard is free to answer
     * 422 or 200-with-success-false and a status assertion would then fail on the fix. The four
     * exclusions are what keeps that freedom from also admitting a refusal nobody wrote -- see the
     * constants' docblocks for why a 422 on the VALUE and a rolled-back 500 both leave the row in
     * the state these cases assert.
     */
    private function assertHandlerWasReached($response): void
    {
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString(self::NOT_FOUND_MESSAGE, $body,
            'the order lookup missed, so the transition question was never asked -- a fixture '
            . 'fault, not evidence');

        $this->assertStringNotContainsString(self::NO_PERMISSION_MESSAGE, $body,
            'the Manage Order gate refused, so the transition question was never asked -- a '
            . 'fixture fault, not evidence');

        $this->assertStringNotContainsString(self::VALIDATION_FAILED_MESSAGE, $body,
            'the request was refused by the VALIDATOR, before the lookup and before the write '
            . '(DashboardController.php:414-420), so the row is unwritten for a reason that is not '
            . 'a transition guard. If this is the fix, the validator has been narrowed back to a '
            . 'hand-typed `in:` list and the app can no longer request a status the column holds -- '
            . 'a regression, not a repair. Body: ' . $body);

        $this->assertStringNotContainsString(self::COULD_NOT_UPDATE_MESSAGE, $body,
            'the handler THREW and answered 500 (DashboardController.php:635-649). The write sits '
            . 'inside DB::transaction (:520), so the throw rolled it back and left the row exactly '
            . 'as the assertions here demand -- a crash standing in for a refusal. A transition '
            . 'guard must refuse in words the shipped Flutter app can render; a new 500 on a path '
            . 'a client calls is a user-visible regression. Body: ' . $body);
    }
}

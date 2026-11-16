<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliverySyriaCredential;
use App\Services\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F056 -- THE DELIVERY SYRIA WEBHOOK MOVES AN ORDER TO "RETURNED" AND LEAVES THE GOODS OFF THE
 * SHELF, THEN SHUTS EVERY AUTOMATED DOOR THAT COULD HAVE PUT THEM BACK.
 * =================================================================================================
 *
 * `POST api/delivery-syria/orders/update-status` (routes/api.php:71-74 ->
 * `Api\DeliverySyriaOrderController::updateStatus`) is the courier's status feed. Its `switch`
 * (:190-236) writes a DATE and, for a return, a RETURN STATE -- and nothing else:
 *
 *     case 2:  // Cancel     $order->cancel_date = now();                            (:200-203)
 *     case 3:  // Return     $order->return_date = now();  $order->return_status = 2; (:205-220)
 *
 * No line of `product_json` is read and no stock figure is touched, in either limb. Both are
 * transitions this codebase restocks everywhere else:
 *
 *   - `OrderController::status_cancel()` restores every line of a cancelled order through
 *     `ProductStockService` -- ratcheted by `OrderCancelRestockTest`;
 *   - `OrderController::order_return_request()` with `status = 2` restores every line of an
 *     approved return via `restoreReturnedStock()` (:1437-1445, :1469) -- ratcheted by
 *     `ReturnApprovalStockTest` and `OrderReturnWorkflowTest`.
 *
 * So the courier's own report of the same two events is the one path in the system that moves the
 * order and not the inventory.
 *
 * THE RETURN LIMB IS THE WORSE OF THE TWO, because it does not merely omit the restock -- it
 * forecloses it. `return_status = 2` at :219 is the exact value both remaining automated doors use
 * as their "already counted back in" marker:
 *
 *   1. `OrderController::order_return_request()` computes
 *      `$isNewApproval = ((int) $status === 2) && $previousReturnStatus !== 2;` (:1422). The webhook
 *      has already written 2, so a merchant approving the return gets `$isNewApproval === false`,
 *      `restoreReturnedStock()` is skipped (:1438-1440), and the handler still answers
 *      *"Return request approve successfully"* (:1448). A success message over a no-op.
 *
 *   2. `Order::product_return()` -- the per-line merchant return -- refuses first, at
 *      `if ($Order->delivered_status != 1)` (app/Models/Order.php:963), because the webhook left
 *      `delivered_status = 3`.
 *
 *   3. And the merchant is never even offered the control: both order views gate the Approve and
 *      Reject buttons on `$order->delivered_status == 3 && $order->return_status == 1`
 *      (resources/views/order/action.blade.php:35, resources/views/order/grid.blade.php:230). The
 *      webhook satisfies the first half and breaks the second, so the row renders as "Return" with
 *      no action attached and the stock quietly missing.
 *
 * NOT "PERMANENTLY BLOCKED": a merchant can still correct the figure by hand-editing the product.
 *
 * AND NOT "EVERY AUTOMATED DOOR" EITHER -- the finding overstates this, and the correction is
 * recorded here because it bounds the severity. `OrderRefundController::RefundStock()` also
 * restocks through `ProductStockService` (app/Http/Controllers/OrderRefundController.php:805), and
 * it is gated on the REFUND's own `refund_status` being accepted or refunded
 * (`RESTOCKABLE_STATES`, :161, checked at :661-666) -- that controller contains **no reference at
 * all** to `delivered_status` or `return_status`. So a merchant who raises and accepts a refund
 * request for the order can still recover the quantity automatically. What the webhook shuts is
 * both RETURN doors; the REFUND door stays open, at the cost of booking the movement as
 * `REASON_REFUND` rather than a return and of requiring a financial refund record that the
 * merchant may not want to create.
 *
 * ONE MORE BOUND ON THE CANCEL LIMB, for the same reason: the webhook is not uniquely wrong there.
 * `Order::order_status_change()`'s own `cancel` branch (app/Models/Order.php:701-720) restores no
 * stock either, while `OrderController::status_cancel()` restores every line. The two host cancel
 * doors already disagree with each other, so the cancel case below is written against
 * `status_cancel()`'s behaviour (the one with a ratchet) and is flagged as touching a wider
 * question than this webhook.
 *
 * WHY EVERY FIGURE IS READ WITH `DB::table()`. `Product` and `Order` are both `Cachable`
 * (CLAUDE.md: 79 of 95 models are), and the handlers under test write through Eloquent while
 * `ProductStockService` issues `increment()`. A model read here can serve a pre-write row for the
 * rest of the test under `CACHE_STORE=array`, which has produced measured false readings in this
 * repository. The database is the system of record for an inventory claim.
 *
 * WHY A 200 IS NEVER THE EVIDENCE. CLAUDE.md records that `ApiResponser::error()` answers HTTP 200
 * with `{status: 0}`. This controller does not use that trait -- it returns explicit codes -- but
 * the discipline still applies: every case below asserts the ORDER ROW MOVED before it asserts
 * anything about stock, so a refusal cannot masquerade as a passing "nothing was inflated" test.
 *
 * THE REPAIR LANDED ON 2026-11-16 AND IT CHOSE REMEDY (b) FOR BOTH RETURN ARMS, so the three cases
 * that were written red are no longer specifications of a remedy they pre-empted. Read
 * `test_the_return_webhook_is_accepted_and_moves_the_order` for the two remedies and why the return
 * assertions are DISJUNCTS -- credited, OR left in a state the merchant can still approve -- rather
 * than figures. (b) writes `return_status = 1`: a courier reporting a return is a REQUEST, the goods
 * are on their way back and not checked in, the Approve control reappears (both web controls gate on
 * `== 1`) and the credit goes through the one existing restore door. The disjunct is kept and not
 * collapsed to the measured value because the finding has two correct answers and this file must not
 * decide between them a second time.
 *
 * ONE CASE IS STILL RED, AND IT IS A PRODUCTION DEFECT IN THE FIX, NOT IN THE TEST.
 * `test_a_courier_reported_cancellation_gives_the_stock_back` measures HTTP 500. Diagnosed, not
 * guessed -- the handler's own log line says it:
 *
 *     Delivery Syria order status update failed {"order_id":2,"old_status":0,"new_status":2,
 *       "exception":"ErrorException","message":"Undefined variable $oldStatus",
 *       "file":".../Api/DeliverySyriaOrderController.php","line":222}
 *
 * `$oldStatus` is assigned in the METHOD body (:159) and read inside the `DB::transaction` CLOSURE
 * (:222), whose `use (...)` list captures only `$order, $newStatus, $request` (:185-189). So the new
 * `if ($oldStatus === 0)` replay guard reads an undefined variable, PHP raises a warning, Laravel's
 * error handler turns it into an `ErrorException`, the outer `catch` logs it and answers 500, and the
 * transaction rolls back. EVERY courier-reported cancellation now 500s and writes nothing at all --
 * a wider regression than the omitted restock it replaced, on a live webhook a courier retries. The
 * one-word fix is adding `$oldStatus` to that `use` list; it is a production file and is not this
 * file's change to make.
 *
 * `.github/known-test-outcomes.json` asserts the Feature suite's outcome SET in both directions, so
 * a case left red has to be declared there -- or the gate fails on undeclared failures. Declaring
 * one is not this file's change to make; it belongs with the fix, and the fix here is a one-line
 * scope correction rather than a declaration.
 *
 * @see \App\Http\Controllers\Api\DeliverySyriaOrderController::updateStatus()
 * @see \App\Http\Controllers\OrderController::order_return_request()
 * @see \App\Http\Controllers\OrderController::restoreReturnedStock()
 * @see \Tests\Feature\Commerce\ReturnApprovalStockTest  the return door that does restock
 * @see \Tests\Feature\Commerce\OrderCancelRestockTest   the cancel door that does restock
 */
final class DeliverySyriaReturnRestockTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const WEBHOOK = '/api/delivery-syria/orders/update-status';

    /** The per-merchant bearer secret, `users.deliverysyria_secret`. */
    private const SECRET = 'DS-SECRET-FIXTURE';

    private const OPENING_STOCK = 4;
    private const RETURNED_QTY = 2;

    /** The figure the goods are worth: 4 on the shelf + 2 back off the van. */
    private const RESTOCKED = self::OPENING_STOCK + self::RETURNED_QTY;

    /** `orders.delivered_status`, read off app/Models/Order.php and the controller's statusName(). */
    private const DELIVERED = 1;
    private const CANCELLED = 2;
    private const RETURNED = 3;

    /** `orders.return_status`: 0 none, 1 request, 2 approve, 3 cancel. */
    private const RETURN_NONE = 0;
    private const RETURN_REQUESTED = 1;
    private const RETURN_APPROVED = 2;

    private User $merchant;
    private Store $store;
    private Product $product;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // A tenant whose owner holds the courier secret. `DeliverySyriaCredential::storeIdsFor()`
        // matches the token against `users.deliverysyria_secret` and then joins
        // `stores.created_by` IN (owner ids) -- app/Services/DeliverySyriaCredential.php:36-48 --
        // so the secret has to sit on the row that CREATED the store, not on the store.
        $this->merchant = $this->makeMerchant(['deliverysyria_secret' => self::SECRET]);
        $this->store = $this->makeStore($this->merchant);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();

        $this->product = $this->productIn($this->store, self::OPENING_STOCK);

        // `delivered_status = 1` deliberately: the handler short-circuits with
        // "Order already has this status." when `$oldStatus === $newStatus` (:164-175), so an
        // order parked at 3 would never reach the `switch` and every assertion below would be
        // vacuous.
        $this->order = $this->makeOrder($this->store, [
            'product_order_id' => 'DS-1',
            'delivered_status' => self::DELIVERED,
            'return_status' => self::RETURN_NONE,
            'product_json' => $this->lineJson($this->product, self::RETURNED_QTY),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 0. PREMISES -- without these the two defect cases below prove nothing
    |--------------------------------------------------------------------------
    |
    | Each of the four ways a test passes for the wrong reason in this repository has a premise
    | here: a wrong route (404), a refused credential (403), an order the predicate cannot see
    | (404), and a stale `Cachable` read.
    */

    /**
     * THE FIXTURE IS AN ORDER WHOSE STOCK IS ALREADY OUT.
     *
     * This is the premise the whole file rests on: 4 units on the shelf, 2 of them sold and gone,
     * the order delivered and no return recorded yet. If the opening figure were already 6 the
     * assertions below would be green with the defect fully present.
     */
    public function test_the_fixture_represents_an_order_that_took_its_stock_out(): void
    {
        $this->assertSame(
            self::OPENING_STOCK,
            $this->stock(),
            'the fixture must open at ' . self::OPENING_STOCK . ' units with the returned quantity '
            . 'already out of stock, or "the stock came back" cannot be distinguished from "the '
            . 'stock never left"'
        );

        $row = $this->orderRow();

        $this->assertSame('DS-1', (string) $row->product_order_id);
        $this->assertSame(
            self::DELIVERED,
            (int) $row->delivered_status,
            'the order must be DELIVERED, not already RETURNED: the handler short-circuits at '
            . 'DeliverySyriaOrderController:164 when the new status equals the old one, and the '
            . 'switch under test would never run'
        );
        $this->assertSame(self::RETURN_NONE, (int) $row->return_status);
        $this->assertSame((int) $this->store->id, (int) $row->store_id);

        $lines = json_decode((string) $row->product_json, true);

        $this->assertIsArray($lines, 'the lines must be readable, or no restock path could act');
        $this->assertCount(1, $lines);
        $this->assertSame((int) $this->product->id, (int) $lines[0]['product_id']);
        $this->assertSame(self::RETURNED_QTY, (int) $lines[0]['qty']);

        // And the lines are in the shape the merchant restock path can read, which is a separate
        // question from "is it valid JSON": `Order::linesAreReturnable()` refuses a POS-shaped
        // line, and that refusal would be indistinguishable from the defect.
        $this->assertTrue(
            Order::linesAreReturnable($lines),
            'the fixture lines must be in the shape the return workflow can read, or the merchant '
            . 'limb below is refused for the wrong reason'
        );
    }

    /**
     * THE ROUTE REACHES THE HARDENED CONTROLLER, not an inline closure.
     *
     * This exact URI was once shadowed by a duplicate closure in routes/web.php
     * (NEW-DS-ROUTE-SHADOW-01), which answered 422 and never reached the controller at all; and
     * CLAUDE.md records that a later declaration silently replaces an earlier one for the same
     * method+URI. A test driving a shadowed route measures the shadow.
     */
    public function test_the_webhook_route_reaches_the_controller_under_test(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/delivery-syria/orders/update-status'
                && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'the webhook route is missing');
        $this->assertSame(
            \App\Http\Controllers\Api\DeliverySyriaOrderController::class . '@updateStatus',
            $route->getActionName(),
            'POST ' . self::WEBHOOK . ' no longer resolves to the controller this file measures'
        );
    }

    /**
     * THE CREDENTIAL RESOLVES TO THE FIXTURE STORE.
     *
     * The handler confines its order lookup to `whereIn('store_id', $storeIds)` (:145-148). If the
     * secret resolved to no store the lookup would answer 404 for an order that plainly exists,
     * and "the stock did not change" would be true because nothing ran.
     */
    public function test_the_fixture_credential_owns_the_fixture_store(): void
    {
        $this->assertContains(
            (int) $this->store->id,
            DeliverySyriaCredential::storeIdsFor(self::SECRET),
            'the courier secret must resolve to the store holding the order, via the '
            . 'users.deliverysyria_secret -> stores.created_by join at '
            . 'app/Services/DeliverySyriaCredential.php:36-48'
        );
    }

    /**
     * THE WEBHOOK'S RETURN LIMB REALLY RUNS -- it moves the order.
     *
     * The strongest premise in the file: the handler was reached, authenticated, found the order,
     * entered `case 3` and committed. Whatever the stock assertions say afterwards, they are
     * measuring a limb that executed.
     *
     * THE `return_status` ASSERTION IS A DISJUNCT, NOT A FIGURE, and that is the whole repair this
     * finding needed. This case pinned `return_status === 2` because that was the measured defect,
     * which made the test a specification of ONE remedy: restock inside the webhook and leave the
     * approved marker standing. The finding has two correct remedies and the choice between them is
     * a product decision this test had no business making:
     *
     *   (a) the webhook CREDITS the goods, and `return_status = 2` then honestly records an approved,
     *       counted-in return;
     *   (b) the webhook writes `return_status = 1` -- a REQUEST -- because a courier reporting a
     *       return means the goods are on their way back, not checked in; the Approve control
     *       reappears in front of the merchant (both web controls gate on `== 1`) and the credit
     *       goes through the one existing restore door.
     *
     * The defect is the state that is NEITHER: `return_status = 2` with no credit, which claims the
     * goods were counted in and simultaneously forecloses counting them in. So what is asserted is
     * the disjunct -- credited, OR left in a state the merchant can still approve -- the same shape
     * `ApiOrderStatusTransitionTest::test_the_app_return_transition_must_account_for_the_goods` uses
     * on the sibling finding. MEASURED 2026-11-16: the production fix chose (b), so the second limb
     * is the one that holds.
     */
    public function test_the_return_webhook_is_accepted_and_moves_the_order(): void
    {
        $response = $this->postReturnWebhook();

        $response->assertStatus(200)->assertJson(['success' => true]);

        $row = $this->orderRow();

        $this->assertSame(
            self::RETURNED,
            (int) $row->delivered_status,
            'case 3 of the switch did not run'
        );
        $this->assertNotNull($row->return_date, 'case 3 stamps return_date (:207)');

        $returnStatus = (int) $row->return_status;
        $stock = $this->stock();

        $this->assertTrue(
            $stock === self::RESTOCKED || $returnStatus === self::RETURN_REQUESTED,
            'the courier-reported return left the goods in the one state that is neither remedy: '
            . 'uncredited AND marked approved. Measured product_stock = ' . $stock . ' (opening '
            . self::OPENING_STOCK . ', ' . self::RETURNED_QTY . ' returned, so ' . self::RESTOCKED
            . ' if credited) and return_status = ' . $returnStatus . '. return_status 2 is the '
            . 'durable restock idempotency marker OrderController.php:1421-1422 reads before '
            . 'crediting, and BOTH web controls are gated on return_status == 1 '
            . '(order/action.blade.php:35, order/grid.blade.php:230) -- so an uncredited 2 has no '
            . 'UI-reachable route back to its stock. Either credit the goods here, or write 1 and '
            . 'leave the credit to the merchant approval.'
        );
    }

    /**
     * THE MERCHANT'S RESTOCK DOOR WORKS when the webhook has not been near the order.
     *
     * The anti-vacuity control for the merchant limb. Same merchant, same permission, same route,
     * same payload, same product -- on an order sitting at `return_status = 1`, the state the
     * ordinary customer-requested return reaches. If this were red, the merchant case below would
     * be failing on authorization, middleware or fixture rather than on the defect.
     */
    public function test_the_merchant_restock_door_works_on_an_order_the_webhook_never_touched(): void
    {
        $product = $this->productIn($this->store, self::OPENING_STOCK);
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'DS-CONTROL',
            'delivered_status' => self::RETURNED,
            'return_status' => self::RETURN_REQUESTED,
            'product_json' => $this->lineJson($product, self::RETURNED_QTY),
        ]);

        $response = $this->approveReturnAsMerchant($order);

        $response->assertStatus(200);

        $this->assertSame(
            self::RESTOCKED,
            $this->stock($product),
            'the merchant approval path itself must restock, or every "the merchant cannot '
            . 'restock" assertion in this file is about the fixture and not about the defect'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1. THE DEFECT -- the courier reports a return and no goods come back
    |--------------------------------------------------------------------------
    */

    /**
     * A courier-reported return must ACCOUNT FOR the returned quantity -- credit it, or leave the
     * return in a state the merchant can still approve.
     *
     * RENAMED from `test_a_courier_reported_return_gives_the_stock_back`, because that name was a
     * specification of one remedy and the disjunct is the real contract. See
     * `test_the_return_webhook_is_accepted_and_moves_the_order` above for the two remedies and why
     * demanding the credit HERE would have forced remedy (a) before anybody chose it.
     *
     * MEASURED AT THE TIME OF WRITING: the shelf stayed at 4. `case 3` (:205-220) stamped
     * `return_date`, wrote `return_status = 2`, and `$order->save()` persisted those two columns and
     * nothing else -- `product_json` was never decoded, `ProductStockService` was never called, and
     * the 2 units the customer handed back existed nowhere in the inventory while the order claimed
     * they had been counted in.
     *
     * MEASURED 2026-11-16, after the fix: the shelf is still 4 and `return_status` is 1, so the
     * second limb holds -- remedy (b). The goods are now owed to a return the merchant can see and
     * approve, and `test_the_merchant_can_still_restock_a_courier_reported_return` below is what
     * proves that approval really credits them. The two cases together are what make the disjunct
     * safe: without the second, "left in an approvable state" could be an approvable state that
     * still credits nothing.
     *
     * WHICH BRANCH OF `movedOutFor()` THIS FIXTURE EXERCISES IS NOW ASSERTED, not assumed. This
     * file records NO `stock_movements` row of any kind, so a restore loop asking "did this line
     * take stock out" gets UNKNOWN — no rows for the line, and no `out` row for the order to say it
     * was ever observed (StockMovement.php:174-203) — and UNKNOWN means RESTORE-AND-LOG, because
     * D-15 forbids inferring movement from status. That is a DIFFERENT branch from the MOVED one
     * `MerchantCancelRestockTest` seeds, and the distinction is load-bearing: a fixture that read
     * NOT_MOVED would be correctly SKIPPED by a perfect fix and this case would stay red for ever
     * while looking like a defect. The premise is asserted BEFORE the webhook runs, because that is
     * the state the restore loop would see — a fix that credits and records its `in` movement
     * turns the same question into NOT_MOVED afterwards.
     */
    public function test_a_courier_reported_return_accounts_for_the_goods(): void
    {
        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor(
                $this->store->id,
                $this->order->id,
                $this->product->id,
                null
            ),
            'premise: this fixture no longer reads UNKNOWN. It records no stock_movements row, so a '
            . 'restore loop must reach the UNKNOWN arm and restore. If this now reads NOT_MOVED, a '
            . 'correct fix would SKIP this line and the assertion below could never go green; if it '
            . 'reads MOVED, something is seeding movements and this case has silently changed '
            . 'meaning.'
        );

        $this->postReturnWebhook()->assertStatus(200)->assertJson(['success' => true]);

        // PREMISE, repeated inside the case on purpose: a 403, a 404 or the
        // `$oldStatus === $newStatus` short-circuit would all leave the stock untouched too, and
        // would look identical to the defect from the stock figure alone.
        $this->assertSame(
            self::RETURNED,
            (int) $this->orderRow()->delivered_status,
            'premise: the return limb ran. Without this the figure below is not evidence.'
        );

        $stock = $this->stock();
        $returnStatus = (int) $this->orderRow()->return_status;

        $this->assertTrue(
            $stock === self::RESTOCKED || $returnStatus === self::RETURN_REQUESTED,
            'the Delivery Syria webhook accepted a RETURN, moved the order to "Returned", restored '
            . 'no stock AND wrote the approved marker over it. Measured product_stock = ' . $stock
            . ' (expected ' . self::RESTOCKED . ' if credited) and return_status = ' . $returnStatus
            . '. Every other return door in this codebase restores the goods '
            . '(OrderController::order_return_request -> restoreReturnedStock, ratcheted by '
            . 'ReturnApprovalStockTest), so the courier feed is the one path that moves the order '
            . 'and not the inventory -- and because return_status 2 is the idempotency marker that '
            . 'door reads (OrderController.php:1421-1422), and both web controls are gated on '
            . 'return_status == 1, the goods are invisible to the catalogue with no UI-reachable '
            . 'way back. Credit them here, or write return_status = 1 so the merchant can.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. AND THE WEBHOOK SHUTS THE DOOR BEHIND IT
    |--------------------------------------------------------------------------
    */

    /**
     * After the webhook, the merchant's own approval must still credit the goods -- exactly once.
     *
     * THIS IS THE CASE THAT MAKES THE DISJUNCT ABOVE SAFE, and it is the one assertion in the file
     * that did NOT become a disjunct. "The webhook left the return in an approvable state" is only
     * worth anything if approving it really credits the goods; without this case, remedy (b) could
     * be satisfied by writing `return_status = 1` and never crediting anything at all, which is the
     * same lost stock behind a politer marker.
     *
     * MEASURED BEFORE THE FIX: the shelf stayed at 4 and the merchant was told "Return request
     * approve successfully". `order_return_request` reads
     * `$previousReturnStatus = (int) $order->return_status` and computes
     * `$isNewApproval = ((int) $status === 2) && $previousReturnStatus !== 2` (:1421-1422). The
     * webhook had already written 2, so the guard that exists to stop a DOUBLE credit instead
     * stopped the ONLY credit, and the handler went on to write `return_price` and `return_status`
     * and answer success (:1442-1452) over a no-op.
     *
     * MEASURED 2026-11-16, after the fix: the webhook writes 1, so `$previousReturnStatus` is 1,
     * `$isNewApproval` is TRUE, `restoreReturnedStock()` runs and the shelf reaches 6. The premise
     * below therefore asserts `return_status = 1` -- the approvable state -- and it is an
     * assertion about the REPAIRED property rather than about the defect: it is what would catch a
     * regression back to 2, which is the shape that disarms the guard.
     *
     * "EXACTLY ONCE" IS ASSERTED, not just "at least once", because the fix must not reintroduce
     * the double credit that guard was added for (P1-INV-15 (E)). Under remedy (b) the credit
     * happens here and nowhere else; under remedy (a) it would happen in the webhook and this call
     * would be a genuine no-op -- but the goods must appear once, and 8 is as wrong as 4.
     */
    public function test_the_merchant_can_still_restock_a_courier_reported_return(): void
    {
        $this->postReturnWebhook()->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSame(
            self::RETURN_REQUESTED,
            (int) $this->orderRow()->return_status,
            'premise: the webhook must leave the return at 1 -- a REQUEST the merchant can approve. '
            . 'A 2 here is the regression that disarms the $isNewApproval guard at '
            . 'OrderController:1422 and silently converts the approval below into a no-op that '
            . 'still answers "Return request approve successfully"'
        );

        $response = $this->approveReturnAsMerchant($this->order);

        // PREMISE: the merchant actually reached the handler. `auth`, `verified`, `xss`,
        // `setlocate` and `themelanguage` all sit in front of this route, and a redirect or a 403
        // would leave the stock untouched for a reason that has nothing to do with the finding.
        $response->assertStatus(200);

        // AND IT RAN ITS WRITE BRANCH. `return_price = final_price` is written unconditionally
        // alongside `return_status` inside the same transaction as the restock
        // (OrderController:1442-1444), so a non-zero `return_price` is locale-independent proof
        // that the approval branch committed -- which is what makes the figure below a measurement
        // of the restock rather than of a handler that never ran.
        $this->assertSame(
            100.0,
            (float) $this->orderRow()->return_price,
            'premise: order_return_request committed its approval write (return_price <- '
            . 'final_price), so the stock figure below measures the restock and not a handler that '
            . 'never ran'
        );

        $this->assertSame(
            self::RESTOCKED,
            $this->stock(),
            'the merchant approved the courier-reported return and the goods did not come back -- '
            . 'so the webhook left the return in a state that LOOKS approvable and credits nothing, '
            . 'which is the same lost stock behind a politer marker. Check in order: did the webhook '
            . 'write return_status = 2 again (DeliverySyriaOrderController case 3), which makes '
            . '$isNewApproval false at OrderController:1422 and skips restoreReturnedStock() at '
            . ':1438 while still answering "Return request approve successfully" (:1448); or did the '
            . 'restore itself skip the line, which it does for a line already stamped '
            . 'LINE_STOCK_RESTORED or LINE_STOCK_CARRIED (:1519). A figure above '
            . self::RESTOCKED . ' is the opposite defect: the goods credited twice.'
        );
    }

    /**
     * EVIDENCE, NOT AN EXPECTATION -- the second automated door, measured as it is today.
     *
     * `Order::product_return()` refuses at `if ($Order->delivered_status != 1)`
     * (app/Models/Order.php:963), and the webhook left `delivered_status = 3`. So the per-line
     * merchant return cannot credit these goods either.
     *
     * THIS TEST IS GREEN ON PURPOSE AND IT IS A RATCHET IN BOTH DIRECTIONS. It deliberately does
     * NOT assert that this door SHOULD open: if the webhook is repaired to restock, the goods are
     * already back and this refusal becomes the correct answer, so the test stays green. If instead
     * a repair leaves the restock to the merchant, this turns red and names the second door that
     * must then be opened as well. Asserting a desired outcome here would have prescribed one of
     * those two repairs before it was chosen.
     *
     * WHICH IS WHY THE STOCK IS MEASURED AND NOT NAMED. This case asserted
     * `self::OPENING_STOCK` -- 4 -- against the figure AFTER the webhook, and that is a different
     * claim from the one the docblock above makes. Under the repair this file's own plan chose (the
     * webhook restocks) the figure after the webhook is 6, so the assertion would have RED while
     * the docblock promised in writing that it stays green: a fixer making the correct change would
     * have been told they broke an invariant. The claim here is "`product_return()` credits
     * nothing", so the baseline is whatever the shelf holds once the webhook has run, captured
     * below rather than hard-coded.
     */
    public function test_the_per_line_merchant_return_door_is_shut_after_the_webhook(): void
    {
        $this->postReturnWebhook()->assertStatus(200);

        $this->assertSame(
            self::RETURNED,
            (int) $this->orderRow()->delivered_status,
            'premise: the webhook left delivered_status = 3'
        );

        /* The shelf as the webhook leaves it -- 4 today, 6 once the webhook restocks. Either way
           the question below is whether `product_return()` ADDS to it. */
        $before = $this->stock();

        $result = Order::product_return([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame(
            'error',
            $result['status'] ?? null,
            'measured, not desired: Order::product_return() refuses a courier-reported return '
            . 'because the webhook left delivered_status = 3 rather than 1 '
            . '(app/Models/Order.php:963). If this is now green-as-success, re-read the docblock: '
            . 'a repair that relies on the merchant restocking has to open this door too.'
        );

        $this->assertSame(
            $before,
            $this->stock(),
            'and so this door credits nothing: the figure moved between the webhook and this '
            . 'refused per-line return, which means something other than a refusal happened'
        );
    }

    /**
     * THE MERCHANT IS OFFERED THE APPROVE CONTROL -- the views' gate and the webhook's write agree.
     *
     * RENAMED AND INVERTED, from
     * `test_the_order_views_gate_the_return_actions_on_a_state_the_webhook_destroys`. Its old
     * premise was that the webhook DESTROYS the state the views gate on, and that premise is now
     * false -- which is precisely the point, not a reason to delete the case. It was evidence of
     * the defect; it is now the ratchet on the repair, and it is the only thing in the suite that
     * connects the webhook's written value to the merchant's ability to act on it.
     *
     * Both order surfaces gate Approve and Reject on
     * `$order->delivered_status == 3 && $order->return_status == 1`
     * (resources/views/order/action.blade.php:35, resources/views/order/grid.blade.php:230). Before
     * the fix the webhook satisfied the first half and broke the second: the row rendered as
     * "Return" with no action attached and no sign that the stock was missing, which is what made
     * the lost goods UI-unreachable rather than merely uncredited. The repair writes
     * `return_status = 1`, so both halves hold and the control is back in front of the merchant.
     *
     * THE EXPECTED PAIR IS READ OUT OF THE VIEWS, NOT TYPED IN. Two literals that happen to match
     * would pin the agreement only until somebody edited one side; parsing the gate means a change
     * to either the view's constants or the webhook's write shows up here as a DISAGREEMENT, which
     * is the property worth ratcheting. Read as source deliberately, and the limit is stated rather
     * than implied: rendering these partials needs the whole order-grid fixture, and what is
     * load-bearing is the constants the views compare against.
     */
    public function test_the_order_views_can_offer_the_return_actions_on_the_state_the_webhook_writes(): void
    {
        $gates = [];

        foreach ([
            'resources/views/order/action.blade.php',
            'resources/views/order/grid.blade.php',
        ] as $view) {
            $source = (string) file_get_contents(base_path($view));

            $this->assertSame(
                1,
                preg_match(
                    '/\$order->delivered_status == (\d+) && \$order->return_status == (\d+)/',
                    $source,
                    $matched
                ),
                $view . ' no longer carries the return-action gate this finding depends on; '
                . 're-read it and correct the finding rather than this test'
            );

            $gates[$view] = [(int) $matched[1], (int) $matched[2]];
        }

        $this->assertSame(
            array_values($gates)[0],
            array_values($gates)[1],
            'the two order surfaces now gate the return actions on DIFFERENT states, so one of them '
            . 'offers the merchant a control the other hides. Measured: '
            . json_encode($gates)
        );

        $this->postReturnWebhook()->assertStatus(200);

        $row = $this->orderRow();

        $this->assertSame(
            array_values($gates)[0],
            [(int) $row->delivered_status, (int) $row->return_status],
            'the state the webhook writes does not satisfy the state both order views gate the '
            . 'Approve and Reject controls on, so the order renders as "Return" with no action '
            . 'attached and the merchant has no UI-reachable way to credit the goods back. The '
            . 'views require ' . json_encode(array_values($gates)[0])
            . ' as [delivered_status, return_status]; the webhook wrote '
            . json_encode([(int) $row->delivered_status, (int) $row->return_status])
            . '. A return_status of 2 here is the specific regression: it is the restock '
            . 'idempotency marker OrderController.php:1422 reads, so it both hides the control and '
            . 'disarms the credit.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE CANCEL LIMB -- the same omission, named in the finding's headline
    |--------------------------------------------------------------------------
    */

    /**
     * A courier-reported CANCELLATION must put the stock back too.
     *
     * MEASURED TODAY: it stays at 4. `case 2` (:200-203) stamps `cancel_date` and nothing else.
     *
     * The fixture is an order at `delivered_status = 0` -- goods allocated, nothing delivered --
     * which is precisely the state `OrderController::status_cancel()` restocks from, and
     * `Order::order_status_change()` refuses to cancel outside of (app/Models/Order.php:702-706).
     * The webhook carries neither the guard nor the restore.
     *
     * The companion evidence that a cancellation SHOULD restock is `OrderCancelRestockTest`, which
     * drives `status_cancel()` end to end; it is cited rather than duplicated here.
     *
     * RED AS OF 2026-11-16, ON A PRODUCTION DEFECT IN THE FIX AND NOT ON ANYTHING HERE. The webhook
     * answers HTTP 500 and writes nothing. DIAGNOSED, with the handler's own log line as the
     * evidence: `old_status` was 0 -- exactly what the new `if ($oldStatus === 0)` guard wants -- and
     * the guard still did not run, because `$oldStatus` is assigned in the method body
     * (DeliverySyriaOrderController.php:159) and read inside the `DB::transaction` CLOSURE (:222)
     * whose `use (...)` list captures only `$order, $newStatus, $request` (:185-189). PHP warns on
     * the undefined variable, Laravel's handler raises it as an `ErrorException`, the outer `catch`
     * logs *"Delivered Syria order status update failed ... Undefined variable $oldStatus"* and
     * answers 500, and the transaction rolls the whole write back. So it is not the guard refusing:
     * the guard never evaluated. Adding `$oldStatus` to that `use` list is the entire fix, and it is
     * a production file.
     *
     * NOTHING HERE WAS WEAKENED TO ACCOMMODATE IT. The assertions are the right ones -- a courier
     * cancel of a PENDING order must answer 200 and credit the goods -- and they will go green on
     * the scope correction without being touched. If this case is green and you are reading this
     * note, the correction has landed and the note can go.
     *
     * AND THE SAME BRANCH PREMISE AS THE RETURN CASE, for the same reason: no `stock_movements` row
     * is recorded for this order either, so `movedOutFor()` answers UNKNOWN and the restore loop
     * must take its restore-and-log arm. Asserted rather than assumed, and asserted BEFORE the
     * webhook, so the case cannot silently become a NOT_MOVED fixture — which a perfect fix would
     * correctly skip, leaving this red for ever and looking like the defect.
     */
    public function test_a_courier_reported_cancellation_gives_the_stock_back(): void
    {
        $product = $this->productIn($this->store, self::OPENING_STOCK);
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'DS-2',
            'delivered_status' => 0,
            'return_status' => self::RETURN_NONE,
            'product_json' => $this->lineJson($product, self::RETURNED_QTY),
        ]);

        $this->assertSame(
            StockMovement::UNKNOWN,
            StockMovement::movedOutFor($this->store->id, $order->id, $product->id, null),
            'premise: this fixture no longer reads UNKNOWN. It records no stock_movements row, so a '
            . 'restore loop must reach the UNKNOWN arm and restore; a NOT_MOVED reading would be '
            . 'correctly SKIPPED by a perfect fix and this case could never go green.'
        );

        $this->postWebhook('DS-2', self::CANCELLED)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $row = DB::table('orders')->where('id', $order->id)->first();

        $this->assertSame(
            self::CANCELLED,
            (int) $row->delivered_status,
            'premise: case 2 of the switch ran'
        );
        $this->assertNotNull($row->cancel_date, 'premise: case 2 stamps cancel_date (:202)');

        $this->assertSame(
            self::RESTOCKED,
            $this->stock($product),
            'the Delivery Syria webhook cancelled an undelivered order and restored no stock, '
            . 'while OrderController::status_cancel() restores every line of the same transition '
            . '(ratcheted by OrderCancelRestockTest). The allocated goods stay allocated to an '
            . 'order that no longer exists.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    private function postReturnWebhook()
    {
        return $this->postWebhook('DS-1', self::RETURNED);
    }

    private function postWebhook(string $productOrderId, int $status, string $secret = self::SECRET)
    {
        return $this->postJson(
            self::WEBHOOK,
            ['product_order_id' => $productOrderId, 'status' => $status],
            ['X-Platform' => 'deliverysyria', 'Authorization' => 'Bearer ' . $secret]
        );
    }

    /**
     * The merchant's own return approval, driven through the real web route and its middleware.
     *
     * `Manage Order` is granted explicitly: `OrderPolicy::allow()` checks `isPlatformAdmin` first
     * and only then `isAbleTo()` + `sameStore()`, and a merchant created by `makeMerchant()` holds
     * no role at all. Laratrust's permission cache is disabled by `SecurityTestCase::setUp()`, so
     * the grant is visible in the same request lifecycle.
     */
    private function approveReturnAsMerchant(Order $order)
    {
        $this->grantPermission($this->merchant, 'Manage Order');

        return $this->actingAs($this->merchant->fresh())->post('/order-return-request', [
            'id' => $order->id,
            'status' => self::RETURN_APPROVED,
        ]);
    }

    private function productIn(Store $store, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'ds-' . uniqid(), 'slug' => 'ds-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** @return string one line, in the shape `Order::linesAreReturnable()` accepts */
    private function lineJson(Product $product, int $qty): string
    {
        return json_encode([[
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => $qty,
        ]]);
    }

    /** The PERSISTED stock. `Product` is `Cachable`; an Eloquent read here can be pre-write. */
    private function stock(?Product $product = null): int
    {
        return (int) DB::table('products')
            ->where('id', ($product ?? $this->product)->id)
            ->value('product_stock');
    }

    /** The PERSISTED order row. Same reasoning as stock(): `Order` is `Cachable` too. */
    private function orderRow(?Order $order = null): object
    {
        return DB::table('orders')->where('id', ($order ?? $this->order)->id)->first();
    }
}

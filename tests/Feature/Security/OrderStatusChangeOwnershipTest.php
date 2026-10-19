<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-ORD-04 / S37-2 family — order_status_change had no permission and no ownership check.
 * =============================================================================================
 *
 * The same defect P1-INV-02 closed on `order_return`, one method away, and worse in two respects:
 * it has **no permission check at all**, and its cancel limb is a **money write**.
 *
 * `OrderController::order_status_change` (`:899-906`) passes `$request->id` and
 * `$request->delivered` straight into `Order::order_status_change()`, which does a bare
 * `Order::find($order_id)` (`app/Models/Order.php:537`) on a model with no global scope and no
 * `scoped_models` entry. The route (`routes/web.php:1179`) sits in the `auth` group and carries
 * nothing else; `index()`'s `isAbleTo('Manage Order')` (`:483`) guards the LISTING, not this.
 *
 * WHY THE CANCEL LIMB IS A MONEY WRITE. It sets `payment_status = 'Unpaid'` and saves
 * (`app/Models/Order.php:546-554`), and `OrderObserver::updated` fires
 * `OrderFinancialService::syncFromOrder` on exactly that column changing
 * (`app/Observers/OrderObserver.php:32-38`). So cancelling walks back the order's wallet record.
 *
 * THE FINANCE HALF CANNOT BE ASSERTED IN THIS SUITE, AND THAT IS STATED RATHER THAN GLOSSED.
 * `syncFromOrder` reads `store_order_financials`, which is one of the GATE F tables created by NO
 * migration (`database/migrations/_gate_f_pending/0004_draft_create_store_order_financials_table.php.draft`).
 * In a test database the table does not exist, so the query throws and the observer's own
 * try/catch swallows it. The money consequence is therefore real in production and invisible
 * here; these tests pin the ORDER columns, which are the trigger for it. P1-MIG-01 (#38) is what
 * would make the finance half testable.
 *
 * `$request->id` ALSO WINS OVER THE ROUTE SEGMENT. `Request::__get` reads `all()` first and falls
 * back to the route parameter only when the key is absent, so `POST /order-status-change/1` with
 * a body `id=<other order>` acts on the body's order. One test drives exactly that, because a
 * reader would reasonably assume the URL identifies the target.
 */
class OrderStatusChangeOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function pendingOrder($store)
    {
        return $this->makeOrder($store, [
            'delivered_status' => 0,
            'payment_status' => 'Paid',
            'product_json' => json_encode([]),
        ]);
    }

    /** @return array{delivered_status:int,payment_status:string} */
    private function snapshot(int $orderId): array
    {
        $row = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($row, 'The order row vanished.');

        return [
            'delivered_status' => (int) $row->delivered_status,
            'payment_status' => (string) $row->payment_status,
        ];
    }

    /**
     * A MERCHANT CANNOT CANCEL ANOTHER STORE'S ORDER.
     *
     * This is the headline case and it is also the money case: a cancel sets
     * `payment_status = 'Unpaid'`, which is what the finance observer reacts to.
     */
    public function test_a_merchant_cannot_cancel_another_stores_order(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $victim = $this->pendingOrder($storeB);
        $before = $this->snapshot($victim->id);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $victim->id, ['delivered' => 'cancel']);

        $this->assertSame($before, $this->snapshot($victim->id),
            'Another store\'s order was cancelled: ' . json_encode($before) . ' -> '
            . json_encode($this->snapshot($victim->id))
            . '. payment_status moving to Unpaid is what drives the finance sync.');
        $this->assertNotSame(500, $response->status(),
            'The refusal must not be a crash. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * A MERCHANT HOLDING NO ORDER PERMISSION CANNOT CHANGE THEIR OWN ORDER EITHER.
     *
     * The route had no permission check at all, only the `auth` group. This is the case that
     * distinguishes authenticated from authorised, and it is deliberately on the caller's OWN
     * order so that ownership cannot be what refuses it.
     */
    public function test_a_merchant_without_manage_order_cannot_change_status(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.

        $order = $this->pendingOrder($storeA);
        $before = $this->snapshot($order->id);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $this->assertSame($before, $this->snapshot($order->id),
            'A merchant holding no order permission cancelled an order. Authenticated is not '
            . 'authorised.');
        $this->assertNotSame(500, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * THE BODY `id` CANNOT REACH AN ORDER THE URL DOES NOT NAME.
     *
     * `Request::__get` reads `all()` before the route parameters, so the posted `id` wins. A
     * reader checking "which order does this route act on?" would answer from the URL and be
     * wrong, so the behaviour is pinned rather than left to be rediscovered.
     */
    public function test_the_posted_id_cannot_override_the_route_segment(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $own = $this->pendingOrder($storeA);
        $victim = $this->pendingOrder($storeB);
        $victimBefore = $this->snapshot($victim->id);

        // The URL names the caller's own order; the body names the victim's.
        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $own->id, [
                'id' => $victim->id,
                'delivered' => 'cancel',
            ]);

        $this->assertSame($victimBefore, $this->snapshot($victim->id),
            'A posted `id` overrode the route segment and reached another store\'s order.');
        $this->assertNotSame(500, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /** THE POSITIVE CONTROL: an own-store status change still works. */
    public function test_a_merchant_can_still_change_their_own_order_status(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $order = $this->pendingOrder($storeA);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $after = $this->snapshot($order->id);

        $this->assertSame(2, $after['delivered_status'],
            'The owner can no longer cancel their own order, so the refusals above prove nothing. '
            . 'Status ' . $response->status() . ', body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame('Unpaid', $after['payment_status'],
            'The cancel did not reach the payment_status write, so the destructive path did not '
            . 'run and the negative tests measure nothing.');
    }

    /**
     * AN ABSENT ORDER ID IS A REFUSAL, NOT A CRASH.
     *
     * `order_status_change` continued past the model call into
     * `getStoreById($d_order->store_id)` (`:961`) on a null order.
     */
    public function test_an_absent_order_id_does_not_crash(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/999999', ['delivered' => 'cancel']);

        $this->assertNotSame(500, $response->status(),
            'An absent order id still crashes. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /** AN ANONYMOUS CALLER IS REFUSED — pinning the route's inherited `auth`. */
    public function test_an_anonymous_caller_cannot_change_status(): void
    {
        [, $storeB] = $this->makeTenant();
        $order = $this->pendingOrder($storeB);
        $before = $this->snapshot($order->id);

        $this->post('/order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $this->assertSame($before, $this->snapshot($order->id),
            'An anonymous caller changed an order\'s status.');
    }

    /**
     * WHICH ORDER IS ACTED ON, WITHIN ONE STORE — the case that isolates the id pinning.
     * ==========================================================================================
     *
     * The cross-store override test above does NOT isolate it: reverting to `$request->id` makes
     * the handler resolve the victim's order, which `authorize('updateStatus')` then refuses on
     * ownership. Measured — that removal check passed with the pinning reverted. So the pinning
     * is not independently security-load-bearing, and claiming it as a third control would be
     * wrong.
     *
     * What it IS load-bearing for is correctness within one tenant: two of the caller's own
     * orders, where `authorize` passes for either, and the URL and the body disagree about which
     * one to act on. Nothing is escalated, but the authorization and the write would be talking
     * about different rows — and a reader checking "which order does this route change?" would
     * answer from the URL and be wrong. This asserts the URL wins.
     */
    public function test_the_route_segment_decides_which_of_the_callers_own_orders_changes(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $named = $this->pendingOrder($storeA);
        $other = $this->pendingOrder($storeA);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $named->id, [
                'id' => $other->id,
                'delivered' => 'cancel',
            ]);

        $this->assertSame(2, $this->snapshot($named->id)['delivered_status'],
            'The order named in the URL was not the one changed, so the handler acted on the '
            . 'posted id instead. Status ' . $response->status());
        $this->assertSame(0, $this->snapshot($other->id)['delivered_status'],
            'The posted `id` decided which order changed, so the authorization and the write '
            . 'can disagree about which row they mean.');
    }
}

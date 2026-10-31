<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\RefundIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * E13 REQUIREMENT E — THE REFUSAL GETS A DESTINATION.
 * ===================================================
 *
 * D-15 condition 6 is *"refusal-and-pending-review, never auto-replacement"*. The refusal half has
 * worked since `RefundIntegrityService` existed: a refund with no gateway driver is stored as
 * `Accept` rather than `Refunded`, so nothing claims money moved that did not. The **pending
 * review** half was a `Log::warning` — and a grep for `pending.review|pendingReview|
 * PENDING_REVIEW|needs_review` across `app/` returned **zero**, so that half of the condition had
 * no subject in code at all.
 *
 * `awaitingSettlement()` is that subject. No migration: a refund awaiting settlement is already
 * fully described by `order_refunds.refund_status`, and inventing a table for a state the data
 * already carries would make the review surface disagree with the refunds themselves the first
 * time one was updated by another path.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE TENANCY CASE IS THE LOAD-BEARING ONE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `OrderRefund` carries no `StoreScope` and is not in `config/tenancy.php`'s `scoped_models`, so a
 * bare query reaches every tenant's refunds. A review surface is exactly where that leaks: it is
 * built to list rows a human then reads. The store predicate is written at the call site and
 * `test_one_merchants_queue_never_shows_another_stores_refunds` is the reason this file exists at
 * all — the rest is bookkeeping by comparison.
 */
class RefundAwaitingSettlementTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `order_refunds.order_id` carries a real FOREIGN KEY to `orders.id` -- measured, by a
     * `SQLSTATE[23000] FOREIGN KEY constraint failed` when the first version of this fixture used
     * a random integer. So every refund needs a real order behind it, which is also the faithful
     * shape: a refund with no order is not a state the product can reach.
     */
    private function orderIn($store): Order
    {
        return Order::create([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([]),
            'product_id' => '0',
            'product_price' => 100, 'coupon_price' => 0, 'delivery_price' => 0,
            'tax_price' => 0, 'final_price' => 100,
            'payment_type' => 'stripe', 'payment_status' => 'paid', 'delivered_status' => 0,
            'order_source' => Order::SOURCE_STOREFRONT,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function refundIn($store, string $status, string $amount = '50.00'): OrderRefund
    {
        return OrderRefund::create([
            'order_id' => $this->orderIn($store)->id,
            'refund_status' => $status,
            'refund_reason' => 'a reason',
            'refund_amount' => $amount,
            'store_id' => $store->id,
        ]);
    }

    private function service(): RefundIntegrityService
    {
        return new RefundIntegrityService();
    }

    /**
     * THE PREMISE, and it is the finding rather than a detail: EVERY online refund is in this
     * queue, because no gateway refund driver exists anywhere in the platform.
     */
    public function test_premise_no_online_refund_can_settle_so_the_queue_is_every_one_of_them(): void
    {
        $service = $this->service();

        $this->assertFalse(
            $service->hasGatewayRefundDriver('stripe'),
            'a refund driver now exists. This queue should be getting SHORTER — re-read D-15 '
            . 'condition 6 and check that resolveRefundStatus() returns STATUS_SETTLED for it.'
        );

        $this->assertSame(
            RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
            $service->resolveRefundStatus('stripe', '50.00', __METHOD__),
            'an online refund resolved to a settled status with no driver to settle it, which '
            . 'would assert that money moved when none did'
        );
    }

    public function test_a_refund_that_could_not_settle_is_surfaced_for_review(): void
    {
        [, $store] = $this->makeTenant();
        $refund = $this->refundIn($store, RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED);

        $queue = $this->service()->awaitingSettlement($store->id);

        $this->assertCount(1, $queue, 'a refund approved but not settled is invisible to review');
        $this->assertSame((int) $refund->id, (int) $queue->first()->id);
    }

    /** A settled refund is finished, so it must not sit in a queue of things to act on. */
    public function test_a_settled_refund_is_not_in_the_queue(): void
    {
        [, $store] = $this->makeTenant();
        $this->refundIn($store, RefundIntegrityService::STATUS_SETTLED);

        $this->assertCount(
            0,
            $this->service()->awaitingSettlement($store->id),
            'a refund that already settled is in the review queue, so the queue fills with '
            . 'completed work and stops being read'
        );
    }

    /**
     * THE ONE THAT MATTERS. `OrderRefund` is not tenant-scoped, so nothing but this predicate
     * stands between one merchant and another's refunds — and this surface exists to be read.
     */
    public function test_one_merchants_queue_never_shows_another_stores_refunds(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->refundIn($storeA, RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED);
        $theirs = $this->refundIn($storeB, RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED, '999.00');

        $queue = $this->service()->awaitingSettlement($storeA->id);

        $this->assertCount(1, $queue, 'the queue is not store-scoped');
        $this->assertSame((int) $mine->id, (int) $queue->first()->id);
        $this->assertNotContains(
            (int) $theirs->id,
            $queue->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'one merchant\'s review queue listed ANOTHER store\'s refund. `OrderRefund` carries no '
            . 'StoreScope and is not in config/tenancy.php\'s scoped_models, so the predicate at '
            . 'the call site is the only control — and a review surface is built to be read by a '
            . 'human, which makes this a cross-tenant disclosure of money.'
        );
    }

    /**
     * NO STORE, NO ANSWER — and emphatically not "every store".
     *
     * THIS CASE DOES NOT DISTINGUISH THE GUARD, and that is recorded rather than left to be
     * discovered: removing the early return leaves these five green, because `(int) null`,
     * `(int) ''` and `(int) 'not-a-store'` are all `0`, so the store predicate becomes
     * `where store_id = 0` and matches nothing on its own. The guard is kept for intent and for
     * the day the predicate changes shape; the case is kept because the BEHAVIOUR it asserts is
     * the one that matters, however it is currently achieved.
     *
     * @dataProvider noStore
     */
    public function test_an_unusable_store_returns_nothing_rather_than_everything($storeId): void
    {
        [, $store] = $this->makeTenant();
        $this->refundIn($store, RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED);

        $this->assertCount(
            0,
            $this->service()->awaitingSettlement($storeId),
            'an unusable store id returned refunds. Returning "all stores" is never what a caller '
            . 'meant and is the cross-tenant disclosure this method exists to avoid.'
        );
    }

    public static function noStore(): array
    {
        return [
            'null' => [null],
            'zero' => [0],
            'negative' => [-1],
            'non-numeric' => ['not-a-store'],
            'empty string' => [''],
        ];
    }
}

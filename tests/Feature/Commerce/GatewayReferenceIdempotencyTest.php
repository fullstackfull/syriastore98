<?php

namespace Tests\Feature\Commerce;

use App\Services\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F216 / D-29.3 — ONE CAPTURE IS ONE ROW, ACROSS THE RETURN, THE WEBHOOK AND THE RETRY.
 * =================================================================================================
 *
 * `orders` carries no gateway reference of any kind — measured: not `payment_id`, not
 * `transaction_id`, not `txn_id`; the only `txn_id` in the schema is on `plan_orders`, for plan
 * purchases. And `OrderCompleteBinding` is session-based, so it answers *"did this browser place
 * this order"* and cannot reconcile someone else's abandoned checkout. So there was nothing to ask
 * when the owner's decision requires *"evidence that prevents a duplicate order and a duplicate
 * capture."*
 *
 * These cases drive `GatewayReference` against the real table. **The concurrency half is NOT here
 * and cannot be:** `UNIQUE` behaviour under two simultaneous inserts is an engine property, and the
 * suite runs on SQLite `:memory:` with one connection. It is measured on MariaDB with two real
 * connections in `tests/Support/Security/bin/verify-gateway-reference-idempotency-on-mysql.php`,
 * and the owner's condition — *"real MariaDB tests including concurrency and failure before and
 * after commit"* — is satisfied there rather than claimed here.
 */
final class GatewayReferenceIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_the_table_exists_so_these_cases_are_not_vacuous(): void
    {
        $this->assertTrue(Schema::hasTable(GatewayReference::TABLE),
            'the migration has not run in this suite, so every case below would take the '
            . '"table absent" branch and pass while asserting nothing about idempotency');
    }

    /** A first capture is recorded. */
    public function test_a_first_capture_is_claimed(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertTrue(GatewayReference::claim($store->id, 'khalti', 'TXN-1', '100.00'));
        $this->assertSame(1, DB::table(GatewayReference::TABLE)->count());
    }

    /**
     * THE REPLAY — the gateway return, the webhook and the retry all arrive as the same pair.
     *
     * The second call must be refused by the DATABASE rather than by a read-then-write, which is
     * what makes it safe under concurrency.
     */
    public function test_the_same_reference_is_refused_however_many_times_it_arrives(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertTrue(GatewayReference::claim($store->id, 'khalti', 'TXN-2'));

        foreach (range(1, 3) as $_) {
            $this->assertFalse(GatewayReference::claim($store->id, 'khalti', 'TXN-2'),
                'a replayed capture was recorded again, so the reference does not prevent a '
                . 'duplicate order (F216 / D-29.3)');
        }

        $this->assertSame(1, DB::table(GatewayReference::TABLE)->count());
    }

    /**
     * TENANT ISOLATION: two stores may legitimately see the same gateway reference.
     *
     * Gateways number their transactions per merchant account, so two Store Syria tenants with
     * separate gateway accounts can both receive `TXN-1`. If the unique key omitted `store_id`,
     * the second store's genuine capture would be refused as a replay of the first store's.
     */
    public function test_two_stores_may_hold_the_same_gateway_reference(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->assertTrue(GatewayReference::claim($storeA->id, 'khalti', 'SHARED-REF'));
        $this->assertTrue(GatewayReference::claim($storeB->id, 'khalti', 'SHARED-REF'),
            'a second store was refused its own capture because another store had seen the same '
            . 'gateway reference. The unique key must include store_id.');
    }

    /** Different gateways are different captures even on the same reference string. */
    public function test_the_same_reference_on_a_different_gateway_is_a_different_capture(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertTrue(GatewayReference::claim($store->id, 'khalti', 'REF-X'));
        $this->assertTrue(GatewayReference::claim($store->id, 'payu', 'REF-X'));
    }

    /**
     * THE OWNER'S SENTENCE AS A TEST: *"another order in the same store is not evidence that this
     * operation succeeded."*
     *
     * A store with other recorded, bound captures must not make an unrelated reference look
     * reconciled.
     */
    public function test_another_bound_capture_in_the_same_store_is_not_evidence_for_this_one(): void
    {
        [, $store] = $this->makeTenant();

        GatewayReference::claim($store->id, 'khalti', 'OTHER-REF');
        GatewayReference::bind($store->id, 'khalti', 'OTHER-REF', 4242);

        GatewayReference::claim($store->id, 'khalti', 'MINE-REF');

        $this->assertNull(GatewayReference::orderFor($store->id, 'khalti', 'MINE-REF'),
            'an unrelated bound capture in the same store made this reference look reconciled');
        $this->assertSame(4242, (int) GatewayReference::orderFor($store->id, 'khalti', 'OTHER-REF'));
    }

    /** Binding is idempotent too: a re-delivered webhook must not move the binding. */
    public function test_a_binding_is_not_moved_by_a_second_delivery(): void
    {
        [, $store] = $this->makeTenant();

        GatewayReference::claim($store->id, 'khalti', 'REF-BIND');

        $this->assertTrue(GatewayReference::bind($store->id, 'khalti', 'REF-BIND', 11));
        $this->assertFalse(GatewayReference::bind($store->id, 'khalti', 'REF-BIND', 22),
            'a second bind moved the capture to a different order');

        $this->assertSame(11, (int) GatewayReference::orderFor($store->id, 'khalti', 'REF-BIND'));
    }

    /** A bind with no prior claim records nothing — an order for an unrecorded capture is a defect to see. */
    public function test_a_bind_without_a_claim_creates_nothing(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertFalse(GatewayReference::bind($store->id, 'khalti', 'NEVER-CLAIMED', 9));
        $this->assertSame(0, DB::table(GatewayReference::TABLE)->count());
    }

    /** The review queue: captures with no order, excluding the ones the gateway rejected. */
    public function test_the_review_queue_lists_unbound_captures_and_omits_rejected_ones(): void
    {
        [, $store] = $this->makeTenant();

        GatewayReference::claim($store->id, 'khalti', 'R-UNKNOWN', '10', GatewayReference::UNKNOWN);
        GatewayReference::claim($store->id, 'khalti', 'R-CONFIRMED', '20', GatewayReference::CONFIRMED);
        GatewayReference::claim($store->id, 'khalti', 'R-REJECTED', '30', GatewayReference::REJECTED);
        GatewayReference::claim($store->id, 'khalti', 'R-BOUND', '40', GatewayReference::CONFIRMED);
        GatewayReference::bind($store->id, 'khalti', 'R-BOUND', 7);

        $queue = GatewayReference::unreconciled($store->id)->pluck('reference')->all();

        sort($queue);
        $this->assertSame(['R-CONFIRMED', 'R-UNKNOWN'], $queue,
            'the review queue must list captures with no order, and must omit both the bound one '
            . 'and the one the gateway REJECTED — there is nothing to settle on a refused payment '
            . 'and a queue that lists them is a queue nobody reads');
    }

    /** Another store's queue is not this store's. */
    public function test_the_review_queue_is_per_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        GatewayReference::claim($storeA->id, 'khalti', 'A-REF');
        GatewayReference::claim($storeB->id, 'khalti', 'B-REF');

        $this->assertSame(['A-REF'], GatewayReference::unreconciled($storeA->id)->pluck('reference')->all());
    }

    /**
     * NOTHING HERE TOUCHES PAYMENT STATUS, STOCK OR THE REPLAY CLAIM — D-29.4's refusal, asserted
     * on the source rather than hoped for.
     *
     * The decision is that a merchant's statement alone is not payment proof, and its consequences
     * are a list of things this service must NOT do. A source assertion is the right instrument:
     * the absence of a behaviour cannot be driven.
     */
    public function test_the_service_sets_no_paid_state_moves_no_stock_and_releases_no_claim(): void
    {
        $source = file_get_contents(base_path('app/Services/GatewayReference.php'));

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        foreach ([
            'payment_status' => 'it sets an order\'s payment status, which D-29.3 forbids without proof of capture',
            'releaseGatewayReturnClaim' => 'it releases the replay claim, which D-29.4 forbids',
            'restoreProductStock' => 'it moves stock, which D-29.4 forbids on a guess',
            'restoreVariantStock' => 'it moves stock, which D-29.4 forbids on a guess',
            'decrementStock' => 'it moves stock, which D-29.4 forbids on a guess',
        ] as $needle => $why) {
            $this->assertStringNotContainsString($needle, $out, "GatewayReference: {$why}");
        }
    }
}

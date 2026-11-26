<?php

namespace Tests\Unit\Commerce;

use App\Support\Orders\OrderStateDimensions;
use App\Support\Orders\FulfilmentStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * `ORD-003` -- SEPARATE ORDER, PAYMENT, FULFILMENT AND SHIPPING STATES.
 * ====================================================================
 *
 * The approved decision, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     ORD-003  الفصل بين حالة الطلب والدفع والتجهيز والشحن.
 *              Separate order, payment, fulfilment and shipping status
 *
 * It names FOUR dimensions. This file pins what the schema actually answers for each of them, and
 * it exists because the honest answer differs per dimension and one of the four cannot be answered
 * at all.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE IS NOT. It is not a fix for `ORD-003` and must not be read as one.
 *
 * The violation lives in `orders.delivered_status`, a single integer column whose nine values span
 * three of the four dimensions plus an order TYPE. Splitting that column changes values the shipped
 * Flutter admin app reads through `App\Support\Api\OrderStatusContract`, which makes it a public-API
 * compatibility change and therefore an OWNER ESCALATION under CLAUDE.md §2. So nothing here
 * changes a column, a written value or a wire field.
 *
 * What it does instead is give the four dimensions ONE place to be read from, and make every place
 * the column cannot answer say so by name rather than returning a plausible wrong answer. A reader
 * that wants "has this shipped?" gets either an answer or a reason, never a guess.
 *
 * -------------------------------------------------------------------------------------------------
 * THE MEASUREMENT, so the next reader does not have to redo it.
 *
 * `delivered_status` carries 0..8 (`OrderStatusContract::KEYS`, `FulfilmentStateMachine::STATE_*`):
 *
 *     0 pending         order lifecycle
 *     1 delivered       shipping
 *     2 cancelled       order lifecycle
 *     3 return          order lifecycle -- AND `orders.return_status` is a dedicated column
 *     4 confirmed       order lifecycle
 *     5 picked_up       shipping  -- the COURIER collecting the parcel, NOT warehouse picking;
 *                       measured in `App\Support\Orders\PackingSlip`'s docblock, which found zero
 *                       picking or packing surfaces in the tree
 *     6 shipped         shipping
 *     7 partially_paid  PAYMENT   -- AND `orders.payment_status` is a dedicated column
 *     8 pre_order       an order TYPE, which is not a state in any of the four dimensions
 *
 * So the column holds order, shipping and payment facts in one place, plus a type; and **not one of
 * the nine values is a fulfilment state**. `ORD-003`'s fourth dimension, التجهيز, has no
 * representation in the schema at all -- which is why {@see OrderStateDimensions} answers it with a
 * named absence instead of inventing one.
 *
 * Two of the nine duplicate a dedicated column, so the two can disagree, and nothing reconciles
 * them: `packages/workdo/PartialPayments/.../PartialPaymentsController::ManageOrderStatus()` assigns
 * `delivered_status = 7` with no regard for what the column already held, so recording a partial
 * payment ERASES the shipping fact. That is not a column carrying two meanings; it is one
 * dimension's writer destroying another's.
 */
class Ord003StateDimensionsTest extends TestCase
{
    /**
     * The four dimensions `ORD-003` names, and only those four.
     *
     * Pinned as a set because the decision is about the SEPARATION: a fifth dimension appearing
     * here, or one of these four disappearing, means somebody has reinterpreted the decision and
     * should say so in the ledger rather than in a constant.
     */
    public function test_the_projection_offers_exactly_the_four_dimensions_the_decision_names(): void
    {
        $this->assertSame(
            ['order', 'payment', 'fulfilment', 'shipping'],
            OrderStateDimensions::DIMENSIONS,
            'ORD-003 names order, payment, fulfilment and shipping. Changing this set is a scope change.'
        );
    }

    /**
     * EVERY value of the column is classified, and the classification is the measured one.
     *
     * Not a subset and not a sample: the test iterates the vocabulary the two existing authorities
     * declare (`FulfilmentStateMachine::STATE_*` 0..8) so a tenth value added to the column without
     * a dimension fails here instead of being silently projected as one of the nine.
     */
    public function test_every_status_value_is_classified_into_the_dimension_it_really_belongs_to(): void
    {
        $expected = [
            FulfilmentStateMachine::PENDING             => 'order',
            FulfilmentStateMachine::STATE_DELIVERED     => 'shipping',
            FulfilmentStateMachine::STATE_CANCELLED     => 'order',
            FulfilmentStateMachine::STATE_RETURN        => 'order',
            FulfilmentStateMachine::STATE_CONFIRMED     => 'order',
            FulfilmentStateMachine::STATE_PICKED_UP     => 'shipping',
            FulfilmentStateMachine::STATE_SHIPPED       => 'shipping',
            FulfilmentStateMachine::STATE_PARTIALLY_PAID => 'payment',
            // An order TYPE. Deliberately NOT a dimension: `null` is the honest answer and a
            // reader must handle it rather than be handed "order" and believe it.
            FulfilmentStateMachine::STATE_PRE_ORDER     => null,
        ];

        $actual = [];
        foreach (array_keys($expected) as $value) {
            $actual[$value] = OrderStateDimensions::dimensionOf($value);
        }

        $this->assertSame($expected, $actual);

        $this->assertSame(
            $expected,
            OrderStateDimensions::DIMENSION_OF_STATUS,
            'The published table and the resolver must not be able to drift apart.'
        );
    }

    /**
     * THE FINDING, ENCODED: no value of the column is a fulfilment state.
     *
     * This is the assertion that makes the file worth keeping. If somebody adds a genuine
     * preparation state to the column -- or splits the column and gives fulfilment its own --
     * this test fails, and the `ORD-003` disposition in the traceability matrix has to be
     * revisited rather than left reading the way it reads today.
     */
    public function test_no_value_of_the_column_carries_a_fulfilment_state(): void
    {
        $this->assertNotContains(
            'fulfilment',
            OrderStateDimensions::DIMENSION_OF_STATUS,
            'ORD-003 names four dimensions and the column represents three of them. '
            . 'If that has changed, ORD-003 needs a new disposition, not a new constant.'
        );
    }

    /**
     * The two values that duplicate a dedicated column are named as such, because the duplication
     * is what makes a contradiction possible.
     */
    public function test_the_values_that_duplicate_a_dedicated_column_are_declared(): void
    {
        $this->assertSame(
            [
                FulfilmentStateMachine::STATE_RETURN         => 'return_status',
                FulfilmentStateMachine::STATE_PARTIALLY_PAID => 'payment_status',
            ],
            OrderStateDimensions::DUPLICATES_COLUMN
        );
    }

    /**
     * A knowable negative is NOT the same answer as an unanswerable question, and conflating the
     * two is how a projection becomes a guess.
     *
     * A pending order has genuinely not shipped -- that is a fact. An order stamped
     * `partially_paid` has had its shipping fact overwritten by a payment event, so the same
     * question has no answer at all. Both would be "not shipped" to a naive reader.
     */
    public function test_a_knowable_negative_is_distinguished_from_an_unanswerable_question(): void
    {
        $pending = OrderStateDimensions::forStatus(FulfilmentStateMachine::PENDING);
        $this->assertSame(OrderStateDimensions::NOT_REACHED, $pending['shipping']);
        $this->assertNull($pending['shipping_reason']);

        $partiallyPaid = OrderStateDimensions::forStatus(FulfilmentStateMachine::STATE_PARTIALLY_PAID);
        $this->assertSame(OrderStateDimensions::UNKNOWN, $partiallyPaid['shipping']);
        $this->assertSame(
            OrderStateDimensions::REASON_OVERWRITTEN_BY_PAYMENT_EVENT,
            $partiallyPaid['shipping_reason'],
            'PartialPayments assigns 7 over whatever the column held, so the shipping fact is gone.'
        );

        $this->assertNotSame($pending['shipping'], $partiallyPaid['shipping']);
    }

    /**
     * The fulfilment dimension answers with a named absence for EVERY input, including the inputs
     * that look like fulfilment.
     */
    public function test_the_fulfilment_dimension_is_an_absence_and_says_why(): void
    {
        foreach ([null, '', '-', 0, 1, 2, 3, 4, 5, 6, 7, 8, 99] as $raw) {
            $projection = OrderStateDimensions::forStatus($raw);

            $this->assertSame(
                OrderStateDimensions::UNKNOWN,
                $projection['fulfilment'],
                'raw=' . var_export($raw, true)
            );
            $this->assertSame(
                OrderStateDimensions::REASON_NO_FULFILMENT_STATE_EXISTS,
                $projection['fulfilment_reason'],
                'raw=' . var_export($raw, true)
            );
        }
    }

    /**
     * A value outside the vocabulary is not projected as anything.
     *
     * `orders.delivered_status` really does hold values outside 0..8: `FulfilmentStateMachine`'s
     * docblock records the literal `'-'` written by one shipped handler, and `OrderDataTable`
     * branches on `null` and `''`. A projection that mapped those onto `pending` would be
     * inventing an order state for a row whose state was never recorded.
     *
     * `null` IS NOT ONE OF THEM, and that is measured rather than chosen. `FulfilmentStateMachine`
     * answers `PENDING` for `null` because every existing comparison in the codebase already does
     * -- `$status == 0` in `getDeliveredStatusStringAttribute`, and `null != 0` being false in the
     * writer's cancel rule. `''` does NOT read as Pending, for the reason that docblock records:
     * under PHP 8 `'' == 0` is false, so the writer has always treated an empty column as neither
     * Pending nor anything else. This projection reuses `stateOf()` rather than normalising a
     * second time, so there is ONE vocabulary and these two cases cannot drift apart.
     */
    public function test_a_value_outside_the_vocabulary_is_refused_rather_than_mapped(): void
    {
        $this->assertSame(
            'pending',
            OrderStateDimensions::forStatus(null)['order'],
            'null reads as Pending throughout this codebase; a projection that refused it would '
            . 'be a second vocabulary.'
        );

        foreach (['', '-', 'x', 9, 99, -1] as $raw) {
            $projection = OrderStateDimensions::forStatus($raw);

            $this->assertSame(
                OrderStateDimensions::UNKNOWN,
                $projection['order'],
                'raw=' . var_export($raw, true)
            );
            $this->assertSame(
                OrderStateDimensions::REASON_VALUE_OUTSIDE_VOCABULARY,
                $projection['order_reason'],
                'raw=' . var_export($raw, true)
            );
        }

        $this->assertNull(OrderStateDimensions::dimensionOf('-'));
        $this->assertNull(OrderStateDimensions::dimensionOf(9));
    }

    /**
     * `pre_order` is a TYPE, so every dimension says so by name instead of one of them claiming it.
     */
    public function test_the_order_type_value_blanks_every_dimension_with_its_own_reason(): void
    {
        $projection = OrderStateDimensions::forStatus(FulfilmentStateMachine::STATE_PRE_ORDER);

        $this->assertSame(OrderStateDimensions::UNKNOWN, $projection['order']);
        $this->assertSame(
            OrderStateDimensions::REASON_COLUMN_HOLDS_ORDER_TYPE,
            $projection['order_reason']
        );
        $this->assertSame(OrderStateDimensions::UNKNOWN, $projection['shipping']);
        $this->assertSame(
            OrderStateDimensions::REASON_COLUMN_HOLDS_ORDER_TYPE,
            $projection['shipping_reason']
        );
    }

    /**
     * THE CONTRADICTION IS REPORTED, NOT RESOLVED.
     *
     * `delivered_status = 7` asserts "partially paid" while `orders.payment_status` is the dedicated
     * payment column. When the latter says the order is paid in full, the two disagree and there is
     * no rule in the repository that says which wins -- so the projection reports the disagreement
     * and leaves the decision where it belongs.
     *
     * `'paid'` is compared case-insensitively and is NOT a vocabulary this file invents: it is the
     * literal five places in the shipped Flutter client compare against, recorded in
     * `OrderStatusContract`'s docblock with file and line. The column itself is free text and holds
     * at least fourteen spellings, which is a separate defect and not this one.
     */
    public function test_a_payment_column_that_contradicts_the_status_column_is_reported(): void
    {
        foreach (['paid', 'Paid', 'PAID'] as $spelling) {
            $projection = OrderStateDimensions::forStatus(
                FulfilmentStateMachine::STATE_PARTIALLY_PAID,
                $spelling
            );

            $this->assertTrue(
                $projection['payment_contradicts_status'],
                'spelling=' . $spelling
            );
        }

        $agreeing = OrderStateDimensions::forStatus(
            FulfilmentStateMachine::STATE_PARTIALLY_PAID,
            'Unpaid'
        );
        $this->assertFalse($agreeing['payment_contradicts_status']);

        // No contradiction is possible when the status column is not making a payment claim.
        $shipped = OrderStateDimensions::forStatus(FulfilmentStateMachine::STATE_SHIPPED, 'Paid');
        $this->assertFalse($shipped['payment_contradicts_status']);
    }

    /**
     * The payment dimension reports the dedicated column VERBATIM.
     *
     * Normalising it here would be inventing a payment vocabulary, which is an owner decision: the
     * column holds `Paid`, `paid`, `Approved`, `Succeeded`, `success`, `On Hold`, `Rejected` and
     * more, and choosing a canonical set is a business-rule change, not a projection.
     */
    public function test_the_payment_dimension_passes_the_dedicated_column_through_unchanged(): void
    {
        foreach (['Paid', 'paid', 'On Hold', 'Pending payment', 'Succeeded', '', null] as $raw) {
            $projection = OrderStateDimensions::forStatus(FulfilmentStateMachine::STATE_SHIPPED, $raw);

            $this->assertSame(
                $raw,
                $projection['payment'],
                'The projection must not normalise a free-text column. raw=' . var_export($raw, true)
            );
        }
    }

    /**
     * Every projection answers on all four dimensions, with a reason key beside each.
     *
     * Shape is asserted as a whole so a dimension cannot be quietly dropped from the return value
     * while the four-name constant above still lists it.
     */
    public function test_the_projection_shape_is_complete_for_every_input(): void
    {
        $expectedKeys = [
            'order', 'order_reason',
            'payment', 'payment_reason',
            'fulfilment', 'fulfilment_reason',
            'shipping', 'shipping_reason',
            'payment_contradicts_status',
            'status_value_dimension',
        ];

        foreach ([null, '', '-', 0, 3, 7, 8, 99] as $raw) {
            $projection = OrderStateDimensions::forStatus($raw);

            $this->assertSame(
                $expectedKeys,
                array_keys($projection),
                'raw=' . var_export($raw, true)
            );
        }
    }
}

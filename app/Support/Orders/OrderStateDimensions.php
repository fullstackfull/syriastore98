<?php

namespace App\Support\Orders;

/**
 * `ORD-003` -- THE FOUR ORDER STATE DIMENSIONS, READ SEPARATELY FROM A SCHEMA THAT CONFLATES THEM.
 * ================================================================================================
 *
 * The approved decision, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     ORD-003  الفصل بين حالة الطلب والدفع والتجهيز والشحن.
 *              Separate order, payment, fulfilment and shipping status
 *
 * -------------------------------------------------------------------------------------------------
 * THIS CLASS IS NOT THE FIX, AND MUST NOT BE READ AS ONE.
 *
 * `orders.delivered_status` is ONE integer column carrying nine values that belong to three
 * different dimensions plus an order TYPE, and two of the nine duplicate a dedicated column of
 * their own. That is the `ORD-003` violation and the fix is to split the column.
 *
 * **Splitting it is an OWNER ESCALATION, not an engineering choice.** The integers are a public API:
 * {@see \App\Support\Api\OrderStatusContract} turns them into `order_status_key` for the shipped
 * Flutter admin app, `order_status_text` is pinned byte for byte by
 * `tests/Feature/ApiContract/OrderStatusTextTest`, and the client branches on the result. CLAUDE.md
 * §2 puts a public-API compatibility change in the escalate set. So **nothing here writes a column,
 * changes a stored value, or adds a wire field.**
 *
 * What it does is give the four dimensions ONE place to be read from, and make every place the
 * schema cannot answer say so BY NAME. A caller asking "has this shipped?" gets an answer, a
 * knowable negative, or a reason -- never a plausible guess. That is the honest half of `ORD-003`
 * that can be delivered without the owner's decision, and it is what makes the cost of the
 * conflation visible at every call site instead of once in a document.
 *
 * -------------------------------------------------------------------------------------------------
 * THE MEASUREMENT THIS ENCODES. Each line was read, not inferred.
 *
 *     0 pending         order lifecycle
 *     1 delivered       shipping
 *     2 cancelled       order lifecycle
 *     3 return          order lifecycle -- `orders.return_status` is ALSO a column
 *     4 confirmed       order lifecycle
 *     5 picked_up       shipping. The COURIER collecting the parcel, NOT warehouse picking --
 *                       measured in {@see PackingSlip}'s docblock, whose whole-word search for
 *                       picking/packing surfaces across `app/`, `resources/views/`,
 *                       `packages/workdo/` and `database/` found ZERO
 *     6 shipped         shipping
 *     7 partially_paid  PAYMENT -- `orders.payment_status` is ALSO a column
 *     8 pre_order       an order TYPE, which is not a state in any of the four dimensions
 *
 * **NOT ONE OF THE NINE IS A FULFILMENT STATE.** `ORD-003`'s fourth dimension, التجهيز, has no
 * representation anywhere in the schema -- not a value, not a column. So {@see self::FULFILMENT}
 * answers {@see self::UNKNOWN} for every input with the reason
 * {@see self::REASON_NO_FULFILMENT_STATE_EXISTS}, which is the true answer. Returning "pending"
 * there would be the projection inventing the dimension the decision asked somebody to build.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY AT MOST ONE DIMENSION ANSWERS AT A TIME, which is the whole point.
 *
 * One column holds one value. When it holds a shipping value the order-lifecycle fact that was
 * there before is gone, and vice versa -- so a projection cannot report both. That is not a
 * limitation of this class; it is the conflation, stated. Three reason codes keep the three
 * mechanisms distinguishable, because they are not the same thing and a reader fixing one should
 * not be misled about the others:
 *
 *   - {@see self::REASON_DISPLACED} -- the column holds a value from another dimension, and the
 *     move was made THROUGH {@see FulfilmentStateMachine}, which governs it and refuses the
 *     transitions that re-open a settled order. The fact is unavailable, but the move was legal.
 *
 *   - {@see self::REASON_OVERWRITTEN_BY_PAYMENT_EVENT} -- value 7, and this one is worse.
 *     `FulfilmentStateMachine::governs()` answers FALSE for the `PartialPayments` vocabulary, so
 *     NO transition rule applies at all:
 *     `packages/workdo/PartialPayments/src/app/Http/Controllers/PartialPaymentsController.php`'s
 *     `ManageOrderStatus()` assigns `delivered_status = 7` and saves, whatever the column held. A
 *     shipped order that takes a partial payment stops being a shipped order in the record. A
 *     payment writer destroys a shipping fact, and nothing reconciles the two afterwards.
 *
 *   - {@see self::REASON_COLUMN_HOLDS_ORDER_TYPE} -- value 8. Not a state in any dimension, so no
 *     dimension may claim it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT DERIVED.
 *
 * `orders.payment_status` is passed through VERBATIM. The column is free text and holds at least
 * fourteen spellings in `app/` alone -- `Paid`, `paid`, `Approved`, `Succeeded`, `succeeded`,
 * `success`, `On Hold`, `Pending payment`, `Rejected`, `Failed`, `Unpaid`, `unpaid`, `Pending`,
 * `pending`. Choosing a canonical set is a business-rule change and therefore the owner's, so this
 * class reports the column and does not normalise it.
 *
 * The ONE comparison it does make is `delivered_status === 7` against a `payment_status` of `paid`,
 * case-insensitively, because those two cannot both be true and the disagreement is the measurable
 * consequence of the duplication. `'paid'` is not a vocabulary invented here: it is the literal the
 * shipped Flutter client compares against in five places, recorded with file and line in
 * {@see \App\Support\Api\OrderStatusContract}'s docblock. The projection REPORTS the contradiction
 * and does not resolve it -- no rule in this repository says which column wins, and inventing one
 * would be the owner's decision made quietly.
 */
final class OrderStateDimensions
{
    /** The four dimensions `ORD-003` names, in the decision's own order. */
    public const ORDER = 'order';
    public const PAYMENT = 'payment';
    public const FULFILMENT = 'fulfilment';
    public const SHIPPING = 'shipping';

    /** @var list<string> */
    public const DIMENSIONS = [self::ORDER, self::PAYMENT, self::FULFILMENT, self::SHIPPING];

    /**
     * The schema cannot answer this dimension for this row. Always accompanied by a reason.
     *
     * DISTINCT FROM {@see self::NOT_REACHED} on purpose, and conflating the two is how a
     * projection becomes a guess: "this order has not shipped" is a fact, while "this order's
     * shipping state was overwritten by a payment event" is the absence of one. A caller that
     * cannot tell them apart will present the second as the first.
     */
    public const UNKNOWN = 'unknown';

    /** A knowable negative: the order has not reached this dimension yet. */
    public const NOT_REACHED = 'not_reached';

    /** The column holds a value belonging to another dimension; the move was governed. */
    public const REASON_DISPLACED = 'displaced_by_another_dimension';

    /** Value 7, written by an UNGOVERNED writer that overwrites whatever was there. */
    public const REASON_OVERWRITTEN_BY_PAYMENT_EVENT = 'overwritten_by_payment_event';

    /** Value 8 is an order TYPE, so no dimension may claim the row. */
    public const REASON_COLUMN_HOLDS_ORDER_TYPE = 'column_holds_order_type';

    /** No value and no column records a fulfilment state. `ORD-003`'s fourth dimension is absent. */
    public const REASON_NO_FULFILMENT_STATE_EXISTS = 'no_fulfilment_state_exists';

    /** The column holds something outside 0..8, so it names no state at all. */
    public const REASON_VALUE_OUTSIDE_VOCABULARY = 'value_outside_vocabulary';

    /** The dedicated payment column is empty, so the payment dimension has nothing to report. */
    public const REASON_PAYMENT_COLUMN_EMPTY = 'payment_column_empty';

    /**
     * WHICH DIMENSION EACH VALUE OF THE COLUMN REALLY BELONGS TO.
     *
     * `null` means the value is not a state in any of the four dimensions -- value 8 is an order
     * type. Published as a constant so the table and {@see self::dimensionOf()} cannot drift
     * apart; the unit test asserts both against each other.
     *
     * @var array<int, string|null>
     */
    public const DIMENSION_OF_STATUS = [
        FulfilmentStateMachine::PENDING              => self::ORDER,
        FulfilmentStateMachine::STATE_DELIVERED      => self::SHIPPING,
        FulfilmentStateMachine::STATE_CANCELLED      => self::ORDER,
        FulfilmentStateMachine::STATE_RETURN         => self::ORDER,
        FulfilmentStateMachine::STATE_CONFIRMED      => self::ORDER,
        FulfilmentStateMachine::STATE_PICKED_UP      => self::SHIPPING,
        FulfilmentStateMachine::STATE_SHIPPED        => self::SHIPPING,
        FulfilmentStateMachine::STATE_PARTIALLY_PAID => self::PAYMENT,
        FulfilmentStateMachine::STATE_PRE_ORDER      => null,
    ];

    /**
     * The two values that duplicate a dedicated column of `orders`, and which column each
     * duplicates.
     *
     * Declared because the duplication is what makes a CONTRADICTION possible: two places record
     * the same fact and nothing reconciles them.
     *
     * @var array<int, string>
     */
    public const DUPLICATES_COLUMN = [
        FulfilmentStateMachine::STATE_RETURN         => 'return_status',
        FulfilmentStateMachine::STATE_PARTIALLY_PAID => 'payment_status',
    ];

    /**
     * The stable key each recognised value carries, matching `OrderStatusContract::KEYS`.
     *
     * @var array<int, string>
     */
    private const KEY_OF_STATUS = [
        FulfilmentStateMachine::PENDING              => 'pending',
        FulfilmentStateMachine::STATE_DELIVERED      => 'delivered',
        FulfilmentStateMachine::STATE_CANCELLED      => 'cancelled',
        FulfilmentStateMachine::STATE_RETURN         => 'return',
        FulfilmentStateMachine::STATE_CONFIRMED      => 'confirmed',
        FulfilmentStateMachine::STATE_PICKED_UP      => 'picked_up',
        FulfilmentStateMachine::STATE_SHIPPED        => 'shipped',
        FulfilmentStateMachine::STATE_PARTIALLY_PAID => 'partially_paid',
        FulfilmentStateMachine::STATE_PRE_ORDER      => 'pre_order',
    ];

    /**
     * The two states that PROVABLY precede any shipping event, so "not shipped" is a fact for them.
     *
     * Only these two. A CANCELLED or RETURNED order is NOT in this set, and that is deliberate:
     * `FulfilmentStateMachine`'s rules mean a forward-moving order can only reach `cancelled` from
     * `pending` and `return` from `delivered`, but those rules are new (GAP-135) and the rows that
     * predate them were written by a writer that asked nothing. Reading "cancelled, therefore never
     * shipped" off a recently-introduced rule would be an inference about historical data dressed
     * as a fact.
     *
     * @var list<int>
     */
    private const PRECEDES_SHIPPING = [
        FulfilmentStateMachine::PENDING,
        FulfilmentStateMachine::STATE_CONFIRMED,
    ];

    /**
     * The `payment_status` spelling that cannot be true at the same time as `delivered_status = 7`.
     *
     * Compared case-insensitively against the trimmed column. ONE literal, because it is the one
     * the shipped Flutter client branches on; this is not an attempt to canonicalise a free-text
     * column.
     */
    private const FULLY_PAID_LITERAL = 'paid';

    /**
     * Which of the four dimensions a raw `delivered_status` value belongs to.
     *
     * @return string|null one of {@see self::DIMENSIONS}, or `null` when the value is an order
     *                     TYPE (8) or names no state at all (anything outside 0..8)
     */
    public static function dimensionOf($rawStatus): ?string
    {
        $state = FulfilmentStateMachine::stateOf($rawStatus);

        if ($state === null) {
            return null;
        }

        return self::DIMENSION_OF_STATUS[$state] ?? null;
    }

    /**
     * THE PROJECTION: all four dimensions for one order's raw columns, each with its reason.
     *
     * Takes the RAW column values rather than an `Order`, so it needs no database and no model
     * cache -- `Order` is `Cachable` and CLAUDE.md records three measured false readings from a
     * stale cached row. {@see self::forOrder()} is the convenience wrapper for a real row.
     *
     * @param  mixed  $rawStatus         `orders.delivered_status`, exactly as stored
     * @param  mixed  $rawPaymentStatus  `orders.payment_status`, exactly as stored
     * @return array<string, mixed>
     */
    public static function forStatus($rawStatus, $rawPaymentStatus = null): array
    {
        $state = FulfilmentStateMachine::stateOf($rawStatus);
        $dimension = $state === null ? null : (self::DIMENSION_OF_STATUS[$state] ?? null);

        [$order, $orderReason] = self::dimension(self::ORDER, $state, $dimension);
        [$shipping, $shippingReason] = self::dimension(self::SHIPPING, $state, $dimension);

        $paymentEmpty = $rawPaymentStatus === null || trim((string) $rawPaymentStatus) === '';

        return [
            self::ORDER => $order,
            'order_reason' => $orderReason,

            // VERBATIM. See the class docblock: normalising a free-text payment column is an
            // owner decision, not a projection.
            self::PAYMENT => $rawPaymentStatus,
            'payment_reason' => $paymentEmpty ? self::REASON_PAYMENT_COLUMN_EMPTY : null,

            // ALWAYS an absence. `ORD-003`'s fourth dimension does not exist in the schema.
            self::FULFILMENT => self::UNKNOWN,
            'fulfilment_reason' => self::REASON_NO_FULFILMENT_STATE_EXISTS,

            self::SHIPPING => $shipping,
            'shipping_reason' => $shippingReason,

            'payment_contradicts_status' => $state === FulfilmentStateMachine::STATE_PARTIALLY_PAID
                && strcasecmp(trim((string) $rawPaymentStatus), self::FULLY_PAID_LITERAL) === 0,

            'status_value_dimension' => $dimension,
        ];
    }

    /**
     * The projection for a real order row.
     *
     * Reads the two raw attributes and nothing else, so it performs no query and triggers no
     * relation load.
     *
     * @param  object  $order  anything carrying `delivered_status` and `payment_status`
     * @return array<string, mixed>
     */
    public static function forOrder(object $order): array
    {
        return self::forStatus(
            $order->delivered_status ?? null,
            $order->payment_status ?? null
        );
    }

    /**
     * One dimension's answer, and the reason when it has none.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function dimension(string $wanted, ?int $state, ?string $actualDimension): array
    {
        if ($state === null) {
            return [self::UNKNOWN, self::REASON_VALUE_OUTSIDE_VOCABULARY];
        }

        if ($state === FulfilmentStateMachine::STATE_PRE_ORDER) {
            return [self::UNKNOWN, self::REASON_COLUMN_HOLDS_ORDER_TYPE];
        }

        // The column answers this dimension: hand back the stable key.
        if ($actualDimension === $wanted) {
            return [self::KEY_OF_STATUS[$state], null];
        }

        // The ungoverned payment writer destroyed whatever this dimension held.
        if ($state === FulfilmentStateMachine::STATE_PARTIALLY_PAID) {
            return [self::UNKNOWN, self::REASON_OVERWRITTEN_BY_PAYMENT_EVENT];
        }

        // A knowable negative, for the two states that provably precede shipping.
        if ($wanted === self::SHIPPING && in_array($state, self::PRECEDES_SHIPPING, true)) {
            return [self::NOT_REACHED, null];
        }

        return [self::UNKNOWN, self::REASON_DISPLACED];
    }
}

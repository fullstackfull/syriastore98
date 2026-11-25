<?php

namespace App\Support\Orders;

/**
 * THE FULFILMENT STATE MACHINE, DEFINED ONCE. GAP-135 / `P04-P4-03`.
 *
 * =================================================================================================
 * WHAT WAS WRONG
 * =================================================================================================
 *
 * `Order::order_status_change()` is the writer five doors share -- the merchant web screen and the
 * order board, the storefront customer's own order (`Api\ApiController::order_status_change`),
 * `OrderReplacement::cancelAndReplace()`, the vendored `LotteryPrizes` order screen, and the
 * `PartialPayments` / `PreOrder` module arms inside the method itself. It carried exactly TWO
 * transition rules, each about one arm:
 *
 *     cancel  refused when `delivered_status != 0`
 *     return  refused when `delivered_status == 0`
 *
 * The other four arms -- `confirmed`, `pickedup`, `shipped`, `delivered` -- asked nothing at all
 * about where the order already was. They assigned the integer and saved. So the two states that
 * have to be terminal were not, and the consequences are money, not cosmetics:
 *
 *   - CANCELLED (2) -> DELIVERED (1).  `Order::product_return()` refuses unless
 *     `delivered_status == 1`, and it REDUCES `final_price`; the storefront refund request is
 *     gated on delivery the same way. So a cancelled order -- one whose merchant entitlement
 *     `OrderEntitlementReversal` has already walked back -- could be marched back into the return
 *     and refund workflow. This is the leg P4-F036 names from the shopper's end.
 *   - RETURN (3) -> DELIVERED (1).  A second pass at the return workflow on the same goods.
 *   - CANCELLED (2) -> RETURN (3).  Straight into the return workflow, skipping delivery
 *     altogether, because the only rule on that arm was "not from Pending".
 *   - DELIVERED (1) -> CONFIRMED / PICKED UP / SHIPPED.  Delivery un-happens while
 *     `delivery_date` stays stamped.
 *
 * =================================================================================================
 * WHAT THIS DELIBERATELY DOES **NOT** DO, AND WHY
 * =================================================================================================
 *
 * It is NOT a full workflow restriction. Backwards moves AMONG the in-flight states -- Shipped
 * back to Confirmed, say -- stay permitted, because a merchant correcting a mis-click is a
 * business-rule question and CLAUDE.md §2 puts a material business-rule change in the
 * escalate-to-owner set. Only the transitions that RE-OPEN A SETTLED ORDER are refused, which is
 * a defect fix. A narrower matrix is a one-line edit to {@see self::PERMITTED} when an owner asks
 * for one.
 *
 * It also does not touch the statuses this machine does not govern. `pre_order` and the
 * `PartialPayments` vocabulary are handled by module arms further down the writer, and
 * {@see self::governs()} answers false for every word outside the six literals the writer's own
 * branch chain matches -- so an unrecognised value still falls through to the writer's "that order
 * status is not recognised" arm exactly as before (F81, `OrderStatusUnknownValueTest`).
 *
 * =================================================================================================
 * THE TWO ESTABLISHED MESSAGES ARE REPRODUCED BYTE FOR BYTE
 * =================================================================================================
 *
 * The cancel and return rules move here so there is ONE definition, not two that can drift -- but
 * they answer with the exact strings the writer has always answered with, both of which are keys
 * in `resources/lang/en.json` and `ar.json`. A new string on those two paths would have gone out
 * untranslated to every Arabic merchant and shopper, and would have broken any client matching on
 * the text.
 *
 * =================================================================================================
 * THE COLUMN DOES NOT ONLY HOLD 0..8, AND THE RULES PRESERVE THAT
 * =================================================================================================
 *
 * `orders.delivered_status` is written as the literal `'-'` by one shipped handler
 * (`Api\ApiController:10471`), and `OrderDataTable:334` already branches on `null` and `''`, so
 * rows outside the vocabulary exist. {@see self::stateOf()} therefore answers `null` for a value
 * that is not one of the nine, and an unrecognised state is NOT GOVERNED for the four progression
 * arms -- it stays as movable as it is today, because making such a row unmovable would be a new
 * defect rather than a fix.
 *
 * The cancel and return rules keep the writer's LOOSE comparisons (`== 0`, `!= 0`) rather than
 * casting, because `(int) '-'` is `0` while `'-' == 0` is false under PHP 8: casting would have
 * silently made a `'-'` order cancellable, which it is not today.
 */
final class FulfilmentStateMachine
{
    /** The six status words `Order::order_status_change()`'s own branch chain matches. */
    public const CANCEL = 'cancel';
    public const CONFIRMED = 'confirmed';
    public const PICKED_UP = 'pickedup';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const RETURNED = 'return';

    /**
     * `delivered_status` integers. Named here rather than imported from `HomeController` so that a
     * model-layer rule does not depend on a controller, and kept identical to it and to
     * `App\Support\Api\OrderStatusContract::allKeys()` -- a unit test pins all three together.
     */
    public const PENDING = 0;
    public const STATE_DELIVERED = 1;
    public const STATE_CANCELLED = 2;
    public const STATE_RETURN = 3;
    public const STATE_CONFIRMED = 4;
    public const STATE_PICKED_UP = 5;
    public const STATE_SHIPPED = 6;
    public const STATE_PARTIALLY_PAID = 7;
    public const STATE_PRE_ORDER = 8;

    /**
     * Settled states. Nothing leaves them through the shared writer.
     *
     * A RETURN is settled for the purposes of THIS writer only: `return_status` still moves 1 -> 2
     * (approved) or 1 -> 3 (rejected) through `OrderController::order_return_request()`, which
     * does not come through here and is not affected.
     */
    public const TERMINAL = [self::STATE_CANCELLED, self::STATE_RETURN];

    /**
     * STATES NOTHING GOES BACK FROM. OWNER DECISION H2, option A, 2026-11-25.
     *
     * Each of these three already PUBLISHES that rule in this file and could not ENFORCE it, which
     * is the gap H2 closes. `TERMINAL`'s docblock says "Nothing leaves them through the shared
     * writer" and `PERMITTED` carries no row for either, so `matrix()[2]` is `[]`. The
     * `PERMITTED[STATE_DELIVERED]` row's comment says "forward into the return workflow, or
     * re-stamped. **Never backwards**".
     *
     * WHY THE RULE NEEDED A HOME AT ALL, measured through both live doors before it existed: the
     * two handlers that assign `orders.delivered_status` themselves take an INTEGER off the wire and
     * map it onto one of the six governed words. Three of the nine values the column holds map to no
     * word -- `0` Pending, `7` Partially Paid, `8` Pre Order -- so `refusalFor()` was asked nothing
     * and answered null, and `PERMITTED[PENDING]` then admits five of the six words. The result was
     * that every refusal was one extra request away from irrelevant:
     *
     *   `2 (CANCELLED) -> 0 -> 1 (DELIVERED)`  answered 200 then 200, reaching the state
     *                                          `Order::product_return()` gates on before it REDUCES
     *                                          `final_price`;
     *   `1 (DELIVERED) -> 0 -> 2 (CANCELLED)`  reached a cancel that RESTORES STOCK for goods the
     *                                          courier had already delivered;
     *   `1 -> 7|8 -> 4|5|6`                    un-did a delivery through an ungoverned waypoint.
     *
     * AND THE SHARED WRITER INHERITS THIS TOO, which "no door was edited" obscures. The two integer
     * doors compose `refusalForLeaving() ?? refusalFor()`, but `Order::refuseIllegalTransition()`
     * calls `refusalFor()` ALONE, from the top of `Order::order_status_change()` -- the writer five
     * surfaces share -- with the status word taken straight off the request. So for a from-state in
     * this set: (a) an unrecognised word now answers `MESSAGE_NOT_PERMITTED` where F81's "That order
     * status is not recognised." used to, and no test sees it because every fixture in
     * `OrderStatusUnknownValueTest` sits at Pending; and (b) the `partial_paid` and `pre_order`
     * module arms below that refusal become unreachable out of DELIVERED, CANCELLED and RETURN --
     * which is probably correct, since it closes `2 -> 7 -> 1` at the writer itself, but no WorkDo
     * module loads in any test run, so it is structurally invisible to the suite and belongs in
     * writing rather than in a later surprise.
     *
     * THE SET IS EXPLICIT AND PER-STATE ON PURPOSE, because the obvious generalisation is wrong and
     * was tried: a draft refused EVERY untranslatable target from EVERY state, which also refused 7
     * and 8 out of a PENDING row -- values `P2-07-12` deliberately widened the admin endpoint to
     * accept, with `ApiOrderFilterStatusCoverageTest` existing for them. "No `PERMITTED` row holds a
     * word targeting Pending" is equally true of 7 and 8, so that argument never singled Pending
     * out. It was withdrawn. What distinguishes these three states is not that nothing TARGETS them;
     * it is that each one has a published rule saying nothing LEAVES them except forward.
     *
     * THE DEFECT *CLASS* IS NOT CLOSED, AND THE PARAGRAPHS ABOVE WOULD READ AS IF IT WERE. An
     * independent review of this change measured the rest, so it is written here rather than
     * discovered later. "Every refusal was one extra request away from irrelevant" is equally true
     * of the five IN-FLIGHT states, which are NOT in this set:
     *
     *   `6 (SHIPPED) -> 0 -> 2 (CANCELLED)` LANDS. Hop 1 is permitted -- 6 is not forward-only and
     *   `cancel` is not the word asked about -- and hop 2 is permitted because `PERMITTED[PENDING]`
     *   holds `cancel`. So a cancel is reachable out of every in-flight state, restoring stock for
     *   goods IN TRANSIT and reversing the merchant's wallet entitlement, while the one-step
     *   `6 -> 2` is refused by `MESSAGE_CANCEL_REFUSED`. On the courier webhook that principal is a
     *   THIRD PARTY: whoever holds the merchant's `deliverysyria_secret`.
     *
     * IT IS OUTSIDE H2's SCOPE -- the owner's decision is about a DELIVERED order -- so it is not
     * open by oversight; and widening this set to 4..8 is NOT the fix, because that would refuse 7
     * and 8 out of in-flight rows, a bigger compatibility change than the one H2 authorised. The
     * shape of a fix is to make PENDING a target the two integer doors may not write, which is a
     * contract change on both and therefore an owner question. Recorded as one rather than taken.
     * This constant closes three states; the register names the five it does not.
     *
     * WHAT IS DELIBERATELY STILL PERMITTED, so the owner's "preserve legitimate return and refund
     * workflows" is enforced rather than asserted: `DELIVERED -> returned` and `DELIVERED ->
     * delivered` are both in `PERMITTED[STATE_DELIVERED]` and both still pass, because they are
     * GOVERNED words and this invariant only speaks about untranslatable ones. `return_status`
     * 1 -> 2/3 does not come through this machine at all. Idempotency is untouched: both doors
     * short-circuit an unchanged status BEFORE consulting the machine, so a courier's retry still
     * answers 200. And an unrecognised `delivered_status` -- the literal `'-'` one shipped handler
     * writes -- is still as movable as it was, because `stateOf()` answers null for it and null is
     * in no set.
     *
     * @var list<int>
     */
    public const FORWARD_ONLY = [self::STATE_DELIVERED, self::STATE_CANCELLED, self::STATE_RETURN];

    /**
     * from `delivered_status` => the status words permitted out of it.
     *
     * The three in-flight states (4, 5, 6) and the two non-fulfilment values that share this
     * column (7 Partially Paid, 8 Pre Order) carry the same row on purpose: they are all "in
     * progress", and 7 and 8 are a schema problem (one column holding a fulfilment state, a
     * payment state and an order type) that this machine must not pretend to have solved.
     *
     * @var array<int, list<string>>
     */
    private const PERMITTED = [
        self::PENDING => [
            self::CANCEL, self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED,
        ],
        self::STATE_CONFIRMED => [
            self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED, self::RETURNED,
        ],
        self::STATE_PICKED_UP => [
            self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED, self::RETURNED,
        ],
        self::STATE_SHIPPED => [
            self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED, self::RETURNED,
        ],
        self::STATE_PARTIALLY_PAID => [
            self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED, self::RETURNED,
        ],
        self::STATE_PRE_ORDER => [
            self::CONFIRMED, self::PICKED_UP, self::SHIPPED, self::DELIVERED, self::RETURNED,
        ],

        /* Delivered: forward into the return workflow, or re-stamped. Never backwards. */
        self::STATE_DELIVERED => [self::DELIVERED, self::RETURNED],

        /* Terminal. */
        self::STATE_CANCELLED => [],
        self::STATE_RETURN => [],
    ];

    /** The established refusal for `cancel` from anywhere but Pending. A translated key. */
    public const MESSAGE_CANCEL_REFUSED = 'The order has been delivered. So you cannot cancel the order.';

    /** The established refusal for `return` from Pending. A translated key. */
    public const MESSAGE_RETURN_REFUSED = 'Your order has not been delivered. You can return the order after delivery.';

    /**
     * THE TWO NEW SENTENCES, AND THEY GO THROUGH `__()` WHILE THE TWO ABOVE DO NOT.
     *
     * The established pair are returned as RAW literals, byte for byte, because that is what the
     * writer has always put on the wire -- to the merchant screen, to the order board and to the
     * storefront API that the Flutter app and the web storefront both read. `ar.json` carries the
     * cancel one as a key while nothing on this path translates it, so passing it through `__()`
     * would start answering a shipped path in Arabic where it has always answered in English:
     * an improvement, but a user-visible string change on a client contract, which CLAUDE.md puts
     * in the escalate set. It is left exactly as it was.
     *
     * These two are new, so there is no contract to keep and they are localisable from the start.
     * `__()` answers with the key itself while no catalogue entry exists, so they read as English
     * today and in Arabic the moment somebody adds them to `resources/lang/en.json` and `ar.json`
     * -- which this change does NOT do, because those files are shared and the entries are
     * reported for the owner to word rather than guessed at here.
     */
    public const MESSAGE_SETTLED = 'This order is closed, so its status cannot be changed.';
    public const MESSAGE_NOT_PERMITTED = 'This order cannot be moved to that status.';

    /** Whether this machine has anything to say about a status word. */
    public static function governs($word): bool
    {
        return in_array($word, [
            self::CANCEL, self::CONFIRMED, self::PICKED_UP,
            self::SHIPPED, self::DELIVERED, self::RETURNED,
        ], true);
    }

    /**
     * The state an `orders.delivered_status` value names, or `null` when it names none.
     *
     * `null` reads as Pending, which is what every existing comparison in the codebase already
     * makes it (`$status == 0` in `getDeliveredStatusStringAttribute`, `null != 0` is false in the
     * writer's cancel rule).
     *
     * `''` DOES NOT, and that is measured rather than chosen. Under PHP 8 `'' == 0` is FALSE, so
     * the writer's two rules have always treated an empty `delivered_status` as neither Pending
     * nor any other known state: `cancel` was refused on it (`'' != 0` is true) and `return` was
     * permitted on it. Reading it as Pending here would have refused that return -- a behaviour
     * change dressed as a tidy-up. So `''` answers `null`, unrecognised, exactly like the `'-'`
     * one shipped handler writes (`Api\ApiController:10471`) and anything else outside 0..8.
     */
    public static function stateOf($raw): ?int
    {
        if ($raw === null) {
            return self::PENDING;
        }

        if (is_bool($raw)) {
            return null;
        }

        if (! is_int($raw) && ! (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)
            && ! is_float($raw)) {
            return null;
        }

        $state = (int) $raw;

        return array_key_exists($state, self::PERMITTED) ? $state : null;
    }

    /**
     * Why this transition is refused, or `null` when it is permitted or not governed.
     *
     * The caller passes the order's RAW `delivered_status` and the RAW status word, both exactly as
     * the writer has them, so that nothing is normalised here that the writer's own branch chain
     * does not normalise.
     *
     * THE TWO ESTABLISHED RULES ARE TOTAL, so `cancel` and `return` never reach the matrix: the
     * `cancel` arm answers for every state from its own comparison, and `return` answers for
     * Pending. Their rows in {@see self::PERMITTED} agree with those answers and are documentation
     * of the whole picture rather than a second mechanism -- the unit test drives every pair
     * through `permits()`, so the two cannot disagree without failing.
     *
     * @return string|null the sentence to show, or null when the transition is permitted or the
     *                     word is not governed. The two ESTABLISHED sentences come back as raw
     *                     literals, byte for byte, because that is what the writer has always put
     *                     on the wire; the two NEW ones come back through `__()`. See the message
     *                     constants for why the pair are treated differently.
     */
    public static function refusalFor($rawFrom, $word): ?string
    {
        if (! self::governs($word)) {
            /*
             * A WORD THIS MACHINE DOES NOT GOVERN IS NOT THEREFORE PERMITTED. `null` here used to
             * mean "I was not asked about a word I govern", which every caller read as "go ahead" --
             * see `FORWARD_ONLY` for what that cost. Out of a forward-only state it is a refusal;
             * everywhere else the machine genuinely has nothing to say and still says nothing.
             */
            $from = self::stateOf($rawFrom);

            return $from !== null && in_array($from, self::FORWARD_ONLY, true)
                ? (string) __(self::MESSAGE_NOT_PERMITTED)
                : null;
        }

        /*
         * THE TWO ESTABLISHED RULES FIRST, ON THE WRITER'S OWN LOOSE COMPARISONS. See the class
         * docblock: casting would change what a `'-'` row may do.
         */
        if ($word === self::CANCEL) {
            return $rawFrom != 0 ? self::MESSAGE_CANCEL_REFUSED : null;
        }

        if ($word === self::RETURNED && $rawFrom == 0) {
            return self::MESSAGE_RETURN_REFUSED;
        }

        $from = self::stateOf($rawFrom);

        /*
         * AN UNRECOGNISED STATE IS LEFT AS MOVABLE AS IT IS TODAY. Refusing here would strand
         * every row carrying a value outside the vocabulary, and such rows are written by shipped
         * code -- a new defect in place of a fix.
         */
        if ($from === null) {
            return null;
        }

        if (in_array($word, self::PERMITTED[$from], true)) {
            return null;
        }

        return in_array($from, self::TERMINAL, true)
            ? (string) __(self::MESSAGE_SETTLED)
            : (string) __(self::MESSAGE_NOT_PERMITTED);
    }

    /**
     * WHETHER THIS ROW MAY MOVE AT ALL, asked without reference to any target.
     *
     * TWO QUESTIONS, NOT ONE, AND CONFLATING THEM WAS A MEASURED MISTAKE OF MINE. `refusalFor()`
     * answers "is this TARGET permitted out of this state", and answers null for a word it does not
     * govern — correct for its own question, and a hole in any caller that asks only that one. A
     * door whose wire format is an integer must map the integer onto one of the six words, and when
     * its map has no entry it holds `null`, which `refusalFor()` says nothing about.
     *
     * MEASURED ON THE DELIVERY SYRIA WEBHOOK (2026-11-25, found by independent review): `governs()`
     * lists no pending word, so `status = 0` mapped to nothing and fell through from every state,
     * and `PERMITTED[PENDING]` holds FIVE of the six governed words — so `2 (CANCELLED) -> 0 -> 1`
     * answered 200 then 200 and reached the state `Order::product_return()` gates on before it
     * REDUCES `final_price`. This predicate closes that, because 2 is `TERMINAL` and the FIRST hop
     * is refused.
     *
     * IT IS THE MACHINE'S OWN PUBLISHED INVARIANT AND NOTHING WIDER. `TERMINAL`'s docblock says
     * "Nothing leaves them through the shared writer" and `PERMITTED` carries no row for either
     * terminal state, so `matrix()[2]` is `[]`. Applying it is honouring the single definition.
     *
     * WHAT THIS DELIBERATELY DOES **NOT** DO, because a first draft did and it was an over-reach:
     * it does not refuse every target the caller cannot translate. That draft returned
     * `MESSAGE_NOT_PERMITTED` for **27 of 27** untranslated (from-state x target) pairs — measured
     * by the agent that wired it into `Api\DashboardController` — which included `delivered_status`
     * **7 (Partially Paid)** and **8 (Pre Order)** out of a Pending row. `P2-07-12` deliberately
     * widened that endpoint from `in:0,1,2,3,4,5,6` to all nine values, and
     * `ApiOrderFilterStatusCoverageTest::test_the_status_write_accepts_every_value_the_column_holds`
     * exists for it. The draft's justification was "no `PERMITTED` row holds a word targeting
     * Pending, so writing Pending is outside the authorisation" — but that is equally true of 7 and
     * 8, and `P2-07-12` shows the reading is wrong for them. So the argument never singled Pending
     * out, and it is withdrawn. Which targets a door accepts is a DOOR-level decision; what a row
     * may do is this machine's.
     *
     * AN UNRECOGNISED FROM-STATE STAYS AS MOVABLE AS IT IS TODAY, for `refusalFor()`'s own reason:
     * `stateOf()` answers null for the literal `'-'` that `Api\ApiController` writes, and stranding
     * rows that shipped code creates would be a new defect in place of a fix.
     *
     * COMPOSE THE TWO AT EVERY DOOR THAT ASSIGNS `delivered_status` ITSELF:
     *
     *     FulfilmentStateMachine::refusalForLeaving($order->delivered_status)
     *         ?? FulfilmentStateMachine::refusalFor($order->delivered_status, $word)
     *
     * passing the RAW column value to both, never the `(int)` cast.
     *
     * STILL OPEN AND NOT CLOSABLE HERE: a DELIVERED row is not `TERMINAL`, and no word targets
     * Pending, 7 or 8 — so `1 -> 0 -> 2` and `1 -> 7 -> 4` walk delivery backwards in two requests
     * while the one-step moves are refused. The `PERMITTED[STATE_DELIVERED]` row's own comment says
     * "Never backwards", so the INTENT is stated here and the MECHANISM cannot express it: closing it
     * needs either an ordering over the states, which these integers do not carry (2 is cancel, 3 is
     * return, 4 is confirmed), or a word for each non-fulfilment value. Both are business rules.
     * `OWNER-DECISION-PACKAGE.md` H2 carries the question.
     */
    public static function refusalForLeaving($rawFrom): ?string
    {
        $from = self::stateOf($rawFrom);

        return $from !== null && in_array($from, self::TERMINAL, true)
            ? (string) __(self::MESSAGE_SETTLED)
            : null;
    }

    /** Whether the transition is allowed. The inverse of a refusal. */
    public static function permits($rawFrom, $word): bool
    {
        return self::refusalFor($rawFrom, $word) === null;
    }

    /**
     * The whole matrix, for a test that wants to assert it rather than re-derive it.
     *
     * @return array<int, list<string>>
     */
    public static function matrix(): array
    {
        return self::PERMITTED;
    }
}

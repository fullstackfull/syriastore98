<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliverySyriaCredential;
use App\Support\Orders\FulfilmentStateMachine as SM;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * GAP-135, THE SECOND OF THE TWO DOORS THAT WRITE `delivered_status` WITHOUT ASKING THE STATE
 * MACHINE: the Delivery Syria courier webhook.
 * =================================================================================================
 *
 * `App\Support\Orders\FulfilmentStateMachine` is the one definition of which fulfilment transitions
 * are legal, and `Order::order_status_change()` routes through it (app/Models/Order.php:946). That
 * method's own docblock names the doors it CANNOT reach -- the handlers that assign the integer to
 * `orders.delivered_status` themselves and save:
 *
 *     Api\DashboardController::updateStatus()         the merchant's Flutter app  (a separate fix)
 *     Api\DeliverySyriaOrderController::updateStatus() the courier webhook        -- THIS FILE
 *
 * MEASURED AT HEAD, BY THIS FILE, BEFORE THE FIX: `POST api/delivery-syria/orders/update-status`
 * with `status = 1` against an order sitting at `delivered_status = 2` (CANCELLED) answered
 * **HTTP 200 `{"success":true,"message":"Order status updated successfully."}`**, wrote
 * `delivered_status = 1` and stamped `delivery_date`. The state machine refuses that transition --
 * `SM::permits(2, 'delivered')` is false, and `refusalFor()` answers
 * "This order is closed, so its status cannot be changed." -- because CANCELLED is TERMINAL.
 *
 * WHY THAT IS MONEY AND NOT COSMETICS, in the machine's own words (FulfilmentStateMachine:26-32):
 * `Order::product_return()` refuses unless `delivered_status == 1` and then REDUCES `final_price`,
 * and the storefront refund request is gated on delivery the same way. A cancelled order has
 * already had its merchant entitlement walked back by `OrderEntitlementReversal` and its goods
 * credited by the cancel arm of this very handler. Marching it back to DELIVERED re-opens the
 * return and refund workflow over goods that are already on the shelf and money that is already
 * reversed -- and the caller that can do it is a third-party courier, not the merchant.
 *
 * =================================================================================================
 * WHO THE PRINCIPAL AT THIS DOOR ACTUALLY IS
 * =================================================================================================
 *
 * NOT a Sanctum bearer and NOT `custom.auth`. `routes/api.php:71-74` declares this route with no
 * middleware of its own, and the handler authenticates the caller itself: an `X-Platform:
 * deliverysyria` header plus a per-merchant bearer secret in `users.deliverysyria_secret`, resolved
 * to the set of store ids that secret owns by `DeliverySyriaCredential::storeIdsFor()`. So the real
 * principal is the courier platform holding one merchant's secret, and that -- not a `DeliveryBoy`
 * token -- is what every case below drives. Both premises are asserted rather than assumed
 * (`test_the_webhook_route_reaches_the_controller_under_test`,
 * `test_the_fixture_credential_owns_the_fixture_store`), because a 404 from an unresolved
 * credential and a refusal from the fix are indistinguishable by "the row did not move".
 *
 * =================================================================================================
 * THE REFUSAL IS A 409, AND IT IS NEVER A 401
 * =================================================================================================
 *
 * CLAUDE.md: repo B's `lib/network/network.dart` turns ANY 401 under this API into a forced logout
 * that clears stored preferences, so a new 401 on a path a client calls is a user-visible
 * regression. This handler answers with `response()->json([...], code)` and its neighbours already
 * use 403 (platform / credentials), 422 (validation) and 404 (order not found); 409 Conflict is the
 * code this codebase already uses for "the current state refuses this action"
 * (`ProductController:4222`, `StoreFinanceController:2582`, `OrderRefundController:709`,
 * `PosController:417`). The refused transition is exactly that, and it is deliberately NOT 422:
 * 422 on this endpoint means "your payload was malformed" and carries an `errors` bag.
 *
 * =================================================================================================
 * NOTHING HERE HARDCODES A TRANSITION
 * =================================================================================================
 *
 * Every expectation is computed from `SM::matrix()` and `SM::refusalFor()` at run time, including
 * the headline case's own premise. A test that spelled out "2 -> 1 must be refused" would have to
 * be edited the day an owner narrows the matrix (which the machine's docblock says is a one-line
 * edit), and would then be asserting the old rules against the new machine. The only mapping this
 * file states is integer -> word, which is the exact translation the handler has to perform because
 * the webhook speaks integers and the machine speaks the six words
 * `Order::order_status_change()`'s branch chain matches; it is built from the machine's own
 * constants and pinned in both directions by
 * `test_every_status_integer_the_endpoint_accepts_maps_onto_the_machines_vocabulary`.
 *
 * WHY EVERY ROW IS READ WITH `DB::table()`: `Order` is `Cachable` (CLAUDE.md -- 79 of 95 models
 * are), so an Eloquent read here can serve a pre-write row for the rest of the test under
 * `CACHE_STORE=array`. "The order did not move" is the whole claim of a refusal test, so it is read
 * from the system of record.
 */
final class DeliverySyriaOrderTransitionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const WEBHOOK = '/api/delivery-syria/orders/update-status';

    private const SECRET = 'DS-TRANSITION-FIXTURE-SECRET';

    /**
     * The `status` integer the webhook speaks => the state machine's word for it.
     *
     * Built from the machine's OWN constants on both sides, so a renamed word or a renumbered state
     * cannot leave this file asserting against a vocabulary that no longer exists.
     *
     * `0` (Pending) IS ABSENT ON PURPOSE AND IS NOT AN OVERSIGHT. The machine governs exactly the
     * six words `Order::order_status_change()`'s branch chain matches and there is no `pending`
     * among them -- so a webhook push of `status = 0` is a transition the machine has nothing to say
     * about, and `governs()` must leave it alone rather than refuse it. Pinned by
     * `test_a_status_the_machine_does_not_govern_is_left_alone`.
     *
     * 7 (Partially Paid) and 8 (Pre Order) are absent because the validator refuses them as targets
     * (`in:0,1,2,3,4,5,6`), asserted by
     * `test_the_endpoint_accepts_exactly_the_status_integers_this_file_covers`. They are still
     * exercised as FROM states by the census, because the column really holds them.
     *
     * @var array<int, string>
     */
    private const WORD_FOR_STATUS = [
        SM::STATE_DELIVERED => SM::DELIVERED,
        SM::STATE_CANCELLED => SM::CANCEL,
        SM::STATE_RETURN => SM::RETURNED,
        SM::STATE_CONFIRMED => SM::CONFIRMED,
        SM::STATE_PICKED_UP => SM::PICKED_UP,
        SM::STATE_SHIPPED => SM::SHIPPED,
    ];

    /** The `status` values the handler's validator accepts. Asserted, not assumed. */
    private const ACCEPTED_STATUSES = [0, 1, 2, 3, 4, 5, 6];

    /** The `status` values it refuses. Asserted too, so the census's coverage claim is honest. */
    private const REJECTED_STATUSES = [7, 8];

    private User $merchant;

    private Store $store;

    private int $fixtureCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The secret lives on the row that CREATED the store: `DeliverySyriaCredential` matches
        // `users.deliverysyria_secret` and then joins `stores.created_by IN (owner ids)`.
        $this->merchant = $this->makeMerchant(['deliverysyria_secret' => self::SECRET]);
        $this->store = $this->makeStore($this->merchant);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | 0. PREMISES -- without these, "the row did not move" proves nothing
    |--------------------------------------------------------------------------
    */

    /**
     * THE ROUTE REACHES THE CONTROLLER UNDER TEST, not a closure and not a shadow.
     *
     * This exact URI was once served by a duplicate closure in `routes/web.php`
     * (NEW-DS-ROUTE-SHADOW-01) which answered 422 and never reached the controller, and CLAUDE.md
     * records that a later declaration silently replaces an earlier one for the same method+URI.
     */
    public function test_the_webhook_route_reaches_the_controller_under_test(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/delivery-syria/orders/update-status'
                && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'POST ' . self::WEBHOOK . ' is not registered at all');
        $this->assertSame(
            \App\Http\Controllers\Api\DeliverySyriaOrderController::class . '@updateStatus',
            $route->getActionName(),
            'POST ' . self::WEBHOOK . ' no longer resolves to the controller this file measures'
        );
    }

    /**
     * THE FIXTURE CREDENTIAL RESOLVES TO THE FIXTURE STORE.
     *
     * The handler confines its order lookup to `whereIn('store_id', $storeIds)`. If the secret
     * resolved to no store, every case below would get 404 for an order that plainly exists and
     * every "the order did not move" assertion would be vacuously true.
     */
    public function test_the_fixture_credential_owns_the_fixture_store(): void
    {
        $this->assertContains(
            (int) $this->store->id,
            DeliverySyriaCredential::storeIdsFor(self::SECRET),
            'the courier secret must resolve to the store holding the fixture orders, via the '
            . 'users.deliverysyria_secret -> stores.created_by join in DeliverySyriaCredential'
        );
    }

    /**
     * THE INTEGER->WORD MAP IS COMPLETE IN BOTH DIRECTIONS.
     *
     * A census that reads a map can be satisfied by a map that is missing the interesting half. If
     * a word were absent from `WORD_FOR_STATUS` the census would quietly treat every push to that
     * integer as "not governed, must be accepted" -- which is the defect, asserted as correct. So
     * the map is pinned against the machine's own vocabulary, both ways.
     */
    public function test_this_files_status_word_map_is_the_one_the_handler_actually_uses(): void
    {
        /*
         * THE COPY IS COMPARED TO THE ORIGINAL, which an independent review noted it was not.
         * `WORD_FOR_STATUS` below is a transcription of the handler's own private const, and every
         * case in this file reasons from the transcription. The census does catch a hole in the
         * production map by consequence -- dropping an entry flips at least one pair from 409 to
         * 200 -- but nothing asserted the two were the same map, so the case names above claimed
         * more than they proved. Reflection is the only way to read a private const, and reading it
         * is better than widening the handler's API for a test.
         */
        $production = new \ReflectionClass(\App\Http\Controllers\Api\DeliverySyriaOrderController::class);
        $map = $production->getConstant('MACHINE_WORD_FOR_STATUS');

        $this->assertIsArray($map, 'the handler no longer carries the const this file transcribes');
        $this->assertSame(self::WORD_FOR_STATUS, $map,
            'this file\'s transcription of the handler\'s status-to-word map has drifted from the '
            . 'handler. Every case here reasons from the transcription, so a drift makes the whole '
            . 'file assert things about a map that is not in production.');
    }

    public function test_every_status_integer_the_endpoint_accepts_maps_onto_the_machines_vocabulary(): void
    {
        foreach (self::WORD_FOR_STATUS as $status => $word) {
            $this->assertTrue(
                SM::governs($word),
                "status {$status} is mapped to '{$word}', which the state machine does not govern"
            );
            $this->assertContains(
                (int) $status,
                self::ACCEPTED_STATUSES,
                "status {$status} is mapped but the endpoint does not accept it"
            );
        }

        // Every word the machine governs is reachable through some accepted integer -- otherwise
        // a whole arm of the handler would be outside the census.
        foreach (array_unique(array_merge(...array_values(SM::matrix()))) as $word) {
            $this->assertContains(
                $word,
                self::WORD_FOR_STATUS,
                "the machine governs '{$word}' but no status integer in this file maps to it, so "
                . 'the census never pushes that transition through the webhook'
            );
        }

        // And the one deliberate hole: Pending has no word, which is why `governs()` has to gate.
        $this->assertArrayNotHasKey(
            SM::PENDING,
            self::WORD_FOR_STATUS,
            'the machine governs no `pending` word; mapping one here would invent a rule the single '
            . 'definition does not have'
        );
    }

    /**
     * THE ENDPOINT ACCEPTS EXACTLY THE INTEGERS THE CENSUS COVERS.
     *
     * Driven through the real route, because the coverage claim is about the validator as deployed
     * and not about a line of source. A new accepted value would have to be mapped and censused.
     */
    public function test_the_endpoint_accepts_exactly_the_status_integers_this_file_covers(): void
    {
        foreach (self::ACCEPTED_STATUSES as $status) {
            $order = $this->orderAt(SM::STATE_SHIPPED);

            /*
             * AN EXACT SET, NOT `assertNotSame(422)`, WHICH WAS THE ASSERTION AN INDEPENDENT REVIEW
             * CALLED THE ONE CASE IN THIS FILE THAT WOULD SURVIVE AN ENDPOINT BROKEN FOR EVERYONE:
             * a 500 from a fatal, or a 404 from a route that stopped resolving, is "not 422" and
             * passed. For a well-formed payload on an order the caller owns, this handler answers
             * 200 (written, or the same-status no-op) or 409 (the machine refused); nothing else is
             * a correct answer, so nothing else may pass.
             */
            $this->assertContains(
                $this->push($order, $status)->status(),
                [200, 409],
                "status {$status} is listed as accepted, and a well-formed payload for an order "
                . 'this caller owns must answer 200 or 409 -- any other code means the endpoint is '
                . 'failing rather than deciding'
            );
        }

        foreach (self::REJECTED_STATUSES as $status) {
            $order = $this->orderAt(SM::STATE_SHIPPED);

            $this->push($order, $status)
                ->assertStatus(422)
                ->assertJson(['success' => false, 'message' => 'Validation failed.']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1. THE DEFECT
    |--------------------------------------------------------------------------
    */

    /**
     * A SETTLED ORDER CANNOT BE RE-OPENED BY THE COURIER WEBHOOK.
     *
     * The headline case. CANCELLED is terminal in the machine, so the delivery word is refused out
     * of it; at HEAD this endpoint wrote it anyway. The premise is asked of the machine rather than
     * stated, so narrowing or widening the matrix cannot leave this case asserting the old rules.
     */
    public function test_a_settled_order_cannot_be_re_opened_by_the_courier_webhook(): void
    {
        $from = SM::STATE_CANCELLED;
        $target = SM::STATE_DELIVERED;
        $word = self::WORD_FOR_STATUS[$target];

        $this->assertFalse(
            SM::permits($from, $word),
            'premise: the state machine must refuse this transition, or the case below is not a '
            . 'defect at all. If an owner has widened the matrix, this file follows the machine.'
        );

        $order = $this->orderAt($from);

        $response = $this->push($order, $target);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => SM::refusalFor($from, $word),
        ]);

        $row = $this->row($order);

        $this->assertSame(
            $from,
            (int) $row->delivered_status,
            'the courier webhook moved a CANCELLED order to DELIVERED. `Order::product_return()` '
            . 'refuses unless delivered_status == 1 and then REDUCES final_price, so this walks an '
            . 'order whose entitlement is already reversed and whose goods are already back on the '
            . 'shelf into the return and refund workflow a second time.'
        );
        $this->assertNull(
            $row->delivery_date,
            'the refusal must not have stamped delivery_date on the way to refusing'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2. THE POSITIVE CONTROL -- an endpoint that refuses everybody is not a fix
    |--------------------------------------------------------------------------
    */

    /**
     * A TRANSITION THE MACHINE PERMITS STILL SUCCEEDS, AND STILL WRITES THE NEW STATUS.
     *
     * The pair is the first permitted one in `SM::matrix()`, so this case cannot be quietly
     * narrowed to a transition nobody uses.
     */
    public function test_a_permitted_transition_still_succeeds_and_writes_the_new_status(): void
    {
        [$from, $target, $word] = $this->firstPermittedPair();

        $this->assertTrue(SM::permits($from, $word), 'premise: the machine permits this pair');

        $order = $this->orderAt($from);

        $this->push($order, $target)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Order status updated successfully.',
                'data' => ['previous_status' => $from, 'status' => $target],
            ]);

        $this->assertSame(
            $target,
            (int) $this->row($order)->delivered_status,
            "the webhook refused a transition the state machine permits ({$from} -> '{$word}'), or "
            . 'accepted it and wrote nothing. A door that refuses everything is not a state machine.'
        );
    }

    /**
     * WHERE AN UNGOVERNED STATUS IS LEFT ALONE, AND WHERE IT IS NOT. Two branches, both asserted.
     *
     * THIS DOCBLOCK WAS CORRUPT AND SAID THE OPPOSITE OF THE CODE, which an independent review
     * caught and which matters more here than it would elsewhere: in this repository docblocks ARE
     * the evidence, and a census has been broken four times by prose about the thing it measures.
     * An earlier edit of mine left a truncated sentence running into the replacement comment, so
     * PHP saw ONE docblock whose headline read "A STATUS THE MACHINE DOES NOT GOVERN IS LEFT ALONE,
     * NOT UNIVERSALLY REFUSED" -- true before OWNER DECISION H2 and false after it. Rewritten from
     * the method body rather than patched.
     *
     * WHAT THE METHOD ACTUALLY ESTABLISHES, after H2:
     *
     *   - out of a from-state the machine does NOT recognise, `status = 0` still falls through. The
     *     fixture is 99 and not `'-'` for a measured reason: `(int) '-'` is 0, so a push of 0 takes
     *     the same-status early return and answers 200 before the machine is consulted -- the first
     *     draft measured the early return and reported it as the exemption, and a mutation
     *     stranding every unrecognised row survived it. `'-'` has its own case below for what it
     *     really exercises.
     *   - out of every state in `FulfilmentStateMachine::FORWARD_ONLY`, the same push is REFUSED.
     *     The loop reads that constant rather than `TERMINAL`, so when H2 added DELIVERED to it
     *     this sweep covered the new state without an edit -- and a fourth addition would be
     *     demanded of the door here rather than quietly uncovered.
     *
     * The in-flight states 4..8 are deliberately NOT asserted here, and that is a gap rather than a
     * decision: F1 of the H2 review measured that `6 -> 0 -> 2` still reaches a cancel which
     * restores stock for goods in transit, because `PERMITTED[PENDING]` admits `cancel` and the
     * Pending waypoint is writable from anywhere. H2 was scoped to a DELIVERED order and does not
     * close it. The register carries the finding; the census case further down records the door's
     * present answers for those pairs rather than claiming they are correct.
     */
    public function test_an_ungoverned_status_falls_through_unless_the_from_state_is_forward_only(): void
    {
        $this->assertArrayNotHasKey(SM::PENDING, self::WORD_FOR_STATUS,
            'premise: the endpoint can express no pending word, which is what makes this a door '
            . 'problem rather than a matrix problem');

        /*
         * A ROW OUTSIDE THE VOCABULARY, AND THE VALUE IS 99 FOR A MEASURED REASON. The first draft
         * used `'-'` — the literal one shipped handler really writes — and the case PASSED while a
         * mutation that strands every unrecognised row SURVIVED it. The reason is the early return:
         * `(int) '-'` is 0, so a push of `status = 0` satisfies `$oldStatus === $newStatus` and
         * returns 200 before the machine is consulted at all. The case was measuring the early
         * return and reporting it as the exemption. `99` casts to 99, so the comparison fails, the
         * push reaches the predicate, and the exemption is what answers. `'-'` keeps its own case
         * below, for what it really exercises.
         */
        $unrecognised = $this->orderAt(99);

        $this->assertNull(SM::stateOf($this->row($unrecognised)->delivered_status),
            'premise: the machine recognises no state for this row, so the exemption is what '
            . 'decides the push rather than either rule');
        $this->assertNotSame(SM::PENDING, (int) $this->row($unrecognised)->delivered_status,
            'premise: the stored value must not cast to the pushed one, or the same-status early '
            . 'return answers before the predicate is reached — which is how the first draft of '
            . 'this case passed while proving nothing');

        $this->push($unrecognised, SM::PENDING)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(SM::PENDING, (int) $this->row($unrecognised)->delivered_status,
            'a row carrying a value outside the vocabulary must stay as movable as it is today — '
            . 'stranding rows that shipped code creates would be a new defect in place of a fix');

        /*
         * OUT OF EVERY FORWARD-ONLY STATE THE SAME PUSH IS REFUSED.
         *
         * `FORWARD_ONLY` AND NOT `TERMINAL`, so this sweep follows the constant: H2 added DELIVERED
         * to it and this loop covered the new state with no edit.
         */
        $this->assertNotEmpty(SM::FORWARD_ONLY, 'premise: the set is empty, so this loop runs never');

        foreach (SM::FORWARD_ONLY as $terminal) {
            $order = $this->orderAt($terminal);

            $this->push($order, SM::PENDING)->assertStatus(409);

            $this->assertSame($terminal, (int) $this->row($order)->delivered_status, sprintf(
                'a push of status 0 out of forward-only state %d wrote Pending, and from Pending '
                . '`PERMITTED` admits five of the six governed words -- so this one hop is what '
                . 'makes every other refusal out of that state composable.',
                $terminal
            ));
        }
    }

    /**
     * A DELIVERED ORDER CANNOT BE WALKED BACKWARDS, IN ONE PUSH OR TWO. OWNER DECISION H2, option A.
     *
     * THIS CASE USED TO ASSERT THE OPPOSITE, by the repository's `test_MEASURED_*` convention: it
     * recorded that `1 (DELIVERED) -> 0 (PENDING) -> 2 (CANCELLED)` landed, while `1 -> 2` was
     * refused in one push -- so a cancel RESTORED STOCK for goods the courier had already
     * delivered. The convention is that whoever closes such a residual inverts the assertion in the
     * same change, and that is what this is.
     *
     * THE FIX IS CENTRAL, NOT HERE. `FulfilmentStateMachine::FORWARD_ONLY` now holds DELIVERED and
     * the two terminal states, and `refusalFor()` refuses an untranslatable target out of any of
     * them -- so this door inherited the rule without an edit, and so did
     * `Api\DashboardController::updateStatus`. That is the whole point of putting it in the machine:
     * the previous attempt at this defect was a local check, and a local check disagreeing with the
     * single definition is what made the door wrong in the first place.
     */
    public function test_a_delivered_order_cannot_be_walked_backwards_in_two_pushes(): void
    {
        $this->assertContains(SM::STATE_DELIVERED, SM::FORWARD_ONLY,
            'premise: DELIVERED is no longer forward-only, so this case is about nothing');
        $this->assertNotNull(SM::refusalFor(SM::STATE_DELIVERED, SM::CANCEL),
            'premise: the ONE-step move must still be refused, or the two-step one is not a bypass');

        $order = $this->orderAt(SM::STATE_DELIVERED);

        $viaPending = $this->push($order, SM::PENDING);

        $this->assertSame(409, $viaPending->getStatusCode(),
            'the hop into Pending is the one that must be refused: from there `PERMITTED[PENDING]` '
            . 'admits five of the six governed words, so permitting it makes every other refusal '
            . 'one request from irrelevant');

        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status,
            'the delivered order left its state');

        $thenCancel = $this->push($order, SM::STATE_CANCELLED);

        $this->assertSame(409, $thenCancel->getStatusCode(),
            'the second hop is refused too, by the established cancel rule');
        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status,
            'a delivered order reached CANCELLED, which restores stock for goods already delivered');
    }

    /**
     * AND THE RETURN WORKFLOW IS UNTOUCHED, which H2 required explicitly.
     *
     * `returned` is a GOVERNED word and sits in `PERMITTED[STATE_DELIVERED]`, so the invariant --
     * which speaks only about targets the door cannot translate -- does not reach it. Without this
     * case, widening the rule into "nothing leaves DELIVERED" would pass the case above and close
     * the refund path silently.
     */
    public function test_a_delivered_order_can_still_be_returned(): void
    {
        $this->assertNull(SM::refusalFor(SM::STATE_DELIVERED, SM::RETURNED),
            'premise: the machine must still permit the return, or the door cannot');

        $order = $this->orderAt(SM::STATE_DELIVERED);

        $this->push($order, SM::STATE_RETURN)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(SM::STATE_RETURN, (int) $this->row($order)->delivered_status,
            'the return did not land, so H2 closed the workflow it required be preserved');
    }

    /**
     * A `'-'` ROW PUSHED THE STATUS IT CASTS TO MUST NOT ANSWER 500. Pre-existing, measured here.
     *
     * `Api\ApiController` writes the literal `'-'` into `orders.delivered_status` for a storefront
     * order placed without one. `(int) '-'` is 0, so a courier push of `status = 0` satisfies the
     * same-status comparison and takes the EARLY RETURN — which passed the RAW column value to
     * `statusName(int $status)`. Under PHP 8 that is `TypeError: Argument #1 ($status) must be of
     * type int, string given`, raised outside the handler's `try`, so it surfaced as an uncaught
     * HTTP 500 on a live third-party webhook.
     *
     * An independent review identified it as a latent defect; this case reproduced it by execution,
     * which is why it was fixed rather than filed. It is NOT about the state machine — the machine
     * is never consulted on this path — and that is the point of keeping it separate from the
     * exemption case above, which the same fixture was briefly and wrongly used for.
     */
    public function test_a_dash_row_pushed_the_status_it_casts_to_does_not_fail(): void
    {
        $order = $this->orderAt(0);
        DB::table('orders')->where('id', $order->id)->update(['delivered_status' => '-']);
        (new Order)->flushCache();

        $this->assertSame('-', (string) $this->row($order)->delivered_status,
            'premise: the column really holds the non-numeric literal, which SQLite stores and '
            . 'which is what makes this path reachable in the suite');

        $this->push($order, SM::PENDING)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Order already has this status.']);

        $this->assertSame('-', (string) $this->row($order)->delivered_status,
            'the early return must write nothing');
    }

    /**
     * THE SAME-STATUS EARLY RETURN IS UNCHANGED, INCLUDING OUT OF A TERMINAL STATE.
     *
     * The handler short-circuits with 200 "Order already has this status." when the pushed integer
     * equals the stored one, and Delivery Syria retries deliveries, so that is the endpoint's only
     * idempotency. The machine would refuse `cancel` from CANCELLED -- but a no-op re-opens nothing,
     * and turning a courier's retry into a 409 would be a behaviour change dressed as a fix. So the
     * state-machine check must sit AFTER the early return, and this case is what says so.
     */
    public function test_the_same_status_early_return_is_preserved_even_where_the_machine_refuses(): void
    {
        $from = SM::STATE_CANCELLED;
        $word = self::WORD_FOR_STATUS[$from];

        $this->assertFalse(
            SM::permits($from, $word),
            'premise: the machine refuses this word out of this state, so answering 200 here is a '
            . 'deliberate preservation of the early return and not an accident of ordering'
        );

        $order = $this->orderAt($from);

        $this->push($order, $from)
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Order already has this status.',
                'data' => ['status' => $from],
            ]);

        $this->assertSame($from, (int) $this->row($order)->delivered_status);
    }

    /**
     * THE MACHINE IS ASKED WITH THE RAW COLUMN VALUE, NOT THE CAST ONE.
     *
     * `orders.delivered_status` really holds values outside 0..8: `Api\ApiController:10616` writes
     * the literal `-` into it (`!empty($request->delivered_status) ? $request->delivered_status :
     * '-'`), so a storefront order placed without one carries that. The handler keeps its own
     * `$oldStatus = (int) $order->delivered_status` for the response and the log, and `(int) '-'`
     * is `0` while `'-' == 0` is FALSE under PHP 8 -- so which of the two is handed to the machine
     * decides whether such a row may be cancelled. The shared writer has always refused it
     * (`Order::refuseIllegalTransition()` passes the raw column, app/Models/Order.php:946), and the
     * machine's docblock records the loose comparison as deliberate for exactly this reason.
     *
     * NOT A RULE THIS FILE INVENTS: the expectation is `SM::refusalFor()`'s own answer for the raw
     * value, so if the machine ever decides to normalise such a row, this case follows it. What is
     * pinned is only that THIS DOOR asks the same question the shared writer asks.
     *
     * The value is written through Eloquent, not `DB::table()->update()`: `Order` is `Cachable`, and
     * CLAUDE.md records three measured false readings from raw writes leaving Eloquent -- route-model
     * binding included -- serving the pre-write row for the rest of a test.
     */
    public function test_the_machine_is_asked_with_the_raw_column_value_and_not_the_cast_one(): void
    {
        $order = $this->orderAt(SM::PENDING);

        $order->delivered_status = '-';
        $order->save();

        $this->assertSame(
            '-',
            (string) $this->row($order)->delivered_status,
            'premise: the fixture could not store a non-numeric delivered_status, so the raw/cast '
            . 'distinction this case exists to measure is not present'
        );

        $target = array_search(SM::CANCEL, self::WORD_FOR_STATUS, true);
        $rawRefusal = SM::refusalFor('-', SM::CANCEL);
        $castRefusal = SM::refusalFor((int) '-', SM::CANCEL);

        $this->assertNotSame(
            $rawRefusal,
            $castRefusal,
            'premise: the machine answers the same for the raw value and the cast one, so this '
            . 'case can no longer tell the two implementations apart'
        );
        $this->assertNotNull($rawRefusal, 'premise: the machine refuses cancel out of a `-` row');

        $this->push($order, (int) $target)
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => $rawRefusal]);

        $this->assertSame(
            '-',
            (string) $this->row($order)->delivered_status,
            'the webhook cancelled a `-` row that the shared writer has always refused to cancel, '
            . 'which means it handed the machine `(int) $order->delivered_status` instead of the '
            . 'raw column value'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. THE CENSUS -- every transition the endpoint can be asked for
    |--------------------------------------------------------------------------
    */

    /**
     * THE WEBHOOK AND THE STATE MACHINE AGREE ON EVERY TRANSITION THIS ENDPOINT CAN BE ASKED FOR.
     *
     * Every from-state in `SM::matrix()` (0..8 -- 7 Partially Paid and 8 Pre Order included,
     * because the column really holds them) crossed with every integer the validator accepts, with
     * the expectation taken from `SM::refusalFor()` at run time. Same-status pairs are excluded:
     * the early return owns those and has its own case above.
     *
     * Both directions are asserted in one place on purpose. A refusal census alone would go green
     * on a handler that refuses everything; an acceptance census alone would go green on the defect.
     */
    public function test_the_webhook_agrees_with_the_state_machine_on_every_transition(): void
    {
        $refused = 0;
        $accepted = 0;

        foreach (array_keys(SM::matrix()) as $from) {
            foreach (self::ACCEPTED_STATUSES as $target) {
                if ((int) $from === (int) $target) {
                    continue; // the early return, covered above
                }

                $word = self::WORD_FOR_STATUS[$target] ?? null;

                /*
                 * BOTH QUESTIONS, AND THE FIRST VERSION OF THIS CENSUS ASKED ONLY THE SECOND. It
                 * read `$word === null ? null : refusalFor(...)`, i.e. it EXPECTED 200 for every
                 * push whose integer maps to no machine word -- so it asserted the bypass was
                 * correct, for every from-state including both terminal ones. The row-level question
                 * is what the handler now asks first, and the census has to ask what the single
                 * definition answers rather than recomputing the handler's own arithmetic.
                 */
                $expectedRefusal = SM::refusalForLeaving($from) ?? SM::refusalFor($from, $word);

                $order = $this->orderAt((int) $from);
                $response = $this->push($order, $target);
                $where = "from {$from} to {$target} ('" . ($word ?? 'ungoverned') . "')";

                if ($expectedRefusal === null) {
                    $accepted++;

                    $response->assertStatus(200)->assertJson(['success' => true]);
                    $this->assertSame(
                        (int) $target,
                        (int) $this->row($order)->delivered_status,
                        "the machine permits {$where} and the webhook did not write it"
                    );

                    continue;
                }

                $refused++;

                $response->assertStatus(409)->assertJson([
                    'success' => false,
                    'message' => $expectedRefusal,
                ]);
                $this->assertSame(
                    (int) $from,
                    (int) $this->row($order)->delivered_status,
                    "the machine refuses {$where} and the webhook wrote it anyway"
                );
            }
        }

        // The census must have exercised both arms. If a future matrix made every pair legal (or
        // every pair illegal) this test would still pass while measuring nothing.
        $this->assertGreaterThan(0, $refused, 'the census drove no refused transition');
        $this->assertGreaterThan(0, $accepted, 'the census drove no permitted transition');
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * THE BYPASS: THE REFUSAL COSTS ONE EXTRA REQUEST AND REMOVES NOTHING.
     *
     * Found by independent review of the one-step fix, and verified here by driving the real route
     * twice. `FulfilmentStateMachine::governs()` lists no PENDING word, so `status = 0` maps to no
     * word, `refusalFor()` answers null and the push falls through from EVERY state -- terminal ones
     * included. And `PERMITTED[PENDING]` holds FIVE of the six governed words
     * (`cancel, confirmed, pickedup, shipped, delivered`). So a caller refused `2 -> 1` in one push
     * reaches the identical row state with `2 -> 0 -> 1`.
     *
     * Every transition the one-step check refuses is reachable in two pushes EXCEPT the `-> return`
     * ones, because `return` is the one governed word `PERMITTED[PENDING]` does not hold.
     *
     * THE END STATE IS THE ONE THE MONEY DEPENDS ON: `delivered_status = 1` with `delivery_date`
     * stamped is exactly what `Order::product_return()` gates on before it REDUCES `final_price`.
     */
    public function test_a_settled_order_cannot_be_re_opened_in_two_pushes_either(): void
    {
        $order = $this->orderAt(SM::STATE_CANCELLED);

        $viaPending = $this->push($order, SM::PENDING);
        $thenDelivered = $this->push($order, SM::STATE_DELIVERED);

        /*
         * THE HTTP CODES ARE ASSERTED BEFORE THE ROW, AND THAT ORDERING IS THE WHOLE POINT.
         *
         * MEASURED ON THE SIBLING DOOR, not reasoned about: the agent closing
         * `Api\DashboardController`'s half of GAP-135 wired a call to a predicate that I renamed
         * under it. Its door raised `Call to undefined method` -> HTTP 500 -> the row did not move
         * -> and ALL TEN of its bypass cases PASSED and reported the bypass closed. A case that
         * only asserts "the row did not move" cannot tell a refusal from a crash, and a crash is
         * not a control. This case had exactly that hole until the same agent pointed it out.
         */
        $this->assertSame(409, $viaPending->getStatusCode(),
            'the first hop out of a terminal state must be REFUSED, not merely ineffective -- a 500 '
            . 'would also leave the row alone and would not be a control');
        $this->assertSame(409, $thenDelivered->getStatusCode(),
            'the second hop must be refused for the same reason, since the row never left CANCELLED');

        $row = $this->row($order);

        $this->assertNotSame(SM::STATE_DELIVERED, (int) $row->delivered_status, sprintf(
            'a CANCELLED order was walked to DELIVERED in two pushes (0 then 1), answering %d then '
            . '%d. The one-step refusal raises the cost of this by one HTTP request and changes the '
            . 'reachable state set not at all -- and DELIVERED with a delivery_date is what '
            . '`Order::product_return()` gates on before it reduces `final_price`.',
            $viaPending->getStatusCode(),
            $thenDelivered->getStatusCode()
        ));

        $this->assertSame(SM::STATE_CANCELLED, (int) $row->delivered_status,
            'the order left its terminal state, which `SM::TERMINAL` states '
            . 'nothing does through the shared writer');
    }

    /**
     * AND THE FIRST LEG ON ITS OWN, so the two cases cannot both be satisfied by refusing the wrong one.
     *
     * If only the SECOND push were refused, the order would sit in PENDING -- out of a terminal
     * state, with its `cancel_date` intact and every in-flight transition then available. The
     * invariant is that it never leaves, so the first push is the one that must be refused.
     */
    public function test_the_first_leg_out_of_a_terminal_state_is_itself_refused(): void
    {
        foreach (SM::TERMINAL as $terminal) {
            $order = $this->orderAt($terminal);

            $response = $this->push($order, SM::PENDING);

            /* 409 unconditionally: `TERMINAL` is [2, 3] and `PENDING` is 0, so the ternary that
               stood here had a dead 200 arm and read as though 0 could be terminal. */
            $this->assertSame(409, $response->getStatusCode(), sprintf(
                    'a push of status 0 out of terminal state %d answered %d. Pending is not in the '
                    . 'machine\'s word vocabulary, so the one-step check asks it nothing -- but '
                    . 'leaving a terminal state is refused by the machine\'s own TERMINAL '
                    . 'invariant regardless of the word.',
                    $terminal,
                    $response->getStatusCode()
                ));

            $this->assertSame($terminal, (int) $this->row($order)->delivered_status,
                'the row left its terminal state');
        }
    }

    private function orderAt(int $deliveredStatus): Order
    {
        return $this->makeOrder($this->store, [
            'product_order_id' => 'DST-' . (++$this->fixtureCounter),
            'delivered_status' => $deliveredStatus,
            'return_status' => 0,
            'product_json' => '[]',
        ]);
    }

    /** The courier's own push, through the real route with the real credential. */
    private function push(Order $order, int $status)
    {
        return $this->postJson(
            self::WEBHOOK,
            ['product_order_id' => $order->product_order_id, 'status' => $status],
            ['X-Platform' => 'deliverysyria', 'Authorization' => 'Bearer ' . self::SECRET]
        );
    }

    /** The PERSISTED row. `Order` is `Cachable`, so an Eloquent read here can be pre-write. */
    private function row(Order $order): object
    {
        return DB::table('orders')->where('id', $order->id)->first();
    }

    /**
     * The first permitted (from-state, target integer, word) triple in the machine's matrix.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function firstPermittedPair(): array
    {
        foreach (SM::matrix() as $from => $words) {
            foreach ($words as $word) {
                $target = array_search($word, self::WORD_FOR_STATUS, true);

                if ($target !== false && (int) $target !== (int) $from) {
                    return [(int) $from, (int) $target, $word];
                }
            }
        }

        $this->fail('the state machine permits no transition this endpoint can express');
    }
}
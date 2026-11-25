<?php

namespace Tests\Unit\Commerce;

use App\Http\Controllers\HomeController;
use App\Support\Api\OrderStatusContract;
use App\Support\Orders\FulfilmentStateMachine as SM;
use Tests\TestCase;

/**
 * GAP-135 / `P04-P4-03` -- the fulfilment matrix itself, with no database in the way.
 *
 * The Feature twin (`tests/Feature/Commerce/OrderFulfilmentStateMachineTest`) drives the real
 * writer and is the evidence that the defect was live. This file asserts the two things that file
 * cannot see:
 *
 *   1. THE VOCABULARY DOES NOT DRIFT. `delivered_status` is named in three places --
 *      `HomeController::ORDER_STATUS_*`, `OrderStatusContract::allKeys()` and this machine -- and
 *      a fourth definition that quietly disagreed with the other two is exactly how a state
 *      machine stops being one. All three are compared here.
 *
 *   2. THE WHOLE MATRIX, both directions. Every (state, word) pair is enumerated, so a future edit
 *      that widens a row has to change this file and say so. A test that only asserted the
 *      refusals would pass just as well if the machine refused EVERYTHING, which would lock every
 *      merchant out of their own order board.
 *
 * `Tests\TestCase` rather than a bare PHPUnit case: nothing here calls `__()`, but the sibling
 * Unit suites in this repository all boot the container and a bare case would be the odd one out.
 */
class FulfilmentStateMachineTest extends TestCase
{
    /** Every status word the machine governs, and nothing else. */
    private const WORDS = [
        SM::CANCEL, SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED, SM::DELIVERED, SM::RETURNED,
    ];

    public function test_the_state_integers_match_the_controller_and_the_api_contract(): void
    {
        $this->assertSame(HomeController::ORDER_STATUS_PENDING, SM::PENDING);
        $this->assertSame(HomeController::ORDER_STATUS_DELIVERED, SM::STATE_DELIVERED);
        $this->assertSame(HomeController::ORDER_STATUS_CANCEL, SM::STATE_CANCELLED);
        $this->assertSame(HomeController::ORDER_STATUS_RETURN, SM::STATE_RETURN);
        $this->assertSame(HomeController::ORDER_STATUS_CONFIRMED, SM::STATE_CONFIRMED);
        $this->assertSame(HomeController::ORDER_STATUS_PICKED_UP, SM::STATE_PICKED_UP);
        $this->assertSame(HomeController::ORDER_STATUS_SHIPPED, SM::STATE_SHIPPED);
        $this->assertSame(HomeController::ORDER_STATUS_PARTIALLY_PAID, SM::STATE_PARTIALLY_PAID);
        $this->assertSame(HomeController::ORDER_STATUS_PRE_ORDER, SM::STATE_PRE_ORDER);

        $machineStates = array_keys(SM::matrix());
        $contractStates = array_keys(OrderStatusContract::allKeys());
        sort($machineStates);
        sort($contractStates);

        $this->assertSame(
            $contractStates,
            $machineStates,
            'the machine covers a different set of delivered_status values than the API contract '
            . 'names. A state with no row is a state with no rule.'
        );
    }

    /**
     * THE WHOLE MATRIX, pair by pair. The expectation is written out here rather than read from
     * the class, because reading it from the class would assert nothing.
     */
    public function test_every_transition_is_decided_exactly_as_specified(): void
    {
        $expected = [
            // state => the words permitted out of it
            0 => ['cancel', 'confirmed', 'pickedup', 'shipped', 'delivered'],
            4 => ['confirmed', 'pickedup', 'shipped', 'delivered', 'return'],
            5 => ['confirmed', 'pickedup', 'shipped', 'delivered', 'return'],
            6 => ['confirmed', 'pickedup', 'shipped', 'delivered', 'return'],
            7 => ['confirmed', 'pickedup', 'shipped', 'delivered', 'return'],
            8 => ['confirmed', 'pickedup', 'shipped', 'delivered', 'return'],
            1 => ['delivered', 'return'],
            2 => [],
            3 => [],
        ];

        foreach ($expected as $from => $permitted) {
            foreach (self::WORDS as $word) {
                $shouldPass = in_array($word, $permitted, true);

                $this->assertSame(
                    $shouldPass,
                    SM::permits($from, $word),
                    sprintf(
                        'state %d -> %s was %s and should be %s',
                        $from,
                        $word,
                        SM::permits($from, $word) ? 'PERMITTED' : 'REFUSED',
                        $shouldPass ? 'PERMITTED' : 'REFUSED'
                    )
                );
            }
        }
    }

    /** Terminal means terminal: no word at all leaves a cancelled or returned order. */
    public function test_the_two_terminal_states_permit_nothing(): void
    {
        $this->assertSame([2, 3], SM::TERMINAL);

        foreach (SM::TERMINAL as $from) {
            foreach (self::WORDS as $word) {
                $this->assertFalse(SM::permits($from, $word),
                    "state {$from} is terminal but permitted '{$word}'");
            }
        }
    }

    /**
     * THE TWO ESTABLISHED MESSAGES ARE RETURNED BYTE FOR BYTE, because both are translation keys
     * (`resources/lang/en.json:4406` and its Arabic twin) and both have been on the wire to the
     * Flutter app and the storefront for the life of this codebase.
     */
    public function test_the_two_pre_existing_refusals_keep_their_exact_wording(): void
    {
        $this->assertSame(
            'The order has been delivered. So you cannot cancel the order.',
            SM::refusalFor(1, SM::CANCEL)
        );

        $this->assertSame(
            'Your order has not been delivered. You can return the order after delivery.',
            SM::refusalFor(0, SM::RETURNED)
        );

        /*
         * AND THE TWO NEW ONES ARE LOCALISABLE, which is a different claim from "translated".
         * They go through `__()`, which answers with the key while no catalogue entry exists -- so
         * they read as English today, deliberately, and the entries are reported to the owner
         * rather than worded here. This asserts the SHAPE: the sentence the machine returns is the
         * constant, so a localisation census keyed on the constant finds the right string.
         */
        $this->assertSame(SM::MESSAGE_SETTLED, SM::refusalFor(2, SM::DELIVERED));
        $this->assertSame(SM::MESSAGE_SETTLED, SM::refusalFor(3, SM::SHIPPED));
        $this->assertSame(SM::MESSAGE_NOT_PERMITTED, SM::refusalFor(1, SM::CONFIRMED));

        foreach ([
            'resources/lang/en.json',
            'resources/lang/ar.json',
        ] as $catalogue) {
            $keys = json_decode((string) file_get_contents(base_path($catalogue)), true);

            $this->assertIsArray($keys, $catalogue . ' did not decode');
            $this->assertArrayHasKey(SM::MESSAGE_CANCEL_REFUSED, $keys,
                'the cancel refusal is no longer a key in ' . $catalogue
                . ', so it reaches an Arabic merchant untranslated');
        }
    }

    /**
     * A WORD THIS MACHINE DOES NOT GOVERN FALLS THROUGH -- EXCEPT OUT OF A FORWARD-ONLY STATE.
     *
     * This case asserted the first half for every state, and that is what OWNER DECISION H2 (option
     * A, 2026-11-25) changed. The half that was right is still right and still asserted: from an
     * ordinary state the machine genuinely has nothing to say about a word it does not govern, and
     * must say nothing, so the writer's own module and unknown-value arms keep deciding -- `7`
     * Partially Paid and `8` Pre Order out of a Pending row are the cases that depend on it, and
     * `P2-07-12` widened the admin endpoint for exactly those two.
     *
     * The half that was WRONG let every refusal be undone in one extra request. The two doors that
     * assign `delivered_status` themselves take an integer off the wire, and three of the nine
     * values map to no word -- so `2 (CANCELLED) -> 0 -> 1 (DELIVERED)` answered 200 then 200,
     * reaching the state `product_return()` gates on before it reduces `final_price`, and
     * `1 -> 0 -> 2` reached a cancel that restores stock for delivered goods. Measured through both
     * live routes before `FORWARD_ONLY` existed.
     */
    public function test_an_ungoverned_status_word_falls_through_except_out_of_a_forward_only_state(): void
    {
        $this->assertNotEmpty(SM::FORWARD_ONLY,
            'premise: the forward-only set is empty, so the second loop below asserts nothing');

        foreach (['pre_order', 'partial', 'no_such_status', '', '2', 'CANCEL', 'Delivered'] as $word) {
            $this->assertFalse(SM::governs($word), "'{$word}' should not be governed");

            foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $from) {
                if (in_array($from, SM::FORWARD_ONLY, true)) {
                    $this->assertSame(SM::MESSAGE_NOT_PERMITTED, SM::refusalFor($from, $word),
                        "'{$word}' was permitted out of forward-only state {$from}. Nothing leaves "
                        . 'these three except a governed word -- which is what makes every other '
                        . "refusal composable rather than one request from irrelevant.");

                    continue;
                }

                $this->assertNull(SM::refusalFor($from, $word),
                    "'{$word}' was refused from state {$from}; outside the forward-only set it must "
                    . "fall through to the writer's own module and unknown-value arms");
            }
        }
    }

    /**
     * AND THE RETURN WORKFLOW OUT OF A DELIVERED ORDER IS UNTOUCHED, which is the half of H2 the
     * owner named explicitly: "Preserve legitimate return and refund workflows".
     *
     * `returned` and `delivered` are GOVERNED words and both sit in `PERMITTED[STATE_DELIVERED]`, so
     * the invariant -- which speaks only about words the machine cannot translate -- does not reach
     * them. Without this case, widening `FORWARD_ONLY` into a blanket "nothing leaves DELIVERED"
     * would pass the case above and silently close the return path.
     */
    public function test_the_return_workflow_out_of_a_delivered_order_is_still_permitted(): void
    {
        $this->assertContains(SM::STATE_DELIVERED, SM::FORWARD_ONLY,
            'premise: DELIVERED must be in the set for this case to be about anything');

        $this->assertNull(SM::refusalFor(SM::STATE_DELIVERED, SM::RETURNED),
            'a delivered order can no longer be returned, which closes the return and refund '
            . 'workflow H2 required be preserved');
        $this->assertNull(SM::refusalFor(SM::STATE_DELIVERED, SM::DELIVERED),
            're-stamping a delivered order is refused, so a courier retry that carries a note now '
            . 'fails where it used to succeed');

        foreach ([SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED, SM::CANCEL] as $backwards) {
            $this->assertNotNull(SM::refusalFor(SM::STATE_DELIVERED, $backwards),
                'a delivered order moved backwards to a governed word, which the PERMITTED row '
                . 'already refused before H2 -- so this is a regression in the older rule');
        }
    }

    /**
     * THE COLUMN HOLDS VALUES OUTSIDE 0..8 AND THOSE ROWS MUST NOT BE STRANDED.
     *
     * `Api\ApiController:10471` writes the literal `'-'`, and `OrderDataTable:334` branches on
     * `null` and `''`. The pre-existing loose comparisons are what decide those rows, and casting
     * would have changed them: `(int) '-'` is `0` -- which would make a `'-'` order cancellable --
     * while `'-' == 0` is false under PHP 8, which is what the writer has always evaluated.
     */
    public function test_a_value_outside_the_vocabulary_behaves_as_it_did_before(): void
    {
        $this->assertNull(SM::stateOf('-'), "'-' is not a state");
        $this->assertNull(SM::stateOf('abc'));
        $this->assertNull(SM::stateOf(99));
        $this->assertSame(0, SM::stateOf(null), 'null reads as Pending, as every comparison does');
        $this->assertNull(SM::stateOf(''),
            "'' is NOT Pending: PHP 8 makes `'' == 0` false, so the writer's two rules have "
            . 'always treated it as an unrecognised value and this machine must not "tidy" that');
        $this->assertSame(3, SM::stateOf('3'), 'the column is read back as a string on MySQL');

        /*
         * cancel: decided by the writer's own `!= 0`, reproduced rather than rewritten. MEASURED
         * under PHP 8, where `0 == ''` is FALSE and `0 == null` is TRUE -- so an order whose
         * `delivered_status` is the empty string has never been cancellable through this writer
         * and still is not. That is a faithful quirk, not an intention: it is asserted here so
         * that a later "tidy-up" to `(int)` has to notice it is a behaviour change.
         */
        $this->assertFalse(SM::permits('-', SM::CANCEL));
        $this->assertFalse(SM::permits('', SM::CANCEL),
            "'' is not cancellable today (PHP 8: 0 == '' is false) and must not become so here");
        $this->assertTrue(SM::permits(null, SM::CANCEL));
        $this->assertTrue(SM::permits('0', SM::CANCEL));
        $this->assertTrue(SM::permits(0, SM::CANCEL));

        /* return: the mirror of the same comparison, `== 0`. */
        $this->assertTrue(SM::permits('-', SM::RETURNED));
        $this->assertTrue(SM::permits('', SM::RETURNED),
            "'' is returnable today for the same PHP 8 reason, and must stay so");
        $this->assertFalse(SM::permits(null, SM::RETURNED));
        $this->assertFalse(SM::permits(0, SM::RETURNED));

        /* the four progression arms: an unrecognised state stays as movable as it is today. */
        foreach ([SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED, SM::DELIVERED] as $word) {
            $this->assertTrue(SM::permits('-', $word),
                "a '-' row was stranded on '{$word}'; that is a new defect, not a fix");
        }
    }

    /** A numeric-string state decides the same way the integer does, on every pair. */
    public function test_a_numeric_string_state_decides_identically_to_the_integer(): void
    {
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $from) {
            foreach (self::WORDS as $word) {
                $this->assertSame(
                    SM::permits($from, $word),
                    SM::permits((string) $from, $word),
                    "state {$from} and '{$from}' disagreed on '{$word}'; MySQL returns the "
                    . 'column as a string and SQLite as an int'
                );
            }
        }
    }
}

<?php

namespace Tests\Unit\Commerce;

use App\Support\Orders\FulfilmentStateMachine as SM;
use Tests\TestCase;

/**
 * WHICH STATUSES A SHOPPER MAY SET, with no HTTP in the way.  GAP-135 / `P04-P4-03` (`P4-F036`).
 * =================================================================================================
 *
 * The Feature twin -- `tests/Feature/Security/Gap135ShopperFulfilmentTransitionTest` -- drives the
 * real route with a real customer, merchant, driver and operator token and is the evidence that the
 * defect was live. This file asserts the three things that file cannot see:
 *
 *   1. THE WHOLE VOCABULARY, BOTH DIRECTIONS. Every governed word is put to the rule, so a future
 *      edit that widens the shopper's set has to change this file and say so. A test that only
 *      asserted the refusals would pass just as well if the rule refused `cancel` too -- which
 *      would take away self-service cancellation, the one journey this door exists to serve.
 *   2. THE SET STAYS INSIDE THE WRITER'S OWN VOCABULARY. A word the door permits that the shared
 *      writer does not match would answer "That order status is not recognised." to a shopper
 *      doing exactly what they are allowed to do.
 *   3. THE REFUSAL IS A STRING THAT ALREADY SHIPS IN ARABIC. A new sentence on a shopper path goes
 *      out untranslated to every Arabic shopper, which CLAUDE.md puts in the escalate set.
 */
class Gap135ShopperStatusVocabularyTest extends TestCase
{
    /** Every word `Order::order_status_change()`'s branch chain matches. */
    private const WORDS = [
        SM::CANCEL, SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED, SM::DELIVERED, SM::RETURNED,
    ];

    public function test_a_shopper_may_cancel_and_may_not_set_any_other_governed_word(): void
    {
        $this->assertSame([SM::CANCEL], SM::SHOPPER_PERMITTED,
            'The shopper vocabulary changed. `cancel` alone is the rule the sibling web shopper '
            . 'door already enforces (OrderController::status_cancel, finding P4-F235); widening '
            . 'it is a product decision about what a buyer may assert, and the Feature twin has a '
            . 'named case to invert for `return`.');

        $this->assertNull(SM::refusalForShopper(SM::CANCEL),
            'A shopper may cancel their own order. The AUTHZ-3 fix calls that "a legitimate journey '
            . '... deliberately preserved" and the model already refuses it once the order has left '
            . 'Pending.');

        foreach (array_diff(self::WORDS, SM::SHOPPER_PERMITTED) as $word) {
            $this->assertSame(SM::MESSAGE_SHOPPER_NOT_PERMITTED, SM::refusalForShopper($word),
                'A shopper may set `' . $word . '`. Confirmation, pick-up, shipment and delivery '
                . 'are facts about goods that the merchant or the courier establishes, and `return` '
                . 'drops the order into the merchant\'s return-approval workflow -- out of SHIPPED, '
                . 'for goods the courier is still carrying.');
        }
    }

    /**
     * DEFAULT-DENY. A word this machine does not govern is refused for a shopper too: `pre_order`
     * and the `PartialPayments` vocabulary are handled by module arms below the writer's six and
     * write `delivered_status` 8 and 7 -- a payment state and an order type, neither of them a
     * buyer's to name.
     *
     * `refusalFor()`'s own history is the argument. It answered `null` for an ungoverned word, every
     * caller read that as "go ahead", and `FORWARD_ONLY` exists because of what that cost.
     */
    public function test_a_word_the_machine_does_not_govern_is_refused_for_a_shopper(): void
    {
        foreach (['pre_order', 'partial_paid', 'pending', 'wat', ''] as $word) {
            $this->assertFalse(SM::governs($word), 'Premise: `' . $word . '` is not a governed word, '
                . 'so this case is measuring the default-deny arm and not the list.');
            $this->assertSame(SM::MESSAGE_SHOPPER_NOT_PERMITTED, SM::refusalForShopper($word),
                'A shopper was admitted with `' . $word . '`, a word the machine does not govern. '
                . 'The rule is default-deny: `cancel` and nothing else.');
        }
    }

    /**
     * AND IT IS NOT COERCED. A padded, re-cased or non-string value is refused rather than
     * normalised, because the writer's own branch chain matches `'cancel'` exactly -- so admitting
     * `' cancel '` here would mean passing the writer a word it then answers "not recognised" to,
     * with a worse message than the refusal.
     *
     * THIS IS THE ONE PROPERTY THE FEATURE TWIN CANNOT SEE, and that was measured, not assumed:
     * Laravel's global `TrimStrings` middleware trims every request value, so `'delivered '` arrives
     * at the door as `'delivered'` and a padded word cannot be driven over HTTP at all. Found when a
     * mutation that widened the shopper set accepted `'delivered '` through the real route. So the
     * strictness here is depth for a caller that does not come through the HTTP kernel -- a console
     * command, a queued job, a module -- and it is pinned at this level because that is the only
     * level at which it is visible.
     */
    public function test_a_padded_recased_or_non_string_value_is_refused(): void
    {
        foreach ([' cancel ', 'cancel ', 'CANCEL', 'Cancel', 0, 1, null, true, ['cancel']] as $value) {
            $this->assertSame(SM::MESSAGE_SHOPPER_NOT_PERMITTED, SM::refusalForShopper($value),
                'A shopper was admitted with ' . var_export($value, true) . '. The comparison is '
                . 'strict on purpose: it can only ever ADD refusals, never admit a word the shared '
                . 'writer would not have matched.');
        }
    }

    /**
     * THE SET IS A SUBSET OF WHAT THE WRITER MATCHES, so a permitted word always reaches an arm.
     */
    public function test_every_word_a_shopper_may_set_is_one_the_writer_governs(): void
    {
        foreach (SM::SHOPPER_PERMITTED as $word) {
            $this->assertTrue(SM::governs($word),
                '`' . $word . '` is in the shopper vocabulary and the machine does not govern it, '
                . 'so the shared writer has no arm for it and a shopper doing exactly what they are '
                . 'allowed to do would be told their status is not recognised.');
        }
    }

    /**
     * IT SAYS NOTHING ABOUT THE ORDER'S OWN STATE, and the door composes the two.
     *
     * The vocabulary rule is about the PRINCIPAL; `refusalFor()` is about the ROW. Both still apply:
     * a shopper's `cancel` on a delivered order is refused by the established
     * `MESSAGE_CANCEL_REFUSED`, which is what the shared writer has always answered and what the
     * Flutter and web clients read.
     */
    public function test_the_two_rules_compose_rather_than_replace_each_other(): void
    {
        $this->assertNull(SM::refusalForShopper(SM::CANCEL),
            'Premise: the vocabulary rule admits `cancel` whatever the state, because it is not '
            . 'asked about the state.');

        $this->assertSame(SM::MESSAGE_CANCEL_REFUSED, SM::refusalFor(SM::STATE_DELIVERED, SM::CANCEL),
            'The state rule stopped refusing a cancel after delivery. That refusal is the one the '
            . 'writer has always answered with and both clients read its text.');

        $this->assertSame(SM::MESSAGE_CANCEL_REFUSED, SM::refusalFor(SM::STATE_CANCELLED, SM::CANCEL),
            'A second cancellation of the same order was permitted; the replay guard on the '
            . 'storefront door is exactly this refusal.');
    }

    /**
     * THE REFUSAL ALREADY SHIPS IN BOTH LANGUAGES, because the sibling shopper door put it there.
     *
     * Read from the catalogues rather than from `__()`: the application locale in a test is English,
     * so `__()` would answer the key itself whether or not an Arabic entry exists -- the exact
     * reading that lets an untranslated string ship.
     */
    public function test_the_refusal_is_already_translated_in_both_catalogues(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['en', 'ar'] as $locale) {
            $path = $root . '/resources/lang/' . $locale . '.json';
            $this->assertFileExists($path);

            $catalogue = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($catalogue, $locale . '.json did not decode.');
            $this->assertArrayHasKey(SM::MESSAGE_SHOPPER_NOT_PERMITTED, $catalogue,
                'The shopper refusal is not in ' . $locale . '.json. A new sentence on a shopper '
                . 'path goes out untranslated to every Arabic shopper; this string was chosen '
                . 'because the sibling door (OrderController::status_cancel) already ships it.');
            $this->assertNotSame('', trim((string) $catalogue[SM::MESSAGE_SHOPPER_NOT_PERMITTED]),
                'The ' . $locale . ' entry is empty.');
        }

        $arabic = json_decode((string) file_get_contents($root . '/resources/lang/ar.json'), true);

        $this->assertNotSame(
            SM::MESSAGE_SHOPPER_NOT_PERMITTED,
            $arabic[SM::MESSAGE_SHOPPER_NOT_PERMITTED],
            'The Arabic entry is the English sentence, so an Arabic shopper reads English.'
        );
    }
}

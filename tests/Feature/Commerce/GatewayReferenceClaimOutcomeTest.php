<?php

namespace Tests\Feature\Commerce;

use App\Services\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * E14 / THE OWNER'S §3 REQUIREMENT 5 — `claim()`'s `bool` COULD NOT CARRY WHAT IT HAD TO SAY.
 * ============================================================================================
 *
 * `claim()` returned `true` on **four** different paths and `false` on one, and the four do not
 * mean the same thing:
 *
 * | outcome | the row exists | a replay is possible | was it visible |
 * |---|---|---|---|
 * | the insert succeeded | yes | **no** | — |
 * | the table is absent | no | **yes** | logged `warning` |
 * | `usable()` said no | no | **yes** | **NOTHING AT ALL** |
 * | the insert threw | no | **yes** | logged `error` |
 * | already recorded | yes | — | logged, returns `false` |
 *
 * So a caller reading `true` as "I am protected" was wrong three times out of four, and in the
 * third case **no line anywhere said so**: an empty reference or a zero store id produced no row,
 * no refusal and no signal, so a writer that passed `''` believed it was protected forever. That
 * is the worst of the four, because a missing migration is an operator's outstanding task while an
 * unusable reference is a **bug in the caller**.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY NOW, AND WHY NOTHING BREAKS
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `claim()` and `bind()` have **zero production callers**. That is exactly why this is the moment:
 * wiring a money path to a control whose success value is ambiguous is how the ambiguity becomes
 * permanent. `claim()` keeps its documented meaning — *"may I proceed"*, a real question with a
 * real yes/no answer — and `claimState()` is the one a money path should ask.
 *
 * Each case below drives ONE outcome and asserts the state, so a future change that collapses two
 * of them fails here rather than at the call site that trusted the difference.
 */
class GatewayReferenceClaimOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_premise_the_table_is_installed_in_this_suite(): void
    {
        /* Without this the absent-table case below is indistinguishable from every other case,
           and three of the five assertions would pass for the wrong reason. */
        $this->assertTrue(
            Schema::hasTable(GatewayReference::TABLE),
            'the gateway reference table is not installed, so this suite cannot tell a recorded '
            . 'claim from an unprotected one'
        );
    }

    public function test_a_first_claim_is_recorded_and_is_the_only_protected_outcome(): void
    {
        $state = GatewayReference::claimState(7, 'stripe', 'ref-' . uniqid(), '100.00', GatewayReference::CONFIRMED);

        $this->assertSame(GatewayReference::CLAIM_RECORDED, $state);
        $this->assertTrue(GatewayReference::isProtected($state));
        $this->assertFalse(GatewayReference::isReplay($state));
        $this->assertSame(1, (int) DB::table(GatewayReference::TABLE)->count(), 'the row was not written');
    }

    public function test_a_second_claim_on_the_same_reference_is_a_replay(): void
    {
        $reference = 'ref-' . uniqid();
        GatewayReference::claimState(7, 'stripe', $reference, '100.00');

        $state = GatewayReference::claimState(7, 'stripe', $reference, '100.00');

        $this->assertSame(GatewayReference::CLAIM_REPLAY, $state);
        $this->assertTrue(GatewayReference::isReplay($state));
        $this->assertFalse(GatewayReference::isProtected($state), 'a replay must never read as protected');
        $this->assertSame(1, (int) DB::table(GatewayReference::TABLE)->count(), 'the replay wrote a second row');

        /* And `claim()`'s bool still says what it always said. */
        $this->assertFalse(GatewayReference::claim(7, 'stripe', $reference, '100.00'));
    }

    /**
     * THE SAME REFERENCE IN ANOTHER STORE IS NOT A REPLAY.
     *
     * The uniqueness is per store, and it has to be: two merchants' gateways can mint the same
     * reference, and treating one as the other's replay would refuse a legitimate payment.
     */
    public function test_the_same_reference_in_a_different_store_is_recorded(): void
    {
        $reference = 'ref-' . uniqid();
        GatewayReference::claimState(7, 'stripe', $reference, '100.00');

        $this->assertSame(
            GatewayReference::CLAIM_RECORDED,
            GatewayReference::claimState(8, 'stripe', $reference, '100.00'),
            'another store\'s identical reference was refused as a replay, which refuses a real payment'
        );
    }

    /**
     * THE PATH THAT WAS SILENT. An unusable store id or reference is now named AND logged.
     *
     * @dataProvider unusable
     */
    public function test_an_unusable_input_is_named_as_unprotected_and_no_longer_silent($storeId, string $reference): void
    {
        $state = GatewayReference::claimState($storeId, 'stripe', $reference, '100.00');

        $this->assertSame(GatewayReference::CLAIM_UNPROTECTED_UNUSABLE_INPUT, $state);
        $this->assertFalse(
            GatewayReference::isProtected($state),
            'an unusable input read as PROTECTED, so a writer that passed an empty reference '
            . 'believed it could not be replayed. Nothing was recorded.'
        );
        $this->assertTrue(
            GatewayReference::claim($storeId, 'stripe', $reference, '100.00'),
            'the bool must still say "proceed" -- this is additive and must not refuse a payment'
        );
        $this->assertSame(0, (int) DB::table(GatewayReference::TABLE)->count(), 'something was written for an unusable input');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains(
                (string) $message,
                'the store id or reference is unusable'
            ))
            ->atLeast()->once();
    }

    public static function unusable(): array
    {
        return [
            'empty reference' => [7, ''],
            'whitespace reference' => [7, '   '],
            'zero store' => [0, 'ref-ok'],
            'negative store' => [-1, 'ref-ok'],
            'non-numeric store' => ['not-a-store', 'ref-ok'],
        ];
    }

    /**
     * AND THE REFERENCE VALUE ITSELF IS NOT LOGGED.
     *
     * It is a payment identifier. The log says WHETHER it was empty, not what it was — the same
     * division `GuardsPlanActivation` makes between what an operator needs and what belongs in a
     * log file.
     */
    public function test_the_unusable_log_does_not_carry_the_reference_value(): void
    {
        GatewayReference::claimState(0, 'stripe', 'a-real-looking-payment-reference', '100.00');

        Log::shouldNotHaveReceived('warning', [
            \Mockery::any(),
            \Mockery::on(fn ($context) => is_array($context)
                && in_array('a-real-looking-payment-reference', array_values($context), true)),
        ]);
    }

    /**
     * THE ABSENT TABLE IS A DISTINCT OUTCOME FROM THE UNUSABLE INPUT, and the distinction is the
     * point: one is an operator's outstanding migration, the other is a bug in the caller.
     *
     * The table is dropped rather than mocked, because `available()` asks the schema directly.
     * `RefreshDatabase` rebuilds it for the next test.
     */
    public function test_an_absent_table_is_its_own_unprotected_outcome(): void
    {
        Schema::drop(GatewayReference::TABLE);

        $state = GatewayReference::claimState(7, 'stripe', 'ref-' . uniqid(), '100.00');

        $this->assertSame(GatewayReference::CLAIM_UNPROTECTED_TABLE_ABSENT, $state);
        $this->assertNotSame(
            GatewayReference::CLAIM_UNPROTECTED_UNUSABLE_INPUT,
            $state,
            'a missing migration and a caller bug are reported as the same thing, so an operator '
            . 'cannot tell which one they have'
        );
        $this->assertFalse(GatewayReference::isProtected($state));
        $this->assertTrue(
            GatewayReference::claim(7, 'stripe', 'ref-' . uniqid(), '100.00'),
            'an installation without the table must behave exactly as it did before this service '
            . 'existed -- the feature is additive and cannot refuse a legitimate payment because a '
            . 'migration is outstanding'
        );
    }

    /**
     * `claim()` KEEPS ITS CONTRACT, which is what makes this change safe to land with no callers
     * migrated: the bool is `false` for exactly one outcome and `true` for the other four.
     */
    public function test_the_bool_wrapper_is_false_only_for_a_replay(): void
    {
        $reference = 'ref-' . uniqid();

        $this->assertTrue(GatewayReference::claim(7, 'stripe', $reference, '100.00'), 'a first claim must proceed');
        $this->assertFalse(GatewayReference::claim(7, 'stripe', $reference, '100.00'), 'a replay must not proceed');
        $this->assertTrue(GatewayReference::claim(7, 'stripe', '', '100.00'), 'an unusable input must still proceed');
    }
}

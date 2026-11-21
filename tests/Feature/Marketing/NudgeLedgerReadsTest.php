<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingNudgeSend;
use App\Services\Marketing\NudgeLedger;
use App\Services\Marketing\NudgeThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-219 — THE LEDGER'S TWO READS, driven at the service because the endpoints cannot reach them.
 * =================================================================================================
 *
 * Both cases here exist because a MUTATION SURVIVED `NudgeIdempotencyTest`, and in each case the
 * survival was for a real reason rather than an oversight in the mutation:
 *
 *  1. DROPPING `store_id` FROM THE DUPLICATE READ changed nothing observable through the HTTP
 *     endpoints. The unique key is `(store_id, idempotency_key)`, so two stores with the SAME key
 *     both insert successfully and the duplicate read never runs for either -- it runs only on the
 *     THIRD click, by which point the end-to-end test has already finished. But two stores really
 *     can share a key: the key is derived from channel, template, contact and subject, and two
 *     merchants can have the same shopper's phone number, the same template name and the same cart
 *     row id. Without the predicate, `->first()` returns the LOWEST id -- the other store's row --
 *     so store B's merchant would be told their nudge was sent at a time that is actually store
 *     A's send. A small disclosure and a wrong answer, and the only test that can see it is this
 *     one.
 *
 *  2. THE `outcome` FILTER IN `lastSentAt()` IS MASKED BY `whereNotNull('sent_at')` as the code
 *     stands, because `recordRefusal()` always writes `sent_at` as null. That makes the filter look
 *     like dead code, and the honest response is not to delete it but to drive what it is FOR: the
 *     row outlives the code that wrote it. A future caller that stamps `sent_at` on a refusal -- or
 *     a hand-written row, or a data repair -- must not be able to extend a shopper's quiet period
 *     with a message that never arrived. This is the same posture `SavedView::appliedFilters()`
 *     takes by re-sanitising on read.
 */
final class NudgeLedgerReadsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function ledger(): NudgeLedger
    {
        return app(NudgeLedger::class);
    }

    /**
     * THE DUPLICATE REPORTED TO ONE STORE IS THAT STORE'S OWN SEND, not a neighbour's.
     *
     * Store A and store B are given the SAME idempotency key on purpose -- same channel, template,
     * contact hash and subject id, which is an ordinary coincidence between two merchants. A's send
     * is backdated so the two are distinguishable by time alone.
     */
    public function test_a_duplicate_reports_the_callers_own_previous_send_and_not_another_stores(): void
    {
        $ledger = $this->ledger();
        $hash = $ledger->hashContact('whatsapp', '963900005555');

        $storeA = 4001;
        $storeB = 4002;

        // Store A reserved this exact action ten days ago.
        $this->assertTrue(
            $ledger->reserve($storeA, 'whatsapp', 'Abandon Cart', $hash, 'cart', 77)->allowed(),
            'Premise: store A must be able to reserve.'
        );

        MarketingNudgeSend::withoutGlobalScopes()
            ->where('store_id', $storeA)
            ->update(['sent_at' => CarbonImmutable::now()->subDays(10)]);

        // Store B reserves the SAME key. The unique index is per store, so this must succeed.
        $this->assertTrue(
            $ledger->reserve($storeB, 'whatsapp', 'Abandon Cart', $hash, 'cart', 77)->allowed(),
            'Store B was refused a key store A happens to share. The unique index is '
            . '(store_id, idempotency_key), so this is not a collision.'
        );

        $this->assertSame(
            2,
            MarketingNudgeSend::withoutGlobalScopes()->count(),
            'Premise: there should now be exactly one row per store.'
        );

        // Store B clicks again. THIS is the duplicate read.
        $duplicate = $ledger->reserve($storeB, 'whatsapp', 'Abandon Cart', $hash, 'cart', 77);

        $this->assertFalse($duplicate->allowed(), 'The repeat click was not refused.');

        $previous = $duplicate->previousSentAt();

        $this->assertNotNull($previous, 'The duplicate carries no previous send time.');

        $this->assertLessThan(
            60,
            abs((int) $previous->diffInSeconds(CarbonImmutable::now())),
            sprintf(
                'The duplicate reported %s, which is store A\'s backdated send rather than store '
                . 'B\'s own. The duplicate read is missing its store_id predicate, so `first()` '
                . 'returned the lowest id -- another tenant\'s row.',
                $previous->toDateTimeString()
            )
        );
    }

    /**
     * A REFUSAL NEVER EXTENDS THE QUIET PERIOD, even if something stamps a time on it.
     *
     * The row is written directly, with `outcome = throttled` AND a `sent_at`, because that is the
     * shape the filter defends against and no current code path produces it. If the filter were
     * removed, this refusal would read as a send and the merchant would be locked out for an hour
     * by a message that never left.
     */
    public function test_a_refusal_row_with_a_timestamp_is_not_counted_as_a_send(): void
    {
        config(['marketing.nudge_min_interval_seconds' => 3600]);

        $ledger = $this->ledger();
        $throttle = app(NudgeThrottle::class);
        $hash = $ledger->hashContact('whatsapp', '963900006666');
        $storeId = 5001;

        DB::table('marketing_nudge_sends')->insert([
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'message_class' => 'marketing',
            'template' => 'Abandon Cart',
            'contact_hash' => $hash,
            'subject_type' => 'cart',
            'subject_id' => 99,
            'idempotency_key' => hash('sha256', 'a-refusal-that-carries-a-time'),
            'outcome' => MarketingNudgeSend::OUTCOME_THROTTLED,
            // The shape the filter exists for: a refusal with a time on it.
            'sent_at' => CarbonImmutable::now()->subMinutes(5),
            'created_by' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $this->assertNull(
            $ledger->lastSentAt($storeId, 'whatsapp', $hash),
            'A `throttled` row was counted as the last send. Nothing arrived, so nothing may '
            . 'extend the shopper\'s quiet period.'
        );

        $this->assertTrue(
            $throttle->check($storeId, 'whatsapp', $hash)->allowed(),
            'The cadence rule refused a nudge on the strength of a refusal five minutes ago. A '
            . 'guard that punishes the merchant for having been refused is worse than no guard.'
        );
    }

    /**
     * AND A REAL SEND DOES STOP IT, so the test above is not passing because the throttle is inert.
     *
     * Without this, deleting the whole cadence limb would leave both assertions above green.
     */
    public function test_the_premise_that_a_real_send_does_stop_a_nudge_within_the_interval(): void
    {
        config(['marketing.nudge_min_interval_seconds' => 3600]);

        $ledger = $this->ledger();
        $hash = $ledger->hashContact('whatsapp', '963900007777');
        $storeId = 5002;

        $ledger->reserve($storeId, 'whatsapp', 'Abandon Cart', $hash, 'cart', 1);

        $decision = app(NudgeThrottle::class)->check($storeId, 'whatsapp', $hash);

        $this->assertFalse($decision->allowed(),
            'A send seconds ago did not trigger the one-hour cadence, so the limb is inert and the '
            . 'two tests above prove nothing.');
        $this->assertSame('throttled', $decision->reason());
        $this->assertNotNull($decision->retryAfterSeconds());
    }

    /**
     * NORMALISATION: one shopper, two spellings, one contact.
     *
     * Without this, a merchant could send twice by typing the number differently -- which is not a
     * hypothetical, because the mobile comes from whatever the shopper entered at registration.
     */
    public function test_two_spellings_of_one_number_are_the_same_contact(): void
    {
        $ledger = $this->ledger();

        $this->assertSame(
            $ledger->hashContact('whatsapp', '+963 900 00 11 22'),
            $ledger->hashContact('whatsapp', '963-900-001122'),
            'Two spellings of one phone number hash differently, so the same shopper can be nudged '
            . 'once per spelling.'
        );

        $this->assertSame(
            $ledger->hashContact('email', 'Shopper@Example.Test'),
            $ledger->hashContact('email', 'shopper@example.test  '),
            'E-mail case or trailing space produces a different contact.'
        );

        $this->assertSame('', $ledger->hashContact('whatsapp', ''),
            'An empty address must hash to nothing, not to the hash of an empty string -- callers '
            . 'read the empty result as "no contact, no guard".');
        $this->assertSame('', $ledger->hashContact('whatsapp', '   '));
    }

    /**
     * THE KEY HAS NO CLOCK IN IT, which is what makes the guard an identity check rather than a
     * frequency rule -- and therefore what lets it ship without an approved sending frequency.
     */
    public function test_the_idempotency_key_is_stable_across_time_and_distinct_per_action(): void
    {
        $ledger = $this->ledger();
        $hash = $ledger->hashContact('whatsapp', '963900008888');

        $first = $ledger->keyFor('whatsapp', 'Abandon Cart', $hash, 'cart', 5);

        /*
         * THE KEY IS PINNED TO ITS EXACT VALUE, and that is deliberate rather than brittle.
         *
         * The key is effectively a WIRE FORMAT: rows written by one version of this code have to go
         * on matching in the next, or every stored reservation silently stops suppressing anything
         * and the first click after a deploy sends a second message. An exact pin is the only
         * assertion that catches a change to ANY input.
         *
         * It is also the only one that catches a CLOCK. Moving `CarbonImmutable::setTestNow()`
         * forward (below) does NOT move PHP's `time()`, so a mutation salting the key with `time()`
         * passed a Carbon-only check: both calls landed in the same second. That survival is why
         * this line exists. If the key's definition changes on purpose, bump the `nudge.v1` prefix
         * and update this value in the same commit -- the version prefix is there precisely so an
         * intentional change is distinguishable from an accidental one.
         */
        $this->assertSame(
            'c73657245cc8ff7a7f0d6fd60f66e0cdeadd4aeeda07779c863495e4b027cc1a',
            $first,
            'The idempotency key no longer hashes to its pinned value, so every reservation already '
            . 'in the ledger has stopped matching and the guard suppresses nothing. If this change '
            . 'is intended, bump the `nudge.v1` prefix and update this expectation together.'
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addYear());
        $later = $ledger->keyFor('whatsapp', 'Abandon Cart', $hash, 'cart', 5);
        CarbonImmutable::setTestNow();

        $this->assertSame($first, $later,
            'The idempotency key changed with the clock. A key that drifts in time is a frequency '
            . 'rule in disguise, and no sending frequency has been approved.');

        foreach ([
            'channel' => $ledger->keyFor('email', 'Abandon Cart', $hash, 'cart', 5),
            'template' => $ledger->keyFor('whatsapp', 'Abandon Wishlist', $hash, 'cart', 5),
            'contact' => $ledger->keyFor('whatsapp', 'Abandon Cart', str_repeat('a', 64), 'cart', 5),
            'subject type' => $ledger->keyFor('whatsapp', 'Abandon Cart', $hash, 'wishlist', 5),
            'subject id' => $ledger->keyFor('whatsapp', 'Abandon Cart', $hash, 'cart', 6),
        ] as $what => $other) {
            $this->assertNotSame($first, $other, sprintf(
                'Two nudges differing only in %s produce the same key, so they would deduplicate '
                . 'against each other and the merchant could not send the second at all.',
                $what
            ));
        }
    }
}

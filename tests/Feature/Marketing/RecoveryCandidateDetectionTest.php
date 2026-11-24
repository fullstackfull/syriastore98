<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingRecoveryCandidate;
use App\Models\Setting;
use App\Support\Marketing\RecoveryCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * MKT-007 — DETECTION AND THE SWEEP, and the fact that it sends nothing.
 *
 * THE FIRST THING THESE TESTS HOLD IS THAT NOTHING IS SENT. The owner's instruction is explicit:
 * automatic promotional sending stays disabled until the required consent enforcement is approved
 * and active. So every queued row is asserted `held`, with the reason, and the command is asserted
 * never to reach a sender. A feature that quietly started sending because a config default moved
 * would be the worst outcome of this slice, which is why it is the first property and not the last.
 *
 * THE SECOND IS "WITHOUT DUPLICATION", which is in the requirement's own title. An hourly sweep
 * that re-queued one basket would write twenty-four rows a day, and on the day sending is approved
 * it would send twenty-four messages about one basket. The unique key is the control and the
 * command relies on the INSERT failing rather than on a preceding read.
 *
 * AND THE THIRD IS THAT A RECOVERED SHOPPER IS NOT CHASED. A basket whose owner has since ordered
 * is not a recovery candidate; without that predicate this is a list of old rows rather than a
 * recovery list, and the message would be about a basket they already bought from.
 */
final class RecoveryCandidateDetectionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const COMMAND = 'marketing:detect-recovery-candidates';

    private function setAge(int $storeId, string $kind, $hours): void
    {
        Setting::putForStore(
            RecoveryCandidates::KINDS[$kind]['setting'],
            (string) $hours,
            $storeId,
            1
        );
    }

    private function customer($store, string $email)
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Rec',
            'last_name' => 'Fixture',
            'email' => $email,
            'store_id' => $store->id,
            'status' => 1,
        ]);
    }

    /** A basket, with an explicit age. `qty`/`price` are the real columns -- read off the migration. */
    private function basket($store, int $customerId, string $createdAt): int
    {
        return (int) DB::table('carts')->insertGetId([
            'customer_id' => $customerId,
            'store_id' => $store->id,
            'product_id' => 1,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** An order whose ledger row says nothing was paid. */
    private function unpaidOrder($store, array $extra = [])
    {
        $order = $this->makeOrder($store, $extra);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'unpaid', 'paid_amount' => 0.0, 'paid_at' => null]);

        return $order;
    }

    private function queued(int $storeId)
    {
        return MarketingRecoveryCandidate::query()->where('store_id', $storeId)->get();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Nothing is sent, and nothing fires unconfigured
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_store_that_has_configured_no_age_detects_nothing(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customer($store, 'a'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($store, (int) $customer->id, now()->subYear()->toDateTimeString());

        $this->assertSame([], RecoveryCandidates::configuredFor((int) $store->id));
        $this->assertSame([], RecoveryCandidates::forStore((int) $store->id),
            'a year-old basket was detected for a store whose merchant set no age — a default has '
            .'been invented, which this slice exists not to do');

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(0, $this->queued((int) $store->id));
    }

    public function test_no_default_age_appears_anywhere_in_the_detector(): void
    {
        $source = $this->sourceWithoutComments(app_path('Support/Marketing/RecoveryCandidates.php'));

        preg_match_all('/\?\?\s*([0-9]+)/', $source, $matches);

        $this->assertSame([], $matches[1],
            'RecoveryCandidates gained a numeric fallback: '.implode(', ', $matches[1]));
    }

    public function test_every_queued_candidate_is_held_with_a_reason(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'b'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $rows = $this->queued((int) $store->id);

        $this->assertCount(1, $rows);

        foreach ($rows as $row) {
            $this->assertSame(MarketingRecoveryCandidate::STATE_HELD, $row->send_state,
                'a candidate was queued as anything other than held. Automatic promotional sending '
                .'is OFF by owner decision until consent enforcement is approved and active.');

            $this->assertSame(MarketingRecoveryCandidate::HELD_CONSENT_OFF, $row->held_reason,
                'the row does not say WHICH gate held it, so "nothing was sent" is an inference '
                .'rather than a fact a reader can check');
        }
    }

    public function test_the_command_reaches_no_sender(): void
    {
        /*
         * A SOURCE ASSERTION, and the one in this file that is worth its weight. The behavioural
         * tests above prove nothing was sent TODAY, with both gates off; this states that the
         * command has no path to a sender at all, so flipping a config value cannot start it
         * sending. Comments stripped, because this file and the command both discuss sending at
         * length and a census that reads prose can be satisfied by it.
         */
        $source = $this->sourceWithoutComments(app_path('Console/Commands/DetectRecoveryCandidates.php'));

        /*
         * THE VOCABULARY IS SHARED WITH `ConsentLedgerCensusTest::SENDER_MARKERS` rather than
         * restated. Review found this list and that one each missing names the other had, which is
         * what two copies of a security vocabulary always do: the short one stops catching things
         * and still passes. `sendEmailTemplate` and `NudgeGate` -- the project's real first-party
         * senders in `CartController`'s own recovery click -- were missing from both.
         */
        foreach (\Tests\Unit\Marketing\ConsentLedgerCensusTest::SENDER_MARKERS as $sender) {
            $this->assertStringNotContainsString($sender, $source,
                "the recovery sweep references `{$sender}`. It must detect and queue only: "
                .'automatic promotional sending is disabled by owner decision, and a sweep that can '
                .'send is one config value away from sending a backlog.');
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Abandoned baskets
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_basket_younger_than_the_age_is_not_a_candidate(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'c'.$this->uniqueFixtureRef().'@example.test');

        $this->basket($store, (int) $customer->id, now()->subHours(2)->toDateTimeString());

        $this->assertSame([], RecoveryCandidates::forStore((int) $store->id),
            'a two-hour-old basket was called abandoned against a 24-hour age');
    }

    public function test_a_shopper_who_ordered_since_is_not_chased(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);

        $recovered = $this->customer($store, 'd'.$this->uniqueFixtureRef().'@example.test');
        $stillGone = $this->customer($store, 'e'.$this->uniqueFixtureRef().'@example.test');

        $this->basket($store, (int) $recovered->id, now()->subDays(3)->toDateTimeString());
        $this->basket($store, (int) $stillGone->id, now()->subDays(3)->toDateTimeString());

        /*
         * THEY CAME BACK AND *BOUGHT*, and the fixture has to pay for it. An order alone is not
         * recovery: a shopper who started a checkout and never paid is still worth following up,
         * and the detector's predicate requires the ledger row to be out of the unpaid statuses.
         * Written as an unpaid order first, which is how the over-broad predicate was found.
         */
        $paid = $this->makeOrder($store, [
            'customer_id' => $recovered->id,
            'is_guest' => 0,
            'order_date' => now()->subDay()->toDateTimeString(),
        ]);

        DB::table('store_order_financials')
            ->where('order_id', $paid->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()->subDay()]);

        $ids = array_column(RecoveryCandidates::forStore((int) $store->id), 'customer_id');

        $this->assertSame([(int) $stillGone->id], $ids,
            'a shopper who has ordered since leaving the basket is still in the recovery list');
    }

    /**
     * AN ABANDONED CHECKOUT IS NOT RECOVERY, and this is the defect the two-kinds case exposed.
     *
     * The first version of the detector read "has ordered since" as "has an order row since", so a
     * shopper who left a basket and then started a checkout they never paid for was treated as
     * RECOVERED and dropped from the basket list. That is the opposite of the truth: they are
     * further down the funnel and MORE worth following up.
     *
     * The predicate now requires the order's ledger row to be out of the same unpaid statuses that
     * make an order an `incomplete_payment` candidate, so the two kinds agree with each other
     * instead of one silently suppressing the other.
     */
    public function test_an_unpaid_order_since_the_basket_does_not_count_as_recovery(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'u'.$this->uniqueFixtureRef().'@example.test');

        /*
         * A DECOY WHOSE ONLY JOB IS TO MAKE THE IDS DISAGREE, and it was added because this test
         * SURVIVED a mutation that broke the join key. With one tenant and one order, `store_id` is
         * 1 and the order's id is 1, so joining the ledger on `orders.store_id` instead of
         * `orders.id` matched by COINCIDENCE and the test passed against a join that was wrong --
         * the id-collision false reading this codebase keeps producing.
         *
         * This order is PAID and dated BEFORE the basket, so the correct predicate ignores it (the
         * date comparison excludes it) while a join on `store_id` would find its ledger row, read
         * "paid", and suppress the basket. It also pushes the real order's id past 1.
         */
        $decoy = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDays(30)->toDateTimeString(),
        ]);

        DB::table('store_order_financials')
            ->where('order_id', $decoy->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()->subDays(30)]);

        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->unpaidOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDay()->toDateTimeString(),
        ]);

        $kinds = array_column(RecoveryCandidates::forStore((int) $store->id), 'kind');

        $this->assertContains(RecoveryCandidates::KIND_BASKET, $kinds,
            'an unpaid checkout suppressed the abandoned basket, so the shopper most worth '
            .'following up fell out of the basket list');
    }

    /**
     * AN ORDER THE LEDGER CANNOT SEE STILL COUNTS AS RECOVERY, and the asymmetry is the point.
     *
     * FOUND BY INDEPENDENT REVIEW, not by this suite. The first fix for the unpaid-checkout defect
     * used an INNER JOIN to `store_order_financials`, so an order with NO ledger row could not
     * satisfy the subquery at all -- and `OrderObserver::created()` swallows an exception from
     * `OrderFinancialService::createForOrder()`, so such orders exist (its own docblock says the
     * order commits without one). A shopper who BOUGHT would then have read as not recovered and
     * been chased about the basket they bought from: the one outcome the predicate exists to
     * prevent.
     *
     * A MISSING ROW IS "UNKNOWN", NOT "UNPAID", and the two readings are not symmetric: treating
     * unknown as unpaid sends a wrong message to a real customer, treating it as bought merely
     * declines to send one. For a feature whose purpose is messaging shoppers, the wrong message is
     * the worse error.
     *
     * THE RESIDUE IS ASSERTED TOO, in the second half: such an order is invisible to the
     * incomplete-payment half as well, so nothing follows it up. That is the swallowed observer
     * exception's doing rather than this slice's, and `GAP-226` records it -- but a test that left
     * it unsaid would imply the order was handled.
     */
    public function test_an_order_the_ledger_cannot_see_still_counts_as_recovery(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $customer = $this->customer($store, 'v'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDay()->toDateTimeString(),
        ]);

        /*
         * THE ROW IS REMOVED, NOT OMITTED. The observer writes one for every order, so the only
         * faithful way to reproduce "the ledger cannot see this order" is to delete it -- the same
         * fixture `MerchantAnalyticsFiguresTest::orderTheLedgerCannotSee()` uses, for the same
         * reason.
         */
        DB::table('store_order_financials')->where('order_id', $order->id)->delete();

        $kinds = array_column(RecoveryCandidates::forStore((int) $store->id), 'kind');

        $this->assertNotContains(RecoveryCandidates::KIND_BASKET, $kinds,
            'an order whose ledger row is missing was read as NOT a purchase, so the shopper would '
            .'be chased about a basket they may well have bought from');

        $this->assertNotContains(RecoveryCandidates::KIND_PAYMENT, $kinds,
            'premise for GAP-226: an order the ledger cannot see is invisible to the '
            .'incomplete-payment half too, so nothing follows it up. Asserted so the consequence is '
            .'on the record rather than implied.');
    }

    public function test_an_order_placed_before_the_basket_does_not_count_as_recovery(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'f'.$this->uniqueFixtureRef().'@example.test');

        // A PAID order from BEFORE the basket was created. The basket is still abandoned: a purchase
        // last month does not recover a basket left yesterday, and a predicate with no date
        // comparison would wrongly exclude every repeat customer.
        $old = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDays(30)->toDateTimeString(),
        ]);

        DB::table('store_order_financials')
            ->where('order_id', $old->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()->subDays(30)]);

        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->assertCount(1, RecoveryCandidates::forStore((int) $store->id),
            'a repeat customer\'s older order made their new abandoned basket invisible');
    }

    public function test_a_basket_with_no_customer_is_skipped(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);

        // `carts.customer_id` is an integer defaulting to 0, so 0 means NOBODY -- not customer 0.
        $this->basket($store, 0, now()->subDays(3)->toDateTimeString());

        $this->assertSame([], RecoveryCandidates::forStore((int) $store->id),
            'a basket belonging to nobody was queued; there is nobody to recover it with');
    }

    public function test_another_stores_basket_is_not_a_candidate_here(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setAge((int) $mine->id, RecoveryCandidates::KIND_BASKET, 24);

        $foreign = $this->customer($theirs, 'g'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($theirs, (int) $foreign->id, now()->subDays(3)->toDateTimeString());

        $this->assertSame([], RecoveryCandidates::forStore((int) $mine->id),
            'another store\'s basket appeared in this store\'s recovery list');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Incomplete payments
     * ---------------------------------------------------------------------------------------------
     */

    public function test_an_unpaid_order_older_than_the_age_is_a_candidate(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $customer = $this->customer($store, 'h'.$this->uniqueFixtureRef().'@example.test');
        $order = $this->unpaidOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDays(3)->toDateTimeString(),
        ]);

        $candidates = RecoveryCandidates::forStore((int) $store->id);

        $this->assertCount(1, $candidates);
        $this->assertSame(RecoveryCandidates::KIND_PAYMENT, $candidates[0]['kind']);
        $this->assertSame((int) $order->id, $candidates[0]['subject_id']);
        $this->assertSame((int) $customer->id, $candidates[0]['customer_id']);
    }

    public function test_a_paid_order_is_not_a_candidate(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $order = $this->makeOrder($store, ['order_date' => now()->subDays(3)->toDateTimeString()]);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $this->assertSame([], RecoveryCandidates::forStore((int) $store->id),
            'a paid order was listed as an incomplete payment');
    }

    public function test_a_part_paid_order_is_a_different_conversation_and_is_not_listed(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $order = $this->makeOrder($store, ['order_date' => now()->subDays(3)->toDateTimeString()]);

        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'partially_paid', 'paid_amount' => 40.0, 'paid_at' => now()]);

        /*
         * MONEY HAS ARRIVED, so "you did not pay" is the wrong message -- the right one is "you
         * still owe X", which is a different template and a different decision. Stated here so the
         * exclusion is a choice on the record rather than an oversight.
         */
        $this->assertSame([], RecoveryCandidates::forStore((int) $store->id),
            'a part-paid order was listed as if nothing had been paid');
    }

    public function test_a_guest_order_is_listed_with_no_customer(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $this->unpaidOrder($store, [
            'customer_id' => 0,
            'is_guest' => 1,
            'order_date' => now()->subDays(3)->toDateTimeString(),
        ]);

        $candidates = RecoveryCandidates::forStore((int) $store->id);

        /*
         * A REAL UNPAID ORDER THE MERCHANT SHOULD SEE. Dropping it because a guest placed it would
         * hide money that never arrived; whether anybody can be messaged about it is the send step's
         * problem, and the send step is off.
         */
        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]['customer_id']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Without duplication
     * ---------------------------------------------------------------------------------------------
     */

    public function test_running_the_sweep_again_does_not_queue_the_same_basket_twice(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'i'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND)->assertExitCode(0);
        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(1, $this->queued((int) $store->id),
            'the hourly sweep queued the same basket on every tick — twenty-four rows a day, and '
            .'twenty-four messages about one basket on the day sending is approved');
    }

    public function test_the_first_detection_time_is_not_refreshed_on_a_later_sweep(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $customer = $this->customer($store, 'j'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $first = $this->queued((int) $store->id)->first();
        $firstSeen = $first->detected_at;

        DB::table('marketing_recovery_candidates')
            ->where('id', $first->id)
            ->update(['detected_at' => now()->subDays(2)]);

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $after = MarketingRecoveryCandidate::query()->where('id', $first->id)->first();

        /*
         * `detected_at` IS WHEN IT WAS FIRST SEEN. Refreshing it on every tick would make the
         * queue's age always "now", destroying the one piece of information a deliberate backlog
         * release would need.
         */
        $this->assertTrue($after->detected_at->lt($firstSeen),
            'a later sweep refreshed detected_at, so the queue can no longer tell how old a '
            .'candidate is');
    }

    public function test_two_kinds_for_one_customer_are_two_candidates(): void
    {
        [, $store] = $this->makeTenant();
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
        $this->setAge((int) $store->id, RecoveryCandidates::KIND_PAYMENT, 24);

        $customer = $this->customer($store, 'k'.$this->uniqueFixtureRef().'@example.test');

        $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());
        $this->unpaidOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => now()->subDays(3)->toDateTimeString(),
        ]);

        $this->artisan(self::COMMAND)->assertExitCode(0);

        /*
         * An abandoned basket and an unpaid order are two different things to follow up, even for
         * one person. A dedup key that collapsed them would hide one of the merchant's two problems.
         */
        $this->assertCount(2, $this->queued((int) $store->id));
    }

    /**
     * EVERY CONFIGURED STORE IS SWEPT, not just the first.
     *
     * WRITTEN BECAUSE A MUTATION SURVIVED. `array_slice($storeIds, 0, 1)` passed the cross-tenant
     * case next door, because that case configures ONE store -- so slicing a one-element list
     * changes nothing and the test could not tell "visits every store" from "visits the first".
     * On a platform of thousands the mutation would mean one merchant's alerts work and nobody
     * else's, which is exactly the shape of defect that reads as "it works for me".
     */
    public function test_every_configured_store_is_swept_not_just_the_first(): void
    {
        $stores = [];

        foreach ([1, 2, 3] as $n) {
            [, $store] = $this->makeTenant();
            $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, 24);
            $customer = $this->customer($store, 's'.$n.$this->uniqueFixtureRef().'@example.test');
            $this->basket($store, (int) $customer->id, now()->subDays(3)->toDateTimeString());
            $stores[] = $store;
        }

        $this->artisan(self::COMMAND)->assertExitCode(0);

        foreach ($stores as $store) {
            $this->assertCount(1, $this->queued((int) $store->id),
                'store '.$store->id.' was not swept — the command stopped early or visited one store');
        }
    }

    /**
     * THE DETECTOR REFUSES AN OUT-OF-RANGE AGE ITSELF, not only through the form's validator.
     *
     * ALSO WRITTEN BECAUSE A MUTATION SURVIVED. Removing `RecoveryCandidates`' own `1..8760` bound
     * passed the screen test, because the controller validates before storing -- so the detector's
     * bound is unreachable from the form and the screen test was answering for it. That is the same
     * shape as ANA-009's `TrimStrings` case: a control whose only test is satisfied by a different
     * control.
     *
     * The bound still matters, because a `settings` row can arrive from a restored dump, a hand
     * edit, or a future writer with a wider validator -- and a 100,000-hour window is a full-table
     * scan on every hourly tick.
     */
    public function test_a_stored_age_out_of_range_is_refused_by_the_detector(): void
    {
        [, $store] = $this->makeTenant();

        foreach (['0', '-5', '100000', 'soon', ''] as $bad) {
            $this->setAge((int) $store->id, RecoveryCandidates::KIND_BASKET, $bad);

            $this->assertSame([], RecoveryCandidates::configuredFor((int) $store->id),
                'a stored age of '.var_export($bad, true).' was accepted by the detector');
        }
    }

    public function test_one_stores_candidate_is_not_queued_against_another(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setAge((int) $mine->id, RecoveryCandidates::KIND_BASKET, 24);

        $customer = $this->customer($mine, 'l'.$this->uniqueFixtureRef().'@example.test');
        $this->basket($mine, (int) $customer->id, now()->subDays(3)->toDateTimeString());

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertCount(1, $this->queued((int) $mine->id));
        $this->assertCount(0, $this->queued((int) $theirs->id),
            'a candidate was queued into a store that configured nothing and owns no basket');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The schedule
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_sweep_is_actually_scheduled(): void
    {
        /*
         * A COMMAND NOBODY RUNS IS NOT A FEATURE, and for MKT-007 that is the whole measurement:
         * the requirement's complaint was that nothing looks unless a person remembers to.
         * Comments stripped, because `routes/console.php` names this command in prose.
         */
        $source = $this->sourceWithoutComments('routes/console.php');

        $this->assertStringContainsString("Schedule::command('".self::COMMAND."')", $source,
            'the recovery sweep is not on the schedule, so detection is still manual');

        $this->assertMatchesRegularExpression(
            "/Schedule::command\('".preg_quote(self::COMMAND, '/')."'\)[^;]*->withoutOverlapping\(\)/",
            $source,
            'the sweep may overlap itself; two concurrent sweeps race on the dedup insert'
        );
    }
}

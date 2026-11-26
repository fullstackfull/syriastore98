<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Services\SaasRevenueFigures;
use App\Support\Operator\SettledPlanOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-231 — THE SETTLED-SPELLING DERIVATION, AND THE TWO COMPARATORS THAT MUST NOT DISAGREE.
 * =================================================================================================
 *
 * `SettledPlanOrders::isSettled()` deliberately repeats what `SaasRevenueFigures::isSettled()`
 * does — `strtolower` then `trim` against the same public constant — because that helper is
 * `private` and making it public would be editing another workstream's service to save a line.
 * Duplicated logic drifts, so this file pins the two together in BOTH directions over every
 * spelling the application's own census classifies.
 *
 * AND IT PINS THE ENGINE DIVERGENCE THAT MADE THE DERIVATION NECESSARY. The naive fix is
 * `whereIn('payment_status', SaasRevenueFigures::SETTLED_PLAN_STATUSES)`. On MariaDB 10.11 that
 * matches a stored `'Approved'`, because `utf8mb4_unicode_ci` is case-insensitive — measured, 5
 * checks, by `tests/Support/Security/bin/verify-plan-order-id-uniqueness-on-mysql.php`. On the
 * suite's SQLite it does NOT. This file asserts the SQLite half, so the pair of measurements is
 * complete and the next reader can see why the predicate is not the obvious one.
 */
final class SettledPlanOrderSpellingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private \App\Models\User $owner;
    private \App\Models\Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        /* REAL ROWS, because `plan_orders.user_id` and `.store_id` carry foreign keys -- a literal
           1 fails on insert, which is how this file's first draft failed for a fixture reason
           rather than a code one. */
        [$this->owner, $this->store] = $this->makeTenant();
    }

    /**
     * Every spelling `PlanOrderSettledStatusCensusTest` classifies, plus the capitalised variants
     * the application really writes, with the verdict each must get.
     *
     * NOT A COPY OF THAT CENSUS'S ARRAY — it is `private` there. These are its classifications as
     * its own comments state them, and `test_premise_this_list_matches_the_declared_constant`
     * checks the settled half against the public constant so the two cannot silently diverge.
     */
    private const VERDICTS = [
        'success' => true,
        'succeeded' => true,
        'approved' => true,
        'Succeeded' => true,   // 9 literal writers
        'Approved' => true,    // BankTransferController::orderapprove(), the operator's own action
        'SUCCESS' => true,
        'succeeded ' => true,  // PAD SPACE: a trailing space is ignored by the engine
        /*
         * A LEADING SPACE IS SETTLED HERE, AND THAT ROW WAS WRONG IN THIS FILE'S FIRST DRAFT.
         *
         * I wrote `false`, importing the rule `UserPolicy::isOperator()` follows for `users.type`:
         * `rtrim` and deliberately not `trim`, because MySQL's PAD SPACE folds a TRAILING space and
         * does NOT fold a leading one, so an application that trimmed both ends would call a row
         * the ENGINE refuses an operator. This test failed and it was right to.
         *
         * That rule does not transfer, because the comparison it protects does not exist here.
         * `SaasRevenueFigures::isSettled()` classifies values it has ALREADY read out of the
         * column -- it groups by `payment_status` in SQL and decides in PHP -- so there is no SQL
         * equality for it to agree or disagree with, and `trim()` is a defensible leniency about
         * dirty data rather than a divergence from the engine.
         *
         * And this slice's own predicate is immune either way: `spellingsInUse()` returns the
         * spellings AS STORED, so a stored `' success'` is classified in PHP and then matched
         * verbatim. A trimmed value is never compared against an untrimmed column.
         * `test_a_leading_space_spelling_is_matched_as_stored` drives that end to end.
         */
        ' success' => true,
        'pending' => false,
        'rejected' => false,
        'failed' => false,
        'fail' => false,       // Sspay / Toyyibpay
        'pandding' => false,   // Sspay / Toyyibpay, sic
        '' => false,
    ];

    #[Test]
    public function test_premise_this_list_matches_the_declared_constant(): void
    {
        $settledHere = array_keys(array_filter(self::VERDICTS));

        $lowered = array_values(array_unique(array_map(
            fn (string $s): string => strtolower(trim($s)),
            $settledHere
        )));

        sort($lowered);

        $declared = SaasRevenueFigures::SETTLED_PLAN_STATUSES;
        sort($declared);

        $this->assertSame($declared, $lowered,
            'this file\'s settled list no longer reduces to SETTLED_PLAN_STATUSES, so one of the '
            . 'two has moved and the verdicts below are measuring the wrong thing.');
    }

    #[Test]
    public function test_the_comparator_gives_the_declared_verdict_for_every_spelling(): void
    {
        foreach (self::VERDICTS as $spelling => $expected) {
            $this->assertSame($expected, SettledPlanOrders::isSettled((string) $spelling),
                sprintf('isSettled(%s) answered the wrong way.', var_export($spelling, true)));
        }

        $this->assertFalse(SettledPlanOrders::isSettled(null), 'NULL is not settled.');
    }

    /**
     * THE TWO COMPARATORS AGREE, asserted through reflection because the other one is private.
     * If `SaasRevenueFigures` ever changes how it normalises, this fails and somebody decides
     * deliberately rather than leaving the operator's rankings and the revenue screen disagreeing
     * about one row.
     */
    #[Test]
    public function test_the_two_comparators_agree_on_every_spelling(): void
    {
        $theirs = new \ReflectionMethod(SaasRevenueFigures::class, 'isSettled');
        $theirs->setAccessible(true);

        $this->assertTrue($theirs->isPrivate(),
            'SaasRevenueFigures::isSettled() is no longer private — call it directly and delete '
            . 'the duplicate in SettledPlanOrders rather than pinning two copies together.');

        foreach (array_keys(self::VERDICTS) as $spelling) {
            $this->assertSame(
                $theirs->invoke(null, (string) $spelling),
                SettledPlanOrders::isSettled((string) $spelling),
                sprintf('the two settled comparators disagree about %s.', var_export($spelling, true))
            );
        }
    }

    /**
     * THE NAIVE PREDICATE IS WRONG ON THIS ENGINE — which is the whole case for the derivation.
     *
     * A stored `'Approved'` is settled. `whereIn` against the lower-cased constant does not find it
     * on SQLite; the derived list does. The MariaDB counterpart (where the naive predicate DOES
     * find it, so the bug is invisible there) is measured by the bin script named in the class
     * docblock.
     */
    #[Test]
    public function test_the_naive_lowercased_predicate_misses_a_capitalised_status_on_this_engine(): void
    {
        $plan = $this->makePlan();
        $this->makePlanOrder($plan, 'Approved');

        $naive = PlanOrder::query()
            ->whereIn('payment_status', SaasRevenueFigures::SETTLED_PLAN_STATUSES)
            ->count();

        $derived = PlanOrder::query()
            ->whereIn('payment_status', SettledPlanOrders::spellingsInUse())
            ->count();

        $this->assertSame(0, $naive,
            'SQLite now matches a capitalised status against a lower-cased IN(). If the suite\'s '
            . 'engine or its collation changed, re-read whether the derivation is still needed — '
            . 'but do NOT conclude it is unnecessary from this test alone, because production is '
            . 'MySQL and the two engines disagreed here by construction.');

        $this->assertSame(1, $derived,
            'the derived spelling list failed to find a settled row it was built to find.');
    }

    #[Test]
    public function test_the_derivation_returns_only_settled_spellings_actually_present(): void
    {
        $plan = $this->makePlan();

        foreach (['succeeded', 'Approved', 'pending', 'failed', 'pandding'] as $status) {
            $this->makePlanOrder($plan, $status);
        }

        $this->assertSame(['Approved', 'succeeded'], SettledPlanOrders::spellingsInUse(),
            'the derivation returned something other than the settled spellings present, sorted.');
    }

    /** An empty table is an empty list, not an error and not a default. */
    #[Test]
    public function test_an_empty_table_yields_no_spellings_and_matches_no_row(): void
    {
        $this->assertSame([], SettledPlanOrders::spellingsInUse());

        $this->assertSame(0, PlanOrder::query()
            ->whereIn('payment_status', SettledPlanOrders::spellingsInUse())
            ->count());
    }

    /**
     * END TO END for the leading-space spelling: it is classified settled in PHP, returned AS
     * STORED, and then matched verbatim by the predicate. This is what makes the `trim()` question
     * above moot for this slice rather than merely answered.
     */
    #[Test]
    public function test_a_leading_space_spelling_is_matched_as_stored(): void
    {
        $plan = $this->makePlan();
        $this->makePlanOrder($plan, ' success');

        $this->assertSame([' success'], SettledPlanOrders::spellingsInUse(),
            'the spelling was normalised on its way out, so it would no longer match the column.');

        $this->assertSame(1, PlanOrder::query()
            ->whereIn('payment_status', SettledPlanOrders::spellingsInUse())
            ->count());
    }

    /** A spelling present but UNSETTLED must not leak into the list. */
    #[Test]
    public function test_a_table_of_only_failures_yields_no_spellings(): void
    {
        $plan = $this->makePlan();

        foreach (['pending', 'failed', 'rejected', 'pandding'] as $status) {
            $this->makePlanOrder($plan, $status);
        }

        $this->assertSame([], SettledPlanOrders::spellingsInUse());
    }

    /**
     * WHY THE `EXISTS`-VS-JOIN CHOICE CANNOT BE PINNED BY A TEST, established rather than left as a
     * silent gap in the mutation run.
     *
     * Replacing `SettledPlanOrders::redeemedAgainstSettledOrder()`'s `EXISTS` with a join-shaped
     * subquery is NOT caught by any case in this slice — measured. The two differ only when two
     * `plan_orders` rows share an `order_id`, because that is when a join multiplies a redemption
     * and `EXISTS` does not. And that state cannot be created: `order_id` is UNIQUE, and this case
     * proves the suite's own engine enforces it, so the distinguishing fixture is impossible here.
     *
     * That is the whole argument for `EXISTS` rather than a join. The index is declared by
     * `2023_12_11_102124_create_plan_orders_table.php`, no later migration drops it, and on a real
     * MariaDB 10.11 it materialises and is enforced
     * (`tests/Support/Security/bin/verify-plan-order-id-uniqueness-on-mysql.php`, 5 checks, 0
     * failed). What none of that establishes is the LIVE table — CLAUDE.md records that the
     * migrations are not the schema, and GATE F's promotion is a no-op against production by
     * design. `EXISTS` is correct whether or not the live index is there, so the one thing that
     * cannot be verified is the one thing it does not depend on.
     */
    #[Test]
    public function test_premise_a_duplicate_order_id_is_refused_so_the_join_mutant_is_unreachable(): void
    {
        $plan = $this->makePlan();
        $first = $this->makePlanOrder($plan, 'succeeded');

        $refused = false;

        try {
            PlanOrder::create([
                'order_id' => $first->order_id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'price' => 49,
                'price_currency' => 'USD',
                'txn_id' => 'TX-' . $this->uniqueFixtureRef(),
                'user_id' => $this->owner->id,
                'store_id' => $this->store->id,
                'payment_status' => 'succeeded',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $refused = true;
        }

        $this->assertTrue($refused,
            '`plan_orders.order_id` no longer refuses a duplicate on this engine. That makes the '
            . 'join-vs-EXISTS distinction REACHABLE and therefore testable: write the case that '
            . 'two orders sharing an id inflate a join and not an EXISTS, and stop relying on this '
            . 'premise.');
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'P-' . $this->uniqueFixtureRef(),
            'price' => 49,
            'duration' => 'Month',
            'themes' => '[]',
            'storage_limit' => '100',
        ]);
    }

    private function makePlanOrder(Plan $plan, string $paymentStatus): PlanOrder
    {
        return PlanOrder::create([
            'order_id' => 'PO-' . $this->uniqueFixtureRef(),
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => 49,
            'price_currency' => 'USD',
            'txn_id' => 'TX-' . $this->uniqueFixtureRef(),
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'payment_status' => $paymentStatus,
        ]);
    }
}

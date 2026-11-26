<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\User;
use App\Services\SaasRevenueFigures;
use App\Support\Saas\SubscriptionLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SAAS-002 — RENEWAL DOES NOT ACCUMULATE, AND SAAS-007's CLASSIFIER NOW AGREES WITH THE PREDICATE.
 * =================================================================================================
 *
 * TWO MEASUREMENTS, ONE OF THEM AN OWNER QUESTION.
 *
 * -------------------------------------------------------------------------------------------------
 * 1. RENEWAL RESETS THE EXPIRY FROM NOW. IT DOES NOT ADD TO WHAT IS LEFT.
 * -------------------------------------------------------------------------------------------------
 *
 * `User::assignPlan()` writes `plan_expire_date = Carbon::now()->addMonths(1)` (or `addYears(1)`)
 * unconditionally, with no reference to the value already stored. So a merchant with 20 days left
 * who renews early gets 1 month from TODAY and the 20 days are discarded — they have paid for two
 * months and received one month and ten days.
 *
 * THIS IS MEASURED AND IT IS NOT NEW: `Services\Payments\Reconciliation\ReconciliationOutcome`
 * already records it in prose — "`assignPlan()` SETS the plan and recomputes the expiry from NOW --
 * it does not accumulate -- so 'apply the payment late' and 'give them what they paid for' are not
 * the same operation" — and `PlanPurchaseSettlement` repeats it. Both use it as a reason to REFUSE
 * a late settlement. Neither treats it as a renewal defect, and nothing anywhere asserts it.
 *
 * WHY THIS FILE PINS IT RATHER THAN CHANGING IT. Whether renewal accumulates is a BUSINESS RULE.
 * `SAAS-002` lists "التجديد" (renewal) among the approved decisions and says nothing about what a
 * renewal does to unused time, and CLAUDE.md §2 reserves "material changes to prices/payments/
 * stock/business rules" to the owner. Making it accumulate would also change what a gateway
 * callback grants for a given payment, which is a money path.
 *
 * THE QUESTION FOR THE OWNER, in one line: when a merchant renews before their current period
 * ends, does the new period start TODAY (today's behaviour, discarding the unused days) or at the
 * END of the current period (accumulating)?
 *
 * So these cases record today's answer exactly, in both directions, so that whichever way the owner
 * decides, the change is deliberate and visible rather than a silent drift in a money path.
 *
 * -------------------------------------------------------------------------------------------------
 * 2. THE REVENUE CLASSIFIER AND THE LIFECYCLE PREDICATE PLACE EVERY ROW ON THE SAME SIDE.
 * -------------------------------------------------------------------------------------------------
 *
 * `SubscriptionLifecycleTest` asserts the boundary against `SaasRevenueFigures`' SQL expression
 * reproduced in PHP. That is one step removed from the figure. The last case here closes the gap by
 * driving `SaasRevenueFigures::forPlatform()` itself over the same fixtures and requiring its
 * `lapsed` bucket to hold exactly the merchants `SubscriptionLifecycle::hasLapsed()` says have
 * lapsed — the equivalence that the three auth sites used to break.
 */
final class SubscriptionRenewalTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-26 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        config(['saas.grace_period_days' => null]);
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    private function subscriber(Plan $plan, ?string $expires, array $attributes = []): User
    {
        return $this->makeMerchant(array_merge([
            'plan_id' => $plan->id,
            'plan_expire_date' => $expires,
        ], $attributes))->fresh();
    }

    // ---------------------------------------------------------------- renewal, pinned as it is

    /**
     * THE MEASUREMENT. 20 days left, renew the same monthly plan, and the stored expiry is one
     * month from TODAY — not one month from the old expiry.
     *
     * `Carbon::setTestNow()` because `assignPlan()` reads `Carbon::now()` directly; the assertion
     * is against a date computed the same way rather than a literal, so the case does not go stale
     * on 1 December.
     */
    #[Test]
    public function test_an_early_renewal_discards_the_unused_days(): void
    {
        CarbonImmutable::setTestNow($this->now());
        \Carbon\Carbon::setTestNow($this->now());

        try {
            $plan = $this->makePlan(['duration' => 'Month']);
            $remaining = $this->now()->addDays(20)->format('Y-m-d');
            $merchant = $this->subscriber($plan, $remaining);

            $this->assertSame($remaining, $merchant->plan_expire_date, 'premise: the days really are there');

            $merchant->assignPlan($plan->id);

            $after = User::withoutGlobalScopes()->find($merchant->id)->plan_expire_date;

            $this->assertSame(
                $this->now()->addMonths(1)->format('Y-m-d'),
                $after,
                'renewal resets the period from today -- OWNER QUESTION, see this file\'s docblock'
            );
            $this->assertNotSame(
                CarbonImmutable::parse($remaining)->addMonths(1)->format('Y-m-d'),
                $after,
                'and it is NOT the accumulating answer'
            );
        } finally {
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    /**
     * THE SAME FACT AS A QUANTITY: what one month's payment actually bought.
     *
     * The merchant had 20 days left and paid for a month. Service was extended from the old expiry
     * to the new one — ten days — so twenty of the thirty they paid for were already theirs and are
     * gone. Stated as a subtraction rather than as a date so that the size of the loss is the
     * assertion, which is the part an owner needs to decide on.
     */
    #[Test]
    public function test_a_months_payment_extends_service_by_only_the_days_not_already_held(): void
    {
        CarbonImmutable::setTestNow($this->now());
        \Carbon\Carbon::setTestNow($this->now());

        try {
            $plan = $this->makePlan(['duration' => 'Month']);
            $oldExpiry = $this->now()->addDays(20)->startOfDay();
            $merchant = $this->subscriber($plan, $oldExpiry->format('Y-m-d'));

            $merchant->assignPlan($plan->id);

            $newExpiry = CarbonImmutable::parse(
                User::withoutGlobalScopes()->find($merchant->id)->plan_expire_date
            )->startOfDay();

            $wholePeriod = $this->now()->startOfDay()->diffInDays($this->now()->addMonths(1)->startOfDay());
            $extension = $oldExpiry->diffInDays($newExpiry);

            $this->assertSame(
                20,
                $wholePeriod - $extension,
                'a month was paid for and only ' . $extension . ' days of new service were granted; '
                . 'the other ' . ($wholePeriod - $extension) . ' were days the merchant already held'
            );
            $this->assertGreaterThan(0, $extension, 'premise: the renewal did extend the period');
        } finally {
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    /** A renewal AFTER the period ended behaves the same way, which is the uncontroversial case. */
    #[Test]
    public function test_a_renewal_after_the_period_ended_starts_from_today(): void
    {
        CarbonImmutable::setTestNow($this->now());
        \Carbon\Carbon::setTestNow($this->now());

        try {
            $plan = $this->makePlan(['duration' => 'Month']);
            $merchant = $this->subscriber($plan, $this->now()->subDays(5)->format('Y-m-d'));

            $this->assertTrue(
                SubscriptionLifecycle::hasLapsed($merchant, $this->now()),
                'premise: this subscription really had lapsed'
            );

            $merchant->assignPlan($plan->id);

            $this->assertSame(
                $this->now()->addMonths(1)->format('Y-m-d'),
                User::withoutGlobalScopes()->find($merchant->id)->plan_expire_date
            );
        } finally {
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    /** A yearly plan is the same rule with a different period, and is where the loss is largest. */
    #[Test]
    public function test_an_early_renewal_of_a_yearly_plan_also_discards_the_remainder(): void
    {
        CarbonImmutable::setTestNow($this->now());
        \Carbon\Carbon::setTestNow($this->now());

        try {
            $plan = $this->makePlan(['duration' => 'Year', 'price' => 1200]);
            $merchant = $this->subscriber($plan, $this->now()->addMonths(5)->format('Y-m-d'));

            $merchant->assignPlan($plan->id);

            $this->assertSame(
                $this->now()->addYears(1)->format('Y-m-d'),
                User::withoutGlobalScopes()->find($merchant->id)->plan_expire_date,
                'five months of paid service were discarded'
            );
        } finally {
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    // ------------------------------------------------- the classifier and the predicate agree

    /**
     * SAAS-007 MEETS SAAS-002. The figure's `lapsed` bucket and the lifecycle predicate must hold
     * the same merchants, including on the boundary day itself — which is the exact disagreement
     * that cost a merchant a paid day at login.
     *
     * Five subscriptions either side of the boundary, driven through the real figure.
     */
    #[Test]
    public function test_the_revenue_figures_lapsed_bucket_holds_exactly_the_lapsed_subscriptions(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);

        $expiries = [
            $this->now()->subDays(30)->format('Y-m-d'),
            $this->now()->subDays(1)->format('Y-m-d'),
            $this->now()->format('Y-m-d'),
            $this->now()->addDays(1)->format('Y-m-d'),
            $this->now()->addDays(30)->format('Y-m-d'),
        ];

        $expectedLapsed = 0;
        foreach ($expiries as $expiry) {
            $merchant = $this->subscriber($plan, $expiry);

            if (SubscriptionLifecycle::hasLapsed($merchant, $this->now())) {
                $expectedLapsed++;
            }
        }

        $this->assertSame(2, $expectedLapsed, 'premise: the fixture really straddles the boundary');

        $figures = SaasRevenueFigures::forPlatform($this->now());

        $buckets = [];
        foreach ($figures['buckets'] as $bucket) {
            $buckets[$bucket['key']] = $bucket['count'];
        }

        $this->assertSame(
            $expectedLapsed,
            $buckets['lapsed'] ?? -1,
            'SaasRevenueFigures and SubscriptionLifecycle must place every subscription on the same '
            . 'side of the boundary; three auth sites used to disagree with both of them'
        );
        $this->assertSame(
            300.0,
            $figures['recurring']['mrr']['raw'],
            'and the three live ones -- including the one expiring today -- are the MRR'
        );
    }
}

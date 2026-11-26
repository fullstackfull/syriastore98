<?php

namespace Tests\Unit\Saas;

use App\Models\Plan;
use App\Models\User;
use App\Support\Saas\SubscriptionLifecycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SAAS-002 / SAAS-004 — ONE DEFINITION OF "WHERE IS THIS SUBSCRIPTION IN ITS LIFECYCLE".
 * ======================================================================================
 *
 * WHY THIS CLASS EXISTS, MEASURED BEFORE IT WAS WRITTEN. Four surfaces answered "is this
 * subscription expired?" with their own arithmetic, and two of them DISAGREED BY ONE DAY:
 *
 *   `Auth\AuthenticatedSessionController::store()`   `$datetime2->diff($datetime1)->format('%r%a') <= 0`
 *   `Auth\RegisteredUserController::store()` (x2)    the same expression, twice
 *   `Http\Middleware\SetLocate::handle()`            the same expression again
 *   `Services\SaasRevenueFigures::subscriptions()`   `users.plan_expire_date >= :today`  ->  'live'
 *   `resources/views/plans/index.blade.php`          `plan_expire_date < date('Y-m-d')`  ->  'Expired'
 *
 * On the expiry date itself the diff is 0, so `<= 0` is TRUE and the three auth sites call
 * `assignPlan(1)` and flash "Your Plan is expired." -- while `SaasRevenueFigures` counts the very
 * same row as `live` (its docblock states the rule: "the expiry date is the last day of the
 * subscription, so a plan expiring today is live today", pinned by
 * `PlatformRevenueFiguresTest::test_a_subscription_expiring_today_still_counts`) and the merchant's
 * own plan screen does NOT print `Expired`.
 *
 * DRIVEN, NOT INFERRED: a merchant on a `Month` plan with `plan_expire_date = date('Y-m-d')` was
 * logged in over HTTP and came back with `plan_id` 1 and `plan_expire_date` NULL, with
 * `session('error')` reading `Your Plan is expired.` -- on a day they had paid for.
 *
 * THE BOUNDARY THIS CLASS ADOPTS IS THE ONE THE PLATFORM ALREADY DOCUMENTS AND TESTS: the expiry
 * date is the last day of service. That is two of the three readings above, and it is the only one
 * with a stated rule behind it. The auth sites are the outliers and are corrected to call this
 * class; `LoginExpiryBoundaryTest` drives that over HTTP.
 *
 * THE GRACE PERIOD IS AN OWNER DECISION AND THIS CLASS DOES NOT INVENT ONE. Searched as an
 * IDENTIFIER (`grace_period`, `grace_days`, `graceDays`, `gracePeriod`) across `app/`, `database/`,
 * `routes/`, `resources/`, `config/` and `packages/workdo/`: ONE hit, `config/jwt.php`'s unrelated
 * `blacklist_grace_period`. (The bare word `grace` is the WRONG search; an earlier draft of this
 * docblock used it and its "zero times" was false -- the vendored WorkDo demo data contains people
 * called Grace. The lesson is CLAUDE.md's: a census keyed on a word that also occurs in prose.) So
 * `config('saas.grace_period_days')` ships as NULL, `graceDays()` returns null, and a lapsed
 * subscription reports `EXPIRED_GRACE_UNDEFINED` rather than being placed on one side or the other
 * of a boundary nobody has set. `restrictsNewSelling()` is then FALSE, with a named reason -- the
 * conservative answer, because the alternative is stopping a live merchant's sales on a number this
 * repository made up.
 */
class SubscriptionLifecycleTest extends TestCase
{
    private const TODAY = '2026-11-26';

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY . ' 13:45:00');
    }

    /**
     * A merchant row in memory. Nothing here touches the database.
     *
     * `forceFill`, NOT `new User([...])`, AND THAT COST TWO RED RUNS WORTH FINDING.
     * `users.trial_expire_date` IS NOT IN `User::$fillable` (the list runs `plan_id`,
     * `plan_expire_date`, `plan_is_active`, `requested_plan` and stops), so mass assignment DROPS
     * it silently — the two trial cases read `no_expiry`, and worse,
     * `test_an_expired_trial_is_no_longer_a_trial` PASSED while the attribute it names was never
     * set, which is this project's most expensive recurring defect in miniature.
     * `test_the_fixture_really_sets_the_trial_column` is the premise that keeps it honest.
     */
    private function merchant(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge(['type' => 'admin', 'plan_id' => 7], $attributes));

        return $user;
    }

    /**
     * THE PREMISE FOR EVERY TRIAL CASE BELOW. Without it they pass whether or not the fixture sets
     * the column, because "not trialling" is the answer to an unset trial AND to a drifted one.
     */
    #[Test]
    public function test_the_fixture_really_sets_the_trial_column(): void
    {
        $this->assertNotContains(
            'trial_expire_date',
            (new User())->getFillable(),
            'premise: this column is NOT mass-assignable, which is why the fixture force-fills'
        );
        $this->assertSame(
            '2026-12-05',
            $this->merchant(['trial_expire_date' => '2026-12-05'])->trial_expire_date
        );
    }

    private function recurringPlan(): Plan
    {
        return new Plan(['duration' => 'Month', 'price' => 1200]);
    }

    private function unlimitedPlan(): Plan
    {
        return new Plan(['duration' => 'Unlimited', 'price' => 0]);
    }

    // ---------------------------------------------------------------- the boundary

    /**
     * THE DEFECT THIS CLASS WAS WRITTEN FOR. The expiry date is the LAST DAY of service.
     *
     * Grounded in `SaasRevenueFigures::subscriptions()`'s own documented `>=`, not chosen here.
     */
    #[Test]
    public function test_a_subscription_expiring_today_is_still_active_today(): void
    {
        $user = $this->merchant(['plan_expire_date' => self::TODAY]);

        $this->assertSame(
            SubscriptionLifecycle::ACTIVE,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertFalse(SubscriptionLifecycle::hasLapsed($user, $this->now()));
    }

    #[Test]
    public function test_a_subscription_that_expired_yesterday_has_lapsed(): void
    {
        $user = $this->merchant(['plan_expire_date' => '2026-11-25']);

        $this->assertTrue(SubscriptionLifecycle::hasLapsed($user, $this->now()));
    }

    #[Test]
    public function test_a_subscription_expiring_tomorrow_is_active(): void
    {
        $user = $this->merchant(['plan_expire_date' => '2026-11-27']);

        $this->assertSame(
            SubscriptionLifecycle::ACTIVE,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertFalse(SubscriptionLifecycle::hasLapsed($user, $this->now()));
    }

    /**
     * A STORED `Y-m-d H:i:s` STILL COMPARES CORRECTLY, for the reason `SaasRevenueFigures` gives:
     * the comparison is lexicographic over ISO text, and `'2026-11-26 00:00:00' >= '2026-11-26'`.
     */
    #[Test]
    public function test_a_datetime_valued_expiry_on_the_expiry_day_is_still_active(): void
    {
        $user = $this->merchant(['plan_expire_date' => self::TODAY . ' 00:00:00']);

        $this->assertFalse(SubscriptionLifecycle::hasLapsed($user, $this->now()));
    }

    // ------------------------------------------------- no expiry, no plan, not a merchant

    /**
     * AN EMPTY EXPIRY IS "NO EXPIRY RECORDED", NOT "LAPSED" — and that is the one place this class
     * must NOT follow the auth sites, because `new \DateTime('')` is NOW and their `<= 0` therefore
     * read an empty column as expired. `SaasRevenueFigures` classifies the same value as `none`.
     */
    #[Test]
    public function test_an_empty_expiry_is_not_a_lapse(): void
    {
        foreach ([null, '', '   '] as $value) {
            $user = $this->merchant(['plan_expire_date' => $value]);

            $this->assertFalse(
                SubscriptionLifecycle::hasLapsed($user, $this->now()),
                'an expiry of ' . var_export($value, true) . ' is not a lapse'
            );
            $this->assertSame(
                SubscriptionLifecycle::NO_EXPIRY,
                SubscriptionLifecycle::stateFor($user, $this->unlimitedPlan(), $this->now())
            );
        }
    }

    #[Test]
    public function test_a_merchant_with_no_plan_is_in_its_own_state(): void
    {
        $user = $this->merchant(['plan_id' => null, 'plan_expire_date' => '2026-01-01']);

        $this->assertSame(
            SubscriptionLifecycle::NO_PLAN,
            SubscriptionLifecycle::stateFor($user, null, $this->now())
        );
        $this->assertFalse(
            SubscriptionLifecycle::restrictsNewSelling($user, null, $this->now()),
            'a merchant the platform has never put on a plan is not a merchant who stopped paying'
        );
    }

    /**
     * A SUBSCRIPTION IS A `type = 'admin'` ROW'S PROPERTY. Staff and the operator do not hold one:
     * `SaasRevenueFigures` filters `users.type = 'admin'` and `helper.php` grants a `super admin`
     * every module regardless of any plan row, so there is nothing for this to answer about them.
     */
    #[Test]
    public function test_staff_and_the_operator_hold_no_subscription(): void
    {
        foreach (['staff', 'super admin', 'client', ''] as $type) {
            $user = $this->merchant(["type" => $type, "plan_expire_date" => "2020-01-01"]);

            $this->assertSame(
                SubscriptionLifecycle::NOT_A_MERCHANT,
                SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now()),
                'type ' . var_export($type, true)
            );
            $this->assertFalse(SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()));
        }
    }

    #[Test]
    public function test_a_null_principal_is_not_a_merchant_and_restricts_nothing(): void
    {
        $this->assertSame(SubscriptionLifecycle::NOT_A_MERCHANT, SubscriptionLifecycle::stateFor(null, null, $this->now()));
        $this->assertFalse(SubscriptionLifecycle::hasLapsed(null, $this->now()));
        $this->assertFalse(SubscriptionLifecycle::restrictsNewSelling(null, null, $this->now()));
    }

    // ---------------------------------------------------------------- trials

    /**
     * A LIVE TRIAL IS ITS OWN STATE, ahead of the expiry arithmetic. `PlanController::planTrial()`
     * writes `trial_expire_date` AND calls `assignPlan()`, which recomputes `plan_expire_date` from
     * the plan's duration -- so a trialling merchant carries both dates and the trial is the one
     * that describes what they hold.
     */
    #[Test]
    public function test_a_live_trial_is_reported_as_a_trial(): void
    {
        $user = $this->merchant([
            'trial_expire_date' => '2026-12-05',
            'plan_expire_date' => '2026-12-26',
        ]);

        $this->assertSame(
            SubscriptionLifecycle::TRIALLING,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertFalse(SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()));
    }

    /** The trial's last day is the last day of the trial, for the same reason as the plan's. */
    #[Test]
    public function test_a_trial_ending_today_is_still_a_trial(): void
    {
        $user = $this->merchant(['trial_expire_date' => self::TODAY]);

        $this->assertSame(
            SubscriptionLifecycle::TRIALLING,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
    }

    #[Test]
    public function test_an_expired_trial_is_no_longer_a_trial(): void
    {
        $user = $this->merchant([
            'trial_expire_date' => '2026-11-25',
            'plan_expire_date' => '2026-11-25',
        ]);

        $this->assertNotSame(
            SubscriptionLifecycle::TRIALLING,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
    }

    // ------------------------------------------------- the grace period: an owner decision

    /**
     * THE SHIPPED CONFIGURATION NAMES NO GRACE PERIOD, so a lapsed subscription is reported as
     * lapsed-with-no-boundary-set and NOTHING is restricted.
     */
    #[Test]
    public function test_with_no_grace_period_configured_a_lapsed_subscription_restricts_nothing(): void
    {
        config(['saas.grace_period_days' => null]);

        $user = $this->merchant(['plan_expire_date' => '2026-01-01']);

        $this->assertNull(SubscriptionLifecycle::graceDays());
        $this->assertSame(
            SubscriptionLifecycle::EXPIRED_GRACE_UNDEFINED,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertFalse(SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()));
        $this->assertSame(
            SubscriptionLifecycle::REASON_GRACE_UNDEFINED,
            SubscriptionLifecycle::whyNotRestricted($user, $this->recurringPlan(), $this->now())
        );
    }

    /** That the key really is absent from the shipped config, so the case above is the live one. */
    #[Test]
    public function test_the_shipped_configuration_names_no_grace_period(): void
    {
        $config = require base_path('config/saas.php');

        $this->assertArrayHasKey('grace_period_days', $config);
        $this->assertNull(
            $config['grace_period_days'],
            'the grace period is an owner decision; a default here would be this repository inventing it'
        );
    }

    #[Test]
    public function test_inside_a_configured_grace_period_nothing_is_restricted(): void
    {
        config(['saas.grace_period_days' => 7]);

        // Expired 7 days ago: the last day of a 7-day grace.
        $user = $this->merchant(['plan_expire_date' => '2026-11-19']);

        $this->assertSame(7, SubscriptionLifecycle::graceDays());
        $this->assertSame(
            SubscriptionLifecycle::IN_GRACE,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertFalse(SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()));
    }

    #[Test]
    public function test_past_a_configured_grace_period_new_selling_is_restricted(): void
    {
        config(['saas.grace_period_days' => 7]);

        // Expired 8 days ago: one day past a 7-day grace.
        $user = $this->merchant(['plan_expire_date' => '2026-11-18']);

        $this->assertSame(
            SubscriptionLifecycle::PAST_GRACE,
            SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now())
        );
        $this->assertTrue(SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()));
        $this->assertNull(SubscriptionLifecycle::whyNotRestricted($user, $this->recurringPlan(), $this->now()));
    }

    #[Test]
    public function test_a_grace_period_of_zero_restricts_from_the_day_after_expiry(): void
    {
        config(['saas.grace_period_days' => 0]);

        $this->assertSame(
            SubscriptionLifecycle::ACTIVE,
            SubscriptionLifecycle::stateFor($this->merchant(['plan_expire_date' => self::TODAY]), $this->recurringPlan(), $this->now()),
            'zero grace does not move the expiry day itself'
        );
        $this->assertSame(
            SubscriptionLifecycle::PAST_GRACE,
            SubscriptionLifecycle::stateFor($this->merchant(['plan_expire_date' => '2026-11-25']), $this->recurringPlan(), $this->now())
        );
    }

    /**
     * A GRACE PERIOD THIS CLASS CANNOT READ AS A WHOLE NUMBER OF DAYS IS NOT A GRACE PERIOD.
     * `config()` values arrive from `.env` as strings and from a bad edit as anything at all;
     * treating `'soon'` as 0 would silently start restricting sales.
     */
    #[Test]
    public function test_an_unreadable_grace_period_is_treated_as_unset(): void
    {
        foreach (['soon', '', 'two weeks', -1, 1.5, [], true] as $value) {
            config(['saas.grace_period_days' => $value]);

            $this->assertNull(
                SubscriptionLifecycle::graceDays(),
                'grace of ' . var_export($value, true) . ' is not a whole number of days'
            );
            $this->assertFalse(
                SubscriptionLifecycle::restrictsNewSelling(
                    $this->merchant(['plan_expire_date' => '2026-01-01']),
                    $this->recurringPlan(),
                    $this->now()
                )
            );
        }
    }

    /** A NUMERIC STRING IS a whole number of days: that is how `.env` hands one over. */
    #[Test]
    public function test_a_numeric_string_grace_period_is_read(): void
    {
        config(['saas.grace_period_days' => '7']);

        $this->assertSame(7, SubscriptionLifecycle::graceDays());
    }

    /**
     * A STORED VALUE THAT IS NOT A DATE MUST NOT THROW ON A SELLING DOOR.
     *
     * FOUND BY SELF-REVIEW, NOT BY A FAILURE, and it is a 500 on a checkout. The grace arithmetic
     * needs a real date, so it calls `CarbonImmutable::parse()`. `users.plan_expire_date` is a
     * free-text `string` column -- `create_users_table` declares `$table->string(...)->nullable()`
     * -- and while `assignPlan()` only ever writes `isoFormat('YYYY-MM-DD')`, CLAUDE.md's rule is
     * that the migrations are not the schema and an import or a hand edit can put anything there.
     *
     * `'1999-99-99'` is the case that matters: it compares as EARLIER than today lexicographically,
     * so it passes the lapse test and reaches the parse, where it throws
     * `InvalidFormatException`. With a grace period configured that is an uncaught exception inside
     * `LapsedSubscriptionSellingGuard`, i.e. HTTP 500 on `payment.process`.
     *
     * The answer is the same fail-open direction the rest of this class takes: an expiry this class
     * cannot read is not a lapse it can act on, so nothing is restricted. Note that `'not-a-date'`
     * never reaches the parse at all -- `'n' > '2'`, so it compares as LATER than today and reads
     * as active -- which is why the test uses a value that is numerically early and calendrically
     * impossible.
     *
     * IT GETS ITS OWN STATE RATHER THAN BORROWING `EXPIRED_GRACE_UNDEFINED`. "The owner has not set
     * a grace period" and "this row's expiry is not a date" are opposite facts, and collapsing them
     * is the shape CLAUDE.md records costing real dispositions. The vocabulary already exists:
     * `SaasRevenueFigures` has `duration_unreadable` and `plan_missing` buckets for exactly this
     * reason.
     */
    #[Test]
    public function test_an_unparseable_expiry_does_not_throw_and_does_not_restrict(): void
    {
        config(['saas.grace_period_days' => 7]);

        /*
         * THE THIRD VALUE IS A DIFFERENT DEFECT AND IT IS THE WORSE ONE, found when this case first
         * ran. `'1999-99-99'` and `'2026-13-45'` THROW. `'0000-00-00'` — MySQL's zero-date sentinel,
         * which is what the server writes for an invalid date outside strict mode, and production is
         * MySQL — PARSES, to `-0001-11-30`, year **-1**. So it did not 500: it classified as
         * `past_grace` and would have stopped a merchant's sales because their expiry column held
         * MySQL's "no value". Measured with `CarbonImmutable::parse()` directly.
         *
         * `'2026-13-45'` IS NOT IN THIS LIST, and the premise assertion below is what moved it out.
         * It sorts AFTER today (`'13' > '11'`), so the string comparison reads it as not-yet-lapsed
         * and it never reaches the parse at all — the asymmetry this case's docblock describes. It
         * is asserted in the case that follows, with its own value, rather than left implied.
         */
        foreach (['1999-99-99', '0000-00-00', '0000-01-01'] as $value) {
            $user = $this->merchant(['plan_expire_date' => $value]);

            $this->assertTrue(
                $value < self::TODAY,
                'premise: ' . $value . ' really does compare as earlier than today, so it reaches '
                . 'the grace arithmetic rather than reading as active'
            );

            $this->assertSame(
                SubscriptionLifecycle::EXPIRY_UNREADABLE,
                SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now()),
                'an expiry of ' . $value . ' must be reported as unreadable, not parsed'
            );
            $this->assertSame(
                SubscriptionLifecycle::REASON_EXPIRY_UNREADABLE,
                SubscriptionLifecycle::whyNotRestricted($user, $this->recurringPlan(), $this->now()),
                'and the reason must say so, not "no grace period configured"'
            );
            $this->assertFalse(
                SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now()),
                'and must not stop the merchant selling'
            );
        }
    }

    /**
     * THE OTHER HALF OF THE ASYMMETRY, ASSERTED RATHER THAN IMPLIED.
     *
     * A garbage expiry that sorts AFTER today reads as ACTIVE and never reaches the parse. Both
     * values here are real shapes a free-text column can hold, and both sort high: `'2026-13-45'`
     * because `'13' > '11'`, `'not-a-date'` because `'n' > '2'`. The outcome is the same
     * fail-open direction — nothing restricted, nothing thrown — reached by a different route, and
     * it is recorded so that a future change to the comparison cannot alter it silently.
     */
    #[Test]
    public function test_a_garbage_expiry_that_sorts_after_today_reads_as_active(): void
    {
        config(['saas.grace_period_days' => 7]);

        foreach (['2026-13-45', 'not-a-date'] as $value) {
            $this->assertGreaterThan(
                self::TODAY,
                $value,
                'premise: ' . $value . ' really does sort after today, which is why it never parses'
            );

            $user = $this->merchant(['plan_expire_date' => $value]);

            $this->assertSame(
                SubscriptionLifecycle::ACTIVE,
                SubscriptionLifecycle::stateFor($user, $this->recurringPlan(), $this->now()),
                'an expiry of ' . $value . ' sorts high, so the cheap comparison reads it as live'
            );
            $this->assertFalse(
                SubscriptionLifecycle::restrictsNewSelling($user, $this->recurringPlan(), $this->now())
            );
        }
    }

    // ------------------------------------------------- the auth sites' preserved edge case

    /**
     * THE ONE BEHAVIOUR THE AUTH SITES HAD THAT THIS DELIBERATELY KEEPS: a RECURRING plan whose
     * expiry column is empty was downgraded by them, because `new \DateTime('')` is now. That is a
     * broken row rather than a lapse, and changing what the login does with it is a separate
     * question from the off-by-one -- so the auth-site predicate answers true for it and
     * `hasLapsed()`, which is about lifecycle rather than about those three call sites, does not.
     */
    #[Test]
    public function test_the_auth_site_predicate_keeps_treating_an_empty_expiry_as_a_downgrade(): void
    {
        $user = $this->merchant(['plan_expire_date' => null]);

        $this->assertTrue(SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user, $this->now()));
        $this->assertFalse(SubscriptionLifecycle::hasLapsed($user, $this->now()));
    }

    #[Test]
    public function test_the_auth_site_predicate_does_not_downgrade_on_the_expiry_day(): void
    {
        $user = $this->merchant(['plan_expire_date' => self::TODAY]);

        $this->assertFalse(
            SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user, $this->now()),
            'the expiry date is a day the merchant paid for'
        );
    }

    #[Test]
    public function test_the_auth_site_predicate_downgrades_the_day_after(): void
    {
        $user = $this->merchant(['plan_expire_date' => '2026-11-25']);

        $this->assertTrue(SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user, $this->now()));
    }

    // ------------------------------------------------- agreement with the revenue classifier

    /**
     * THE WHOLE POINT, ASSERTED AS AN EQUIVALENCE RATHER THAN DESCRIBED IN PROSE: this class and
     * `SaasRevenueFigures` must place the same row on the same side of the boundary, because the
     * divergence they are here to end was exactly that.
     *
     * The figure's rule is reproduced from its own expression -- `plan_expire_date >= :today`
     * means live -- over a table of dates either side of the boundary.
     */
    #[Test]
    public function test_the_boundary_agrees_with_the_revenue_classifiers_own_expression(): void
    {
        $today = $this->now()->format('Y-m-d');

        foreach (['2026-11-24', '2026-11-25', self::TODAY, '2026-11-27', '2027-01-01'] as $expiry) {
            $revenueSaysLive = $expiry >= $today;

            $this->assertSame(
                $revenueSaysLive,
                ! SubscriptionLifecycle::hasLapsed($this->merchant(['plan_expire_date' => $expiry]), $this->now()),
                "expiry {$expiry}: this class and SaasRevenueFigures must agree"
            );
        }
    }
}

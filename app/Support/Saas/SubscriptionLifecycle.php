<?php

namespace App\Support\Saas;

use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * THE ONE PLACE THAT ANSWERS "WHERE IS THIS SUBSCRIPTION IN ITS LIFECYCLE?" — SAAS-002, SAAS-004.
 *
 * MEASURED BEFORE THIS CLASS EXISTED: five surfaces answered "is this subscription expired?" with
 * their own arithmetic, and they did not agree.
 *
 *   `Auth\AuthenticatedSessionController::store()`   `$datetime2->diff($datetime1)->format('%r%a') <= 0`
 *   `Auth\RegisteredUserController::store()`         the same expression, at two sites
 *   `Http\Middleware\SetLocate::handle()`            the same expression again
 *   `Services\SaasRevenueFigures::subscriptions()`   `users.plan_expire_date >= :today`  ->  'live'
 *   `resources/views/plans/index.blade.php`          `plan_expire_date < date('Y-m-d')`  ->  'Expired'
 *
 * ON THE EXPIRY DATE ITSELF the diff is 0, so `<= 0` is true: the three auth sites called
 * `assignPlan(1)` and flashed "Your Plan is expired." while `SaasRevenueFigures` counted the same
 * row as `live` and the merchant's own plan screen did not print `Expired`. Driven over HTTP before
 * the correction: a `Month`-plan merchant with `plan_expire_date = date('Y-m-d')` logged in and came
 * back with `plan_id` 1, `plan_expire_date` NULL and that flash — on a day they had paid for.
 *
 * THE BOUNDARY HERE IS THE PLATFORM'S OWN DOCUMENTED ONE, not a new choice. `SaasRevenueFigures`
 * states it: "`>=` AND NOT `>`: the expiry date is the last day of the subscription, so a plan
 * expiring today is live today", and `PlatformRevenueFiguresTest::test_a_subscription_expiring_
 * today_still_counts` pins it. `SubscriptionLifecycleTest::test_the_boundary_agrees_with_the_
 * revenue_classifiers_own_expression` asserts the agreement as an equivalence over a table of
 * dates rather than restating it in prose, because prose is what drifted.
 *
 * THE COMPARISON IS LEXICOGRAPHIC OVER ISO TEXT, for the reason `SaasRevenueFigures` gives:
 * `users.plan_expire_date` is a VARCHAR holding `Y-m-d` (`create_users_table` declares
 * `$table->string('plan_expire_date')->nullable()`, and `User::assignPlan()` writes
 * `isoFormat('YYYY-MM-DD')`), so string comparison is correct on MySQL, on SQLite and in PHP, and
 * needs no date parsing that could throw on a malformed column. A stored `Y-m-d H:i:s` still
 * compares correctly against a `Y-m-d` today.
 *
 * THE GRACE PERIOD IS AN OWNER DECISION AND THIS CLASS DOES NOT INVENT ONE — see `config/saas.php`
 * for the question in one line. With it unset, `restrictsNewSelling()` is FALSE and
 * `whyNotRestricted()` names the reason, so a caller cannot read "not restricted" as "subscription
 * fine".
 *
 * WHAT THIS CLASS IS NOT. It does not decide what a restriction DOES, it does not write anything,
 * and it answers nothing about authorization or tenancy. `SAAS-003`, `SAAS-005` and `SAAS-006`
 * require that no lifecycle transition destroys merchant data; this class cannot destroy any,
 * and `SubscriptionDataPreservationTest` plus `SubscriptionExpiryDeletesNothingTest` are what
 * hold the rest of the platform to it.
 */
final class SubscriptionLifecycle
{
    /** Not a `type = 'admin'` row: staff, the operator and a null principal hold no subscription. */
    public const NOT_A_MERCHANT = 'not_a_merchant';

    /** A merchant the platform has never put on a plan. Not the same thing as one who stopped paying. */
    public const NO_PLAN = 'no_plan';

    /** On a plan with no expiry recorded — an `Unlimited` plan, or a column that was never written. */
    public const NO_EXPIRY = 'no_expiry';

    /** Inside a free trial. `PlanController::planTrial()` writes `users.trial_expire_date`. */
    public const TRIALLING = 'trialling';

    /** Paid up: the expiry date is today or later. */
    public const ACTIVE = 'active';

    /** Expired, but within the configured grace period. */
    public const IN_GRACE = 'in_grace';

    /** Expired and past the configured grace period. The only state that restricts new selling. */
    public const PAST_GRACE = 'past_grace';

    /** Expired, and NO grace period has been configured — so which side of it this falls on is unknown. */
    public const EXPIRED_GRACE_UNDEFINED = 'expired_grace_undefined';

    /** `whyNotRestricted()`: the subscription has lapsed but the owner has set no grace period. */
    public const REASON_GRACE_UNDEFINED = 'grace_period_not_configured';

    /** `whyNotRestricted()`: the subscription has not lapsed. */
    public const REASON_NOT_LAPSED = 'subscription_not_lapsed';

    /** `whyNotRestricted()`: within the configured grace period. */
    public const REASON_IN_GRACE = 'within_grace_period';

    /**
     * Where this merchant's subscription sits.
     *
     * `$plan` is passed in rather than loaded here so that a caller holding it already (every auth
     * site does) causes no query, and so that this class never decides WHICH plan row a `plan_id`
     * means. Pass null when the merchant has no plan or the row is missing — a dangling `plan_id`
     * is a real shape in this schema (`PlatformRevenueFiguresTest::test_a_subscription_pointing_at
     * _a_missing_plan_is_counted_as_unreadable`).
     */
    public static function stateFor(?User $user, ?Plan $plan, ?CarbonImmutable $now = null): string
    {
        if (! self::isMerchant($user)) {
            return self::NOT_A_MERCHANT;
        }

        $now = $now ?? CarbonImmutable::now();

        if ($plan === null || self::isBlank($user->plan_id)) {
            return self::NO_PLAN;
        }

        if (self::isTrialling($user, $now)) {
            return self::TRIALLING;
        }

        $expiry = self::expiry($user);

        if ($expiry === null) {
            return self::NO_EXPIRY;
        }

        if (! self::expiryHasPassed($expiry, $now)) {
            return self::ACTIVE;
        }

        $grace = self::graceDays();

        if ($grace === null) {
            return self::EXPIRED_GRACE_UNDEFINED;
        }

        /*
         * DAYS COUNTED FROM THE DAY AFTER THE EXPIRY DATE, because the expiry date is the last day
         * of service. A 7-day grace on an expiry of the 19th therefore covers the 20th to the 26th
         * inclusive, and the 27th is past it. `diffInDays` on two midnight dates is a whole number
         * of calendar days regardless of the time of day in `$now`, which is why both sides are
         * taken at `startOfDay()` rather than compared as instants.
         */
        $elapsed = self::startOfDay($expiry)->diffInDays($now->startOfDay());

        return $elapsed <= $grace ? self::IN_GRACE : self::PAST_GRACE;
    }

    /**
     * Has the paid period ended? The lifecycle question, with no reference to the grace period.
     *
     * An EMPTY expiry is "no expiry recorded" and therefore NOT a lapse — the opposite of what the
     * auth sites' `new \DateTime('')` did. `recurringSubscriptionNeedsDowngrade()` below is the
     * predicate that preserves their behaviour for that one case.
     */
    public static function hasLapsed(?User $user, ?CarbonImmutable $now = null): bool
    {
        if (! self::isMerchant($user)) {
            return false;
        }

        $expiry = self::expiry($user);

        if ($expiry === null) {
            return false;
        }

        return self::expiryHasPassed($expiry, $now ?? CarbonImmutable::now());
    }

    /**
     * MAY THIS MERCHANT STILL OPEN NEW SALES? — the SAAS-004 predicate.
     *
     * TRUE means "restrict new selling". It is true in exactly one state, `PAST_GRACE`, which
     * cannot be reached until the owner has configured a grace period.
     */
    public static function restrictsNewSelling(?User $user, ?Plan $plan, ?CarbonImmutable $now = null): bool
    {
        return self::stateFor($user, $plan, $now) === self::PAST_GRACE;
    }

    /**
     * Why `restrictsNewSelling()` answered false, or null when it answered true.
     *
     * A caller must be able to tell "this subscription is fine" from "this subscription has lapsed
     * and the platform has no rule for it yet". Those are opposite facts behind the same `false`,
     * and that is the shape CLAUDE.md records costing real dispositions.
     */
    public static function whyNotRestricted(?User $user, ?Plan $plan, ?CarbonImmutable $now = null): ?string
    {
        $state = self::stateFor($user, $plan, $now);

        return match ($state) {
            self::PAST_GRACE => null,
            self::IN_GRACE => self::REASON_IN_GRACE,
            self::EXPIRED_GRACE_UNDEFINED => self::REASON_GRACE_UNDEFINED,
            default => self::REASON_NOT_LAPSED,
        };
    }

    /**
     * THE PREDICATE THE THREE AUTH SITES CALL, which is NOT `hasLapsed()`.
     *
     * It differs in exactly one case, deliberately: a RECURRING plan whose `plan_expire_date` is
     * empty. `new \DateTime('')` is NOW, so those sites' `$days <= 0` was true for such a row and
     * they downgraded it to plan 1. That is a broken row rather than a lapse, and whether the login
     * should repair it is a separate question from the off-by-one this slice corrects — so the
     * behaviour is preserved here rather than changed silently.
     *
     * Callers keep their own `$plan->duration != 'Unlimited'` guard: this answers only the date
     * question, which is the one that was wrong.
     */
    public static function recurringSubscriptionNeedsDowngrade(?User $user, ?CarbonImmutable $now = null): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $expiry = self::expiry($user);

        if ($expiry === null) {
            return true;
        }

        return self::expiryHasPassed($expiry, $now ?? CarbonImmutable::now());
    }

    /**
     * The configured grace period in whole days, or null when the owner has not set one.
     *
     * ANYTHING THAT IS NOT A WHOLE NUMBER OF DAYS >= 0 READS AS UNSET, never as zero. `config()`
     * values arrive from `.env` as strings and from a bad edit as anything at all, and zero is the
     * value that starts restricting sales the day after expiry — so `'soon'`, `''`, `-1`, `1.5`,
     * `true` and an array all answer null. A NUMERIC STRING is accepted, because that is the only
     * shape `.env` can hand over.
     */
    public static function graceDays(): ?int
    {
        $configured = config('saas.grace_period_days');

        if (is_bool($configured) || ! is_scalar($configured)) {
            return null;
        }

        $value = trim((string) $configured);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    // ------------------------------------------------------------------ internals

    private static function isMerchant(mixed $user): bool
    {
        return $user instanceof User
            && strtolower(trim((string) $user->type)) === 'admin';
    }

    private static function isTrialling(User $user, CarbonImmutable $now): bool
    {
        $trial = self::normalise($user->trial_expire_date);

        return $trial !== null && ! self::expiryHasPassed($trial, $now);
    }

    private static function expiry(User $user): ?string
    {
        return self::normalise($user->plan_expire_date);
    }

    /** The stored value as trimmed text, or null when it records nothing. */
    private static function normalise(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** True when the last day of service is strictly before today. */
    private static function expiryHasPassed(string $expiry, CarbonImmutable $now): bool
    {
        return $expiry < $now->format('Y-m-d');
    }

    private static function startOfDay(string $expiry): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($expiry, 0, 10))->startOfDay();
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }
}

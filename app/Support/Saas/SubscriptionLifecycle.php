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
 *   `Api\DashboardController::…` (the Flutter one)   `$today->greaterThan($expireDate)`  ->  expired
 *
 * ON THE EXPIRY DATE ITSELF the diff is 0, so `<= 0` is true: the three auth sites called
 * `assignPlan(1)` and flashed "Your Plan is expired." while `SaasRevenueFigures` counted the same
 * row as `live` and the merchant's own plan screen did not print `Expired`. Driven over HTTP before
 * the correction: a `Month`-plan merchant with `plan_expire_date = date('Y-m-d')` logged in and came
 * back with `plan_id` 1, `plan_expire_date` NULL and that flash — on a day they had paid for.
 *
 * AND THE SHIPPED FLUTTER CONTRACT ALREADY SAYS LIVE-ON-THE-EXPIRY-DAY. `Api\DashboardController`
 * answers the admin app's plan screen with `is_expired`, `days_remaining`, `status` and
 * `expire_message`, and its comparison is `$today->greaterThan($expireDate)` -- strictly after, so
 * the expiry date is live. `StoreSyria_app98`'s `PlanStatus.fromJson` reads that `is_expired`
 * straight through to the merchant's screen. So FIVE of the six surfaces agree and the three auth
 * sites were the sole outliers; correcting them brings the web login into line with what the
 * merchant's own phone has been telling them, and changes no API field. Nothing in
 * `Api\DashboardController` is touched.
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

    /**
     * The stored expiry is not a date this class can read, so no lifecycle judgement is possible.
     *
     * ITS OWN STATE, NOT A SPELLING OF `EXPIRED_GRACE_UNDEFINED`: "the owner has set no grace
     * period" and "this row's expiry is not a date" are opposite facts and a caller may need to act
     * differently on each. `SaasRevenueFigures` already keeps `duration_unreadable` and
     * `plan_missing` apart for the same reason.
     */
    public const EXPIRY_UNREADABLE = 'expiry_unreadable';

    /** `whyNotRestricted()`: the subscription has lapsed but the owner has set no grace period. */
    public const REASON_GRACE_UNDEFINED = 'grace_period_not_configured';

    /** `whyNotRestricted()`: the subscription has not lapsed. */
    public const REASON_NOT_LAPSED = 'subscription_not_lapsed';

    /** `whyNotRestricted()`: within the configured grace period. */
    public const REASON_IN_GRACE = 'within_grace_period';

    /** `whyNotRestricted()`: the stored expiry is not a readable date. */
    public const REASON_EXPIRY_UNREADABLE = 'expiry_not_a_readable_date';

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

        /*
         * THE UNREADABLE CHECK COMES BEFORE THE GRACE CHECK, deliberately. "This row's expiry is
         * not a date" is a fact about the data and does not depend on what the owner has
         * configured, so answering `EXPIRED_GRACE_UNDEFINED` for `'1999-99-99'` would assert that
         * the merchant lapsed — a claim the row does not support.
         *
         * NOTE THE ASYMMETRY, WHICH IS DELIBERATE AND NOT A GAP. The lapse test above is a cheap
         * STRING comparison that cannot throw, so a garbage value which happens to compare as LATER
         * than today (`'not-a-date'`: `'n' > '2'`) reads as ACTIVE and never reaches here. Parsing
         * first would catch it — and would also parse on every request and reclassify rows that are
         * currently harmless. The string comparison stays the primary test; this is the guard on
         * the one path that must parse.
         */
        $expiredOn = self::startOfDay($expiry);

        if ($expiredOn === null) {
            return self::EXPIRY_UNREADABLE;
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
         *
         * `$expiredOn` was resolved above, where the only parse in this class happens:
         * `users.plan_expire_date` is a free-text `string` column and `'1999-99-99'` compares as
         * earlier than today, so it reaches the parse and `CarbonImmutable::parse()` raises
         * `InvalidFormatException` — which, behind `LapsedSubscriptionSellingGuard`, is HTTP 500 on
         * `payment.process`. A selling door must not 500 on a bad row. Found by self-review, not by
         * a failure.
         */
        $elapsed = $expiredOn->diffInDays($now->startOfDay());

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
     * MAY A SHOPPER OPEN A NEW ORDER ON THIS STORE? — the storefront form of the SAAS-004 predicate.
     *
     * A storefront request carries no merchant principal (CLAUDE.md: `auth()->user()` reads the
     * `web` guard and is null for a shopper), so the store's own owner is resolved here.
     *
     * THE OWNER IS RESOLVED THROUGH `Support\Commerce\StorePlan::ownerOf()`, NOT BY A SECOND COPY
     * OF THAT LOOKUP. That class is this codebase's established seam for "which merchant's
     * subscription governs this store" — thirteen call sites, including
     * `Api\ApiController::place_order()` itself, which is one of the three doors this predicate
     * gates. It exists because a global `admin_details` cache made every store answer with ONE
     * merchant's plan, and it handles a `Store` whose `created_by` was not selected by re-reading
     * it. A first draft of this method resolved `created_by` itself and would have been a
     * fourteenth spelling of a rule that has already been got wrong once.
     *
     * `StorePlan` deliberately does NOT apply the `type == 'admin'` test — its docblock says every
     * caller keeps its own, because that predicate decides whether a plan exists at all and
     * widening it has money consequences. So `isMerchant()` is applied here. CLAUDE.md records
     * `created_by` being read as a tenant costing an account takeover, so the type test is not
     * optional, and it is enforced AGAIN inside `stateFor()` — two spellings on a selling door is
     * the cheap direction.
     *
     * EVERY UNREADABLE CASE ANSWERS FALSE — a null store, a missing owner row, a non-admin owner.
     * A selling door must never refuse a sale because an ownership read was ambiguous: fail-open on
     * the RESTRICTION is fail-safe for the merchant's revenue, and a wrongly-permitted sale is
     * recoverable where a wrongly-refused one is lost.
     */
    public static function restrictsNewSellingForStore(mixed $store, ?CarbonImmutable $now = null): bool
    {
        if (! $store instanceof \App\Models\Store) {
            return false;
        }

        $owner = \App\Support\Commerce\StorePlan::ownerOf($store);

        if (! self::isMerchant($owner)) {
            return false;
        }

        return self::restrictsNewSelling($owner, Plan::find($owner->plan_id), $now);
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
            self::EXPIRY_UNREADABLE => self::REASON_EXPIRY_UNREADABLE,
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

    /**
     * The expiry as a midnight instant, or null when the stored text is not a usable date.
     *
     * TWO FAILURE MODES, MEASURED SEPARATELY, AND THE SECOND IS THE DANGEROUS ONE:
     *
     *   '1999-99-99'  -> throws Carbon\Exceptions\InvalidFormatException
     *   '2026-13-45'  -> throws Carbon\Exceptions\InvalidFormatException
     *   '0000-00-00'  -> PARSES, to -0001-11-30, i.e. YEAR -1
     *   '0000-01-01'  -> PARSES, year 0
     *
     * The throwing ones are a 500 on a checkout and are caught. `'0000-00-00'` is worse, because it
     * succeeds: it is MYSQL'S ZERO-DATE SENTINEL — what the server writes for an invalid date
     * outside strict mode, and production is MySQL — and Carbon turns it into a date in 2 BC, which
     * is comfortably past any grace period. A merchant whose expiry column held MySQL's "no value"
     * would have had their sales stopped. So a parsed year at or below zero is rejected as
     * unreadable rather than treated as an ancient expiry. That is not an invented cutoff: a date
     * before the Common Era is not a subscription expiry under any reading.
     *
     * Values measured with `CarbonImmutable::parse()` directly, not inferred from the format.
     */
    private static function startOfDay(string $expiry): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::parse(substr($expiry, 0, 10))->startOfDay();
        } catch (\Throwable) {
            // `\Throwable`, not `\Exception`: Carbon raises `InvalidFormatException` (an
            // Exception) for `'1999-99-99'` but the parser's own failures are not all of one
            // hierarchy, and nothing about a bad date in one row is worth a 500 at a checkout.
            return null;
        }

        return $parsed->year > 0 ? $parsed : null;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === '0';
    }
}

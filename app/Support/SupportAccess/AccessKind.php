<?php

namespace App\Support\SupportAccess;

/**
 * THE TWO PATHS `SUPPORT-005` REQUIRES TO BE SEPARATE.
 *
 * "وصول طوارئ منفصل" — a SEPARATE emergency access. The decision's own word is منفصل (separate), so
 * the break-glass route must not be the ordinary route with a flag flipped. This class is where
 * that separation is expressed, and it is expressed as a difference in WHAT AUTHORISES A GRANT
 * rather than as a label on an otherwise identical record:
 *
 *   `SUPPORT`    is authorised by the MERCHANT. `consented_at` must be set. No approvals are read.
 *   `EMERGENCY`  is authorised by OTHER OPERATORS. `consented_at` is never set — the merchant is
 *                not asked, which is the whole reason the path exists — and N distinct approvals
 *                must exist instead, none of them from the grantee.
 *
 * So the two kinds are satisfied by disjoint predicates (`SupportAccessGate::grantIsActive()`), and
 * neither can be reached by the other's route: a `support` grant with two approvals and no consent
 * is INACTIVE, and an `emergency` grant with the merchant's consent and no approvals is INACTIVE
 * too. That is stronger than a flag, and `SupportAccessKindSeparationTest` asserts both directions.
 */
final class AccessKind
{
    public const SUPPORT = 'support';

    public const EMERGENCY = 'emergency';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::SUPPORT, self::EMERGENCY];
    }

    public static function isKnown(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::all(), true);
    }

    /**
     * The configured ceiling for this kind, in minutes.
     *
     * Emergency access reads its own, shorter key: it skips the merchant, so it should run out
     * sooner. Both are owner questions — see `config/support_access.php`.
     */
    public static function maxDurationMinutes(string $kind): int
    {
        $minutes = $kind === self::EMERGENCY
            ? config('support_access.emergency.max_duration_minutes')
            : config('support_access.max_duration_minutes');

        /*
         * A MISSING OR NONSENSE CONFIG VALUE MUST NOT MEAN "NO LIMIT".
         *
         * `(int) null` is 0 and `(int) 'forever'` is 0, and a 0-minute ceiling refuses every grant
         * — which fails CLOSED. The alternative reading, treating an unusable value as unbounded,
         * would turn a deleted config key into permanent support access, and `expires_at` being
         * NOT NULL in the schema exists precisely so that cannot happen. So the floor is 0 and the
         * caller refuses; it is never PHP_INT_MAX.
         */
        return max(0, (int) $minutes);
    }
}

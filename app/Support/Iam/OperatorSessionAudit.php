<?php

namespace App\Support\Iam;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\UserPolicy;

/**
 * `IAM-004` — THE PLATFORM OPERATOR'S SESSIONS, IN THE CENTRAL AUDIT LOG.
 *
 * Recovered decision texts (`docs/development/master-plan/DECISION-ID-REGISTER.md:238`, `:240`):
 * *"مصادقة قوية وإدارة الجلسات."* (`IAM-002`) and *"سجل تدقيق مركزي واستجابة للحوادث."* (`IAM-004`).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHAT WAS MEASURED, AND WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `App\Http\Controllers\Auth\AuthenticatedSessionController` carried **zero** references to
 * `AuditLog` — measured by reading the whole file. So the platform's audit trail recorded what the
 * operator DID and never recorded that they had signed in, from where, or that somebody had tried
 * and failed. `IAM-004`'s load-bearing word is *"مركزي"* — central — and a central trail that
 * cannot answer "who was signed in as the operator when this happened" cannot support the
 * *"استجابة للحوادث"* (incident response) the same decision asks for.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  SCOPED TO THE OPERATOR, AND THE REASON IS VOLUME AND NOT PREFERENCE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **An `Illuminate\Auth\Events\Login` listener was considered and rejected, measured rather than
 * guessed.** `app/Http/Middleware/CustomAuth.php:110-117` calls `auth()->login($tokenable)` on
 * every request that carries a Sanctum token, and that middleware guards 189 route declarations in
 * `routes/api.php`. A listener on that event would therefore write one audit row PER API REQUEST —
 * a storage and latency regression on the path the shipped Flutter app uses for everything.
 *
 * So the recording happens at the INTERACTIVE sign-in controller, which no API request reaches,
 * and only for the platform operator. `users.type` is the whole population here: there is exactly
 * one seeded `super admin` row and no path in the application writes that type, so the row count
 * this adds is "operator sign-ins", not "sign-ins".
 *
 * **WHETHER EVERY USER'S SIGN-IN SHOULD BE RECORDED IS AN OWNER DECISION**, not this repository's:
 * it is a retention and personal-data question (an IP address per merchant sign-in), and the
 * answer decides a volume this schema has no partitioning for. Named for the owner rather than
 * assumed in either direction.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE FAILED ATTEMPT IS THE MOST VALUABLE ROW AND THE ONLY RISKY ONE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A failed sign-in against the operator account is the single most important security signal on
 * the platform, and recording it means an UNAUTHENTICATED caller can cause a write. Three things
 * bound that, and all three were checked rather than assumed:
 *
 *   - **It is throttled.** `LoginRequest::ensureIsNotRateLimited()` caps attempts at 5 per
 *     email-and-IP key (`LoginRequest.php:62`), and a throttled attempt is itself a failure, so
 *     the write rate is bounded by the limiter rather than by the attacker.
 *   - **No caller-supplied text is stored.** The attempted e-mail is used only to LOOK UP a row,
 *     and nothing is recorded unless it resolves to a real operator. The row then names that
 *     operator by id. So the trail cannot be filled with an attacker's prose, which would print on
 *     the operator's own screens — the shape of P1-SEC-79/80.
 *   - **The actor is NULL and the operator is the SUBJECT.** Nobody is authenticated on a failed
 *     attempt, so claiming an actor would be a false statement in a compliance record. "Actor
 *     unknown, subject = the account that was targeted" is the honest row, and it is what
 *     `AuditLog::record()`'s `$subject` parameter is for.
 */
final class OperatorSessionAudit
{
    public const SIGNED_IN = 'operator_signed_in';
    public const SIGNED_OUT = 'operator_signed_out';
    public const SIGN_IN_FAILED = 'operator_sign_in_failed';

    /**
     * Record that the platform operator's interactive session began.
     *
     * A NO-OP FOR EVERY OTHER PRINCIPAL, silently and on purpose — see the class docblock on
     * volume. `instanceof User` FIRST, then `UserPolicy::isOperator()`: CLAUDE.md records that
     * `'type'` is in `Customer::$fillable` and that a class-blind `->type` comparison is the
     * `GAP-307` defect, and the canonical helper is what agrees with MySQL's PAD SPACE comparison.
     */
    public static function signedIn($user): void
    {
        if (! self::isOperator($user)) {
            return;
        }

        AuditLog::record(self::SIGNED_IN, $user, [], [
            'guard' => 'web',
            // The session id is NOT recorded. It is a bearer credential for that session: a
            // compliance table that anybody with `Manage Setting` can be shown is the wrong place
            // for one, and it answers no question the timestamp and address do not.
        ], $user);
    }

    /** Record that the platform operator's interactive session ended. */
    public static function signedOut($user): void
    {
        if (! self::isOperator($user)) {
            return;
        }

        AuditLog::record(self::SIGNED_OUT, $user, [], ['guard' => 'web'], $user);
    }

    /**
     * Record a failed interactive sign-in AGAINST AN OPERATOR ACCOUNT.
     *
     * `$candidate` is the row the controller already looked up from the posted e-mail. Nothing is
     * recorded when it is absent or is not an operator: an attempt against a merchant account, or
     * against an address that exists nowhere, writes no row — so this cannot be used to probe
     * which addresses exist, and it cannot be used to flood the table on behalf of accounts that
     * are not the operator.
     *
     * NO ACTOR. Nobody is authenticated, so the actor column stays null rather than claiming the
     * targeted operator took an action they did not take.
     */
    public static function signInFailed($candidate): void
    {
        if (! self::isOperator($candidate)) {
            return;
        }

        AuditLog::record(self::SIGN_IN_FAILED, $candidate, [], [
            'guard' => 'web',
            'outcome' => 'refused',
        ]);
    }

    /** `instanceof` FIRST so `isOperator()` never reads `->type` off a non-`User` at all. */
    private static function isOperator($user): bool
    {
        return $user instanceof User && UserPolicy::isOperator($user);
    }
}

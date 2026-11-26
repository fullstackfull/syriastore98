<?php

namespace App\Support\SupportAccess;

use App\Models\AuditLog;
use App\Models\SupportAccessGrant;
use App\Models\User;

/**
 * THE AUDIT HALF OF `SUPPORT-004` — "سجل تدقيق" — AND IT REUSES THE TRAIL THAT ALREADY EXISTS.
 *
 * NO SECOND AUDIT TABLE. `audit_logs` is already the compliance trail: `AuditLog::record()` resolves
 * the acting `User` through the `web` guard explicitly, attributes the store through
 * `App\Support\Audit\AuditAttribution` (which prefers the SUBJECT's own `store_id` and gives the
 * operator `null` rather than the fabricated `1` that `getCurrentStore()` returns), and its
 * migration's `down()` is a documented no-op so a rollback cannot destroy the record. A second table
 * would duplicate all of that and give a merchant asking "who saw my data" two places to look, one
 * of which somebody would forget to write to. `RecordImpersonation` already files impersonation
 * there; these rows sit beside it.
 *
 * THE SUBJECT IS THE GRANT, NOT THE MERCHANT. `audit_logs.subject_type` / `subject_id` therefore
 * name a `SupportAccessGrant`, which is what makes the trail joinable: one grant's whole life —
 * requested, approved or consented, used six times, revoked — reads as rows sharing one subject id.
 * Attribution still lands on the right store, because `AuditAttribution::storeFor()` reads the
 * subject's own `store_id` and a grant has one.
 *
 * THE ACTOR IS ALWAYS PASSED EXPLICITLY. `RecordImpersonation`'s docblock records why that parameter
 * exists — at listener time the session guard holds the impersonated merchant, so a row built from
 * `auth()` reads as the merchant acting on themselves. The same hazard applies to every row written
 * from inside an impersonated session, and `recordUse()` is called from exactly there.
 *
 * NOTHING HERE IS A `Log::` LINE, so `docs/security/P01-OBSERVABILITY-INVENTORY.md` and
 * `SecurityLogInventoryTest` are unaffected: that census reads `Log::warning`/`error` calls in
 * `app/`, and these are database rows. Checked rather than assumed — the file writes no `Log::` call
 * at all, which `SupportAccessAuditTest` asserts so a later author who adds one is told to document
 * it.
 */
final class SupportAccessAudit
{
    /** A grant was asked for. Written before any approval or consent exists. */
    public const REQUESTED = 'support_access_requested';

    /** The merchant consented. `SUPPORT-004`'s consent clause, recorded as an event. */
    public const CONSENTED = 'support_access_consented';

    /** Consent withdrawn, or the grant closed early, by whoever did it. */
    public const REVOKED = 'support_access_revoked';

    /** The grant was actually used to reach the store. One row per use. */
    public const USED = 'support_access_used';

    /**
     * SUPPORT REACHED A MERCHANT'S DATA WITH NO LIVE GRANT.
     *
     * This is the most important action name in the file. While `config('support_access.enforce')`
     * is false — the shipped default, owner question 1 — the doors still write this row every time,
     * so the gap `SUPPORT-004` names is MEASURABLE before it is enforced. The owner's decision about
     * whether to switch enforcement on is best taken with the count of these rows in hand, and
     * nobody has that number today.
     */
    public const UNGRANTED = 'support_access_ungranted';

    /** `SUPPORT-005`: one approver's decision on an emergency request. */
    public const EMERGENCY_APPROVED = 'support_access_emergency_approved';

    public const EMERGENCY_DECLINED = 'support_access_emergency_declined';

    /** Enforcement refused the access. The counterpart to UNGRANTED, for the enforced mode. */
    public const REFUSED = 'support_access_refused';

    /** @return array<int, string> */
    public static function actions(): array
    {
        return [
            self::REQUESTED,
            self::CONSENTED,
            self::REVOKED,
            self::USED,
            self::UNGRANTED,
            self::EMERGENCY_APPROVED,
            self::EMERGENCY_DECLINED,
            self::REFUSED,
        ];
    }

    /**
     * Record one event in the grant's life.
     *
     * `$details` lands in `new_values`, which is a json column, and it is deliberately NOT the grant
     * row: copying `reason`, `merchant_id` and the rest into every row would put the same text in
     * eight places and none of them authoritative. The grant is the subject; this carries only what
     * the EVENT adds.
     */
    public static function record(
        string $action,
        SupportAccessGrant $grant,
        ?User $actor,
        array $details = []
    ): void {
        AuditLog::record($action, $grant, [], $details, $actor);
    }

    /**
     * Record an access taken with no live grant, where there is no grant to be the subject.
     *
     * THE SUBJECT IS THE TARGET USER OR STORE instead, because there is no grant — that is the whole
     * fact being recorded. Without this branch the row would have a null subject and the trail could
     * not say WHOSE data was reached, which is the only thing a merchant reading it cares about.
     */
    public static function recordUngranted(
        string $action,
        mixed $subject,
        ?User $actor,
        array $details = []
    ): void {
        AuditLog::record($action, $subject, [], $details, $actor);
    }
}

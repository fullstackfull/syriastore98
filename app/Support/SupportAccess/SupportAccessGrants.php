<?php

namespace App\Support\SupportAccess;

use App\Models\SupportAccessApproval;
use App\Models\SupportAccessGrant;
use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * THE ONLY WAY A GRANT IS CREATED, CONSENTED TO, APPROVED, REVOKED OR USED.
 *
 * `SUPPORT-004` / `SUPPORT-005`. `SupportAccessGate` answers "may they"; this answers "write it
 * down". The two are separate because a predicate that writes rows gets called twice by the next
 * author and double-counts a use, and because the gate is called on every door hit while these
 * methods are called once per lifecycle event.
 *
 * EVERY METHOD REFUSES BY THROWING, not by returning false. A caller that fails to create a grant
 * and carries on as though it had would be a caller that reached a merchant's data without one, and
 * `InvalidArgumentException` is loud. The messages name the clause that refused, because "support
 * access denied" tells a support engineer nothing about which of five conditions to fix.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE BOUND IS ENFORCED HERE, AT THE ONLY DOOR THAT CREATES A GRANT
 * -------------------------------------------------------------------------------------------------
 *
 * `config('support_access.max_duration_minutes')` is an owner question, and a configured ceiling
 * that nothing checks is a comment. `request()` refuses a longer duration, refuses a zero or
 * negative one, and refuses when the configured ceiling is itself unusable — so a deleted config key
 * produces no grants rather than permanent ones.
 */
final class SupportAccessGrants
{
    /**
     * Ask for access. Writes the grant in its INACTIVE state and nothing else.
     *
     * A freshly requested grant authorises NOTHING: a `support` grant has no `consented_at` and an
     * `emergency` grant has no approvals, and `SupportAccessGate::grantIsActive()` refuses both.
     * That is the point — requesting is not granting, and the row exists so the merchant has
     * something to consent TO and the approvers something to approve.
     *
     * THE GRANTEE MUST BE A PLATFORM OPERATOR. Support access is a platform-side capability; a
     * grant naming a merchant would be a cross-tenant authorisation written by the product, which
     * is the shape of every tenancy defect in this repository's history. CLAUDE.md's operator test
     * (`UserPolicy::isOperator`) is used rather than a raw `===` because `users.type` is stored
     * under a case- and trailing-space-folding collation, so `'Super Admin'` IS the operator to
     * every SQL query while a PHP `===` refuses it.
     *
     * THE STORE MUST EXIST, and it is read `withoutGlobalScopes()`. `Store` carries no `StoreScope`
     * today (CLAUDE.md's table), but the requester here is the operator, and if `Store` were ever
     * scoped, `getCurrentStore()`'s hardcoded `1` would confine this lookup to store 1 and make
     * every other store un-grantable. The merchant is derived FROM THE STORE's `created_by`, never
     * from a parameter: a caller-supplied merchant id is exactly CLAUDE.md's "a tenant predicate
     * built from caller-supplied input is not a tenant check".
     */
    public static function request(
        User $requester,
        int $storeId,
        string $kind,
        string $scope,
        string $reason,
        int $durationMinutes,
        ?User $grantee = null
    ): SupportAccessGrant {
        if (! AccessKind::isKnown($kind)) {
            throw new InvalidArgumentException('Unknown support access kind: ' . $kind);
        }

        if (! SupportAccessScope::isKnown($scope)) {
            throw new InvalidArgumentException('Unknown support access scope: ' . $scope);
        }

        if (trim($reason) === '') {
            // A consent prompt with no reason is not informed consent, and an approver with no
            // reason is rubber-stamping. The column is NOT NULL for the same reason.
            throw new InvalidArgumentException('A support access request needs a stated reason.');
        }

        $grantee = $grantee ?? $requester;

        if (! UserPolicy::isOperator($grantee)) {
            throw new InvalidArgumentException('A support access grant names a platform operator.');
        }

        $ceiling = AccessKind::maxDurationMinutes($kind);

        if ($ceiling < 1) {
            throw new InvalidArgumentException(
                'No usable maximum duration is configured for ' . $kind . ' access; refusing.'
            );
        }

        if ($durationMinutes < 1 || $durationMinutes > $ceiling) {
            throw new InvalidArgumentException(
                'A ' . $kind . ' grant must run between 1 and ' . $ceiling . ' minutes.'
            );
        }

        $store = Store::withoutGlobalScopes()->find($storeId);

        if (! $store) {
            throw new InvalidArgumentException('No such store.');
        }

        /*
         * WHO MAY ASK — FOUND BY APPLYING THE `tenant-authz-reviewer` CHECKLIST TO THIS FILE,
         * SHAPE 2 ("a tenant predicate built from caller input").
         *
         * `$storeId` IS caller-supplied, and that is correct for a REQUEST: the control is that the
         * resulting grant authorises nothing until the store's own merchant consents, and
         * `merchant_id` is derived from `$store->created_by` rather than from a parameter. So no
         * access is gained by naming someone else's store.
         *
         * BUT A ROW IS STILL WRITTEN, and an unconstrained requester could write one into any
         * store's grant list — which is the list a merchant is meant to read to see who has asked
         * for their data. Unsolicited rows there are noise at best and a phishing surface at worst
         * ("support needs access, please approve"), aimed at the merchant through their own panel.
         *
         * LATENT, NOT LIVE, and said plainly: no route calls this method yet, so nothing can reach
         * it today. It is closed now rather than left for the controller that will expose it,
         * because "the caller will check" is how shape 2 produced four separate defects.
         *
         * THE OPERATOR *OR* THE STORE'S OWN MERCHANT. Not operator-only: a merchant asking for help
         * with their own store is a real product flow, and a merchant who both requests and
         * consents is giving away nothing but their own data. The operator keeps their deliberate
         * cross-tenant reach, which is the whole point of support access.
         *
         * `isOperator()` rather than a raw `===`, for CLAUDE.md's reason: `users.type` is stored
         * under a case- and trailing-space-folding collation, so `'Super Admin'` IS the operator to
         * every SQL query while a PHP `===` refuses it.
         */
        $requesterIsOperator = UserPolicy::isOperator($requester);
        $requesterOwnsStore = (int) $requester->id === (int) $store->created_by;

        if (! $requesterIsOperator && ! $requesterOwnsStore) {
            throw new InvalidArgumentException(
                'Only the platform operator or the store\'s own merchant may request support access.'
            );
        }

        $now = Carbon::now();

        $grant = SupportAccessGrant::create([
            'store_id' => (int) $store->id,
            // From the store, never from a parameter. See the docblock.
            'merchant_id' => (int) $store->created_by,
            'grantee_id' => (int) $grantee->id,
            'kind' => $kind,
            'scope' => $scope,
            'reason' => $reason,
            'requested_by' => (int) $requester->id,
            'requested_at' => $now,
            'expires_at' => $now->copy()->addMinutes($durationMinutes),
        ]);

        SupportAccessAudit::record(SupportAccessAudit::REQUESTED, $grant, $requester, [
            'kind' => $kind,
            'scope' => $scope,
            'duration_minutes' => $durationMinutes,
            'expires_at' => $grant->expires_at?->toDateTimeString(),
            'grantee_id' => (int) $grantee->id,
        ]);

        return $grant;
    }

    /**
     * The merchant consents. `SUPPORT-004`'s "بموافقة التاجر".
     *
     * ONLY THE MERCHANT WHOSE STORE IT IS MAY CONSENT, and that is the whole requirement — an
     * operator who could call this would be self-authorising, which is the state the decision
     * exists to end. The test is `$consenter->id === $grant->merchant_id` against the merchant id
     * the grant took FROM THE STORE at request time, so it cannot be redirected by a parameter.
     *
     * A sub-user of the merchant's tenant is REFUSED, deliberately and conservatively: who inside a
     * merchant's organisation may hand their data to support is a real decision the plan does not
     * state, and admitting every staff member with a panel login would answer it the widest way.
     * The narrow reading is reversible; the wide one is not. Named in the owner questions document.
     *
     * AN EMERGENCY GRANT CANNOT BE CONSENTED TO. `SUPPORT-005` makes it a separate path authorised
     * by approvals INSTEAD of consent; letting consent satisfy it would collapse the two paths into
     * one, which is what منفصل forbids.
     *
     * IDEMPOTENT-SAFE: consenting twice is refused rather than silently re-stamped, so the audit
     * trail cannot show two consents for one decision.
     */
    public static function consent(SupportAccessGrant $grant, User $consenter): SupportAccessGrant
    {
        if ($grant->isEmergency()) {
            throw new InvalidArgumentException(
                'Emergency access is authorised by approvals, not by merchant consent.'
            );
        }

        if ($grant->isRevoked()) {
            throw new InvalidArgumentException('That grant has been revoked.');
        }

        if ($grant->hasExpired()) {
            throw new InvalidArgumentException('That grant has expired.');
        }

        if ($grant->isConsented()) {
            throw new InvalidArgumentException('That grant has already been consented to.');
        }

        if ((int) $consenter->id !== (int) $grant->merchant_id) {
            throw new InvalidArgumentException('Only the merchant who owns the store may consent.');
        }

        // Not through $fillable: these two columns decide whether the grant is active, and the
        // model deliberately omits them so no mass assignment can reach them. See the model.
        $grant->forceFill([
            'consented_at' => Carbon::now(),
            'consented_by' => (int) $consenter->id,
        ])->save();

        SupportAccessAudit::record(SupportAccessAudit::CONSENTED, $grant, null, [
            'consented_by' => (int) $consenter->id,
            'scope' => $grant->scope,
            'expires_at' => $grant->expires_at?->toDateTimeString(),
        ]);

        return $grant;
    }

    /**
     * One approver's decision on an emergency request. `SUPPORT-005`.
     *
     * THE GRANTEE MAY NOT APPROVE THEIR OWN. Refused here AND ignored by the gate — two layers,
     * because this is the property that makes "موافقات أعلى" mean more than one person. Refusing
     * here gives the approver an error instead of a row that silently never counts.
     *
     * ONE DECISION PER APPROVER, enforced here and by a UNIQUE index. Without it a single person
     * satisfies any N-approver rule by posting N times.
     */
    public static function decide(
        SupportAccessGrant $grant,
        User $approver,
        string $decision,
        ?string $note = null
    ): SupportAccessApproval {
        if (! $grant->isEmergency()) {
            throw new InvalidArgumentException('Only emergency access is approved.');
        }

        if (! SupportAccessApproval::isKnownDecision($decision)) {
            throw new InvalidArgumentException('Unknown approval decision: ' . $decision);
        }

        if ((int) $approver->id === (int) $grant->grantee_id) {
            throw new InvalidArgumentException('An operator may not approve their own emergency access.');
        }

        if (! SupportAccessGate::approverIsEligible($approver)) {
            throw new InvalidArgumentException('That user may not approve emergency access.');
        }

        $already = SupportAccessApproval::query()
            ->where('grant_id', $grant->id)
            ->where('approver_id', $approver->id)
            ->exists();

        if ($already) {
            throw new InvalidArgumentException('That approver has already decided on this request.');
        }

        $approval = SupportAccessApproval::create([
            'grant_id' => (int) $grant->id,
            'approver_id' => (int) $approver->id,
            'decision' => $decision,
            'note' => $note,
            'decided_at' => Carbon::now(),
        ]);

        SupportAccessAudit::record(
            $decision === SupportAccessApproval::APPROVED
                ? SupportAccessAudit::EMERGENCY_APPROVED
                : SupportAccessAudit::EMERGENCY_DECLINED,
            $grant,
            $approver,
            ['approver_id' => (int) $approver->id, 'note' => $note]
        );

        return $approval;
    }

    /**
     * End a grant now.
     *
     * THE MERCHANT MAY ALWAYS REVOKE. That is the counterpart of consent: consent that cannot be
     * withdrawn is not consent. The grantee and any platform operator may also close one — an
     * operator finishing early should not have to wait for the expiry, and a grant left open is a
     * live authorisation.
     *
     * REVOKING AN EXPIRED GRANT IS ALLOWED, deliberately. It is already inert, but a merchant
     * clicking "revoke" on a grant that expired a minute ago should see it recorded rather than get
     * an error that reads as a failure; and the row is the merchant's own statement that they
     * withdrew, which has a meaning the expiry does not.
     */
    public static function revoke(
        SupportAccessGrant $grant,
        User $actor,
        ?string $reason = null
    ): SupportAccessGrant {
        if ($grant->isRevoked()) {
            throw new InvalidArgumentException('That grant has already been revoked.');
        }

        $isMerchant = (int) $actor->id === (int) $grant->merchant_id;
        $isGrantee = (int) $actor->id === (int) $grant->grantee_id;

        if (! $isMerchant && ! $isGrantee && ! UserPolicy::isOperator($actor)) {
            throw new InvalidArgumentException('You may not revoke that grant.');
        }

        $grant->forceFill([
            'revoked_at' => Carbon::now(),
            'revoked_by' => (int) $actor->id,
            'revoked_reason' => $reason,
        ])->save();

        SupportAccessAudit::record(SupportAccessAudit::REVOKED, $grant, $actor, [
            'revoked_by' => (int) $actor->id,
            'by' => $isMerchant ? 'merchant' : ($isGrantee ? 'grantee' : 'operator'),
            'reason' => $reason,
        ]);

        return $grant;
    }

    /**
     * Record that a live grant was actually used, and count it.
     *
     * ONE ROW PER USE, so a merchant asking "what did support do with the access I gave them" gets
     * a list rather than a yes. The counters on the grant are a summary of the same facts, kept
     * because "consented and never used" and "used sixty times" are different facts and a merchant
     * reviewing a grant should see which without joining the trail.
     *
     * The write is deliberately NOT inside `SupportAccessGate`: the gate is consulted on every door
     * hit, including ones that then refuse for an unrelated reason, and a use counter incremented
     * by a predicate counts attempts rather than uses.
     */
    public static function recordUse(SupportAccessGrant $grant, ?User $actor, array $details = []): void
    {
        $now = Carbon::now();

        $grant->forceFill([
            'first_used_at' => $grant->first_used_at ?? $now,
            'last_used_at' => $now,
            'uses' => (int) $grant->uses + 1,
        ])->save();

        SupportAccessAudit::record(SupportAccessAudit::USED, $grant, $actor, $details + [
            'scope' => $grant->scope,
            'kind' => $grant->kind,
        ]);
    }
}

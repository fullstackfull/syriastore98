<?php

namespace App\Support\SupportAccess;

use App\Models\SupportAccessApproval;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Policies\UserPolicy;

/**
 * THE ONE PREDICATE: may this person reach this store's data, in this scope, right now?
 *
 * `SUPPORT-004` / `SUPPORT-005`. Every door that consults the grant mechanism asks this class and
 * nothing else, so the four clauses of the approved decision are checked in one place and a new
 * door cannot implement three of them.
 *
 * -------------------------------------------------------------------------------------------------
 *  THE CLAUSES, AND WHERE EACH IS ENFORCED
 * -------------------------------------------------------------------------------------------------
 *
 *   temporary    `grantIsActive()` — `hasExpired()`, and `expires_at` is NOT NULL in the schema.
 *   scoped       `grantIsActive()` — `SupportAccessScope::covers()`, plus the store predicate in
 *                `activeGrant()`, which is explicit because this model is deliberately unscoped.
 *   consent      `grantIsActive()` — a `support` grant without `consented_at` is inactive.
 *   audit        `App\Support\SupportAccess\SupportAccessAudit` — written by the callers, not here,
 *                because this class is a pure predicate and a predicate that writes rows gets
 *                called twice by the next author and double-counts.
 *   emergency    `grantIsActive()` — N distinct approvals, none from the grantee, no decline.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHY `allows()` DOES NOT CONSULT `isAbleTo()`
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md: `isAbleTo()` REFUSES the platform operator, because `permissions_as_gates => false`
 * and operator status is a string comparison on `users.type`. The principal this gate is about IS
 * the platform operator, so a permission check here would refuse exactly the person the mechanism
 * exists to govern. The gate asks a different question — "is there a live authorisation for this" —
 * and leaves "may this role do support work at all" to the existing route permissions.
 *
 * The one place a permission IS read is `approverIsEligible()`, for the owner's optional
 * `approver_permission`, and there the operator is tested FIRST, in CLAUDE.md's required order.
 */
final class SupportAccessGate
{
    /**
     * Is the grant mechanism enforced at the live doors?
     *
     * See `config/support_access.php` question 1. A missing key reads `false` — the shipped default —
     * rather than `true`, because a config file that failed to load must not lock every support
     * agent out of the platform. The complementary protection is that `false` is not silent: the
     * doors write `support_access_ungranted` audit rows in that mode.
     */
    public static function isEnforced(): bool
    {
        return (bool) config('support_access.enforce', false);
    }

    /**
     * The live grant authorising `$actor` over `$storeId` in `$scope`, or null.
     *
     * THE STORE PREDICATE IS EXPLICIT AND SO IS THE GRANTEE PREDICATE. `SupportAccessGrant` carries
     * no `StoreScope` (deliberately — see the model), so there is no global scope to fall back on
     * and nothing here depends on `getCurrentStore()`, which answers a hardcoded `1` for the
     * operator and would otherwise match a store-1 grant against every store on the platform.
     *
     * NEWEST FIRST, by `id`. Several grants may exist for one store and grantee — a narrow one from
     * yesterday, a wider one from today — and the gate must not stop at the first row the database
     * happens to return. It walks them and takes the first that is ACTIVE for the requested scope,
     * so a revoked or expired row never shadows a live one.
     *
     * `$storeId <= 0` is refused before any query. `getCurrentStore()` can answer null, and a null
     * cast to int is 0; a `where('store_id', 0)` would match a row only if one existed with that
     * value, which is unlikely but is the wrong reason to be safe. An unidentified store is a
     * refusal, stated as one.
     */
    public static function activeGrant(mixed $actor, ?int $storeId, string $scope): ?SupportAccessGrant
    {
        if (! $actor instanceof User || $storeId === null || $storeId <= 0) {
            return null;
        }

        if (! SupportAccessScope::isKnown($scope)) {
            return null;
        }

        $candidates = SupportAccessGrant::query()
            ->where('grantee_id', $actor->id)
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $grant) {
            if (self::grantIsActive($grant, $scope)) {
                return $grant;
            }
        }

        return null;
    }

    public static function allows(mixed $actor, ?int $storeId, string $scope): bool
    {
        return self::activeGrant($actor, $storeId, $scope) !== null;
    }

    /**
     * Impersonation needs a `full` grant, because impersonation IS full access.
     *
     * `SupportAccessScope::coversImpersonation()` holds the reasoning. This wrapper exists so the
     * door reads as the question it is asking rather than as a scope string the next author might
     * "generalise" to the narrow scopes.
     */
    public static function allowsImpersonation(mixed $actor, ?int $storeId): bool
    {
        $grant = self::activeGrant($actor, $storeId, SupportAccessScope::FULL);

        return $grant !== null && SupportAccessScope::coversImpersonation($grant->scope);
    }

    /**
     * May `$actor` sign in AS `$target`? — the live door, and the answer is stricter than it looks.
     *
     * IMPERSONATING A MERCHANT REACHES EVERY STORE THEY OWN, so authorisation must cover every
     * store they own. Measured rather than assumed: `Impersonate::take()` signs the operator into
     * the `web` guard as the merchant, and from then on `StoreController::switchStore` lets that
     * session move between any store with `created_by = <merchant>` — the merchant's own ordinary
     * ability. So a `full` grant for ONE of a merchant's four stores does not authorise becoming
     * the merchant; it would hand over the other three, which the merchant never consented to.
     *
     * Hence: a `full`, live grant for EACH store the merchant owns. For the common case — a
     * merchant with one store — that is exactly one grant and the rule is invisible. For a merchant
     * with four it is four consents, which is the honest price of a mechanism whose unit is a
     * store.
     *
     * A MERCHANT WHO OWNS NO STORE IS REFUSED, which looks odd and is deliberate. With no store
     * there is no `store_id` for a grant to name, so no consent can exist — and admitting the case
     * would make "owns no store" a bypass of the whole mechanism. It fails closed, and the audit
     * row says so.
     *
     * `withoutGlobalScopes()` on the store lookup, for the reason `SupportAccessGrants::request()`
     * gives: the reader is the operator, and `getCurrentStore()`'s hardcoded `1` would confine this
     * to store 1 if `Store` were ever scoped.
     *
     * @return array{allowed: bool, grants: array<int, SupportAccessGrant>, stores: array<int, int>}
     */
    public static function impersonationDecision(mixed $actor, mixed $target): array
    {
        $storeIds = [];

        if ($target instanceof User) {
            $storeIds = \App\Models\Store::withoutGlobalScopes()
                ->where('created_by', $target->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($storeIds === []) {
            return ['allowed' => false, 'grants' => [], 'stores' => []];
        }

        $grants = [];

        foreach ($storeIds as $storeId) {
            $grant = self::activeGrant($actor, $storeId, SupportAccessScope::FULL);

            if ($grant === null || ! SupportAccessScope::coversImpersonation($grant->scope)) {
                return ['allowed' => false, 'grants' => [], 'stores' => $storeIds];
            }

            $grants[] = $grant;
        }

        return ['allowed' => true, 'grants' => $grants, 'stores' => $storeIds];
    }

    /**
     * Every clause of the approved decision, in one place.
     *
     * ORDER IS NOT SIGNIFICANT TO THE RESULT — all five must hold — but revocation and expiry are
     * tested first because they are the two a reader most wants to see cannot be skipped.
     */
    public static function grantIsActive(?SupportAccessGrant $grant, string $scope): bool
    {
        if ($grant === null) {
            return false;
        }

        if ($grant->isRevoked()) {
            return false;
        }

        if ($grant->hasExpired()) {
            return false;
        }

        if (! AccessKind::isKnown($grant->kind)) {
            return false;
        }

        if (! SupportAccessScope::covers($grant->scope, $scope)) {
            return false;
        }

        return $grant->isEmergency()
            ? self::emergencyIsApproved($grant)
            : $grant->isConsented();
    }

    /**
     * `SUPPORT-005`'s approvals clause.
     *
     * THREE PROPERTIES, AND ONLY ONE OF THEM IS THE OWNER'S NUMBER:
     *
     *  1. A SINGLE DECLINE IS FATAL, whatever the approval count. Checked first, so no amount of
     *     approving outvotes a refusal. There is no majority rule here and inventing one would be
     *     inventing policy; "someone with the authority to review this said no" is a stop.
     *  2. THE APPROVERS MUST BE DISTINCT PEOPLE AND NONE MAY BE THE GRANTEE. The distinctness is
     *     also a UNIQUE index (see the migration) — belt and braces, because an index protects the
     *     table and this protects a grant whose rows predate the index. Excluding the grantee is
     *     the difference between "approved by others" and "approved by me", and it is the property
     *     that makes a two-approver rule mean anything.
     *  3. THE COUNT is `config('support_access.emergency.required_approvals')` — the owner's.
     *
     * A REQUIREMENT OF ZERO IS REFUSED. `(int) null` is 0, so a deleted config key would otherwise
     * make every emergency grant self-approving — an unset value turning into unlimited access,
     * which is the failure mode this whole file is written against. Below 1 the method refuses.
     */
    public static function emergencyIsApproved(SupportAccessGrant $grant): bool
    {
        $required = (int) config('support_access.emergency.required_approvals', 2);

        if ($required < 1) {
            return false;
        }

        $decisions = SupportAccessApproval::query()
            ->where('grant_id', $grant->id)
            ->get();

        foreach ($decisions as $decision) {
            if ($decision->decision === SupportAccessApproval::DECLINED) {
                return false;
            }
        }

        $approverIds = [];

        foreach ($decisions as $decision) {
            if ($decision->decision !== SupportAccessApproval::APPROVED) {
                continue;
            }

            // The grantee's own approval is not an approval.
            if ((int) $decision->approver_id === (int) $grant->grantee_id) {
                continue;
            }

            if (! self::approverIsEligible($decision->approver_id)) {
                continue;
            }

            $approverIds[(int) $decision->approver_id] = true;
        }

        return count($approverIds) >= $required;
    }

    /**
     * May this person approve emergency access?
     *
     * OPERATOR FIRST, THEN THE PERMISSION — CLAUDE.md's required ordering, and here it is not a
     * formality. `isAbleTo()` refuses the platform operator outright, so testing the permission
     * first would reject every operator and, with `approver_permission` set, leave emergency access
     * unapprovable by anyone. The operator test comes first and short-circuits.
     *
     * With `approver_permission` unset (the shipped state, owner question 5), the rule is "any
     * platform operator other than the grantee" — the widest honest reading, since no support-lead
     * role exists in `roles` to name. A non-operator is refused either way: emergency access to a
     * merchant's data is not approved by someone who could not take it themselves.
     *
     * `withoutGlobalScopes()` on the lookup: `User` carries no `StoreScope` today (CLAUDE.md's
     * table), but an approver must be resolvable whoever is asking, and a scope added to `User`
     * later must not silently turn approvals into non-approvals.
     */
    public static function approverIsEligible(mixed $approverId): bool
    {
        $approver = $approverId instanceof User
            ? $approverId
            : User::withoutGlobalScopes()->find($approverId);

        if (! $approver instanceof User) {
            return false;
        }

        if (! UserPolicy::isOperator($approver)) {
            return false;
        }

        $permission = config('support_access.emergency.approver_permission');

        if (! is_string($permission) || trim($permission) === '') {
            return true;
        }

        return $approver->isAbleTo($permission);
    }
}

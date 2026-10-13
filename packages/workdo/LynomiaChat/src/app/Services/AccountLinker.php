<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Attaches an EXISTING Lynomia workspace to a store, behind administrative approval.
 *
 * THIS CLASS NEVER CALLS createUser(), AND THAT IS THE POINT.
 *
 * The obvious implementation of "link my existing account" is to POST the merchant's Lynomia
 * email to /platform/api/v1/users. Upstream that resolves an existing user and grants this
 * app a permissible on them -- so the obvious implementation is an account-takeover
 * primitive: any merchant could type any Lynomia user's address and be handed a working SSO
 * session for it. The approved rules say an account may never be linked on email similarity
 * alone, and this is the exact shape that rule exists to forbid.
 *
 * What Store Syria can honestly establish by itself is nothing. A platform token reaches only
 * accounts it already holds a permissible for, so "does this merchant control that account?"
 * is a question this app is structurally unable to answer. Pretending otherwise would be
 * inventing a verification.
 *
 * So the flow is a CLAIM, not a link:
 *
 *   1. request()  -- the merchant states the Lynomia account id they claim. A one-time code
 *                    is generated, shown to them once, and only its hash is stored. The row
 *                    is pending_verification and grants NOTHING: no SSO, no API access, no
 *                    permissible is requested from Lynomia.
 *   2. (out of band) -- the merchant proves control to a Store Syria operator, and the
 *                    Lynomia-side owner grants this platform app a permissible on the
 *                    account. Both are human steps on purpose.
 *   3. approve()  -- a platform operator records the decision. Only here does the row become
 *                    active, and only then is an SSO link ever issued for it.
 *
 * The code is what ties step 2 to step 1: an operator approving a claim confirms the merchant
 * presented the code from THIS request, so an approval cannot be replayed against a different
 * pending claim.
 */
class AccountLinker
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Open a claim on an existing Lynomia account.
     *
     * @return array{link: LynomiaStoreLink, verification_code: string}
     *         The plaintext code is returned ONCE, for display to the merchant. It is not
     *         stored and cannot be recovered; a lost code means opening a new claim.
     */
    public function request(Store $store, User $owner, int $lynomiaAccountId): array
    {
        if ($lynomiaAccountId <= 0) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'A valid Lynomia account id is required.'
            );
        }

        $existing = LynomiaStoreLink::forStore((int) $store->id)->first();

        // A CLAIM MAY ONLY BE OPENED ON NOTHING, OR ON AN UNAPPROVED CLAIM.
        //
        // This used to refuse only an ACTIVE row, which let a merchant re-open a REVOKED or
        // SUSPENDED one. That was not merely untidy, it was an operator-deception bug: the
        // attribute set below rewrites lynomia_account_id but says nothing about
        // lynomia_user_id, so a previously provisioned row kept the ORIGINAL workspace's user
        // id while presenting itself as a fresh claim for an unrelated account. allowsSso()
        // asks only that both ids are non-null, so approving the claim the operator was shown
        // minted a session for the workspace they had just revoked -- and the approval screen
        // showed them the new account id, not the inherited user id.
        //
        // Refusing every non-pending state fixes that at the cause rather than by scrubbing
        // the symptom: a pending row has no identifiers to inherit. A claim never sets
        // lynomia_user_id, and a failed provision leaves the row pending with both ids still
        // null because AccountProvisioner only writes them after the attach succeeds. So the
        // rows that reach the force-fill below carry nothing to leak, and nulling the columns
        // there would be unreachable code.
        //
        // It also makes the states mean what LinkState says they mean. REVOKED is documented
        // "terminal for this row" and is an operator's deliberate act -- often for abuse --
        // so the merchant must not be able to undo it by filing a new claim. SUSPENDED is
        // documented as keeping the account ids "so the same workspace is reconnected rather
        // than a second one created", which a claim naming a different account would throw
        // away. Both are operator business now.
        if ($existing !== null && $existing->state !== LinkState::PENDING_VERIFICATION) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                $existing->state === LinkState::ACTIVE
                    ? 'This store is already connected to a Lynomia workspace.'
                    : 'This store\'s Lynomia connection was ended by support. Please contact support to restore it.'
            );
        }

        $code = strtoupper(Str::random(10));
        $ttl = (int) config('lynomia.link_verification_ttl_minutes', 60);

        $attributes = [
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'lynomia_account_id' => $lynomiaAccountId,
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_LINK_EXISTING,
            'verification_code_hash' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes($ttl),
            // A re-opened claim must not inherit a previous approval.
            'verified_at' => null,
            'verified_by' => null,
            'last_error' => null,
        ];

        try {
            if ($existing !== null) {
                $existing->forceFill($attributes)->save();
                $link = $existing;
            } else {
                $link = LynomiaStoreLink::create($attributes);
            }
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // The unique index on lynomia_account_id is doing the work here: another
                // store already holds this workspace. Refusing is the "no unauthorized
                // cross-store sharing" rule, enforced by the database rather than a check.
                throw new LynomiaApiException(
                    LynomiaApiException::KIND_CONFLICT,
                    'That Lynomia workspace is already connected to another store.'
                );
            }

            throw $e;
        }

        AuditLog::record('lynomia_link_requested', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $lynomiaAccountId,
            'expires_at' => (string) $link->verification_expires_at,
        ]);

        return ['link' => $link, 'verification_code' => $code];
    }

    /**
     * Record a platform operator's approval of a pending claim.
     *
     * The caller is responsible for having established that $approver is a platform operator;
     * the route carries PlatformOperatorOnly and the controller re-checks. What is enforced
     * HERE is everything about the claim itself: that it exists, is still pending, has not
     * expired, and that the presented code is the one issued for this claim.
     */
    public function approve(LynomiaStoreLink $link, User $approver, string $presentedCode): LynomiaStoreLink
    {
        if ($link->state !== LinkState::PENDING_VERIFICATION) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This link is not awaiting verification.'
            );
        }

        if ($link->link_mode !== LynomiaStoreLink::MODE_LINK_EXISTING) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'Only an existing-account claim is approved this way.'
            );
        }

        if ($link->verificationHasExpired()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'The verification window for this claim has expired. Ask the merchant to start again.'
            );
        }

        if (blank($link->verification_code_hash)
            || ! Hash::check($presentedCode, (string) $link->verification_code_hash)) {
            // Deliberately not audited with the presented value.
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'The verification code does not match this claim.'
            );
        }

        $link->forceFill([
            'state' => LinkState::ACTIVE,
            'verified_at' => now(),
            'verified_by' => (int) $approver->id,
            // One-time: the code cannot be replayed to re-approve after a later revocation.
            'verification_code_hash' => null,
            'verification_expires_at' => null,
            'last_synced_at' => now(),
        ])->save();

        AuditLog::record('lynomia_link_approved', $link, [
            'state' => LinkState::PENDING_VERIFICATION,
        ], [
            'state' => LinkState::ACTIVE,
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'approved_by' => (int) $approver->id,
        ]);

        return $link->refresh();
    }

    /**
     * Withdraw access without discarding the mapping.
     *
     * Revoking keeps lynomia_account_id so the same workspace is reconnected later rather
     * than a second one created, and so the historical mapping survives for migration and
     * audit. Nothing is deleted on the Lynomia side: this integration does not destroy a
     * merchant's conversations, and un-permissioning an account is a Lynomia-side decision.
     */
    public function revoke(LynomiaStoreLink $link, User $actor, string $reason): LynomiaStoreLink
    {
        $previous = $link->state;

        $link->forceFill([
            'state' => LinkState::REVOKED,
            'verification_code_hash' => null,
            'verification_expires_at' => null,
            'last_error' => Str::limit($reason, 480),
        ])->save();

        AuditLog::record('lynomia_link_revoked', $link, ['state' => $previous], [
            'state' => LinkState::REVOKED,
            'store_id' => $link->store_id,
            'reason' => Str::limit($reason, 200),
            'actor_id' => (int) $actor->id,
        ]);

        return $link->refresh();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}

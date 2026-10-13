<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Attaches an EXISTING Lynomia workspace to a store, on proof minted inside that workspace.
 *
 * THIS CLASS NEVER CALLS createUser(), AND THAT IS STILL THE POINT.
 *
 * The obvious implementation of "link my existing account" is to POST the merchant's Lynomia
 * email to /platform/api/v1/users. Upstream that resolves an existing user and grants this
 * app a permissible on them, so the obvious implementation is an account-takeover primitive:
 * any merchant could type any Lynomia user's address and be handed a working SSO session for
 * it. An account may never be linked on email similarity alone, and that is the exact shape
 * the rule exists to forbid.
 *
 * WHAT CHANGED, AND WHY THE EARLIER VERSION WAS NOT FINISHED. The first version took the
 * merchant's word for the account id, stored it as a pending claim, and waited for a human to
 * approve. It was safe -- it granted nothing -- but it could never produce a working link:
 * nothing in it ever learned a Lynomia USER id, and SSO is minted per user, so an approved
 * claim was a row that could not be opened. The missing half was never an approval step. It
 * was a way to find out, truthfully, who the claimant is on the Lynomia side.
 *
 * That now exists, and it lives in Lynomia because Lynomia is the only side that can check.
 * An administrator of the account signs in there -- which is the authentication -- and mints a
 * one-time code (Integrations::AccountLinkCode). The merchant pastes it here. Store Syria
 * forwards it server to server to POST /platform/api/v1/account_links, which re-reads the
 * author's administrator role, grants this app a permissible on the account AND on that user,
 * and answers with both ids.
 *
 * So the claimant asserts nothing. There is no field on this path that names an account, an
 * email or a user, and the only thing a merchant can submit is a string they could only have
 * got by signing in to the workspace they are claiming.
 *
 * BOTH GATES REMAIN, BECAUSE THEY ANSWER DIFFERENT QUESTIONS:
 *
 *   ownership_proven_at  Lynomia's answer to "does this person control that workspace?".
 *                        Cryptographic, server to server, and not forgeable from here.
 *   verified_at          A Store Syria operator's answer to "should this store be connected
 *                        to it?". Commercial, human, and deliberately not automatic.
 *
 * Proof without approval is a pending row that grants nothing. Approval without proof cannot
 * happen -- approve() refuses a row that was never proven -- so an operator cannot be talked
 * into connecting a workspace nobody demonstrated control of.
 */
class AccountLinker
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Link an existing Lynomia workspace, on a code the merchant minted inside it.
     *
     * THE REMOTE CALL HAPPENS BEFORE THE ROW IS WRITTEN, which is the opposite of the
     * provisioning order and right for the opposite reason. Provisioning claims the slot
     * first because the thing it is about to create does not exist yet and must not be
     * created twice. Here the workspace already exists and the code is single-use: writing a
     * row first would mean a row describing a claim we had not yet established, and a failure
     * in between would leave the store looking connected to an account that never agreed.
     *
     * @return array{link: LynomiaStoreLink, verification_code: string}
     *         The operator code is returned ONCE. It is not stored and cannot be recovered.
     */
    public function request(Store $store, User $owner, string $connectionCode): array
    {
        $existing = LynomiaStoreLink::forStore((int) $store->id)->first();

        $this->assertClaimable($existing);

        // Proof first. Lynomia answers with the account and the administrator who authorized
        // it, or refuses -- and a refusal here is the end of it, with nothing written.
        $proof = $this->api->redeemLinkCode($connectionCode);

        $code = strtoupper(Str::random(10));
        $ttl = (int) config('lynomia.link_verification_ttl_minutes', 60);

        $attributes = [
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'lynomia_account_id' => $proof['account_id'],
            'lynomia_user_id' => $proof['user_id'],
            'lynomia_account_name' => $proof['account_name'],
            'state' => LinkState::PENDING_VERIFICATION,
            'link_mode' => LynomiaStoreLink::MODE_LINK_EXISTING,
            'ownership_proven_at' => now(),
            'verification_code_hash' => Hash::make($code),
            'verification_expires_at' => now()->addMinutes($ttl),
            // A re-opened claim must not inherit a previous approval.
            'verified_at' => null,
            'verified_by' => null,
            'last_error' => null,
            'last_synced_at' => now(),
        ];

        try {
            if ($existing !== null) {
                $existing->forceFill($attributes)->save();
                $link = $existing;
            } else {
                $link = LynomiaStoreLink::create($attributes);
            }
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Another store already holds this workspace -- the unique index on
            // lynomia_account_id is the "no unauthorized cross-store sharing" rule, enforced
            // by the database rather than by a check that could lose the race.
            //
            // THE GRANT IS HANDED BACK ONLY IF NOTHING ELSE IS STANDING ON IT.
            //
            // Refusing locally while keeping the grant would leave the orphaned access this
            // flow exists to prevent. But the index that fired here is almost always
            // lynomia_account_id, and that index firing MEANS another store's link already
            // owns this workspace -- so the grant is not a thing the redemption just created,
            // it is the thing that store's working link depends on. Revoking it unprompted
            // breaks SSO for an innocent store, removes the workspace from the recovery
            // lookup, and makes the only operator remedy produce a second workspace with all
            // the conversations stranded in the first.
            //
            // Not hypothetical and not an attack: one merchant with two stores, following the
            // instructions on the page, pasting the code for store A's workspace into store
            // B's form.
            if (! LynomiaStoreLink::where('lynomia_account_id', $proof['account_id'])->exists()) {
                $this->handBackGrant($proof['account_id']);
            }

            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'That Lynomia workspace is already connected to another store.'
            );
        }

        AuditLog::record('lynomia_link_requested', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'lynomia_user_id' => $link->lynomia_user_id,
            'ownership_proven' => true,
            'expires_at' => (string) $link->verification_expires_at,
        ]);

        return ['link' => $link, 'verification_code' => $code];
    }

    /**
     * A claim may be opened on nothing, or on an unapproved claim -- nothing else.
     *
     * Refusing every non-pending state is what stops a REVOKED or SUSPENDED row being re-opened
     * by the merchant. REVOKED is documented terminal and is an operator's deliberate act,
     * often for abuse; SUSPENDED keeps its ids so the SAME workspace is reconnected, which a
     * claim naming a different one would throw away. Both are operator business now.
     *
     * It is also what keeps a pending row free of identifiers it did not earn: before this
     * existed, re-opening a revoked row rewrote lynomia_account_id while leaving
     * lynomia_user_id untouched, so an operator approving "a claim on account X" was in fact
     * restoring SSO into the workspace they had just revoked.
     */
    private function assertClaimable(?LynomiaStoreLink $existing): void
    {
        if ($existing === null) {
            return;
        }

        // A PROVISIONING ROW IS NEVER CLAIMABLE, WHATEVER STATE IT IS IN.
        //
        // An unfinished provision looks exactly like an open claim -- pending_verification --
        // but it may already own a workspace. Overwriting it turns the row into a claim on a
        // different account and leaves the one this app created with NOTHING referencing it:
        // no row to find it by, no revocation to hand its grant back, and provision() refusing
        // to touch the store again because the mode no longer matches. That is an unreachable
        // workspace holding a merchant's conversations plus a grant Lynomia still honours --
        // both of the outcomes this whole flow exists to prevent, reachable by a merchant with
        // two tabs open.
        //
        // The null-id case is no safer: a create whose answer was lost leaves no id here while
        // the workspace exists upstream, and converting the row to a claim retires the
        // adopt-before-create lookup that was going to recover it.
        //
        // So an unfinished provision is finished ("Continue setup", which resumes it) or
        // released by an operator. It is not something a merchant can abandon by starting a
        // different flow.
        if ($existing->link_mode === LynomiaStoreLink::MODE_PROVISION) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                $existing->state === LinkState::ACTIVE
                    ? 'This store already has a Lynomia workspace.'
                    : 'Setting up this store\'s workspace is still in progress. Finish it, or contact support to start over.'
            );
        }

        if ($existing->state === LinkState::PENDING_VERIFICATION) {
            return;
        }

        throw new LynomiaApiException(
            LynomiaApiException::KIND_CONFLICT,
            $existing->state === LinkState::ACTIVE
                ? 'This store is already connected to a Lynomia workspace.'
                : "This store's Lynomia connection was ended by support. Please contact support to restore it."
        );
    }

    /**
     * Give back a grant this app should not be holding.
     *
     * Best effort, and deliberately so: the local refusal has already been decided and must
     * stand whether or not Lynomia is reachable. A failure here is recorded for an operator
     * rather than thrown, because turning "we could not hand the grant back" into "your
     * request failed" would invite the merchant to retry and redeem a second code.
     */
    private function handBackGrant(int $accountId): void
    {
        try {
            $this->api->revokeAccountLink($accountId);
        } catch (LynomiaApiException $e) {
            Log::warning('[lynomia] could not hand back an account grant', [
                'lynomia_account_id' => $accountId,
                'kind' => $e->kind,
            ]);
        }
    }

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

        // APPROVAL CANNOT SUBSTITUTE FOR PROOF. An operator answers "should this store be
        // connected to that workspace?"; they are in no position to answer "does this merchant
        // control it?", and must not be able to grant access by being persuaded. A row without
        // ownership_proven_at was never confirmed by Lynomia, and a row without both ids could
        // not open anything even if approved -- which is how the old claim flow produced
        // approved links that silently refused to work.
        if ($link->ownership_proven_at === null || ! $link->isProvisioned()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'This claim was never confirmed by Lynomia and cannot be approved.'
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

        // THE MERCHANT'S OWN WORKSPACE IS GIVEN BACK; ONE THIS APP CREATED IS NOT.
        //
        // For a linked workspace the grant is something a merchant administrator handed over,
        // and revoking the link without returning it would leave Lynomia trusting this app
        // with an account Store Syria no longer has any record of -- an access with no
        // mapping, which is the orphan this flow exists to avoid. Handing it back also kills
        // any SSO link already minted, which a revocation for abuse needs.
        //
        // For a PROVISIONED workspace the account is this app's own creation and the grant is
        // the only way anyone can ever reach it again. Dropping that would orphan the
        // merchant's conversations beyond recovery, so a provisioned link keeps its grant and
        // the revocation is local: no SSO is issued, and the row records why.
        if ($link->link_mode === LynomiaStoreLink::MODE_LINK_EXISTING && $link->lynomia_account_id !== null) {
            $this->handBackGrant((int) $link->lynomia_account_id);
        }

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

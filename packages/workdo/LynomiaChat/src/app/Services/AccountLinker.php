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
use Workdo\LynomiaChat\app\Support\StoreIdentity;

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
 * An administrator of the account signs in there -- which is the authentication -- selects the
 * integration the code is for, and mints a one-time code (Integrations::AccountLinkCode). The
 * merchant pastes it here. Store Syria forwards it server to server to
 * POST /platform/api/v1/account_links, which re-reads the author's administrator role, checks
 * the code was issued for THIS integration, grants this app a permissible on the account, and
 * names the administrator who authorized it.
 *
 * THE GRANT IS THE WORKSPACE AND NOTHING ELSE. It deliberately does not include the authorizing
 * administrator's own identity: minting a link for a real person produces a session that is
 * theirs everywhere, so a proof about ONE workspace would have bought the ability to act as
 * them in every workspace they belong to. Who signs in afterwards, as whom, and with what role
 * is decided per person by IdentityProvisioner.
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
    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly IdentityProvisioner $identities,
        private readonly SeatAllowanceSync $seats
    ) {
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

        // Captured BEFORE the row is overwritten. A pending row may already carry a workspace
        // from an earlier claim, and that workspace is one Lynomia has already granted this
        // app access to. Overwriting the row in place would discard the only reference to it
        // and leave administrative access nothing in Store Syria records -- the orphan this
        // flow exists to avoid, reachable with no race at all: claim 7001, then claim 7002.
        $displacedAccountId = $existing?->lynomia_account_id;

        // Proof first. Lynomia answers with the account and the administrator who authorized
        // it, or refuses -- and a refusal here is the end of it, with nothing written.
        $proof = $this->api->redeemLinkCode($connectionCode);

        $code = strtoupper(Str::random(10));
        $ttl = (int) config('lynomia.link_verification_ttl_minutes', 60);

        $attributes = [
            'store_id' => (int) $store->id,
            'owner_user_id' => (int) $owner->creatorId(),
            'lynomia_account_id' => $proof['account_id'],
            // AUDIT, NOT ACCESS. This is the Lynomia administrator who authorized the claim.
            // Redemption deliberately does NOT grant this app anything on them -- their
            // session would be unscoped, so a proof about one workspace would buy the ability
            // to act as that person in every workspace they belong to. Nobody signs in as
            // them; people get their own identities (IdentityProvisioner). The id is kept so
            // an operator reviewing the claim can see who on the Lynomia side vouched for it.
            'lynomia_user_id' => $proof['authorized_by_user_id'],
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
                // A CONDITIONAL UPDATE, BECAUSE THE DECISION IS OLDER THAN THE WRITE.
                //
                // assertClaimable ran against a snapshot taken before a remote call that can
                // take fifteen seconds, and an operator can approve the claim it was shown
                // inside that window. A plain save() writes only the columns dirty relative
                // to that stale snapshot: `state` matches in both, so it is NOT in the
                // UPDATE, and the operator's state=active survives while the ids they
                // approved are silently replaced -- an ACTIVE link, with verified_by set, to
                // a workspace nobody approved. Reproduced before this guard existed.
                //
                // Re-asserting the state in the WHERE clause makes the database refuse the
                // write when the row is no longer the one the decision was made about. The
                // affected-row count is meaningful here because verification_code_hash is a
                // fresh random hash on every call, so the SET list always changes something
                // even on MySQL, which counts rows CHANGED rather than matched.
                $written = LynomiaStoreLink::query()
                    ->whereKey($existing->getKey())
                    ->where('state', LinkState::PENDING_VERIFICATION)
                    ->where('link_mode', LynomiaStoreLink::MODE_LINK_EXISTING)
                    // AND the row must still carry the workspace this caller is responsible
                    // for handing back. Fencing only on state catches pending -> active but
                    // not pending -> pending: two concurrent claims over the same pending row
                    // both read the same displaced id, both succeed, and both return THAT
                    // one -- so the workspace the first claim wrote is left with no row and
                    // no revocation. Naming the value makes the write optimistic on exactly
                    // the field whose previous contents it owes a hand-back for.
                    ->where(function ($query) use ($displacedAccountId) {
                        $displacedAccountId === null
                            ? $query->whereNull('lynomia_account_id')
                            : $query->where('lynomia_account_id', $displacedAccountId);
                    })
                    ->update($attributes + ['updated_at' => now()]);

                if ($written !== 1) {
                    $this->handBackGrantIfUnreferenced($proof['account_id']);

                    throw new LynomiaApiException(
                        LynomiaApiException::KIND_CONFLICT,
                        'This connection changed while your request was being confirmed. Please check the page and try again.'
                    );
                }

                $link = $existing->fresh();
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
            $this->handBackGrantIfUnreferenced($proof['account_id']);

            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'That Lynomia workspace is already connected to another store.'
            );
        }

        // The claim this one replaced left a grant behind. Nothing references that workspace
        // now, so nothing could ever revoke it -- hand it back here or never.
        if ($displacedAccountId !== null && (int) $displacedAccountId !== (int) $proof['account_id']) {
            $this->handBackGrantIfUnreferenced((int) $displacedAccountId);
        }

        AuditLog::record('lynomia_link_requested', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'authorized_by_lynomia_user_id' => $link->lynomia_user_id,
            'authorized_by_name' => $proof['authorized_by_name'],
            'ownership_proven' => true,
            'expires_at' => (string) $link->verification_expires_at,
        ]);

        return ['link' => $link, 'verification_code' => $code];
    }

    /**
     * A claim may be opened on nothing, or on an unapproved claim -- nothing else.
     *
     * Refusing every non-pending state is what stops a REVOKED row being re-opened by the
     * merchant. Revocation is an operator's deliberate act, often for abuse, and undoing it is
     * theirs too -- SuperAdmin\IntegrationController::restore exists for exactly that and is
     * the only way back.
     *
     * It is also what keeps a pending row free of identifiers it did not earn: before this
     * existed, re-opening a revoked row rewrote lynomia_account_id while leaving
     * lynomia_user_id untouched, so an operator approving "a claim on account X" was in fact
     * restoring SSO into the workspace they had just revoked.
     */
    /**
     * RE-AUTHORISE THE WORKSPACE THIS LINK ALREADY HOLDS. Not a claim, and that distinction is
     * the whole of the design.
     *
     * WHY THIS EXISTS. A merchant removed the Store Syria Integration through Lynomia's
     * Connected platforms page. That deleted the one `platform_app_permissibles` row the
     * integration rests on, so every platform call for their workspace answered 401 -- and there
     * was NO WAY BACK. The only mechanism that can restore a permissible is a merchant-minted
     * connection code redeemed through `POST /platform/api/v1/account_links`, and `request()`
     * above refuses to redeem one for this store: `assertClaimable` rejects any link in
     * MODE_PROVISION, which is exactly what a workspace this app created is. The refusal is
     * right for its own purpose -- an unfinished provision must never be converted into a claim
     * on a different account -- and simply had no case for "the same account, again".
     *
     * THE SAFETY PROPERTY IS ONE COMPARISON, and it is stronger than the claim path's.
     *
     *     the account the code proves  ===  the account this link already names
     *
     * A claim has to work out which workspace a code is for and then guard against it being
     * someone else's. A recovery does not: this store is already bound to account 135, so the
     * code is used ONLY as proof that the person minting it administers 135. A code for any
     * other workspace is refused precisely BECAUSE the link already names one -- so a merchant
     * cannot take over another merchant's workspace through this path even with a valid code for
     * it, and cannot re-point their own store at a different workspace either. Nothing is
     * written unless the ids match.
     *
     * WHAT IT DOES NOT TOUCH. `link_mode` stays MODE_PROVISION -- this app did provision the
     * workspace and that remains true. `state`, `lynomia_account_id`, `owner_user_id`,
     * `lynomia_commerce_store_id` and the commerce credential version are all left exactly as
     * they are: nothing about the arrangement changed, only Lynomia's willingness to admit us,
     * and the redemption restores precisely that.
     *
     * REPEATABLE. Lynomia's `grant_one` is idempotent (`find_or_create_by!` plus a
     * RecordNotUnique rescue), so redeeming a second fresh code changes nothing upstream, and
     * `AuthorizationHealth::clear` is a no-op once already cleared. A merchant who presses the
     * button twice, or whose first response was lost, is not punished for it.
     *
     * @throws LynomiaApiException
     */
    public function reauthorize(LynomiaStoreLink $link, User $actor, string $connectionCode): LynomiaStoreLink
    {
        if (! $link->isProvisioned()) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('This store has no Lynomia Chat workspace to recover. Connect one first.')
            );
        }

        // Proof first, and a refusal here ends it with nothing written -- the same ordering
        // request() uses, for the same reason.
        $proof = $this->api->redeemLinkCode($connectionCode);

        // THE GUARD. A valid code for the wrong workspace is the cross-tenant takeover attempt,
        // and it is refused on the strength of what this row already says rather than on
        // anything the caller supplied.
        if ((int) ($proof['account_id'] ?? 0) !== (int) $link->lynomia_account_id) {
            AuditLog::record('lynomia_authorization_recovery_refused', $link, [], [
                'store_id' => $link->store_id,
                'expected_account_id' => (int) $link->lynomia_account_id,
                // The id the code actually proved. Recorded because an operator investigating a
                // refused recovery needs to know whether this was a merchant pasting the wrong
                // code or something worth looking at; it is this app's own view of a workspace
                // it was just shown, not a disclosure of anybody's data.
                'presented_account_id' => (int) ($proof['account_id'] ?? 0),
                'actor_id' => (int) $actor->id,
            ]);

            // THE REDEMPTION ALREADY HAPPENED, SO "NOTHING WAS WRITTEN" IS ONLY TRUE LOCALLY.
            //
            // redeemLinkCode burned the code upstream AND created this app's permissible on the
            // workspace it proved. Refusing here without disposing of it leaves exactly what
            // request() calls "the orphaned access this flow exists to prevent": a standing grant
            // on a workspace no row in this database references, which nothing will ever hand
            // back because nothing can find it. Reached without an attack -- a merchant with two
            // stores pasting store A's code into store B's recovery form.
            //
            // `IfUnreferenced` is the whole safety of doing it here: if any link still names that
            // workspace it belongs to a store that did nothing wrong, and revoking it would break
            // their SSO. Best-effort by design -- a failed hand-back logs and does not turn the
            // merchant's refusal into a 500.
            $this->handBackGrantIfUnreferenced((int) ($proof['account_id'] ?? 0));

            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('That code is for a different Lynomia Chat workspace. Generate the code inside the workspace this store is already connected to.')
            );
        }

        // The grant is back. Record who vouched for it and that we are permitted again; the
        // workspace, its id, its state and its commerce installation are untouched.
        $attributes = [
            'lynomia_user_id' => $proof['authorized_by_user_id'] ?? $link->lynomia_user_id,
            'lynomia_account_name' => $proof['account_name'] ?? $link->lynomia_account_name,
            'last_synced_at' => now(),
        ];

        // `last_error` IS ONLY OURS TO CLEAR WHEN THE ARRANGEMENT IS INTACT.
        //
        // On an ACTIVE row the only thing that can have written it is a failure of the very
        // thing just fixed, so clearing it is the honest record. On any other state it holds an
        // OPERATOR's reason for ending the arrangement -- InstallationLifecycle::move writes the
        // suspend/decommission reason there (480 chars) and revoke() writes the revocation
        // reason -- and a merchant restoring a grant has not answered any of that. Nulling it
        // would let a merchant-initiated action erase an operator's record from the row; the
        // audit rows keep a copy, but truncated to 200 characters, so it is not a clean restore.
        if ($link->isActive()) {
            $attributes['last_error'] = null;
        }

        $link->forceFill($attributes)->save();

        // THE GRANT THAT CAME BACK IS THE ACCOUNT'S, AND THAT IS NOT THE WHOLE OF WHAT WAS LOST.
        //
        // Lynomia's revoke destroys the per-USER permissibles alongside the account's
        // (Integrations::AccountGrants#release_unneeded_user_grants), and redeeming a code
        // restores only the account one -- deliberately, because granting the authorizing
        // administrator's own identity would hand this app an UNSCOPED session for a person who
        // consented to one workspace (account_links_controller.rb says so at length).
        //
        // So the stale `lynomia_store_identities` rows are the problem: they name Lynomia users
        // this app no longer holds, IdentityProvisioner resolves them, skips creation, and
        // `GET /platform/api/v1/users/:id/login` answers 401 because that route checks the
        // permissible on the USER, not just the account. The merchant would be told their
        // connection was restored and then be unable to open their inbox -- and the next failure
        // would re-light the banner, so the recovery loops.
        //
        // handBackGrant has forgotten the workspace for this exact reason since it was written:
        // "a later re-link to the same workspace resolves them, skips creation, and fails at the
        // SSO call with an authorization error the merchant can do nothing about." The next SSO
        // mints fresh identities through POST /platform/api/v1/users, which re-grants the
        // permissible on each person as it resolves them.
        $this->identities->forgetWorkspace((int) $link->lynomia_account_id);

        AuthorizationHealth::clear($link);

        AuditLog::record('lynomia_authorization_recovered_by_merchant', $link->fresh(), [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => (int) $link->lynomia_account_id,
            'authorized_by_lynomia_user_id' => $proof['authorized_by_user_id'] ?? null,
            'actor_id' => (int) $actor->id,
        ]);

        return $link->fresh();
    }

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
     * Undo a revocation, so an operator is not the only thing standing between a merchant who
     * settled their bill and a workspace they still own.
     *
     * REVOCATION IS NOT SYMMETRIC, SO NEITHER IS THIS. A provisioned workspace kept its grant
     * when it was revoked, so restoring it is just a state change -- the access never went
     * away. A linked workspace had its grant handed back, so this app genuinely cannot reach
     * it any more and no state change can conjure that back: the row is returned to an empty
     * claim and the merchant re-proves ownership with a fresh code. Clearing the ids also
     * releases the unique index, which is what was keeping the workspace unreachable from
     * every other store as well.
     */
    public function restore(LynomiaStoreLink $link, User $actor): LynomiaStoreLink
    {
        if ($link->state !== LinkState::REVOKED) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                'Only a revoked connection can be restored.'
            );
        }

        $attributes = $link->link_mode === LynomiaStoreLink::MODE_PROVISION && $link->isProvisioned()
            ? ['state' => LinkState::ACTIVE, 'last_error' => null]
            : [
                'state' => LinkState::PENDING_VERIFICATION,
                'lynomia_account_id' => null,
                'lynomia_user_id' => null,
                'lynomia_account_name' => null,
                'ownership_proven_at' => null,
                'verified_at' => null,
                'verified_by' => null,
                'verification_code_hash' => null,
                'verification_expires_at' => null,
                'last_error' => null,
            ];

        $link->forceFill($attributes)->save();

        // Bring the commerce installation back with it, for the same reason revoking stops
        // it. Only for a link that still has a workspace: a claim reset to
        // pending_verification has had its account id cleared and has nothing to resume.
        $this->stopInstallation($link->refresh(), 'resume', 'connection restored', $actor);

        AuditLog::record('lynomia_link_restored', $link, ['state' => LinkState::REVOKED], [
            'state' => $link->state,
            'store_id' => $link->store_id,
            'actor_id' => (int) $actor->id,
        ]);

        return $link->refresh();
    }

    /**
     * Give back a grant this app should not be holding.
     *
     * Best effort, and deliberately so: the local refusal has already been decided and must
     * stand whether or not Lynomia is reachable. A failure here is recorded for an operator
     * rather than thrown, because turning "we could not hand the grant back" into "your
     * request failed" would invite the merchant to retry and redeem a second code.
     */
    private function handBackGrantIfUnreferenced(int $accountId): void
    {
        // A grant another link is standing on is not ours to return: revoking it would break
        // SSO for a store that did nothing wrong and hide its workspace from the recovery
        // lookup.
        if (LynomiaStoreLink::where('lynomia_account_id', $accountId)->exists()) {
            return;
        }

        $this->handBackGrant($accountId);
    }

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

        // THE IDENTITIES GO WITH THE GRANT, and unconditionally -- including when the remote
        // call above failed. Lynomia releases the per-user permissibles along with the
        // account's, so those remote users are no longer ones this app may mint a link for.
        // Keeping the rows would mean a later re-link to the same workspace resolves them,
        // skips creation, and fails at the SSO call with an authorization error the merchant
        // can do nothing about. Forgetting them is also right when the revoke did not land:
        // the next link mints fresh identities, which is correct either way.
        $this->identities->forgetWorkspace($accountId);
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

        // Approval is the other moment a workspace becomes usable, so the seat allowance is
        // pushed here too -- a linked workspace is included in the merchant's package exactly as
        // a provisioned one is.
        $this->seats->syncQuietly($link->refresh(), $approver);

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
        // THE INSTALLATION IS STOPPED FIRST, AND THE ORDER IS THE WHOLE POINT.
        //
        // Until P6B this method's only remote effect was the account grant, so a revoked
        // installation stayed `active` in `commerce_stores` and Partners::Eligibility went on
        // authorising its campaigns indefinitely -- with the operator who had just revoked it
        // having every reason to believe they had stopped it.
        //
        // P6B added this call and put it AFTER the hand-back below, which left the defect
        // exactly where it was for a linked workspace: `revokeAccountLink` releases this app's
        // permissible on the account, and `validate_platform_app_permissible` then answers the
        // lifecycle call with 401. stopInstallation logs that at warning level and returns, so
        // the store stayed active and nothing said otherwise. Stopping the installation while
        // this app still has the authority to is the only ordering that works.
        //
        // `suspend`, not `disconnect`: revocation is reversible by restore() below, and
        // Lynomia's disconnect drops the stored credentials, which would quietly make it
        // permanent. Decommissioning is the transition that is meant to be final.
        $this->stopInstallation($link, 'suspend', $reason, $actor);

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

    /**
     * Carry a link-state change across to Lynomia's commerce installation.
     *
     * BEST EFFORT, AND DELIBERATELY SO -- unlike InstallationLifecycle, which pushes first and
     * refuses to write locally if the push fails. The difference is what the operator asked
     * for. Here they asked to revoke or restore a LINK; the installation is a consequence, and
     * a Lynomia that cannot be reached must not leave the link un-revoked, which would be the
     * worse failure of the two. The disagreement it can leave behind is visible: the Super
     * Admin page's "In Lynomia" column reads the far side's actual state, and the reconciler
     * re-pushes on the next successful pass.
     */
    private function stopInstallation(LynomiaStoreLink $link, string $transition, string $reason, User $actor): void
    {
        if ($link->lynomia_account_id === null) {
            return;
        }

        try {
            $this->api->installationLifecycle(
                (int) $link->lynomia_account_id,
                StoreIdentity::of((int) $link->store_id),
                $transition,
                $reason,
                (string) ($actor->email ?? $actor->id)
            );
        } catch (LynomiaApiException $e) {
            Log::warning('[lynomia] the installation state could not be carried across', [
                'store_id' => $link->store_id, 'transition' => $transition, 'kind' => $e->kind,
            ]);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}

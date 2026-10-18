<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\CommerceState;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * TURNS COMMERCE ON FOR A STORE THAT ALREADY HAS A WORKSPACE, WITHOUT ANYBODY HANDLING A TOKEN.
 *
 * THE STEP THIS REPLACES. Provisioning a workspace was one merchant click; finishing the job was
 * a Store Syria operator minting an `lsc_` credential in Super Admin, a human carrying it to
 * Lynomia, and an administrator of that workspace pasting it into a form. Everything in between
 * worked -- the credential, the commerce API, the provider adapter, the tenancy checks -- and the
 * only thing missing was something to carry the credential across the boundary on the merchant's
 * behalf. That is all this class is.
 *
 * WHY THE ORCHESTRATION IS HERE AND NOT IN LYNOMIA. This side already holds the three facts the
 * decision needs and two of them are things Lynomia cannot see: the merchant's session, the
 * store's ownership, and the derivation of the credential. Having Lynomia PULL a credential would
 * mean exporting the first two across the boundary and giving Lynomia a reason to call a partner
 * for a secret -- a far larger trust claim than accepting one. It also sits beside
 * AccountProvisioner, which orchestrates the other half of the same onboarding with the same
 * resumption and locking machinery, rather than being a second half-finished flow in another
 * repository.
 *
 * THE CREDENTIAL IS NOT ROTATED TO INSTALL. A store with an active credential re-sends THAT one;
 * only a store with none mints. This is deliberate and it is the difference between a retry that
 * repairs and a retry that breaks: rotating invalidates every token minted before it, so an
 * activation that rotated every time would take down the working installation it was being asked
 * to verify. Nothing is read out of a database to do this -- the credential is derived from the
 * store id and the current version, so re-deriving is free and the value is never stored.
 *
 * FOUR PROPERTIES, each paid for somewhere specific:
 *
 *   one at a time            a conditional UPDATE on commerce_activation_started_at decides which
 *                            of two concurrent callers proceeds. Lynomia's own Redis store lock is
 *                            behind it; this one is what stops the loser being an error a merchant
 *                            has to interpret.
 *   no duplicate installs    the remote operation is idempotent and keyed on the store's external
 *                            id. A lost response is recovered by asking again, not by creating.
 *   nothing claimed falsely  only an answer from Lynomia sets commerce_installed_at and
 *                            lynomia_commerce_store_id. A failure records the reason and clears
 *                            nothing it did not learn.
 *   bounded                  commerce_attempts counts, and the merchant's button is the retry.
 *                            There is no background re-attempt loop that could rotate or install
 *                            unattended.
 */
class CommerceActivator
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Connect (or re-connect) this store's commerce data to its workspace.
     *
     * @return array{state:string, applied:bool, commerce_store_id:?int}
     *
     * @throws LynomiaApiException
     */
    public function activate(LynomiaStoreLink $link, User $actor): array
    {
        return $this->run($link, $actor, 'activate');
    }

    /**
     * Re-obtain the confirmation rather than assume it.
     *
     * THE SAME CALL, WHICH IS THE POINT. The remote operation is idempotent, so "verify" is
     * "install again and see what it says": an already-connected store answers `applied: false`
     * and nothing is written upstream, which is exactly the safe no-op a merchant pressing
     * "Verify connection" on a working integration deserves. A separate read-only endpoint would
     * have been a second thing to keep in step with the first, and it could not have repaired
     * anything.
     */
    public function verify(LynomiaStoreLink $link, User $actor): array
    {
        return $this->run($link, $actor, 'verify');
    }

    /**
     * STOP LYNOMIA READING THIS STORE, AT THE MERCHANT'S REQUEST.
     *
     * THE LOCAL HALF IS THE ONE THAT MATTERS, AND IT GOES FIRST. Revoking the credential is a
     * UNILATERAL stop: the version is inside the signed material and the commerce middleware
     * re-reads it on every request, so every token already issued stops verifying at once --
     * without a deploy, without a cache expiry, and WITHOUT LYNOMIA'S COOPERATION. That is what
     * makes a disconnect hold while the other system is unreachable, which is the property
     * "revocation must stop unauthorized reads even when one system is unavailable" actually
     * asks for. Telling Lynomia is the tidy-up, not the enforcement.
     *
     * SO THE PUSH IS BEST EFFORT AND ITS FAILURE IS NOT THE MERCHANT'S PROBLEM. If it does not
     * land, Lynomia keeps a row it can no longer authenticate with: its next provider call fails
     * and it moves the store to `needs_reauth` by itself. Nothing is readable in the meantime.
     * Reporting that as a failed disconnect would be telling the merchant their data is still
     * being read when it is not.
     *
     * NOT A WORKSPACE DISCONNECT. The merchant keeps their workspace, their conversations and
     * their team; this ends the store-data half only, and reconnecting is a button away.
     *
     * @return array{state:string, told_lynomia:bool}
     */
    public function disconnect(LynomiaStoreLink $link, User $actor): array
    {
        // THE SAME GATE AS CONNECT, and not merely for symmetry. A SUSPENDED link already has no
        // commerce access -- AuthenticateCommerceApi refuses every request for it -- so there is
        // nothing for the merchant to stop; what a push WOULD do is drop Lynomia's stored
        // credentials, and Commerce::StoreConnection#enable refuses to bring back a store whose
        // credentials are gone. A merchant action would then have made an operator's pending
        // resume impossible, from the far side, with nothing on either console saying why.
        $this->assertWorkspaceUsable($link);

        // And nothing to disconnect is not an error worth a failure: the button only renders for
        // a connected store, so this is a direct post, and the honest answer is the current state.
        if ($link->lynomia_commerce_store_id === null) {
            return ['state' => CommerceState::of($link), 'told_lynomia' => false];
        }

        // THE ENFORCEMENT, and it cannot fail for a reason outside this process.
        $link->forceFill([
            'commerce_credential_version' => (int) $link->commerce_credential_version + 1,
            'commerce_credential_issued_at' => null,
        ])->save();

        $told = true;

        try {
            $this->api->installationLifecycle(
                (int) $link->lynomia_account_id,
                StoreIdentity::of((int) $link->store_id),
                'disconnect',
                'Disconnected by the merchant from Store Syria.',
                (string) ($actor->email ?? $actor->id)
            );
        } catch (LynomiaApiException $e) {
            $told = false;
            Log::warning('[lynomia] commerce disconnect could not be pushed', [
                'store_id' => $link->store_id,
                'lynomia_account_id' => $link->lynomia_account_id,
                'kind' => $e->kind,
            ]);
        }

        $link->forceFill([
            // Recorded only when Lynomia confirmed it. Where it did not, the revoked credential
            // already makes CommerceState read DISCONNECTED, so the merchant is told the truth
            // either way and nothing is written that Lynomia has not said.
            'commerce_remote_status' => $told ? 'disconnected' : $link->commerce_remote_status,
            'commerce_verified_at' => now(),
            'commerce_last_error' => null,
            'commerce_attempts' => 0,
        ])->save();

        AuditLog::record('lynomia_commerce_disconnected', $link->fresh(), [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'commerce_credential_version' => (int) $link->fresh()->commerce_credential_version,
            'told_lynomia' => $told,
            'actor_id' => (int) $actor->id,
        ]);

        return ['state' => CommerceState::of($link->fresh()), 'told_lynomia' => $told];
    }

    private function run(LynomiaStoreLink $link, User $actor, string $purpose): array
    {
        $this->assertEligible($link);

        $claim = $this->acquireClaim($link);

        if ($claim === null) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_IN_PROGRESS,
                __('Your store data is already being connected. Please wait a moment and refresh.')
            );
        }

        try {
            return $this->install($link, $actor, $purpose);
        } finally {
            // Released whether the attempt succeeded or threw: a failed attempt that kept the
            // claim would read as "connecting" and refuse every retry until it went stale.
            $this->releaseClaim($link, $claim);
        }
    }

    /**
     * Everything an INSTALL needs: a usable workspace, and an installation able to mint.
     *
     * The second half is not in assertWorkspaceUsable because a DISCONNECT does not need it:
     * revoking is a version bump and derives nothing. Refusing a merchant's "stop sharing"
     * because the signing key is missing would refuse it for the very reason nothing could be
     * read anyway. Checked before the claim so a misconfigured installation says so rather than
     * counting an attempt against a store that never had a chance; mint() asserts it again,
     * because a pre-flight check is never the only thing standing between a caller and a key.
     */
    private function assertEligible(LynomiaStoreLink $link): void
    {
        $this->assertWorkspaceUsable($link);

        if (! CommerceCredential::isConfigured()) {
            throw LynomiaApiException::notConfigured('LYNOMIA_WEBHOOK_MASTER_SECRET');
        }
    }

    /**
     * The gate, and it is the same one SSO and the commerce API already use.
     *
     * allowsSso() is active + provisioned + (for a CLAIMED workspace) ownership proven by
     * Lynomia. Reusing it rather than writing a new predicate is what makes this impossible to
     * get wrong: a pending claim, a suspended link, a revoked one and an approved-but-unproven
     * one are all refused here for exactly the reasons they are refused there, and a future
     * change to that rule applies to commerce activation automatically.
     */
    private function assertWorkspaceUsable(LynomiaStoreLink $link): void
    {
        if (! $link->allowsSso()) {
            // TWO REASONS, AND THEY NEED DIFFERENT SENTENCES. A store with no workspace yet is
            // told to connect one, which it can do itself. An ACTIVE link that still fails the
            // check is a claimed workspace Lynomia has not confirmed the claimant for, or a link
            // an operator has paused -- neither of which the merchant can resolve by pressing
            // anything, so sending them to "connect first" would be sending them nowhere.
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                $link->isProvisioned()
                    ? __('Your Lynomia Chat connection is not ready for store data yet. Please contact support.')
                    : __('Connect this store to Lynomia Chat first.')
            );
        }
    }

    private function install(LynomiaStoreLink $link, User $actor, string $purpose): array
    {
        $externalStoreId = StoreIdentity::of((int) $link->store_id);
        $credential = $this->credentialFor($link);
        // READ AFTER MINTING, NOT BEFORE: credentialFor() may have rotated, and the version this
        // records has to be the one actually on the wire.
        $version = (int) $link->fresh()->commerce_credential_version;

        try {
            $result = $this->api->installCommerce(
                (int) $link->lynomia_account_id,
                $externalStoreId,
                $this->baseUrl(),
                $credential,
                $this->storeName($link)
            );
        } catch (LynomiaApiException $e) {
            $this->recordFailure($link, $e);

            throw $e;
        }

        $this->recordSuccess($link, $result, $version);

        AuditLog::record('lynomia_commerce_installed', $link->fresh(), [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'lynomia_commerce_store_id' => $result['commerce_store_id'],
            'external_store_id' => $externalStoreId,
            'remote_status' => $result['status'],
            'applied' => $result['applied'],
            'purpose' => $purpose,
            'actor_id' => (int) $actor->id,
            // The credential is never recorded. Only that one was sent, and which version is
            // current, which is already on the row.
        ]);

        return [
            'state' => CommerceState::of($link->fresh()),
            'applied' => $result['applied'],
            'commerce_store_id' => $result['commerce_store_id'],
        ];
    }

    /**
     * Re-send the active credential; mint only when there is none.
     *
     * A REVOKED CREDENTIAL IS "NONE". commerceCredentialStatus() reads revoked as its own state
     * precisely so this can tell it from active, and re-deriving a revoked version would send a
     * token the commerce middleware is guaranteed to refuse -- the version check is a database
     * read on every request, so there is no window in which it would work.
     */
    private function credentialFor(LynomiaStoreLink $link): string
    {
        return $link->hasActiveCommerceCredential()
            ? CommerceCredential::issue($link)
            : CommerceCredential::mint($link);
    }

    /**
     * WHAT LYNOMIA WILL CALL BACK ON, and it is this installation's own URL rather than anything a
     * merchant supplied. There is no field for a merchant to put a URL in, which is how the SSRF
     * question is answered here rather than defended against: Lynomia still parses and filters it
     * (Commerce::StoreUrl, Commerce::HttpClient), but nothing merchant-controlled ever reaches
     * that parser.
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function storeName(LynomiaStoreLink $link): ?string
    {
        $name = (string) (Store::find((int) $link->store_id)?->name ?? '');

        return $name === '' ? null : Str::limit($name, 60, '');
    }

    /**
     * @param int $version the credential version that was actually sent
     */
    private function recordSuccess(LynomiaStoreLink $link, array $result, int $version): void
    {
        $link->forceFill([
            // Lynomia's own id for the installation, learned directly instead of by coincidence.
            // Only overwritten when it actually answered with one.
            'lynomia_commerce_store_id' => $result['commerce_store_id'] ?? $link->lynomia_commerce_store_id,
            // WHAT LYNOMIA NOW HOLDS. Written here and nowhere else, because this is the only
            // moment anything hands Lynomia a credential. A later rotation that does not come
            // through here leaves this behind the current version, which is what makes the drift
            // visible instead of looking like a healthy connection -- see CommerceState.
            'commerce_installed_version' => $version,
            'commerce_remote_status' => $result['status'],
            'commerce_installed_at' => $link->commerce_installed_at ?? now(),
            'commerce_verified_at' => now(),
            'commerce_last_error' => null,
            'commerce_attempts' => 0,
        ])->save();
    }

    /**
     * A LOCALE-FREE REASON CODE, NOT A SENTENCE.
     *
     * This used to store the rendered message, and that put the FAILING REQUEST'S language in a
     * row that every user of the tenant reads: an owner working in Arabic produced an Arabic
     * sentence which then appeared, verbatim, between the English badge and the English button on
     * a colleague's page -- and because the stored text was not a dictionary key, no later __()
     * could recover it. The code is mapped to a sentence by CommerceState::reason() at render
     * time, in the reader's language.
     *
     * The mapping is on the exception's own `errorCode` where it has one, because that is the
     * machine-readable reason both sides of this integration define, and on `kind` otherwise.
     */
    private function recordFailure(LynomiaStoreLink $link, LynomiaApiException $e): void
    {
        // NOT INCREMENTED HERE. acquireClaim() already counted this attempt -- it has to, because
        // the counter is what makes the conditional UPDATE's affected-row count meaningful on
        // MySQL -- and counting it twice would make the ceiling an operator reads off this column
        // wrong by a factor of two.
        $link->forceFill([
            'commerce_last_error' => $this->reasonCode($e),
            'commerce_verified_at' => now(),
        ])->save();

        Log::warning('[lynomia] commerce activation failed', [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $link->lynomia_account_id,
            'kind' => $e->kind,
            'status' => $e->status,
        ]);
    }

    /**
     * Which recorded reason this failure is, as a token CommerceState can translate.
     *
     * Matched on the error code Lynomia defines where there is one -- the same strings
     * LynomiaApiException::fromStatus maps to merchant sentences -- so the two cannot describe the
     * same refusal differently. Anything unrecognised is a category, because there is nothing in
     * it a merchant could act on.
     */
    private function reasonCode(LynomiaApiException $e): string
    {
        return match (true) {
            $e->errorCode === 'commerce_disabled' => CommerceState::REASON_PLAN,
            $e->errorCode === 'installation_claimed_elsewhere' => CommerceState::REASON_CLAIMED,
            $e->errorCode === 'store_limit_reached' => CommerceState::REASON_STORE_LIMIT,
            in_array($e->errorCode, ['provider_disabled', 'auth_invalid', 'platform_owned_account'], true) => CommerceState::REASON_REFUSED,
            in_array($e->kind, [LynomiaApiException::KIND_UNREACHABLE, LynomiaApiException::KIND_RATE_LIMITED], true) => CommerceState::REASON_UNREACHABLE,
            default => CommerceState::REASON_UNKNOWN,
        };
    }

    /**
     * Take the in-flight claim, or report that someone else holds it.
     *
     * A CONDITIONAL UPDATE, NOT A READ FOLLOWED BY A WRITE -- the same idiom, and the same
     * reasoning, as AccountProvisioner::acquireLock. Two requests that both SELECT an unclaimed
     * row both decide they may proceed; one UPDATE carrying the condition is settled by the
     * database. The attempt counter is in the SET list for the reason documented there too: MySQL
     * reports rows CHANGED rather than MATCHED, so two attempts inside the same whole second
     * would write an identical timestamp, report 0 changed, and tell the winner it had lost.
     *
     * @return \Illuminate\Support\Carbon|null the claim taken, or null if somebody else holds it
     */
    private function acquireClaim(LynomiaStoreLink $link)
    {
        $claimedAt = now();

        $claimed = LynomiaStoreLink::query()
            ->whereKey($link->getKey())
            ->where(function ($query) {
                $query->whereNull('commerce_activation_started_at')
                      ->orWhere('commerce_activation_started_at', '<', CommerceState::staleBefore());
            })
            ->update([
                'commerce_activation_started_at' => $claimedAt,
                'commerce_attempts' => DB::raw('commerce_attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $link->refresh();

        return $claimedAt;
    }

    /**
     * Release only the claim this caller took.
     *
     * THE TIMESTAMP IS THE FENCING TOKEN, which is why it is passed back in rather than read off
     * the row: an attempt that overran its window does not know it was superseded, and an
     * unconditional release would clear the SUCCESSOR's claim and let a third request start while
     * the second is still mid-flight.
     */
    private function releaseClaim(LynomiaStoreLink $link, $claimedAt): void
    {
        LynomiaStoreLink::query()
            ->whereKey($link->getKey())
            ->where('commerce_activation_started_at', $claimedAt)
            ->update(['commerce_activation_started_at' => null, 'updated_at' => now()]);
    }
}

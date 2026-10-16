<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * SUSPEND, RESUME, RE-AUTHENTICATE AND DECOMMISSION ONE INSTALLATION, ON BOTH SIDES AT ONCE.
 *
 * THE DEFECT THIS EXISTS TO CLOSE. The two lifecycles were independent state machines with no
 * transition channel between them. Revoking a link here tore down the account grant and left
 * `commerce_stores.status` at `active` in Lynomia, so `Partners::Eligibility` went on
 * authorising that installation's campaigns -- indefinitely, and invisibly to the operator
 * who had just revoked it. Every transition below now carries across.
 *
 * THE REMOTE CALL GOES FIRST, AND THE LOCAL WRITE ONLY IF IT SUCCEEDED.
 *
 * The orders are not equivalent. Writing locally first and pushing second means a failed push
 * leaves this side saying "suspended" while Lynomia keeps sending -- the exact disagreement
 * this service was built to end, now with an operator who has been told it is handled.
 * Pushing first means a failure leaves both sides where they were and the operator sees the
 * error: nothing half-done, and the action can simply be repeated. The upstream transition is
 * idempotent, so repeating it is safe.
 *
 * WHY REVOKE AND SUSPEND BOTH MAP TO `disabled`, AND ONLY DECOMMISSION TO `disconnect`.
 * Lynomia's `disconnect` drops the stored credentials. A revoked link is restorable precisely
 * because its credentials survive, so mapping revoke to `disconnect` would quietly turn a
 * reversible operator action into a permanent one. Decommission is the transition that is
 * meant to be permanent, and it is the only one that drops them.
 *
 * LOCKED, because two operators -- or one operator and a retry -- acting on the same
 * installation would otherwise interleave their reads and writes, and the loser would audit a
 * change that never happened.
 */
class InstallationLifecycle
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /** A hold on a relationship nobody has ended. The merchant keeps their workspace and data. */
    public function suspend(LynomiaStoreLink $link, User $actor, string $reason): LynomiaStoreLink
    {
        return $this->move($link, $actor, $reason, [LinkState::ACTIVE], LinkState::SUSPENDED, 'suspend',
            'lynomia_installation_suspended');
    }

    /** Back from a suspension or a revocation. */
    public function resume(LynomiaStoreLink $link, User $actor, string $reason): LynomiaStoreLink
    {
        return $this->move($link, $actor, $reason, LinkState::RESUMABLE, LinkState::ACTIVE, 'resume',
            'lynomia_installation_resumed');
    }

    /**
     * Ended for good. Lynomia drops its stored credentials; the row, the conversations and the
     * consent evidence stay, because what a person was told and agreed to outlives the
     * commercial arrangement that produced it.
     */
    public function decommission(LynomiaStoreLink $link, User $actor, string $reason): LynomiaStoreLink
    {
        return $this->move($link, $actor, $reason, [LinkState::ACTIVE, LinkState::SUSPENDED, LinkState::REVOKED],
            LinkState::DECOMMISSIONED, 'disconnect', 'lynomia_installation_decommissioned');
    }

    /**
     * THE WAY OUT OF A REJECTED CREDENTIAL, in the order the credential actually travels.
     *
     * A rejected credential used to be an unattended transition with no remedy: Lynomia's
     * store sat in `needs_reauth`, nothing here knew, and the only fix was to issue a new
     * credential by hand and hope.
     *
     * WHY THE RESUME IS ATTEMPTED BUT NOT REQUIRED. The commerce credential is never stored
     * on either side -- it is minted here, shown to an operator once, and PASTED INTO LYNOMIA
     * BY THEM. Bumping the version invalidates the one Lynomia is holding at that instant, so
     * the resume that follows asks Lynomia to health-check a store whose credential has, for
     * the moment, deliberately stopped working. It is expected to fail, and it must not take
     * the new credential down with it: an operator left looking at an error with no credential
     * to paste is strictly worse off than before they pressed the button.
     *
     * So the resume is best effort and its outcome is reported. It succeeds when the
     * installation was suspended for a reason other than its credential -- which is the other
     * case this button serves -- and when it does not, the operator pastes the credential in
     * and presses Resume.
     *
     * @return array{0: LynomiaStoreLink, 1: string, 2: bool} the link, the credential to show
     *                                                        once, and whether it is back
     */
    public function reauthenticate(LynomiaStoreLink $link, User $actor): array
    {
        $previous = (int) $link->commerce_credential_version;

        $link->forceFill([
            'commerce_credential_version' => $previous + 1,
            'commerce_credential_issued_at' => now(),
        ])->save();

        AuditLog::record('lynomia_installation_reauthenticated', $link,
            ['commerce_credential_version' => $previous],
            [
                'commerce_credential_version' => $previous + 1,
                'store_id' => $link->store_id,
                'actor_id' => (int) $actor->id,
                // The credential itself is NEVER audited. Only the fact and the version.
            ]);

        $link = $link->refresh();
        $resumed = false;

        try {
            $link = $this->move($link, $actor, 'credential reissued', LinkState::RESUMABLE, LinkState::ACTIVE,
                'resume', 'lynomia_installation_resumed');
            $resumed = true;
        } catch (LynomiaApiException) {
            // Expected while Lynomia still holds the credential this call just invalidated.
        }

        return [$link, CommerceCredential::issue($link), $resumed];
    }

    /**
     * @param  array<int,string>  $from  the states this transition may start from
     */
    private function move(
        LynomiaStoreLink $link,
        User $actor,
        string $reason,
        array $from,
        string $to,
        string $transition,
        string $auditEvent
    ): LynomiaStoreLink {
        $previous = $link->state;

        if (! in_array($previous, $from, true) && $previous !== $to) {
            throw new LynomiaApiException(
                LynomiaApiException::KIND_CONFLICT,
                __('This connection cannot be moved to that state from where it is now.')
            );
        }

        $this->push($link, $transition, $reason, $actor);

        return DB::transaction(function () use ($link, $actor, $reason, $previous, $to, $auditEvent) {
            $locked = LynomiaStoreLink::query()->lockForUpdate()->find($link->id);
            $locked->forceFill(['state' => $to, 'last_error' => Str::limit($reason, 480)])->save();

            AuditLog::record($auditEvent, $locked, ['state' => $previous], [
                'state' => $to,
                'store_id' => $locked->store_id,
                'reason' => Str::limit($reason, 200),
                'actor_id' => (int) $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * A link that never reached Lynomia has nothing to tell it -- a claim abandoned before
     * approval has no workspace and no store. That is not a failure to report; there is simply
     * no far side yet.
     */
    private function push(LynomiaStoreLink $link, string $transition, string $reason, User $actor): void
    {
        if ($link->lynomia_account_id === null) {
            return;
        }

        $this->api->installationLifecycle(
            (int) $link->lynomia_account_id,
            StoreIdentity::of((int) $link->store_id),
            $transition,
            $reason,
            (string) ($actor->email ?? $actor->id)
        );
    }
}

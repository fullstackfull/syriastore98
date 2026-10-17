<?php

namespace Workdo\LynomiaChat\app\Support;

use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * WHERE A STORE'S COMMERCE CONNECTION HAS GOT TO, DERIVED AND STORED NOWHERE.
 *
 * THERE IS NO `commerce_state` COLUMN, DELIBERATELY. Seven facts already describe parts of this,
 * and an eighth that paraphrased them would be the one that drifts out of step: the workspace
 * state decides whether commerce is even permitted, the credential version and issued-at decide
 * whether a token would verify, `lynomia_commerce_store_id` and `commerce_remote_status` are
 * Lynomia's own answers about the installation, and `commerce_last_error` /
 * `commerce_activation_started_at` are the record of the last attempt. Every one of them has
 * exactly one writer. A derived reading cannot disagree with them; a stored enum could, and the
 * first thing it would do on disagreeing is show a merchant a green tick over a dead connection.
 *
 * THE ORDER OF THE BRANCHES IS THE DESIGN. Workspace eligibility comes first, because a suspended
 * link must not advertise a commerce connection it is not allowed to use whatever Lynomia still
 * holds. The far side's own status comes before this side's error, because if Lynomia has told us
 * the installation is suspended then that is what is wrong, not whatever our last attempt
 * happened to fail on. An in-flight claim comes before "not connected", so a merchant who has
 * just clicked does not read "not connected" for the few seconds the call takes.
 */
final class CommerceState
{
    /** The workspace is not in a state where commerce could be connected at all. */
    public const UNAVAILABLE = 'unavailable';

    /** Workspace is ready, commerce has never been installed (or we have never been told it was). */
    public const NOT_CONNECTED = 'not_connected';

    /** An attempt is in flight right now. */
    public const CONNECTING = 'connecting';

    /** Lynomia holds an active installation for this store. The only fully-working reading. */
    public const CONNECTED = 'connected';

    /** The installation exists and a Lynomia operator switched it off. Not ours to undo. */
    public const SUSPENDED = 'suspended';

    /** Lynomia rejected the credential. Re-minting and re-installing is the repair. */
    public const NEEDS_REPAIR = 'needs_repair';

    /** The installation was disconnected on the Lynomia side. Connecting again is allowed. */
    public const DISCONNECTED = 'disconnected';

    /** The last attempt failed. `commerce_last_error` carries the merchant-safe reason. */
    public const FAILED = 'failed';

    private function __construct()
    {
    }

    public static function of(?LynomiaStoreLink $link): string
    {
        // Commerce is an extension of a usable workspace and never outlives one. A link that
        // cannot be opened cannot read orders either -- AuthenticateCommerceApi refuses every
        // commerce request for it -- so claiming a connection here would be claiming something
        // the data path has already closed.
        if ($link === null || ! $link->allowsSso()) {
            return self::UNAVAILABLE;
        }

        // AN INSTALLATION THAT CANNOT AUTHENTICATE IS NOT A CONNECTION, whatever Lynomia's row
        // still says. The credential is the only thing that lets Lynomia read this store, and
        // revoking it is a UNILATERAL stop: it takes effect on the next request, needs no
        // cooperation from the other side, and works while the other side is unreachable. So a
        // revoked credential is read here as a disconnected installation -- which is both true
        // and actionable, because reconnecting mints a new one and re-installs it.
        //
        // Checked before the far side's status for exactly that reason: a merchant who has just
        // disconnected, or an operator who has just revoked in Super Admin, must not be shown
        // "connected" for however long it takes the two systems to agree.
        if ($link->lynomia_commerce_store_id !== null && ! $link->hasActiveCommerceCredential()) {
            return self::DISCONNECTED;
        }

        // LYNOMIA'S OWN ANSWER NEXT, where we have one. These three are states of the
        // installation, not of our attempt to make one, and each has a different next step.
        $remote = (string) ($link->commerce_remote_status ?? '');

        if ($remote === 'disabled') {
            return self::SUSPENDED;
        }

        if ($remote === 'needs_reauth') {
            return self::NEEDS_REPAIR;
        }

        if ($remote === 'disconnected') {
            return self::DISCONNECTED;
        }

        if ($link->commerce_last_error !== null) {
            return self::FAILED;
        }

        if ($link->lynomia_commerce_store_id !== null) {
            return self::CONNECTED;
        }

        // READ LAST AND WITH THE SAME STALENESS WINDOW THE CLAIM ITSELF USES, so a process killed
        // mid-activation shows "not connected" with a working button rather than "connecting"
        // forever. The claim is the lock; this is only how it reads.
        if ($link->commerce_activation_started_at !== null
            && $link->commerce_activation_started_at->isAfter(self::staleBefore())) {
            return self::CONNECTING;
        }

        return self::NOT_CONNECTED;
    }

    /** True when the merchant has a connection they can actually use right now. */
    public static function usable(?LynomiaStoreLink $link): bool
    {
        return self::of($link) === self::CONNECTED;
    }

    /**
     * Whether offering the merchant a button would be honest.
     *
     * CONNECTED is absent on purpose -- there is nothing to connect -- and so is SUSPENDED, which
     * is a Lynomia operator's decision that a merchant retrying cannot and should not reverse.
     */
    public static function actionable(?LynomiaStoreLink $link): bool
    {
        return in_array(self::of($link), [
            self::NOT_CONNECTED,
            self::NEEDS_REPAIR,
            self::DISCONNECTED,
            self::FAILED,
        ], true);
    }

    /** The same window AccountProvisioner's claim goes stale after, read from the same setting. */
    public static function staleBefore(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(max(1, (int) config('lynomia.provisioning_lock_minutes', 5)));
    }
}

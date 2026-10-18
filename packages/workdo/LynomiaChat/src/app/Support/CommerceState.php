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

    /** The last attempt failed. `commerce_last_error` carries the reason CODE; see reason(). */
    public const FAILED = 'failed';

    /**
     * The reasons an attempt can fail, as locale-free tokens.
     *
     * STORED, NOT RENDERED. These are what goes in commerce_last_error; the sentence is produced
     * by reason() at render time, in the reader's language rather than the failing request's.
     */
    public const REASON_PLAN = 'plan_excludes_commerce';
    public const REASON_CLAIMED = 'claimed_elsewhere';
    public const REASON_STORE_LIMIT = 'store_limit_reached';
    public const REASON_REFUSED = 'refused_by_lynomia';
    public const REASON_UNREACHABLE = 'unreachable';
    public const REASON_UNKNOWN = 'unknown';

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

        $remote = (string) ($link->commerce_remote_status ?? '');
        $installed = $link->lynomia_commerce_store_id !== null;

        // FIRST, THE ONE STATE THE MERCHANT CANNOT ACT ON AT ALL. A Lynomia operator switched this
        // installation off, and nothing the merchant presses will turn it back on: the partner
        // install endpoint deliberately leaves a `disabled` store alone and answers applied=false.
        // So this is read before anything on this side, including a revoked credential -- the two
        // genuinely co-occur (an operator pauses the store there, another revokes the token here),
        // and reading the credential first offered a Connect button whose press changed nothing.
        if ($remote === 'disabled') {
            return self::SUSPENDED;
        }

        // THEN THE TWO WAYS A CREDENTIAL AND AN INSTALLATION COME APART, because both look exactly
        // like a healthy connection in every other column and both are repaired by reconnecting.
        if ($installed) {
            // Revoked here -- by the merchant stopping the sharing, or by an operator in Super
            // Admin. Lynomia keeps a row it can no longer authenticate with, so there is no
            // connection, whatever that row says.
            if (! $link->hasActiveCommerceCredential()) {
                return self::DISCONNECTED;
            }

            // ROTATED HERE AND NEVER RE-INSTALLED. The hard case, and the reason
            // commerce_installed_version exists: issuing a new credential in Super Admin bumps the
            // version and leaves Lynomia holding the previous one, while the version is current,
            // issued_at is set, the installation id is recorded and the far side still reports
            // `active`. Nothing else on the row can see it. Comparing what we last GAVE Lynomia
            // against what is current can, and it covers every rotation path rather than the one
            // button somebody remembered to flag.
            //
            // A NULL IS NOT DRIFT, AND THAT DISTINCTION IS THE WHOLE CARE OF THIS BRANCH. Null
            // means nothing has ever installed a credential FROM HERE -- a store connected by hand
            // before this existed, or one the sweep merely observed -- so which version Lynomia
            // holds is genuinely unknown. Reading unknown as drift would put a "Reconnect" prompt
            // over every working manually-connected store, which is the same lie as the one this
            // branch exists to stop, told in the other direction. Drift is only claimed where
            // there is a recorded fact to contradict.
            if ($link->commerce_installed_version !== null
                && (int) $link->commerce_installed_version !== (int) $link->commerce_credential_version) {
                return self::NEEDS_REPAIR;
            }
        }

        if ($remote === 'needs_reauth') {
            return self::NEEDS_REPAIR;
        }

        if ($remote === 'disconnected') {
            return self::DISCONNECTED;
        }

        // A CONFIRMED INSTALLATION OUTRANKS A FAILED ATTEMPT, and getting this the wrong way round
        // was worse than it sounds. A failed attempt writes only commerce_last_error -- it clears
        // nothing it did not learn -- so a merchant pressing "Verify connection" while Lynomia was
        // briefly unreachable turned a live, reading installation into "Not connected", and took
        // the "Stop sharing store data" control away with it. The one person who might have wanted
        // to stop the sharing was told there was nothing to stop. The error is still shown, as a
        // note beside the connection rather than instead of it.
        if ($installed) {
            return self::CONNECTED;
        }

        // No installation and a reason why not: that is a failed setup, and it has a retry.
        if ($link->commerce_last_error !== null) {
            return self::FAILED;
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

    /**
     * Whether there is a last-attempt failure worth showing BESIDE the state rather than as it.
     *
     * A connected store can carry a stale error -- a verify that timed out against a connection
     * that is working -- and the honest rendering is the connection plus a note, not a red cross
     * over a live integration.
     */
    public static function staleFailureNote(?LynomiaStoreLink $link): ?string
    {
        if ($link === null || $link->commerce_last_error === null) {
            return null;
        }

        return self::of($link) === self::CONNECTED ? self::reason($link) : null;
    }

    /**
     * The merchant-safe sentence for a recorded failure, TRANSLATED AT RENDER TIME.
     *
     * The column stores a locale-free token, not a sentence. It used to store the rendered string,
     * which meant the row carried whichever language the failing request happened to run under:
     * an owner working in Arabic produced an Arabic sentence that then appeared, verbatim, between
     * the English badge and the English button on a colleague's page -- and because the stored
     * text was not a dictionary key, no later __() could recover it. Locale is per user and every
     * user of the tenant reads the same row, so the only thing that can be stored is the reason.
     */
    public static function reason(?LynomiaStoreLink $link): ?string
    {
        $code = (string) ($link->commerce_last_error ?? '');

        if ($code === '') {
            return null;
        }

        return match ($code) {
            self::REASON_PLAN => __('Your Lynomia Chat plan does not include store data yet. Please contact support to enable it.'),
            self::REASON_CLAIMED => __('Your store data is already connected to a different Lynomia Chat workspace. Please contact support.'),
            self::REASON_STORE_LIMIT => __('Your Lynomia Chat plan has no room for another connected store. Please contact support.'),
            self::REASON_REFUSED => __('Store data could not be connected for this store. Please contact support.'),
            self::REASON_UNREACHABLE => __('Lynomia Chat is temporarily unreachable. Please try again shortly.'),
            // Includes any token written by an older release, which cannot be mapped and must not
            // be printed -- a stored sentence from before this column held codes would render as
            // itself, in whatever language it was written.
            default => __('Store data could not be connected just now. You can try again.'),
        };
    }

    /** The same window AccountProvisioner's claim goes stale after, read from the same setting. */
    public static function staleBefore(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(max(1, (int) config('lynomia.provisioning_lock_minutes', 5)));
    }
}

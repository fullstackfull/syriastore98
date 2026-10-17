<?php

namespace Workdo\LynomiaChat\app\Support;

/**
 * The states a store's Lynomia link can hold, and the only place they are spelled.
 *
 * Written as a closed vocabulary rather than free strings because the previous integration
 * carried `event_type` as an unvalidated string and accumulated values that nothing
 * dispatched. A typo here is a class constant that does not exist, which is a fatal at the
 * call site rather than a row nobody notices.
 */
final class LinkState
{
    /**
     * A link_existing claim has been made and is waiting for administrative approval. The
     * store has NO access in this state: nothing is attached on the Lynomia side yet.
     */
    public const PENDING_VERIFICATION = 'pending_verification';

    /** Provisioned or approved. The store may open its workspace. */
    public const ACTIVE = 'active';


    /**
     * Unlinked by an operator. Terminal for the merchant -- they cannot re-open it -- but NOT
     * terminal for the row: SuperAdmin\IntegrationController::restore returns it to a usable
     * state, which is what a billing hold needs once the bill is settled.
     */
    public const REVOKED = 'revoked';

    /**
     * Paused by an operator, and meant to come back.
     *
     * SUSPENDED was once declared here and then DELETED, because nothing wrote it: no service,
     * no controller, no job, while three places branched on it as though it meant something.
     * It is back because P6B gave it a writer -- suspend() and resume() on the lifecycle
     * service -- and, more to the point, because it now MEANS something on the other side:
     * suspending pushes Lynomia's store to `disabled`, which is what actually stops the
     * sending. A state that only this database believed in was worth deleting; one that both
     * systems act on is not.
     *
     * Distinct from REVOKED on purpose. Revoking is an operator withdrawing the arrangement
     * and it takes the merchant's access with it; suspending is a hold on a relationship
     * nobody has ended, so the merchant keeps their workspace and their data and the
     * installation simply stops acting until it is resumed.
     */
    public const SUSPENDED = 'suspended';

    /**
     * Ended, deliberately and for good.
     *
     * This is the one state that drops Lynomia's stored credentials (its store moves to
     * `disconnected`), which is why it is separate from REVOKED rather than a flag on it: a
     * revoked link can be restored because its credentials are still there, and a
     * decommissioned one cannot be, because they are gone. Making them one state would have
     * meant either never dropping the credentials or never being able to restore.
     *
     * NOT A DELETE. The row stays, the merchant's conversations stay, and the consent
     * evidence stays -- what a person was told and agreed to outlives the commercial
     * arrangement that produced it, and a decommission that took it with the installation
     * would destroy the only record that an opt-out ever happened.
     */
    public const DECOMMISSIONED = 'decommissioned';

    public const ALL = [
        self::PENDING_VERIFICATION,
        self::ACTIVE,
        self::SUSPENDED,
        self::REVOKED,
        self::DECOMMISSIONED,
    ];

    /** States an operator can bring back. DECOMMISSIONED is absent deliberately. */
    public const RESUMABLE = [self::SUSPENDED, self::REVOKED];

    /** States in which a merchant may be handed an SSO link. */
    public const SSO_ALLOWED = [self::ACTIVE];

    /**
     * States from which a recovery window may be opened at all.
     *
     * Identical to RESUMABLE by construction rather than by coincidence: a window exists only to
     * let Lynomia health-check an installation an operator is bringing back, so the states it
     * covers are exactly the states an operator can bring back. DECOMMISSIONED is absent for the
     * same reason it is absent there -- there is nothing to return to -- and PENDING_VERIFICATION
     * is absent because an installation that was never active is being provisioned, not recovered.
     */
    public const RECOVERY_ELIGIBLE = self::RESUMABLE;

    private function __construct()
    {
    }
}

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
     *
     * There was a SUSPENDED state here too, documented as the reversible one. Nothing ever
     * wrote it: no service, no controller, no job. It was a state the product could reach only
     * by hand-editing a row, and three places branched on it as though it meant something.
     * Removed rather than given a writer, because the reversibility it promised is what
     * restore() actually provides.
     */
    public const REVOKED = 'revoked';

    public const ALL = [
        self::PENDING_VERIFICATION,
        self::ACTIVE,
        self::REVOKED,
    ];

    /** States in which a merchant may be handed an SSO link. */
    public const SSO_ALLOWED = [self::ACTIVE];

    private function __construct()
    {
    }
}

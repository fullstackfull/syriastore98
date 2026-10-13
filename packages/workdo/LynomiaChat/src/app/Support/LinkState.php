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
     * Access withdrawn without discarding the mapping -- a lapsed entitlement, a billing
     * hold, or an operator decision. Reversible, and it keeps the account ids so the same
     * workspace is reconnected rather than a second one created.
     */
    public const SUSPENDED = 'suspended';

    /** Deliberately unlinked. Terminal for this row. */
    public const REVOKED = 'revoked';

    public const ALL = [
        self::PENDING_VERIFICATION,
        self::ACTIVE,
        self::SUSPENDED,
        self::REVOKED,
    ];

    /** States in which a merchant may be handed an SSO link. */
    public const SSO_ALLOWED = [self::ACTIVE];

    private function __construct()
    {
    }
}

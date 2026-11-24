<?php

namespace App\Support\Marketing;

/**
 * WHY A MESSAGE IS BEING SENT, which decides whether consent is required. D8 / MKT-008.
 *
 * Only {@see self::Marketing} is ever held back by the consent store. The other two are
 * deliberately never blocked, because the owner's direction is explicit that necessary
 * transactional notifications must not be stopped indiscriminately: an order confirmation, a
 * receipt, a one-time password and a password reset are part of delivering what the person asked
 * for, and refusing them would break checkout, not protect anyone.
 *
 * THERE IS NO DEFAULT. A call site must say which of the three it is. A helper that assumed
 * "transactional" would let every unclassified sender bypass enforcement; one that assumed
 * "marketing" would silently stop receipts. Neither guess is safe, so the type system refuses to
 * make it, and the census of sender sites exists to make every site choose.
 */
enum MessageClass: string
{
    /** Necessary to deliver what the person asked for. Never blocked by marketing consent. */
    case Transactional = 'transactional';

    /** About the merchant's own store, sent to the merchant or their staff (stock alerts, new-order notices). Never blocked. */
    case Operational = 'operational';

    /** Anything whose purpose is to promote, remind, win back or cross-sell. Needs a verified permission. */
    case Marketing = 'marketing';

    public function needsConsent(): bool
    {
        return $this === self::Marketing;
    }
}

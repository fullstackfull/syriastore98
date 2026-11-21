<?php

namespace App\Support\Marketing;

/**
 * Where a person stands for one (store, channel, purpose) after every event so far.
 *
 * The transition table lives here, pure and with no database, so it can be tested exhaustively
 * and so the stored projection can be REBUILT from the event log and compared against it.
 *
 *   none        -- nothing has ever been recorded. NOT consent. This is what every existing
 *                  customer is today, and the whole point of D8 is that it stays that way:
 *                  consent is never inferred from an order, a registration or an address book.
 *   granted     -- an explicit opt-in is the latest word.
 *   withdrawn   -- the person (or a merchant on their behalf) opted out. They may opt in again.
 *   suppressed  -- a HARD block: a complaint, a legal request, a bounce. It outranks everything
 *                  except an explicit lift, and a lift does not grant, it only returns the person to
 *                  `withdrawn`, from which they must opt in afresh. A checkbox ticked at checkout
 *                  therefore cannot undo a complaint.
 */
enum ConsentState: string
{
    case None = 'none';
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
    case Suppressed = 'suppressed';

    /** Apply one event to a state and return the new state. */
    public function after(ConsentEvent $event): self
    {
        return match ($event) {
            ConsentEvent::Granted => $this === self::Suppressed ? self::Suppressed : self::Granted,
            ConsentEvent::Withdrawn => $this === self::Suppressed ? self::Suppressed : self::Withdrawn,
            ConsentEvent::Suppressed => self::Suppressed,
            ConsentEvent::SuppressionLifted => $this === self::Suppressed ? self::Withdrawn : $this,
        };
    }

    /** The only state in which a marketing message may be sent. */
    public function permitsMarketing(): bool
    {
        return $this === self::Granted;
    }
}

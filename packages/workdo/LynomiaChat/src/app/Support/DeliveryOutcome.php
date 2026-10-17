<?php

namespace Workdo\LynomiaChat\app\Support;

/**
 * WHAT IS KNOWN ABOUT A MESSAGE WE ASKED LYNOMIA TO SEND.
 *
 * NINE STATES, AND NOT ONE OF THEM IS "WE GOT A 200". That is the defect this vocabulary
 * exists for: `POST .../messages` answers 200 the moment the message row exists, and the
 * provider call, the UTILITY gate, the consent gate and the allowance meter all run
 * afterwards. Treating the 200 as a send meant recording "sent" for messages Lynomia went on
 * to refuse, and never learning otherwise.
 *
 * TWO OF THESE ARE OURS AND SEVEN ARE LYNOMIA'S. `ACCEPTED` and `UNKNOWN` are what this side
 * can say on its own: it got an answer, or it did not. Everything else arrives through the
 * delivery feed and is Lynomia reporting what actually happened.
 *
 * TERMINALITY IS NOT THE ORDERING RULE. A delivered message can legitimately be re-sent and
 * fail, and a failed one can be retried and delivered -- Lynomia allows both and its own specs
 * pin them. So the projector orders on the event's microsecond stamp, not on a ranking of
 * these names, and `terminal()` below is only used to decide what still needs chasing.
 */
final class DeliveryOutcome
{
    /** Lynomia took the request. Says nothing about whether anything was sent. */
    public const ACCEPTED = 'accepted';

    /** The message row exists on the far side and the provider has not answered for it. */
    public const QUEUED = 'queued';

    /** Handed to the provider. Lynomia reports this by having the provider's own id for it. */
    public const SUBMITTED = 'submitted';

    /** The provider acknowledged it. */
    public const PROVIDER_ACCEPTED = 'provider_accepted';

    /** The provider confirmed it reached the recipient. */
    public const DELIVERED = 'delivered';

    /** And that they opened it. Strictly more than delivered, kept separate because it is. */
    public const READ = 'read';

    /** The provider would not deliver it, or the attempt broke. */
    public const FAILED = 'failed';

    /** Lynomia itself would not send it: consent, the UTILITY rule, or the allowance. */
    public const REFUSED = 'refused';

    /**
     * Stopped before it went.
     *
     * NO PRODUCER IN THIS ARCHITECTURE, and that is a property of the send path rather than
     * an omission: `PlatformNotifier::notify` resolves the recipient, claims the row and
     * calls Lynomia in one synchronous pass, so there is no interval during which a notice
     * is outstanding and could be called back. A queued send path would need this state on
     * its first day, and a vocabulary with a hole in it is how the next person reaches for
     * `failed` to mean "we changed our mind", which is not the same fact.
     */
    public const CANCELLED = 'cancelled';

    /**
     * NOT A FAILURE, AND THE DISTINCTION MATTERS MOST HERE. The request may or may not have
     * arrived: a connection that died after the bytes left is indistinguishable from one that
     * died before. Treating it as failed invites a retry that messages somebody twice;
     * treating it as sent claims something nobody knows.
     */
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::ACCEPTED, self::QUEUED, self::SUBMITTED, self::PROVIDER_ACCEPTED,
        self::DELIVERED, self::READ, self::FAILED, self::REFUSED, self::CANCELLED, self::UNKNOWN,
    ];

    /**
     * Lynomia's feed vocabulary, mapped onto this one.
     *
     * `submitted` has no distinct producer today: Lynomia reports `provider_accepted` the
     * moment it has the provider's id, which is the same instant it would report a submission.
     * The state is kept because a provider that acknowledges asynchronously would need it, and
     * because leaving a hole in the vocabulary is how the next person fills it with `sent`.
     */
    public const FROM_FEED = [
        'queued' => self::QUEUED,
        'provider_accepted' => self::PROVIDER_ACCEPTED,
        'delivered' => self::DELIVERED,
        'read' => self::READ,
        'failed' => self::FAILED,
        'refused' => self::REFUSED,
    ];

    /** Nothing further is expected. Used to decide what reconciliation still has to chase. */
    public const TERMINAL = [self::DELIVERED, self::READ, self::REFUSED, self::CANCELLED];

    public static function fromFeed(?string $outcome): ?string
    {
        return self::FROM_FEED[$outcome] ?? null;
    }

    public static function terminal(?string $outcome): bool
    {
        return in_array($outcome, self::TERMINAL, true);
    }
}

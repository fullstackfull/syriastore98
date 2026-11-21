<?php

namespace App\Support\Marketing;

/**
 * WHO IS SENDING AN EMAIL, as far as the mail event can say. D8 / MKT-008. Immutable.
 *
 * `origin` is a class name (a Mailable or a Notification) or the word `raw` for a message built without either. `class` is what the
 * registry says it is FOR, or null when nobody has decided. `storeId` is the store the message can be attributed to from the event
 * alone, or null: a Mailable that does not carry its store (the vendored coupon mail) cannot be attributed, and that is a finding,
 * not a default.
 */
final class MailSender
{
    public const KIND_MAILABLE = 'mailable';

    public const KIND_NOTIFICATION = 'notification';

    public const KIND_RAW = 'raw';

    public function __construct(
        public readonly string $kind,
        public readonly string $origin,
        public readonly ?string $template,
        public readonly ?MessageClass $class,
        public readonly ?int $storeId,
    ) {}

    /** True when the registry says what this sender is for. An undecided sender is NOT treated as anything. */
    public function isDecided(): bool
    {
        return $this->class !== null;
    }

    /** A stable label for logs and the once-per-origin memo: the class, plus the template when the class is only a carrier. */
    public function label(): string
    {
        return $this->template === null ? $this->origin : $this->origin.' ['.$this->template.']';
    }
}

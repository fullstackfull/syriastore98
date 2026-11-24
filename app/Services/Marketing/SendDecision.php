<?php

namespace App\Services\Marketing;

/**
 * The gate's answer, with enough to act on and to log. Immutable.
 *
 * `send` is what the caller must do. `wouldHold` is what enforcement WOULD have done, which differs from
 * `send` in report mode: that is the whole of report mode, and it is why the two are separate fields.
 */
final class SendDecision
{
    private function __construct(
        public readonly bool $send,
        public readonly bool $wouldHold,
        public readonly string $reason,
        public readonly string $mode,
    ) {}

    public static function allow(string $reason, string $mode): self
    {
        return new self(true, false, $reason, $mode);
    }

    /** Enforcement is `enforce` and the message is held. */
    public static function hold(string $reason, string $mode): self
    {
        return new self(false, true, $reason, $mode);
    }

    /** Enforcement is `report`: the message goes, and the caller is told it would otherwise have been held. */
    public static function allowButWouldHold(string $reason, string $mode): self
    {
        return new self(true, true, $reason, $mode);
    }
}

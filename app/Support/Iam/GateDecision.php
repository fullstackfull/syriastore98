<?php

namespace App\Support\Iam;

use App\Models\OperatorActionApproval;

/**
 * `IAM-003` — what the two-person gate says about one attempt at a dangerous action.
 *
 * THREE OUTCOMES AND NOT A BOOLEAN, because the three have to produce three DIFFERENT responses
 * and a caller that cannot tell them apart will get one of them wrong:
 *
 *   `proceed`            the action is not governed (the shipped default). The handler runs
 *                        exactly as it did before this mechanism existed.
 *   `awaitingApproval`   the ask is recorded and **the effect does not exist**. The caller must
 *                        not perform it.
 *   `refused`            this principal may not even ask. Carries the reason, so a test can key
 *                        on WHICH rule refused rather than on a status another rule also produces.
 */
final class GateDecision
{
    public const PROCEED = 'proceed';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $kind,
        public readonly ?OperatorActionApproval $approval,
        public readonly string $reason,
    ) {
    }

    public static function proceed(): self
    {
        return new self(self::PROCEED, null, '');
    }

    public static function awaitingApproval(OperatorActionApproval $approval): self
    {
        return new self(self::AWAITING_APPROVAL, $approval, '');
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, null, $reason);
    }

    public function mayProceed(): bool
    {
        return $this->kind === self::PROCEED;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->kind === self::AWAITING_APPROVAL;
    }

    public function wasRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }
}

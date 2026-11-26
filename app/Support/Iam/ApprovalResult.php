<?php

namespace App\Support\Iam;

use App\Models\OperatorActionApproval;

/**
 * `IAM-003` — what happened when a second operator decided on a pending request.
 *
 * `$reason` IS LOAD-BEARING AND IS NOT A MESSAGE. It is a stable key naming which rule answered,
 * because the tests for this family have to tell a self-approval refusal apart from a
 * stale-state refusal apart from a non-operator refusal — and all three would otherwise be
 * "the row did not execute", which is also what a crash, a missing row and a typo produce.
 * CLAUDE.md's rule, measured repeatedly in this repository: key a refusal assertion on the REASON.
 */
final class ApprovalResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly string $reason,
        public readonly ?OperatorActionApproval $approval,
        public readonly ?ExecutionOutcome $outcome,
    ) {
    }

    public static function accepted(OperatorActionApproval $approval, ExecutionOutcome $outcome): self
    {
        return new self(true, '', $approval, $outcome);
    }

    public static function refused(string $reason, ?OperatorActionApproval $approval = null): self
    {
        return new self(false, $reason, $approval, null);
    }

    /** Did the approval both pass the gate AND produce the effect? */
    public function effectExists(): bool
    {
        return $this->accepted && $this->outcome !== null && $this->outcome->performed;
    }
}

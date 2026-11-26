<?php

namespace App\Support\Iam;

use App\Models\OperatorActionApproval;

/**
 * `IAM-003` — what performs a dangerous operator action AT APPROVAL TIME.
 *
 * THE INTERFACE EXISTS TO MAKE ONE PROPERTY STRUCTURAL RATHER THAN HOPED FOR: the effect of a
 * governed action is produced HERE, by the approval, and nowhere in the requesting path. The brief
 * for this family named the opposite — "the action taking effect at request time rather than
 * approval time" — as one of the two ways such a mechanism is wrong in practice. A gate that only
 * *records* a request while the handler carries on is that defect, and it is invisible to a test
 * that asserts a row was written.
 *
 * AN EXECUTOR MUST RE-ASK ITS OWN SAFETY QUESTIONS. It runs an unbounded time after the request —
 * minutes or days — so the fixture it was asked about may no longer be the one in front of it. A
 * plan with no subscriber when the request was made may have one when the approval lands.
 * Re-validating is not belt-and-braces; the value of the stored payload is the ASK, never the
 * permission.
 */
interface ApprovedActionExecutor
{
    /**
     * Perform the effect, or refuse.
     *
     * @param  array<string,mixed>  $payload  the arguments the gate recorded, already narrowed
     * @return ExecutionOutcome  what happened, which the caller writes onto the approval row
     */
    public static function execute(array $payload, OperatorActionApproval $approval): ExecutionOutcome;
}

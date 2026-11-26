#!/usr/bin/env python3
"""
`IAM-003` — MUTATIONS FOR TWO-PERSON APPROVAL OF DANGEROUS OPERATOR ACTIONS.

Twelve one-change mutations, each applied alone, run, and reverted. They attack the claims the
slice makes, because a control that cannot fail is not a control:

  1-2.  THE EFFECT IS NOT PRODUCED AT REQUEST TIME. (1) lets the handler run anyway; (2) makes the
        gate answer `proceed` instead of recording. Both are the defect the brief named first.
  3-4.  SELF-APPROVAL IS REFUSED. (3) deletes the comparison outright. (4) is the subtler one: it
        compares the approver against the APPROVER column instead of the requester, which is still
        "a comparison" and still refuses something -- a test keyed on "nothing happened" would miss
        it, and one keyed on the REASON does not.
  5.    THE PRINCIPAL IS CLASS-CHECKED. Dropping `instanceof User` is the `GAP-307` shape: a
        `Customer` row carrying the word `super admin` then approves.
  6.    THE OPERATOR TEST IS REAL. Replacing it with `true` admits a merchant.
  7.    EXECUTION HAPPENS ONCE. Dropping the state predicate from the claiming UPDATE makes a
        second approval execute again.
  8.    THE EXECUTOR RE-ASKS ITS SAFETY QUESTION. Removing the subscriber rule withdraws a plan a
        merchant is paying for.
  9.    THE EXECUTOR PARSES STRICTLY. `is_numeric` for `FILTER_VALIDATE_INT` is the exact
        substitution the skill names: it accepts `'5.9'` and rejects `'5abc'`, which MySQL reads as
        row 5.
  10.   A REJECTION PREVENTS THE EFFECT. Making `reject()` approve instead.
  11.   THE CENTRAL AUDIT ROW IS WRITTEN WITH THE RIGHT ACTOR. Passing the requester as the actor
        of the EXECUTION makes the trail unable to evidence two-person control -- the row count is
        unchanged, so only an actor assertion sees it.
  12.   THE SHIPPED DEFAULT IS OFF. Flipping it to `true` must redden the premise test, because an
        accidental flip would start refusing operator actions nobody decided to govern.

MUTATION 12 IS A PREMISE ATTACK IN THE OPPOSITE DIRECTION from the rest: every other case here
PASSES with enforcement on (they switch it on themselves), so nothing but the premise test can
notice the default changing.

Run: `python3 tests/Support/bin/mutate-iam003.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

SERVICE = 'app/Support/Iam/TwoPersonApproval.php'
GUARD = 'app/Http/Middleware/RequiresTwoPersonApproval.php'
EXECUTOR = 'app/Support/Iam/Executors/DisablePlanExecutor.php'
CONFIG = 'config/operator_approvals.php'
TEST = 'tests/Feature/Security/OperatorTwoPersonApprovalTest.php'

MUTATIONS = [
    Mutation(
        name='1. the door lets the handler run anyway (effect at request time)',
        path=GUARD,
        old="""        if ($decision->isAwaitingApproval()) {
            return $this->refuse($request, __(""",
        new="""        if (false && $decision->isAwaitingApproval()) {
            return $this->refuse($request, __(""",
        test_file=TEST,
        filter='test_with_the_control_on_the_request_alone_does_not_change_the_plan',
    ),
    Mutation(
        name='2. the gate answers proceed instead of recording the ask',
        path=SERVICE,
        old='        return GateDecision::awaitingApproval($approval);\n    }',
        new='        return GateDecision::proceed();\n    }',
        test_file=TEST,
        filter='test_with_the_control_on_the_request_alone_does_not_change_the_plan',
    ),
    Mutation(
        name='3. the self-approval comparison is deleted',
        path=SERVICE,
        old='        if ((int) $approver->id === (int) $approval->requested_by) {',
        new='        if (false) {',
        test_file=TEST,
        filter='test_the_requester_cannot_approve_their_own_request',
    ),
    Mutation(
        name='4. self-approval compares against approved_by instead of requested_by',
        path=SERVICE,
        old='        if ((int) $approver->id === (int) $approval->requested_by) {',
        new='        if ((int) $approver->id === (int) $approval->approved_by) {',
        test_file=TEST,
        filter='test_the_requester_cannot_approve_their_own_request',
    ),
    Mutation(
        name='5. the approver is no longer class-checked (GAP-307 shape)',
        path=SERVICE,
        old='        if (! $approver instanceof User || ! UserPolicy::isOperator($approver)) {\n            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);\n        }\n\n        /*\n         * SELF-APPROVAL.',
        new='        if (! UserPolicy::isOperator($approver)) {\n            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);\n        }\n\n        /*\n         * SELF-APPROVAL.',
        test_file=TEST,
        filter='test_a_customer_carrying_the_operators_type_cannot_approve',
    ),
    Mutation(
        name='6. the operator test on approval is replaced with true',
        path=SERVICE,
        old='        if (! $approver instanceof User || ! UserPolicy::isOperator($approver)) {\n            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);\n        }\n\n        /*\n         * SELF-APPROVAL.',
        new='        if (false) {\n            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);\n        }\n\n        /*\n         * SELF-APPROVAL.',
        test_file=TEST,
        filter='test_a_merchant_cannot_approve',
    ),
    Mutation(
        name='7. the claiming UPDATE drops its state predicate (executes twice)',
        path=SERVICE,
        old="""        $claimed = OperatorActionApproval::where('id', $approval->id)
            ->where('state', OperatorActionApproval::STATE_PENDING)
            ->update([
                'state' => OperatorActionApproval::STATE_APPROVED,
                'approved_by' => $approver->id,""",
        new="""        $claimed = OperatorActionApproval::where('id', $approval->id)
            ->update([
                'state' => OperatorActionApproval::STATE_APPROVED,
                'approved_by' => $approver->id,""",
        test_file=TEST,
        filter='test_approving_twice_executes_once',
    ),
    Mutation(
        name='8. the executor stops re-asking the subscriber rule',
        path=EXECUTOR,
        old='        if ($subscriber !== null && $planId !== 1 && $isDisable === 0) {',
        new='        if (false) {',
        test_file=TEST,
        filter='test_the_executor_refuses_when_a_merchant_subscribed_between_request_and_approval',
    ),
    Mutation(
        name='9. the executor parses the id with is_numeric instead of FILTER_VALIDATE_INT',
        path=EXECUTOR,
        old="        $planId = filter_var($payload['plan_id'] ?? null, FILTER_VALIDATE_INT);",
        new="        $planId = is_numeric($payload['plan_id'] ?? null) ? (int) ($payload['plan_id']) : (is_string($payload['plan_id'] ?? null) ? (int) $payload['plan_id'] : false);",
        test_file=TEST,
        filter='test_the_executor_refuses_a_payload_it_cannot_read',
    ),
    Mutation(
        name='10. reject() approves instead of rejecting',
        path=SERVICE,
        old="""                'state' => OperatorActionApproval::STATE_REJECTED,
                'approved_by' => $approver->id,
                'decided_at' => now(),""",
        new="""                'state' => OperatorActionApproval::STATE_EXECUTED,
                'approved_by' => $approver->id,
                'decided_at' => now(),""",
        test_file=TEST,
        filter='test_rejection_prevents_the_effect',
    ),
    Mutation(
        name='11. the execution audit row is attributed to the requester, not the approver',
        path=SERVICE,
        old="""                'detail' => $outcome->detail,
            ],
            $approver
        );

        return ApprovalResult::accepted($approval, $outcome);""",
        new="""                'detail' => $outcome->detail,
            ],
            User::find($approval->requested_by)
        );

        return ApprovalResult::accepted($approval, $outcome);""",
        test_file=TEST,
        filter='test_every_transition_reaches_the_central_audit_log_with_the_right_actor',
    ),
    Mutation(
        name='12. the shipped default starts enforcing (premise attack)',
        path=CONFIG,
        old="    'enforced' => env('OPERATOR_APPROVALS_ENFORCED', false),",
        new="    'enforced' => env('OPERATOR_APPROVALS_ENFORCED', true),",
        test_file=TEST,
        filter='test_premise_the_shipped_default_governs_nothing',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))

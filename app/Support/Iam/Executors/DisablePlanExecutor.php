<?php

namespace App\Support\Iam\Executors;

use App\Models\OperatorActionApproval;
use App\Models\Plan;
use App\Models\User;
use App\Support\Iam\ApprovedActionExecutor;
use App\Support\Iam\ExecutionOutcome;

/**
 * `IAM-003` — withdraw a subscription plan from sale, or put it back, once a SECOND operator has
 * approved it.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * `is_disable` IS INVERTED: **1 MEANS ENABLED**. Measured, and not by me first —
 * `PlatformSiteController.php:161` records the measurement and cites the evidence: the merchant
 * plan list is `Plan::where('is_disable', 1)` (`PlanController.php:175`), and the column's
 * migration declares `integer default(1)`
 * (`2024_03_13_062916_add_is_disable_to_plans_table.php:17`), so a brand-new plan is on sale.
 *
 * So the DANGEROUS direction is `is_disable = 0` — that is the one that takes a plan off the
 * platform. The column's name reads the other way round, and writing this executor from the name
 * would have governed the harmless direction.
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * THE SUBSCRIBER RULE IS RE-ASKED HERE, AND THAT IS THE POINT RATHER THAN DUPLICATION.
 * `PlanController::planDisable()` refuses when a user is subscribed to the plan, the plan is not
 * plan 1, and the direction is withdrawal. That handler asks the question when the REQUEST is
 * made. This executor runs after a second operator approves, which may be days later, so it asks
 * again against the table as it is NOW. A merchant who subscribed in between must not have their
 * plan withdrawn by an approval granted while the plan was empty.
 *
 * The two are therefore not two copies of one decision that can drift into disagreement — they
 * are the same rule asked at two different times, which is what a deferred action requires.
 * `OperatorApprovalPlanDisableTest::test_the_two_paths_refuse_the_same_fixture_for_the_same_reason`
 * drives the handler and this executor against one fixture and asserts they agree, so a change to
 * either that makes them disagree is a red test rather than a comment going stale.
 *
 * NOT A SECOND AUTHORIZATION CHECK. Whether the principal may do this at all was settled twice
 * before anything reached here — `PlatformOperatorOnly` on the route and
 * `TwoPersonApproval::approve()`'s own operator test — and an executor that re-derived
 * authorisation from the stored payload would be deriving it from caller-supplied input, which
 * CLAUDE.md records as the shape of four separate defects in this codebase.
 */
final class DisablePlanExecutor implements ApprovedActionExecutor
{
    public const REASON_PLAN_GONE = 'plan_no_longer_exists';
    public const REASON_SUBSCRIBED = 'a_merchant_is_subscribed_to_this_plan';
    public const REASON_MALFORMED = 'payload_is_not_a_plan_disable_request';

    public static function execute(array $payload, OperatorActionApproval $approval): ExecutionOutcome
    {
        /*
         * STRICT INTEGER PARSING, not `is_numeric`. The skill records why: `is_numeric` accepts
         * `'5.9'` and rejects `'5abc'`, while MySQL coerces `'5abc'` to row 5 in a `WHERE id = ?`.
         * The payload was written by the gate from a validated request, so this is the second
         * layer rather than the first — but a stored payload is still data, and a row in this
         * table outlives the request that made it.
         */
        $planId = filter_var($payload['plan_id'] ?? null, FILTER_VALIDATE_INT);
        $isDisable = filter_var($payload['is_disable'] ?? null, FILTER_VALIDATE_INT);

        if ($planId === false || $planId <= 0 || ! in_array($isDisable, [0, 1], true)) {
            return ExecutionOutcome::refused(self::REASON_MALFORMED, [
                'plan_id' => $payload['plan_id'] ?? null,
                'is_disable' => $payload['is_disable'] ?? null,
            ]);
        }

        $plan = Plan::find($planId);

        if ($plan === null) {
            return ExecutionOutcome::refused(self::REASON_PLAN_GONE, ['plan_id' => $planId]);
        }

        $previous = (int) $plan->is_disable;

        /*
         * `PlanController::planDisable()`'s own rule, re-asked against the table as it is now.
         * Read it in the handler's own order so the two cannot be compared and found to differ:
         * somebody is subscribed, the plan is not plan 1, and the direction is withdrawal.
         */
        $subscriber = User::where('plan_id', $planId)->first();

        if ($subscriber !== null && $planId !== 1 && $isDisable === 0) {
            return ExecutionOutcome::refused(self::REASON_SUBSCRIBED, [
                'plan_id' => $planId,
                'subscriber_id' => $subscriber->id,
            ]);
        }

        /*
         * THROUGH ELOQUENT AND NOT `DB::table()`. `Plan` carries `Cachable`, and CLAUDE.md records
         * three measured false readings where a raw write left route-model binding and every
         * later Eloquent read serving the pre-write row for the rest of the process. An Eloquent
         * update flushes.
         *
         * Same statement shape as the handler's (`Plan::where('id', $id)->update([...])`), so the
         * two paths write the same column the same way.
         */
        Plan::where('id', $planId)->update(['is_disable' => $isDisable]);

        return ExecutionOutcome::performed([
            'plan_id' => $planId,
            'is_disable_before' => $previous,
            'is_disable_after' => $isDisable,
        ]);
    }
}

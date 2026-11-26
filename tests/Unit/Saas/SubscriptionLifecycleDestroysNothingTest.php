<?php

namespace Tests\Unit\Saas;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * `EX-011` / SAAS-003 / SAAS-006 — THE STANDING GUARD, NOT THE CURRENT STATE.
 * ===========================================================================
 *
 * `SubscriptionDataPreservationTest` drives the lifecycle transitions and asserts the rows survive.
 * That catches a deletion on the paths it drives. THIS catches one on a path nobody has written
 * yet — a `plans:enforce-limits` command, a "tidy up after cancellation" job — by refusing a
 * destructive statement inside the funnel every transition already goes through.
 *
 * `User::assignPlan()` IS THAT FUNNEL, measured: every expiry, upgrade, downgrade, trial start,
 * refund and reactivation path in this codebase reaches it. `Auth\AuthenticatedSessionController`,
 * `Auth\RegisteredUserController` (x2), `Http\Middleware\SetLocate`, `PlanController::refund`,
 * `PlanController::planTrial`, `StoreController::activePlan`, `PlanRequestController::acceptRequest`,
 * `BankTransferController::orderapprove`, `Services\Payments\Reconciliation\PlanPurchaseSettlement`
 * and every gateway callback call it. So a guard on this one method covers them all.
 *
 * THE SAFE SET IS ENUMERATED, NOT THE DANGEROUS ONE. CLAUDE.md records a census keyed on dangerous
 * spellings reporting ONE dynamic raw-SQL site where there were FIFTEEN, because the list was short
 * by the spellings nobody thought of — and that happened after a live cross-tenant injection had
 * already been found among them. So this does not hunt for `->delete()`, `forceDelete`, `truncate`
 * and friends: it takes EVERY statement in `assignPlan()` that could destroy a row and requires it
 * to be one of the two the method is allowed to make. An unthought-of spelling is caught by
 * default, because the allowed list names its TARGET rather than its verb.
 *
 * COMMENTS ARE STRIPPED, for the reason that has bitten this repository eight times: the docblocks
 * inside `assignPlan()` discuss the `userActiveModule` delete at length, and this file's own prose
 * above names every verb it refuses.
 */
final class SubscriptionLifecycleDestroysNothingTest extends TestCase
{
    use StripsSourceComments;

    /**
     * Statements `assignPlan()` is allowed to make that remove rows, each quoted exactly.
     *
     * ONE ENTRY, AND IT IS AN ENTITLEMENT ROW RATHER THAN CONTENT. `user_active_modules` records
     * which addon modules a subscriber may use; recomputing it on a plan change is the plan change.
     * No row a merchant created — a store, a product, an order, a customer, a ticket, a staff
     * account — may be removed by any of them, which is `EX-011` and SAAS-006 verbatim.
     *
     * @var list<string>
     */
    private const PERMITTED_REMOVALS = [
        "userActiveModule::where('user_id', \$this->id)->where('module', \$moduleName)->delete()",
    ];

    /**
     * Every PHP token sequence that can remove rows. Not a list of "dangerous things" — a list of
     * the ways SQL deletion is reachable from Eloquent and the query builder at all, so that the
     * assertion below is "every removal is permitted" rather than "no bad word appears".
     *
     * @var list<string>
     */
    private const REMOVAL_VERBS = [
        'delete', 'forceDelete', 'forceDeleteQuietly', 'deleteQuietly',
        'truncate', 'destroy', 'detach', 'dissociate',
    ];

    private function assignPlanSource(): string
    {
        $source = $this->sourceWithoutComments('app/Models/User.php');

        $start = strpos($source, 'public function assignPlan(');
        $this->assertNotFalse($start, 'premise: User::assignPlan() exists and is spelled this way');

        // To the next method declaration at class-body indentation. `assignPlan()` is followed by
        // `currentPlan()`, so the window is the whole method and nothing after it.
        $end = strpos($source, "\n    public function ", $start + 10);
        $this->assertNotFalse($end, 'premise: another method follows assignPlan()');

        return substr($source, $start, $end - $start);
    }

    /**
     * THE GUARANTEE. Every row-removing statement in `assignPlan()` is one of the permitted ones.
     */
    #[Test]
    public function test_assign_plan_removes_nothing_but_the_entitlement_rows(): void
    {
        $body = $this->assignPlanSource();

        $found = [];
        foreach (self::REMOVAL_VERBS as $verb) {
            $offset = 0;
            while (($at = strpos($body, '->' . $verb . '(', $offset)) !== false) {
                // The statement this call belongs to: back to the previous `;`, `{` or `}`.
                $statementStart = max(
                    strrpos(substr($body, 0, $at), ';') ?: 0,
                    strrpos(substr($body, 0, $at), '{') ?: 0,
                    strrpos(substr($body, 0, $at), '}') ?: 0
                );
                // TO THE END OF THE STATEMENT, not to the end of the call. The first version
                // sliced `$at + strlen($verb) + 2` and lost the closing paren, so the extracted
                // text could never match the permitted list and the guard failed on correct
                // source -- a census that cannot reproduce the thing it permits is a census that
                // gets loosened.
                $statementEnd = strpos($body, ';', $at);
                $statement = trim(substr(
                    $body,
                    $statementStart + 1,
                    ($statementEnd === false ? strlen($body) : $statementEnd) - $statementStart - 1
                ));
                $found[] = preg_replace('/\s+/', ' ', $statement);
                $offset = $at + 1;
            }
        }

        foreach ($found as $statement) {
            $this->assertContains(
                $statement,
                self::PERMITTED_REMOVALS,
                "User::assignPlan() may not remove rows here: `{$statement}`.\n"
                . "EX-011 forbids deleting store data when a subscription ends and SAAS-006 forbids\n"
                . "deleting content that exceeds a downgraded plan's limits. Deactivate the row\n"
                . '(`is_active = 0`) as the two existing cascades do, or escalate to the owner.'
            );
        }
    }

    /**
     * THE PREMISE, WHICH IS THE HALF THAT ROTS. If the permitted statement ever stops appearing —
     * renamed, reformatted, moved out — the assertion above becomes vacuous and passes forever over
     * an empty set. CLAUDE.md records exactly this failure mode ("a census that reads comments can
     * be satisfied by prose"), so the ratchet is asserted in both directions.
     */
    #[Test]
    public function test_the_permitted_statement_is_really_in_the_method(): void
    {
        $body = $this->assignPlanSource();

        foreach (self::PERMITTED_REMOVALS as $statement) {
            $this->assertStringContainsString(
                $statement,
                preg_replace('/\s+/', ' ', $body),
                'the permitted-removals list must describe the method as it is, or the guard above '
                . 'is measuring an empty set'
            );
        }
    }

    /**
     * THE THREE AUTH EXPIRY SITES REMOVE NOTHING AT ALL. They are where a "wipe it on expiry"
     * change would most naturally be written — the handler already knows the subscription ended —
     * and unlike `assignPlan()` they have no legitimate removal to allow.
     */
    #[Test]
    public function test_no_expiry_handler_removes_a_row(): void
    {
        $files = [
            'app/Http/Controllers/Auth/AuthenticatedSessionController.php',
            'app/Http/Controllers/Auth/RegisteredUserController.php',
            'app/Http/Middleware/SetLocate.php',
        ];

        foreach ($files as $file) {
            $source = $this->sourceWithoutComments($file);

            foreach (self::REMOVAL_VERBS as $verb) {
                $this->assertStringNotContainsString(
                    '->' . $verb . '(',
                    $source,
                    $file . " must not remove rows: EX-011 forbids deleting store data when a\n"
                    . 'subscription ends, and these are the handlers that notice it has.'
                );
            }
        }
    }

    /**
     * NO SCHEDULED TASK PRUNES BY PLAN LIMIT. `routes/console.php` is the whole schedule — there is
     * no `app/Console/Kernel.php` on Laravel 11 — so this reads the one file that could introduce a
     * recurring deletion nobody drives in a test.
     */
    #[Test]
    public function test_the_schedule_runs_no_plan_limit_prune(): void
    {
        $schedule = $this->sourceWithoutComments('routes/console.php');

        foreach (['prune', 'cleanup', 'clean-up', 'purge', 'wipe', 'expire'] as $word) {
            $this->assertStringNotContainsString(
                $word,
                strtolower($schedule),
                "routes/console.php schedules a command whose name suggests it removes things.\n"
                . 'EX-011 and SAAS-006 forbid pruning merchant content by subscription state; if '
                . 'this command does something else, add it here with its evidence.'
            );
        }
    }
}

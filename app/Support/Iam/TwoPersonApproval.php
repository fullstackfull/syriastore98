<?php

namespace App\Support\Iam;

use App\Models\AuditLog;
use App\Models\OperatorActionApproval;
use App\Models\User;
use App\Policies\UserPolicy;

/**
 * `IAM-003` — TWO-PERSON APPROVAL FOR DANGEROUS OPERATOR ACTIONS.
 *
 * Recovered decision text (`docs/development/master-plan/DECISION-ID-REGISTER.md:239`):
 * *"موافقات ثنائية للإجراءات الخطرة."*
 *
 * One operator asks. The effect does not exist. A SECOND, DIFFERENT operator approves, and the
 * effect is produced BY THAT APPROVAL. `config/operator_approvals.php` is the action list, the
 * enforcement switch and the record of what is proposed to the owner and not wired.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE FOUR THINGS THAT MAKE SUCH A MECHANISM WRONG, AND WHERE EACH IS CLOSED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **THE SAME PERSON APPROVING THEIR OWN REQUEST.** `approve()` compares `$approver->id`
 *    against `$row->requested_by` and refuses with `REASON_SELF_APPROVAL`. It compares INTEGER
 *    IDS and not a loaded relation, so a renamed, demoted or deleted requester cannot change who
 *    may approve a row. The reason is returned, not just a failure, because CLAUDE.md records
 *    that a refusal asserted without its reason is satisfied by three other things.
 *
 * 2. **THE ACTION TAKING EFFECT AT REQUEST TIME.** `gate()` NEVER performs anything. The effect
 *    lives in an `ApprovedActionExecutor`, which only `approve()` calls. That is structural: there
 *    is no code path from `gate()` to an executor.
 *
 * 3. **EXECUTING TWICE.** The `pending → approved` transition is ONE CONDITIONAL UPDATE whose
 *    `WHERE` carries the old state, and the executor runs only if that update affected exactly one
 *    row. Two concurrent approvals therefore produce one execution and one `REASON_NOT_PENDING`,
 *    at the engine rather than in PHP. The same technique `module_payment_initiations` uses for
 *    its completion claim.
 *
 * 4. **AUTHORIZATION BY THE WRONG TEST.** CLAUDE.md: there is NO super-admin bypass here;
 *    `isAbleTo()` alone REFUSES the platform operator, and `users.type` is a string comparison.
 *    So every principal test in this class is `instanceof User` FIRST and then
 *    `UserPolicy::isOperator()` — the canonical helper, which `rtrim`s a trailing space to agree
 *    with MySQL's PAD SPACE and deliberately does NOT strip a leading one. The `instanceof` is not
 *    decoration: `'type'` is in `Customer::$fillable`, and `GAP-307` measured a `Customer` typed
 *    `super admin` disabling a plan through a class-blind comparison.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHAT THIS CLASS DOES NOT DO, SAID RATHER THAN IMPLIED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *  - **It is OFF on a stock install** (`config/operator_approvals.php`'s header says why: one
 *    seeded operator account makes enforcement a lockout, which CLAUDE.md names as a failure mode
 *    that has already happened once in this repository). The code is complete and tested in both
 *    modes; the switch is the owner's.
 *  - **It does not grant partial operator powers.** That is `IAM-001`, and it is a separate and
 *    unbuilt thing: operator status is binary here. Every approver is a full operator.
 *  - **It cannot govern an action whose effect needs the requester's live session** —
 *    impersonation is the example, and `config/operator_approvals.php`'s `proposed_actions` says
 *    so rather than wiring it to a mechanism that does not fit.
 */
final class TwoPersonApproval
{
    public const REASON_NOT_OPERATOR = 'principal_is_not_a_platform_operator';
    public const REASON_SELF_APPROVAL = 'an_operator_may_not_approve_their_own_request';
    public const REASON_NOT_PENDING = 'this_request_has_already_been_decided';
    public const REASON_UNKNOWN_ACTION = 'action_is_not_governed';
    public const REASON_NO_EXECUTOR = 'action_has_no_executor_configured';

    /** Is the control switched on at all? */
    public static function enforced(): bool
    {
        return (bool) config('operator_approvals.enforced', false);
    }

    /** @return array<string,array<string,mixed>> the wired action definitions */
    public static function actions(): array
    {
        $actions = config('operator_approvals.actions', []);

        return is_array($actions) ? $actions : [];
    }

    /**
     * Does this action require two people right now?
     *
     * BOTH HALVES, in this order: the master switch and then membership. An action absent from
     * the list is NOT governed even with the switch on — the list is the whole statement of what
     * is governed, so a typo in a call site fails open to the previous behaviour rather than
     * refusing an operator action nobody meant to govern.
     */
    public static function governs(string $action): bool
    {
        return self::enforced() && array_key_exists($action, self::actions());
    }

    /**
     * ASK THE GATE. Returns `proceed` when the caller may act now, `awaitingApproval` when the ask
     * has been recorded and the effect must NOT happen, or `refused` with a reason.
     *
     * `$payload` MUST ALREADY BE NARROWED BY THE CALLER to the named arguments the action's
     * executor reads. It is not `$request->all()`, and that is deliberate: the skill records that
     * `Request::__get` reads `all()` before route parameters, so a posted body key can shadow a
     * route parameter, and the skill's §12 records a multipart FILE part replacing a merged field
     * for every later reader. A gate that stored the whole bag would hand the executor whatever
     * the requester chose to put in it.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function gate(string $action, array $payload, $principal): GateDecision
    {
        if (! self::governs($action)) {
            return GateDecision::proceed();
        }

        // `instanceof` FIRST so `isOperator()` never reads `->type` off a non-`User` at all.
        if (! $principal instanceof User || ! UserPolicy::isOperator($principal)) {
            return GateDecision::refused(self::REASON_NOT_OPERATOR);
        }

        if (self::executorFor($action) === null) {
            /*
             * FAIL CLOSED. A governed action with no executor cannot take effect at approval time,
             * so letting the request through would make the action ungoverned while the config
             * claims otherwise — the worst of the three outcomes. Refusing is visible.
             */
            return GateDecision::refused(self::REASON_NO_EXECUTOR);
        }

        $fingerprint = self::fingerprint($action, $payload);

        /*
         * AN IDENTICAL ASK FINDS ITS OWN ROW. An operator clicking a toggle twice must not create
         * two pending approvals, because two approvals are two executions. Read WITHOUT a global
         * scope is unnecessary here -- this model carries none, by design (see the model) -- but
         * the state predicate is: a row that has already been decided must not be reused, or a
         * rejected request would be silently resurrected by a repeat click.
         */
        $existing = OperatorActionApproval::where('fingerprint', $fingerprint)
            ->where('state', OperatorActionApproval::STATE_PENDING)
            ->first();

        if ($existing !== null) {
            return GateDecision::awaitingApproval($existing);
        }

        $approval = OperatorActionApproval::create([
            'action' => $action,
            'payload' => $payload,
            'fingerprint' => $fingerprint,
            'state' => OperatorActionApproval::STATE_PENDING,
            'requested_by' => $principal->id,
        ]);

        /*
         * `IAM-004` — THE CENTRAL LOG, in the same change. `AuditLog::record()` is the mechanism
         * this application already uses, and `$principal` is passed explicitly rather than left to
         * the helper's `auth('web')` read: this can be reached from a console command or a queued
         * job, where that guard is empty, and a null actor on an authorisation record is a worse
         * answer than the one we hold.
         */
        AuditLog::record(
            'operator_action_approval_requested',
            $approval,
            [],
            ['action' => $action, 'payload' => $payload],
            $principal
        );

        return GateDecision::awaitingApproval($approval);
    }

    /**
     * A SECOND OPERATOR APPROVES, AND THE EFFECT IS PRODUCED HERE.
     *
     * The order of the three refusals is the order they must be asked in:
     *
     *   1. not an operator        — an authorisation question, before anything about the row;
     *   2. the requester          — the whole point of the control;
     *   3. already decided        — a state question, settled at the ENGINE by the conditional
     *                              update below rather than by the read above it, because a read
     *                              then write is a race and two approvals are two executions.
     */
    public static function approve(OperatorActionApproval $approval, $approver): ApprovalResult
    {
        if (! $approver instanceof User || ! UserPolicy::isOperator($approver)) {
            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);
        }

        /*
         * SELF-APPROVAL. Integer comparison against the stored column, with both sides cast, so
         * a string `'7'` from a cast-free driver cannot pass as a different principal from 7.
         */
        if ((int) $approver->id === (int) $approval->requested_by) {
            AuditLog::record(
                'operator_action_approval_self_approval_refused',
                $approval,
                [],
                ['action' => $approval->action, 'reason' => self::REASON_SELF_APPROVAL],
                $approver
            );

            return ApprovalResult::refused(self::REASON_SELF_APPROVAL, $approval);
        }

        $executor = self::executorFor($approval->action);

        if ($executor === null) {
            return ApprovalResult::refused(self::REASON_NO_EXECUTOR, $approval);
        }

        /*
         * ONE CONDITIONAL UPDATE CARRYING THE OLD STATE. `UPDATE ... WHERE id = ? AND state =
         * 'pending'` returns 1 for exactly one caller however many arrive at once, so the executor
         * below runs once. A `$approval->isPending()` check in PHP would not: the row could change
         * between the read and the write, and CLAUDE.md records that this model's reads are not
         * even cached precisely so that this question is answered by the table.
         *
         * THROUGH THE ELOQUENT BUILDER, so `Cachable` siblings are flushed if this model ever
         * gains the trait, and so the statement is the same shape as every other write here.
         */
        $claimed = OperatorActionApproval::where('id', $approval->id)
            ->where('state', OperatorActionApproval::STATE_PENDING)
            ->update([
                'state' => OperatorActionApproval::STATE_APPROVED,
                'approved_by' => $approver->id,
                'decided_at' => now(),
            ]);

        if ($claimed !== 1) {
            return ApprovalResult::refused(self::REASON_NOT_PENDING, $approval->fresh());
        }

        $approval = $approval->fresh();

        /*
         * THE EFFECT. After this line the action has happened — or the executor refused it,
         * because the world may have changed between the request and this approval. Both are
         * recorded on the row and in the central log; neither leaves it `approved`.
         */
        $outcome = $executor::execute((array) $approval->payload, $approval);

        $approval->forceFill([
            'state' => $outcome->performed
                ? OperatorActionApproval::STATE_EXECUTED
                : OperatorActionApproval::STATE_FAILED,
            'executed_at' => now(),
            'refusal_reason' => $outcome->performed ? null : $outcome->reason,
            'outcome' => $outcome->detail,
        ])->save();

        AuditLog::record(
            $outcome->performed
                ? 'operator_action_approved_and_executed'
                : 'operator_action_approved_but_refused_at_execution',
            $approval,
            ['state' => OperatorActionApproval::STATE_PENDING],
            [
                'action' => $approval->action,
                'requested_by' => (int) $approval->requested_by,
                'approved_by' => (int) $approver->id,
                'performed' => $outcome->performed,
                'reason' => $outcome->reason,
                'detail' => $outcome->detail,
            ],
            $approver
        );

        return ApprovalResult::accepted($approval, $outcome);
    }

    /**
     * REJECT a pending request.
     *
     * **THE REQUESTER MAY REJECT THEIR OWN**, and that is a decision rather than an oversight: a
     * rejection can only PREVENT an effect, never cause one, so the two-person rule — which exists
     * to stop one person causing an effect alone — does not apply in this direction. Refusing
     * self-rejection would instead leave an operator who mis-clicked unable to withdraw their own
     * ask without a colleague, which is friction with no security content.
     */
    public static function reject(OperatorActionApproval $approval, $approver, string $reason = ''): ApprovalResult
    {
        if (! $approver instanceof User || ! UserPolicy::isOperator($approver)) {
            return ApprovalResult::refused(self::REASON_NOT_OPERATOR, $approval);
        }

        $claimed = OperatorActionApproval::where('id', $approval->id)
            ->where('state', OperatorActionApproval::STATE_PENDING)
            ->update([
                'state' => OperatorActionApproval::STATE_REJECTED,
                'approved_by' => $approver->id,
                'decided_at' => now(),
                // Trimmed to the column's width. The column is varchar(255); a longer string
                // would truncate on MySQL and pass silently on SQLite, which is exactly the
                // affinity difference CLAUDE.md records as having hidden a real defect.
                'refusal_reason' => mb_substr(trim($reason), 0, 255) ?: null,
            ]);

        if ($claimed !== 1) {
            return ApprovalResult::refused(self::REASON_NOT_PENDING, $approval->fresh());
        }

        $approval = $approval->fresh();

        AuditLog::record(
            'operator_action_approval_rejected',
            $approval,
            ['state' => OperatorActionApproval::STATE_PENDING],
            [
                'action' => $approval->action,
                'requested_by' => (int) $approval->requested_by,
                'rejected_by' => (int) $approver->id,
                'reason' => $approval->refusal_reason,
            ],
            $approver
        );

        return ApprovalResult::accepted($approval, ExecutionOutcome::refused('rejected_by_operator'));
    }

    /**
     * The executor class for an action, or null.
     *
     * VALIDATED, NOT TRUSTED: a configured value that is not a class implementing the interface
     * answers null, which `gate()` turns into a fail-closed refusal. A config typo must not become
     * an ungoverned dangerous action.
     *
     * @return class-string<ApprovedActionExecutor>|null
     */
    public static function executorFor(string $action): ?string
    {
        $executor = self::actions()[$action]['executor'] ?? null;

        if (! is_string($executor) || $executor === '' || ! class_exists($executor)) {
            return null;
        }

        if (! in_array(ApprovedActionExecutor::class, (array) class_implements($executor), true)) {
            return null;
        }

        return $executor;
    }

    public static function labelFor(string $action): string
    {
        $label = self::actions()[$action]['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $action;
    }

    /**
     * SHA-256 over the action and a CANONICALISED payload, so the same ask hashes the same way
     * however the keys arrived.
     *
     * `ksort` RECURSIVELY, because `['a' => 1, 'b' => 2]` and `['b' => 2, 'a' => 1]` are the same
     * ask and `json_encode` would give them different bytes. Not a security boundary — it is
     * derived from data the requester chose, so it says "this is the same ask" and never "this ask
     * is legitimate".
     *
     * @param  array<string,mixed>  $payload
     */
    public static function fingerprint(string $action, array $payload): string
    {
        $canonical = self::canonicalise($payload);

        return hash('sha256', $action . '|' . json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<mixed> $value */
    private static function canonicalise(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }

    /**
     * The pending queue, oldest first.
     *
     * NO TENANT PREDICATE AND NONE IS MISSING: every governed action is a platform action and this
     * model carries no `StoreScope` (see the model's docblock). The screen that renders this is
     * behind `PlatformOperatorOnly`, and its controller asks the operator question again.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,OperatorActionApproval>
     */
    public static function pending()
    {
        return OperatorActionApproval::where('state', OperatorActionApproval::STATE_PENDING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The most recently decided rows, for the same screen.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,OperatorActionApproval>
     */
    public static function recentlyDecided(int $limit = 25)
    {
        return OperatorActionApproval::whereIn('state', [
            OperatorActionApproval::STATE_EXECUTED,
            OperatorActionApproval::STATE_FAILED,
            OperatorActionApproval::STATE_REJECTED,
            OperatorActionApproval::STATE_APPROVED,
        ])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }
}

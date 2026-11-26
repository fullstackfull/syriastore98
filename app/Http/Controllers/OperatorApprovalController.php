<?php

namespace App\Http\Controllers;

use App\Models\OperatorActionApproval;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Iam\TwoPersonApproval;
use Illuminate\Http\Request;

/**
 * `IAM-003` — THE SECOND OPERATOR'S QUEUE. Pending dangerous actions, and the approve / reject
 * decisions on them.
 *
 * Recovered decision text (`docs/development/master-plan/DECISION-ID-REGISTER.md:239`):
 * *"موافقات ثنائية للإجراءات الخطرة."*
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE HANDLERS CHECK AGAIN, AND THAT IS NOT BELT-AND-BRACES HERE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The routes carry `PlatformOperatorOnly`. Every method below ALSO calls
 * `refuseAnyoneButTheOperator()`, the same shape `DocumentationEditorialController` uses, for the
 * reason recorded beside those routes: relying on route grouping alone has failed in this
 * repository, and CLAUDE.md records that a later declaration of the same method+URI replaces the
 * earlier one and silently drops its middleware.
 *
 * **AND BECAUSE A HANDLER-LEVEL CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP
 * TEST.** `GAP-307` is the measured case: `PlanController::planDisable` admitted a `Customer`
 * typed `super admin` — 200, plan disabled — behind a gate whose HTTP tests were green, because
 * the middleware answered 403 and the handler was never reached. So
 * `OperatorApprovalScreenTest` calls these methods DIRECTLY as well as over HTTP.
 *
 * `instanceof User` FIRST, then `UserPolicy::isOperator()`. CLAUDE.md: there is no super-admin
 * bypass, `isAbleTo()` alone REFUSES the operator, and `'type'` is in `Customer::$fillable` — so a
 * class-blind `->type` comparison is exactly the `GAP-307` defect. The `instanceof` short-circuits,
 * so `isOperator()` never reads `->type` off a non-`User` at all.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  NO PERMISSION NAME, AND NO TENANT PREDICATE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `PlatformOperatorOnly` and not `isAbleTo('...')`, for the reason stated beside every other
 * `platform/*` route: a merchant creates their own roles through the product, so a permission is
 * substantially self-assignable — and `isAbleTo()` would refuse the operator outright, so a
 * permission gate would be wrong in both directions at once.
 *
 * No `store_id` filter, and none is missing: every governed action is a PLATFORM action, and
 * `OperatorActionApproval` carries no `StoreScope` by design (see that model). `getCurrentStore()`
 * is never called here — it returns the hardcoded literal `1` for a `super admin`, which is the
 * fabrication `App\Support\Audit\AuditAttribution` exists to undo.
 */
class OperatorApprovalController extends Controller
{
    public function index(Request $request)
    {
        $this->refuseAnyoneButTheOperator();

        return view('platform.approvals', [
            'enforced' => TwoPersonApproval::enforced(),
            'quorum' => (int) config('operator_approvals.quorum', 2),
            'wired' => TwoPersonApproval::actions(),
            'proposed' => (array) config('operator_approvals.proposed_actions', []),
            'pending' => TwoPersonApproval::pending(),
            'decided' => TwoPersonApproval::recentlyDecided(),
            'viewer' => auth()->user(),
        ]);
    }

    /**
     * APPROVE, which is also where the effect is produced — by `TwoPersonApproval::approve()`,
     * never here.
     *
     * `$id` IS A METHOD ARGUMENT AND THEREFORE THE ROUTE PARAMETER. The skill's §4: `$request->id`
     * would resolve to the POSTED BODY when both are present, because `Request::__get` reads
     * `all()` before route parameters — so a crafted body key could point an approval at a
     * different row than the one the URL names and than any UI showed. Taking it as an argument
     * makes that impossible.
     *
     * NO ROUTE-MODEL BINDING, deliberately: a missing row must be OUR 404 with a known message
     * rather than `SubstituteBindings`' 404 raised before the operator check, which would make a
     * refusal test satisfiable by a route that never ran the guard.
     */
    public function approve(Request $request, $id)
    {
        $this->refuseAnyoneButTheOperator();

        $approval = $this->findOrFail($id);

        $result = TwoPersonApproval::approve($approval, auth()->user());

        if (! $result->accepted) {
            return redirect()
                ->route('platform.approvals.index')
                ->with('error', $this->refusalMessage($result->reason));
        }

        if ($result->effectExists()) {
            return redirect()
                ->route('platform.approvals.index')
                ->with('success', __('Approved. The action has been performed.'));
        }

        /*
         * APPROVED AND THEN REFUSED AT EXECUTION TIME. Not an error in the mechanism — the world
         * changed between the request and the approval, and the executor re-asked its safety
         * question. The operator is told WHICH rule refused, because "nothing happened" is also
         * what a crash looks like.
         */
        return redirect()
            ->route('platform.approvals.index')
            ->with('error', __('Approved, but the action was refused when it ran: :reason', [
                'reason' => (string) ($result->outcome?->reason ?? ''),
            ]));
    }

    public function reject(Request $request, $id)
    {
        $this->refuseAnyoneButTheOperator();

        $approval = $this->findOrFail($id);

        /*
         * The operator's words, length-validated. `operator_action_approvals.refusal_reason` is
         * `varchar(255)`: a longer string TRUNCATES on MySQL and passes on SQLite, which is the
         * affinity difference CLAUDE.md records as having hidden a real defect (P01-SEC-13). The
         * service trims to the width as well, so the rule holds for a non-HTTP caller too.
         */
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = TwoPersonApproval::reject($approval, auth()->user(), (string) ($validated['reason'] ?? ''));

        if (! $result->accepted) {
            return redirect()
                ->route('platform.approvals.index')
                ->with('error', $this->refusalMessage($result->reason));
        }

        return redirect()
            ->route('platform.approvals.index')
            ->with('success', __('Request rejected. Nothing was performed.'));
    }

    /**
     * ONE MESSAGE PER REASON KEY, so what the operator reads names the rule that answered.
     *
     * The keys are the service's constants rather than literals, so renaming one is a compile-time
     * concern and not a message that silently stops matching.
     */
    private function refusalMessage(string $reason): string
    {
        return match ($reason) {
            TwoPersonApproval::REASON_SELF_APPROVAL => __('You requested this action, so you cannot approve it. A different platform operator must.'),
            TwoPersonApproval::REASON_NOT_PENDING => __('This request has already been decided.'),
            TwoPersonApproval::REASON_NOT_OPERATOR => __('Permission denied.'),
            TwoPersonApproval::REASON_NO_EXECUTOR => __('This action has no executor configured and cannot be performed.'),
            default => __('This request could not be decided.'),
        };
    }

    /** Strict integer parsing, then our own 404. See `approve()` for why there is no binding. */
    private function findOrFail($id): OperatorActionApproval
    {
        $key = filter_var($id, FILTER_VALIDATE_INT);

        $approval = $key === false || $key <= 0
            ? null
            : OperatorActionApproval::find($key);

        if ($approval === null) {
            abort(404);
        }

        return $approval;
    }

    private function refuseAnyoneButTheOperator(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403, __('Permission denied.'));
        }
    }
}

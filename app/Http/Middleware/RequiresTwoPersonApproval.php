<?php

namespace App\Http\Middleware;

use App\Support\Iam\GateDecision;
use App\Support\Iam\TwoPersonApproval;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `IAM-003` — THE DOOR. Converts an attempt at a governed dangerous action into a recorded request
 * that a SECOND operator must approve, and refuses to let the handler run.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHY MIDDLEWARE AND NOT A CHECK IN `PlanController`
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `PlatformOperatorOnly`'s own docblock settled this for the same handler and the same reason:
 * *"BankTransferController is a plan-purchase gateway controller and belongs to the payments
 * workstream; PlanController is not this workstream's file either."* The gate is attached where
 * this workstream owns the code. A handler edit would also put the ask and the effect in one
 * method, which is the arrangement `IAM-003` exists to break.
 *
 * APPENDED TO THE `web` GROUP, not declared on the route, and that is CLAUDE.md's rule rather than
 * convenience: *"Later route declarations replace earlier ones for the same method+URI, silently
 * dropping the earlier middleware."* A group registration survives that, and it survives
 * `route:cache` too, because the cache stores declared middleware NAMES unexpanded.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  KEYED ON THE CONTROLLER ACTION, NEVER ON THE URI
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The skill records four censuses in this repository that missed what they claimed to cover, each
 * because it was keyed on spelling: uri words, `Route::post` lines, a uri prefix. `GOVERNED` maps
 * `$route->getActionName()` to an action key, so a renamed URI or a second declaration of the same
 * handler is still governed, and `…_premise_the_governed_handler_still_exists` fails if the
 * handler is renamed rather than silently governing nothing.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE ANONYMOUS CASE IS NOT OURS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `web`-group middleware runs BEFORE a route's own `auth`, so this can see a request with no
 * principal on a route that requires one. It passes such a request through, exactly as
 * `PlatformOperatorOnly` does and for the same reason recorded there: answering here would replace
 * the sign-in redirect with a refusal.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE REFUSAL SHAPE IS THE HANDLER'S OWN, WHICH IS NOT COSMETIC
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `plan.disable` answers an `$.ajax` call whose success handler reads `data.success` and
 * `data.error` (`resources/views/plans/index.blade.php:368-373`) without navigating. So an
 * "awaiting approval" outcome returns `error` AND NOT `success`: the plan's state did NOT change,
 * and a success toast beside an unchanged switch is a lie to the operator. HTTP 200 with the
 * error key, because that is the envelope that handler already uses — and never 401, because
 * repo B's `lib/network/network.dart` turns any 401 into a forced logout.
 */
class RequiresTwoPersonApproval
{
    /**
     * `Controller@method` (as `getActionName()` reports it) => the action key in
     * `config/operator_approvals.php`.
     *
     * ONE ENTRY. `config/operator_approvals.php`'s `proposed_actions` is the list this workstream
     * proposes and has NOT wired, each with the measured reason — it is an owner decision, and
     * inventing the rest of the list here is what that config file exists to avoid.
     */
    private const GOVERNED = [
        'App\Http\Controllers\PlanController@planDisable' => 'plan.disable',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $action = self::GOVERNED[ltrim((string) $route->getActionName(), '\\')] ?? null;

        if ($action === null || ! TwoPersonApproval::governs($action)) {
            return $next($request);
        }

        /*
         * `auth('web')` EXPLICITLY, NOT `auth()`. CLAUDE.md's rule, and here it is not decoration:
         * this middleware is appended to the `web` GROUP, so it runs on every `web` request
         * including the storefront, where a shopper may hold a `customers` session. `auth()` reads
         * the DEFAULT guard, and a test that signs a customer in with
         * `actingAs($customer, 'customers')` also calls `shouldUse('customers')` -- so `auth()`
         * would hand this a `Customer`. The gate's own `instanceof User` test refuses one, so
         * either spelling fails closed; the explicit guard is right because the honest answer for
         * a request with no `web` principal is "not mine", which lets the route's own `auth`
         * produce the sign-in redirect rather than this answering 403 in its place.
         *
         * Not ours: the route's own `auth` owns the anonymous case and runs after this, because
         * group middleware precedes route middleware.
         */
        $user = auth('web')->user();

        if (! $user) {
            return $next($request);
        }

        $payload = $this->payloadFor($action, $request);

        if ($payload === null) {
            /*
             * FAIL CLOSED ON A SHAPE WE CANNOT RECORD. If the request does not carry the arguments
             * the executor needs, the ask cannot be replayed at approval time — so letting it
             * through would perform a governed action with no approval, which is the one outcome
             * this middleware exists to prevent. The refusal names the reason.
             */
            return $this->refuse($request, __('This action could not be recorded for approval and has not been performed.'));
        }

        $decision = TwoPersonApproval::gate($action, $payload, $user);

        if ($decision->mayProceed()) {
            return $next($request);
        }

        if ($decision->isAwaitingApproval()) {
            return $this->refuse($request, __(
                'Recorded as request #:id. This action needs a different platform operator to approve it before it takes effect.',
                ['id' => $decision->approval?->id]
            ));
        }

        return $this->refuse($request, __('Permission denied.'), 403);
    }

    /**
     * THE NARROWED ARGUMENTS, per action. Never `$request->all()`.
     *
     * The skill's §4: `Request::__get` reads `all()` BEFORE route parameters, so a posted body key
     * shadows a route parameter — and §12 records a multipart FILE part replacing a merged field
     * for every later reader. `planDisable` itself reads `$request->id` and `$request->is_disable`
     * from the body, so `input()` on those two names is the same two values the handler would
     * have used, and nothing else is stored.
     *
     * STRICT INTEGER PARSING, not `is_numeric`: the skill records that `is_numeric` accepts
     * `'5.9'` and rejects `'5abc'` while MySQL coerces `'5abc'` to row 5 in a `WHERE id = ?`.
     *
     * @return array<string,mixed>|null null when the request is not a shape we can record
     */
    private function payloadFor(string $action, Request $request): ?array
    {
        if ($action !== 'plan.disable') {
            return null;
        }

        $planId = filter_var($request->input('id'), FILTER_VALIDATE_INT);
        $isDisable = filter_var($request->input('is_disable'), FILTER_VALIDATE_INT);

        if ($planId === false || $planId <= 0 || ! in_array($isDisable, [0, 1], true)) {
            return null;
        }

        return ['plan_id' => $planId, 'is_disable' => $isDisable];
    }

    /**
     * `error` and not `success`, in the envelope `plans/index.blade.php` reads. See the class
     * docblock: a success toast beside an unchanged switch is a lie.
     */
    private function refuse(Request $request, string $message, int $status = 200): Response
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['error' => $message], $status);
        }

        return redirect()->back()->with('error', $message);
    }
}

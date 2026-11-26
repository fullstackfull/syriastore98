<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OperatorActionApproval;
use App\Models\Plan;
use App\Models\User;
use App\Support\Iam\Executors\DisablePlanExecutor;
use App\Support\Iam\TwoPersonApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `IAM-003` — TWO-PERSON APPROVAL FOR DANGEROUS OPERATOR ACTIONS.
 *
 * Recovered decision text (`docs/development/master-plan/DECISION-ID-REGISTER.md:239`):
 * *"موافقات ثنائية للإجراءات الخطرة."* — two-person approval for dangerous actions. It appears in
 * `MASTER-PLAN-GAP-REGISTER.md:444` (`GAP-198`) as *"Not located. No two-person approval for
 * dangerous actions"*, and in neither the requirement matrix nor anywhere in `app/`.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE TWO WAYS SUCH A MECHANISM IS WRONG IN PRACTICE, EACH WITH ITS OWN CASE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   1. **The same person approves their own request.**
 *      `test_the_requester_cannot_approve_their_own_request` keys on the REASON
 *      (`REASON_SELF_APPROVAL`) and not on "nothing happened" — CLAUDE.md records that a refusal
 *      asserted without its reason is also satisfied by a crash, by a missing row and by an
 *      unrelated earlier rule. It also asserts the approver is a DIFFERENT user id in the positive
 *      case, so a mutant that drops the comparison is caught from both sides.
 *
 *   2. **The action takes effect at request time rather than approval time.**
 *      `test_with_the_control_on_the_request_alone_does_not_change_the_plan` reads the stored flag
 *      straight out of `plans` and asserts it is untouched after the request, with a pending row
 *      to prove the gate was reached rather than the handler having failed for some other reason.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHY SOME CASES CALL THE HANDLER DIRECTLY
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `GAP-307`: a handler-level check behind an `abort()`ing middleware is INVISIBLE to an HTTP test —
 * the middleware answers 403 and the handler is never reached, so the route returns 403 whether or
 * not the handler checks anything. `PlanController::planDisable` admitted a `Customer` typed
 * `super admin` behind a green gate for exactly that reason. So the principal cases below drive
 * `TwoPersonApproval::approve()` and `OperatorApprovalController` DIRECTLY as well as over HTTP.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  READ THE FLAG PAST THE MODEL CACHE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `Plan` is `Cachable`. CLAUDE.md records three measured false readings where an Eloquent read
 * served a pre-write row for the rest of a test under `CACHE_STORE=array`. Every assertion about
 * the plan's state here goes through `DB::table('plans')`.
 *
 * `plans.is_disable` IS NOT IN `Plan::$fillable`, so `Plan::create(['is_disable' => 0])` silently
 * drops the value and the migration default of 1 applies —
 * `SubscriptionAuthorizationTest::withdrawnPlan()` records that this cost one of its cases a false
 * pass. The fixtures below set the attribute and save.
 *
 * `is_disable` IS INVERTED: **1 MEANS ENABLED** (`PlatformSiteController.php:161` holds the
 * measurement and its evidence). So `is_disable => 0` is the DANGEROUS direction — the one that
 * takes a plan off the platform — and that is the direction these cases drive.
 */
class OperatorTwoPersonApprovalTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  PREMISES. Each one is a thing every case below silently depends on.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * THE SHIPPED DEFAULT GOVERNS NOTHING, and that is asserted rather than stated.
     *
     * `config/operator_approvals.php`'s header gives the measured reason: the seeder creates
     * exactly one `super admin` row and no path in the application writes that type, so
     * enforcement on a stock install would be a LOCKOUT rather than a tightening — the failure
     * CLAUDE.md names by name. This case is what stops the switch flipping by accident on an
     * upgrade, which would refuse operator actions nobody decided to govern.
     */
    #[Test]
    public function test_premise_the_shipped_default_governs_nothing(): void
    {
        $this->assertFalse(
            (bool) config('operator_approvals.enforced'),
            'config/operator_approvals.php now enforces two-person approval BY DEFAULT. That is an '
            . 'owner decision (the action list, the quorum, and at least two operator accounts) '
            . 'and it is not this repository\'s to make: with one seeded operator account, every '
            . 'governed action becomes impossible.'
        );

        $this->assertFalse(
            TwoPersonApproval::governs('plan.disable'),
            'the gate governs plan.disable on the shipped default.'
        );

        $this->assertFalse(TwoPersonApproval::enforced());
    }

    /**
     * THE QUORUM THE SCHEMA CAN ACTUALLY KEEP. `operator_action_approvals` carries ONE
     * `approved_by` column, so a configured 3 would be a claim the table cannot hold — a quorum
     * that silently behaved as 2 is worse than one that is refused.
     */
    #[Test]
    public function test_premise_the_only_implemented_quorum_is_two(): void
    {
        $this->assertSame(
            2,
            (int) config('operator_approvals.quorum'),
            'operator_approvals.quorum is not 2. Only 2 is implemented: the approvals table has a '
            . 'single approved_by column, so a higher quorum needs a schema change and is an owner '
            . 'escalation, not a config edit.'
        );
    }

    /**
     * THE GOVERNED HANDLER STILL EXISTS UNDER THE NAME THE MIDDLEWARE KEYS ON.
     *
     * The middleware maps `$route->getActionName()`, never a URI. The skill records four censuses
     * in this repository that missed what they claimed to cover because they were keyed on
     * spelling — so without this case a rename of `planDisable` would make the gate govern
     * NOTHING, silently, and every case below would still pass by never being reached.
     */
    #[Test]
    public function test_premise_the_governed_handler_is_still_the_one_the_gate_keys_on(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'plan.disable');

        $this->assertNotNull($route, 'the plan.disable route has gone.');

        $this->assertSame(
            'App\Http\Controllers\PlanController@planDisable',
            ltrim((string) $route->getActionName(), '\\'),
            'plan.disable no longer resolves to the handler RequiresTwoPersonApproval::GOVERNED '
            . 'names, so the gate governs nothing and every case in this file would pass '
            . 'vacuously. Re-point the GOVERNED map.'
        );
    }

    /** The wired action has a real executor; a config typo must not become an ungoverned action. */
    #[Test]
    public function test_premise_the_wired_action_has_a_usable_executor(): void
    {
        $this->assertSame(
            DisablePlanExecutor::class,
            TwoPersonApproval::executorFor('plan.disable')
        );

        $this->assertNull(
            TwoPersonApproval::executorFor('not.an.action'),
            'an unknown action resolved an executor.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  THE CONTROL IS OFF: the product behaves exactly as it did before this mechanism.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * CONTROL, and it is doing two jobs: it proves the fixture and the route are usable at all —
     * so the "nothing happened" assertions below are not satisfied by a broken fixture — and it
     * pins that the shipped default is a no-op.
     */
    #[Test]
    public function test_with_the_control_off_the_plan_is_withdrawn_immediately_and_nothing_is_recorded(): void
    {
        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $this->assertSame(1, $this->storedFlag($plan->id), 'premise: the plan starts on sale.');

        $response = $this->actingAs($operator)
            ->post('/plan-disable', ['id' => $plan->id, 'is_disable' => 0]);

        $this->assertSame(0, $this->storedFlag($plan->id), 'the control is off, so the handler should have withdrawn the plan.');
        $this->assertSame(200, $response->status());
        $this->assertSame(0, OperatorActionApproval::count(), 'a row was recorded while the control was off.');
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  THE CONTROL IS ON.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * **THE CENTRAL PROPERTY: THE EFFECT DOES NOT EXIST AT REQUEST TIME.**
     *
     * The stored flag is read straight out of `plans`, and a pending row is asserted as well — so
     * a green result cannot come from the handler having failed for an unrelated reason. The
     * response is asserted to carry `error` and NOT `success`, because the view's `$.ajax` success
     * handler reads those two keys and a success toast beside an unchanged switch would tell the
     * operator the opposite of the truth.
     */
    #[Test]
    public function test_with_the_control_on_the_request_alone_does_not_change_the_plan(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        /*
         * THE AJAX HEADER, BECAUSE THAT IS THE REQUEST THE PRODUCT MAKES. `plans/index.blade.php`
         * drives this route with `$.ajax`, which sends `X-Requested-With: XMLHttpRequest`, and the
         * gate answers in the envelope that handler's success callback reads. A bare
         * `$this->post()` carries neither that header nor an `Accept: application/json`, so it
         * would measure the redirect branch and say nothing about what the operator actually sees.
         * `test_premise_the_plan_screen_really_drives_this_route_with_ajax` is why this header is
         * the right one rather than a guess, and the non-AJAX shape has its own case below.
         */
        $response = $this->actingAs($operator)
            ->post(
                '/plan-disable',
                ['id' => $plan->id, 'is_disable' => 0],
                ['X-Requested-With' => 'XMLHttpRequest']
            );

        // The database question FIRST, so a regression reports what was changed rather than
        // failing on a status and never asking.
        $this->assertSame(
            1,
            $this->storedFlag($plan->id),
            'THE PLAN WAS WITHDRAWN BY THE REQUEST ALONE. Two-person approval means the effect '
            . 'exists only when a second operator approves; an effect at request time is the '
            . 'defect this mechanism exists to prevent.'
        );

        $approval = OperatorActionApproval::sole();

        $this->assertSame('plan.disable', $approval->action);
        $this->assertSame(OperatorActionApproval::STATE_PENDING, $approval->state);
        $this->assertSame((int) $operator->id, (int) $approval->requested_by);
        $this->assertNull($approval->approved_by);
        $this->assertSame(['plan_id' => $plan->id, 'is_disable' => 0], $approval->payload);

        $response->assertStatus(200);
        $this->assertArrayHasKey('error', $response->json());
        $this->assertArrayNotHasKey(
            'success',
            $response->json(),
            'the response told the operator the action succeeded while the plan was unchanged.'
        );
    }

    /**
     * THE PREMISE FOR THAT HEADER: the plan screen really drives `plan.disable` with `$.ajax`.
     *
     * Without this, the choice of `X-Requested-With` in the case above would be an assumption
     * about the product rather than a measurement of it — and the skill's §11 records a comment
     * and a test header both asserting a claim about JavaScript that nobody had read.
     *
     * `{{-- --}}` comments are stripped first: CLAUDE.md records eight occasions where a census
     * was satisfied, or broken, by prose about the very thing it measures, one of them a Blade
     * comment containing the literal it searched for.
     */
    #[Test]
    public function test_premise_the_plan_screen_really_drives_this_route_with_ajax(): void
    {
        $path = resource_path('views/plans/index.blade.php');

        $this->assertFileExists($path);

        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        $this->assertStringContainsString("route('plan.disable')", $source);
        $this->assertStringContainsString(
            '$.ajax',
            $source,
            'the plan screen no longer calls plan.disable over $.ajax, so the gate\'s JSON refusal '
            . 'envelope may be the wrong shape for what the operator now sees.'
        );
    }

    /**
     * A NON-AJAX CALLER IS REFUSED TOO, and gets a shape a browser can show.
     *
     * The effect is what matters and it is asserted first. The shape differs from the AJAX case on
     * purpose: a redirect with a flash is what a plain form post can render, and the gate is
     * generic middleware that will govern plain doors as well as this one.
     */
    #[Test]
    public function test_a_non_ajax_request_is_refused_as_a_redirect_carrying_the_reason(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $response = $this->actingAs($operator)
            ->from('/plans')
            ->post('/plan-disable', ['id' => $plan->id, 'is_disable' => 0]);

        $this->assertSame(1, $this->storedFlag($plan->id), 'a non-AJAX request withdrew the plan.');
        $this->assertSame(1, OperatorActionApproval::count(), 'the ask was not recorded.');

        $this->assertSame(302, $response->status());
        $response->assertSessionHas('error');
    }

    /**
     * **THE SECOND CENTRAL PROPERTY: SELF-APPROVAL IS REFUSED, BY REASON.**
     *
     * Keyed on `REASON_SELF_APPROVAL` and not on "nothing happened": a stale row, a missing
     * executor, a non-operator and a crash all produce "nothing happened", and only one of them
     * is this control working.
     */
    #[Test]
    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $approval = $this->requestWithdrawal($operator, $plan);

        $result = TwoPersonApproval::approve($approval, $operator);

        $this->assertFalse($result->accepted);
        $this->assertSame(
            TwoPersonApproval::REASON_SELF_APPROVAL,
            $result->reason,
            'self-approval was refused for some OTHER reason than being the requester, so this '
            . 'case is not measuring the control it is named for.'
        );

        $this->assertSame(
            1,
            $this->storedFlag($plan->id),
            'the requester approved their own request and the plan was withdrawn.'
        );

        $this->assertSame(
            OperatorActionApproval::STATE_PENDING,
            $approval->fresh()->state,
            'the row left the pending state on a refused self-approval.'
        );
    }

    /** The same refusal over HTTP, through the real screen, with the real gate in front of it. */
    #[Test]
    public function test_the_requester_cannot_approve_their_own_request_over_http(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();
        $approval = $this->requestWithdrawal($operator, $plan);

        $response = $this->actingAs($operator)
            ->post('/platform/approvals/' . $approval->id . '/approve');

        $this->assertSame(1, $this->storedFlag($plan->id));
        $this->assertSame(OperatorActionApproval::STATE_PENDING, $approval->fresh()->state);

        $this->assertNotSame(404, $response->status(), 'the approvals route does not exist.');
        $response->assertSessionHas('error');
    }

    /**
     * **THE POSITIVE CASE: A DIFFERENT OPERATOR'S APPROVAL IS WHAT PRODUCES THE EFFECT.**
     *
     * Asserts the approver is a different user id, not merely that someone approved — which is
     * the other half of the self-approval control and is what a mutant deleting the comparison
     * would otherwise survive.
     */
    #[Test]
    public function test_a_different_operator_approving_performs_the_action(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $approver = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $this->assertNotSame(
            (int) $requester->id,
            (int) $approver->id,
            'premise: the two operators must be different people.'
        );

        $approval = $this->requestWithdrawal($requester, $plan);
        $this->assertSame(1, $this->storedFlag($plan->id), 'premise: the request alone changed nothing.');

        $result = TwoPersonApproval::approve($approval, $approver);

        $this->assertTrue($result->accepted, 'a second operator was refused: ' . $result->reason);
        $this->assertTrue($result->effectExists(), 'the approval was accepted but the effect was not produced.');

        $this->assertSame(
            0,
            $this->storedFlag($plan->id),
            'the second operator approved and the plan was NOT withdrawn, so the effect is not '
            . 'produced at approval time.'
        );

        $row = $approval->fresh();
        $this->assertSame(OperatorActionApproval::STATE_EXECUTED, $row->state);
        $this->assertSame((int) $approver->id, (int) $row->approved_by);
        $this->assertNotSame((int) $row->requested_by, (int) $row->approved_by);
        $this->assertNotNull($row->executed_at);
        $this->assertSame(1, $row->outcome['is_disable_before']);
        $this->assertSame(0, $row->outcome['is_disable_after']);
    }

    /**
     * APPROVING TWICE EXECUTES ONCE, and the second attempt is refused by the state rule.
     *
     * The `pending → approved` transition is one conditional UPDATE carrying the old state, so
     * this is the same question concurrency asks — answered at the engine rather than by a PHP
     * read that could be stale.
     */
    #[Test]
    public function test_approving_twice_executes_once(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $approver = $this->makePlatformAdmin();
        $third = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $approval = $this->requestWithdrawal($requester, $plan);

        $first = TwoPersonApproval::approve($approval, $approver);
        $this->assertTrue($first->accepted);

        // A THIRD operator, so the second attempt cannot be refused as self-approval and the
        // refusal we read is unambiguously the state rule.
        $second = TwoPersonApproval::approve($approval->fresh(), $third);

        $this->assertFalse($second->accepted);
        $this->assertSame(TwoPersonApproval::REASON_NOT_PENDING, $second->reason);

        $this->assertSame((int) $approver->id, (int) $approval->fresh()->approved_by, 'the second approval overwrote the first approver.');
        $this->assertSame(1, OperatorActionApproval::count(), 'a second row appeared.');
    }

    /** An identical repeat of the same ask finds its own pending row; two rows would be two effects. */
    #[Test]
    public function test_a_repeat_of_the_same_request_reuses_the_pending_row(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $first = $this->requestWithdrawal($operator, $plan);
        $second = $this->requestWithdrawal($operator, $plan);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, OperatorActionApproval::count());
        $this->assertSame(1, $this->storedFlag($plan->id));
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  PRINCIPALS. CLAUDE.md: `instanceof` FIRST, then `UserPolicy::isOperator()`.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A `Customer` ROW CARRYING THE WORD `super admin` CANNOT APPROVE — the `GAP-307` shape.
     *
     * `'type'` is in `Customer::$fillable`, and `UserPolicy::isOperator()` takes an UNTYPED
     * `$actor` and reads `->type`. A class-blind comparison therefore admits this principal, and
     * `GAP-307` measured exactly that disabling a plan. Driven DIRECTLY, because over HTTP the
     * route's own `PlatformOperatorOnly` answers 403 first and the handler's check would be
     * invisible.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_cannot_approve(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();
        $approval = $this->requestWithdrawal($requester, $plan);

        $impostor = Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'super admin',
            'store_id' => $this->makeStore($requester)->id,
        ]);

        $this->assertSame(
            'super admin',
            DB::table('customers')->where('id', $impostor->id)->value('type'),
            'premise: the customers row really carries the operator word.'
        );

        $result = TwoPersonApproval::approve($approval, $impostor);

        $this->assertFalse($result->accepted);
        $this->assertSame(TwoPersonApproval::REASON_NOT_OPERATOR, $result->reason);
        $this->assertSame(1, $this->storedFlag($plan->id));
        $this->assertSame(OperatorActionApproval::STATE_PENDING, $approval->fresh()->state);
    }

    /** An ordinary merchant cannot approve, and is refused for being no operator. */
    #[Test]
    public function test_a_merchant_cannot_approve(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $merchant = $this->makeMerchant();
        $plan = $this->enabledPlan();
        $approval = $this->requestWithdrawal($requester, $plan);

        $result = TwoPersonApproval::approve($approval, $merchant);

        $this->assertFalse($result->accepted);
        $this->assertSame(TwoPersonApproval::REASON_NOT_OPERATOR, $result->reason);
        $this->assertSame(1, $this->storedFlag($plan->id));
    }

    /**
     * AN ANONYMOUS VISITOR IS SENT TO SIGN IN, AND IS NOT SERVED A PAGE.
     *
     * Not a formality. `routes/web.php` declares the PUBLIC patterns `platform/docs/{category}`
     * and `platform/{...}` marketing pages EARLIER in the file, and Laravel matches in declaration
     * order — the comment beside `platform/documentation` records a route being swallowed by
     * exactly that. So this asserts the approvals screen is reached by the authenticated
     * declaration and not by a public pattern: a 200 here would mean the operator's queue is a
     * public page.
     *
     * The redirect and NOT 403: the route sits inside the `auth` group, which runs before
     * `PlatformOperatorOnly`, and repo B's `lib/network/network.dart` turns any 401 into a forced
     * logout — so the anonymous case must stay a sign-in redirect.
     */
    #[Test]
    public function test_an_anonymous_visitor_is_not_served_the_approvals_screen(): void
    {
        $response = $this->get('/platform/approvals');

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'an anonymous visitor was served the operator approvals queue. Check whether an '
            . 'earlier public `platform/...` pattern is now matching this URI.'
        );
        $this->assertNotSame(404, $response->getStatusCode(), 'the route does not exist.');
        $this->assertSame(302, $response->getStatusCode());
        $response->assertRedirectContains('login');
    }

    /** A merchant cannot even see the queue. 403, and never 401 — repo B logs out on any 401. */
    #[Test]
    public function test_a_merchant_is_refused_the_approvals_screen(): void
    {
        $response = $this->actingAs($this->makeMerchant())->get('/platform/approvals');

        $this->assertNotSame(404, $response->status(), 'the approvals screen route does not exist.');
        $this->assertSame(403, $response->status());
    }

    /**
     * THE HANDLER REFUSES A NON-`User` ON ITS OWN, with no middleware in the way.
     *
     * `GAP-307`'s lesson exactly: the route carries `PlatformOperatorOnly`, so an HTTP test cannot
     * tell whether the handler checks anything. This calls the controller method directly with a
     * `Customer` on the default guard.
     */
    #[Test]
    public function test_the_approvals_handler_refuses_a_non_user_principal_on_its_own(): void
    {
        $merchant = $this->makeMerchant();

        $impostor = Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'super admin',
            'store_id' => $this->makeStore($merchant)->id,
        ]);

        // The handler reads `auth()->user()` -- the DEFAULT guard.
        $this->actingAs($impostor);

        foreach (['index', 'approve', 'reject'] as $method) {
            $threw = null;

            try {
                $controller = new \App\Http\Controllers\OperatorApprovalController();
                $request = \Illuminate\Http\Request::create('/platform/approvals', 'POST');

                $method === 'index'
                    ? $controller->index($request)
                    : $controller->{$method}($request, 1);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $threw = $e->getStatusCode();
            }

            $this->assertSame(
                403,
                $threw,
                'OperatorApprovalController::' . $method . ' admitted a principal that is not an '
                . 'App\\Models\\User, leaving PlatformOperatorOnly as the sole control -- and '
                . 'CLAUDE.md records that a route re-declaration drops middleware silently.'
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  REJECTION, AND RE-VALIDATION AT EXECUTION TIME.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /** A rejection prevents the effect and records who rejected it and why. */
    #[Test]
    public function test_rejection_prevents_the_effect(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $rejecter = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();
        $approval = $this->requestWithdrawal($requester, $plan);

        $result = TwoPersonApproval::reject($approval, $rejecter, 'Merchants are still on it.');

        $this->assertTrue($result->accepted);
        $this->assertSame(1, $this->storedFlag($plan->id), 'a rejected request took effect.');

        $row = $approval->fresh();
        $this->assertSame(OperatorActionApproval::STATE_REJECTED, $row->state);
        $this->assertSame('Merchants are still on it.', $row->refusal_reason);
        $this->assertSame((int) $rejecter->id, (int) $row->approved_by);
    }

    /**
     * THE REQUESTER MAY REJECT THEIR OWN REQUEST, and that is a decision rather than an oversight.
     * A rejection can only PREVENT an effect, never cause one, so the two-person rule — which
     * exists to stop one person CAUSING an effect alone — does not apply in this direction.
     */
    #[Test]
    public function test_the_requester_may_reject_their_own_request(): void
    {
        $this->enforce();

        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();
        $approval = $this->requestWithdrawal($operator, $plan);

        $result = TwoPersonApproval::reject($approval, $operator, 'Mis-clicked.');

        $this->assertTrue($result->accepted, 'the requester could not withdraw their own ask.');
        $this->assertSame(OperatorActionApproval::STATE_REJECTED, $approval->fresh()->state);
        $this->assertSame(1, $this->storedFlag($plan->id));
    }

    /**
     * **THE EXECUTOR RE-ASKS ITS SAFETY QUESTION AT EXECUTION TIME.**
     *
     * A governed action is approved an unbounded time after it was asked for, so the fixture may
     * have changed. Here a merchant subscribes to the plan BETWEEN the request and the approval:
     * the approval is accepted and the executor then refuses, naming the rule. Without this the
     * mechanism would turn a stale approval into a withdrawal of a plan somebody is paying for.
     */
    #[Test]
    public function test_the_executor_refuses_when_a_merchant_subscribed_between_request_and_approval(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $approver = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $approval = $this->requestWithdrawal($requester, $plan);

        // The world changes. Through Eloquent, so `User`'s cache is flushed -- a raw write would
        // leave the executor's own Eloquent read serving the pre-write row (CLAUDE.md).
        $subscriber = $this->makeMerchant();
        $subscriber->plan_id = $plan->id;
        $subscriber->save();

        $this->assertSame(
            (int) $plan->id,
            (int) DB::table('users')->where('id', $subscriber->id)->value('plan_id'),
            'premise: a merchant is now subscribed to the plan.'
        );

        $result = TwoPersonApproval::approve($approval, $approver);

        $this->assertTrue($result->accepted, 'the approval itself should be accepted.');
        $this->assertFalse($result->effectExists(), 'the plan was withdrawn under a subscriber.');
        $this->assertSame(DisablePlanExecutor::REASON_SUBSCRIBED, $result->outcome->reason);

        $this->assertSame(1, $this->storedFlag($plan->id));

        $row = $approval->fresh();
        $this->assertSame(OperatorActionApproval::STATE_FAILED, $row->state);
        $this->assertSame(DisablePlanExecutor::REASON_SUBSCRIBED, $row->refusal_reason);
    }

    /**
     * THE HANDLER AND THE EXECUTOR ASK THE SAME QUESTION OF THE SAME FIXTURE AND AGREE.
     *
     * This is the instrument for the one duplicated rule in this slice: `planDisable` refuses a
     * withdrawal under a subscriber at REQUEST time and `DisablePlanExecutor` refuses it at
     * EXECUTION time. A BEHAVIOURAL comparison rather than a source grep, because a grep over a
     * condition is satisfied by prose about the condition — CLAUDE.md records eight such cases.
     */
    #[Test]
    public function test_the_two_paths_refuse_the_same_fixture_for_the_same_reason(): void
    {
        $operator = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $subscriber = $this->makeMerchant();
        $subscriber->plan_id = $plan->id;
        $subscriber->save();

        // (a) THE HANDLER, control OFF, over HTTP: it must refuse and leave the plan on sale.
        $response = $this->actingAs($operator)
            ->post('/plan-disable', ['id' => $plan->id, 'is_disable' => 0]);

        $this->assertSame(1, $this->storedFlag($plan->id), 'the handler withdrew a plan under a subscriber.');
        $this->assertArrayHasKey('error', $response->json());

        // (b) THE EXECUTOR, asked the same thing directly.
        $outcome = DisablePlanExecutor::execute(
            ['plan_id' => $plan->id, 'is_disable' => 0],
            new OperatorActionApproval(['action' => 'plan.disable'])
        );

        $this->assertFalse($outcome->performed, 'the executor withdrew a plan under a subscriber.');
        $this->assertSame(DisablePlanExecutor::REASON_SUBSCRIBED, $outcome->reason);
        $this->assertSame(1, $this->storedFlag($plan->id));
    }

    /** A malformed stored payload is refused rather than coerced. `'5abc'` is row 5 to MySQL. */
    #[Test]
    public function test_the_executor_refuses_a_payload_it_cannot_read(): void
    {
        $plan = $this->enabledPlan();

        foreach ([
            ['plan_id' => (string) $plan->id . 'abc', 'is_disable' => 0],
            ['plan_id' => $plan->id, 'is_disable' => 7],
            ['plan_id' => 0, 'is_disable' => 0],
            [],
        ] as $index => $payload) {
            $outcome = DisablePlanExecutor::execute($payload, new OperatorActionApproval());

            $this->assertFalse($outcome->performed, 'payload ' . $index . ' was performed.');
            $this->assertSame(DisablePlanExecutor::REASON_MALFORMED, $outcome->reason, 'payload ' . $index);
            $this->assertSame(1, $this->storedFlag($plan->id), 'payload ' . $index . ' changed the plan.');
        }
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  `IAM-004` — EVERY TRANSITION REACHES THE CENTRAL AUDIT LOG.
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `IAM-004` — *"سجل تدقيق مركزي"*, a CENTRAL audit log. The request, the refused
     * self-approval, and the approval-and-execution all land in `audit_logs` with the right actor.
     *
     * `user_id` IS THE ACTOR OF THAT STEP, which is the whole point: a trail in which the approval
     * is attributed to the requester cannot evidence two-person control at all.
     */
    #[Test]
    public function test_every_transition_reaches_the_central_audit_log_with_the_right_actor(): void
    {
        $this->enforce();

        $requester = $this->makePlatformAdmin();
        $approver = $this->makePlatformAdmin();
        $plan = $this->enabledPlan();

        $approval = $this->requestWithdrawal($requester, $plan);
        TwoPersonApproval::approve($approval, $requester);           // refused: self-approval
        TwoPersonApproval::approve($approval->fresh(), $approver);   // accepted

        $rows = AuditLog::where('subject_type', OperatorActionApproval::class)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [
                'operator_action_approval_requested',
                'operator_action_approval_self_approval_refused',
                'operator_action_approved_and_executed',
            ],
            $rows->pluck('action')->all(),
            'the central audit trail does not carry one row per transition.'
        );

        $this->assertSame((int) $requester->id, (int) $rows[0]->user_id);
        $this->assertSame((int) $requester->id, (int) $rows[1]->user_id);
        $this->assertSame(
            (int) $approver->id,
            (int) $rows[2]->user_id,
            'the EXECUTION was attributed to someone other than the approver, so the trail cannot '
            . 'evidence that two different people were involved.'
        );

        $this->assertSame((int) $requester->id, (int) $rows[2]->new_values['requested_by']);
        $this->assertSame((int) $approver->id, (int) $rows[2]->new_values['approved_by']);
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  FIXTURES
    // ────────────────────────────────────────────────────────────────────────────────────────

    /** Switch the control on for one case. The shipped default is pinned by its own premise test. */
    private function enforce(): void
    {
        config(['operator_approvals.enforced' => true]);

        $this->assertTrue(TwoPersonApproval::governs('plan.disable'), 'premise: the gate is on.');
    }

    /**
     * A plan ON SALE (`is_disable = 1`; the column is inverted — see the class docblock), whose id
     * is not 1, with no subscriber.
     *
     * `is_disable` IS NOT FILLABLE, so it is set on the instance and saved;
     * `Plan::create(['is_disable' => …])` silently drops it and the default of 1 applies. Here the
     * wanted value IS 1, so this would pass either way — it is written the safe way so that
     * copying this fixture for the other direction does not inherit a false pass.
     */
    private function enabledPlan(): Plan
    {
        /*
         * THE FIXTURE PLAN MUST NOT BE PLAN 1, and under `RefreshDatabase` it would be: the table
         * starts empty, so the first insert takes id 1 -- and `planDisable`'s subscriber rule
         * EXEMPTS `$request->id != 1`, so a fixture that happened to be plan 1 would be refused by
         * a different rule than the one under test, or by none. That is the skill's lesson 15
         * measured on this file: an id that collides with a special value makes several mutants
         * survive. The premise assertion below is what caught it, and this padding row is what
         * keeps it true however the suite is ordered.
         */
        if (Plan::count() === 0) {
            Plan::create([
                'name' => 'IAM003-padding-' . uniqid(),
                'price' => 0,
                'duration' => 'Month',
                'max_users' => 1,
                'max_stores' => 1,
                'themes' => '',
                'storage_limit' => '0',
                'description' => 'reserves id 1 so the fixture below is not plan 1',
            ]);
        }

        $plan = Plan::create([
            'name' => 'IAM003-' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_users' => 10,
            'max_stores' => 2,
            // NOT NULL with no default, read from the schema: plans.themes, plans.storage_limit.
            'themes' => '',
            'storage_limit' => '0',
            'description' => 'two-person approval fixture',
        ]);

        $plan->is_disable = 1;
        $plan->save();

        $this->assertNotSame(1, (int) $plan->id, 'premise: plan 1 is exempt from the subscriber rule, so the fixture must not be it.');
        $this->assertNull(
            User::where('plan_id', $plan->id)->first(),
            'premise: nobody is subscribed to the fixture plan.'
        );

        return $plan;
    }

    /** Ask for the dangerous direction through the real gate, and return the recorded row. */
    private function requestWithdrawal(User $operator, Plan $plan): OperatorActionApproval
    {
        $decision = TwoPersonApproval::gate(
            'plan.disable',
            ['plan_id' => (int) $plan->id, 'is_disable' => 0],
            $operator
        );

        $this->assertTrue($decision->isAwaitingApproval(), 'the gate did not record the ask: ' . $decision->reason);

        return $decision->approval;
    }

    /** The stored flag, read past the model cache: `Plan` is `Cachable`. */
    private function storedFlag(int $planId): int
    {
        return (int) DB::table('plans')->where('id', $planId)->value('is_disable');
    }
}

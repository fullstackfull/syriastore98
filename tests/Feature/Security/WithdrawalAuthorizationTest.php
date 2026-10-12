<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\StoreFinanceController;
use App\Http\Middleware\FinancePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * NEW-withdrawal-view-permission-authorises-state-change.
 *
 * THE DEFECT. approveWithdrawal, rejectWithdrawal and payWithdrawal -- three
 * state-changing, money-moving endpoints -- were each gated on the single
 * permission 'View Finance Withdrawal Requests', in routes/web.php AND in the
 * controller. A VIEW permission authorised a state change: whoever could READ
 * the withdrawal list could also APPROVE and PAY. The routes/web.php comment
 * sitting on them said so in as many words and left it standing.
 *
 * THE FIX. A withdrawal state change requires ALL of
 * StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS -- the view permission
 * AND 'Edit Finance Payment Status'. `finance.permission:A,B` is AND, never OR.
 * The read (finance.withdrawals) keeps the view permission alone, so the
 * separation is real rather than a uniform tightening.
 *
 * WHY NOT A DEDICATED 'Approve Finance Withdrawal' NAME. It would be cleaner,
 * and it is reported as an owner decision instead of taken here, because
 * Utility::addNewData() is the only mechanism that creates permission ROWS and
 * this workstream does not own that file. A gated name with no row is the exact
 * defect FinancePermissionTest documents: all six finance names were enforced
 * while none was seeded, so every finance mutator was 403 for every user
 * including the platform operator. Both names used here are seeded
 * (Utility.php:2881-2886).
 *
 * METHOD. The denial assertions go through the real HTTP stack, because the
 * middleware refuses before the controller runs and so needs none of the Gate F
 * tables. The ADMISSION assertions invoke the middleware directly and inspect
 * whether $next ran -- the same technique FinancePermissionTest adopted after an
 * HTTP-level "not 403" assertion was found to pass for the wrong reason (a 500
 * is also not a 403). An admission assertion routed through HTTP here would hit
 * store_withdrawals, which no migration declares, and a 500 would score green.
 */
class WithdrawalAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const STATE_CHANGING_ROUTES = [
        'finance.withdrawals.approve',
        'finance.withdrawals.reject',
        'finance.withdrawals.pay',
    ];

    private const VIEW_PERMISSION = 'View Finance Withdrawal Requests';
    private const MUTATE_PERMISSION = 'Edit Finance Payment Status';

    /**
     * The constant is the single source of truth shared by the route file and
     * the controller, so it is asserted rather than assumed.
     */
    public function test_the_withdrawal_review_permission_set_contains_a_mutate_permission(): void
    {
        $this->assertContains(
            self::VIEW_PERMISSION,
            StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS
        );

        $this->assertContains(
            self::MUTATE_PERMISSION,
            StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS,
            'A withdrawal state change must require more than a VIEW permission.'
        );

        // And every name in it must be one Utility::addNewData() can create, or
        // the gate is a lockout rather than a control.
        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        foreach (StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS as $permission) {
            $this->assertStringContainsString(
                $permission,
                $utility,
                "'{$permission}' is gated on but is not seeded by Utility::addNewData(), "
                . 'so no role can ever hold it and every delegated user is permanently '
                . 'denied. That is the finance-permission lockout, not a fix.'
            );
        }
    }

    /**
     * THE ROUTE-TABLE PROOF, measured against the real router: each of the three
     * state changers names BOTH permissions, and the READ names only the view
     * permission.
     */
    public function test_the_three_state_changing_routes_require_both_permissions(): void
    {
        foreach (self::STATE_CHANGING_ROUTES as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $joined = implode(' ', array_map(
                'strval',
                Route::getRoutes()->getByName($name)->gatherMiddleware()
            ));

            $this->assertStringContainsString(self::VIEW_PERMISSION, $joined, $name);
            $this->assertStringContainsString(
                self::MUTATE_PERMISSION,
                $joined,
                "{$name} is a money-moving state change and must not be gated on a "
                . "VIEW permission alone. Middleware: {$joined}"
            );
        }
    }

    public function test_the_withdrawal_list_route_still_needs_only_the_view_permission(): void
    {
        $joined = implode(' ', array_map(
            'strval',
            Route::getRoutes()->getByName('finance.withdrawals')->gatherMiddleware()
        ));

        $this->assertStringContainsString(self::VIEW_PERMISSION, $joined);
        $this->assertStringNotContainsString(
            self::MUTATE_PERMISSION,
            $joined,
            'Reading the withdrawal list must not require the mutate permission, or '
            . 'the split is a uniform tightening rather than a separation of duties.'
        );
    }

    /**
     * THE DEFECT ITSELF, through the real stack: a user holding ONLY the view
     * permission is refused all three state changes.
     *
     * Before the fix this user was ADMITTED to all three.
     */
    public function test_a_view_only_user_cannot_approve_reject_or_pay(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, self::VIEW_PERMISSION);
        $staff = $staff->fresh();

        foreach ([
            '/finance/withdrawals/1/approve',
            '/finance/withdrawals/1/reject',
            '/finance/withdrawals/1/pay',
        ] as $uri) {
            $response = $this->actingAs($staff)->post($uri);

            // 403, NOT 401. repo-B lib/network/network.dart:285-287 maps any 401
            // into a forced logout that clears preferences, so an authorization
            // denial must never be a 401.
            $response->assertStatus(403);
        }

        // AND THE PROOF THAT THIS IS NOT PASSING FOR AN UNRELATED REASON: the
        // SAME user, through the SAME guard, is ADMITTED by the permission list
        // these three routes used to carry. So the denial above is caused by the
        // added permission requirement and by nothing else.
        $this->assertTrue(
            $this->guardAdmits($staff, [self::VIEW_PERMISSION]),
            'This user was admitted to approve/reject/pay before the fix; if this is '
            . 'false the 403s above prove something other than the fix.'
        );
    }

    /**
     * And the user keeps what the view permission is for. Asserted on the guard
     * rather than through HTTP, because the controller would query
     * store_withdrawals, which no migration declares.
     */
    public function test_a_view_only_user_is_still_admitted_to_the_withdrawal_list(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, self::VIEW_PERMISSION);

        $this->assertTrue(
            $this->guardAdmits($staff->fresh(), [self::VIEW_PERMISSION]),
            'The view permission must still admit its holder to the withdrawal list.'
        );
    }

    /**
     * THE LEGITIMATE WORKFLOW. A delegated user holding BOTH permissions is
     * admitted to the state changers. Without this, the denial test above could
     * pass while withdrawal review was broken for every non-super-admin.
     */
    public function test_a_user_holding_both_permissions_is_admitted_to_the_state_changers(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, self::VIEW_PERMISSION);
        $this->grantPermission($staff, self::MUTATE_PERMISSION);

        $this->assertTrue(
            $this->guardAdmits($staff->fresh(), StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS),
            'A delegated user holding both permissions must be able to review withdrawals.'
        );
    }

    /**
     * Holding ONLY the mutate permission is not enough either: the set is AND.
     */
    public function test_the_mutate_permission_alone_does_not_admit(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->grantPermission($staff, self::MUTATE_PERMISSION);

        $this->assertFalse(
            $this->guardAdmits($staff->fresh(), StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS),
            'finance.permission:A,B must mean A AND B, never A OR B.'
        );
    }

    /**
     * The platform operator keeps the seed-independent path. This is the
     * property the whole FinancePermission middleware exists to preserve, and
     * tightening the gate must not cost it.
     */
    public function test_a_platform_admin_is_still_admitted_with_no_seeded_row(): void
    {
        [, $store] = $this->makeTenant();
        $super = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(
            0,
            \App\Models\Permission::where('name', 'like', '%Finance%')->count(),
            'Precondition: no Finance permission row may exist for this assertion to mean anything.'
        );

        $this->assertTrue(
            $this->guardAdmits($super, StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS),
            'A super admin must be admitted to a withdrawal state change with no seeded row.'
        );
    }

    /**
     * A MISSING principal is 401 and a DENIAL is 403, and that distinction is
     * load-bearing: FinancePermission draws it deliberately, and the Flutter
     * client turns 401 into a forced logout.
     */
    public function test_the_guard_answers_401_for_a_missing_user_and_403_for_a_denial(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $middleware = new FinancePermission();

        // Missing principal -> 401, as JSON so the status is inspectable rather
        // than raised through abort().
        $anonymous = Request::create('/api/admin/wallet/withdrawals/1/approve', 'POST');
        $anonymous->headers->set('Accept', 'application/json');
        $anonymous->setUserResolver(fn () => null);

        $response = $middleware->handle(
            $anonymous,
            fn () => response('ok'),
            ...StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS
        );

        $this->assertSame(401, $response->getStatusCode(), 'A missing token is 401.');

        // Present principal, insufficient permission -> 403.
        $denied = Request::create('/api/admin/wallet/withdrawals/1/approve', 'POST');
        $denied->headers->set('Accept', 'application/json');
        $denied->setUserResolver(fn () => $staff);

        $response = $middleware->handle(
            $denied,
            fn () => response('ok'),
            ...StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS
        );

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'An authorization denial must be 403: repo-B network.dart:285-287 turns any '
            . '401 into a forced logout that clears preferences.'
        );
    }

    /**
     * A mis-declared route must fail CLOSED. `finance.permission:` with no
     * argument, or with an empty one, previously would have fallen straight
     * through to $next -- a typo in a middleware string turning a money-moving
     * endpoint into an open one.
     */
    public function test_the_guard_fails_closed_on_an_empty_permission_list(): void
    {
        [, $store] = $this->makeTenant();
        $super = $this->makePlatformAdmin(['current_store' => $store->id]);

        $middleware = new FinancePermission();

        foreach ([[], ['']] as $permissions) {
            $reached = false;
            $request = Request::create('/finance/withdrawals/1/approve', 'POST');
            $request->headers->set('Accept', 'application/json');
            $request->setUserResolver(fn () => $super);

            $response = $middleware->handle(
                $request,
                function () use (&$reached) {
                    $reached = true;

                    return response('ok');
                },
                ...$permissions
            );

            $this->assertFalse($reached, 'An empty permission list must not admit anybody.');
            $this->assertSame(403, $response->getStatusCode());
        }
    }

    /**
     * The controller half, which had the same single-view-permission gate as the
     * route half and must not be able to drift from it.
     *
     * BLOCKED, and reported as blocked rather than worked around: the full
     * approve transition needs store_wallets / store_withdrawals /
     * store_wallet_transactions, which are read and written by this controller
     * and declared by NO migration (Gate F). Minting the tables in the test
     * would prove nothing about the schema production runs on.
     */
    public function test_a_view_only_user_cannot_change_a_withdrawals_state_end_to_end(): void
    {
        foreach (['store_wallets', 'store_withdrawals', 'store_wallet_transactions'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped(
                    "BLOCKED: `{$table}` is declared by no migration, though the model "
                    . 'exists and StoreFinanceController queries it (GATE F -- the six '
                    . 'money tables). The route-level denial is covered by '
                    . 'test_a_view_only_user_cannot_approve_reject_or_pay; this test '
                    . 'covers the controller-level guard and becomes live the moment the '
                    . 'schema lands.'
                );
            }
        }

        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, self::VIEW_PERMISSION);

        $wallet = \App\Models\StoreWallet::create([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => 0,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 100,
            'total_income' => 100,
            'total_withdrawn' => 0,
        ]);

        $withdrawal = \App\Models\StoreWithdrawal::create([
            'store_id' => $store->id,
            'wallet_id' => $wallet->id,
            'amount' => 100,
            'status' => 'pending',
        ]);

        $this->actingAs($staff->fresh())
            ->post('/finance/withdrawals/' . $withdrawal->id . '/approve')
            ->assertStatus(403);

        $this->assertSame(
            'pending',
            $withdrawal->fresh()->status,
            'A view-only user must not be able to move a withdrawal out of pending.'
        );
    }

    /**
     * Invoke the route guard directly and report whether $next ran.
     *
     * Direct invocation rather than an HTTP call, because an HTTP admission
     * assertion would reach the controller and query a Gate F table: the request
     * would 500, and "not 403" would score green for a broken guard. That exact
     * mistake is recorded in FinancePermissionTest.
     */
    private function guardAdmits(\App\Models\User $user, array $permissions): bool
    {
        $reached = false;

        $request = Request::create('/finance/withdrawals/1/approve', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        try {
            (new FinancePermission())->handle(
                $request,
                function () use (&$reached) {
                    $reached = true;

                    return response('ok');
                },
                ...$permissions
            );
        } catch (HttpException $e) {
            // abort() path, used for non-JSON requests. Still a denial.
            return false;
        }

        return $reached;
    }
}

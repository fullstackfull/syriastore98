<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * THE PERMISSION-SEEDING PROBLEM.
 *
 * The finance surface gates on six permission names:
 *
 *   View Finance Dashboard, Add Finance Balance, Deduct Finance Balance,
 *   Request Finance Withdrawal, View Finance Withdrawal Requests,
 *   Edit Finance Payment Status
 *
 * Utility::addNewData() is the only mechanism in this application that creates
 * permission rows, and it contains zero occurrences of the string "Finance"
 * (asserted below, so this suite fails loudly if that ever stops being true in
 * either direction). Laratrust's `permission:` middleware and isAbleTo() have
 * no super-admin bypass, so on a database built from source the finance
 * mutators were 403 for EVERY user -- a lockout of the platform operator, not
 * a vulnerability.
 *
 * On the live database the rows may exist, created by hand (the same pattern as
 * the finance TABLES, which no migration declares either). We cannot see the
 * live database. These tests therefore pin the behaviour that is correct under
 * BOTH possibilities:
 *
 *   * a super admin is allowed WITHOUT any seeded row;
 *   * a delegated user is allowed ONLY when the row exists and is granted;
 *   * everyone else gets a clear 403.
 *
 * NOTE ON PRECONDITIONS. test_a_delegated_user_... mints a Permission row that
 * production does not currently create. That is deliberate and is the point of
 * the test: it proves the delegated branch still works *if* the row exists
 * (live DB, or once Agent 2 seeds it). It is not used to make any other test
 * pass -- the super-admin tests assert against a database with provably zero
 * Finance permission rows.
 */
class FinancePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The six names, as they appear at every isAbleTo()/gate call site in
     * StoreFinanceController and in the routes/web.php finance group.
     */
    public const FINANCE_PERMISSIONS = [
        'View Finance Dashboard',
        'Add Finance Balance',
        'Deduct Finance Balance',
        'Request Finance Withdrawal',
        'View Finance Withdrawal Requests',
        'Edit Finance Payment Status',
    ];

    /**
     * The defect this whole suite exists for, asserted rather than assumed.
     *
     * If Agent 2 lands the seeder, this test tells us so by failing -- at which
     * point the cross-agent dependency is closed and the assertion inverts to
     * assertNotEmpty. It is NOT a test of desired behaviour; it is a tripwire on
     * a cross-agent fact.
     */
    public function test_no_finance_permission_row_exists_on_a_database_built_from_source(): void
    {
        $this->assertSame(
            0,
            Permission::where('name', 'like', '%Finance%')->count(),
            'A Finance permission row now exists. If Utility::addNewData() seeds '
            . 'the six names, the cross-agent dependency is closed: invert this '
            . 'assertion and keep the super-admin tests below as they are.'
        );

        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        $this->assertSame(
            0,
            substr_count(strtolower($utility), 'finance'),
            'Utility.php now mentions Finance. See the note above.'
        );
    }

    /**
     * THE GATE. A super admin can authorize every finance action on a fresh
     * database with no hand-created rows.
     *
     * This exercises the real Gate closure registered by AuthServiceProvider --
     * it is not a syntax check and not a bespoke harness.
     */
    public function test_a_super_admin_is_allowed_every_finance_action_with_no_seeded_row(): void
    {
        $this->assertSame(0, Permission::where('name', 'like', '%Finance%')->count());

        $super = $this->makePlatformAdmin();

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            // The raw Laratrust answer is false -- this is the defect.
            $this->assertFalse(
                $super->isAbleTo($permission),
                "Precondition: isAbleTo('{$permission}') must be false for an unseeded row."
            );

            // The gate is true anyway -- this is the fix.
            $this->assertTrue(
                Gate::forUser($super)->allows('finance', $permission),
                "A super admin must be allowed '{$permission}' without a seeded row."
            );
        }
    }

    public function test_a_staff_user_without_the_permission_is_denied_every_finance_action(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->assertFalse(
                Gate::forUser($staff)->allows('finance', $permission),
                "A staff user with no grant must be denied '{$permission}'."
            );
        }
    }

    public function test_a_guest_is_denied_every_finance_action(): void
    {
        foreach (self::FINANCE_PERMISSIONS as $permission) {
            $this->assertFalse(
                Gate::forUser(null)->allows('finance', $permission),
                "A guest must be denied '{$permission}'."
            );
        }
    }

    /**
     * The delegated branch: a non-super-admin WITH the permission is allowed.
     *
     * This is what proves the gate did not simply become "super admin only",
     * which would have broken every merchant who legitimately holds the
     * permission on the live database.
     */
    public function test_a_delegated_user_is_allowed_once_the_permission_row_exists(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->assertFalse(Gate::forUser($staff)->allows('finance', 'Add Finance Balance'));

        $this->grantPermission($staff, 'Add Finance Balance');

        $this->assertTrue(
            Gate::forUser($staff->fresh())->allows('finance', 'Add Finance Balance'),
            'A user holding the permission must be allowed, seeded by hand or by seeder.'
        );

        // Granting one finance permission must not grant the others.
        $this->assertFalse(
            Gate::forUser($staff->fresh())->allows('finance', 'Deduct Finance Balance'),
            'Permissions must stay independent of one another.'
        );
    }

    /**
     * Every route in the finance group carries the gate, so a future route
     * added with the old `permission:` middleware (which would 403 the operator)
     * is caught here rather than in production.
     */
    public function test_every_finance_route_is_guarded_by_the_finance_gate(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'finance')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $middleware = array_map(
                static fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : ''),
                $middleware
            );
            $joined = implode(' ', $middleware);

            $this->assertStringContainsString(
                'auth',
                strtolower($joined),
                "Finance route {$route->uri()} is not behind auth."
            );

            // gatherMiddleware() returns the ALIAS as written on the route, not
            // the resolved class, so accept either spelling.
            $this->assertTrue(
                str_contains($joined, 'finance.permission:')
                || str_contains($joined, 'FinancePermission'),
                "Finance route {$route->uri()} is not guarded by FinancePermission "
                . "(middleware: {$joined})."
            );

            // The alias must actually resolve to the middleware class -- an alias
            // that is never registered is silently ignored by the router, which
            // would leave the route wide open while still reading as guarded.
            $this->assertSame(
                \App\Http\Middleware\FinancePermission::class,
                app('router')->getMiddleware()['finance.permission'] ?? null,
                'The finance.permission alias is not registered in bootstrap/app.php.'
            );

            // Laratrust's `permission:` 403s a super admin when the row is
            // unseeded; Laravel's `can:` passes NULL for an unquoted literal and
            // fatals. Neither may come back.
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[ ,])permission:/',
                $joined,
                "Finance route {$route->uri()} uses Laratrust's permission: "
                . 'middleware, which 403s a super admin when the row is unseeded.'
            );

            $this->assertStringNotContainsString(
                'Authorize:finance',
                $joined,
                "Finance route {$route->uri()} uses can:finance, which passes NULL "
                . 'for an unquoted literal and raises a TypeError at runtime.'
            );

            $checked++;
        }

        $this->assertGreaterThanOrEqual(
            9,
            $checked,
            'Expected at least the 9 routes of the finance group to be inspected.'
        );
    }

    /**
     * End-to-end denial through the real HTTP stack and the real middleware.
     *
     * This needs no finance table: the Authorize middleware refuses before the
     * controller runs, which is exactly what is being asserted.
     */
    public function test_a_staff_user_gets_a_clear_403_from_the_approve_withdrawal_route(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff)->post('/finance/withdrawals/1/approve');

        $response->assertStatus(403);
    }

    /**
     * The route-level guard itself, instantiated and invoked directly.
     *
     * A "not 403" assertion against the real HTTP stack was tried here first and
     * REMOVED, because it passed for the wrong reason: while `can:finance,<name>`
     * was in place the route raised a TypeError, and a 500 is also "not 403", so
     * a broken guard scored green. Invoking the middleware and inspecting whether
     * $next ran is the assertion that cannot be satisfied by a crash.
     */
    public function test_the_route_guard_admits_a_super_admin_and_refuses_a_staff_user(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $super = $this->makePlatformAdmin(['current_store' => $store->id]);
        $staff = $this->makeStaff($merchant);

        $middleware = new \App\Http\Middleware\FinancePermission();

        // Super admin: $next must run, on a database with no Finance rows.
        $reached = false;
        $request = \Illuminate\Http\Request::create('/finance/withdrawals/1/approve', 'POST');
        $request->setUserResolver(fn () => $super);

        $middleware->handle(
            $request,
            function () use (&$reached) {
                $reached = true;

                return response('ok');
            },
            'View Finance Withdrawal Requests'
        );

        $this->assertTrue(
            $reached,
            'The route guard must admit a super admin with no seeded permission row.'
        );

        // Staff without the permission: $next must NOT run, and the refusal must
        // be a 403.
        $reachedStaff = false;
        $staffRequest = \Illuminate\Http\Request::create('/finance/withdrawals/1/approve', 'POST');
        $staffRequest->setUserResolver(fn () => $staff);

        $status = null;

        try {
            $middleware->handle(
                $staffRequest,
                function () use (&$reachedStaff) {
                    $reachedStaff = true;

                    return response('ok');
                },
                'View Finance Withdrawal Requests'
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $status = $e->getStatusCode();
        }

        $this->assertFalse($reachedStaff, 'The route guard must refuse a staff user.');
        $this->assertSame(403, $status, 'The refusal must be a clear 403.');
    }

    /**
     * The full state transition -- pending withdrawal becomes approved -- which
     * is the behaviour the owner actually cares about.
     *
     * CROSS-AGENT DEPENDENCY. store_wallets, store_withdrawals,
     * store_wallet_transactions and store_order_financials are declared by NO
     * migration in this repository, although App\Models\StoreWallet,
     * StoreWithdrawal and StoreWalletTransaction all exist and are called from
     * this controller. They are part of the undeclared-table set. This test is
     * written now and skips until the schema agent declares them, rather than
     * minting the tables itself -- a test that creates its own schema proves
     * nothing about the schema production runs on.
     */
    public function test_a_super_admin_can_approve_a_pending_withdrawal_on_a_fresh_database(): void
    {
        foreach (['store_wallets', 'store_withdrawals', 'store_wallet_transactions'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped(
                    "BLOCKED: `{$table}` is declared by no migration, though the "
                    . 'model exists and this controller queries it '
                    . '(cross-agent dependency -- schema agent).'
                );
            }
        }

        [$merchant, $store] = $this->makeTenant();
        $super = $this->makePlatformAdmin(['current_store' => $store->id]);

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

        $this->actingAs($super)
            ->post('/finance/withdrawals/' . $withdrawal->id . '/approve')
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'approved',
            $withdrawal->fresh()->status,
            'A super admin must be able to approve a withdrawal with no seeded permission row.'
        );
    }
}

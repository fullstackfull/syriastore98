<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * F223 — `dashboard/overview.blade.php` gated its orders section on a bare `isAbleTo()`, so the
 * platform operator saw no orders at all.
 *
 * CLAUDE.md's first rule: **there is no super-admin bypass.** Operator status is a string comparison
 * on `users.type`, and `isAbleTo()` ALONE REFUSES the platform operator — so an authorization check
 * has to test the operator first or the operator is locked out of their own platform. Every other
 * order surface here does: `OrderController::mayUseOrderScreen`, `OrderDocumentAccessGuard`, and the
 * `finance` gate in `AuthServiceProvider`. This one blade did not.
 *
 * **IT FAILED IN THE SAFE DIRECTION**, which is why nothing broke and nobody noticed: the operator
 * was shown LESS, never more. That makes it a functional defect rather than a security one — and
 * exactly the kind that survives, because no test and no user complaint points at it.
 *
 * WHY A SOURCE TEST RATHER THAN A RENDER. The dashboard view cannot be rendered under
 * `RefreshDatabase` in this suite — measured earlier in this phase when an assertion about it
 * returned 500 for the ORIGINAL code too, and the honest response was to withdraw the assertion
 * rather than keep a test that measured the container. The property here is which expression the
 * blade evaluates, which is a source property; and the ratchet is what stops the limb being dropped
 * again. Where a rendered check is possible it is the better evidence, and this says plainly that
 * this is not one.
 */
class DashboardOverviewOperatorGateTest extends TestCase
{
    private const VIEW = 'resources/views/dashboard/overview.blade.php';

    private function source(): string
    {
        return (string) file_get_contents(base_path(self::VIEW));
    }

    public function test_the_orders_gate_tests_the_operator_before_asking_laratrust(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression(
            "/\\\$ssMayOrders\s*=.*?'super admin'/s",
            $source,
            'The dashboard overview\'s orders gate no longer tests the platform operator. '
            . '`isAbleTo()` ALONE REFUSES the operator — there is no super-admin bypass in '
            . 'Laratrust here — so the operator sees no orders section on their own dashboard.'
        );

        // And the operator limb must come BEFORE the permission call, since `||` short-circuits and
        // the point is to admit the operator without consulting a role they do not hold.
        $gate = [];
        preg_match("/\\\$ssMayOrders\s*=(.*?);/s", $source, $gate);
        $expression = $gate[1] ?? '';

        $this->assertNotSame('', $expression, 'the gate expression must be findable');

        $operatorAt = strpos($expression, 'super admin');
        $permissionAt = strpos($expression, 'isAbleTo');

        $this->assertNotFalse($operatorAt);
        $this->assertNotFalse($permissionAt);
        $this->assertLessThan(
            $permissionAt,
            $operatorAt,
            'the operator test must come first in the expression. `||` short-circuits, and the '
            . 'whole point is to admit the operator WITHOUT consulting a Laratrust role they do '
            . 'not hold.'
        );
    }

    public function test_the_comparison_is_normalised_the_way_the_rest_of_the_codebase_does_it(): void
    {
        /*
         * `users.type` is a plain string column with no constraint, so a stored value of
         * 'Super Admin' or ' super admin' is possible. CLAUDE.md gives the canonical form:
         *   strtolower(trim((string) $user->type)) === 'super admin'
         * A bare `=== 'super admin'` would be a second, weaker spelling of the same test.
         */
        $this->assertStringContainsString(
            "strtolower(trim((string) auth()->user()->type)) === 'super admin'",
            $this->source(),
            'the operator comparison must be the normalised form CLAUDE.md specifies — `users.type` '
            . 'is an unconstrained string column, so case and whitespace are real possibilities'
        );
    }

    public function test_the_permission_name_is_still_the_one_that_exists(): void
    {
        // A premise worth keeping: this blade's sibling gate was once `Manage Product`, SINGULAR, a
        // permission that exists in no seeder and no row — so it could be held by nobody. The
        // orders gate must name a permission that is actually seeded.
        $this->assertStringContainsString(
            "isAbleTo('Manage Order')",
            $this->source(),
            'the orders gate must still name `Manage Order`, which is seeded'
        );
    }
}

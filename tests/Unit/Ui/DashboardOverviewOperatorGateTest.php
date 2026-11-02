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
 * ---------------------------------------------------------------------------------------------
 * WS C MOVED THE SUBJECT, AND THE MOVE IS WHY THIS FILE WAS REWRITTEN RATHER THAN RELAXED.
 *
 * The rule now lives in `App\Support\MerchantDashboardAbilities`, because `dashboard.blade.php`
 * — the WorkDo page that `@include`s the overview — had to ask the same questions and a flag
 * defined inside an `@include` is invisible to the parent. Two answers is how two regions of one
 * page came to disagree about who may see order money.
 *
 * TWO OF THE THREE ORIGINAL CASES FAILED ON THAT MOVE, correctly: they named a blade that no
 * longer carries the expression. **The third passed, and should not have.**
 * `test_the_permission_name_is_still_the_one_that_exists` asserted `isAbleTo('Manage Order')`
 * appears in the overview's source — and it still does, inside the F223 docblock QUOTING the code
 * it replaced. A source assertion that matches the author's own comment is the exact failure mode
 * this repository has now hit four times, and it is why `executable()` strips comments before any
 * assertion below reads a file.
 *
 * WHAT IS ASSERTED NOW, in two halves, because either alone can be satisfied by broken code:
 *   - the RULE, in the support class: operator-first, normalised, naming seeded permissions;
 *   - the DELEGATION, in both blades: neither file may re-inline a Laratrust call of its own,
 *     which is the only way the single definition can quietly become two again.
 *
 * WHY SOURCE TESTS AT ALL. `MerchantDashboardAbilities` is also covered behaviourally — the
 * dashboard renders under the Feature suite and `DashboardFigureHonestyTest` drives a staff
 * member without each permission and reads what they are shown. What a rendered test CANNOT show
 * is the ORDERING inside a short-circuit: a gate written `isAbleTo(...) || isOperator()` behaves
 * identically for every principal in the fixture, and differs only for an operator who also holds
 * no role — so the ordering is a source property and belongs here.
 */
class DashboardOverviewOperatorGateTest extends TestCase
{
    private const OVERVIEW = 'resources/views/dashboard/overview.blade.php';

    private const WORKDO_PAGE = 'resources/views/dashboard.blade.php';

    private const ABILITIES = 'app/Support/MerchantDashboardAbilities.php';

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    /**
     * Source with every comment removed, so no assertion below can be satisfied by prose.
     *
     * `token_get_all()` RATHER THAN A REGEX, because a regex strips inside STRING LITERALS too:
     * an earlier census in this repository turned `'https://egate-t.paymera.cc'` into `'https:`
     * that way, and this very file is full of comments quoting the code they replaced.
     *
     * AND A BLADE FILE DOES NOT TOKENISE AS PHP. It opens with markup and never with `<?php`, so
     * `token_get_all()` returns ONE `T_INLINE_HTML` token for the lot and strips nothing —
     * measured: the first version of this helper left every `/* … *\/` inside `@php` blocks
     * intact, and `test_neither_dashboard_blade_re_inlines_its_own_permission_check` then failed
     * against the F223 docblock QUOTING the call it had removed. The same trap, caught by its own
     * assertion. So `@php … @endphp` bodies are tokenised individually with an opening tag
     * prepended, and the inline HTML between them is left alone.
     */
    private function executable(string $path): string
    {
        /* Blade's own comments first: they are inline HTML to PHP either way. */
        $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->source($path));

        return (string) preg_replace_callback(
            '/@php\b(.*?)@endphp/s',
            fn (array $m) => '@php' . $this->stripPhpComments($m[1]) . '@endphp',
            $blade
        );
    }

    private function stripPhpComments(string $php): string
    {
        $out = '';

        foreach (token_get_all('<?php ' . $php) as $i => $token) {
            if ($i === 0) {
                continue;                    // the opening tag this method added
            }
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------
    //  THE RULE
    // ---------------------------------------------------------------------------------------

    public function test_the_orders_gate_tests_the_operator_before_asking_laratrust(): void
    {
        $source = $this->executable(self::ABILITIES);

        $this->assertMatchesRegularExpression("/'super admin'/", $source,
            'the merchant dashboard\'s ability class no longer tests the platform operator. '
            . '`isAbleTo()` ALONE REFUSES the operator — there is no super-admin bypass in '
            . 'Laratrust here — so the operator sees no orders section on their own dashboard.');

        /* The operator limb must come BEFORE the permission call: `||` short-circuits, and the
           point is to admit the operator without consulting a role they do not hold. */
        $this->assertSame(1, preg_match('/return\s+([^;]*isAbleTo[^;]*);/s', $source, $gate),
            'the gate expression must be findable');

        $expression = $gate[1];
        $operatorAt = strpos($expression, 'isOperator');
        $permissionAt = strpos($expression, 'isAbleTo');

        $this->assertNotFalse($operatorAt,
            'the permission check no longer consults the operator test at all');
        $this->assertNotFalse($permissionAt);
        $this->assertLessThan($permissionAt, $operatorAt,
            'the operator test must come first in the expression. `||` short-circuits, and the '
            . 'whole point is to admit the operator WITHOUT consulting a Laratrust role they do '
            . 'not hold.');
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
            "strtolower(trim((string) \$user->type)) === 'super admin'",
            $this->executable(self::ABILITIES),
            'the operator comparison must be the normalised form CLAUDE.md specifies — `users.type` '
            . 'is an unconstrained string column, so case and whitespace are real possibilities'
        );
    }

    public function test_the_permission_names_are_still_the_ones_that_exist(): void
    {
        // A premise worth keeping: this screen's products gate was once `Manage Product`,
        // SINGULAR, a permission that exists in no seeder and no row — so it could be held by
        // nobody, and the dashboard silently hid the product figures from everyone including the
        // tenant owner. The names must be the seeded ones, and the two really are spelled
        // differently: `Manage Order` singular, `Manage Products` plural.
        $source = $this->executable(self::ABILITIES);

        $this->assertStringContainsString("PERMISSION_ORDERS = 'Manage Order'", $source);
        $this->assertStringContainsString("PERMISSION_PRODUCTS = 'Manage Products'", $source);
        $this->assertStringContainsString("PERMISSION_CUSTOMERS = 'Manage Customer'", $source);
        $this->assertStringContainsString("PERMISSION_STORE_SETTINGS = 'Manage Store Setting'", $source);
    }

    // ---------------------------------------------------------------------------------------
    //  THE DELEGATION
    // ---------------------------------------------------------------------------------------

    /**
     * ONE DEFINITION, OR THE DISAGREEMENT COMES BACK. Before WS C the WorkDo page answered these
     * questions by not asking them: "Top Category — By Sales", "Top Brand — By Sales", "Recent
     * Orders" and "Latest Products" were ungated, so the overview withheld order money from a
     * staff member and the markup below it showed them the same money. The failure mode to guard
     * against now is the opposite one — a second, drifting copy of the rule — so neither blade may
     * carry a Laratrust call of its own.
     */
    public function test_neither_dashboard_blade_re_inlines_its_own_permission_check(): void
    {
        foreach ([self::OVERVIEW, self::WORKDO_PAGE] as $path) {
            $source = $this->executable($path);

            $this->assertStringNotContainsString('isAbleTo(', $source,
                "{$path} calls Laratrust directly again. That is how the dashboard came to hold "
                . 'two answers to "may this principal see order money" and show both.');
            $this->assertStringNotContainsString("'super admin'", $source,
                "{$path} spells out the operator comparison again rather than asking "
                . 'MerchantDashboardAbilities.');
        }
    }

    public function test_both_dashboard_blades_ask_the_one_ability_class(): void
    {
        foreach ([self::OVERVIEW, self::WORKDO_PAGE] as $path) {
            $this->assertStringContainsString('MerchantDashboardAbilities::', $this->executable($path),
                "{$path} no longer consults the shared ability class, so its gates are either "
                . 'absent or defined somewhere this ratchet cannot see');
        }
    }
}

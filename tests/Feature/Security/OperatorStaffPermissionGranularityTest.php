<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\User;
use App\Models\Utility;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `IAM-001` — "صلاحيات دقيقة لموظفي Super Admin": FINE-GRAINED PERMISSIONS FOR SUPER ADMIN STAFF.
 *
 * Recovered decision text: `docs/development/master-plan/DECISION-ID-REGISTER.md:237`. The id
 * appears in `MASTER-PLAN-GAP-REGISTER.md` only; it is absent from
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` — measured by grep over both.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  THIS FILE DOES NOT IMPLEMENT `IAM-001`. IT MEASURES WHY IT IS NOT IMPLEMENTED, IN BOTH
 *  DIRECTIONS, SO THE GAP IS AN ASSERTED PROPERTY RATHER THAN AN ASSUMPTION.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **OPERATOR ACCESS IS BINARY.** `users.type` decides it by string comparison, and nothing grants a
 * SUBSET of it. The cases below assert that from both sides, because a gap stated only in prose is
 * how a reader's threat model goes stale — the same failure that left `PlatformOperatorOnly`'s own
 * blast radius recorded as 14 when it was 37:
 *
 *   - a `User` holding **EVERY** `Permission` row in the database, who is not type `super admin`,
 *     is refused on every operator route group. So permissions cannot grant operator powers;
 *   - a `User` of type `super admin` holding **NO ROLE AND NO PERMISSION AT ALL** is admitted to
 *     every one of them. So permissions cannot withhold operator powers either.
 *
 * Those two together are the whole of `IAM-001`'s gap: there is no third state. An operator
 * assistant who should see the revenue screen but must not disable plans cannot be expressed.
 *
 * **WHY IT CANNOT SIMPLY BE BUILT ON LARATRUST PERMISSIONS**, which is the part a reader needs
 * most, and the codebase already states it beside its own operator routes (`routes/web.php`, the
 * `platform/*` block): *"A merchant creates their own roles through the product, so a permission is
 * substantially self-assignable."* `RoleController::store()` is reachable with the `Create Role`
 * permission that the seeded global `admin` role carries for every self-registered merchant. And
 * `isAbleTo()` alone REFUSES the platform operator — there is no super-admin bypass here — so a
 * permission gate on an operator screen would be wrong in both directions at once. A case below
 * asserts that second half directly.
 *
 * **THE ONE PARTIAL MECHANISM THAT EXISTS** is `config/superadminaccess.php`: names a merchant may
 * not grant themselves, filtered server-side on both role write paths
 * (`RolePolicy::isGrantableBy()`, with `RoleEscalationTest` already covering the write paths). It
 * is a GRANTABILITY denylist and not an operator capability set, and a case below asserts the
 * difference by measuring that the operator does not hold those permissions.
 *
 * **WHAT `IAM-001` WOULD NEED** is therefore a capability namespace a tenant cannot reach at all —
 * not a `permissions` row, because that table is shared with every tenant's own role editor. That
 * is a design with a policy question inside it (which subsets exist, and who may grant them), and
 * it is reported to the owner rather than guessed at here.
 */
class OperatorStaffPermissionGranularityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * ONE REPRESENTATIVE PER OPERATOR ROUTE GROUP, matching
     * `PlatformOperatorOnlyPrincipalTest::REPRESENTATIVES`, and chosen for NO ROUTE PARAMETERS
     * wherever the group has such a route: `SubstituteBindings` is `web`-group middleware and
     * therefore runs BEFORE a route's own `PlatformOperatorOnly`, so a missing bound model would
     * answer 404 before the gate and satisfy a "refused" assertion the guard never reached.
     */
    private const OPERATOR_ROUTES = [
        'lynomia super-admin' => ['GET', '/super-admin/lynomia-integration'],
        'platform/* screens' => ['GET', '/platform/analytics'],
        'platform/approvals' => ['GET', '/platform/approvals'],
        'plan-coupon' => ['GET', '/plan-coupon'],
        'zid' => ['GET', '/zid/7/orders'],
    ];

    /**
     * **DIRECTION ONE: PERMISSIONS CANNOT GRANT OPERATOR POWERS.**
     *
     * A merchant holding EVERY permission row in the database is refused on every group. Exactly
     * 403 and not `assertNotSame(200, …)`: a 404 from a renamed route and a 302 from some other
     * rule are both "not 200", and `PlatformOperatorOnlyPrincipalTest` records being bitten by
     * precisely that looseness.
     */
    #[Test]
    public function test_a_user_holding_every_permission_is_still_refused_every_operator_route(): void
    {
        $merchant = $this->makeMerchantHoldingEveryPermission();

        foreach (self::OPERATOR_ROUTES as $group => [$verb, $uri]) {
            $response = $this->actingAs($merchant)->call($verb, $uri);

            $this->assertNotSame(
                404,
                $response->getStatusCode(),
                $group . ': ' . $uri . ' does not exist, so this assertion measures nothing.'
            );

            $this->assertSame(
                403,
                $response->getStatusCode(),
                $group . ': a principal holding EVERY permission in the database reached ' . $uri
                . '. If that is now intended it is `IAM-001` being implemented, and this file is '
                . 'the place to record how.'
            );
        }
    }

    /**
     * **DIRECTION TWO: PERMISSIONS CANNOT WITHHOLD OPERATOR POWERS EITHER.**
     *
     * An operator with no role and no permission at all is admitted everywhere. This is the half
     * that makes the gap a gap rather than a conservative default: there is no way to hand someone
     * operator status minus one capability.
     *
     * The premise — that the operator really holds nothing — is asserted, because an operator who
     * had somehow acquired a role would make this case pass for the wrong reason.
     */
    #[Test]
    public function test_an_operator_holding_no_permission_at_all_is_admitted_everywhere(): void
    {
        Utility::addNewData();

        $operator = $this->makePlatformAdmin();

        $this->assertCount(0, $operator->roles, 'premise: the operator must hold no role.');
        $this->assertGreaterThan(
            0,
            Permission::count(),
            'premise: the permission table must be seeded, or "holds no permission" is trivial.'
        );

        foreach (self::OPERATOR_ROUTES as $group => [$verb, $uri]) {
            $response = $this->actingAs($operator)->call($verb, $uri);

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                $group . ': the platform operator was refused ' . $uri . ' while holding no '
                . 'permission. CLAUDE.md records that reversing the operator-first ordering is a '
                . 'LOCKOUT rather than a tightening, and `FinancePermission` has done it once.'
            );
        }
    }

    /**
     * **`isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR**, which is the other reason a permission
     * gate cannot express `IAM-001`.
     *
     * Measured against the operator-reserved names themselves, so the claim is about exactly the
     * permissions an operator capability model would be built from. There is no super-admin bypass
     * in this codebase: Laratrust's `permissions_as_gates` is false and nothing substitutes for
     * `users.type`.
     */
    #[Test]
    public function test_the_operator_holds_none_of_the_operator_reserved_permissions(): void
    {
        Utility::addNewData();

        $operator = $this->makePlatformAdmin();
        $reserved = (array) config('superadminaccess', []);

        $this->assertNotEmpty($reserved, 'premise: config/superadminaccess.php is empty.');

        $this->assertTrue(
            UserPolicy::isOperator($operator),
            'premise: the fixture really is the platform operator by the canonical helper.'
        );

        foreach ($reserved as $name) {
            $this->assertFalse(
                $operator->isAbleTo($name),
                'the platform operator now HOLDS the reserved permission "' . $name . '". That '
                . 'changes what a permission gate means on an operator screen -- the reason those '
                . 'screens use `PlatformOperatorOnly` instead is that `isAbleTo()` refused the '
                . 'operator. If an operator role is now seeded, every "and isAbleTo() would refuse '
                . 'the operator" comment in this codebase needs re-reading.'
            );
        }

        // And Laravel's own Gate, for the same names: `permissions_as_gates => false`.
        foreach ($reserved as $name) {
            $this->assertFalse(
                $operator->can($name),
                '`can("' . $name . '")` is now true. CLAUDE.md records that Laratrust is wired with '
                . '`permissions_as_gates => false`, so `@can` and `can()` are ALWAYS false here -- '
                . 'if that has changed, every `@can` in the views starts meaning something.'
            );
        }
    }

    /**
     * `config/superadminaccess.php` HOLDS **13** NAMES, asserted because the number is quoted in
     * prose in at least two places and one of them had drifted.
     *
     * Measured 2026-11-26: the file holds 13 entries. `RolePolicy.php` says "thirteen" twice and
     * is right; `PlatformAnalyticsController.php` said "15 names" and was wrong — corrected in the
     * same change as this case. CLAUDE.md's rule: a count that lives only in prose is exactly what
     * goes stale, so it is asserted here instead.
     *
     * This is NOT a ceiling on the list. If a name is deliberately added, update the number here
     * and in `RolePolicy`'s two sentences — the point is that somebody reads it, not that it
     * cannot change.
     */
    #[Test]
    public function test_the_operator_reserved_name_list_is_still_thirteen_names(): void
    {
        $reserved = (array) config('superadminaccess', []);

        $this->assertCount(
            13,
            $reserved,
            'config/superadminaccess.php no longer holds 13 names. Update this assertion AND the '
            . '"thirteen" in RolePolicy\'s two sentences, so the prose and the file agree.'
        );

        $this->assertSame(
            array_values($reserved),
            array_values(array_unique($reserved)),
            'the reserved-name list has a duplicate, so its count overstates its coverage.'
        );
    }

    /**
     * NO PROSE IN `app/` STATES A DIFFERENT COUNT FOR THAT LIST.
     *
     * The drift this corrects was a sentence, not code, so the instrument has to read sentences.
     * It enumerates the numbers written beside the config's name and asserts each is the real one
     * — the SAFE set rather than a denylist of wrong numbers, which CLAUDE.md requires because a
     * list of wrong spellings is always short by the one nobody thought of.
     */
    #[Test]
    public function test_no_comment_in_app_states_a_different_count_for_the_reserved_list(): void
    {
        $expected = count((array) config('superadminaccess', []));
        $words = [13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 12 => 'twelve'];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'superadminaccess')) {
                continue;
            }

            foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
                if (! str_contains($line, 'superadminaccess')) {
                    continue;
                }

                // Numbers and number-words written on the same line as the config's name.
                if (preg_match('/\b(\d{1,3})\s+names?\b/i', $line, $m) === 1 && (int) $m[1] !== $expected) {
                    $offenders[] = str_replace(base_path() . '/', '', $path) . ':' . ($number + 1)
                        . ' says ' . $m[1];
                }

                foreach ($words as $value => $word) {
                    if ($value !== $expected && preg_match('/\b' . $word . '\b/i', $line) === 1) {
                        $offenders[] = str_replace(base_path() . '/', '', $path) . ':' . ($number + 1)
                            . ' says ' . $word;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'a comment in app/ states a count for config/superadminaccess.php that the file does '
            . 'not have (' . $expected . '). Prose beside a control is what a reader trusts: '
            . implode('; ', $offenders)
        );
    }

    // ────────────────────────────────────────────────────────────────────────────────────────
    //  FIXTURES
    // ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A tenant owner holding EVERY permission row in the database.
     *
     * `Utility::addNewData()` first, because it is the host mechanism that creates `Permission`
     * rows — without it the role would be granted an empty set and "holds every permission" would
     * be the same claim as "holds none", which is the shape of the skill's lesson 9 (a test that
     * cannot tell the two readings apart).
     */
    private function makeMerchantHoldingEveryPermission(): User
    {
        Utility::addNewData();

        [$merchant] = $this->makeTenant();

        $names = Permission::pluck('name')->all();

        $this->assertGreaterThan(
            50,
            count($names),
            'premise: the permission table is seeded. Granting an empty set would make the '
            . 'refusal below say nothing about permissions at all.'
        );

        foreach ($names as $name) {
            $this->grantPermission($merchant, $name);
        }

        $merchant->flushCache();
        $merchant = $merchant->fresh();

        // The premise, stated as a property of the principal and not of the fixture code: it
        // really holds names from every corner of the table, operator-reserved ones included.
        foreach (array_slice($names, 0, 5) as $name) {
            $this->assertTrue(
                $merchant->isAbleTo($name),
                'premise: the merchant must actually hold "' . $name . '".'
            );
        }

        $this->assertFalse(
            UserPolicy::isOperator($merchant),
            'premise: the fixture must NOT be the platform operator, or the refusals below are '
            . 'measuring the wrong thing.'
        );

        return $merchant;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

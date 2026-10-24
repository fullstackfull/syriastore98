<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-OWN-01 / S1 — the module-gating facts behind "BackupRestore is disabled".
 * =============================================================================================
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO: assert that the `backuprestore` routes are absent.
 * That assertion is VACUOUS in a test run and the project has already been bitten by it —
 * `CredentialBoundaryTest:504-511` records an assertion of exactly that shape protecting the
 * highest-severity row in the queue while being unable to fail, because NO WorkDo MODULE LOADS
 * IN ANY TEST RUN unless the test inserts an `add_on_managers` row
 * (`PackageServiceProvider::loadDynamicAddons()` runs inside `$this->app->booted()`).
 *
 * The behavioural claim therefore lives where it can be measured — three application boots in
 * three subprocesses, one per module state:
 * `tests/Support/Security/bin/verify-backuprestore-module-gating.php`. It MEASURED the finding
 * this file exists to protect: with an `add_on_managers` row at `is_enable = 0`, all EIGHT
 * backuprestore routes are registered, byte-identical to `is_enable = 1`. The flag changes
 * nothing about what is reachable.
 *
 * What CAN be asserted in-process is the code shape that makes that true, so a future change to
 * it is noticed here rather than in the script nobody runs. These are ratchets.
 *
 * THE RATCHETS ARE WRITTEN COMMENT-PROOF, ON PURPOSE. A ratchet that greps source can be
 * satisfied by a docblock quoting the thing it scans for — P1-INV-08 did exactly that to
 * `BulkDeleteAuthorizationTest` and it took deleting the real check and watching the suite stay
 * green (23/23) to notice. So every assertion below strips comment lines before matching.
 */
class BackupRestoreModuleGatingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A file's source with every comment line removed, so prose cannot satisfy an assertion. */
    private function codeOf(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        $kept = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === ''
                || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * THE LOADER IGNORES `is_enable`, WHICH IS THE WHOLE FINDING.
     *
     * `AddOnManager::pluck('module')` with no predicate means a row's EXISTENCE loads the
     * module's service provider and registers its routes. So "disabled" is protective only when
     * it means NO ROW; a row with the flag off still exposes the route table.
     *
     * If someone adds the `is_enable` filter, that is an IMPROVEMENT — and this test should then
     * fail, be read, and be updated along with the script and the S1 write-up, rather than the
     * three quietly disagreeing.
     */
    public function test_the_module_loader_selects_rows_without_consulting_is_enable(): void
    {
        $code = $this->codeOf('app/Providers/PackageServiceProvider.php');

        $this->assertStringContainsString("AddOnManager::pluck('module')", $code,
            'The dynamic-addon loader no longer selects modules with a bare pluck(). Re-read it: '
            . 'if it now filters on is_enable, the P1-OWN-01 finding has changed and '
            . 'verify-backuprestore-module-gating.php plus the S1 write-up need updating in the '
            . 'same change.');

        $this->assertStringNotContainsString('is_enable', $code,
            'PackageServiceProvider now mentions is_enable in CODE. That would change which '
            . 'modules load, which is the single fact P1-OWN-01 measured. Update the script and '
            . 'the write-up with it.');
    }

    /**
     * `is_enable` GATES ONLY THE ACTIVATION CHECK, NOT THE LOADER.
     *
     * The other half of the disagreement: `Module::isEnabled()` does consult the flag, so the
     * two sources answer differently for the same row by design.
     */
    public function test_the_activation_check_does_consult_is_enable(): void
    {
        $code = $this->codeOf('app/Classes/Module.php');

        $this->assertStringContainsString("where('is_enable', 1)", $code,
            'App\Classes\Module no longer filters on is_enable, so the loader and the activation '
            . 'check may no longer disagree -- which would change the P1-OWN-01 finding.');
    }

    /**
     * `PlanModuleCheck` IS A NO-OP FOR THE PLATFORM OPERATOR, WHATEVER THE MODULE STATE.
     *
     * `handle()` opens `if (auth()->user() && auth()->user()->type != 'super admin')` and falls
     * through to `return $next($request)`. So for the operator the module gate does not apply at
     * all, and the only thing in front of a BackupRestore action is the controller's
     * `isAbleTo('backup&restore ...')` -- a Laratrust permission check which, per CLAUDE.md, does
     * NOT exempt the operator and therefore refuses them unless the permission was granted.
     *
     * This is pinned because it is load-bearing in the opposite direction to intuition: the
     * operator is NOT protected by the module being inactive, and merchants ARE.
     */
    public function test_plan_module_check_skips_the_platform_operator(): void
    {
        $code = $this->codeOf('app/Http/Middleware/PlanModuleCheck.php');

        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*auth\(\)->user\(\)\s*&&\s*auth\(\)->user\(\)->type\s*!=\s*'super admin'\s*\)/",
            $code,
            'PlanModuleCheck no longer short-circuits for a super admin. If that limb was '
            . 'removed the operator is now subject to the module gate, which is a behaviour '
            . 'change worth recording -- and it invalidates the P1-OWN-01 write-up.');

        $this->assertStringContainsString('return $next($request);', $code,
            'PlanModuleCheck no longer falls through to $next(), so the operator path has '
            . 'changed shape.');
    }

    /**
     * THE MODULE'S OWN ROUTES CARRY `auth`, SO S1'S TITLE IS IMPRECISE.
     *
     * S1 is headed "unauthenticated route to the whole database and .env". The ROUTE is not
     * unauthenticated. What is unauthenticated is the ARTEFACT: a generated backup sits at 0777
     * inside the document root and .htaccess serves any real file unrewritten. Pinned so the
     * containment work is aimed at the host, not at route middleware.
     *
     * Reading the vendored route file is allowed; EDITING packages/workdo/ is not.
     */
    public function test_the_modules_own_routes_are_declared_behind_auth(): void
    {
        $code = $this->codeOf('packages/workdo/BackupRestore/src/routes/web.php');

        $this->assertStringContainsString(
            "Route::middleware(['auth', 'web', 'PlanModuleCheck:BackupRestore'])",
            $code,
            'The BackupRestore resource routes are no longer declared behind auth. If the module '
            . 'is ever enabled this becomes an unauthenticated path to the backup function '
            . 'itself, which is a different and worse finding than the artefact exposure S1 '
            . 'describes.');

        // And nothing else in the file declares a route outside that group. The file's only other
        // group is an EMPTY `Route::middleware(['web'])`.
        $this->assertSame(
            1,
            preg_match_all('/Route::(resource|get|post|put|patch|delete|any|match)\s*\(/', $code),
            'The module route file now declares more than the single resource route P1-OWN-01 '
            . 'enumerated. Re-read it: a route added outside the auth group would be reachable '
            . 'by anyone the moment an add_on_managers row exists.');
    }
}

<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\DisabledModuleRouteGuard;
use App\Models\AddOnManager;
use App\Support\Modules\ModuleAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SWITCHING AN APPLICATION OFF REMOVED NOTHING: ITS ROUTES STAYED MOUNTED.
 *
 * Phase 06 item 2(B). `PackageServiceProvider:47` reads `AddOnManager::pluck('module')` with NO
 * `is_enable` predicate and hands the result to `loadAddons()`, which registers each module's PSR-4
 * prefix and service provider. Two other places in the same codebase DO filter on that flag
 * (`Module.php:114` and `:141`), so the omission is a divergence rather than a stated design.
 *
 * MEASURED through the project's own harness (`tests/Support/Security/bin/route-census.php`, all 70
 * modules loaded through the production loader, 0 load failures):
 *
 *   3,058 routes in the table | 1,464 owned by a module across 68 modules | 1,594 host
 *   1,012 of the module routes carry their own authentication | 452 do not
 *   18 of the 1,464 sit OUTSIDE the `web` group, so the guard is registered in `api` too
 *
 * WHY THE DECISION LOGIC IS TESTED ON SYNTHETIC ROUTES RATHER THAN A LOADED MODULE. CLAUDE.md
 * records that NO WorkDo module loads in any test run unless the test inserts an `add_on_managers`
 * row -- and `route-census.php`'s own docblock records why loading one in-process is worse than
 * useless here: `loadAddons()` adds PSR-4 prefixes to the process-global Composer ClassLoader and
 * pollutes every later test in the run (it moved `ResidualTenancyExposureTest`'s count from 23 to 21
 * once). So the real-module proof lives in a separate process, as the census does, and what is
 * asserted here is the guard's decision on routes this test constructs -- which is the part that can
 * be wrong in a way nobody notices.
 */
class DisabledModuleRouteGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function moduleRoute(string $module, array $middleware = ['web', 'auth']): Route
    {
        return new Route(['GET'], 'probe/' . strtolower($module), [
            'controller' => 'Workdo\\' . $module . '\\Http\\Controllers\\ProbeController@index',
            'middleware' => $middleware,
        ]);
    }

    private function drive(Route $route): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/probe', 'GET');
        $request->setRouteResolver(fn () => $route);

        return (new DisabledModuleRouteGuard())->handle(
            $request,
            fn () => new \Illuminate\Http\Response('REACHED THE HANDLER', 200)
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the predicate
    // ---------------------------------------------------------------------------------------------

    /** THE DEFECT: a row saying the application is off must mean it is off. */
    #[Test]
    public function test_a_module_whose_row_says_disabled_is_disabled(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 0]);

        $this->assertTrue(ModuleAvailability::isDisabled('Campaigns'));
    }

    /** The control: an enabled row is not disabled. */
    #[Test]
    public function test_a_module_whose_row_says_enabled_is_not_disabled(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 1]);

        $this->assertFalse(ModuleAvailability::isDisabled('Campaigns'));
    }

    /**
     * AN ABSENT ROW IS NOT A DISABLED APPLICATION, AND THIS IS THE TEST THAT MATTERS MOST.
     *
     * `PackageServiceProvider:49-51` force-adds `LandingPage` to the loaded set whether or not it has
     * an `add_on_managers` row -- and `LandingPage` owns 275 routes, MORE THAN ANY OTHER MODULE,
     * including the public landing pages. A guard that read "no row" as "disabled" would take them
     * down. It is the same mistake the account-suspension predicate nearly shipped, with a concrete
     * victim this time.
     */
    #[Test]
    public function test_a_module_with_no_row_is_not_disabled(): void
    {
        $this->assertSame(0, AddOnManager::where('module', 'LandingPage')->count(), 'premise: no row');

        $this->assertFalse(
            ModuleAvailability::isDisabled('LandingPage'),
            'A module with no add_on_managers row was treated as switched off. An absent row means '
            . '"not managed through the add-on manager", and LandingPage is force-added by the '
            . 'provider with 275 routes including the public landing pages.'
        );
    }

    /** @return list<array{0: mixed}> */
    public static function emptyModuleNames(): array
    {
        return [[null], ['']];
    }

    #[Test]
    #[DataProvider('emptyModuleNames')]
    public function test_an_absent_module_name_is_not_disabled(mixed $name): void
    {
        $this->assertFalse(ModuleAvailability::isDisabled($name));
    }

    /**
     * EITHER WAY OF WRITING THE FLAG IS READ THE SAME -- AND THIS TEST SAYS WHAT IT CANNOT PROVE.
     *
     * An earlier version of this test claimed to pin a loose-vs-strict comparison. A mutation
     * refuted that: changing `== 0` to `=== 0` SURVIVED it. The reason is measured --
     * `add_on_managers.is_enable` is `$table->boolean(...)` (`tinyint(1)`, NOT NULL, default 0) and
     * on SQLite the driver returns an INTEGER whichever way the value went in, for all four of `0`,
     * `'0'`, `1`, `'1'`. So the two comparisons are indistinguishable in this suite and no test here
     * can tell them apart.
     *
     * The loose form is kept for a reason the suite cannot reach: production is MySQL, whose PDO
     * driver can hand back a `tinyint(1)` as the string `"0"`, and `=== 0` would then read a disabled
     * module as enabled. CLAUDE.md records SQLite's type affinity hiding a real MySQL defect as how
     * P01-SEC-13 survived, so that gap is stated rather than papered over. What this test DOES pin is
     * that both ways of writing the flag round-trip to the same answer.
     */
    #[Test]
    public function test_either_way_of_writing_the_flag_reads_the_same(): void
    {
        AddOnManager::create(['module' => 'IntOff', 'name' => 'IntOff', 'is_enable' => 0]);
        AddOnManager::create(['module' => 'StrOff', 'name' => 'StrOff', 'is_enable' => '0']);
        AddOnManager::create(['module' => 'IntOn', 'name' => 'IntOn', 'is_enable' => 1]);
        AddOnManager::create(['module' => 'StrOn', 'name' => 'StrOn', 'is_enable' => '1']);

        $this->assertTrue(ModuleAvailability::isDisabled('IntOff'));
        $this->assertTrue(ModuleAvailability::isDisabled('StrOff'));
        $this->assertFalse(ModuleAvailability::isDisabled('IntOn'));
        $this->assertFalse(ModuleAvailability::isDisabled('StrOn'));

        // The premise of the paragraph above, asserted rather than asserted-about: SQLite hands back
        // an int either way, which is why no assertion in this file can distinguish == from ===.
        $this->assertSame(0, AddOnManager::where('module', 'StrOff')->value('is_enable'));
        $this->assertSame(1, AddOnManager::where('module', 'StrOn')->value('is_enable'));
    }

    // ---------------------------------------------------------------------------------------------
    // the guard
    // ---------------------------------------------------------------------------------------------

    /** THE DEFECT, at the guard: a disabled module's authenticated route must be refused. */
    #[Test]
    public function test_a_disabled_modules_authenticated_route_is_refused(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 0]);

        $response = $this->drive($this->moduleRoute('Campaigns'));

        $this->assertSame(
            302,
            $response->getStatusCode(),
            'A switched-off application still served an authenticated route. Its code stays loaded '
            . 'and its routes stay mounted, because PackageServiceProvider:47 reads the module list '
            . 'with no is_enable predicate.'
        );
        $this->assertStringNotContainsString('REACHED THE HANDLER', (string) $response->getContent());
    }

    /** NEVER A 401: the shipped Flutter app forces a logout on any 401 (CLAUDE.md). */
    #[Test]
    public function test_the_refusal_is_a_403_for_a_json_caller_and_never_a_401(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 0]);

        $route = $this->moduleRoute('Campaigns');
        $request = Request::create('/probe', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setRouteResolver(fn () => $route);

        $response = (new DisabledModuleRouteGuard())->handle(
            $request,
            fn () => new \Illuminate\Http\Response('REACHED THE HANDLER', 200)
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame(401, $response->getStatusCode(),
            'A 401 makes the shipped Flutter admin app log the user out.');
    }

    /** The control: an ENABLED module's authenticated route still works. */
    #[Test]
    public function test_an_enabled_modules_authenticated_route_still_works(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 1]);

        $response = $this->drive($this->moduleRoute('Campaigns'));

        $this->assertSame(200, $response->getStatusCode(),
            'The guard refused an application that is switched ON, which would break every module.');
        $this->assertStringContainsString('REACHED THE HANDLER', (string) $response->getContent());
    }

    /**
     * A HOST ROUTE IS NEVER REFUSED -- including the module enable and remove screens themselves,
     * which live in the first-party `ModuleController` and must stay reachable for an application
     * that is switched off, or it could never be switched back on.
     */
    #[Test]
    public function test_a_host_route_is_never_refused(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 0]);

        $hostRoute = new Route(['POST'], 'remove-modules/{module}', [
            'controller' => 'App\\Http\\Controllers\\ModuleController@remove',
            'middleware' => ['web', 'auth'],
        ]);

        $this->assertNull(DisabledModuleRouteGuard::moduleOf($hostRoute), 'premise: a host route');
        $this->assertSame(200, $this->drive($hostRoute)->getStatusCode(),
            'A first-party route was refused. The module screens themselves are host routes.');
    }

    /**
     * AN ANONYMOUS ROUTE OF A DISABLED MODULE PASSES THROUGH, DELIBERATELY AND NOT BY OVERSIGHT.
     *
     * 87 of the anonymous module routes are payment-gateway callbacks by
     * `ModuleRouteManifest::ANONYMOUS_BY_DESIGN`'s own classification. Refusing one can lose a
     * CAPTURED PAYMENT: if an application is switched off while a transaction is in flight, the
     * gateway's return or notify URL is how the capture gets recorded. Whether a switched-off
     * application still records money already taken is a settlement rule and is the owner's.
     *
     * This test pins the current, bounded behaviour so that widening it is a DECISION somebody makes
     * on purpose rather than a side effect -- and so that the day the owner answers, the test that
     * has to change says why.
     */
    #[Test]
    public function test_an_anonymous_route_of_a_disabled_module_is_not_refused_pending_an_owner_decision(): void
    {
        AddOnManager::create(['module' => 'Campaigns', 'name' => 'Campaigns', 'is_enable' => 0]);

        $anonymous = $this->moduleRoute('Campaigns', ['web']);

        $this->assertFalse(DisabledModuleRouteGuard::requiresAuthentication($anonymous), 'premise');
        $this->assertSame(
            200,
            $this->drive($anonymous)->getStatusCode(),
            'The guard was widened to anonymous module routes. 87 of those are payment-gateway '
            . 'callbacks, and refusing one can lose a captured payment -- that is an owner decision.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the wiring
    // ---------------------------------------------------------------------------------------------

    /**
     * REGISTERED IN BOTH GROUPS, because 18 of the 1,464 module routes sit outside `web`:
     * LynomiaChat's 8 commerce endpoints, CartQuantityControl's 8, and one each from LandingPage and
     * ProductsPDF. Measured, not assumed -- a `web`-only registration would silently miss them.
     */
    #[Test]
    public function test_the_guard_is_registered_in_both_the_web_and_api_groups(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middlewareGroups');
        $property->setAccessible(true);
        $groups = $property->getValue($kernel);

        foreach (['web', 'api'] as $group) {
            $this->assertContains(
                DisabledModuleRouteGuard::class,
                $groups[$group] ?? [],
                'The guard is not in the ' . $group . ' group, so module routes in it are unguarded.'
            );
        }
    }
}

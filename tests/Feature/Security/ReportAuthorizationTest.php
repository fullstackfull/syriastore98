<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\ReportController;
use App\Http\Middleware\ReportPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * P1-SEC-09 -- THE REPORT HANDLERS CHECKED NO PERMISSION, SO A STAFF MEMBER WITH NO REPORT ROLE READ EVERY REPORT BY URL.
 * =========================================================================================================================
 *
 * Within one tenant (getCurrentStore() scopes the data throughout), a vertical privilege escalation: order and revenue figures, customer
 * lists and CSV/XLSX exports for any staff member, because `Manage Reports` / `Manage Order Reports` / `Manage Stock Reports` were enforced only
 * by the sidebar menu. The finding's original measurement transcript was lost (a harness truncation), so it is re-established here as the
 * RED of this file rather than taken on trust.
 *
 * ORACLE: the response a permissionless staff member receives -- the refusal must be a redirect/403 that carries none of the report body -- and
 * the SAME request by a staff member who was granted the matching permission, by the owner and by the operator (positive controls: a 403 from
 * a route that 403s everyone would pass the first case for the wrong reason). `config(['app.debug' => false])` before any body is read: an
 * APP_DEBUG query log was once nearly mis-filed as a disclosure.
 */
class ReportAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** @return array<string, array{0: string, 1: string}> uri => the permission that opens it */
    public static function screens(): array
    {
        return [
            'customer reports' => ['/reports', 'Manage Reports'],
            'customer reports chart' => ['/reports-chart', 'Manage Reports'],
            'customer export' => ['/report-export', 'Manage Reports'],
            'top products' => ['/top-all-reports', 'Manage Reports'],
            'sales report' => ['/order-reports', 'Manage Order Reports'],
            'sales chart' => ['/order-reports-chart', 'Manage Order Reports'],
            'sales export' => ['/order-report-export', 'Manage Order Reports'],
            'sales barchart export' => ['/order-barchart-report-export', 'Manage Order Reports'],
            'sales chart data' => ['/order-reports-chart-data', 'Manage Order Reports'],
            'product sales' => ['/product-order-sale-reports', 'Manage Order Reports'],
            'product sales chart' => ['/product-order-reports', 'Manage Order Reports'],
            'product export' => ['/product-order-export', 'Manage Order Reports'],
            'category sales' => ['/category-order-sale-reports', 'Manage Order Reports'],
            'category export' => ['/category-order-export', 'Manage Order Reports'],
            'brand sales' => ['/Brand-order-sale-reports', 'Manage Order Reports'],
            'brand export' => ['/brand-order-export', 'Manage Order Reports'],
            'downloadable sales' => ['/order-downloadable-reports', 'Manage Order Reports'],
            'order status' => ['/Status-reports', 'Manage Order Reports'],
            'country orders' => ['/order-country-reports', 'Manage Order Reports'],
            'stock report' => ['/stock-reports', 'Manage Stock Reports'],
            'stock report (short)' => ['/stock-report', 'Manage Stock Reports'],
            'low stock' => ['/stock-report/low-stock', 'Manage Stock Reports'],
            'out of stock' => ['/stock-report/out-stock', 'Manage Stock Reports'],
            'most stocked' => ['/stock-report/most-stocked', 'Manage Stock Reports'],
        ];
    }

    private function refused($response): bool
    {
        return $response->status() === 403 || ($response->isRedirect() && session()->has('error'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_a_staff_member_with_no_report_permission_is_refused(string $uri, string $permission): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $this->assertFalse($staff->isAbleTo($permission), 'Premise: the staff member holds no report permission.');

        $response = $this->actingAs($staff->fresh())->get($uri);

        $this->assertTrue($this->refused($response), "{$uri}: a staff member with no report permission was answered {$response->status()}.");
        $this->assertNotSame(401, $response->status());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_the_matching_permission_opens_it_and_a_different_report_permission_does_not(string $uri, string $permission): void
    {
        [$merchant] = $this->makeTenant();

        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, $permission);
        $opens = $this->actingAs($holder->fresh())->get($uri);
        $this->assertFalse($this->refused($opens), "{$uri}: a staff member holding '{$permission}' was refused ({$opens->status()}).");

        $other = collect(['Manage Reports', 'Manage Order Reports', 'Manage Stock Reports'])->first(fn ($p) => $p !== $permission);
        $wrong = $this->makeStaff($merchant);
        $this->grantPermission($wrong, $other);
        $this->assertTrue($this->refused($this->actingAs($wrong->fresh())->get($uri)), "{$uri}: '{$other}' opened a '{$permission}' screen.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_the_store_owner_and_the_platform_operator_are_admitted(string $uri): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->assertFalse($this->refused($this->actingAs($merchant->fresh())->get($uri)), "{$uri}: the store owner was refused.");

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->assertFalse($this->refused($this->actingAs($operator)->get($uri)), "{$uri}: the platform operator was refused.");
    }

    /**
     * creatorId() makes a CANONICALLY spelled 'super admin' its own owner, so the owner limb alone admits the operator; a non-canonical
     * spelling ('Super Admin ') is the operator for every other check in the application (normalised comparison) and only the explicit
     * operator limb says so. Without this case the operator limb is unreachable and a removal check cannot tell it from the owner limb.
     */
    public function test_an_operator_whose_type_is_spelled_non_canonically_is_still_admitted(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['type' => ' Super Admin ', 'current_store' => $store->id]);
        $this->assertNotSame((int) $operator->id, (int) $operator->creatorId(), 'Premise: the owner limb does NOT admit this principal.');

        $this->assertFalse($this->refused($this->actingAs($operator)->get('/order-reports-chart')));
    }

    /** An action the map does not name is held to `Manage Reports`, not left open and not held to nothing. */
    public function test_an_unmapped_action_falls_back_to_manage_reports(): void
    {
        /*
         * REWRITTEN 2026-11-26, AND THE REASON IS THE POINT OF THE CASE.
         *
         * This drove `GET /reports/1` -- the `show` stub of `Route::resource('reports', ...)`.
         * `ReportController` defines no `show`, so that stub was one of the dead resource actions
         * that answered HTTP 500, and the route is now `->only(['index'])`. `/reports/1` is
         * therefore a 404, `refused()` accepts only a 403 or a redirect carrying the gate's
         * message, and this case failed -- against a change that made the product STRICTLY BETTER.
         *
         * So the old shape measured the fallback THROUGH A DEAD ROUTE, and the route was the only
         * way in. `ACTIONS`'s fallback still exists and is still fail-closed; what no longer exists
         * is a routed action that reaches it. Driving the middleware DIRECTLY is the only way to
         * see a property the route table cannot express -- the same reason the operator-gate tests
         * had to call their handlers directly, because the middleware answers 403 first and the
         * handler is never reached.
         *
         * Both properties are asserted here, so neither can be lost: the stub is GONE, and if it
         * ever comes back the fallback will hold it to `Manage Reports`.
         */
        [$merchant] = $this->makeTenant();

        /* PREMISE 1: `show` really is unmapped. If someone maps it, this case stops measuring the
           fallback and must say so rather than passing for a new reason. */
        $actions = (new \ReflectionClass(\App\Http\Middleware\ReportPermission::class))->getConstant('ACTIONS');
        $this->assertArrayNotHasKey('show', $actions,
            'Premise: `show` is no longer unmapped, so this case is not measuring the fallback.');

        /* PREMISE 2: the dead stub really is withdrawn. This is the stronger property the route
           change created, and it is asserted rather than assumed. */
        $this->assertNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('reports.show'),
            'The `reports.show` stub is routed again. It names a method ReportController does not '
            . 'define, so it answers HTTP 500 -- re-withdraw it rather than mapping it.');

        $drive = function ($user) {
            $request = \Illuminate\Http\Request::create('/reports/1', 'GET');
            $route = new \Illuminate\Routing\Route('GET', 'reports/{report}', [
                'uses' => \App\Http\Controllers\ReportController::class . '@show',
            ]);
            $request->setRouteResolver(fn () => $route);

            $this->actingAs($user->fresh());

            $reached = false;
            $response = (new \App\Http\Middleware\ReportPermission())->handle(
                $request,
                function () use (&$reached) {
                    $reached = true;

                    return new \Illuminate\Http\Response('reached', 200);
                }
            );

            return [$reached, $response];
        };

        [$reached] = $drive($this->makeStaff($merchant));
        $this->assertFalse($reached, 'An unmapped action was open to a staff member with no report permission.');

        $stock = $this->makeStaff($merchant);
        $this->grantPermission($stock, 'Manage Stock Reports');
        [$reached] = $drive($stock);
        $this->assertFalse($reached, 'An unrelated report permission opened an unmapped action.');

        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, 'Manage Reports');
        [$reached, $response] = $drive($holder);
        $this->assertTrue($reached,
            'Manage Reports did not open the fallback (the gate answered ' . $response->getStatusCode() . '), '
            . 'so the fallback is now fail-closed against EVERYBODY rather than held to Manage Reports.');
    }

    public function test_a_json_caller_is_answered_403_not_401(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff->fresh())->getJson('/order-reports-chart');

        $this->assertSame(403, $response->status());
        $this->assertSame('error', $response->json('status'));
    }

    /** Every route that reaches ReportController is covered by the gate, and every action it serves has an explicit permission. */
    public function test_every_routed_report_action_is_mapped_and_gated(): void
    {
        $actions = [];

        foreach (Route::getRoutes() as $route) {
            if (($route->getAction('controller') ?? '') !== '' && str_starts_with((string) $route->getAction('controller'), ReportController::class . '@')) {
                $actions[$route->getActionMethod()] = true;
                $this->assertContains(ReportPermission::class, app('router')->gatherRouteMiddleware($route), $route->uri() . ' does not carry the report gate.');
            }
        }

        $resourceStubs = ['show', 'create', 'store', 'edit', 'update', 'destroy'];
        $unmapped = array_values(array_diff(array_keys($actions), array_keys(ReportPermission::ACTIONS), $resourceStubs));

        $this->assertSame([], $unmapped, 'A routed report action has no explicit permission (it would fall back to Manage Reports).');
        $this->assertGreaterThanOrEqual(24, count(array_intersect(array_keys($actions), array_keys(ReportPermission::ACTIONS))), 'Premise: the scan found the report routes.');
    }

    public function test_the_map_names_only_permissions_the_application_seeds(): void
    {
        $seeded = (string) file_get_contents(app_path('Models/Utility.php'));

        foreach (array_unique(array_values(ReportPermission::ACTIONS)) as $permission) {
            $this->assertStringContainsString("'{$permission}'", $seeded, "'{$permission}' is not created by Utility::addNewData, so it can never be granted and the screens would be a permanent refusal.");
        }
    }
}

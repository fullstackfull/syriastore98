<?php

namespace Tests\Feature\Security;

use App\Support\ModuleRouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-04 -- THE ROUTE-CENSUS GATE.
 * ===================================
 *
 * The suite is structurally blind to module routes (no WorkDo module loads in a test run unless a test inserts an add_on_managers row), and a
 * module's route file can put a merchant screen in the same `Route::middleware(['web'])` group as a shopper endpoint. This gate re-runs the
 * census in its OWN PROCESS -- tests/Support/Security/bin/route-census.php boots a throwaway application on sqlite :memory:, loads all 70
 * modules through the production loader and prints the resolved route table -- and judges it against App\Support\ModuleRouteManifest:
 *
 *   - every module route with NO authentication middleware must be classified: administrative (ModuleAdministrativeRouteGuard will refuse an
 *     anonymous caller) or anonymous by design. An unclassified one fails the build;
 *   - a classification must still point at a real module route (a stale key would silently pre-classify a future route that reuses it);
 *   - a key cannot be in both tables;
 *   - NON-VACUITY: the census must have loaded the modules and found the routes (floors below), so an empty or truncated table cannot "pass".
 *
 * It extends the plain PHPUnit TestCase on purpose: it needs no application and must not touch the process-global state the others share.
 */
class RouteCensusGateTest extends TestCase
{
    private const MODULE_FLOOR = 70;
    private const ROUTE_FLOOR = 2500;
    private const MODULE_ROUTE_FLOOR = 1400;
    private const ANONYMOUS_MODULE_ROUTE_FLOOR = 400;

    private const RULES = ['auth', 'operator', 'owner:campaign', 'owner:campaign-route', 'owner:helpdesk-ticket', 'owner:helpdesk-ticket-link', 'owner:email-template', 'owner:duo-setting', 'settings:store', 'settings:global'];

    private static ?array $census = null;

    private function census(): array
    {
        if (self::$census === null) {
            $root = dirname(__DIR__, 3);
            $out = [];
            $code = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/Support/Security/bin/route-census.php') . ' 2>&1', $out, $code);
            $json = json_decode(implode("\n", $out), true);

            $this->assertSame(0, $code, 'route-census.php failed: ' . substr(implode("\n", $out), 0, 600));
            $this->assertIsArray($json, 'route-census.php did not print JSON: ' . substr(implode("\n", $out), 0, 300));

            self::$census = $json;
        }

        return self::$census;
    }

    /** @return array<string,array> `METHOD uri` => row, for module routes */
    private function moduleRoutes(array $census): array
    {
        $routes = [];

        foreach ($census['routes'] as $row) {
            if ($row['module'] !== null) {
                $routes[$row['method'] . ' ' . $row['uri']][] = $row;
            }
        }

        return $routes;
    }

    /**
     * The rows the gate rejects: module routes with no authentication middleware that no table classifies. Pure, so the gate's own bite can be
     * shown on a synthetic table (below) as well as on the real one.
     */
    public static function unclassified(array $rows, array $administrative, array $byDesign): array
    {
        $bad = [];

        foreach ($rows as $row) {
            if ($row['module'] === null || $row['authenticated']) {
                continue;
            }

            $key = $row['method'] . ' ' . $row['uri'];

            if (! isset($administrative[$key]) && ! isset($byDesign[$key])) {
                $bad[$key] = $row['module'];
            }
        }

        ksort($bad);

        return $bad;
    }

    public function test_the_census_really_loaded_every_module_and_found_the_routes(): void
    {
        $census = $this->census();

        $this->assertSame([], $census['load_failures'], 'A module failed to load; the gate would be judging a partial table.');
        $this->assertGreaterThanOrEqual(self::MODULE_FLOOR, $census['modules']);
        $this->assertGreaterThanOrEqual(self::ROUTE_FLOOR, count($census['routes']));
        $this->assertGreaterThanOrEqual(self::MODULE_ROUTE_FLOOR, count(array_filter($census['routes'], fn ($r) => $r['module'] !== null)));

        $anonymous = array_filter($census['routes'], fn ($r) => $r['module'] !== null && ! $r['authenticated']);
        $this->assertGreaterThanOrEqual(self::ANONYMOUS_MODULE_ROUTE_FLOOR, count($anonymous), 'The census found suspiciously few unauthenticated module routes.');
    }

    public function test_every_unauthenticated_module_route_is_classified(): void
    {
        $bad = self::unclassified($this->census()['routes'], ModuleRouteManifest::ADMINISTRATIVE, ModuleRouteManifest::ANONYMOUS_BY_DESIGN);

        $this->assertSame([], $bad, "Module routes with no authentication middleware that App\\Support\\ModuleRouteManifest does not classify (add each to ADMINISTRATIVE, "
            . "where ModuleAdministrativeRouteGuard will require a signed-in user, or to ANONYMOUS_BY_DESIGN with the reason):\n" . implode("\n", array_map(fn ($k, $m) => "  [{$m}] {$k}", array_keys($bad), $bad)));
    }

    public function test_the_gate_fails_on_a_deliberately_unclassified_administrative_route(): void
    {
        $rows = $this->census()['routes'];
        $rows[] = ['method' => 'POST', 'uri' => 'p1-sec-04/deliberately-ungated-admin-screen', 'action' => 'Workdo\\Fake\\Controller@store', 'module' => 'Fake', 'name' => null, 'authenticated' => false, 'middleware' => ['web']];

        $bad = self::unclassified($rows, ModuleRouteManifest::ADMINISTRATIVE, ModuleRouteManifest::ANONYMOUS_BY_DESIGN);

        $this->assertSame(['POST p1-sec-04/deliberately-ungated-admin-screen' => 'Fake'], $bad);
    }

    public function test_an_authenticated_route_needs_no_classification(): void
    {
        $rows = [['method' => 'POST', 'uri' => 'x', 'action' => 'a', 'module' => 'Fake', 'name' => null, 'authenticated' => true, 'middleware' => ['auth']]];

        $this->assertSame([], self::unclassified($rows, [], []));
    }

    public function test_no_key_is_in_both_tables_and_every_rule_is_known(): void
    {
        $this->assertSame([], array_keys(array_intersect_key(ModuleRouteManifest::ADMINISTRATIVE, ModuleRouteManifest::ANONYMOUS_BY_DESIGN)));

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            $known = in_array($rule, self::RULES, true) || preg_match('/^store-row:[a-z_]+:\w+$/', $rule) === 1;

            $this->assertTrue($known, "{$key} has an unknown rule '{$rule}': the guard would fail it closed for everyone but the operator.");
        }
    }

    public function test_every_by_design_category_is_known_and_a_guarded_one_names_a_guard_the_route_really_carries(): void
    {
        $byKey = [];

        foreach ($this->census()['routes'] as $row) {
            if ($row['module'] !== null) {
                $byKey[$row['method'] . ' ' . $row['uri']] = $row;
            }
        }

        foreach (ModuleRouteManifest::ANONYMOUS_BY_DESIGN as $key => $category) {
            $this->assertTrue(
                in_array($category, ['storefront', 'payment-callback', 'oauth', 'webhook'], true)
                    || preg_match('/^(own-credential|guarded|owner-exemption|inert):[A-Za-z0-9_.-]+$/', $category) === 1,
                "{$key} has an unknown category '{$category}'."
            );

            if (str_starts_with($category, 'guarded:')) {
                $class = 'App\\Http\\Middleware\\' . substr($category, strlen('guarded:'));

                $this->assertTrue(class_exists($class), "{$key}: {$class} does not exist.");
                $this->assertContains($class, $byKey[$key]['middleware'] ?? [], "{$key} is classified as owned by {$class} but the resolved route does not carry it.");
                $this->assertTrue(self::guardActsOn($class, $byKey[$key]), "{$key} is classified as owned by {$class}, which is on the route's group but has no rule for THIS route.");
            }
        }
    }

    /** Does the guard have a rule for this route? Each guard keys on something different; this reads the key from the guard itself. */
    private static function guardActsOn(string $class, array $row): bool
    {
        $const = fn (string $name) => (new \ReflectionClassConstant($class, $name))->getValue();

        return match ($class) {
            \App\Http\Middleware\OrderDocumentAccessGuard::class => array_key_exists($row['uri'], $const('TARGETS')),
            \App\Http\Middleware\Duo2FALoginGuard::class => str_ends_with((string) $row['action'], $const('ACTION_SUFFIX')),
            \App\Http\Middleware\ModuleStorefrontInputGuard::class => in_array($row['name'], $const('ROUTE_NAMES'), true),
            default => false,
        };
    }

    public function test_every_classification_still_names_a_real_module_route(): void
    {
        $routes = $this->moduleRoutes($this->census());
        $stale = [];

        foreach (array_keys(ModuleRouteManifest::ADMINISTRATIVE + ModuleRouteManifest::ANONYMOUS_BY_DESIGN) as $key) {
            if (! isset($routes[$key])) {
                $stale[] = $key;
            }
        }

        $this->assertSame([], $stale, 'Classified keys that are no longer module routes (remove them; a stale key pre-classifies a future route that reuses it).');
    }

    public function test_an_administrative_key_is_never_actually_public_by_its_own_module(): void
    {
        // a route the manifest calls administrative must not be one the module authenticated itself with something the census misses --
        // if it IS authenticated the entry is redundant but harmless; what must not happen is an administrative key on a route whose action
        // is one of the shopper-facing {storeSlug} endpoints.
        foreach (array_keys(ModuleRouteManifest::ADMINISTRATIVE) as $key) {
            $uri = explode(' ', $key, 2)[1];

            $this->assertStringStartsNotWith('{storeSlug', $uri, "{$key}: a slug-prefixed storefront route cannot require a merchant session.");
        }
    }

    /**
     * The guard is registered on the `web` group, so it only fires on a route that is IN that group. A module can declare a route in a group
     * without it (CartQuantityControl's `'middleware' => 'web,PlanModuleCheck:...'` is one string; ProductsPDF's product_pdf has none at all).
     * For every ADMINISTRATIVE key, the resolved route must really carry the guard - otherwise the classification is a promise nothing keeps.
     */
    public function test_the_guard_is_really_on_every_route_it_is_supposed_to_protect(): void
    {
        $guard = \App\Http\Middleware\ModuleAdministrativeRouteGuard::class;
        $byKey = [];

        foreach ($this->census()['routes'] as $row) {
            if ($row['module'] !== null) {
                $byKey[$row['method'] . ' ' . $row['uri']] = $row;
            }
        }

        $without = [];

        foreach (array_keys(ModuleRouteManifest::ADMINISTRATIVE) as $key) {
            if (! in_array($guard, $byKey[$key]['middleware'] ?? [], true)) {
                $without[] = $key;
            }
        }

        $this->assertSame([], $without, "These ADMINISTRATIVE routes do not carry ModuleAdministrativeRouteGuard (the module declared them outside the `web` group, or the guard is not registered), so their classification protects nothing:\n" . implode("\n", $without));
    }
}

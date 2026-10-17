<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * MEASUREMENT, NOT ASSERTION -- written FIRST, before the P01-SEC-2 fix was designed.
 *
 * The whole package rested on a claim that could not be settled by reading: that the
 * AutomaticOrderPrinting module's ['web']-only re-declaration of GET shippinglabel/pdf/{id}
 * REPLACES the authenticated host declaration at routes/web.php:1178, leaving the surviving
 * route anonymous. Two independent readings of the boot sequence disagreed about the winner,
 * because every module's own RouteServiceProvider re-registers the HOST routes/web.php through
 * the inherited static self::$alwaysLoadRoutesUsing before declaring its own routes.
 *
 * So this file measures the RESOLVED route table instead of reasoning about it.
 */
class OrderDocumentRouteResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly what production does: the row, then the provider's own loader. */
    private function enableAutomaticOrderPrinting(): void
    {
        AddOnManager::create([
            'module' => 'AutomaticOrderPrinting',
            'name' => 'Automatic Order Printing',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'automatic-order-printing',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AutomaticOrderPrinting']);
    }

    private function describe(string $label): void
    {
        $names = [
            'shippinglabel.pdf',
            'invoice.shippinglabel.pdf',
            'invoice.shippinglabel.printing',
            'invoice.preview',
        ];

        fwrite(STDERR, "\n===== {$label} =====\n");
        fwrite(STDERR, 'total routes: ' . count(Route::getRoutes()->getRoutes()) . "\n");

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route === null) {
                fwrite(STDERR, sprintf("  %-32s NOT REGISTERED\n", $name));
                continue;
            }

            fwrite(STDERR, sprintf(
                "  %-32s uri=%-44s action=%s\n      middleware=[%s]\n",
                $name,
                $route->uri(),
                $route->getActionName(),
                implode(', ', app('router')->gatherRouteMiddleware($route))
            ));
        }

        // The raw GET slot, which is what addToCollections keys and therefore what a
        // later identical declaration actually replaces.
        foreach (['shippinglabel/pdf/{id}', 'shippinglabel/{id?}/{template?}/{color?}'] as $uri) {
            $slot = Route::getRoutes()->getRoutesByMethod()['GET'][$uri] ?? null;
            fwrite(STDERR, sprintf(
                "  GET slot %-46s %s\n",
                $uri,
                $slot === null ? 'EMPTY' : $slot->getActionName()
            ));
        }
    }

    public function test_without_the_addon_the_host_declaration_is_authenticated(): void
    {
        $this->describe('WITHOUT AutomaticOrderPrinting (the state of the entire existing suite)');

        $route = Route::getRoutes()->getByName('shippinglabel.pdf');
        $this->assertNotNull($route, 'The host order-document route is gone.');

        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(
            \Illuminate\Auth\Middleware\Authenticate::class,
            $middleware,
            'routes/web.php:1178 is declared inside the authenticated admin group; without the '
            . 'addon loaded it must carry Authenticate.'
        );

        // The premise for the whole package: the addon contributes nothing until a row exists,
        // which is why no pre-existing test could see the defect.
        foreach (['invoice.shippinglabel.pdf', 'invoice.shippinglabel.printing', 'invoice.preview'] as $name) {
            $this->assertFalse(Route::has($name), "Addon route {$name} exists without the addon being enabled.");
        }
    }

    /**
     * THE DEFECT, PINNED. The addon re-declares the SAME uri with the SAME controller action as
     * the authenticated host route, inside a ['web']-only group, and is loaded afterwards -- so
     * the slot keeps the action and loses Authenticate. The action name is identical either way,
     * which is exactly why reading the diff was not enough to see it.
     *
     * If an upstream release ever gates that group, this test reddens -- and that is wanted: it
     * means the shadow is gone and the guard's reason for covering this uri should be re-read.
     */
    public function test_with_the_addon_enabled_the_shadowed_route_loses_authenticate(): void
    {
        $this->enableAutomaticOrderPrinting();
        $this->describe('WITH AutomaticOrderPrinting enabled through the production loader');

        $route = Route::getRoutes()->getByName('shippinglabel.pdf');
        $this->assertNotNull($route);

        $this->assertSame(
            'App\Http\Controllers\OrderController@shippinglabel',
            $route->getActionName(),
            'The action changed; the shadowing analysis needs re-doing.'
        );

        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertNotContains(
            \Illuminate\Auth\Middleware\Authenticate::class,
            $middleware,
            'The addon no longer strips Authenticate from this route. Good news, but it changes '
            . 'the premise of P01-SEC-2 -- re-read the guard docblock.'
        );

        // Which is precisely why the guard has to be the thing enforcing authentication.
        $this->assertContains(
            \App\Http\Middleware\OrderDocumentAccessGuard::class,
            $middleware,
            'The guard is absent from the stack of the one route that loses Authenticate.'
        );
    }

    /** All three addon-only routes appear, ungated, and all three carry the guard. */
    public function test_with_the_addon_enabled_the_three_addon_routes_are_ungated_but_guarded(): void
    {
        $this->enableAutomaticOrderPrinting();

        $expected = [
            'invoice.shippinglabel.pdf' => 'shippinglabel/{id?}/{template?}/{color?}',
            'invoice.shippinglabel.printing' => 'printlabel/{id?}/{template?}/{color?}',
            'invoice.preview' => 'invoices/preview/{template}/{color}',
        ];

        foreach ($expected as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Addon route {$name} did not register.");
            $this->assertSame($uri, $route->uri(), "Addon route {$name} changed uri -- the guard's target table must be updated.");

            $middleware = app('router')->gatherRouteMiddleware($route);

            $this->assertNotContains(
                \Illuminate\Auth\Middleware\Authenticate::class,
                $middleware,
                "{$name} is now authenticated upstream; re-read the guard docblock."
            );
            $this->assertContains(
                \App\Http\Middleware\OrderDocumentAccessGuard::class,
                $middleware,
                "{$name} does not carry the guard."
            );
        }
    }

    /**
     * CENSUS RATCHET -- the only check that works irrespective of module loading, boot order and
     * route caching, because it reads the route FILES rather than the resolved table.
     *
     * "No alternate unauthorized route remains reachable" cannot be a one-time claim: there are
     * 70 vendored modules and any upstream release may add another document route. So every
     * order-document uri declared anywhere in the route surface must be either a guard target or
     * an explicitly reasoned exemption. A new one is a failure, not a silent addition.
     *
     * THE PATTERN COVERS THE WHOLE DOCUMENT VOCABULARY THE BRIEF NAMES -- order details,
     * receipts, invoices, packing documents and shipping labels -- and not just the labels this
     * package started from, because the two module families that declare label routes in a
     * `['web']`-only group declare their order-view and order-receipt routes in the SAME group.
     * Scanning only for labels would have left those behind while reporting a clean sweep.
     */
    public function test_every_declared_order_document_route_is_either_guarded_or_explicitly_exempt(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class);
        $targets = array_keys($reflection->getConstant('TARGETS'));

        // Reasoned exemptions. Each must say WHY, because an unreasoned entry here is how an
        // exemption outlives its justification.
        $exempt = [
            // Settings screens for the print templates. Authenticated and PlanModuleCheck'd in
            // the addon's own ['auth','web','PlanModuleCheck'] group at its routes/web.php:18-23.
            'automaticorderprinting' => 'addon settings resource, already behind auth + PlanModuleCheck',
            'print-settings' => 'addon settings resource, already behind auth + PlanModuleCheck',
            'automatic-print-setting' => 'addon settings, already behind auth + PlanModuleCheck',
            'automatic-print-setting-setting-form' => 'addon settings, already behind auth + PlanModuleCheck',
            'shipping-label-setting' => 'host AppSettingController, inside the authenticated admin group',
        ];

        $files = array_merge(
            glob(base_path('routes/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/routes/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/Routes/*.php')) ?: []
        );

        $this->assertGreaterThan(60, count($files), 'The route-file scan found too few files to be meaningful.');

        $found = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            // Whole-line comments are skipped the way the repo's own RouteFileParser does.
            foreach (preg_split('/\R/', $contents) as $lineNo => $line) {
                if (preg_match('/^\s*(\/\/|\*|#)/', $line) === 1) {
                    continue;
                }

                if (preg_match_all(
                    '/Route::(?:get|any|match|post)\s*\(\s*[\'"]([^\'"]*(?:shippinglabel|printlabel|print-label'
                        . '|order-view|order_view|order-receipt|order_receipt|invoice|packing)[^\'"]*)[\'"]/i',
                    $line,
                    $m
                ) === 0) {
                    continue;
                }

                foreach ($m[1] as $uri) {
                    $found[ltrim($uri, '/')] = str_replace(base_path() . '/', '', $file) . ':' . ($lineNo + 1);
                }
            }
        }

        $this->assertNotEmpty($found, 'The scanner found no document routes at all, so it would pass vacuously.');

        // A FLOOR, so a pattern edit that quietly stops matching cannot pass this test. 13 uris
        // match today; the scan must never see fewer than the 12 that are guard targets.
        $this->assertGreaterThanOrEqual(12, count($found),
            'The document-route scan matched fewer uris than there are guard targets, so the '
            . "pattern has stopped seeing routes it used to see:\n  " . implode("\n  ", array_keys($found)));

        $unguarded = [];

        foreach ($found as $uri => $where) {
            if (in_array($uri, $targets, true)) {
                continue;
            }

            if (array_key_exists($uri, $exempt)) {
                continue;
            }

            $unguarded[$uri] = $where;
        }

        $this->assertSame(
            [],
            $unguarded,
            "An order-document route is declared that App\\Http\\Middleware\\OrderDocumentAccessGuard "
            . "does not target and that carries no reasoned exemption:\n"
            . implode("\n", array_map(
                fn ($where, $uri) => "  {$uri}  ({$where})",
                $unguarded,
                array_keys($unguarded)
            ))
            . "\n\nAdd it to the guard's TARGETS, or to this test's \$exempt list with the reason "
            . 'it is already protected.'
        );
    }

    /**
     * TRIPWIRE. The guard matches on verb + uri, and nine of its thirteen target uris are
     * module-owned strings in code CI forbids editing. An upstream rename would silently
     * unhook the guard, so the uris are pinned here against the route table itself.
     */
    public function test_every_guard_target_uri_still_exists_in_the_route_table(): void
    {
        $this->enableAutomaticOrderPrinting();

        $reflection = new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class);
        $targets = $reflection->getConstant('TARGETS');

        $this->assertNotEmpty($targets, 'The guard has no targets.');

        $registered = array_keys(Route::getRoutes()->getRoutesByMethod()['GET'] ?? []);

        // The sibling-module uris only exist when THOSE modules are enabled, which this test
        // does not do; they are excluded BY NAME rather than silently skipped, so the list below
        // is itself a statement of which targets this tripwire does not cover. Each is pinned
        // instead by the census above, which reads the route FILES and so needs no module.
        $moduleOnly = [
            'auction/shippinglabel/pdf/{id}',
            'auction-order-view/{id}',
            'auction-order-receipt/{id}',
            'lottery/shippinglabel/pdf/{id}',
            'lottery-order-view/{id}',
            'lottery-order-receipt/{id}',
        ];

        // Every excluded uri must still be a real declaration somewhere, or the exclusion is
        // hiding a rename rather than a module boundary.
        $declared = implode("\n", array_map(
            fn ($f) => (string) file_get_contents($f),
            glob(base_path('packages/workdo/*/src/routes/*.php')) ?: []
        ));

        foreach ($moduleOnly as $uri) {
            $this->assertStringContainsString(
                str_replace('/{id}', '', $uri),
                $declared,
                "The guard targets '{$uri}' and this tripwire excuses it as module-only, but no "
                . 'module route file declares it any more.'
            );
        }

        foreach (array_keys($targets) as $uri) {
            if (in_array($uri, $moduleOnly, true)) {
                continue;
            }

            $this->assertContains(
                $uri,
                $registered,
                "The guard targets uri '{$uri}' but no GET route declares it any more. An upstream "
                . 'rename has unhooked the guard from a live document route.'
            );
        }
    }
}

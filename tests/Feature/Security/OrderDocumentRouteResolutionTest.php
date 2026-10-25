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
            // Produced by the widened regex above. Both are stub resource `show()` methods that
            // ignore their id -- see the handler census below, which verifies that claim and
            // asserts its own exemptions are not dead.
            'print-settings' => 'addon settings resource, stub show(), authenticated + PlanModuleCheck',
            'automatic-print-setting' => 'addon settings, already behind auth + PlanModuleCheck',
        ];

        // THE EXEMPTIONS MUST BE REACHABLE. The first version of this test carried five entries
        // that the regex could never produce, so they looked like controls and were not. Each
        // key below must still be produced by the scan.
        $exemptMustAppear = ['shipping-label-setting', 'print-settings'];

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

                // `Route::resource` is included because the repo registers document controllers
                // that way (routes/web.php:1051, :1274), and `shipping-label` / `print-settings`
                // because those are the repo's OWN spellings -- without them the $exempt list
                // below was dead code that could never fire, which is what the first version of
                // this test shipped with.
                if (preg_match_all(
                    '/Route::(?:get|any|match|post|put|patch|delete|resource)\s*\(\s*[\'"]([^\'"]*(?:shippinglabel'
                        . '|printlabel|print-label|shipping-label|print-settings|printing'
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

        foreach ($exemptMustAppear as $uri) {
            $this->assertArrayHasKey(
                $uri,
                $found,
                "The exemption for '{$uri}' is dead: the scan no longer produces that uri, so the "
                . 'entry is documentation pretending to be a control. Fix the regex or delete it.'
            );
        }
    }

    /**
     * THE HANDLER CENSUS -- the ratchet that the uri census above could not be.
     *
     * WHY THIS EXISTS, AND IT IS NOT A NICE-TO-HAVE. An adversarial audit of this guard AFTER its
     * first commit found four order-document routes it did not cover, and the uri census could
     * not have found any of them: `{storeSlug?}/customerorder/{id}`,
     * `{storeSlug?}/order-refund/{id}`, `refund-request/{refund_request}` and `order/{order}`
     * contain none of the words it scans for. A census keyed on how a uri is SPELLED cannot see a
     * document route whose spelling nobody thought of -- which is the only kind that matters.
     *
     * So this one is keyed on the HANDLER. A route is an order-document route if its action is a
     * method that renders an order, whatever its uri says. Every such route must be a guard
     * target or carry a reason here. Both censuses are kept: this one catches an unexpected uri
     * on a known handler, the uri one catches a known-looking uri on an unexpected handler.
     *
     * STILL NOT A PROOF OF COMPLETENESS, and the limit is stated so nobody reads it as one: a
     * NEW handler method name is invisible to this, exactly as a new uri word is invisible to the
     * other. What it does guarantee is that no route can be added to an EXISTING order-document
     * handler without someone answering for it here.
     */
    public function test_every_route_reaching_an_order_document_handler_is_guarded(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class);
        $targets = array_keys($reflection->getConstant('TARGETS'));

        // Methods that render an order. `show` is deliberately NOT here: it is far too common a
        // name, so it is matched below only on a controller whose name contains "Order".
        $documentMethods = [
            'shippinglabel', 'printinglabel', 'invoice_preview', 'order_view', 'order_receipt',
            'customerorder', 'order_refund', 'orderdetails', 'orderDetails', 'customerpayment',
            'order_detail', 'orderDetail',
            // P2-04-5. Added WITH the handler, not after it. This list is a spelling, and a
            // census keyed on a spelling misses whatever was not spelled -- four times in this
            // project already (see the skill's section 7). A new order-document method name
            // belongs here in the same commit that introduces it, or the census passes
            // vacuously over it.
            'order_preview',
        ];

        // Reasoned exemptions. Each says WHY, and each reason was VERIFIED by reading the handler
        // -- not inferred from the route's group.
        $exempt = [
            // THE API SURFACE. Out of scope for this package by instruction, not by judgement:
            // these are the Flutter app's endpoints and the brief forbids changing the API
            // contract. They are in the `api` group, so this guard could not reach them anyway
            // without being registered there. BOTH WERE PROVEN TO LEAK CROSS-TENANT ORDER
            // DOCUMENTS and are escalated as S12 in docs/security/P01-SECURITY-WORK-QUEUE.md.
            'order-detail' => 'api group, DashboardController@orderDetail -- LEAKS, escalated as S12',
            '{slug}/order-detail' => 'api group, ApiController@order_detail -- LEAKS, escalated as S12',

            // ANONYMOUS BY DESIGN, and the one target this package will not decide alone.
            // PartialPayments' payment page is reached by a customer from a payment link; gating
            // it on a signed-in owning shopper would break paying by link. It DOES leak another
            // tenant's order document to an anonymous caller. Owner decision, S12.
            '{storeSlug?}/customerpayment/{id}' => 'anonymous payment-by-link flow -- LEAKS, owner decision, S12',

            // STOREFRONT ORDER PAGES THAT DO SCOPE THEMSELVES. Both filter the order on the
            // slug's store and abort(404)/redirect otherwise, so neither is cross-tenant. They
            // are readable by anyone holding the emailed link, which is the designed flow and the
            // same owner decision as the storefront print button.
            '{storeSlug?}/order/{id}' => 'OrderController@orderDetails filters on store_id and abort(404)s',
            '{storeSlug}/auction-order/{id}' => 'AuctionOrdersController@orderdetails filters on store_id and abort(404)s',

            // WORKDO SCAFFOLDING STUBS. Each of these resource `show()` methods is
            // `return view('<module>::show');` -- it ignores its id entirely and renders an empty
            // stub. No order data is reachable through any of them. Verified by reading each one.
            'auctionorder/{auctionorder}' => 'stub: returns view(auction-product::auctionorder.show), ignores $id',
            'lotteryorder/{lotteryorder}' => 'stub: returns view(lottery-prizes::show), ignores $id',
            'recentorders/{recentorders}' => 'stub: returns view(recent-orders::show), ignores $id',
            'preorder/{preorder}' => 'stub: returns view(pre-order::show), ignores $id',
            'preordersetting/{preordersetting}' => 'stub: returns view(pre-order::show), ignores $id',
            'automaticorderprinting/{automaticorderprinting}' => 'stub: returns view(automatic-order-printing::show), ignores $id',
            'print-settings/{print_settings}' => 'stub: same AutomaticOrderPrintingController@show',

            // NO SUCH METHOD. Route::resource('order-note') registers a show route but
            // App\Http\Controllers\OrderNoteController has no show() at all, so the route cannot
            // render anything. Listed rather than silently dropped, because a future upstream
            // addition of show() must land here as a failure.
            'order-note/{order_note}' => 'OrderNoteController has no show() method',
        ];

        $files = array_merge(
            glob(base_path('routes/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/routes/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/Routes/*.php')) ?: []
        );

        $this->assertGreaterThan(60, count($files), 'The route-file scan found too few files to be meaningful.');

        $found = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $lineNo => $line) {
                if (preg_match('/^\s*(\/\/|\*|#)/', $line) === 1) {
                    continue;
                }

                // Route::verb('uri', [Controller::class, 'method'])
                if (preg_match_all(
                    '/Route::(?:get|any|match|post|put|patch|delete)\s*\(\s*(?:\[[^\]]*\]\s*,\s*)?[\'"]([^\'"]*)[\'"]\s*,\s*\[\s*([A-Za-z_\\\\]+)::class\s*,\s*[\'"]([A-Za-z_]+)[\'"]/',
                    $line,
                    $m,
                    PREG_SET_ORDER
                ) !== 0) {
                    foreach ($m as $set) {
                        [, $uri, $controller, $method] = $set;
                        $short = (string) (explode('\\', $controller)[count(explode('\\', $controller)) - 1] ?? '');

                        if (in_array($method, $documentMethods, true)
                            || ($method === 'show' && str_contains($short, 'Order'))) {
                            $found[ltrim($uri, '/')] = $relative . ':' . ($lineNo + 1)
                                . '  ' . $short . '@' . $method;
                        }
                    }
                }

                // Route::resource('name', OrderSomethingController::class) -- the generated show
                // route is `name/{name_with_dashes_as_underscores}`, which is how Laravel names
                // the parameter and is NOT what a naive singularisation would produce.
                if (preg_match_all(
                    '/Route::resource\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([A-Za-z_\\\\]+)::class/',
                    $line,
                    $rm,
                    PREG_SET_ORDER
                ) !== 0) {
                    foreach ($rm as $set) {
                        [, $name, $controller] = $set;
                        $parts = explode('\\', $controller);
                        $short = (string) end($parts);

                        if (! str_contains($short, 'Order')) {
                            continue;
                        }

                        $segments = explode('/', $name);
                        $param = str_replace('-', '_', (string) end($segments));
                        $found[$name . '/{' . $param . '}'] = $relative . ':' . ($lineNo + 1)
                            . '  ' . $short . '@show (resource)';
                    }
                }
            }
        }

        $this->assertNotEmpty($found, 'The handler scan found nothing, so it would pass vacuously.');

        // A FLOOR. 17 of the routes this finds are guard targets; if the scan ever sees fewer
        // uris than there are targets, the patterns have stopped matching what they used to.
        $this->assertGreaterThanOrEqual(
            count($targets),
            count($found),
            "The handler scan matched fewer uris than there are guard targets:\n  "
            . implode("\n  ", array_keys($found))
        );

        $unguarded = [];

        foreach ($found as $uri => $where) {
            if (in_array($uri, $targets, true) || array_key_exists($uri, $exempt)) {
                continue;
            }

            $unguarded[$uri] = $where;
        }

        $this->assertSame(
            [],
            $unguarded,
            "A route reaches an order-document handler but is neither a guard target nor "
            . "explicitly exempt:\n"
            . implode("\n", array_map(
                fn ($where, $uri) => "  {$uri}\n      {$where}",
                $unguarded,
                array_keys($unguarded)
            ))
            . "\n\nAdd it to App\\Http\\Middleware\\OrderDocumentAccessGuard::TARGETS, or to this "
            . "test's \$exempt list with the VERIFIED reason it is safe. Do not exempt it because "
            . 'of the group it sits in -- read the handler.'
        );

        // Every exemption must still correspond to a route this scan finds, or it is dead code
        // pretending to be a control -- which is exactly what the first version of the uri
        // census shipped with.
        foreach (array_keys($exempt) as $uri) {
            $this->assertArrayHasKey(
                $uri,
                $found,
                "The exemption for '{$uri}' is dead: no route reaching an order-document handler "
                . 'declares that uri any more. Delete the entry, or fix the scan.'
            );
        }
    }

    /**
     * NO ALIAS ROUTE REACHES A GUARDED ACTION BY A DIFFERENT URI.
     *
     * The guard matches on uri, so guarding one uri does NOT guard another that reaches the same
     * controller action -- a fact this project learned the hard way: `order/{order}` from
     * Route::resource('order') reached OrderController@show, already guarded under
     * `orders/order_view/{id}`, and was wide open. That one is now a target; this test is the
     * general form, so the next one is a failure rather than a discovery.
     *
     * Dynamic on purpose, and complementary to the two file-scanning censuses above: it asks the
     * RESOLVED route table which actions the targets reach, then asks whether any other route
     * reaches one of those same actions without the guard. A uri nobody thought to scan for is
     * invisible to a text scan but not to this.
     */
    public function test_no_alias_route_reaches_a_guarded_action_without_the_guard(): void
    {
        $this->enableAutomaticOrderPrinting();

        $targets = (new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class))
            ->getConstant('TARGETS');

        $router = app('router');

        // Which controller actions are reached by a GUARDED uri.
        $guardedActions = [];

        foreach ($router->getRoutes() as $route) {
            if (array_key_exists($route->uri(), $targets)) {
                $guardedActions[$route->getActionName()] = $route->uri();
            }
        }

        $this->assertNotEmpty($guardedActions,
            'No guard target resolves to a route, so this test would pass vacuously.');

        // Any OTHER route reaching one of them, without the guard, is an alias bypass.
        $aliases = [];

        foreach ($router->getRoutes() as $route) {
            $action = $route->getActionName();

            if (! array_key_exists($action, $guardedActions)) {
                continue;
            }

            if (array_key_exists($route->uri(), $targets)) {
                continue;
            }

            // NO MIDDLEWARE-MEMBERSHIP EXEMPTION HERE, and its absence is the point. The guard is
            // appended to the `web` GROUP, so gatherRouteMiddleware() lists it for every web
            // route on the site whether or not it arms for that route -- handle() returns early
            // unless the matched uri is in TARGETS. An earlier version of this test skipped
            // routes whose stack "contained" the guard, which exempted every web route and made
            // the whole check vacuous: deleting `order/{order}` from TARGETS left it green.
            // Caught by sabotaging TARGETS and watching nothing fail. Membership proves nothing;
            // being a TARGET is the only thing that does.
            $aliases[] = implode(',', $route->methods()) . ' ' . $route->uri()
                . '  ->  ' . $action . '  (also reached by the guarded ' . $guardedActions[$action] . ')';
        }

        $this->assertSame([], $aliases,
            "A route reaches an order-document action that the guard protects under a DIFFERENT "
            . "uri, without carrying the guard:\n  " . implode("\n  ", $aliases)
            . "\n\nAdd its uri to TARGETS. Guarding one uri does not guard another that reaches "
            . 'the same action.');
    }

    /**
     * TRIPWIRE. The guard matches on verb + uri, and nine of its seventeen target uris are
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
            // P1-OWN-05(a): PartialPayments' settle-a-balance page, declared at
            // packages/workdo/PartialPayments/src/routes/web.php:27. Module-only for the same
            // reason as the six above, and the tripwire below still proves it is really declared.
            '{storeSlug?}/customerpayment/{id}',
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

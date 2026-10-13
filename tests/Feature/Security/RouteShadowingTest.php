<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ROUTE SHADOWING -- the defect class where a later declaration sharing a method and a
 * URI silently replaces an earlier one.
 *
 * Illuminate\Routing\RouteCollection::addToCollections keys $routes[$method] by
 * domain+URI, and refreshNameLookups() rebuilds the name table from that index, so two
 * declarations of one method+URI leave ONE route in the matcher with NO error, NO warning
 * and NO startup failure. php -l cannot see it, a name-based grep cannot see it, and
 * reading either declaration in isolation cannot see it.
 *
 * It has produced four distinct defects in this codebase:
 *   - `order-view/{id}` was declared inside the administrative `auth` group AND in a
 *     public block; the UNAUTHENTICATED copy survived
 *     (UnauthenticatedRouteBoundaryTest::test_order_view_is_registered_exactly_once...).
 *   - `profile.edit` was lost, so every successful merchant profile save wrote the record
 *     and then 500ed (NEW-ROUTE-SHADOW-01).
 *   - `contact.us` was lost latently (NEW-ROUTE-SHADOW-02).
 *   - and the one found this phase: an inline closure in routes/web.php took the POST slot
 *     of `api/delivery-syria/orders/update-status` away from
 *     Api\DeliverySyriaOrderController, disabling a per-store webhook credential check and
 *     leaving an anonymous GET that pushed a caller-chosen status to Zid using the
 *     merchant's own decrypted credentials (NEW-DS-ROUTE-SHADOW-01).
 *
 * So this suite does two things. It pins the three repairs, each with the positive
 * direction as well as the denial. And it adds a PROPERTY test that detects the class
 * itself against the booted router, which is the only place the collision is visible.
 */
class RouteShadowingTest extends TestCase
{
    /**
     * Shadowed method+URI pairs that are known, inspected and accepted, each with the
     * evidence for why it is not a defect. Anything NOT in here fails the property test.
     *
     * Format: "METHOD uri" => reason.
     */
    private const ACCEPTED_SHADOWS = [
        // ESewaPaymentController::failure() is unreachable: routes/web.php:662 declares
        // POST /plan-pay-with-esewa for it and :663 re-declares the same URI with
        // Route::any for success(), which wins every verb.
        //
        // NOT A DEFECT IN ITS OWN RIGHT, and deliberately left alone rather than
        // "tidied": success() branches on $request->status (:65) and already answers a
        // failed payment with `redirect()->route('plan.index')->with('error', 'Your
        // Payment has failed!')` (:122-124), which is what failure() (:129-131) does. The
        // two URLs the controller hands the gateway differ only in the query string --
        // route('plan.esewa.payment.success', [... 'status' => 'success']) at :46 and
        // route('plan.esewa.payment.failure', ['status' => 'faild']) at :47 -- and both
        // resolve to /plan-pay-with-esewa, so the shadow changes no outcome. Giving
        // failure() its own URI would change the callback URL this installation hands
        // eSewa, which is a payment-integration change and not a route repair.
        //
        // A SEPARATE AND REAL FINDING WAS FOUND HERE AND IS REPORTED, NOT FIXED:
        // success() trusts $request->status, $request->amount and $request->plan_id with
        // no verification against eSewa and no signature, and the route sits in the plain
        // ['auth','xss','setlocate','verified'] group, so an authenticated merchant can
        // reach $user->assignPlan() (:118) by replaying the success URL that appears in
        // the page source. The repair is inside ESewaPaymentController, which this agent
        // does not own; recorded as a cross-agent dependency.
        'POST plan-pay-with-esewa' => 'ESewaPaymentController@failure, benign -- see comment',
    ];

    /**
     * THE PROPERTY TEST. Walks the booted router and reports every declaration that lost
     * its method+URI slot to a later one.
     *
     * $allRoutes keeps one entry per route keyed by its LAST method plus the URI, while
     * $routes[$method] keeps the winner per verb, so a route that is in the iteration but
     * is not the winner for one of its own verbs is a shadowed declaration.
     *
     * WHAT THIS SEES AND WHAT IT CANNOT, stated so the test is not read as more than it
     * is. It sees a collision between declarations whose METHOD SETS DIFFER --
     * Route::post against Route::any (the eSewa pair below), Route::match(['GET','POST'])
     * against Route::post (NEW-DS-ROUTE-SHADOW-01) -- because the loser still has its own
     * $allRoutes key and so still appears in the iteration. It does NOT see a collision
     * between two declarations with the SAME method set, because addToCollections keys
     * $allRoutes by the last method plus the URI too, so the loser is overwritten there as
     * well and vanishes from the iteration entirely. MEASURED, not assumed: reintroducing
     * the `GET /profile` collision of NEW-ROUTE-SHADOW-01 does not trip this test -- it
     * trips test_every_route_name_declared_in_routes_is_registered, which reported
     * `[profile.edit] => web.php:436`.
     *
     * So the same-verb case is covered by two other tests here, from both sides:
     * the name scan catches it whenever the two declarations carry different names (which
     * is what was lost in both NEW-ROUTE-SHADOW-01 and -02), and
     * test_no_duplicate_method_and_uri_is_declared_in_the_route_files catches it
     * statically even when the names are identical, which is the one shape neither
     * runtime check can see.
     */
    public function test_no_route_declaration_is_shadowed_by_a_later_one(): void
    {
        $collection = Route::getRoutes();

        $shadowed = [];

        foreach ($collection as $route) {
            $key = $route->getDomain() . $route->uri();

            foreach ($route->methods() as $method) {
                $winners = $collection->get($method);

                if (!isset($winners[$key]) || $winners[$key] === $route) {
                    continue;
                }

                $winner = $winners[$key];

                $pair = $method . ' ' . $route->uri();

                if (array_key_exists($pair, self::ACCEPTED_SHADOWS)) {
                    continue;
                }

                $shadowed[$pair] = sprintf(
                    '%s %s -- the declaration named "%s" (%s) is dead; "%s" (%s) wins',
                    $method,
                    $route->uri(),
                    (string) $route->getName(),
                    $this->describeAction($route),
                    (string) $winner->getName(),
                    $this->describeAction($winner)
                );
            }
        }

        $this->assertSame(
            [],
            array_values($shadowed),
            "A route declaration is silently shadowed by a later one sharing its method "
            . "and URI. Laravel reports nothing: the earlier action becomes unreachable "
            . "and its name can disappear from the registry. Fix the declaration or, if "
            . "it is genuinely harmless, add it to ACCEPTED_SHADOWS with the evidence:\n  "
            . implode("\n  ", $shadowed)
        );
    }

    private function describeAction(\Illuminate\Routing\Route $route): string
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof \Closure) {
            $reflection = new \ReflectionFunction($uses);

            return sprintf(
                'Closure at %s:%d',
                str_replace(base_path() . '/', '', (string) $reflection->getFileName()),
                (int) $reflection->getStartLine()
            );
        }

        return is_string($uses) ? $uses : $route->getActionName();
    }

    /**
     * Every name written in routes/ must survive registration, measured with comments
     * stripped so a commented-out declaration (routes/web.php has one) is not counted.
     *
     * This duplicates tests/Feature/Platform/RouteRegistrationIntegrityTest on purpose:
     * that suite belongs to another workstream, and the two repairs below are made in
     * routes/, so the guard has to live beside the thing it guards as well.
     */
    public function test_every_route_name_declared_in_routes_is_registered(): void
    {
        $declared = [];

        foreach (glob(base_path('routes') . '/*.php') ?: [] as $path) {
            $stripped = '';

            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token)) {
                    $stripped .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
                        ? str_repeat("\n", substr_count($token[1], "\n"))
                        : $token[1];
                    continue;
                }

                $stripped .= $token;
            }

            foreach (explode("\n", $stripped) as $i => $line) {
                if (preg_match_all('/->name\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $m)) {
                    foreach ($m[1] as $name) {
                        $declared[$name][] = basename($path) . ':' . ($i + 1);
                    }
                }
            }
        }

        $this->assertNotEmpty($declared, 'The scanner extracted no names, so this would pass vacuously.');

        $lost = [];

        foreach ($declared as $name => $sites) {
            if (!Route::has($name)) {
                $lost[$name] = implode(', ', $sites);
            }
        }

        $this->assertSame([], $lost, "Declared route names missing from the registry: " . print_r($lost, true));
    }

    /**
     * NEW-ROUTE-SHADOW-01, repaired. BOTH directions:
     * the lost name is back AND the product's own profile screen still owns /profile.
     */
    public function test_profile_edit_is_registered_without_taking_the_product_profile_page(): void
    {
        $this->assertTrue(
            Route::has('profile.edit'),
            'ProfileController::update() ends in Redirect::route(\'profile.edit\') '
            . '(app/Http/Controllers/ProfileController.php:37), so losing this name makes '
            . 'every successful profile save write the record and then 500.'
        );

        $edit = Route::getRoutes()->getByName('profile.edit');

        $this->assertSame('profile/edit', $edit->uri());
        $this->assertStringContainsString('ProfileController@edit', $edit->getActionName());

        // THE POSITIVE DIRECTION: /profile is unchanged. It still serves the WorkDo
        // profile screen under the name `profile`, which partision/header.blade.php:46
        // links to and five GoogleAuthenticationController redirects target.
        $byMethod = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'profile') {
                continue;
            }

            foreach ($route->methods() as $method) {
                $byMethod[$method] = $route;
            }
        }

        $this->assertArrayHasKey('GET', $byMethod, 'GET /profile disappeared.');
        $this->assertSame('profile', $byMethod['GET']->getName());
        $this->assertStringContainsString(
            'UserController@profile',
            $byMethod['GET']->getActionName(),
            'GET /profile must keep serving the profile screen this product ships. '
            . 'Handing that URI to the Breeze screen instead would change which page a '
            . 'merchant sees, which is a product change and not a defect repair.'
        );

        $this->assertStringContainsString('ProfileController@update', $byMethod['PATCH']->getActionName());
        $this->assertStringContainsString('ProfileController@destroy', $byMethod['DELETE']->getActionName());

        $this->assertSame(url('/profile'), route('profile'));
        $this->assertSame(url('/profile/edit'), route('profile.edit'));
    }

    /**
     * NEW-ROUTE-SHADOW-02, repaired. The live name is the one that survived, and the
     * contact page itself is untouched.
     */
    public function test_the_storefront_contact_page_keeps_the_name_the_views_read(): void
    {
        $this->assertTrue(
            Route::has('page.contact_us'),
            'resources/views/front_end/jQueryRoute.blade.php:333 compares '
            . 'Request::route()->getName() against this literal.'
        );

        $route = Route::getRoutes()->getByName('page.contact_us');

        $this->assertSame('{storeSlug?}/contact-us', $route->uri());
        $this->assertStringContainsString('HomeController@contactUs', $route->getActionName());
        $this->assertNotContains(
            'auth',
            $route->gatherMiddleware(),
            'The storefront contact page is anonymous by design.'
        );

        $this->assertFalse(
            Route::has('contact.us'),
            'The duplicate declaration was removed, not re-homed: re-adding the name '
            . 'would need a second public URL for a page that already has one.'
        );
    }

    /**
     * NEW-DS-ROUTE-SHADOW-01, repaired. The hardened controller now answers, and the
     * anonymous GET is gone.
     */
    public function test_the_delivery_syria_status_endpoint_is_served_by_its_hardened_controller(): void
    {
        $uri = '/api/delivery-syria/orders/update-status';

        // The route table: exactly one POST declaration, and it is the controller.
        $winners = Route::getRoutes()->get('POST');

        $this->assertArrayHasKey('api/delivery-syria/orders/update-status', $winners);
        $this->assertStringContainsString(
            'Api\DeliverySyriaOrderController@updateStatus',
            $winners['api/delivery-syria/orders/update-status']->getActionName(),
            'An inline closure in routes/web.php used to win this slot, which made the '
            . 'per-store webhook secret check dead code.'
        );

        // DENIAL 1: no platform header. This is the reply the closure made impossible.
        $this->postJson($uri, [])
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid platform.']);

        // DENIAL 2: correct platform, no credential. 403, never 401 -- repo B's
        // lib/network/network.dart turns any 401 under /api into a forced logout.
        $this->postJson($uri, [], ['X-Platform' => 'deliverysyria'])
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid credentials.']);

        // DENIAL 3: the credential gate precedes validation, so an anonymous caller
        // cannot use a 422 to learn which payloads are well formed.
        $this->postJson($uri, ['product_order_id' => 'ZID-1'], ['X-Platform' => 'deliverysyria'])
            ->assertStatus(403);

        // DENIAL 4: the anonymous GET state-change is gone. It used to reach the
        // `zid_orders` lookup with no credential at all.
        $this->getJson($uri . '?product_order_id=ZID-1&status=1')->assertStatus(405);
    }

    /**
     * NEW-ZID-TOKEN-DUMP-01, repaired, plus the property that makes instance three
     * impossible to add quietly.
     */
    public function test_no_unauthenticated_route_dumps_decrypted_credentials(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'show-token',
                $route->uri(),
                'GET /zid/show-token decrypted a merchant\'s Zid bearer and manager token '
                . 'and returned them as text/plain, gated only by equality against a '
                . 'literal committed in routes/web.php.'
            );
        }

        // THE PROPERTY, not just the instance: no closure route outside `auth` may
        // contain Crypt::decryptString. This is what the URI-substring assertion in
        // UnauthenticatedRouteBoundaryTest::test_zid_statuses_test_route_no_longer_exists
        // could not catch -- that test matched 'zid-statuses-test' and stayed green while
        // /zid/show-token, the same closure shape at a different URI, was live.
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $uses = $route->getAction('uses');

            if (!$uses instanceof \Closure) {
                continue;
            }

            if (in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            $reflection = new \ReflectionFunction($uses);
            $file = (string) $reflection->getFileName();

            if ($file === '' || !is_file($file)) {
                continue;
            }

            $source = implode('', array_slice(
                file($file) ?: [],
                (int) $reflection->getStartLine() - 1,
                (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1
            ));

            if (str_contains($source, 'decryptString') || str_contains($source, 'decrypt(')) {
                $offenders[] = sprintf(
                    '%s %s (%s:%d)',
                    implode('|', $route->methods()),
                    $route->uri(),
                    str_replace(base_path() . '/', '', $file),
                    (int) $reflection->getStartLine()
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An unauthenticated closure route decrypts stored credentials:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * THE SAME-VERB CASE, which no runtime check can reach: two declarations of one verb
     * and one URI leave only the later one anywhere in the router, so the earlier action
     * is unreachable with nothing to observe. That is the `order-view/{id}` shape, where
     * the surviving copy was the UNAUTHENTICATED one.
     *
     * So it is scanned statically out of the route FILES, with comments stripped first
     * (routes/web.php contains a commented-out declaration, and a raw regex counts it).
     * Every duplicate is inventoried below with the evidence for why it is harmless; a
     * NEW one fails this test.
     *
     * The scan compares the verb and the URI LITERAL as written, so it deliberately
     * over-reports: two declarations of one literal inside different Route::prefix()
     * groups do not collide at all. Those are in the inventory marked as such. Being
     * over-sensitive is the right failure mode here -- a false positive costs one comment,
     * a false negative costs an unreachable security gate.
     */
    public function test_no_duplicate_method_and_uri_is_declared_in_the_route_files(): void
    {
        $accepted = [
            // DIFFERENT URIs, not a collision: api.php:47 is `api/adminlogin` and :134 is
            // inside Route::prefix('admin')->as('admin.'), i.e. `api/admin/adminlogin`.
            'post adminlogin' => 'api.php:47 is api/adminlogin, api.php:134 is api/admin/adminlogin',
            // IDENTICAL declarations -- same controller action, same middleware, no name.
            // The later one wins and nothing is lost; copy-paste, not a shadow.
            'post updateStoreLanguage' => 'identical declaration repeated (api.php:217, :283)',
            'get getAvailableLanguages' => 'identical declaration repeated (api.php:218, :286)',
            // api.php vs web.php: the api group carries the `api` prefix, so
            // `api/product-brand-status` and `product-brand-status` are different URIs.
            'get product-brand-status' => 'api.php:370 is api-prefixed, web.php:1061 is not',
            'get product-brand-popular' => 'api.php:371 is api-prefixed, web.php:1062 is not',
            'get product-label-status' => 'api.php:377 is api-prefixed, web.php:1066 is not',
            // IDENTICAL declarations, same action AND same name, inside one group.
            'post products/attribute_combination' => 'identical declaration repeated (web.php:810, :827)',
            'post abandon-wish-emailsend' => 'identical declaration repeated (web.php:931, :949)',
        ];

        $seen = [];

        foreach (glob(base_path('routes') . '/*.php') ?: [] as $path) {
            $stripped = '';

            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token)) {
                    $stripped .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
                        ? str_repeat("\n", substr_count($token[1], "\n"))
                        : $token[1];
                    continue;
                }

                $stripped .= $token;
            }

            foreach (explode("\n", $stripped) as $i => $line) {
                $matched = preg_match(
                    '/Route::(get|post|put|patch|delete|any|match|options)\s*\(\s*(\[[^\]]*\]\s*,\s*)?[\x27"]([^\x27"]*)[\x27"]/',
                    $line,
                    $m
                );

                if (!$matched) {
                    continue;
                }

                $key = strtolower($m[1]) . ' ' . trim($m[3], '/');
                $seen[$key][] = basename($path) . ':' . ($i + 1);
            }
        }

        $this->assertNotEmpty($seen, 'The scanner extracted no declarations, so this would pass vacuously.');

        $duplicates = [];

        foreach ($seen as $key => $sites) {
            if (count($sites) < 2 || array_key_exists($key, $accepted)) {
                continue;
            }

            $duplicates[$key] = implode(', ', $sites);
        }

        $this->assertSame(
            [],
            $duplicates,
            "One verb and one URI are declared twice in routes/. Only the later "
            . "declaration survives registration, with no error -- which is how an "
            . "authenticated `order-view/{id}` lost its slot to an unauthenticated copy. "
            . "Remove one, or add it to the inventory in this test with the evidence that "
            . "nothing is lost:\n  "
            . print_r($duplicates, true)
        );
    }

    /**
     * Route closures are invisible to UnauthenticatedRouteBoundaryTest, which sweeps by
     * CONTROLLER class and skips any action that is not "Class@method" -- that is exactly
     * how three unauthenticated closures survived every previous sweep in this file's
     * history. So the closures are inventoried explicitly: a new one, or an existing one
     * losing its gate, fails here.
     */
    public function test_the_route_closure_inventory_is_complete_and_gated(): void
    {
        $expected = [
            // Laravel's own health endpoint, declared by the framework in
            // ApplicationBuilder, intentionally public.
            'GET|HEAD up' => [],
            // Settings -> Cache "Clear Cache" button. Any authenticated user can run it
            // and Artisan::call('optimize') is process-global on a shared-database SaaS,
            // which is REPORTED as a finding rather than changed here: it is a shipped
            // merchant-facing control and gating it is a product decision.
            'GET|HEAD config-cache' => ['auth'],
            'GET|HEAD zid/{storeId}/orders' => ['auth'],
            'GET|HEAD zid/{storeId}/webhooks' => ['auth'],
            // The Zid webhook. Anonymous by necessity (Zid's servers call it) and gated by
            // a configured shared secret, which now fails closed -- ZidWebhookSecretTest.
            'POST zid/events/{v?}' => [],
            // packages/workdo/LandingPage/src/Routes/api.php:16. `auth:api` names a guard
            // config/auth.php does not define, so this route can only throw; it fails
            // CLOSED, and packages/ is not editable here. Reported.
            'GET|HEAD api/landingpage' => ['auth:api'],
        ];

        $actual = [];

        foreach (Route::getRoutes() as $route) {
            if (!$route->getAction('uses') instanceof \Closure) {
                continue;
            }

            $key = implode('|', $route->methods()) . ' ' . $route->uri();
            $actual[$key] = $route->gatherMiddleware();
        }

        $this->assertSame(
            array_keys($expected),
            array_keys($actual),
            'The set of route closures changed. Every closure route is unreviewable by '
            . 'the controller-based sweep in UnauthenticatedRouteBoundaryTest, so each one '
            . 'has to be listed here with its gate and its justification.'
        );

        foreach ($expected as $key => $required) {
            foreach ($required as $middleware) {
                $this->assertContains(
                    $middleware,
                    array_map('strval', $actual[$key]),
                    "Closure route {$key} lost its `{$middleware}` middleware."
                );
            }
        }
    }
}

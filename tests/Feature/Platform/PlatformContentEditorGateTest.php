<?php

namespace Tests\Feature\Platform;

use App\Models\Setting;
use App\Support\Platform\PlatformContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-104 (P03-C-05) — THE DOOR OF THE OFFICIAL SITE'S CONTENT EDITOR.
 * =================================================================================================
 *
 * The official website is the PLATFORM's own public site. Whoever can edit it can rewrite what
 * Store Syria says about itself, publish text under the company's name at the company's address,
 * and -- once the owner supplies them -- change the five legal documents that bind the business.
 * So the door matters more here than on most screens, and this codebase has four specific traps in
 * it, every one of which is driven below:
 *
 *   - `@can()` and `$user->can()` are ALWAYS FALSE (`permissions_as_gates => false`), so a gate
 *     written with them refuses everybody, operator included.
 *   - THERE IS NO SUPER-ADMIN BYPASS: `isAbleTo()` alone refuses the platform operator, so a
 *     permission-name gate on an operator-only screen refuses the only person who may use it.
 *   - A PERMISSION NAME IS THE WRONG SHAPE OF GATE ANYWAY. A merchant mints their own roles
 *     through the product, so any permission is substantially self-assignable;
 *     `test_a_merchant_who_has_granted_themselves_every_permission_is_still_refused` is the
 *     differentiating case, because every other principal here would also be refused by a
 *     careless gate.
 *   - `UserPolicy::isOperator()` takes an untyped actor and reads `->type`, and `customers` HAS a
 *     `type` column -- so the principal's CLASS has to be checked before its type means anything.
 *
 * AND THE WRITE PATH IS A SEPARATE DOOR FROM THE READ PATH. A gate that covers the editor screen
 * and not its POST is the shape that has shipped here before, so every principal below is driven
 * against BOTH, and the refusal is asserted against the stored content and not only against the
 * status code: a 403 that nevertheless wrote the row is not a refusal.
 */
final class PlatformContentEditorGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const INDEX = '/platform/site-content';
    private const EDIT  = '/platform/site-content/legal';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        PlatformContentStore::forget();
    }

    /** @return array<string, array{0: string}> */
    public static function readUris(): array
    {
        return [
            'the index'  => [self::INDEX],
            'the editor' => [self::EDIT],
        ];
    }

    #[Test]
    public function test_a_signed_out_visitor_is_sent_to_sign_in_and_never_told_401(): void
    {
        foreach (self::readUris() as $label => [$uri]) {
            $response = $this->get($uri);

            $this->assertTrue($response->isRedirect(),
                "{$label}: a guest got status " . $response->status() . ' rather than the sign-in '
                . 'redirect, so the official site\'s content editor is reachable without an account.');

            /* repo B's network layer maps ANY 401 to a forced logout that clears stored
               preferences, so `auth` must own the anonymous case and redirect. */
            $this->assertNotSame(401, $response->status(),
                "{$label}: 401 forces a logout in the shipped Flutter client.");
        }
    }

    #[Test]
    public function test_a_merchant_can_neither_read_nor_write_the_official_sites_content(): void
    {
        [$merchant] = $this->makeTenant();

        $this->assertRefused($merchant, 'a merchant');
    }

    /**
     * THE DIFFERENTIATING CASE. A merchant creates their own roles and assigns themselves any
     * permission they like, so a gate written as a permission check would admit this user.
     */
    #[Test]
    public function test_a_merchant_who_has_granted_themselves_every_permission_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (['Manage Setting', 'Manage Landing Page Section', 'Manage System Setting'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $this->assertRefused($merchant->fresh(), 'a merchant holding self-granted permissions');
    }

    #[Test]
    public function test_a_staff_member_of_a_merchant_is_refused(): void
    {
        [$merchant] = $this->makeTenant();

        $this->assertRefused($this->makeStaff($merchant), "a merchant's staff member");
    }

    /**
     * THE LOOKALIKE ROW. MySQL's PAD SPACE ignores a TRAILING space and not a LEADING one, so
     * `' super admin'` is a NON-operator to the engine and to `UserPolicy::isOperator()`, and was
     * an OPERATOR to a gate written with `trim()`.
     */
    #[Test]
    public function test_a_leading_space_type_is_not_an_operator(): void
    {
        $this->assertRefused(
            $this->makeMerchant(['type' => ' super admin']),
            "a user whose type is ' super admin' (leading space)"
        );
    }

    /**
     * A PRINCIPAL OF THE WRONG CLASS IS REFUSED, EVEN ONE CARRYING THE OPERATOR'S OWN `type`.
     *
     * `UserPolicy::isOperator()` takes `$actor` untyped and reads `$actor->type`, and `customers`
     * HAS a `type` column with `'type'` in `Customer::$fillable`, so a registration path that
     * mass-assigns request input decides its value. Reaching these routes as a `Customer` also
     * needs the DEFAULT guard to be `customers`, which they never make it -- so this is hardening
     * rather than a live hole. `actingAs($customer, 'customers')` is the one thing that does make
     * the customers guard the default, which is why it is what this drives: the exact shape
     * CLAUDE.md records as hiding guard confusion in tests.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused(): void
    {
        [, $store] = $this->makeTenant();

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name'  => 'Saleh',
            'email'      => 'shopper-' . $this->uniqueFixtureRef() . '@example.test',
            'password'   => bcrypt('x'),
            'store_id'   => $store->id,
            'type'       => 'super admin',
        ]);

        foreach (self::readUris() as $label => [$uri]) {
            $response = $this->actingAs($customer, 'customers')->get($uri);

            /* EXACTLY 403 and not merely "not 200". `Customer` is not `MustVerifyEmail`, so the
               route's `verified` middleware cannot redirect it and the request really does reach
               the handler -- which means the refusal has one correct value. "not 200" would also
               be satisfied by the guard being removed and the handler merely breaking on a
               principal it cannot render. */
            $this->assertSame(403, $response->status(),
                "{$label}: a Customer row carrying the operator's own type was admitted.");
        }

        $this->assertSame(403, $this->actingAs($customer, 'customers')
            ->post(self::EDIT, ['en' => ['legal.terms.text' => 'Injected by a customer.']])
            ->status(), 'the write path admitted a Customer carrying the operator\'s own type.');

        $this->assertNothingWasStored('a Customer carrying the operator\'s type');
    }

    #[Test]
    public function test_the_platform_operator_can_open_the_index_and_an_editor(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach (self::readUris() as $label => [$uri]) {
            $this->actingAs($operator)->get($uri)->assertOk();
        }
    }

    #[Test]
    public function test_an_unknown_page_is_a_404_for_the_operator(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->assertSame(404, $this->actingAs($operator)
            ->get('/platform/site-content/no-such-page')->status());

        /* A path segment is not a filesystem path and is not a key the registry carries: the
           handler looks the segment up in a declared array and 404s on a miss. */
        $this->assertSame(404, $this->actingAs($operator)
            ->get('/platform/site-content/..%2F..%2Fcomposer')->status());
    }

    /**
     * THE ROUTE-LEVEL MIDDLEWARE IS PINNED, BECAUSE OTHERWISE IT IS A COMMENT.
     *
     * The handlers check again, so removing `PlatformOperatorOnly` from the routes would leave
     * every other test in this file passing -- which is exactly why this assertion exists. An
     * unpinned second layer is not defence in depth.
     *
     * Asserted against the RESOLVED stack rather than the route file's text, because a route's
     * declared middleware is not its resolved middleware: `bootstrap/app.php` appends guards to
     * the `web` group that a route declaration never shows.
     */
    #[Test]
    public function test_every_content_route_carries_the_operator_middleware_in_its_resolved_stack(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'platform.site_content.index',
            'platform.site_content.edit',
            'platform.site_content.update',
        ] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "The route {$name} does not exist.");

            $this->assertContains(
                \App\Http\Middleware\PlatformOperatorOnly::class,
                $route->gatherMiddleware(),
                "The route {$name} no longer carries PlatformOperatorOnly."
            );

            $this->assertContains('auth', $route->gatherMiddleware(),
                "The route {$name} no longer carries `auth`, which owns the anonymous case and "
                . 'redirects instead of answering 401.');

            /*
             * AND NEITHER IS EXCLUDED, which `gatherMiddleware()` ALONE CANNOT TELL YOU and is a
             * live hazard on these three routes rather than a hypothetical: the update route
             * really does carry a `withoutMiddleware('xss')`. `gatherMiddleware()` returns the
             * DECLARED stack and `withoutMiddleware` records its exclusions separately, in
             * `excludedMiddleware()` -- measured: with `xss` excluded, `gatherMiddleware()` still
             * lists it. So a later `->withoutMiddleware(PlatformOperatorOnly::class)` would leave
             * the assertion above passing while the gate no longer ran, and the handler's own
             * check would be the only thing between a merchant and the company's published words.
             */
            $excluded = $route->excludedMiddleware() ?: [];

            $this->assertNotContains(\App\Http\Middleware\PlatformOperatorOnly::class, $excluded,
                "The route {$name} EXCLUDES PlatformOperatorOnly, so the declared gate does not run.");

            $this->assertNotContains('auth', $excluded,
                "The route {$name} EXCLUDES `auth`.");
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The handler's OWN check, with the route middleware out of the picture
     * ---------------------------------------------------------------------------------------------
     *
     * ADDED AFTER A MUTATION RUN SHOWED THE SECOND LAYER WAS UNPINNED, which is the failure mode
     * CLAUDE.md names: removing `refuseAnyoneButTheOperator()` from all three handlers left every
     * test above passing, because `PlatformOperatorOnly` on the routes refuses first. The whole
     * reason the handlers check as well is that a later edit may move a route out of the group —
     * and an unpinned layer is not defence in depth, it is a comment.
     *
     * So these invoke the controller DIRECTLY. No router, no middleware: just the handler and an
     * authenticated principal, which is exactly the situation a mis-moved route would produce.
     */

    /** @return list<array{0: string, 1: array<int, mixed>}> */
    private static function handlerCalls(): array
    {
        return [
            ['index',  []],
            ['edit',   ['legal']],
            ['update', [null, 'legal']],
        ];
    }

    private function assertHandlerRefuses(string $who): void
    {
        $controller = app(\App\Http\Controllers\PlatformContentController::class);

        foreach (self::handlerCalls() as [$method, $arguments]) {
            if ($method === 'update') {
                $arguments[0] = \Illuminate\Http\Request::create('/platform/site-content/legal', 'POST');
            }

            try {
                $controller->{$method}(...$arguments);

                $this->fail("{$who}: {$method}() returned instead of refusing. The handler's own "
                    . 'operator check is gone, so the route grouping is the only control left.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(),
                    "{$who}: {$method}() refused with " . $e->getStatusCode() . ' rather than 403. '
                    . '401 forces a logout in the shipped Flutter client.');
            }
        }
    }

    #[Test]
    public function test_the_handlers_refuse_a_merchant_on_their_own_without_the_route_middleware(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (['Manage Setting', 'Manage System Setting'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $this->actingAs($merchant->fresh());

        $this->assertHandlerRefuses('a merchant holding self-granted permissions');
    }

    #[Test]
    public function test_the_handlers_refuse_a_leading_space_type_on_their_own(): void
    {
        $this->actingAs($this->makeMerchant(['type' => ' super admin'])->fresh());

        $this->assertHandlerRefuses("a user whose type is ' super admin'");
    }

    /**
     * THE PRINCIPAL'S CLASS, CHECKED BY THE HANDLER ITSELF.
     *
     * `UserPolicy::isOperator()` reads `->type` off an untyped actor and `customers` HAS a `type`
     * column, so without `instanceof User` a `Customer` row carrying `super admin` satisfies the
     * gate. The middleware carries the same clause — which is why this mutation survived every
     * HTTP test — so the handler's copy needs its own probe.
     */
    #[Test]
    public function test_the_handlers_refuse_a_customer_carrying_the_operators_type_on_their_own(): void
    {
        [, $store] = $this->makeTenant();

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name'  => 'Saleh',
            'email'      => 'shopper-' . $this->uniqueFixtureRef() . '@example.test',
            'password'   => bcrypt('x'),
            'store_id'   => $store->id,
            'type'       => 'super admin',
        ]);

        /* `actingAs($customer, 'customers')` makes the customers guard the DEFAULT, which is the
           one thing that puts a non-`User` in front of `auth()->user()` — the exact shape CLAUDE.md
           records as hiding guard confusion in tests. */
        $this->actingAs($customer, 'customers');

        $this->assertHandlerRefuses("a Customer carrying the operator's type");
    }

    #[Test]
    public function test_the_handlers_admit_the_operator_on_their_own(): void
    {
        /* THE CONTROL. Without it every assertion above is satisfied by a handler that refuses
           everybody, which is what a gate written with `@can()` would do here. */
        $this->actingAs($this->makePlatformAdmin());

        $controller = app(\App\Http\Controllers\PlatformContentController::class);

        $this->assertNotNull($controller->index());
        $this->assertNotNull($controller->edit('legal'));
    }

    /**
     * EVERY REFUSED PRINCIPAL IS DRIVEN AGAINST THE WRITE PATH TOO, and the refusal is measured
     * against the stored content rather than only against the status code.
     */
    private function assertRefused(\App\Models\User $user, string $who): void
    {
        foreach (self::readUris() as $label => [$uri]) {
            $this->assertSame(403, $this->actingAs($user)->get($uri)->status(),
                "{$label}: {$who} reached the official site's content editor.");
        }

        $this->assertSame(
            403,
            $this->actingAs($user)->post(self::EDIT, [
                'en' => ['legal.terms.text' => 'Injected terms of service.'],
            ])->status(),
            "{$who} was not refused by the WRITE path, which is a separate door from the read path."
        );

        $this->assertNothingWasStored($who);
    }

    /**
     * A 403 THAT NEVERTHELESS WROTE THE ROW IS NOT A REFUSAL. Asserted against the raw table, not
     * through the reader: `Setting` is `Cachable`, so a read could answer from the model cache
     * while the row exists.
     */
    private function assertNothingWasStored(string $who): void
    {
        PlatformContentStore::forget();

        $rows = Setting::withoutGlobalScopes()
            ->where('name', 'like', 'platform_site_content%')
            ->pluck('value', 'name')
            ->all();

        foreach ($rows as $name => $value) {
            $this->assertStringNotContainsString('Injected', (string) $value,
                "{$who} was answered 403 and the content row {$name} still carries their text.");
        }
    }
}

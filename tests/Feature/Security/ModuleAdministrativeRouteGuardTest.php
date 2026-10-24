<?php

namespace Tests\Feature\Security;

use App\Support\ModuleRouteManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * P1-SEC-04 -- the guard, driven against EVERY key of App\Support\ModuleRouteManifest::ADMINISTRATIVE.
 *
 * No module is loaded here (the full table is exercised by RouteCensusGateTest, in a separate process, because loading all 70 modules in-process
 * pollutes the Composer ClassLoader for every later test). Instead each manifest key is registered as a synthetic route with the same method and
 * uri in the `web` group, whose handler just answers REACHED. That is exactly what the guard sees in production: it keys on method + uri, never
 * on the controller. The behaviour of the real modules behind it is AnonymousAdministrativeModuleRoutesTest.
 */
class ModuleAdministrativeRouteGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Keys whose URI another host guard resolves a ROW for (OrderDocumentAccessGuard, ModuleOrderMutationGuard targets): on a synthetic route
     * with no order behind it those guards refuse everyone, the operator included, which says nothing about THIS guard.
     *
     * @return list<string>
     */
    private static function rowGuardedUris(): array
    {
        $uris = [];

        foreach ([\App\Http\Middleware\OrderDocumentAccessGuard::class, \App\Http\Middleware\ModuleOrderMutationGuard::class, \App\Http\Middleware\ModuleResourceOwnershipGuard::class] as $guard) {
            foreach (array_keys((new \ReflectionClassConstant($guard, 'TARGETS'))->getValue()) as $target) {
                $uris[] = str_contains($target, ' ') ? explode(' ', $target, 2)[1] : $target;   // ModuleResourceOwnershipGuard keys 'METHOD uri'
            }
        }

        return $uris;
    }

    private static function isRowGuarded(string $key): bool
    {
        return in_array(explode(' ', $key, 2)[1], self::rowGuardedUris(), true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            [$method, $uri] = explode(' ', $key, 2);
            Route::middleware('web')->match([$method], $uri, fn () => response('REACHED'));
        }
    }

    private function path(string $uri): string
    {
        return '/' . preg_replace(['/\{[^}?]+\?\}/', '/\{[^}]+\}/'], ['', '1'], $uri);
    }

    private function send(string $key, array $body = [])
    {
        [$method, $uri] = explode(' ', $key, 2);

        return $this->call($method, rtrim($this->path($uri), '/') ?: '/', $body);
    }

    public static function keys(): array
    {
        $out = [];

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            $out[$key] = [$key, $rule];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function test_an_anonymous_caller_never_reaches_an_administrative_route(string $key): void
    {
        $response = $this->send($key);

        $this->assertNotSame('REACHED', $response->getContent(), "{$key} answered an anonymous caller.");
        $this->assertTrue($response->isRedirect() || $response->status() === 403, "{$key}: expected a redirect or 403, got {$response->status()}");
        $this->assertNotSame(401, $response->status(), 'a 401 forces a logout in the Flutter app');
    }

    public static function keysWithoutARowGuard(): array
    {
        return array_filter(self::keys(), fn ($case) => ! self::isRowGuarded($case[0]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keysWithoutARowGuard')]
    public function test_the_platform_operator_reaches_every_administrative_route(string $key): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator);

        $this->assertSame('REACHED', $this->send($key)->getContent(), "{$key} refused the platform operator.");
    }

    public static function plainAuthKeys(): array
    {
        $out = [];

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            if ($rule === 'auth' && ! self::isRowGuarded($key)) {
                $out[$key] = [$key];
            }
        }

        return $out;
    }

    public static function ownershipKeys(): array
    {
        $out = [];

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            if (in_array($rule, ['operator', 'owner:campaign', 'owner:campaign-route', 'owner:helpdesk-ticket', 'owner:helpdesk-ticket-link', 'owner:email-template'], true) || str_starts_with($rule, 'store-row:')) {
                $out[$key] = [$key, $rule];
            }
        }

        return $out;
    }

    /** A signed-in merchant reaches every route whose rule is plain `auth` (the keys another row guard also answers are covered by their own tests). */
    #[\PHPUnit\Framework\Attributes\DataProvider('plainAuthKeys')]
    public function test_a_signed_in_merchant_reaches_a_plain_auth_route(string $key): void
    {
        [$merchant] = $this->makeTenant();
        $this->actingAs($merchant->fresh());

        $this->assertSame('REACHED', $this->send($key)->getContent(), "{$key}: a signed-in merchant was refused a plain `auth` route.");
    }

    /** A signed-in merchant is NOT enough for an ownership rule or an operator-only rule: with no row of theirs named, the request is refused. */
    #[\PHPUnit\Framework\Attributes\DataProvider('ownershipKeys')]
    public function test_a_signed_in_merchant_without_a_row_of_theirs_is_refused_an_ownership_route(string $key, string $rule): void
    {
        [$merchant] = $this->makeTenant();
        $this->actingAs($merchant->fresh());

        $this->assertNotSame('REACHED', $this->send($key)->getContent(), "{$key} ({$rule}): a merchant reached it with no row of theirs.");
    }

    public function test_a_customer_session_is_not_a_merchant_session(): void
    {
        [, $store] = $this->makeTenant();
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Guard', 'last_name' => 'Shopper', 'email' => 'guard-' . uniqid() . '@example.test', 'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        foreach (array_slice(array_keys(ModuleRouteManifest::ADMINISTRATIVE), 0, 25) as $key) {
            $this->actingAs($customer, 'customers');

            $this->assertNotSame('REACHED', $this->send($key)->getContent(), "{$key} answered a shopper's session.");
        }
    }

    public function test_a_rule_the_guard_does_not_know_fails_closed_for_everyone_but_the_operator(): void
    {
        // inject an unknown rule for a synthetic route into the manifest's lookup table, then restore it
        $table = new \ReflectionProperty(ModuleRouteManifest::class, 'byUri');
        $table->setAccessible(true);
        ModuleRouteManifest::administrativeRule('POST', 'x');          // builds the table
        $saved = $table->getValue();

        try {
            $table->setValue(null, $saved + ['p1-sec-04-unknown-rule' => ['POST' => 'no-such-rule']]);
            Route::middleware('web')->post('p1-sec-04-unknown-rule', fn () => response('REACHED'));

            $this->actingAs($this->makeTenant()[0]->fresh());
            $this->assertNotSame('REACHED', $this->call('POST', '/p1-sec-04-unknown-rule')->getContent(), 'A signed-in merchant passed a rule the guard does not know.');

            $this->actingAs($this->makePlatformAdmin());
            $this->assertSame('REACHED', $this->call('POST', '/p1-sec-04-unknown-rule')->getContent(), 'Premise: the operator is the one principal that passes (and the route works).');
        } finally {
            $table->setValue(null, $saved);
        }
    }

    public static function settingsKeys(): array
    {
        $out = [];

        foreach (ModuleRouteManifest::ADMINISTRATIVE as $key => $rule) {
            if (in_array($rule, ['settings:store', 'settings:global'], true)) {
                $out[$key] = [$key, $rule];
            }
        }

        return $out;
    }

    /** The matrix for EVERY settings-writer key (P1-SEC-31): no-permission staff, a Store Setting holder, a Settings holder, the owner. */
    #[\PHPUnit\Framework\Attributes\DataProvider('settingsKeys')]
    public function test_each_settings_writer_route_asks_the_permission_of_its_screen(string $key, string $rule): void
    {
        [$owner] = $this->makeTenant();
        $ordinary = ['some_ordinary_module_option' => 'on'];

        $nobody = $this->makeStaff($owner);
        $storeHolder = $this->makeStaff($owner);
        $this->grantPermission($storeHolder, 'Manage Store Setting');
        $settingsHolder = $this->makeStaff($owner);
        $this->grantPermission($settingsHolder, 'Manage Setting');

        $reaches = fn ($who, array $body = []) => $this->actingAs($who->fresh())->call(...[explode(' ', $key, 2)[0], rtrim($this->path(explode(' ', $key, 2)[1]), '/') ?: '/', $body])->getContent() === 'REACHED';

        $this->assertFalse($reaches($nobody, $ordinary), "{$key}: staff with no settings permission reached the writer.");
        $this->assertTrue($reaches($settingsHolder, $ordinary), "{$key}: a Manage Setting holder was refused.");
        $this->assertTrue($reaches($owner, $ordinary), "{$key}: the tenant owner was refused.");
        $this->assertSame($rule === 'settings:store', $reaches($storeHolder, $ordinary), "{$key} ({$rule}): a Store Setting holder " . ($rule === 'settings:store' ? 'was refused an ordinary post' : 'reached a Manage Setting writer') . '.');

        if ($rule === 'settings:store') {
            $this->assertFalse($reaches($storeHolder, ['MAIL_HOST' => 'attacker.example.test']), "{$key}: a Store Setting holder reached the writer with a restricted name.");
            $this->assertTrue($reaches($settingsHolder, ['MAIL_HOST' => 'smtp.example.test']), "{$key}: a Manage Setting holder was refused a restricted name.");
        }
    }
}

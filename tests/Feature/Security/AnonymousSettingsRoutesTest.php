<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * NEW-ANON-SETTINGS-ROUTES-01 -- the end of the public-mutating-route audit.
 *
 * HOW THEY WERE FOUND, since the method matters more than the five routes. The
 * controller-class sweep in UnauthenticatedRouteBoundaryTest only looks at the
 * eleven classes in its ADMINISTRATIVE_CONTROLLERS list, so a settings controller
 * nobody thought to add is invisible to it. Instead the whole booted route table was
 * walked for routes that (a) answer a mutating verb and (b) carry none of `auth`,
 * `custom.auth`, `auth:customers`, `auth:deliveryboy`, `auth:api` or
 * App\Http\Middleware\EnsureCustomerAuthenticated. That is 110 routes, and almost
 * all of them are legitimately public: the storefront cart and checkout, the
 * customer auth endpoints, the payment-gateway callbacks, and the vendor routes
 * Livewire, Debugbar and the installer register.
 *
 * Five were not. GET and POST /otp-integration, POST /otp-integration/test, and GET
 * and POST /chatwoot/abandoned-carts[/send] were declared at the TOP LEVEL of
 * routes/web.php, outside every group. Their only callers in either repository are
 * two admin settings views.
 *
 * MEASURED ANONYMOUSLY BEFORE THE FIX, with no session:
 *   POST /otp-integration               -> 500, the undeclared `otp_integrations`
 *                                          table, i.e. it reached the WRITE
 *   GET  /otp-integration               -> 500, same, through the view
 *   POST /chatwoot/abandoned-carts/send -> 302 from the CONTROLLER, i.e. it ran the
 *                                          send loop over caller-supplied ids
 *
 * The OTP pair was the serious one: save() keys on `store_id => getCurrentStore()`,
 * which is NULL for an anonymous caller (R4-02), Builder::where() turns a null value
 * into whereNull, so the row an anonymous caller writes is the row test() reads
 * back -- and test() posts to the base_url it finds there. Anonymous outbound
 * request to a caller-chosen URL.
 *
 * `otp_integrations` is declared by no migration (the GATE F family), which is what
 * makes the denial measurable: a request that reaches the controller raises a
 * QueryException, and a request the middleware refuses never touches the database.
 * So these tests assert the REDIRECT and assert the absence of that error.
 */
class AnonymousSettingsRoutesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function anonymousMutationProvider(): array
    {
        return [
            'otp settings save' => ['POST', '/otp-integration', [
                'base_url' => 'http://attacker.example/otp',
                'otp_channel' => 'sms',
                'api_secret' => 'attacker-chosen',
            ]],
            'otp settings test' => ['POST', '/otp-integration/test', ['phone' => '0000000000']],
            'otp settings screen' => ['GET', '/otp-integration', []],
            // The two /chatwoot/abandoned-carts limbs that used to be listed here were
            // retired with the legacy Chatwoot integration. LegacyChatwootRemovedTest now
            // asserts they are absent rather than merely gated.
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('anonymousMutationProvider')]
    public function test_an_anonymous_caller_is_refused(string $method, string $uri, array $payload): void
    {
        $this->assertFalse(
            Schema::hasTable('otp_integrations'),
            'otp_integrations now exists, so the "no QueryException" witness below is '
            . 'gone: assert on the absent row instead.'
        );

        // Nothing may leave the server either. The OTP pair was an anonymous
        // outbound-request primitive, so a faked client proves no request was made
        // rather than merely that the response looked like a denial.
        Http::fake();

        $response = $this->call($method, $uri, $payload);

        $this->assertSame(
            302,
            $response->getStatusCode(),
            "{$method} {$uri} is still reachable anonymously. A 500 here means the "
            . 'request got through the middleware and into the controller: these '
            . 'endpoints touch `otp_integrations`, which no migration declares.'
        );

        $this->assertStringContainsString(
            '/login',
            (string) $response->headers->get('Location'),
            'An anonymous caller must be sent to the login screen.'
        );

        Http::assertNothingSent();
    }

    /**
     * Every one of the five must carry the same stack as the rest of the admin
     * surface, asserted against the route table so a future declaration outside the
     * group fails here and not in production.
     */
    public function test_all_three_remaining_routes_carry_the_admin_middleware_stack(): void
    {
        foreach ([
            'otp.integration',
            'otp.integration.save',
            'otp.integration.test',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");

            $middleware = array_map('strval', array_filter(
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                'is_string'
            ));

            foreach (['auth', 'verified'] as $required) {
                $this->assertContains(
                    $required,
                    $middleware,
                    "Route {$name} lost its `{$required}` middleware. It is a merchant "
                    . 'settings endpoint whose only callers are admin views.'
                );
            }
        }
    }

    /**
     * THE POSITIVE DIRECTION. An authenticated merchant must still reach the screen
     * and the save, or the gate has broken the settings page it protects -- the
     * failure mode that has shipped twice on this codebase.
     *
     * The save itself cannot complete: `otp_integrations` is declared by no
     * migration, so it stops at the write exactly as the anonymous call used to.
     * Reaching that write is the proof the middleware ADMITTED this caller, and it
     * is asserted as such rather than dressed up as a working save.
     */
    public function test_an_authenticated_merchant_still_reaches_the_otp_settings_save(): void
    {
        [$merchant] = $this->makeTenant();

        Http::fake();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($merchant->fresh())->post('/otp-integration', [
                'base_url' => 'https://gateway.example/otp',
                'otp_channel' => 'sms',
            ]);

            $this->fail(
                'Expected the admitted request to reach the undeclared otp_integrations '
                . 'table. If it did not, this test is no longer measuring admission.'
            );
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('otp_integrations', $e->getMessage());
        }
    }

    /**
     * And the merchant is not bounced to the login screen, which is the specific
     * regression a new `auth` group can cause.
     */
    public function test_an_authenticated_merchant_is_not_redirected_to_login(): void
    {
        [$merchant] = $this->makeTenant();

        Http::fake();

        // Was POST /chatwoot/abandoned-carts/send, retired with the legacy integration. The
        // regression this guards is the `auth` group bouncing a legitimate merchant, which is
        // a property of the GROUP, not of any one route -- so it is re-pointed at a surviving
        // member of the same group rather than deleted.
        $response = $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0000000000',
        ]);

        $this->assertNotSame(
            '/login',
            (string) $response->headers->get('Location'),
            'An authenticated merchant was sent to the login screen by the new gate.'
        );

        $this->assertStringNotContainsString(
            '/login',
            (string) $response->headers->get('Location'),
            'An authenticated merchant was sent to the login screen by the new gate.'
        );
    }
}

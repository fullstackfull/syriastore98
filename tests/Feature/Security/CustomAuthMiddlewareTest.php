<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * SEC-R1-01 / R2-01 / R2-02 / NEW-custom-auth-forgeable.
 *
 * The middleware under test guards 189 route declarations in routes/api.php.
 * It previously authenticated on personal_access_tokens.id alone and let
 * header-less requests through entirely.
 *
 * `/api/admin/dashboard` is used as the probe because it is a real
 * custom.auth route and it is the endpoint the shipped Flutter app calls
 * first after login.
 */
class CustomAuthMiddlewareTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PROBE_URI = '/api/admin/dashboard';

    /**
     * The end-to-end property: a request with no Authorization header gets 401 and
     * no guard is populated.
     *
     * WHAT THIS DOES NOT PROVE, corrected after measuring it. The docblock here used
     * to call this "THE PASS-THROUGH PROOF" for the middleware's `if (!$bearer)`
     * refusal. It is not. Api\DashboardController::dashboard ALSO answers 401 when
     * there is no principal, so this assertion is satisfied by the controller
     * whether the middleware refuses or not. MEASURED: restoring the pre-fix
     * pass-through -- `if (!$bearer) { return $next($request); }` -- left this test
     * GREEN while three other tests in this file went red. A green assertion that
     * survives the defect it is named after proves nothing about it.
     *
     * The assertion and the test are KEPT, because the end-to-end property is worth
     * pinning and 401-with-no-guard is the contract repo B depends on. The property
     * the old docblock claimed is now proven separately, and for real, by
     * test_the_middleware_itself_refuses_a_credential_less_request_without_calling_next().
     */
    public function test_request_with_no_authorization_header_is_rejected(): void
    {
        $response = $this->postJson(self::PROBE_URI);

        $response->assertStatus(401);

        $this->assertTrue(Auth::guard('web')->guest());
        $this->assertTrue(Auth::guard('customers')->guest());
    }

    /**
     * THE REAL PASS-THROUGH PROOF -- NEW-CUSTOMAUTH-PASSTHROUGH-UNCOVERED-01.
     *
     * `return $next($request)` used to sit outside the `if ($token)` block, so a
     * credential-less request reached the controller with no principal. The only way
     * to assert that the MIDDLEWARE closes it -- rather than that one downstream
     * controller happens to null-check -- is to watch whether $next runs at all.
     * This matters because custom.auth guards 189 route declarations in
     * routes/api.php and most of those controllers do NOT null-check; the one the
     * HTTP probe above uses is the exception.
     *
     * Same technique FinancePermissionTest adopted for the same reason.
     */
    public function test_the_middleware_itself_refuses_a_credential_less_request_without_calling_next(): void
    {
        $middleware = new \App\Http\Middleware\CustomAuth();

        $nextWasCalled = false;

        $response = $middleware->handle(
            \Illuminate\Http\Request::create(self::PROBE_URI, 'POST'),
            function () use (&$nextWasCalled) {
                $nextWasCalled = true;

                return new \Symfony\Component\HttpFoundation\Response('reached the controller');
            }
        );

        $this->assertFalse(
            $nextWasCalled,
            'CustomAuth called $next for a request carrying no credentials. Every one '
            . 'of the 189 custom.auth routes then runs with no principal, and only the '
            . 'handful of controllers that null-check would notice.'
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * And the converse, so the refusal above cannot be "refuses everything": a valid
     * token makes the SAME middleware instance call $next.
     */
    public function test_the_middleware_calls_next_for_a_valid_token(): void
    {
        [$merchant] = $this->makeTenant();
        $plainTextToken = $merchant->createToken('auth_token')->plainTextToken;

        $middleware = new \App\Http\Middleware\CustomAuth();

        $request = \Illuminate\Http\Request::create(self::PROBE_URI, 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $plainTextToken);

        $nextWasCalled = false;

        $middleware->handle($request, function () use (&$nextWasCalled) {
            $nextWasCalled = true;

            return new \Symfony\Component\HttpFoundation\Response('ok');
        });

        $this->assertTrue(
            $nextWasCalled,
            'A valid plain-text token was refused by the middleware, which would break '
            . 'every already-issued token in the shipped Flutter app.'
        );

        $this->assertSame($merchant->id, Auth::guard('web')->id());
    }

    /**
     * THE FORGERY PROOF. Token ids are small sequential integers and the
     * secret was never compared, so `Bearer 7` authenticated as the owner of
     * token id 7.
     */
    public function test_token_id_without_secret_is_rejected(): void
    {
        [$merchant] = $this->makeTenant();
        $token = $merchant->createToken('auth_token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->accessToken->id)
            ->postJson(self::PROBE_URI);

        $response->assertStatus(401);
        $this->assertTrue(Auth::guard('web')->guest());
    }

    public function test_token_id_with_wrong_secret_is_rejected(): void
    {
        [$merchant] = $this->makeTenant();
        $token = $merchant->createToken('auth_token');

        $forged = $token->accessToken->id . '|' . str_repeat('a', 40);

        $this->withHeader('Authorization', 'Bearer ' . $forged)
            ->postJson(self::PROBE_URI)
            ->assertStatus(401);

        $this->assertTrue(Auth::guard('web')->guest());
    }

    /**
     * THE FLUTTER-COMPATIBILITY ASSERTION, and the most important test in this
     * file. It asserts the exact string the shipped app sends still works:
     * repo-B lib/core/controller/auth/auth_controller.dart stores
     * `data.token` verbatim, and lib/network/network.dart:60 sends it as
     * `Bearer $token`. That value is createToken()->plainTextToken.
     */
    public function test_valid_plain_text_token_authenticates_the_token_owner(): void
    {
        [$merchant] = $this->makeTenant();
        $plainTextToken = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $plainTextToken)
            ->postJson(self::PROBE_URI)
            ->assertStatus(200);

        $this->assertSame($merchant->id, Auth::guard('web')->id());
    }

    /**
     * Two tokenable types share one integer id space, so a User's token must
     * never populate the customers guard.
     */
    public function test_user_token_does_not_populate_the_customers_guard(): void
    {
        [$merchant] = $this->makeTenant();
        $plainTextToken = $merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $plainTextToken)
            ->postJson(self::PROBE_URI);

        $this->assertTrue(Auth::guard('customers')->guest());
        $this->assertSame($merchant->id, Auth::guard('web')->id());
    }

    /**
     * expires_at exists in the schema and was never read.
     */
    public function test_expired_token_is_rejected(): void
    {
        [$merchant] = $this->makeTenant();
        $token = $merchant->createToken('auth_token');

        $token->accessToken->forceFill(['expires_at' => now()->subDay()])->save();

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson(self::PROBE_URI)
            ->assertStatus(401);

        $this->assertTrue(Auth::guard('web')->guest());
    }

    /**
     * Every one of these passed through un-authenticated before the fix.
     */
    #[DataProvider('malformedHeaderProvider')]
    public function test_malformed_headers_are_rejected(string $header): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->createToken('auth_token');

        $this->withHeader('Authorization', $header)
            ->postJson(self::PROBE_URI)
            ->assertStatus(401);

        $this->assertTrue(Auth::guard('web')->guest());
    }

    public static function malformedHeaderProvider(): array
    {
        return [
            'scheme only'          => ['Bearer '],
            'empty id'             => ['Bearer |x'],
            'zero id'              => ['Bearer 0|x'],
            'basic scheme'         => ['Basic 1|x'],
            'token scheme'         => ['Token  1|x'],
            'no scheme at all'     => ['1|secret'],
            'bare integer'         => ['Bearer 1'],
        ];
    }

    public function test_last_used_at_is_updated_on_successful_auth(): void
    {
        [$merchant] = $this->makeTenant();
        $token = $merchant->createToken('auth_token');

        $this->assertNull($token->accessToken->last_used_at);

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson(self::PROBE_URI);

        $this->assertNotNull(
            PersonalAccessToken::find($token->accessToken->id)->last_used_at
        );
    }

    /**
     * A token belonging to a DELETED user must not authenticate. Previously
     * the row lookup succeeded and User::find() returned null, which the
     * guard branches then silently skipped, leaving the request to proceed.
     */
    public function test_token_whose_owner_was_deleted_is_rejected(): void
    {
        [$merchant] = $this->makeTenant();
        $token = $merchant->createToken('auth_token');

        User::where('id', $merchant->id)->delete();

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->postJson(self::PROBE_URI)
            ->assertStatus(401);
    }
}

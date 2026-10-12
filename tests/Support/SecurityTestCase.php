<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Fixtures\TenantFixtures;
use Tests\TestCase;

/**
 * Shared base class for the P0 security regression suites.
 *
 * It gives agents 1-3 one vocabulary for the three questions every one of those findings
 * reduces to, so the suites stay comparable and a reviewer can read them as a set:
 *
 *   assertDeniedForAnonymous()        no credentials at all   -> must not be served
 *   assertDeniedForOtherTenant()      valid credentials, someone else's record
 *   assertDeniedWithoutPermission()   valid credentials, insufficient role
 *
 * THE FALSE-PASS THIS GUARDS AGAINST, and the reason these are not one-line helpers:
 * a naive security test asserts `$response->assertStatus(403)` or, worse, "not 200". Both
 * pass when the endpoint throws a 500. A crash is NOT an authorization decision -- it is
 * an unhandled error that happens to deny this particular request, and it will stop
 * denying it as soon as the crash is fixed. Every assertion here therefore requires the
 * response to be a DELIBERATE denial (401/403, or a redirect to a login page) and treats
 * 5xx as a test failure with an explicit message saying so.
 *
 * The second false pass: a handler that returns 200 with an empty body. The status says
 * "allowed", the body says "nothing found", and a reviewer reads it as safe. For
 * cross-tenant checks the body is therefore also asserted not to contain the other
 * tenant's identifying values.
 *
 * RefreshDatabase is used here, and it is safe ONLY because tests/bootstrap.php has
 * already proven the connection is sqlite :memory: before any test class loaded. Do not
 * reorder that. See tests/bootstrap.php and
 * docs/development/phase-1/05-REGRESSION-TEST-PLAN.md.
 */
abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Statuses that represent a deliberate authorization denial.
     *
     * 419 is included because Laravel returns it for a missing/expired CSRF token, which
     * is a real denial for session-backed web routes.
     */
    protected const DENIAL_STATUSES = [401, 403, 419];

    /**
     * Statuses that represent a denial expressed as a redirect (typically to a login
     * screen) rather than as a status code. Verified further by assertRedirectIsToAuth().
     */
    protected const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * The two-tenant fixture set, built lazily so a test that does not need it pays
     * nothing and so a fixture failure surfaces inside the test that needed it.
     *
     * @var array{a: array<string, mixed>, b: array<string, mixed>}|null
     */
    private ?array $tenants = null;

    /**
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    protected function tenants(): array
    {
        return $this->tenants ??= TenantFixtures::twoTenants();
    }

    /**
     * @return array<string, mixed>
     */
    protected function tenantA(): array
    {
        return $this->tenants()['a'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tenantB(): array
    {
        return $this->tenants()['b'];
    }

    /**
     * Call this at the top of any test that needs an order fixture.
     *
     * If the schema cannot hold an Order it marks the test SKIPPED with the precise
     * reason and finding id, so the run reports BLOCKED rather than passing on a fixture
     * that was never built. An unverified claim is worse than an honest gap.
     *
     * See TenantFixtures::orderSchemaGap() and NEW-orders-currency-columns-missing.
     */
    protected function requireOrderFixtures(): void
    {
        $reason = $this->tenantA()['order_blocked_reason'];

        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }
    }

    // ---------------------------------------------------------------- assertions

    /**
     * An unauthenticated request to a protected endpoint must be denied.
     *
     * @param string               $method HTTP verb
     * @param string               $uri    path, as the client would request it
     * @param array<string, mixed> $payload
     */
    protected function assertDeniedForAnonymous(
        string $method,
        string $uri,
        array $payload = []
    ): TestResponse {
        $response = $this->requestJson($method, $uri, $payload);

        $this->assertIsDeliberateDenial(
            $response,
            sprintf(
                'ANONYMOUS ACCESS WAS NOT DENIED: %s %s. An endpoint reachable with no '
                . 'credentials is the most direct form of the P0 findings.',
                strtoupper($method),
                $uri
            )
        );

        return $response;
    }

    /**
     * A request authenticated as tenant A that reaches for tenant B's record must be
     * denied, and must not leak B's values in the body.
     *
     * @param string               $method
     * @param string               $uri                 built with tenant B's identifier
     * @param array<string, mixed> $payload
     * @param array<int, string>   $mustNotLeak         substrings belonging to tenant B
     *                                                  that must not appear in the body
     */
    protected function assertDeniedForOtherTenant(
        string $method,
        string $uri,
        array $payload = [],
        array $mustNotLeak = []
    ): TestResponse {
        $actor = $this->tenantA()['merchant'];

        $response = $this->actingAs($actor)->requestJson($method, $uri, $payload);

        $this->assertIsDeliberateDenial(
            $response,
            sprintf(
                'CROSS-TENANT ACCESS WAS NOT DENIED: %s %s as tenant A against a tenant B '
                . 'record. The handler is trusting an identifier from the request instead '
                . 'of scoping it to the caller\'s store.',
                strtoupper($method),
                $uri
            )
        );

        $this->assertDoesNotLeak($response, $mustNotLeak, $uri);

        return $response;
    }

    /**
     * A request authenticated as a user with insufficient role/permission must be denied.
     *
     * @param array<string, mixed> $payload
     */
    protected function assertDeniedWithoutPermission(
        string $method,
        string $uri,
        array $payload = []
    ): TestResponse {
        $actor = TenantFixtures::unprivilegedUser();

        $response = $this->actingAs($actor)->requestJson($method, $uri, $payload);

        $this->assertIsDeliberateDenial(
            $response,
            sprintf(
                'INSUFFICIENT-PERMISSION ACCESS WAS NOT DENIED: %s %s as a user with no '
                . 'entitlement. Authentication is not authorization.',
                strtoupper($method),
                $uri
            )
        );

        return $response;
    }

    // ---------------------------------------------------------------- internals

    /**
     * The single place that decides what "denied" means.
     */
    protected function assertIsDeliberateDenial(TestResponse $response, string $because): void
    {
        $status = $response->getStatusCode();

        // A crash is not a denial. Called out separately because this is the failure mode
        // that most often gets mistaken for a passing security test.
        $this->assertLessThan(
            500,
            $status,
            $because . sprintf(
                "\nThe response was HTTP %d -- a SERVER ERROR, not an authorization "
                . "decision. This test must not be treated as passing: the endpoint is "
                . "crashing, and it will start serving the request again as soon as the "
                . "crash is fixed. Body:\n%s",
                $status,
                $this->excerpt($response)
            )
        );

        if (in_array($status, self::DENIAL_STATUSES, true)) {
            return;
        }

        if (in_array($status, self::REDIRECT_STATUSES, true)) {
            $this->assertRedirectIsToAuth($response, $because);

            return;
        }

        $this->fail($because . sprintf(
            "\nThe response was HTTP %d. Expected one of %s, or a redirect to a login "
            . "screen. Body:\n%s",
            $status,
            implode('/', self::DENIAL_STATUSES),
            $this->excerpt($response)
        ));
    }

    /**
     * A redirect counts as a denial only if it actually sends the caller to authenticate.
     * A redirect to the requested resource, or back to a dashboard, is not a denial.
     */
    protected function assertRedirectIsToAuth(TestResponse $response, string $because): void
    {
        $location = (string) $response->headers->get('Location');
        $path     = strtolower((string) parse_url($location, PHP_URL_PATH));

        $looksLikeAuth = $path !== '' && (bool) preg_match(
            '#(^|/)(login|signin|sign-in|auth|register|install)(/|$)#',
            $path
        );

        $this->assertTrue(
            $looksLikeAuth,
            $because . sprintf(
                "\nThe response was a redirect to '%s', which is not a login screen. A "
                . 'redirect elsewhere is not an authorization denial.',
                $location
            )
        );
    }

    /**
     * @param array<int, string> $mustNotLeak
     */
    protected function assertDoesNotLeak(TestResponse $response, array $mustNotLeak, string $uri): void
    {
        if ($mustNotLeak === []) {
            return;
        }

        $body = (string) $response->getContent();

        foreach ($mustNotLeak as $needle) {
            if ($needle === '') {
                continue;
            }

            $this->assertStringNotContainsString(
                (string) $needle,
                $body,
                sprintf(
                    "TENANT DATA LEAKED in the response to %s: the body contains '%s', "
                    . 'which belongs to the other tenant. A denial that still returns the '
                    . "record is not a denial.",
                    $uri,
                    $needle
                )
            );
        }
    }

    /**
     * Issues the request as JSON so API routes return status codes rather than HTML, and
     * so a FormRequest rejection is visible as 422 rather than a redirect.
     *
     * @param array<string, mixed> $payload
     */
    protected function requestJson(string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->json(strtoupper($method), $uri, $payload);
    }

    /**
     * A short, bounded slice of the body for failure messages. Bounded deliberately: an
     * HTML error page is tens of kilobytes and drowns the actual assertion.
     */
    protected function excerpt(TestResponse $response, int $limit = 400): string
    {
        $body = trim((string) $response->getContent());

        if ($body === '') {
            return '(empty body)';
        }

        return strlen($body) > $limit
            ? substr($body, 0, $limit) . "\n... (truncated)"
            : $body;
    }
}

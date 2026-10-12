<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * NEW-delivery-syria-status.
 *
 * POST /api/delivery-syria/status was ANONYMOUS: routes/api.php applied only
 * `throttle:60,1`. Anyone who could reach the URL could drive any Zid order to
 * 'indelivery' or 'delivered', and this server would then push that status to
 * Zid using the merchant's own stored credentials. The order id is not a secret
 * either -- it is derived from the invoice number (ZID-<id>).
 *
 * DeliverySyriaStatusController::updateStatus now performs the two checks that
 * Api\DeliverySyriaOrderController already had: an X-Platform header check and
 * a constant-time per-store bearer-token comparison against
 * users.deliverysyria_secret.
 *
 * WHAT RUNS HERE. Both checks happen before any database access, so the
 * platform and empty-token refusals are exercised for real. The positive path
 * is BLOCKED and skipped: zid_orders and zid_stores are declared by no
 * migration, and users.deliverysyria_secret is not a declared column either
 * (verified -- no match in database/migrations), so there is no way to build a
 * store whose secret matches. Cross-agent dependency: schema agent.
 */
class DeliverySyriaWebhookTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/api/delivery-syria/status';

    public function test_the_webhook_refuses_a_request_with_no_platform_header(): void
    {
        $response = $this->postJson(self::URI, [
            'product_order_id' => 'ZID-75604309',
            'status' => 9,
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'Invalid platform.']);
    }

    public function test_the_webhook_refuses_a_wrong_platform_header(): void
    {
        $response = $this->postJson(
            self::URI,
            ['product_order_id' => 'ZID-75604309', 'status' => 9],
            ['X-Platform' => 'not-deliverysyria']
        );

        $response->assertStatus(403)->assertJson(['message' => 'Invalid platform.']);
    }

    /**
     * The exact request an attacker would have sent before this fix -- correct
     * platform header, no credentials at all.
     */
    public function test_the_webhook_refuses_a_request_with_no_bearer_token(): void
    {
        $response = $this->postJson(
            self::URI,
            ['product_order_id' => 'ZID-75604309', 'status' => 9],
            ['X-Platform' => 'deliverysyria']
        );

        $response->assertStatus(403)->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_the_webhook_refuses_an_empty_bearer_token(): void
    {
        $response = $this->postJson(
            self::URI,
            ['product_order_id' => 'ZID-75604309', 'status' => 9],
            ['X-Platform' => 'deliverysyria', 'Authorization' => 'Bearer    ']
        );

        $response->assertStatus(403)->assertJson(['message' => 'Invalid credentials.']);
    }

    /**
     * Ordering check: the credential gate must come BEFORE input validation, so
     * an unauthenticated caller cannot use the 422/403 difference to probe which
     * payloads are well formed.
     */
    public function test_the_credential_check_precedes_input_validation(): void
    {
        $response = $this->postJson(
            self::URI,
            [], // no product_order_id, no status
            ['X-Platform' => 'deliverysyria']
        );

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'An unauthenticated caller must get 403, not a 422 that confirms the payload shape.'
        );
    }

    /**
     * A valid token must still be rejected for ANOTHER store's order. This is
     * the per-store half: a single global secret would let any configured
     * merchant move any other merchant's orders.
     *
     * BLOCKED -- see the class docblock. Without zid_orders and without
     * users.deliverysyria_secret there is no way to construct either a matching
     * or a mismatching store.
     */
    public function test_a_valid_token_cannot_move_another_stores_order(): void
    {
        foreach (['zid_orders', 'zid_stores'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped(
                    "BLOCKED: `{$table}` is declared by no migration "
                    . '(cross-agent dependency -- schema agent).'
                );
            }
        }

        if (!Schema::hasColumn('users', 'deliverysyria_secret')) {
            $this->markTestSkipped(
                'BLOCKED: users.deliverysyria_secret is declared by no migration, '
                . 'though User.php lists it as fillable and four controllers read '
                . 'it (cross-agent dependency -- schema agent).'
            );
        }

        $this->fail(
            'The schema now supports this test -- write the positive and '
            . 'cross-store cases.'
        );
    }

    /**
     * The route must not be reachable without the throttle either, since it is
     * an unauthenticated endpoint by design.
     */
    public function test_the_webhook_route_is_still_throttled(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/delivery-syria/status');

        $this->assertNotNull($route, 'The delivery-syria status route is missing.');

        $this->assertStringContainsString(
            'throttle',
            implode(' ', $route->gatherMiddleware()),
            'An unauthenticated webhook must stay rate limited.'
        );
    }
}

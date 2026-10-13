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
 * migration, so there is no Zid order to move and no Zid store to authorise
 * against. Cross-agent dependency: schema agent.
 *
 * NEW-STALE-DOC-02, corrected in place. This docblock used to add "and
 * users.deliverysyria_secret is not a declared column either (verified -- no
 * match in database/migrations)". That is FALSE as of
 * database/migrations/2026_10_12_000103_add_deliverysyria_secret_to_users_table.php:64-66,
 * which adds the column behind a Schema::hasColumn guard and is discoverable by
 * the migrator (it is directly under database/migrations/ and matches the
 * `*_*.php` glob). The claim is removed rather than softened, because a wrong
 * reason attached to a correct skip is how a skip outlives its cause.
 *
 * THE SKIP ITSELF IS STILL CORRECT, and for the OTHER two reasons: its guard is
 * on zid_orders / zid_stores, which remain undeclared. Its third guard, on
 * users.deliverysyria_secret, no longer fires -- verified by execution: the skip
 * message this suite now emits names `zid_orders`, not the column. Keeping that
 * third guard is deliberate, so that re-dropping the column cannot silently turn
 * the eventual positive test into a false pass.
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

        // NEW-STALE-DOC-02: this guard no longer fires -- the column IS declared,
        // by 2026_10_12_000103_add_deliverysyria_secret_to_users_table.php:64-66.
        // It is KEPT so that dropping the column again cannot turn the eventual
        // positive test into a false pass, and it is asserted rather than assumed
        // by test_the_delivery_syria_secret_column_is_declared() below.
        if (!Schema::hasColumn('users', 'deliverysyria_secret')) {
            $this->markTestSkipped(
                'BLOCKED: users.deliverysyria_secret has been dropped again; it was '
                . 'declared by 2026_10_12_000103_add_deliverysyria_secret_to_users_table.php.'
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

    /**
     * NEW-STALE-DOC-02, asserted instead of described. The docblock's old claim that
     * users.deliverysyria_secret is undeclared is now a test, so it cannot go stale
     * in either direction: if the column is dropped this fails, and if it is present
     * the skip above is provably not firing for that reason.
     */
    public function test_the_delivery_syria_secret_column_is_declared(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'deliverysyria_secret'),
            'users.deliverysyria_secret is the per-store webhook credential this suite '
            . 'and Api\\DeliverySyriaOrderController both depend on. It is declared by '
            . 'database/migrations/2026_10_12_000103_add_deliverysyria_secret_to_users_table.php:64-66.'
        );
    }

    /**
     * THE SIBLING ENDPOINT, and the reason this one is worth pinning here: POST
     * api/delivery-syria/orders/update-status had its own hardened controller
     * (Api\DeliverySyriaOrderController) made unreachable by a duplicate inline
     * closure in routes/web.php, which took the POST slot and answered 422 instead
     * (NEW-DS-ROUTE-SHADOW-01, fixed; see RouteShadowingTest). Both endpoints
     * implement the same two-check contract, so both are asserted to still answer
     * 403 rather than one being assumed from the other.
     */
    public function test_the_sibling_update_status_endpoint_enforces_the_same_contract(): void
    {
        $uri = '/api/delivery-syria/orders/update-status';

        $this->postJson($uri, ['product_order_id' => 'ZID-1', 'status' => 1])
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid platform.']);

        $this->postJson(
            $uri,
            ['product_order_id' => 'ZID-1', 'status' => 1],
            ['X-Platform' => 'deliverysyria']
        )->assertStatus(403)->assertJson(['message' => 'Invalid credentials.']);
    }
}

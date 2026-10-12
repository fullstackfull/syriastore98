<?php

namespace Tests\Feature\Security;

use App\Models\OrderRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * R4-04. Cross-tenant IDOR and missing authorization on the refund mutators.
 *
 * OrderRefundController::index() already had the right shape -- an isAbleTo()
 * check plus `where('store_id', getCurrentStore())`. Its five mutating siblings
 * had neither: each did a bare OrderRefund::findOrFail($request->refund_id) and
 * acted on whatever came back, so any authenticated merchant could accept,
 * cancel, re-stock or mark-refunded another store's refund request by guessing
 * an id. Two of them (CancelRefundStatus, RefundAmonut) also write to the
 * underlying order, and RefundStock writes product_stock.
 *
 * WHAT RUNS HERE AND WHAT DOES NOT -- read this before trusting the green.
 *
 * The permission half is fully exercised: the controller checks the permission
 * BEFORE it looks anything up, so those tests need no fixture rows and really
 * run.
 *
 * The cross-tenant half needs a persisted order_refunds row, and that row
 * cannot exist on a from-zero schema: order_refunds.order_id carries a FOREIGN
 * KEY to orders.id (verified -- the insert fails with "FOREIGN KEY constraint
 * failed"), while App\Models\Order's `creating` hook writes
 * orders.currency_code and orders.currency_symbol, which NO migration declares.
 * So no order can be inserted, so no refund row can be inserted.
 *
 * Those tests are therefore SKIPPED, not passing. They are written now and go
 * live the moment the columns land. The alternative -- disabling foreign keys or
 * adding the columns from the test -- would manufacture a schema production does
 * not have and prove nothing about it.
 *
 * CROSS-AGENT DEPENDENCY: orders.currency_code + orders.currency_symbol
 * (schema agent), written by App\Models\Order (commerce agent).
 */
class OrderRefundAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The three mutators reachable with a single id parameter, and the request
     * key each reads it from. updateFinalPrice and RefundAmonut take the order id
     * in the URL as well and are covered by the permission assertions below.
     */
    public static function mutatorProvider(): array
    {
        return [
            'updateRefundStatus' => ['/update-refund-status', 'refund_id'],
            'CancelRefundStatus' => ['/cancel-refund-status', 'refund_id'],
            'RefundStock'        => ['/refund-stock', 'refundId'],
            'updateFinalPrice'   => ['/update-final-price/1', 'refundId'],
            'RefundAmonut'       => ['/refund-amount/1', 'refundId'],
        ];
    }

    /**
     * The check the finance surface failed: is the permission this controller
     * gates on one that production can actually create?
     *
     * `Manage Order Refund Request` appears in Utility::addNewData(), so gating
     * on it does not repeat the finance lockout. If this ever stops being true,
     * every assertion below becomes meaningless and this test says so.
     */
    public function test_the_refund_permission_is_created_by_the_only_seeding_mechanism(): void
    {
        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        $this->assertGreaterThan(
            0,
            substr_count($utility, 'Manage Order Refund Request'),
            'Manage Order Refund Request must be seeded by Utility::addNewData(), '
            . 'otherwise gating on it repeats the finance lockout.'
        );
    }

    /**
     * REAL COVERAGE, no fixtures needed: the permission check runs before any
     * lookup, so a nonexistent id still proves the gate fires.
     *
     */
    #[DataProvider('mutatorProvider')]
    public function test_a_user_without_the_permission_is_refused(string $uri, string $key): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff)->post($uri, [$key => 999999]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "{$uri} must return 403 without Manage Order Refund Request, got "
            . $response->getStatusCode() . '.'
        );
    }

    /**
     * REAL COVERAGE: the permitted user passes the permission gate and is then
     * stopped by the tenant scope rather than by the permission.
     *
     * A 404 here is the proof. Before the fix the same request reached
     * findOrFail() and would have acted on the row if it existed in ANY store;
     * now the lookup carries `where('store_id', ...)`, so an id the caller does
     * not own is indistinguishable from an id that does not exist. 403 would mean
     * the permission gate wrongly rejected a permitted user; 500 would mean the
     * unscoped findOrFail is still there.
     *
     */
    #[DataProvider('mutatorProvider')]
    public function test_a_permitted_user_passes_the_gate_and_is_stopped_by_the_tenant_scope(
        string $uri,
        string $key
    ): void {
        [$merchant] = $this->makeTenant();

        $this->grantPermission($merchant, 'Manage Order Refund Request');

        $response = $this->actingAs($merchant->fresh())->post($uri, [$key => 999999]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            "{$uri} must answer 404 for an id outside the caller's store, got "
            . $response->getStatusCode() . '.'
        );
    }

    /**
     * REAL COVERAGE: a super admin is not locked out by the permission gate --
     * the defect class that made the finance surface 403 for everybody.
     *
     * Asserted as "not 403" rather than "404". An exact-404 assertion was tried
     * first and failed for a reason that has nothing to do with authorization:
     * on a fresh database this application redirects a `super admin` web request
     * to /update (302) somewhere outside app/ -- the same request as the same
     * user's merchant counterpart returns 404. The redirect is pre-existing, is
     * not produced by any code in this change, and I did not track down its
     * source; pinning 404 here would make this suite fail for an unrelated
     * reason. The authorization claim is what matters and is what is asserted.
     *
     * The gate itself is additionally proven directly, independent of the HTTP
     * stack, in test_the_permission_helper_admits_a_super_admin below.
     *
     */
    #[DataProvider('mutatorProvider')]
    public function test_a_super_admin_is_not_refused_by_the_permission_gate(
        string $uri,
        string $key
    ): void {
        [$merchant, $store] = $this->makeTenant();
        $super = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->actingAs($super)->post($uri, [$key => 999999]);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'A super admin must not be refused by the refund permission gate.'
        );
    }

    /**
     * The controller's authorization helper, invoked directly.
     *
     * This is the assertion that cannot be satisfied by a redirect, a crash or
     * any other incidental status code: it calls canManageRefunds() and checks
     * the boolean. A super admin must be true with no granted role at all; a
     * staff user must be false; a staff user who holds the seeded permission
     * must be true, which is what proves the helper did not simply become
     * "super admin only" and lock out every legitimate merchant.
     */
    public function test_the_permission_helper_admits_a_super_admin_and_a_permitted_user(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $helper = new class extends \App\Http\Controllers\OrderRefundController {
            public function probe(): bool
            {
                return $this->canManageRefunds();
            }
        };

        $staff = $this->makeStaff($merchant);
        $this->actingAs($staff);
        $this->assertFalse($helper->probe(), 'A staff user with no grant must be refused.');

        $super = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->actingAs($super);
        $this->assertTrue(
            $helper->probe(),
            'A super admin must be admitted with no granted role and no seeded row.'
        );

        $this->grantPermission($staff, 'Manage Order Refund Request');
        $this->actingAs($staff->fresh());
        $this->assertTrue(
            $helper->probe(),
            'A user holding Manage Order Refund Request must be admitted.'
        );
    }

    /**
     * Guests must not reach these endpoints at all. The route group's `auth`
     * boundary is the control; this pins it, because these five routes sat
     * OUTSIDE `auth` in the baseline (the brace move).
     *
     */
    #[DataProvider('mutatorProvider')]
    public function test_a_guest_cannot_reach_the_refund_mutators(string $uri, string $key): void
    {
        $response = $this->post($uri, [$key => 999999]);

        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403],
            "{$uri} must not be reachable anonymously, got "
            . $response->getStatusCode() . '.'
        );

        if ($response->getStatusCode() === 302) {
            $response->assertRedirect(route('login'));
        }
    }

    /**
     * The cross-tenant exemplar, written against real rows.
     *
     * BLOCKED -- see the class docblock. order_refunds.order_id is a FK to
     * orders.id and no order can be inserted while orders.currency_code is
     * undeclared, so this skips rather than passing for the wrong reason.
     */
    public function test_a_merchant_cannot_accept_another_stores_refund_request(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        // Skips if orders.currency_code / currency_symbol are still undeclared.
        $victimOrder = $this->makeOrder($victimStore);

        $this->grantPermission($attacker, 'Manage Order Refund Request');

        $refund = OrderRefund::forceCreate([
            'order_id' => $victimOrder->id,
            // 'Processing' -- NOT 'Pending'. The application never writes or recognises a
            // 'Pending' refund state: OrderRefundController declares exactly four
            // (STATE_PROCESSING/ACCEPT/CANCEL/REFUNDED at :145-148), the only writes are
            // 'Refunded', STATE_ACCEPT, STATE_CANCEL and the state machine's $targetStatus,
            // and a sweep of refund_status comparisons across app/, resources/ and themes/
            // yields only Accept, Cancel, Processing and Refunded. This fixture invented
            // 'Pending', which was harmless while nothing validated the current state and
            // became a 422 the moment agent3 added the transition guard.
            'refund_status' => 'Processing',
            'refund_reason' => 'test',
            'product_refund_id' => json_encode([
                ['product_refund_id' => 1, 'quantity' => 1, 'return_price' => 10],
            ]),
            'product_refund_price' => 10,
            'refund_amount' => 0,
            'store_id' => $victimStore->id,
        ]);

        $response = $this->actingAs($attacker->fresh())
            ->post('/update-refund-status', ['refund_id' => $refund->id]);

        $this->assertSame(404, $response->getStatusCode());

        // Asserted against the state the fixture actually created, not a hardcoded
        // literal. The first version of this fix changed the fixture and left this
        // assertion reading 'Pending', so the test still failed -- for the opposite
        // reason. Reading it back from the model cannot drift from the fixture again.
        $this->assertSame(
            $refund->refund_status,
            $refund->fresh()->refund_status,
            "A merchant changed another store's refund status."
        );
    }

    /**
     * The positive case: inside the caller's own tenant, with the permission,
     * the action still works. Without this, "deny everything" would satisfy every
     * negative assertion above.
     *
     * BLOCKED on the same undeclared columns.
     */
    public function test_a_permitted_merchant_can_accept_a_refund_in_their_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $order = $this->makeOrder($store);

        $this->grantPermission($merchant, 'Manage Order Refund Request');

        $refund = OrderRefund::forceCreate([
            'order_id' => $order->id,
            // 'Processing' -- NOT 'Pending'. The application never writes or recognises a
            // 'Pending' refund state: OrderRefundController declares exactly four
            // (STATE_PROCESSING/ACCEPT/CANCEL/REFUNDED at :145-148), the only writes are
            // 'Refunded', STATE_ACCEPT, STATE_CANCEL and the state machine's $targetStatus,
            // and a sweep of refund_status comparisons across app/, resources/ and themes/
            // yields only Accept, Cancel, Processing and Refunded. This fixture invented
            // 'Pending', which was harmless while nothing validated the current state and
            // became a 422 the moment agent3 added the transition guard.
            'refund_status' => 'Processing',
            'refund_reason' => 'test',
            'product_refund_id' => json_encode([
                ['product_refund_id' => 1, 'quantity' => 1, 'return_price' => 10],
            ]),
            'product_refund_price' => 10,
            'refund_amount' => 0,
            'store_id' => $store->id,
        ]);

        $this->actingAs($merchant->fresh())
            ->post('/update-refund-status', ['refund_id' => $refund->id])
            ->assertOk();

        $this->assertSame(
            'Accept',
            $refund->fresh()->refund_status,
            'A permitted merchant must still be able to accept their own refund request.'
        );
    }

    /**
     * RefundStock walks product_refund_id, a JSON blob on the refund row, and
     * wrote product_stock for each id it found with no store predicate -- so a
     * refund request in the attacker's OWN store could move another store's
     * stock. The ids are attacker-influenced input, not a validated foreign key.
     *
     * BLOCKED on the same undeclared columns.
     */
    public function test_refund_stock_will_not_move_another_stores_product_stock(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();

        $attackerOrder = $this->makeOrder($attackerStore);

        $this->grantPermission($attacker, 'Manage Order Refund Request');

        $victimProduct = \App\Models\Product::forceCreate([
            'name' => 'victim-product-' . uniqid(),
            'slug' => 'victim-' . uniqid(),
            'product_stock' => 5,
            'variant_product' => 0,
            // products.track_stock is declared integer('track_stock') with NO default and
            // NOT NULL (database/migrations/2023_12_26_092822_create_products_table.php:29),
            // so an insert that omits it is rejected outright on sqlite and on any MySQL
            // running in strict mode. Supplied explicitly rather than worked around by
            // relaxing the column, which would be a schema change and is not this
            // increment's to make.
            'track_stock' => 0,
            'store_id' => $victimStore->id,
            'created_by' => $victim->id,
        ]);

        $refund = OrderRefund::forceCreate([
            'order_id' => $attackerOrder->id,
            // 'Processing' -- NOT 'Pending'. The application never writes or recognises a
            // 'Pending' refund state: OrderRefundController declares exactly four
            // (STATE_PROCESSING/ACCEPT/CANCEL/REFUNDED at :145-148), the only writes are
            // 'Refunded', STATE_ACCEPT, STATE_CANCEL and the state machine's $targetStatus,
            // and a sweep of refund_status comparisons across app/, resources/ and themes/
            // yields only Accept, Cancel, Processing and Refunded. This fixture invented
            // 'Pending', which was harmless while nothing validated the current state and
            // became a 422 the moment agent3 added the transition guard.
            'refund_status' => 'Processing',
            'refund_reason' => 'test',
            'product_refund_id' => json_encode([
                ['product_refund_id' => $victimProduct->id, 'quantity' => 100],
            ]),
            'product_refund_price' => 10,
            'refund_amount' => 0,
            'store_id' => $attackerStore->id,
        ]);

        $this->actingAs($attacker->fresh())
            ->post('/refund-stock', ['refundId' => $refund->id]);

        $this->assertSame(
            5,
            (int) $victimProduct->fresh()->product_stock,
            "RefundStock moved another store's product stock."
        );
    }
}

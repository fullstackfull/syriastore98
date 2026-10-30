<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Order;
use App\Services\GatewayReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * D4 / D-29.4 — the review path for a collected payment with no order.
 *
 * The owner's decision is a refusal before it is a feature: *"a merchant's statement alone is not
 * payment proof."* What it then asks for is *"a documented review path with permissions and an
 * audit log"*. So the tests here are in two halves, and THE SECOND HALF IS THE IMPORTANT ONE:
 *
 *   1. The surface works and is properly gated — permission, operator-first ordering, tenant
 *      isolation, audit entry.
 *   2. **The surface cannot act.** No POST sibling exists, no row is written, no order is created,
 *      no replay claim is released and no stock moves. Every one of those is something D-29.4
 *      forbids without gateway evidence, so each is asserted rather than left to the reader of the
 *      handler to notice is absent. A screen that shows unresolved money is one refactor away from
 *      a screen that resolves it.
 */
class UnreconciledCaptureReviewTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'View Finance Dashboard';
    private const URI = '/finance/unreconciled-captures';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('order_gateway_references')) {
            // The table is created by a migration that is dev/test-only until the owner authorises
            // it (D-29.3), and this suite's database is built from source, so it IS here. Asserted
            // rather than skipped: a skip would make the whole file silently vacuous.
            $this->fail(
                'order_gateway_references is absent. The D-29.3 migration must run in the test '
                . 'database, or every assertion below passes against an empty list for the wrong '
                . 'reason.'
            );
        }
    }

    private function seedCapture(int $storeId, string $reference, string $gateway = 'paymera'): void
    {
        GatewayReference::claim($storeId, $gateway, $reference, '125.00');
    }

    // ───────────────────────────────────────────────────────────── the gate

    public function test_a_staff_member_without_the_finance_view_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        /*
         * `getJson` AND AN EXACT STATUS, both for the same measured reason.
         *
         * This case first read `get()` and asserted `assertNotSame(200, ...)`. It passed with the
         * permission check REMOVED — because the HTML branch then reached the view, which cannot
         * render under RefreshDatabase, and returned 500. "Not 200" is satisfied by a crash, so the
         * test was asserting that something went wrong rather than that the GATE refused.
         *
         * `getJson` takes the handler's `expectsJson()` branch, so a missing gate yields 200 and a
         * present one yields 403 — and the exact status is what distinguishes a refusal from a
         * failure. Bitten: with both the route middleware and the controller check removed, this
         * fails.
         */
        $this->actingAs($staff)->getJson(self::URI)->assertStatus(403);
    }

    public function test_the_controller_refuses_on_its_own_without_the_route_middleware(): void
    {
        /*
         * TWO LAYERS, AND THEY REFUSE DIFFERENTLY — found by biting them separately rather than
         * together, which is the only way the difference shows.
         *
         * `finance.permission:` on the route aborts with 403. The controller's own
         * `canFinance()` returns `redirect()->back()`, a 302. So the 403 assertion in the case
         * above pins the ROUTE layer only: removing the controller check alone would not fail it.
         *
         * This case drives the handler DIRECTLY, with no route and therefore no middleware, so the
         * only thing that can refuse is the controller. Defence in depth is worth having only if
         * each layer is asserted on its own; otherwise one of them can be deleted silently while
         * the other keeps the test green.
         */
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $this->actingAs($staff);

        $response = (new \App\Http\Controllers\StoreFinanceController())
            ->unreconciledCaptures(request());

        $this->assertTrue(
            method_exists($response, 'isRedirect') && $response->isRedirect(),
            'StoreFinanceController::unreconciledCaptures must refuse an unpermitted caller by '
            . 'itself, not only behind the route middleware. A handler that relies on its route '
            . 'is one route-file edit away from being open — and later route declarations REPLACE '
            . 'earlier ones for the same method+URI in this codebase, dropping the earlier '
            . 'middleware silently.'
        );
    }

    public function test_a_staff_member_granted_the_finance_view_permission_is_admitted(): void
    {
        // The positive twin. Without it the refusal above could be the route being broken.
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, self::PERMISSION);

        $response = $this->actingAs($staff->fresh())->getJson(self::URI);

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
    }

    public function test_the_platform_operator_is_admitted_without_any_seeded_row(): void
    {
        /*
         * THE ORDERING THAT CLAUDE.MD REQUIRES. `isAbleTo()` ALONE REFUSES the platform operator —
         * there is no super-admin bypass in Laratrust here — so a handler gated on it without an
         * operator-first test locks the operator out. The `finance` gate tests `type` first, and
         * this asserts that path with NO permission row in the database at all, which is also the
         * state a database built from source is in (Utility::addNewData() seeds no Finance name).
         */
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(
            0,
            DB::table('permissions')->where('name', 'like', '%Finance%')->count(),
            'premise: no finance permission row exists, so admission can only come from the '
            . 'operator limb'
        );

        $this->actingAs($operator)->getJson(self::URI)->assertOk();
    }

    // ───────────────────────────────────────────────────── isolation and content

    public function test_a_reviewer_sees_only_their_own_stores_captures(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->seedCapture((int) $storeA->id, 'ref-store-a');
        $this->seedCapture((int) $storeB->id, 'ref-store-b');

        $this->grantPermission($merchantA, self::PERMISSION);
        $merchantA->current_store = $storeA->id;
        $merchantA->save();

        $response = $this->actingAs($merchantA->fresh())->getJson(self::URI);

        $response->assertOk();

        $references = collect($response->json('captures'))->pluck('reference')->all();

        $this->assertContains('ref-store-a', $references);
        $this->assertNotContains(
            'ref-store-b',
            $references,
            'another store\'s collected payment reached this reviewer. The store comes from '
            . 'getCurrentStore(), never from request input — a predicate built from a '
            . 'caller-supplied store_id only compares the caller\'s own two values.'
        );
    }

    public function test_a_capture_already_bound_to_an_order_is_not_listed(): void
    {
        // The list is "needs follow-up", so a reconciled capture must leave it — otherwise the
        // merchant chases payments that are already settled and learns to ignore the page.
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->seedCapture((int) $store->id, 'ref-unbound');
        $this->seedCapture((int) $store->id, 'ref-bound');

        $order = $this->makeOrder($store);
        GatewayReference::bind((int) $store->id, 'paymera', 'ref-bound', (int) $order->id);

        $references = collect(
            $this->actingAs($merchant->fresh())->getJson(self::URI)->json('captures')
        )->pluck('reference')->all();

        $this->assertContains('ref-unbound', $references);
        $this->assertNotContains('ref-bound', $references);
    }

    public function test_the_projection_is_explicit_so_a_new_column_does_not_reach_the_screen(): void
    {
        /*
         * D-29.4: "keep the reference and available information WITHOUT DISCLOSING SECRETS". The
         * table holds no secret today, which is exactly why this is worth pinning now: the handler
         * maps named fields rather than passing the row through, so a column added later — a raw
         * gateway payload, a signature, a token — does not arrive on a merchant's screen by
         * default.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->seedCapture((int) $store->id, 'ref-shape');

        $row = $this->actingAs($merchant->fresh())->getJson(self::URI)->json('captures.0');

        $this->assertSame(
            ['gateway', 'reference', 'amount', 'capture_state', 'seen_at'],
            array_keys($row),
            'the response must carry exactly the five projected fields. If a field was added, '
            . 'decide deliberately whether a merchant should see it.'
        );
    }

    // ─────────────────────────────────────────────────────────── the audit entry

    public function test_the_review_itself_is_written_to_the_audit_log(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->seedCapture((int) $store->id, 'ref-audited');

        $before = AuditLog::where('action', 'finance.unreconciled_captures.reviewed')->count();

        $this->actingAs($merchant->fresh())->getJson(self::URI)->assertOk();

        $entries = AuditLog::where('action', 'finance.unreconciled_captures.reviewed')->get();

        $this->assertSame(
            $before + 1,
            $entries->count(),
            'D4 asks for an audit log on the review path. Nothing is CHANGED here, so there is no '
            . 'before-and-after to record — what is worth recording is that somebody looked, and '
            . 'at how many unresolved captures.'
        );

        $this->assertSame((int) $merchant->id, (int) $entries->last()->user_id);
        $this->assertSame(1, (int) ($entries->last()->new_values['count'] ?? -1));
    }

    // ──────────────────────────────────── THE HALF THAT MATTERS: it cannot act

    public function test_there_is_no_way_to_act_on_a_capture_from_this_surface(): void
    {
        /*
         * Every verb but GET, asserted absent. D-29.4 forbids declaring a capture successful,
         * creating a duplicate order, releasing or restoring stock on a guess, and releasing the
         * replay claim unsafely. A POST sibling is how each of those arrives, so there must not be
         * one — and this fails the day somebody adds it, which is the point.
         */
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertFalse(
                collect(Route::getRoutes())->contains(
                    fn ($route) => $route->uri() === ltrim(self::URI, '/')
                        && in_array($verb, $route->methods(), true)
                ),
                'A ' . $verb . ' route now exists on the unreconciled-captures surface. D-29.4 '
                . 'permits no action on a capture without gateway evidence, and a merchant\'s '
                . 'statement is not evidence. If an authorised settlement path is being built, it '
                . 'needs its own owner decision — this review surface is read-only by design.'
            );
        }
    }

    public function test_reviewing_changes_nothing_at_all(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, self::PERMISSION);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->seedCapture((int) $store->id, 'ref-untouched');

        $rowsBefore = DB::table('order_gateway_references')->orderBy('id')->get()->toArray();
        $ordersBefore = Order::withoutGlobalScopes()->count();

        $this->actingAs($merchant->fresh())->getJson(self::URI)->assertOk();
        $this->actingAs($merchant->fresh())->getJson(self::URI)->assertOk();

        $this->assertEquals(
            $rowsBefore,
            DB::table('order_gateway_references')->orderBy('id')->get()->toArray(),
            'the review must not alter a single gateway-reference row — not capture_state, not '
            . 'order_id, not bound_at. Two reviews in a row, so an idempotency bug shows too.'
        );

        $this->assertSame(
            $ordersBefore,
            Order::withoutGlobalScopes()->count(),
            'and it must create no order. "Another order in the same store is not evidence the '
            . 'operation succeeded" — D-29.3.'
        );
    }

    public function test_the_service_still_offers_no_way_to_settle_a_capture(): void
    {
        /*
         * The handler cannot mark a capture paid because the SERVICE has no such method. That is a
         * stronger guarantee than the handler's own restraint, so it is asserted where it lives —
         * and it is the shape of guarantee D-29.4 asks for, since an absent capability cannot be
         * reached by a future caller who means well.
         */
        $methods = get_class_methods(GatewayReference::class);

        foreach (['markPaid', 'settle', 'release', 'confirm', 'approve', 'restoreStock'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $methods,
                'GatewayReference::' . $forbidden . '() now exists. D-29.4: no automatic Paid '
                . 'state without proof of capture, no stock released or restored on a guess, and '
                . 'the replay claim is not released unsafely. Adding any of these needs its own '
                . 'owner decision.'
            );
        }
    }
}

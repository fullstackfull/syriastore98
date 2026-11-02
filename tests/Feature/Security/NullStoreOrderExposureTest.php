<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * F149 — A PRINCIPAL WITH NO STORE CONTEXT WAS SERVED EVERY NULL-STORE ORDER ON THE PLATFORM.
 * ============================================================================================
 *
 * THE MECHANISM, in three steps, none of which is exotic:
 *
 *  1. `getCurrentStore()` returns `current_store` when set; otherwise a hardcoded `1` for
 *     `type == 'super admin'`; otherwise `Store::where('created_by', $user->id)->first()?->id`.
 *     For a STAFF member that last branch is **NULL**, because staff do not create stores. A
 *     newly added employee whose `current_store` has never been set is in exactly that state.
 *  2. `OrderDataTable::query()` did `->where('store_id', getCurrentStore())`, and Laravel
 *     rewrites `where(col, null)` into `WHERE col IS NULL`.
 *  3. `orders.store_id` is NULLABLE and P1-PLT-02 records writers that persisted NULL into it.
 *
 * So the predicate did not match nothing — it matched the platform's orphaned orders, for a
 * principal holding `Manage Order` in a tenant those orders do not belong to.
 *
 * THIS TEST SEEDS A REAL NULL-STORE ORDER, and that is the load-bearing part of its design. On a
 * fixture-only database `WHERE store_id IS NULL` matches nothing, so an "it is absent" assertion
 * would pass against the unfixed code and prove nothing — a free assertion, which this project
 * has recorded costing it a false reading before. The row is inserted through `DB::table()` so no
 * model hook can supply a `store_id` the test is trying to omit.
 *
 * THE FOUR PRINCIPALS ARE DRIVEN SEPARATELY because the question is not "does the screen refuse"
 * but "does it refuse the right one". The operator legitimately reads every store and must NOT be
 * locked out by a guard aimed at a missing tenant; a merchant with a store must be unaffected;
 * the staff member without one is the case being closed.
 */
class NullStoreOrderExposureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;
    private int $orphanOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->store = $this->makeStore($this->owner, ['default_language' => 'en']);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();
        $this->owner = User::find($this->owner->id);

        $this->grantPermission($this->owner, 'Manage Order');

        $this->orphanOrderId = $this->seedOrphanOrder();
    }

    /**
     * An order with `store_id` NULL — the shape P1-PLT-02 recorded real writers producing.
     * Inserted raw: `Order`'s `creating` hook would otherwise fill columns this row is meant to
     * be missing, and a factory would give it a store.
     */
    private function seedOrphanOrder(): int
    {
        $id = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORPHAN-1',
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([]),
            'product_id' => '',
            'product_price' => 100,
            'final_price' => 100,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'return_status' => 0,
            'store_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(
            DB::table('orders')->where('id', $id)->value('store_id'),
            'premise: the orphan really has a NULL store_id. Without this row every "it is '
            . 'absent" assertion below is free and would pass against the unfixed code.'
        );

        return $id;
    }

    /** A staff member of this tenant who has never had a store selected. */
    private function staffWithNoStore(): User
    {
        $staff = $this->makeStaff($this->owner, ['name' => 'New Employee']);
        $staff->current_store = null;
        $staff->save();

        $staff = User::find($staff->id);
        $this->grantPermission($staff, 'Manage Order');

        $staff = User::find($staff->id);

        $this->assertNull($staff->current_store, 'premise: no store is selected');
        $this->assertNull(
            Store::where('created_by', $staff->id)->value('id'),
            'premise: and they own none either, which is the ordinary state of an employee — '
            . 'this is what makes getCurrentStore() return null rather than a store id'
        );

        return $staff;
    }

    // =======================================================================================
    //  THE CASE BEING CLOSED
    // =======================================================================================

    public function test_a_staff_member_with_no_store_is_refused_the_order_list(): void
    {
        $staff = $this->staffWithNoStore();

        $response = $this->actingAs($staff)->get(route('order.index'));

        $response->assertRedirect();
        $this->assertSame('No store is selected.', session('error'),
            'refused for the real reason — the caller HAS the permission and lacks a tenant');
    }

    public function test_the_orphan_order_is_not_served_to_a_principal_with_no_store(): void
    {
        $staff = $this->staffWithNoStore();

        /*
         * THE PREMISE, MEASURED RATHER THAN ASSUMED, and it is what makes the refusal below mean
         * anything: the predicate the unguarded code would have run really does select this row.
         * An earlier version of this test asserted the orphan was absent from an empty array,
         * which is true of every array and proved nothing.
         */
        $selectedByTheDangerousPredicate = Order::withoutGlobalScopes()
            ->where('store_id', null)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$this->orphanOrderId], $selectedByTheDangerousPredicate,
            'premise: `where(store_id, null)` is `WHERE store_id IS NULL` and it selects the '
            . 'orphan. This is the set a principal with no store context would have been served.');

        $this->assertListRefused($staff,
            'A PLATFORM-WIDE ORPHAN ORDER REACHED A MERCHANT EMPLOYEE. The predicate meant to '
            . 'scope the list to one tenant selected the orders that belong to none.');
    }

    public function test_the_grid_view_refuses_the_same_principal(): void
    {
        $staff = $this->staffWithNoStore();

        $response = $this->actingAs($staff)->get('order-grid');

        $response->assertRedirect();
        $this->assertSame('No store is selected.', session('error'),
            'the alternative view of the same data carried the identical predicate; fixing only '
            . 'the DataTable would have left it reachable here');
    }

    /**
     * THE FLUTTER CONSTRAINT, asserted rather than assumed. The shipped app forces a logout on any
     * 401, so a refusal for a missing tenant must not be one: the caller is authenticated and
     * permitted, and simply has no store selected.
     */
    public function test_the_refusal_is_not_an_authentication_failure(): void
    {
        $staff = $this->staffWithNoStore();

        foreach ([route('order.index'), url('order-grid')] as $uri) {
            $status = $this->actingAs($staff)->get($uri)->status();

            $this->assertNotSame(401, $status,
                "{$uri} answered 401 for a missing store. That is an authentication verdict for "
                . 'a commercial condition, and the shipped Flutter app clears its session on any '
                . '401 — a user-visible regression.');
            $this->assertSame(302, $status, 'it is a redirect, like every other refusal here');
        }
    }

    // =======================================================================================
    //  WHO MUST NOT BE AFFECTED
    // =======================================================================================

    public function test_a_merchant_with_a_store_still_reads_the_order_screen(): void
    {
        $this->assertSame(200, $this->actingAs($this->owner)->get(route('order.index'))->status(),
            'the guard did not break the ordinary case');
    }

    /**
     * SPLIT FROM THE SCREEN TEST ON PURPOSE, and the reason is a defect this file had. Driving
     * the plain page first calls `TableFilterMemory::remember()`, and the AJAX request that
     * followed then hit `shouldRestore()` and was REDIRECTED — so the row list came back empty
     * and `assertNotContains($orphan)` passed against it for free. One request per test, and
     * `dataTableRows()` now refuses to return an empty list quietly.
     */
    public function test_a_merchant_with_a_store_sees_their_own_order_and_not_the_orphan(): void
    {
        $mine = $this->makeOrder($this->store, ['product_order_id' => 'MINE-1']);

        $rows = $this->dataTableRows($this->owner);

        $this->assertContains($mine->id, $rows, 'their own order is still listed');
        $this->assertNotContains($this->orphanOrderId, $rows,
            'and the orphan is not — a store-scoped list never matches a NULL store');
    }

    /**
     * THE DISTINCTION THE OWNER ASKED FOR: operator PERMISSIONS are not the same question as a
     * merchant's missing store context. The operator resolves a store (the helper hands them one)
     * and must keep working; this guard is not an operator check and must not become one.
     */
    public function test_the_platform_operator_is_not_locked_out(): void
    {
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator);
        $operator->current_store = $operatorStore->id;
        $operator->save();
        $operator = User::find($operator->id);

        $this->assertNotContains($this->orphanOrderId, $this->dataTableRows($operator),
            'the operator reads the order screen without holding a merchant role — '
            . '`mayUseOrderScreen()` is operator-first and this guard asks a different question — '
            . 'and is still scoped to the store they are in. `dataTableRows()` asserts a real '
            . '200 JSON payload first, so this cannot pass on an empty list.');
    }

    /**
     * An operator whose `current_store` is unset gets the helper's hardcoded `1`. If no store 1
     * exists the screen must refuse rather than work against a store that is not theirs —
     * `fileExport()` learned this from a 500 and the helper now tests both conditions.
     */
    public function test_an_operator_whose_default_store_does_not_exist_is_refused(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->current_store = null;
        $operator->save();
        $operator = User::find($operator->id);

        if (Store::find(1) !== null) {
            $this->assertSame(200, $this->actingAs($operator)->get(route('order.index'))->status(),
                'store 1 exists in this fixture, so the helper resolves and the screen works');

            return;
        }

        $response = $this->actingAs($operator)->get(route('order.index'));

        $response->assertRedirect();
        $this->assertSame('No store is selected.', session('error'));
    }

    /**
     * THE SECOND GUARD, TESTED DIRECTLY — because through the route it is unreachable, and an
     * unreachable guard is one nobody knows works.
     *
     * `OrderController::index()` refuses before this table is built, so removing the fail-closed
     * branch in `OrderDataTable::query()` leaves every route-driven test in this file green. That
     * is correct layering and a blind spot at the same time: the table is a public class and the
     * next caller added will not have the controller's guard in front of it. This drives
     * `query()` itself.
     */
    public function test_the_datatable_fails_closed_without_a_store_even_when_called_directly(): void
    {
        $staff = $this->staffWithNoStore();

        $this->actingAs($staff);

        $this->assertNull(getCurrentStore(),
            'premise: this principal really resolves no store, which is the condition under test');

        $rows = (new \App\DataTables\OrderDataTable())->query(new Order())->get();

        $this->assertCount(0, $rows,
            'THE TABLE ITSELF MUST MATCH NOTHING WITHOUT A STORE. Laravel rewrites '
            . '`where(store_id, null)` into `WHERE store_id IS NULL`, so the unguarded query '
            . 'returns the platform\'s orphaned orders rather than an empty set — and this '
            . 'object is reachable by any future caller that does not repeat the controller\'s '
            . 'refusal.');
    }

    // =======================================================================================
    //  HELPERS
    // =======================================================================================

    /**
     * The order ids the list serves `$as`, for a principal who IS supposed to be served.
     *
     * IT ASSERTS ITS OWN PREMISE rather than returning an empty array, because every absence
     * assertion in this file is satisfied by an empty list. Returning `[]` on a redirect — which
     * this helper did at first — turned `assertNotContains($orphan, …)` into a free pass in two
     * tests, including the operator one. For the principals who SHOULD be refused, use
     * `assertListRefused()`.
     */
    private function dataTableRows(User $as): array
    {
        $response = $this->actingAs($as)->get(route('order.index', [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertSame(200, $response->status(),
            'premise: the list answered this principal at all. A redirect here (the filter-memory '
            . 'restore, or a refusal) would make every absence assertion below free.');

        $payload = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($payload, 'premise: the AJAX leg answered with JSON');
        $this->assertArrayHasKey('data', $payload, 'premise: it returned a row set');

        return array_values(array_filter(array_map(
            static fn ($row) => isset($row['id']) ? (int) $row['id'] : null,
            $payload['data']
        )));
    }

    /** A principal the screen must refuse: no rows are served at all. */
    private function assertListRefused(User $as, string $because = ''): void
    {
        $response = $this->actingAs($as)->get(route('order.index', [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertNotSame(200, $response->status(),
            $because !== '' ? $because : 'the list served a principal with no store context');
    }
}
